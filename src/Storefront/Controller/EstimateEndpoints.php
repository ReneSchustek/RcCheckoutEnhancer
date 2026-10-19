<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Storefront\Controller;

/**
 * Was die drei Endpunkte der Versandauskunft (Warenkorb, Produktseite, Speditionshinweis) teilen.
 *
 * Geklickte Berechnungen zählen in allen drei gegen dieselbe Grenze, sonst verdoppelte jeder weitere Endpunkt das
 * Kontingent. Berechnungen ohne Klick (Speditionshinweis, gemerkte Anschrift auf der Produktseite) laufen bei jedem
 * Produktaufruf und haben eine eigene, großzügigere Staffel.
 */
final class EstimateEndpoints
{
    public const RESULT_TEMPLATE = '@Storefront/storefront/component/rc-checkout/shipping-estimate-result.html.twig';

    public const LIMIT_ESTIMATE = 'rc_checkout_shipping_estimate';

    public const LIMIT_PAGE_LOAD = 'rc_checkout_freight_hint';
}
