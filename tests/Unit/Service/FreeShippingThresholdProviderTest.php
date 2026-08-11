<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreeShippingReach;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreeShippingReachability;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreeShippingThresholdProvider;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Die Stelle, an der der Versandkostenfrei-Betrag erfragt wird. Sie entscheidet die
 * Rangfolge: Regel schlägt Einstellung.
 */
final class FreeShippingThresholdProviderTest extends TestCase
{
    /**
     * Was: Die Regel nennt 357, die Einstellung 500.
     * Warum: **Der Kern.** Genau diese Lage lag am 2026-08-04 im Shop vor, und angezeigt
     *        wurde die falsche Zahl.
     */
    public function testTheRuleBeatsTheSetting(): void
    {
        $provider = $this->provider(fromRule: 357.0, configured: 500.0);

        self::assertSame(357.0, $provider->thresholdFor($this->createMock(SalesChannelContext::class)));
    }

    /**
     * Was: Aus der Regel lässt sich nichts ablesen.
     * Warum: Dann bleibt die Einstellung maßgeblich — der Dienst rät nicht.
     */
    public function testWithoutARuleAmountTheSettingApplies(): void
    {
        $provider = $this->provider(fromRule: null, configured: 500.0);

        self::assertSame(500.0, $provider->thresholdFor($this->createMock(SalesChannelContext::class)));
    }

    /**
     * Was: Weder Regel noch brauchbare Einstellung.
     * Warum: Dann gibt es keinen Betrag — und die Zeile mit dem Platzhalter fällt aus der
     *        Vertrauensleiste, statt eine erfundene Zahl zu zeigen.
     */
    public function testWithoutAnythingThereIsNoThreshold(): void
    {
        $provider = $this->provider(fromRule: null, configured: null);

        self::assertNull($provider->thresholdFor($this->createMock(SalesChannelContext::class)));
    }

    private function provider(?float $fromRule, ?float $configured): FreeShippingThresholdProvider
    {
        $reachability = $this->createMock(FreeShippingReachability::class);
        $reachability->method('reachableFrom')->willReturn(
            $fromRule === null ? FreeShippingReach::unknown() : FreeShippingReach::reachable([], [], $fromRule),
        );

        $configService = $this->createMock(ConfigService::class);
        $configService->method('getFreeShippingThreshold')->willReturn($configured);
        $configService->method('getFreeShippingMethodIds')->willReturn([]);

        return new FreeShippingThresholdProvider($configService, $reachability);
    }
}
