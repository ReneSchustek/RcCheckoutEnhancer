<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcCheckoutEnhancer\Struct\ShippingEstimate;
use Ruhrcoder\RcCheckoutEnhancer\Struct\ShippingEstimateResult;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartRuleLoader;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Shipping\SalesChannel\AbstractShippingMethodRoute;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Ermittelt, was der Versand des aktuellen Warenkorbs in ein bestimmtes Land kostet —
 * je verfügbarer Versandart.
 *
 * Der Kern der Sache: Es wird **nichts nachgebaut**. Die Berechnung läuft über
 * Shopwares eigene Warenkorb-Berechnung gegen einen Kontext mit der Zieladresse,
 * und welche Versandarten dorthin überhaupt verfügbar sind, beantwortet Shopwares
 * eigene Versandarten-Route mit `onlyAvailable`. Damit greifen Versandzonen,
 * PLZ-Regeln, Gewichts-, Preis-, Mengen- und Volumenstaffeln, regelbasierte Preise
 * und Gratisversand-Aktionen genau so, wie sie im Checkout greifen.
 *
 * Die Reihenfolge ist dabei nicht beliebig: Welche Regeln zutreffen, steht erst
 * **nach** der Berechnung fest. Deshalb erst rechnen, dann die Route fragen — wer
 * vorher filtert, verliert genau die Versandarten, die erst im Zielland greifen.
 *
 * Warum nicht selbst über die Verfügbarkeits-Regel filtern: Eine eigene Prüfung
 * „Regel-Kennung in den zutreffenden Regeln" sieht identisch aus, ist aber eine
 * zweite Wahrheit neben der von Shopware. Läuft der Kern eines Tages anders — etwa
 * über ein Skript im `ShippingMethodRouteHook` —, weicht die Auskunft still vom
 * Checkout ab. Die Route ist die eine Quelle.
 */
class ShippingEstimateService
{
    /**
     * Ab wie vielen verfügbaren Versandarten abgebrochen wird.
     *
     * Jede kostet eine eigene Warenkorb-Berechnung. Die Grenze greift erst **nach**
     * der Verfügbarkeitsprüfung — nicht davor, denn ein Shop mit Gewichts- und
     * Längenstaffeln hat schnell zweihundert Versandarten, von denen für einen
     * konkreten Warenkorb nur eine Handvoll übrig bleibt. Eine Grenze davor hätte
     * ganze Länder verschluckt.
     */
    private const MAX_BERECHNUNGEN = 25;

    /**
     * @param EntityRepository<CountryCollection> $countryRepository
     */
    public function __construct(
        private readonly AbstractShippingMethodRoute $shippingMethodRoute,
        private readonly EntityRepository $countryRepository,
        private readonly EstimateContextFactory $contextFactory,
        private readonly CartRuleLoader $cartRuleLoader,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function estimate(
        Cart $cart,
        SalesChannelContext $context,
        string $countryIso,
        string $zipCode,
    ): ShippingEstimateResult {
        if ($cart->getLineItems()->count() === 0) {
            return ShippingEstimateResult::withoutShippingMethod($countryIso, $zipCode);
        }

        try {
            $country = $this->findCountry($countryIso, $context);
            if ($country === null) {
                return ShippingEstimateResult::withoutShippingMethod($countryIso, $zipCode);
            }

            $available = $this->availableShippingMethods($cart, $context, $country, $zipCode);

            $estimates = [];
            foreach ($available as $shippingMethod) {
                $estimate = $this->priceFor($cart, $context, $country, $zipCode, $shippingMethod);
                if ($estimate !== null) {
                    $estimates[] = $estimate;
                }
            }

            return $estimates === []
                ? ShippingEstimateResult::withoutShippingMethod($countryIso, $zipCode)
                : ShippingEstimateResult::withShippingMethods($estimates, $countryIso, $zipCode);
        } catch (Throwable $e) {
            $this->logger->error('Versandkosten-Ermittlung fehlgeschlagen', [
                'countryIso' => $countryIso,
                'salesChannelId' => $context->getSalesChannelId(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return ShippingEstimateResult::failed($countryIso, $zipCode);
        }
    }

    /**
     * Lässt sich dieser Warenkorb an den Ort ausliefern, der im Kontext steht?
     *
     * Gedacht für Aussagen, die sonst ins Blaue gehen — allen voran den
     * Versandkostenfrei-Hinweis. Der leitete seine Zusage bis 1.5.3 allein aus den
     * Verfügbarkeits-Regeln ab und sah die Preisbänder nie an. Oberhalb des obersten
     * Gewichtsbands ist eine Versandart aber weiterhin *verfügbar* und scheitert erst
     * am fehlenden Preis; der Hinweis versprach dort kostenlosen Versand für einen
     * Warenkorb, den der Shop gar nicht ausliefert. Am 2026-08-10 mit 530 kg gemessen.
     *
     * Die Prüfung baut nichts nach: Sie fragt dieselbe Route und rechnet mit derselben
     * Kern-Berechnung wie die Auskunft weiter oben.
     *
     * **Sie antwortet nur, wenn eine Postleitzahl bekannt ist.** Die Speditionstarife hängen an
     * PLZ-Zonen; ohne Postleitzahl fielen Versandarten weg, die mit Adresse sehr wohl greifen,
     * und der Hinweis verschwände zu Unrecht. Der Shop sagt an dieser Stelle selbst, dass er die
     * Versandkosten erst mit der Lieferanschrift ermitteln kann. Ohne sie gibt es hier also
     * keine Aussage, und „keine Aussage" heißt `true` — eine unklare Lage darf nicht
     * stillschweigend zur Verneinung werden.
     */
    public function canShipToContextLocation(Cart $cart, SalesChannelContext $context): bool
    {
        if ($cart->getLineItems()->count() === 0) {
            return true;
        }

        $country = $context->getShippingLocation()->getCountry();
        $zipCode = $context->getShippingLocation()->getAddress()?->getZipcode() ?? '';

        if ($zipCode === '') {
            return true;
        }

        try {
            foreach ($this->availableShippingMethods($cart, $context, $country, $zipCode) as $shippingMethod) {
                if ($this->priceFor($cart, $context, $country, $zipCode, $shippingMethod) !== null) {
                    return true;
                }
            }

            return false;
        } catch (Throwable $e) {
            $this->logger->error('Prüfung auf lieferbare Versandart fehlgeschlagen', [
                'salesChannelId' => $context->getSalesChannelId(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            // Im Zweifel nicht verneinen: Ein Aussetzer der Prüfung darf keine
            // Zusage unterdrücken, die sonst richtig wäre.
            return true;
        }
    }

    /**
     * Fragt Shopware, welche Versandarten in dieses Land für diesen Warenkorb
     * verfügbar sind.
     *
     * Die Berechnung davor ist notwendig, nicht schmückend: Sie setzt die
     * zutreffenden Regel-Kennungen auf dem Kontext, und genau die wertet die Route
     * anschließend aus. Ohne sie stünden dort die Regeln des bisherigen Landes.
     *
     * @return list<ShippingMethodEntity>
     */
    private function availableShippingMethods(
        Cart $cart,
        SalesChannelContext $context,
        CountryEntity $country,
        string $zipCode,
    ): array {
        $derived = $this->contextFactory->create($context, $country, $zipCode, $context->getShippingMethod());
        if ($derived === null) {
            $this->logger->warning('Kontext-Ableitung griff nicht — keine Auskunft möglich', [
                'countryIso' => $country->getIso(),
            ]);

            return [];
        }

        $this->calculate($cart, $derived);

        $request = new Request(['onlyAvailable' => true]);

        $criteria = new Criteria();
        $criteria->addAssociation('deliveryTime');
        $criteria->addAssociation('prices');

        $methods = $this->shippingMethodRoute->load($request, $derived, $criteria)->getShippingMethods();

        $available = array_values($methods->getElements());

        if (\count($available) > self::MAX_BERECHNUNGEN) {
            $this->logger->warning('Mehr verfügbare Versandarten als berechnet werden — Liste gekürzt', [
                'countryIso' => $country->getIso(),
                'available' => \count($available),
                'shown' => self::MAX_BERECHNUNGEN,
            ]);

            $available = \array_slice($available, 0, self::MAX_BERECHNUNGEN);
        }

        return $available;
    }

    /**
     * Rechnet den Preis einer einzelnen Versandart aus.
     *
     * Eine eigene Berechnung je Versandart ist unvermeidbar: Die Versandkosten
     * hängen an der gewählten Versandart, und der Warenkorb trägt immer nur eine.
     */
    private function priceFor(
        Cart $cart,
        SalesChannelContext $context,
        CountryEntity $country,
        string $zipCode,
        ShippingMethodEntity $shippingMethod,
    ): ?ShippingEstimate {
        $derived = $this->contextFactory->create($context, $country, $zipCode, $shippingMethod);
        if ($derived === null) {
            $this->logger->warning('Kontext-Ableitung griff nicht — Versandart übersprungen', [
                'shippingMethodId' => $shippingMethod->getId(),
                'countryIso' => $country->getIso(),
            ]);

            return null;
        }

        $calculated = $this->calculate($cart, $derived);

        return new ShippingEstimate(
            $shippingMethod->getId(),
            $shippingMethod->getTranslation('name') ?? $shippingMethod->getName() ?? '',
            $calculated->getShippingCosts()->getTotalPrice(),
            $derived->getCurrency()->getIsoCode(),
            $shippingMethod->getDeliveryTime()?->getTranslation('name'),
        );
    }

    /**
     * Lässt Shopware den Warenkorb im abgeleiteten Kontext durchrechnen.
     *
     * Der Warenkorb wird geklont: Die Berechnung schreibt Lieferungen, Preise und
     * Erweiterungen in das übergebene Objekt. Ginge das Original hinein, stünde der
     * Kunde nach einer bloßen Preisabfrage mit fremden Versandkosten da.
     *
     * `true` sagt dem Lader ausdrücklich: nicht auf die Regeln des übergebenen
     * Warenkorbs vorfiltern. Sonst käme nur zum Zuge, was schon im bisherigen Land
     * galt — und genau das soll sich hier ja ändern.
     */
    private function calculate(Cart $cart, SalesChannelContext $derived): Cart
    {
        return $this->cartRuleLoader
            ->loadByCart($derived, $this->copyOf($cart), new CartBehavior($derived->getPermissions()), true)
            ->getCart();
    }

    /**
     * Eine Kopie des Warenkorbs — ohne seine Hinweise.
     *
     * Ein schlichtes `clone` genügt hier nicht: `Struct` klont **tief**, und ein
     * Warenkorb-Hinweis ist eine Exception. Die verbietet PHP zu klonen
     * (`Exception::__clone` ist privat), also bricht der Klon mit „Trying to clone an
     * uncloneable object" ab, sobald am Warenkorb auch nur ein Hinweis hängt — ein
     * ausverkaufter Artikel, eine abgelaufene Aktion, eine Pflichtangabe aus einem
     * anderen Plugin. Das ist der Normalfall, nicht die Ausnahme; die Auskunft
     * scheiterte dann stillschweigend mit „Berechnung nicht möglich".
     *
     * Die Hinweise werden deshalb kurz abgehängt, kopiert wird ohne sie, und danach
     * hängen sie wieder am Original. Sie gehören ohnehin nicht in die Kopie: Sie
     * beschreiben den Zustand des echten Warenkorbs, nicht den einer Preisabfrage
     * für ein anderes Land.
     */
    private function copyOf(Cart $cart): Cart
    {
        $errors = $cart->getErrors();
        $cart->setErrors(new ErrorCollection());

        try {
            $copy = clone $cart;
        } finally {
            $cart->setErrors($errors);
        }

        return $copy;
    }

    private function findCountry(string $countryIso, SalesChannelContext $context): ?CountryEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('iso', strtoupper($countryIso)));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->setLimit(1);

        $country = $this->countryRepository->search($criteria, $context->getContext())->getEntities()->first();

        return $country instanceof CountryEntity ? $country : null;
    }
}
