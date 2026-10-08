<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Checkout\ShippingMethodAvailability;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Ob eine Versandart für diesen Kontext verfügbar ist. Die Warenkorbseite des Kerns hängt die
 * eingestellte Versandart an ihre Liste an, auch wenn ihre Regel nicht greift.
 */
final class ShippingMethodAvailabilityTest extends TestCase
{
    public function testWithoutARuleAMethodIsAvailable(): void
    {
        self::assertTrue(ShippingMethodAvailability::isAvailable($this->method('ohne', null), $this->context([])));
    }

    public function testAMatchingRuleMakesItAvailable(): void
    {
        self::assertTrue(ShippingMethodAvailability::isAvailable($this->method('paket', 'regel-de'), $this->context(['regel-de'])));
    }

    public function testANonMatchingRuleMakesItUnavailable(): void
    {
        self::assertFalse(ShippingMethodAvailability::isAvailable($this->method('platzhalter', 'regel-plz-leer'), $this->context(['regel-de'])));
    }

    public function testOnlyAvailableMethodsRemain(): void
    {
        $listed = new ShippingMethodCollection([
            $this->method('paket', 'regel-de'),
            $this->method('platzhalter', 'regel-plz-leer'),
        ]);

        self::assertSame(['paket'], array_values(ShippingMethodAvailability::availableOnly($listed, $this->context(['regel-de']))->getIds()));
    }

    private function method(string $id, ?string $ruleId): ShippingMethodEntity
    {
        $method = new ShippingMethodEntity();
        $method->setId($id);
        $method->setUniqueIdentifier($id);
        $method->setAvailabilityRuleId($ruleId);

        return $method;
    }

    /**
     * @param list<string> $ruleIds
     */
    private function context(array $ruleIds): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getRuleIds')->willReturn($ruleIds);

        return $context;
    }
}
