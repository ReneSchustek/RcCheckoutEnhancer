<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

final class ConfigService
{
    private const PLUGIN_CONFIG_KEY = 'RcCheckoutEnhancer.config';

    /** @var array<string, mixed> */
    private array $cache = [];

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function isProgressBarEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->get('.progressBarEnabled', true, $salesChannelId);
    }

    public function isTrustBadgesEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->get('.trustBadgesEnabled', true, $salesChannelId);
    }

    public function isMiniCartEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->get('.miniCartEnabled', true, $salesChannelId);
    }

    public function isOrderSummaryEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->get('.orderSummaryEnabled', true, $salesChannelId);
    }

    public function isDeliveryTimeEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->get('.deliveryTimeEnabled', false, $salesChannelId);
    }

    public function getEstimatedDeliveryTime(?string $salesChannelId = null): string
    {
        return (string) $this->get('.estimatedDeliveryTime', '', $salesChannelId);
    }

    /**
     * Parst die Vertrauenssignale aus der Konfiguration.
     * Format pro Zeile: icon;Text (icon optional)
     * Beispiel: lock;Sichere Bestellung (SSL-verschlüsselt)
     *
     * @return list<array{icon: string, text: string}>
     */
    public function getTrustBadges(?string $salesChannelId = null): array
    {
        $raw = (string) $this->get('.trustBadges', '', $salesChannelId);

        if ($raw === '') {
            return [];
        }

        $badges = [];

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $parts = explode(';', $line, 2);

            if (\count($parts) === 2) {
                $badges[] = [
                    'icon' => trim($parts[0]),
                    'text' => trim($parts[1]),
                ];
            } else {
                $badges[] = [
                    'icon' => '',
                    'text' => $line,
                ];
            }
        }

        return $badges;
    }

    /**
     * Gibt die Schritt-Bezeichnungen für die Progress-Bar zurück.
     *
     * @return array{step1: string, step2: string, step3: string, step4: string}
     */
    public function getProgressStepLabels(?string $salesChannelId = null): array
    {
        return [
            'step1' => (string) $this->get('.progressStep1', '', $salesChannelId),
            'step2' => (string) $this->get('.progressStep2', '', $salesChannelId),
            'step3' => (string) $this->get('.progressStep3', '', $salesChannelId),
            'step4' => (string) $this->get('.progressStep4', '', $salesChannelId),
        ];
    }

    private function get(string $keySuffix, mixed $default, ?string $salesChannelId = null): mixed
    {
        $key = self::PLUGIN_CONFIG_KEY . $keySuffix;
        $cacheKey = $key . '|' . ($salesChannelId ?? '');

        if (!\array_key_exists($cacheKey, $this->cache)) {
            $this->cache[$cacheKey] = $this->systemConfigService->get($key, $salesChannelId) ?? $default;
        }

        return $this->cache[$cacheKey];
    }
}
