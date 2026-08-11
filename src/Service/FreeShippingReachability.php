<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Beantwortet, ob Versandkostenfreiheit für den Lieferort dieses Besuchers überhaupt
 * erreichbar ist.
 *
 * Warum nicht einfach die Versandarten-Route fragen, so wie es der Versandkostenrechner
 * tut: Die Route sagt, was für den Warenkorb **jetzt** verfügbar ist. Ein Warenkorb
 * unter dem Schwellwert hat die versandkostenfreie Versandart naturgemäß nicht — genau
 * dann soll der Hinweis aber erscheinen. Die Route kann „fehlt noch am Betrag" nicht von
 * „falsches Land" unterscheiden, und der Unterschied ist der ganze Punkt.
 *
 * Gelesen wird deshalb die **Verfügbarkeitsregel** der eingestellten Versandarten, und
 * zwar nur ihre Land-Bedingungen. Das ist keine Nachbildung: Ändert der Betreiber die
 * Regel, ändert sich die Antwort mit. Nachgebaut wäre „wenn Land = DE" im Code — genau
 * das soll es nicht geben.
 *
 * Die Grenze dieses Vorgehens steht hier ausdrücklich: Verschachtelte Oder-Container
 * werden nicht ausgewertet. Im Zweifel — keine Land-Bedingung lesbar, keine Versandart
 * eingestellt — lautet die Antwort **ja**. Lieber ein Hinweis mit Bedingung im Text als
 * ein Shop, der still aufhört zu werben.
 */
class FreeShippingReachability
{
    private const CONDITION_COUNTRY = 'customerShippingCountry';
    private const CONDITION_GOODS_PRICE = 'cartGoodsPrice';

    /**
     * @param EntityRepository<ShippingMethodCollection> $shippingMethodRepository
     * @param EntityRepository<CountryCollection>        $countryRepository
     */
    public function __construct(
        private readonly EntityRepository $shippingMethodRepository,
        private readonly EntityRepository $countryRepository,
    ) {
    }

    /**
     * @param list<string> $freeShippingMethodIds Die Versandarten, die der Betreiber als
     *                                            „versandkostenfrei" eingestellt hat
     */
    public function reachableFrom(array $freeShippingMethodIds, SalesChannelContext $context): FreeShippingReach
    {
        if ($freeShippingMethodIds === []) {
            return FreeShippingReach::unknown();
        }

        $methods = $this->load($freeShippingMethodIds, $context);
        $threshold = $this->thresholdFrom($methods);

        $allowed = $this->allowedCountries($methods, $context);
        if ($allowed === null) {
            return FreeShippingReach::unknown();
        }

        return \array_key_exists($context->getShippingLocation()->getCountry()->getId(), $allowed)
            ? FreeShippingReach::reachable(array_keys($allowed), array_values($allowed), $threshold)
            : FreeShippingReach::outOfReach($threshold);
    }

    /**
     * @param list<string> $freeShippingMethodIds
     */
    private function load(array $freeShippingMethodIds, SalesChannelContext $context): ShippingMethodCollection
    {
        $criteria = new Criteria($freeShippingMethodIds);
        $criteria->addAssociation('availabilityRule.conditions');

        /** @var ShippingMethodCollection $methods */
        $methods = $this->shippingMethodRepository->search($criteria, $context->getContext())->getEntities();

        return $methods;
    }

    /**
     * Der Warenwert aus der Regel, ab dem versandkostenfrei geliefert wird.
     *
     * Damit steht der Betrag nur noch an **einer** Stelle. Bis 1.4.0 stand er an dreien —
     * in der Regel, in der Einstellung dieses Plugins und im Freitext der Vertrauensleiste —
     * und am 2026-08-04 waren alle drei verschieden.
     *
     * Bei mehreren Versandarten gewinnt der niedrigste Betrag: Ab dem ist
     * Versandkostenfreiheit überhaupt erreichbar, und genau das sagt der Hinweis zu.
     *
     * `null` heißt nicht auslesbar — dann bleibt die Einstellung im Admin maßgeblich.
     */
    private function thresholdFrom(ShippingMethodCollection $methods): ?float
    {
        $lowest = null;

        foreach ($methods as $method) {
            foreach ($method->getAvailabilityRule()?->getConditions() ?? [] as $condition) {
                if ($condition->getType() !== self::CONDITION_GOODS_PRICE) {
                    continue;
                }

                $value = $condition->getValue() ?? [];
                if (!\in_array($value['operator'] ?? '', ['>', '>='], true)) {
                    continue;
                }

                $amount = $value['amount'] ?? null;
                if (!\is_int($amount) && !\is_float($amount)) {
                    continue;
                }

                $lowest = $lowest === null ? (float) $amount : min($lowest, (float) $amount);
            }
        }

        return $lowest;
    }

    /**
     * Die Länder, in denen mindestens eine der eingestellten Versandarten greifen kann.
     *
     * `null` heißt: nicht ermittelbar — dann trägt der Aufrufer die Unsicherheit, nicht
     * dieser Dienst.
     *
     * @return array<string, string>|null Kennung => angezeigter Name
     */
    private function allowedCountries(ShippingMethodCollection $methods, SalesChannelContext $context): ?array
    {
        $countryIds = [];
        $found = false;

        foreach ($methods as $method) {
            $rule = $method->getAvailabilityRule();
            if ($rule === null) {
                // Eine Versandart ohne Regel ist überall verfügbar — damit ist die Frage
                // nach dem Land beantwortet, und zwar mit „überall".
                return null;
            }

            foreach ($rule->getConditions() ?? [] as $condition) {
                if ($condition->getType() !== self::CONDITION_COUNTRY) {
                    continue;
                }

                $value = $condition->getValue() ?? [];
                if (($value['operator'] ?? '=') !== '=' || !\is_array($value['countryIds'] ?? null)) {
                    // Eine Ausschluss-Bedingung („alles außer diesen Ländern") lässt sich
                    // ohne die Liste aller Länder nicht in eine Erlaubnis übersetzen.
                    return null;
                }

                $found = true;
                foreach ($value['countryIds'] as $countryId) {
                    if (\is_string($countryId)) {
                        $countryIds[] = $countryId;
                    }
                }
            }
        }

        if (!$found) {
            return null;
        }

        // Die Namen erst jetzt holen, und nur die gebrauchten: Der Hinweis nennt sie
        // dem Gast im Text, damit aus einer Zusage eine Bedingung wird.
        $criteria = new Criteria(array_values(array_unique($countryIds)));
        $countries = $this->countryRepository->search($criteria, $context->getContext())->getEntities();

        $result = [];
        foreach ($countries as $country) {
            $result[$country->getId()] = (string) ($country->getTranslation('name') ?? $country->getName());
        }

        return $result;
    }
}
