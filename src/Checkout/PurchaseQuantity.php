<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Checkout;

use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;

/**
 * Die Menge, die sich von einem Artikel bestellen lässt. Reine Rechnung, kein Dienst.
 */
final class PurchaseQuantity
{
    /**
     * Die bestellbare Menge: nicht unter der Mindestabnahme, nicht über der Höchstabnahme, auf die Staffel abgerundet.
     */
    public static function orderable(SalesChannelProductEntity $product, int $quantity): int
    {
        $min = max(1, $product->getMinPurchase() ?? 1);
        $step = max(1, $product->getPurchaseSteps() ?? 1);
        $max = max($min, $product->getCalculatedMaxPurchase());

        $quantity = min(max($quantity, $min), $max);

        // Abgerundet, weil Aufrunden über die Höchstabnahme hinausführen kann.
        return $min + intdiv($quantity - $min, $step) * $step;
    }
}
