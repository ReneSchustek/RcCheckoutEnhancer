<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
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

    /**
     * Die Vorgabe „an" ist keine Geschmacksfrage: Der Indikator lief vor der
     * Zusammenführung in einem eigenen Plugin und war dort standardmäßig an. Stünde er
     * hier auf „aus", schaltete ein Update eine laufende Funktion still ab.
     */
    #[Test]
    public function freeShippingIndicatorIsEnabledByDefault(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);

        self::assertTrue($this->configService->isFreeShippingIndicatorEnabled());
    }

    #[Test]
    public function freeShippingIndicatorCanBeSwitchedOff(): void
    {
        $this->systemConfigService->method('get')->willReturn(false);

        self::assertFalse($this->configService->isFreeShippingIndicatorEnabled());
    }

    /**
     * Der Rechner ist im Auslieferungszustand aus — er muss je Verkaufskanal
     * eingeschaltet werden.
     */
    #[Test]
    public function shippingEstimatorIsDisabledByDefault(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);

        self::assertFalse($this->configService->isShippingEstimatorEnabled());
    }

    #[Test]
    public function shippingEstimatorCanBeSwitchedOn(): void
    {
        $this->systemConfigService->method('get')->willReturn(true);

        self::assertTrue($this->configService->isShippingEstimatorEnabled());
    }

    #[Test]
    public function freeShippingThresholdIsNullWhenNothingIsConfigured(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);

        self::assertNull($this->configService->getFreeShippingThreshold());
    }

    #[Test]
    public function freeShippingThresholdAcceptsANumber(): void
    {
        $this->systemConfigService->method('get')->willReturn(357.0);

        self::assertSame(357.0, $this->configService->getFreeShippingThreshold());
    }

    /**
     * Genau so kommt der Wert aus der Shopware-Konfiguration zurück, wenn er über die
     * Konsole gesetzt wurde.
     */
    #[Test]
    public function freeShippingThresholdAcceptsANumericString(): void
    {
        $this->systemConfigService->method('get')->willReturn('357');

        self::assertSame(357.0, $this->configService->getFreeShippingThreshold());
    }

    /**
     * Ein unlesbarer Wert wird nicht geraten. Wer hier eine Zahl erfände, zeigte dem
     * Kunden eine Zusage, die der Shop nicht kennt.
     */
    #[Test]
    public function freeShippingThresholdRejectsSomethingThatIsNotANumber(): void
    {
        $this->systemConfigService->method('get')->willReturn('unbekannt');

        self::assertNull($this->configService->getFreeShippingThreshold());
    }

    #[Test]
    public function freeShippingMethodIdsAreEmptyWhenNothingIsSelected(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);

        self::assertSame([], $this->configService->getFreeShippingMethodIds());
    }

    /**
     * Leere Einträge fliegen raus: Eine Kennung, die keine ist, führt in der
     * Verfügbarkeits-Abfrage zu einer Suche ohne Treffer — und damit zu „gilt nirgends",
     * obwohl der Betreiber etwas ausgewählt hat.
     */
    #[Test]
    public function freeShippingMethodIdsDropEmptyAndNonStringEntries(): void
    {
        $this->systemConfigService->method('get')->willReturn(['sm-1', '', 17, 'sm-2']);

        self::assertSame(['sm-1', 'sm-2'], $this->configService->getFreeShippingMethodIds());
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
