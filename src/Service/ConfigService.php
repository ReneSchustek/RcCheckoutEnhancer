<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

// Bewusst nicht final: wird in Subscriber-Tests als Test-Double gemockt.
class ConfigService
{
    private const PLUGIN_CONFIG_KEY = 'RcCheckoutEnhancer.config';

    /** @var array<string, mixed> Request-interner Dedup-Cache; Events feuern nur im HTTP-Request (kein Worker-Leak). */
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

    public function isFreeShippingIndicatorEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->get('.freeShippingIndicatorEnabled', true, $salesChannelId);
    }

    public function isShippingEstimatorEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->get('.shippingEstimatorEnabled', false, $salesChannelId);
    }

    public function isShippingEnquiryEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->get('.shippingEnquiryEnabled', true, $salesChannelId);
    }

    /**
     * Die Seite mit dem Kontaktformular, auf die der Anfrageweg führt.
     *
     * Leer heißt: Der Anfrageweg erscheint nicht. Bewusst keine geratene Vorgabe — welche
     * Seite das Formular trägt, weiß nur der Betreiber. Ein geratener Pfad wäre genau der
     * tote Verweis, den dieses Plugin schon einmal gekostet hat.
     */
    public function getShippingEnquiryCategoryId(?string $salesChannelId = null): ?string
    {
        $value = $this->get('.shippingEnquiryCategoryId', '', $salesChannelId);

        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Versandarten, die keine Lieferung sind — allen voran die Selbstabholung.
     *
     * Shopware kennt dafür kein Merkmal: weder ein Feld noch eine Kennzeichnung. Eine
     * Erkennung über den Namen („enthält Abhol") wäre geraten und ginge bei der ersten
     * Umbenennung schief. Deshalb pflegt der Betreiber die Liste.
     *
     * Leer heißt: Es ändert sich nichts gegenüber dem Verhalten bis 1.9.0.
     *
     * @return list<string>
     */
    public function getNonDeliveryMethodIds(?string $salesChannelId = null): array
    {
        $value = $this->get('.shippingEnquiryNonDeliveryMethodIds', [], $salesChannelId);

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn ($id): bool => \is_string($id) && $id !== ''));
    }

    /**
     * Der Text über der Schaltfläche — leer heißt: der Textbaustein gilt.
     *
     * Muster der Vertrauenszeile: Vorgabe als Textbaustein, damit es sie in jeder Sprache
     * gibt, und die Einstellung als Überschreibung je Verkaufskanal.
     */
    public function getShippingEnquiryHint(?string $salesChannelId = null): string
    {
        return trim((string) $this->get('.shippingEnquiryHint', '', $salesChannelId));
    }

    /**
     * Das Anschreiben, das über der Aufstellung im Kontaktformular steht.
     *
     * Dasselbe Muster wie beim Hinweis: leer heißt, der Textbaustein gilt. Wer den
     * Textbaustein selbst leert, bekommt die Aufstellung ohne Anschreiben — auch das ist
     * eine gültige Einstellung und darf nichts brechen.
     */
    public function getShippingEnquiryIntro(?string $salesChannelId = null): string
    {
        return trim((string) $this->get('.shippingEnquiryIntro', '', $salesChannelId));
    }

    /**
     * Der eingestellte Rückfall-Betrag für die Versandkostenfreiheit.
     *
     * `null` heißt: nichts Brauchbares eingestellt. Der Betrag aus der Verfügbarkeitsregel
     * der Versandarten schlägt ihn ohnehin — diese Einstellung greift nur, wenn sich dort
     * keiner ablesen lässt.
     */
    public function getFreeShippingThreshold(?string $salesChannelId = null): ?float
    {
        $value = $this->get('.freeShippingThreshold', null, $salesChannelId);

        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }

        return \is_string($value) && is_numeric($value) ? (float) $value : null;
    }

    /**
     * Die Versandarten, die der Betreiber als „versandkostenfrei" eingestellt hat.
     *
     * @return list<string>
     */
    public function getFreeShippingMethodIds(?string $salesChannelId = null): array
    {
        $value = $this->get('.freeShippingMethodIds', [], $salesChannelId);

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn ($id): bool => \is_string($id) && $id !== ''));
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
     * Der Experiment-Schlüssel, an dem dieses Plugin teilnimmt — leer heißt: an keinem.
     *
     * Das Feld heißt in jedem teilnehmenden Plugin gleich (`abExperimentKey`). RcAbTesting
     * sammelt die Schlüssel über diese Namenskonvention ein; ein Plugin trägt sich damit selbst
     * ein, ohne dass RcAbTesting es kennen muss.
     */
    public function getAbExperimentKey(?string $salesChannelId = null): string
    {
        return trim((string) $this->get('.abExperimentKey', '', $salesChannelId));
    }

    /** Die Variante, bei der sich das Plugin zurückhält — die Vergleichsgruppe. */
    public function getAbSuppressVariant(?string $salesChannelId = null): string
    {
        return trim((string) $this->get('.abSuppressVariant', '', $salesChannelId));
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
