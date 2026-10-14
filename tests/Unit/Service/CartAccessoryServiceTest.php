<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\CartAccessoryService;

/**
 * Prüft die Mischung von „Dazu passt" und die Erkennung von Bausätzen für „Haben Sie an alles gedacht?".
 */
#[CoversClass(CartAccessoryService::class)]
final class CartAccessoryServiceTest extends TestCase
{
    /**
     * Was: Zwei Listen „Oft zusammen gekauft", ein Produkt liegt schon im Warenkorb (als Variante), eines steht in
     * beiden.
     * Erwartet: abwechselnd gemischt, ohne Warenkorb und ohne Doppelte, höchstens so viele wie verlangt.
     */
    public function testMixAlternatesAndSkipsOwnedAndDuplicates(): void
    {
        $a = [['id' => 'a1', 'parentId' => null], ['id' => 'a2', 'parentId' => null], ['id' => 'a3', 'parentId' => null]];
        $b = [['id' => 'b1v', 'parentId' => 'b1'], ['id' => 'a2', 'parentId' => null], ['id' => 'b3', 'parentId' => null]];

        self::assertSame(['a1', 'a2', 'a3', 'b3'], CartAccessoryService::mix([$a, $b], ['b1' => true], 4));
        self::assertSame(['a1'], CartAccessoryService::mix([$a, $b], ['b1' => true], 1));
        self::assertSame([], CartAccessoryService::mix([], [], 4));
    }

    public function testIsKit(): void
    {
        self::assertTrue(CartAccessoryService::isKit(['ROOT', 'KITS', 'GLASS'], ['kits']));
        self::assertFalse(CartAccessoryService::isKit(['root', 'parts'], ['kits']));
        self::assertFalse(CartAccessoryService::isKit(['kits'], []));
    }
}
