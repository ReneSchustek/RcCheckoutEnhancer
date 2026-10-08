<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Vergleicht den Warenwert des Warenkorbs mit der Versandkostenfrei-Schwelle, umgerechnet in die
 * Währung des Besuchers, und sagt, wie viel noch fehlt.
 *
 * Nicht `final`, weil die Tests des Indikator-Subscribers ihn als Test-Double ersetzen.
 */
class FreeShippingService
{
    public function calculate(Cart $cart, SalesChannelContext $context, float $threshold): FreeShippingStatus
    {
        // Der Warenwert ohne Versand: Mit getTotalPrice() zählten die Versandkosten selbst zur
        // Schwelle, die sie aufheben sollen.
        // In einem Netto-Kanal (B2B) ist dieser Wert netto, die Schwelle aber brutto
        // eingestellt. Umgerechnet wird trotzdem nicht: Der Indikator ist ein Werbehinweis und
        // kein Abgleich mit den echten Versandkosten, und im Brutto-Kanal stimmt der Vergleich.
        $cartPositionPrice = $cart->getPrice()->getPositionPrice();

        // Schwelle in die aktive Kontext-Währung umrechnen (Standardwährung: Faktor 1,0),
        // sonst vergleicht ein Fremdwährungs-Warenkorb gegen einen Wert der Standardwährung.
        $thresholdInContext = round($threshold * $context->getCurrency()->getFactor(), 2);
        $remaining = round(max(0.0, $thresholdInContext - $cartPositionPrice), 2);
        $achieved = $cartPositionPrice >= $thresholdInContext;

        return new FreeShippingStatus(
            threshold: $thresholdInContext,
            remaining: $remaining,
            achieved: $achieved,
            currencyIsoCode: $context->getCurrency()->getIsoCode(),
        );
    }
}
