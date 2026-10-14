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
use Shopware\Core\Checkout\Shipping\Cart\Error\ShippingMethodBlockedError;
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
 * Ermittelt, was der Versand des aktuellen Warenkorbs in ein bestimmtes Land kostet,
 * je verfügbarer Versandart.
 *
 * Nachgebaut wird nichts. Die Berechnung läuft über Shopwares eigene
 * Warenkorb-Berechnung gegen einen Kontext mit der Zieladresse, und welche
 * Versandarten dorthin verfügbar sind, beantwortet die Versandarten-Route des Kerns mit
 * `onlyAvailable`. Damit greifen Versandzonen, PLZ-Regeln, Gewichts-, Preis-, Mengen-
 * und Volumenstaffeln, regelbasierte Preise und Gratisversand-Aktionen so wie im
 * Checkout.
 *
 * Erst rechnen, dann die Route fragen: Welche Regeln zutreffen, steht erst nach der
 * Berechnung fest, und wer vorher filtert, verliert die Versandarten, die erst im
 * Zielland greifen.
 *
 * Eine eigene Prüfung „Regel-Kennung in den zutreffenden Regeln" sähe gleich aus, wäre
 * aber eine zweite Wahrheit neben der des Kerns. Läuft der Kern eines Tages anders,
 * etwa über ein Skript im `ShippingMethodRouteHook`, wiche die Auskunft still vom
 * Checkout ab.
 *
 * Nicht `final`, weil die Tests von Rechner-Controller und Indikator ihn als Test-Double
 * ersetzen.
 */
class ShippingEstimateService
{
    /**
     * Ab wie vielen verfügbaren Versandarten abgebrochen wird.
     *
     * Jede kostet eine eigene Warenkorb-Berechnung; 25 liegt weit über der Handvoll, die
     * für einen Warenkorb übrig bleibt, und deckelt eine Anfrage am öffentlichen Endpunkt
     * auf 26 Berechnungen. Die Grenze greift erst nach der Verfügbarkeitsprüfung: Ein Shop
     * mit Gewichts- und Längenstaffeln hat schnell zweihundert Versandarten, und eine
     * Grenze davor verschluckte ganze Länder.
     */
    private const MAX_CALCULATIONS = 25;

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

            $estimates = $this->estimatesFor($cart, $context, $country, $zipCode);

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
     * Gedacht für Aussagen, die sonst ins Blaue gehen, allen voran den
     * Versandkostenfrei-Hinweis. Oberhalb des obersten Gewichtsbands ist eine Versandart
     * weiterhin verfügbar und scheitert erst am fehlenden Preis; wer nur die
     * Verfügbarkeits-Regeln liest, verspricht dort kostenlosen Versand für einen Warenkorb,
     * den der Shop gar nicht ausliefert.
     *
     * Meist beantwortet der Warenkorb die Frage schon selbst, siehe `cartAlreadyAnswers()`.
     * Sonst fragt die Prüfung dieselbe Route und rechnet mit derselben Kern-Berechnung wie die
     * Auskunft weiter oben.
     *
     * Ohne Postleitzahl gibt es keine Aussage, und „keine Aussage" heißt `true`. Die
     * Speditionstarife hängen an PLZ-Zonen; ohne Postleitzahl fielen Versandarten weg, die mit
     * Adresse greifen, und der Hinweis verschwände zu Unrecht. Eine unklare Lage darf nicht
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

        if ($this->cartAlreadyAnswers($cart)) {
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
     * Der Warenkorb der Seite ist für genau den Lieferort im Kontext berechnet. Trägt er eine
     * Lieferung und keinen Fehler zur Versandart, hat die gewählte Versandart dort einen Preis,
     * und die Frage ist ohne Neuberechnung beantwortet. Das spart bei jedem Aufruf von Warenkorb
     * und Leiste die eigene Rechnung (siehe `benchmarks/ShippingCheckBench.php`).
     *
     * Über dem obersten Gewichtsband behält der Kern die Lieferung, meldet aber
     * `ShippingMethodBlockedError` („no shipping costs found"). Dann, und ohne Lieferung, wird wie
     * bisher über alle verfügbaren Versandarten gerechnet; vielleicht hat eine andere einen Preis.
     */
    private function cartAlreadyAnswers(Cart $cart): bool
    {
        if ($cart->getDeliveries()->count() === 0) {
            return false;
        }

        foreach ($cart->getErrors() as $error) {
            if ($error instanceof ShippingMethodBlockedError) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ein Preis je verfügbarer Versandart; Versandarten ohne Preis fallen weg.
     *
     * @return list<ShippingEstimate>
     */
    private function estimatesFor(Cart $cart, SalesChannelContext $context, CountryEntity $country, string $zipCode): array
    {
        $estimates = [];
        foreach ($this->availableShippingMethods($cart, $context, $country, $zipCode) as $shippingMethod) {
            $estimate = $this->priceFor($cart, $context, $country, $zipCode, $shippingMethod);
            if ($estimate !== null) {
                $estimates[] = $estimate;
            }
        }

        return $estimates;
    }

    /**
     * Fragt Shopware, welche Versandarten in dieses Land für diesen Warenkorb
     * verfügbar sind.
     *
     * Die Berechnung davor setzt die zutreffenden Regel-Kennungen auf dem Kontext, und
     * die wertet die Route anschließend aus. Ohne sie stünden dort die Regeln des
     * bisherigen Landes.
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

        if (\count($available) > self::MAX_CALCULATIONS) {
            $this->logger->warning('Mehr verfügbare Versandarten als berechnet werden — Liste gekürzt', [
                'countryIso' => $country->getIso(),
                'available' => \count($available),
                'shown' => self::MAX_CALCULATIONS,
            ]);

            $available = \array_slice($available, 0, self::MAX_CALCULATIONS);
        }

        return $available;
    }

    /**
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
     * Der Warenkorb wird geklont: Die Berechnung schreibt Lieferungen, Preise und
     * Erweiterungen in das übergebene Objekt. Ginge das Original hinein, stünde der
     * Kunde nach einer bloßen Preisabfrage mit fremden Versandkosten da.
     *
     * `true` sagt dem Lader ausdrücklich: nicht auf die Regeln des übergebenen
     * Warenkorbs vorfiltern. Sonst käme nur zum Zuge, was schon im bisherigen Land
     * galt.
     */
    private function calculate(Cart $cart, SalesChannelContext $derived): Cart
    {
        return $this->cartRuleLoader
            ->loadByCart($derived, $this->copyOf($cart), new CartBehavior($derived->getPermissions()), true)
            ->getCart();
    }

    /**
     * Eine Kopie des Warenkorbs ohne seine Hinweise.
     *
     * Ein schlichtes `clone` genügt nicht: `Struct` klont tief, und ein Warenkorb-Hinweis
     * ist eine Exception, die PHP nicht klonen lässt (`Exception::__clone` ist privat).
     * Der Klon bräche mit „Trying to clone an uncloneable object" ab, sobald am Warenkorb
     * auch nur ein Hinweis hängt, etwa ein ausverkaufter Artikel, eine abgelaufene Aktion
     * oder eine Pflichtangabe aus einem anderen Plugin. Das ist der Normalfall, und die
     * Auskunft scheiterte dann stillschweigend mit „Berechnung nicht möglich".
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
