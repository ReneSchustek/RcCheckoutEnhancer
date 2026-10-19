<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Checkout;

use Shopware\Core\System\Currency\CurrencyEntity;

/**
 * Beträge, wie dieses Plugin sie vergleicht und umrechnet. Reine Rechnung, kein Dienst.
 */
final class Amount
{
    /**
     * Unterhalb eines halben Cents gilt ein Betrag als null.
     *
     * Ein Vergleich `> 0.0` auf einem Fließkommawert wäre eine Wette darauf, dass eine Summe aus Rundungen exakt null
     * trifft. Trifft sie es um ein Zehntausendstel nicht, verschwände ein Hinweis, der völlig richtig wäre.
     */
    public const CENT_TOLERANCE = 0.005;

    public static function isPositive(float $amount): bool
    {
        return $amount > self::CENT_TOLERANCE;
    }

    /**
     * Ein Betrag der Standardwährung (etwa die Versandkostenfrei-Schwelle) in der Währung des Besuchers, auf Cent
     * gerundet. Überall dieselbe Rechnung, sonst nennten Hinweis und Vertrauensleiste in Franken zwei Beträge.
     */
    public static function inCurrency(float $amount, CurrencyEntity $currency): float
    {
        return round($amount * $currency->getFactor(), 2);
    }
}
