<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Ruhrcoder\RcCheckoutEnhancer\Checkout\CheckoutLayout;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Liest die Plugin-Einstellungen je Verkaufskanal und legt Vorgabewerte und Bereinigung an
 * eine Stelle, damit kein Aufrufer mit Leerzeichen, Nullen oder fremden Typen rechnen muss.
 *
 * Nicht `final`, weil die Subscriber-Tests ihn als Test-Double ersetzen.
 */
class ConfigService implements ResetInterface
{
    private const PLUGIN_CONFIG_KEY = 'RcCheckoutEnhancer.config';

    /**
     * Ein Wert wird je Schlüssel und Kanal nur einmal gelesen. Zwischen zwei Anfragen leert
     * Symfony den Speicher über `reset()` (Tag `kernel.reset`); ein langlebiger Prozess wie ein
     * Messenger-Worker sähe eine geänderte Einstellung sonst erst nach dem Neustart.
     *
     * @var array<string, mixed>
     */
    private array $cache = [];

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function reset(): void
    {
        $this->cache = [];
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
     * Springt die Vorauswahl ein, wenn die eingestellte Versandart nicht verfügbar ist?
     *
     * Vorgabe an: Eine Liste ohne Anhakung ist für den Kunden eine Sackgasse, und sie kostet den
     * Betreiber Bestellungen, solange er sie nicht bemerkt.
     */
    public function isShippingPreselectEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->get('.shippingPreselectEnabled', true, $salesChannelId);
    }

    /**
     * Geführt, eine Seite oder per A/B-Test. Ein unbekannter Wert gilt als geführt, damit keine
     * Vorlage in einen Zustand gerät, den sie nicht kennt.
     */
    public function getCheckoutLayout(?string $salesChannelId = null): string
    {
        $value = $this->get('.checkoutLayout', CheckoutLayout::GUIDED, $salesChannelId);

        return \in_array($value, CheckoutLayout::ALL, true) ? $value : CheckoutLayout::GUIDED;
    }

    /**
     * Die Seite mit dem Kontaktformular, auf die der Anfrageweg führt.
     *
     * Leer heißt: Der Anfrageweg erscheint nicht. Welche Seite das Formular trägt, weiß nur der
     * Betreiber; ein geratener Pfad führte auf eine Seite ohne Formular.
     */
    public function getShippingEnquiryCategoryId(?string $salesChannelId = null): ?string
    {
        return $this->optionalId('.shippingEnquiryCategoryId', $salesChannelId);
    }

    /**
     * Die Versandart, die ohne Lieferadresse an die Stelle einer nie gewählten Abholung tritt.
     *
     * Leer heißt: Es gibt keinen Platzhalter, und eine nicht angeklickte Abholung bleibt stehen,
     * wenn keine Lieferart übrig ist.
     */
    public function getShippingPlaceholderMethodId(?string $salesChannelId = null): ?string
    {
        return $this->optionalId('.shippingPlaceholderMethodId', $salesChannelId);
    }

    /**
     * Eine einzelne Kennung aus einem Auswahlfeld. Ein leergeräumtes Feld hinterlässt im
     * Verwaltungsbereich Leerzeichen statt `null`; auch das zählt als keine Auswahl.
     */
    private function optionalId(string $keySuffix, ?string $salesChannelId): ?string
    {
        $value = $this->get($keySuffix, '', $salesChannelId);

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
     * Leer heißt: Keine Versandart gilt als Nicht-Lieferart. Der Abhol-Hinweis entfällt dann,
     * und den Anfrageweg löst nur eine leere Versandartenliste aus.
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
     * Ab welchem Gesamtgewicht (kg) der Abhol-Hinweis erscheint — `null` heißt: nie über das
     * Gewicht.
     *
     * Die Schwelle ist nicht fest verdrahtet: Der Shop verschiebt seine
     * Gewichtsgrenzen mit den Versandregeln, und eine Zahl im Code wäre beim ersten
     * Verschieben falsch, ohne dass es jemand merkt.
     */
    public function getPickupHintWeightThreshold(?string $salesChannelId = null): ?float
    {
        return $this->positiveNumber('.pickupHintWeightThreshold', $salesChannelId);
    }

    /**
     * Ab welcher Länge der längsten Position (mm) der Abhol-Hinweis erscheint — `null` heißt:
     * nie über die Länge.
     */
    public function getPickupHintLengthThreshold(?string $salesChannelId = null): ?float
    {
        return $this->positiveNumber('.pickupHintLengthThreshold', $salesChannelId);
    }

    /**
     * Der Text des Abhol-Hinweises — leer heißt: der Textbaustein gilt.
     *
     * Dasselbe Muster wie beim Anfrageweg: Vorgabe als Textbaustein, damit es sie übersetzt
     * gibt, und die Einstellung als Überschreibung je Verkaufskanal.
     */
    public function getPickupHintText(?string $salesChannelId = null): string
    {
        return trim((string) $this->get('.pickupHint', '', $salesChannelId));
    }

    /**
     * Eine Schwelle aus der Einstellung — nur eine echte Zahl über null zählt.
     *
     * Ein leeres Feld liefert je nach Eingabe `''`, `null` oder `0`, und alle drei heißen: keine
     * Schwelle gesetzt. Eine durchgereichte Null träfe jeden Warenkorb und machte den Hinweis
     * zum Dauerzustand.
     */
    private function positiveNumber(string $keySuffix, ?string $salesChannelId): ?float
    {
        $value = $this->get($keySuffix, null, $salesChannelId);

        if (\is_string($value)) {
            $value = trim($value);
            $value = is_numeric($value) ? (float) $value : null;
        }

        if (!\is_int($value) && !\is_float($value)) {
            return null;
        }

        return (float) $value > 0.0 ? (float) $value : null;
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
     * Ein Signal je Zeile im Format `icon;Text`, das Symbol darf fehlen, etwa
     * `lock;Sichere Bestellung (SSL-verschlüsselt)`.
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
     * Leere Einträge lässt die Vorlage auf ihre Textbausteine zurückfallen.
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

    /**
     * Wird eine fehlende Telefonnummer auf der Bestätigungsseite nachgefordert?
     *
     * Vorgabe aus. Der Schalter greift in den Bestellweg ein und sperrt im Zweifel eine
     * Bestellung; das schaltet kein Update nebenbei ein, nur der Betreiber. Wer die Nummer nicht braucht, merkt von diesem Plugin-Teil nichts.
     */
    public function isPhoneNumberRequired(?string $salesChannelId = null): bool
    {
        return (bool) $this->get('.phoneNumberRequired', false, $salesChannelId);
    }

    /**
     * Gästen auf der Abschlussseite ein Kundenkonto anbieten, obwohl Shopware sie dort abmeldet.
     *
     * Vorgabe aus wie bei jeder Neuerung, die der Kunde sieht; eingeschaltet wird sie beim Ausrollen.
     */
    public function isGuestAccountOfferEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->get('.guestAccountOfferEnabled', false, $salesChannelId);
    }

    /**
     * Der Speditionshinweis auf der Produktseite — Vorgabe aus, wie jeder Teil dieses Plugins,
     * der dem Kunden etwas Neues zeigt.
     */
    public function isFreightHintEnabled(?string $salesChannelId = null): bool
    {
        return (bool) $this->get('.freightHintEnabled', false, $salesChannelId);
    }

    /**
     * Welche Versandarten eine Spedition sind. Leer heißt: Es gibt keinen Hinweis — ob eine
     * Versandart eine Spedition ist, steht nirgends im Kern, und aus dem Namen wird nicht geraten.
     *
     * @return list<string>
     */
    public function getFreightShippingMethodIds(?string $salesChannelId = null): array
    {
        $value = $this->get('.freightShippingMethodIds', [], $salesChannelId);

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn ($id): bool => \is_string($id) && $id !== ''));
    }

    /**
     * Eine Postleitzahl je Zone, durch Komma, Strichpunkt oder Leerzeichen getrennt.
     *
     * Höchstens fünf: Jede kostet beim ersten Aufruf eine Handvoll Warenkorb-Berechnungen.
     * Was nicht wie eine Postleitzahl aussieht, fällt heraus, statt eine Berechnung ins Leere
     * zu schicken.
     *
     * @return list<string>
     */
    public function getFreightHintZipCodes(?string $salesChannelId = null): array
    {
        $raw = $this->get('.freightHintZipCodes', '', $salesChannelId);
        $parts = preg_split('/[\s,;]+/', \is_string($raw) ? $raw : '') ?: [];

        $zipCodes = [];
        foreach ($parts as $part) {
            if (preg_match('/^[A-Za-z0-9-]{2,12}$/', $part) === 1 && !\in_array($part, $zipCodes, true)) {
                $zipCodes[] = $part;
            }
        }

        return \array_slice($zipCodes, 0, 5);
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
