<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Shopware\Core\Checkout\Cart\Cart;

/**
 * Ein kurzer Wert, der sich ändert, sobald sich am Warenkorb etwas ändert, das den
 * Versandpreis beeinflussen kann.
 *
 * Einbezogen werden Positionen und Mengen, weil sie Gewicht und Umfang bestimmen, und die
 * Summe, weil an ihr die Versandkostenfrei-Grenze und wertabhängige Preise hängen. Der
 * Warenkorb-Token fehlt darin: Er wechselt beim Anmelden, ohne dass sich am Inhalt etwas
 * ändert, und die Auskunft wäre dann grundlos veraltet.
 */
final class CartFingerprint
{
    public function of(Cart $cart): string
    {
        $parts = [];

        foreach ($cart->getLineItems()->getFlat() as $lineItem) {
            $parts[] = $lineItem->getId() . ':' . $lineItem->getQuantity();
        }

        // Sortiert, damit die Reihenfolge im Warenkorb den Wert nicht verändert:
        // Shopware ordnet Positionen bei manchen Vorgängen neu an, ohne dass am
        // Inhalt etwas anders wäre.
        sort($parts);

        $parts[] = 'total:' . number_format($cart->getPrice()->getTotalPrice(), 2, '.', '');

        return hash('xxh128', implode('|', $parts));
    }
}
