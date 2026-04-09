<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(ConfigService::class)]
final class ConfigServiceTest extends TestCase
{
    private SystemConfigService&MockObject $systemConfigService;
    private ConfigService $configService;

    protected function setUp(): void
    {
        $this->systemConfigService = $this->createMock(SystemConfigService::class);
        $this->configService = new ConfigService($this->systemConfigService);
    }

    #[Test]
    public function progressBarEnabledReturnsTrueByDefault(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);

        self::assertTrue($this->configService->isProgressBarEnabled());
    }

    #[Test]
    public function progressBarEnabledReturnsFalseWhenDisabled(): void
    {
        $this->systemConfigService->method('get')->willReturn(false);

        self::assertFalse($this->configService->isProgressBarEnabled());
    }

    #[Test]
    public function trustBadgesEnabledReturnsTrueByDefault(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);

        self::assertTrue($this->configService->isTrustBadgesEnabled());
    }

    #[Test]
    public function miniCartEnabledReturnsTrueByDefault(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);

        self::assertTrue($this->configService->isMiniCartEnabled());
    }

    #[Test]
    public function orderSummaryEnabledReturnsTrueByDefault(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);

        self::assertTrue($this->configService->isOrderSummaryEnabled());
    }

    #[Test]
    public function deliveryTimeEnabledReturnsFalseByDefault(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);

        self::assertFalse($this->configService->isDeliveryTimeEnabled());
    }

    #[Test]
    public function estimatedDeliveryTimeReturnsEmptyStringByDefault(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);

        self::assertSame('', $this->configService->getEstimatedDeliveryTime());
    }

    #[Test]
    public function estimatedDeliveryTimeReturnsConfiguredValue(): void
    {
        $this->systemConfigService->method('get')->willReturn('3-5 Werktage');

        self::assertSame('3-5 Werktage', $this->configService->getEstimatedDeliveryTime());
    }

    #[Test]
    public function getTrustBadgesParsesMultipleLines(): void
    {
        $raw = "lock;Sichere Bestellung\ntruck;Kostenloser Versand\nundo;14 Tage Widerrufsrecht";
        $this->systemConfigService->method('get')->willReturn($raw);

        $badges = $this->configService->getTrustBadges();

        self::assertCount(3, $badges);
        self::assertSame('lock', $badges[0]['icon']);
        self::assertSame('Sichere Bestellung', $badges[0]['text']);
        self::assertSame('truck', $badges[1]['icon']);
        self::assertSame('Kostenloser Versand', $badges[1]['text']);
        self::assertSame('undo', $badges[2]['icon']);
        self::assertSame('14 Tage Widerrufsrecht', $badges[2]['text']);
    }

    #[Test]
    public function getTrustBadgesParsesLineWithoutIcon(): void
    {
        $this->systemConfigService->method('get')->willReturn('Nur Text ohne Icon');

        $badges = $this->configService->getTrustBadges();

        self::assertCount(1, $badges);
        self::assertSame('', $badges[0]['icon']);
        self::assertSame('Nur Text ohne Icon', $badges[0]['text']);
    }

    #[Test]
    public function getTrustBadgesReturnsEmptyArrayForEmptyConfig(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);

        self::assertSame([], $this->configService->getTrustBadges());
    }

    #[Test]
    public function getTrustBadgesSkipsEmptyLines(): void
    {
        $raw = "lock;Zeile 1\n\n\ntruck;Zeile 2\n";
        $this->systemConfigService->method('get')->willReturn($raw);

        $badges = $this->configService->getTrustBadges();

        self::assertCount(2, $badges);
    }

    #[Test]
    public function getProgressStepLabelsReturnsConfiguredValues(): void
    {
        $this->systemConfigService->method('get')
            ->willReturnCallback(static fn (string $key): string => match ($key) {
                'RcCheckoutEnhancer.config.progressStep1' => 'Warenkorb',
                'RcCheckoutEnhancer.config.progressStep2' => 'Anmelden',
                'RcCheckoutEnhancer.config.progressStep3' => 'Prüfen',
                'RcCheckoutEnhancer.config.progressStep4' => 'Fertig',
                default => '',
            });

        $labels = $this->configService->getProgressStepLabels();

        self::assertSame('Warenkorb', $labels['step1']);
        self::assertSame('Anmelden', $labels['step2']);
        self::assertSame('Prüfen', $labels['step3']);
        self::assertSame('Fertig', $labels['step4']);
    }

    #[Test]
    public function getProgressStepLabelsReturnsEmptyStringsWhenNotConfigured(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);

        $labels = $this->configService->getProgressStepLabels();

        self::assertSame('', $labels['step1']);
        self::assertSame('', $labels['step2']);
        self::assertSame('', $labels['step3']);
        self::assertSame('', $labels['step4']);
    }

    #[Test]
    public function cachePreventsDuplicateSystemConfigCalls(): void
    {
        $this->systemConfigService->expects(self::once())
            ->method('get')
            ->with('RcCheckoutEnhancer.config.progressBarEnabled', null)
            ->willReturn(true);

        $this->configService->isProgressBarEnabled();
        $this->configService->isProgressBarEnabled();
    }

    #[Test]
    public function salesChannelIdIsPassedToSystemConfig(): void
    {
        $channelId = 'test-channel-id-123';

        $this->systemConfigService->expects(self::once())
            ->method('get')
            ->with('RcCheckoutEnhancer.config.progressBarEnabled', $channelId)
            ->willReturn(false);

        self::assertFalse($this->configService->isProgressBarEnabled($channelId));
    }

    #[Test]
    public function differentSalesChannelsAreCachedSeparately(): void
    {
        $this->systemConfigService->expects(self::exactly(2))
            ->method('get')
            ->willReturnCallback(static fn (string $key, ?string $channelId): bool => match ($channelId) {
                'channel-a' => true,
                'channel-b' => false,
                default => true,
            });

        self::assertTrue($this->configService->isProgressBarEnabled('channel-a'));
        self::assertFalse($this->configService->isProgressBarEnabled('channel-b'));
    }
}
