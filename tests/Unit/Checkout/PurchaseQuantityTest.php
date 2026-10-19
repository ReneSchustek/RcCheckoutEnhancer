<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Checkout;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Checkout\PurchaseQuantity;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;

/**
 * Die bestellbare Menge, mit der der Rechner der Produktseite rechnet. Weicht sie von der Bestellung ab, nennt die
 * Auskunft einen Preis für eine Menge, die sich gar nicht bestellen lässt.
 */
#[CoversClass(PurchaseQuantity::class)]
final class PurchaseQuantityTest extends TestCase
{
    /**
     * @return iterable<string, array{0: int, 1: int|null, 2: int|null, 3: int, 4: int}>
     */
    public static function cases(): iterable
    {
        // Eingabe, Mindestabnahme, Staffel, Höchstabnahme => erwartet
        yield '0 heißt Mindestabnahme' => [0, 5, null, 100, 5];
        yield 'unter der Mindestabnahme' => [2, 5, null, 100, 5];
        yield 'über der Höchstabnahme' => [500, 1, null, 100, 100];
        yield 'auf die Staffel abgerundet' => [9, 2, 3, 100, 8];
        yield 'Staffel trifft' => [11, 2, 3, 100, 11];
        yield 'Abrunden bleibt unter der Höchstabnahme' => [100, 2, 3, 100, 98];
        yield 'ohne Angaben' => [7, null, null, 100, 7];
    }

    #[DataProvider('cases')]
    public function testTheOrderableQuantity(int $input, ?int $min, ?int $step, int $max, int $expected): void
    {
        $product = new SalesChannelProductEntity();
        if ($min !== null) {
            $product->setMinPurchase($min);
        }
        if ($step !== null) {
            $product->setPurchaseSteps($step);
        }
        $product->setCalculatedMaxPurchase($max);

        self::assertSame($expected, PurchaseQuantity::orderable($product, $input));
    }
}
