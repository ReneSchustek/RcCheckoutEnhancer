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
 * Die Versandarten-Route, die der Versandkostenrechner fragt, taugt dafür nicht: Sie sagt, was
 * für den Warenkorb in diesem Moment verfügbar ist. Ein Warenkorb unter der Schwelle hat die
 * versandkostenfreie Versandart naturgemäß nicht, und genau dann soll der Hinweis erscheinen.
 * Die Route unterscheidet „fehlt noch am Betrag" nicht von „falsches Land".
 *
 * Gelesen werden deshalb die Land-Bedingungen der Verfügbarkeitsregel der eingestellten
 * Versandarten. Ändert der Betreiber die Regel, ändert sich die Antwort mit; ein „wenn Land = DE"
 * im Code liefe ihr hinterher.
 *
 * Verschachtelte Oder-Container werden nicht ausgewertet. Im Zweifel, also ohne lesbare
 * Land-Bedingung oder ohne eingestellte Versandart, lautet die Antwort „ja": Lieber ein Hinweis
 * mit Bedingung im Text als ein Shop, der still aufhört zu werben.
 *
 * Nicht `final`, weil die Tests der Vertrauensleiste und des Indikators ihn als Test-Double
 * ersetzen.
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
     * Der Betrag steht nur in der Regel; eine zweite Stelle in der Einstellung oder im Freitext
     * der Vertrauensleiste liefe früher oder später auseinander.
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
     * `null` heißt: nicht ermittelbar. Dann trägt der Aufrufer die Unsicherheit.
     *
     * @return array<string, string>|null Kennung => angezeigter Name
     */
    private function allowedCountries(ShippingMethodCollection $methods, SalesChannelContext $context): ?array
    {
        $countryIds = $this->collectCountryIds($methods);
        if ($countryIds === null) {
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

    /**
     * `null` heißt „nicht in eine Erlaubnis übersetzbar" und hat drei Gründe: eine Versandart ohne
     * Regel (die greift überall), eine Ausschluss-Bedingung, oder in keiner Regel eine
     * Länder-Bedingung.
     *
     * @return array<int, string>|null
     */
    private function collectCountryIds(ShippingMethodCollection $methods): ?array
    {
        $countryIds = [];

        foreach ($methods as $method) {
            $rule = $method->getAvailabilityRule();
            if ($rule === null) {
                return null;
            }

            // Jede Versandart für sich: Eine ohne Länder-Bedingung liefert überall kostenlos. Ließe
            // man sie aus, weil eine andere Länder nennt, schwiege der Hinweis in Ländern, in denen
            // sie gilt.
            $restricted = false;
            foreach ($rule->getConditions() ?? [] as $condition) {
                if ($condition->getType() !== self::CONDITION_COUNTRY) {
                    continue;
                }

                $ids = $this->countryIdsOf($condition->getValue() ?? []);
                if ($ids === null) {
                    return null;
                }

                $restricted = true;
                $countryIds = array_merge($countryIds, $ids);
            }

            if (!$restricted) {
                return null;
            }
        }

        return $countryIds === [] ? null : $countryIds;
    }

    /**
     * `null` heißt: Diese Bedingung ist keine Erlaubnis. Eine Ausschluss-Bedingung („alles außer
     * diesen Ländern") lässt sich ohne die Liste aller Länder nicht umdrehen.
     *
     * @param array<string, mixed> $value
     *
     * @return array<int, string>|null
     */
    private function countryIdsOf(array $value): ?array
    {
        if (($value['operator'] ?? '=') !== '=' || !\is_array($value['countryIds'] ?? null)) {
            return null;
        }

        $ids = [];
        foreach ($value['countryIds'] as $countryId) {
            if (\is_string($countryId)) {
                $ids[] = $countryId;
            }
        }

        return $ids;
    }
}
