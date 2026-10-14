<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Struct;

use Shopware\Core\Checkout\Cart\Cart;

/**
 * Die zwei Maße, an denen sich entscheidet, ob eine Sendung noch ins Auto passt:
 * das Gesamtgewicht und die längste einzelne Position.
 *
 * Das Gewicht wird summiert, die Länge nicht. Zwanzig Bleche zu je 15 kg sind 300 kg und
 * brauchen einen Stapler; zwanzig Handläufe zu je sechs Metern sind dagegen nicht 120 Meter
 * lang, sondern zwanzigmal zu lang fürs Auto. Maßgeblich ist deshalb das längste Stück.
 *
 * Positionen ohne Versandangaben zählen als 0: Ohne gepflegte Maße gibt es keinen Hinweis auf
 * Verdacht.
 */
final class CartMeasurements
{
    /**
     * @param float $totalWeight   Summe über alle Warenpositionen, in Kilogramm
     * @param float $longestLength längste einzelne Position, in Millimetern
     */
    private function __construct(
        public readonly float $totalWeight,
        public readonly float $longestLength,
    ) {
    }

    public static function fromCart(Cart $cart): self
    {
        $weight = 0.0;
        $longest = 0.0;

        // `filterGoodsFlat()` lässt Rabatte, Gutscheine und Versandkosten draußen und löst
        // Bündel auf; gewogen wird nur, was tatsächlich transportiert wird.
        foreach ($cart->getLineItems()->filterGoodsFlat() as $lineItem) {
            $delivery = $lineItem->getDeliveryInformation();
            if ($delivery === null) {
                continue;
            }

            $weight += ($delivery->getWeight() ?? 0.0) * $lineItem->getQuantity();
            $longest = max($longest, $delivery->getLength() ?? 0.0);
        }

        return new self($weight, $longest);
    }

    /**
     * Überschreitet der Warenkorb eine der beiden Schwellen?
     *
     * Ein Treffer genügt, und eine nicht gesetzte Schwelle (`null`) zählt nie mit. Sind beide
     * leer, ist die Antwort immer `false`; so schaltet der Betreiber den Hinweis ohne eigenen
     * Schalter ab.
     */
    public function exceeds(?float $weightThreshold, ?float $lengthThreshold): bool
    {
        if ($weightThreshold !== null && $this->totalWeight > $weightThreshold) {
            return true;
        }

        return $lengthThreshold !== null && $this->longestLength > $lengthThreshold;
    }
}
