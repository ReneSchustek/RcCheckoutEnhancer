<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\CartExposedCustomFields;

/**
 * Die Liste der für den Warenkorb freigegebenen Zusatzfelder.
 */
final class CartExposedCustomFieldsTest extends TestCase
{
    public function testItAsksOnlyForExposedFieldsAndOnlyOnce(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchFirstColumn')
            ->with(self::stringContains('`allow_cart_expose` = 1'))
            ->willReturn(['wunschtermin']);

        $fields = new CartExposedCustomFields($connection);

        self::assertSame(['wunschtermin'], $fields->names());
        self::assertSame(['wunschtermin'], $fields->names());
    }

    public function testAResetAsksAgain(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('fetchFirstColumn')->willReturnOnConsecutiveCalls([], ['wunschtermin']);

        $fields = new CartExposedCustomFields($connection);
        $fields->names();
        $fields->reset();

        self::assertSame(['wunschtermin'], $fields->names());
    }
}
