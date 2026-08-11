<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class FreeShippingService
{
    public function calculate(Cart $cart, SalesChannelContext $context, float $threshold): FreeShippingStatus
    {
        // Positions-Summe (Warenwert ohne Versand) — getPositionPrice() statt getTotalPrice(),
        // weil sonst der Versand-Schwellenwert sich selbst kompensieren würde.
        // Hinweis: In einem netto-anzeigenden Kanal (B2B) ist dieser Wert netto; der Schwellenwert
        // ist als Brutto in Shop-Standardwährung konfiguriert. Für Brutto-Kanäle (B2C-Regelfall)
        // stimmt der Vergleich; die volle Steuer-State-Umrechnung ist eine bewusste Produktentscheidung.
        // Der Indikator ist ein konfigurierter Marketing-Hinweis, kein Abgleich mit realen Lieferkosten.
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
