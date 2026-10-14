<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Struct;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Struct\CartMeasurements;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryInformation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;

/**
 * Die zwei Maße eines Warenkorbs — und warum sie unterschiedlich gerechnet werden.
 */
final class CartMeasurementsTest extends TestCase
{
    /**
     * Was: Gewicht mal Menge, über alle Positionen summiert.
     * Warum: Gewicht addiert sich. Zwanzig Bleche zu 15 kg sind 300 kg und brauchen einen
     *        Stapler — die Menge wegzulassen wäre der Fehler, der genau den Warenkorb
     *        durchwinkt, um den es hier geht.
     */
    public function testTheWeightIsSummedAcrossQuantities(): void
    {
        $cart = $this->cartWith(
            $this->goods('li-1', quantity: 20, weight: 15.0, length: 400.0),
            $this->goods('li-2', quantity: 2, weight: 5.0, length: 400.0),
        );

        self::assertSame(310.0, CartMeasurements::fromCart($cart)->totalWeight);
    }

    /**
     * Was: Die Länge ist die des längsten Stücks, nicht die Summe.
     * Warum: Zwanzig Handläufe zu sechs Metern sind nicht 120 Meter lang, sondern
     *        zwanzigmal zu lang fürs Auto. Wer hier summiert, meldet bei jedem größeren
     *        Warenkorb sperrige Ware.
     */
    public function testTheLengthIsTheLongestItemAndNotTheSum(): void
    {
        $cart = $this->cartWith(
            $this->goods('li-1', quantity: 20, weight: 1.0, length: 6000.0),
            $this->goods('li-2', quantity: 1, weight: 1.0, length: 800.0),
        );

        self::assertSame(6000.0, CartMeasurements::fromCart($cart)->longestLength);
    }

    /**
     * Was: Eine Position ohne Versandangaben.
     * Warum: Sie darf die Rechnung nicht abbrechen und nicht raten. Wer keine Maße gepflegt
     *        hat, bekommt keinen Hinweis auf Verdacht — das ist die vorsichtige Richtung.
     */
    public function testItemsWithoutDeliveryInformationCountAsZero(): void
    {
        $ohneAngaben = new LineItem('li-2', LineItem::PRODUCT_LINE_ITEM_TYPE, 'ref-2', 1);
        $ohneAngaben->setGood(true);
        $ohneAngaben->setPrice(new CalculatedPrice(10.0, 10.0, new CalculatedTaxCollection(), new TaxRuleCollection(), 1));

        $measurements = CartMeasurements::fromCart(
            $this->cartWith($this->goods('li-1', quantity: 1, weight: 12.0, length: 500.0), $ohneAngaben)
        );

        self::assertSame(12.0, $measurements->totalWeight);
        self::assertSame(500.0, $measurements->longestLength);
    }

    /**
     * Was: Ein Rabatt im Warenkorb.
     * Warum: `filterGoodsFlat()` lässt ihn draußen — gewogen wird nur, was transportiert
     *        wird. Ein Gutschein ohne Versandangaben würde sonst durch die Rechnung laufen.
     */
    public function testNonGoodsAreLeftOut(): void
    {
        $rabatt = new LineItem('discount', LineItem::PROMOTION_LINE_ITEM_TYPE, 'promo', 1);
        $rabatt->setGood(false);
        $rabatt->setPrice(new CalculatedPrice(-10.0, -10.0, new CalculatedTaxCollection(), new TaxRuleCollection(), 1));

        $cart = $this->cartWith($this->goods('li-1', quantity: 1, weight: 8.0, length: 300.0), $rabatt);

        self::assertSame(8.0, CartMeasurements::fromCart($cart)->totalWeight);
    }

    public function testAnEmptyCartMeasuresZero(): void
    {
        $measurements = CartMeasurements::fromCart(new Cart('token'));

        self::assertSame(0.0, $measurements->totalWeight);
        self::assertSame(0.0, $measurements->longestLength);
    }

    /**
     * Was: Ein Treffer bei einer der beiden Schwellen genügt.
     * Warum: Der leichte, aber sechs Meter lange Handlauf ist genauso wenig ein Fall fürs
     *        Auto wie die schwere, aber kurze Palette.
     */
    public function testOneOfTheTwoThresholdsIsEnough(): void
    {
        $leichtUndLang = CartMeasurements::fromCart($this->cartWith($this->goods('li-1', 1, 3.0, 6000.0)));
        $schwerUndKurz = CartMeasurements::fromCart($this->cartWith($this->goods('li-1', 1, 300.0, 400.0)));

        self::assertTrue($leichtUndLang->exceeds(50.0, 2000.0));
        self::assertTrue($schwerUndKurz->exceeds(50.0, 2000.0));
    }

    public function testBelowBothThresholdsNothingIsExceeded(): void
    {
        $measurements = CartMeasurements::fromCart($this->cartWith($this->goods('li-1', 1, 3.0, 400.0)));

        self::assertFalse($measurements->exceeds(50.0, 2000.0));
    }

    /**
     * Was: Genau auf der Schwelle.
     * Warum: Die Grenze ist der Wert, der noch geht. Wer 50 kg einstellt, meint „ab mehr als
     *        50 kg" — sonst löst die eingestellte Zahl selbst schon aus.
     */
    public function testExactlyOnTheThresholdIsStillFine(): void
    {
        $measurements = CartMeasurements::fromCart($this->cartWith($this->goods('li-1', 1, 50.0, 2000.0)));

        self::assertFalse($measurements->exceeds(50.0, 2000.0));
    }

    /**
     * Was: Eine nicht gesetzte Schwelle.
     * Warum: So schaltet der Betreiber den Hinweis ab. Zählte eine leere Schwelle als
     *        Null mit, träfe sie jeden Warenkorb, und der Hinweis stünde dauerhaft da.
     */
    public function testAnUnsetThresholdNeverMatches(): void
    {
        $measurements = CartMeasurements::fromCart($this->cartWith($this->goods('li-1', 1, 300.0, 6000.0)));

        self::assertFalse($measurements->exceeds(null, null));
        self::assertTrue($measurements->exceeds(50.0, null), 'Die gesetzte Schwelle wirkt weiter.');
    }

    private function cartWith(LineItem ...$lineItems): Cart
    {
        $cart = new Cart('token');
        foreach ($lineItems as $lineItem) {
            $cart->add($lineItem);
        }

        return $cart;
    }

    private function goods(string $id, int $quantity, float $weight, float $length): LineItem
    {
        $lineItem = new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, 'ref-' . $id, $quantity);
        $lineItem->setGood(true);
        $lineItem->setStackable(true);
        $lineItem->setPrice(new CalculatedPrice(1.0, $quantity, new CalculatedTaxCollection(), new TaxRuleCollection(), $quantity));
        $lineItem->setDeliveryInformation(new DeliveryInformation(100, $weight, false, null, null, 100.0, 200.0, $length));

        return $lineItem;
    }
}
