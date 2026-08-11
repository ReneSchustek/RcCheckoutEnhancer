<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreeShippingStatus;

final class FreeShippingStatusTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        $status = new FreeShippingStatus(
            threshold: 50.0,
            remaining: 12.5,
            achieved: false,
            currencyIsoCode: 'EUR',
        );

        self::assertSame(50.0, $status->threshold);
        self::assertSame(12.5, $status->remaining);
        self::assertFalse($status->achieved);
        self::assertSame('EUR', $status->currencyIsoCode);
    }

    public function testGetApiAlias(): void
    {
        $status = new FreeShippingStatus(0.0, 0.0, true, 'EUR');

        self::assertSame('rc_free_shipping_status', $status->getApiAlias());
    }
}
