<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\CartFingerprint;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;

/**
 * Der Fingerabdruck entscheidet, ob ein gespeicherter Versandpreis noch gilt. Er darf
 * weder zu empfindlich sein — dann steht in der Seitenleiste nie eine Zahl — noch zu
 * grob, denn dann steht dort eine falsche.
 */
final class CartFingerprintTest extends TestCase
{
    /**
     * Was: Derselbe Warenkorb, zweimal gefragt.
     * Warum: Wäre der Wert nicht stabil, wäre jede Auskunft sofort veraltet.
     */
    public function testTheSameCartYieldsTheSameValue(): void
    {
        $fingerprint = new CartFingerprint();

        self::assertSame(
            $fingerprint->of($this->cart(['a' => 1])),
            $fingerprint->of($this->cart(['a' => 1])),
        );
    }

    /**
     * Was: Eine geänderte Menge.
     * Warum: Menge bestimmt Gewicht und Umfang und damit den Versandpreis.
     */
    public function testADifferentQuantityChangesTheValue(): void
    {
        $fingerprint = new CartFingerprint();

        self::assertNotSame(
            $fingerprint->of($this->cart(['a' => 1])),
            $fingerprint->of($this->cart(['a' => 2])),
        );
    }

    /**
     * Was: Eine zusätzliche Position.
     */
    public function testAnAdditionalLineItemChangesTheValue(): void
    {
        $fingerprint = new CartFingerprint();

        self::assertNotSame(
            $fingerprint->of($this->cart(['a' => 1])),
            $fingerprint->of($this->cart(['a' => 1, 'b' => 1])),
        );
    }

    /**
     * Was: Dieselben Positionen in anderer Reihenfolge.
     * Warum: Shopware ordnet Positionen bei manchen Vorgängen neu an, ohne dass am Inhalt
     *        etwas anders wäre. Wäre die Reihenfolge maßgeblich, gälte die Auskunft nach
     *        einem solchen Vorgang grundlos als veraltet.
     */
    public function testTheOrderOfLineItemsDoesNotMatter(): void
    {
        $fingerprint = new CartFingerprint();

        self::assertSame(
            $fingerprint->of($this->cart(['a' => 1, 'b' => 2])),
            $fingerprint->of($this->cart(['b' => 2, 'a' => 1])),
        );
    }

    /**
     * Was: Gleiche Positionen, anderer Warenkorbwert.
     * Warum: An der Summe hängen die Versandkostenfrei-Grenze und wertabhängige Preise.
     */
    public function testADifferentTotalChangesTheValue(): void
    {
        $fingerprint = new CartFingerprint();

        self::assertNotSame(
            $fingerprint->of($this->cart(['a' => 1], total: 10.0)),
            $fingerprint->of($this->cart(['a' => 1], total: 20.0)),
        );
    }

    /**
     * Was: Derselbe Inhalt in einem Warenkorb mit anderem Token.
     * Warum: Der Token wechselt beim Anmelden, ohne dass sich am Inhalt etwas ändert —
     *        die Auskunft wäre sonst grundlos veraltet.
     */
    public function testTheCartTokenIsNotPartOfTheValue(): void
    {
        $fingerprint = new CartFingerprint();

        self::assertSame(
            $fingerprint->of($this->cart(['a' => 1], token: 'token-eins')),
            $fingerprint->of($this->cart(['a' => 1], token: 'token-zwei')),
        );
    }

    /**
     * @param array<string, int> $lineItems Kennung => Menge
     */
    private function cart(array $lineItems, float $total = 10.0, string $token = 'token'): Cart
    {
        $cart = new Cart($token);

        foreach ($lineItems as $id => $quantity) {
            $cart->add(new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, 'ref-' . $id, $quantity));
        }

        $cart->setPrice(new CartPrice(
            $total,
            $total,
            $total,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
            CartPrice::TAX_STATE_GROSS,
        ));

        return $cart;
    }
}
