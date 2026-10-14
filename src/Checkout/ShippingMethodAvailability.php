<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Checkout;

use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Ob eine Versandart für den laufenden Warenkorb verfügbar ist, gemessen an ihrer
 * Verfügbarkeitsregel und den Regeln, die der Kern beim Rechnen als erfüllt vermerkt hat.
 *
 * Der Warenkorb-Loader des Kerns hängt die Versandart aus dem Kontext an die Liste an, auch wenn
 * ihre Regel nicht greift. Wer die Liste der Warenkorbseite als „verfügbar" liest, irrt deshalb;
 * diese Prüfung trennt das.
 */
final class ShippingMethodAvailability
{
    public static function isAvailable(ShippingMethodEntity $method, SalesChannelContext $context): bool
    {
        $ruleId = $method->getAvailabilityRuleId();

        return $ruleId === null || \in_array($ruleId, $context->getRuleIds(), true);
    }

    public static function availableOnly(ShippingMethodCollection $methods, SalesChannelContext $context): ShippingMethodCollection
    {
        return $methods->filter(
            static fn (ShippingMethodEntity $method): bool => self::isAvailable($method, $context),
        );
    }
}
