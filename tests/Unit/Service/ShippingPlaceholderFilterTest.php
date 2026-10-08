<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingPlaceholderFilter;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Der Platzhalter steht nur in der angezeigten Liste, wenn keine Lieferart übrig ist.
 *
 * Stünde er neben einer Lieferart, könnte ihn der Kunde anklicken, und die Vorauswahl schaltete
 * sofort zurück. Seine Wahl spränge dann ohne Erklärung um.
 */
final class ShippingPlaceholderFilterTest extends TestCase
{
    private const PLACEHOLDER = 'sm-placeholder';

    private const ABHOLUNG = 'sm-abholung';

    /**
     * Was: Neben dem Platzhalter steht eine verfügbare Lieferart.
     * Warum: Der Hauptfall, Paketware ohne Adresse.
     */
    public function testThePlaceholderLeavesWhenADeliveryMethodIsListed(): void
    {
        $filtered = $this->filter()->forDisplay(
            $this->methods([self::PLACEHOLDER => null, 'sm-paket' => null, self::ABHOLUNG => null]),
            $this->context(),
        );

        self::assertSame(['sm-paket', self::ABHOLUNG], array_values($filtered->getIds()));
    }

    /**
     * Was: Der Platzhalter steht im Kontext, weil die Vorauswahl ausgeschaltet ist.
     * Warum: Nähme der Filter ihn heraus, wäre in der Liste nichts angehakt.
     */
    public function testThePlaceholderStaysWhileItIsTheCurrentMethod(): void
    {
        $filtered = $this->filter()->forDisplay(
            $this->methods([self::PLACEHOLDER => null, 'sm-paket' => null]),
            $this->context(self::PLACEHOLDER),
        );

        self::assertSame([self::PLACEHOLDER, 'sm-paket'], array_values($filtered->getIds()));
    }

    /**
     * Was: Nur Platzhalter und Abholung.
     * Warum: Speditionsware ohne Adresse. Dann ist der Platzhalter das, was angehakt sein soll.
     */
    public function testThePlaceholderStaysWhenOnlyAPickupRemains(): void
    {
        $filtered = $this->filter()->forDisplay(
            $this->methods([self::PLACEHOLDER => null, self::ABHOLUNG => null]),
            $this->context(),
        );

        self::assertSame([self::PLACEHOLDER, self::ABHOLUNG], array_values($filtered->getIds()));
    }

    /**
     * Was: Die Lieferart steht in der Liste, ihre Verfügbarkeitsregel greift aber nicht.
     * Warum: Der Warenkorb-Loader des Kerns hängt die Kontext-Versandart an, auch wenn sie nicht
     *        verfügbar ist. Sie zählt nicht als Lieferart, sonst verschwände der Platzhalter,
     *        obwohl er gebraucht wird.
     */
    public function testAnUnavailableDeliveryMethodDoesNotCount(): void
    {
        $filtered = $this->filter()->forDisplay(
            $this->methods([self::PLACEHOLDER => null, 'sm-paket' => 'rule-bis-5-kg', self::ABHOLUNG => null]),
            $this->context(),
        );

        self::assertTrue($filtered->has(self::PLACEHOLDER));
    }

    /**
     * Was: Kein Platzhalter eingestellt.
     * Warum: Dann bleibt die Liste, wie sie ist. Eine Versandart, die zufällig so heißt, ist kein
     *        Platzhalter.
     */
    public function testWithoutAConfiguredPlaceholderTheListStays(): void
    {
        $listed = $this->methods([self::PLACEHOLDER => null, 'sm-paket' => null]);

        self::assertSame($listed, $this->filter(null)->forDisplay($listed, $this->context()));
    }

    private function filter(?string $placeholder = self::PLACEHOLDER): ShippingPlaceholderFilter
    {
        $configService = $this->createMock(ConfigService::class);
        $configService->method('getShippingPlaceholderMethodId')->willReturn($placeholder);
        $configService->method('getNonDeliveryMethodIds')->willReturn([self::ABHOLUNG]);

        return new ShippingPlaceholderFilter($configService);
    }

    /**
     * @param array<string, string|null> $methods Kennung => Verfügbarkeitsregel
     */
    private function methods(array $methods): ShippingMethodCollection
    {
        $entities = [];
        foreach ($methods as $id => $ruleId) {
            $method = new ShippingMethodEntity();
            $method->setId($id);
            $method->setUniqueIdentifier($id);
            $method->setAvailabilityRuleId($ruleId);
            $entities[] = $method;
        }

        return new ShippingMethodCollection($entities);
    }

    private function context(string $current = 'sm-paket'): SalesChannelContext
    {
        $shippingMethod = new ShippingMethodEntity();
        $shippingMethod->setId($current);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-id');
        $context->method('getRuleIds')->willReturn([]);
        $context->method('getShippingMethod')->willReturn($shippingMethod);

        return $context;
    }
}
