<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\PaymentPlaceholderFilter;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;

/**
 * Prüft, dass die Platzhalter-Zahlart in keiner Auswahl steht, ohne eine Liste leer zu machen.
 */
#[CoversClass(PaymentPlaceholderFilter::class)]
final class PaymentPlaceholderFilterTest extends TestCase
{
    public function testThePlaceholderIsNeverListed(): void
    {
        $list = new PaymentMethodCollection([$this->method('choose'), $this->method('prepayment'), $this->method('invoice')]);

        self::assertSame(['prepayment', 'invoice'], array_values($this->ids(PaymentPlaceholderFilter::without($list, 'choose'))));
        self::assertSame(['choose', 'prepayment', 'invoice'], array_values($this->ids(PaymentPlaceholderFilter::without($list, null))));
    }

    public function testAListWithOnlyThePlaceholderStaysAsItIs(): void
    {
        $list = new PaymentMethodCollection([$this->method('choose')]);

        self::assertSame(['choose'], array_values($this->ids(PaymentPlaceholderFilter::without($list, 'choose'))));
    }

    private function method(string $id): PaymentMethodEntity
    {
        $method = new PaymentMethodEntity();
        $method->setId($id);

        return $method;
    }

    /**
     * @return array<string>
     */
    private function ids(PaymentMethodCollection $list): array
    {
        return $list->getIds();
    }
}
