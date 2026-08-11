<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Übernimmt die Einstellungen von RcCheckout, das in diesem Plugin aufgegangen ist.
 *
 * Ohne sie stünde der Versandkostenfrei-Indikator nach dem Update mit Vorgabewerten da:
 * Schwellwert 50 € statt des eingestellten, keine ausgewählten Versandarten, und damit
 * ein Hinweis, der in jedem Land erscheint. Auf dem Live-Shop ist das konfiguriert —
 * ein Update darf eine gepflegte Einstellung nicht stillschweigend zurücksetzen.
 *
 * Zwei Eigenschaften, auf die es ankommt:
 *
 * - **Je Verkaufskanal getrennt.** Ein Wert in `system_config` gilt entweder für alle
 *   Kanäle (`sales_channel_id IS NULL`) oder für genau einen. Beides wird einzeln
 *   übernommen; sonst erbte ein Kanal den Wert eines anderen.
 * - **Der alte Wert schlägt den Vorgabewert.** Ein vorhandener neuer Wert wird
 *   überschrieben — und das ist Absicht, nicht Nachlässigkeit. Shopware schreibt die
 *   `defaultValue` aus `config.xml` nämlich **vor** dem Lauf der Migrationen in die
 *   Datenbank. Ein Riegel „nur anlegen, nie überschreiben" fände deshalb immer einen
 *   Wert vor und täte nie etwas. Am 2026-08-11 auf dev-67121 gemessen: Der
 *   eingeschaltete Versandkostenrechner stand nach dem Update auf „aus", weil der
 *   Vorgabewert schon dastand.
 *
 * Wiederholte Läufe sind dennoch unbedenklich: Shopware führt eine Migration genau
 * einmal aus und merkt sich das in der Tabelle `migration`. Wer nach dem Update im
 * Admin etwas ändert, behält seine Änderung.
 *
 * **Reihenfolge beim Ausrollen:** erst dieses Plugin aktualisieren, dann RcCheckout
 * deinstallieren. Andersherum sind die Quellwerte weg, bevor sie jemand liest.
 */
class Migration1786406400MigrateRcCheckoutConfig extends MigrationStep
{
    /**
     * Alter Schlüssel => neuer Schlüssel.
     *
     * Die Namen ändern sich dort, wo der alte im zusammengeführten Plugin mehrdeutig
     * wäre: `enabled` und `threshold` sagen nicht, welche der sechs Funktionen gemeint
     * ist.
     */
    private const KEY_MAP = [
        'RcCheckout.config.enabled' => 'RcCheckoutEnhancer.config.freeShippingIndicatorEnabled',
        'RcCheckout.config.threshold' => 'RcCheckoutEnhancer.config.freeShippingThreshold',
        'RcCheckout.config.freeShippingMethodIds' => 'RcCheckoutEnhancer.config.freeShippingMethodIds',
        'RcCheckout.config.shippingEstimatorEnabled' => 'RcCheckoutEnhancer.config.shippingEstimatorEnabled',
    ];

    public function getCreationTimestamp(): int
    {
        return 1786406400;
    }

    public function update(Connection $connection): void
    {
        foreach (self::KEY_MAP as $oldKey => $newKey) {
            foreach ($this->existingValues($connection, $oldKey) as $row) {
                $this->copyValue($connection, $newKey, $row);
            }
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // Die Werte von RcCheckout bleiben stehen. Sie zu löschen wäre die einzige
        // Umkehr, die sich nicht rückgängig machen lässt — und sie stören nicht:
        // Ohne das Plugin liest sie niemand mehr.
    }

    /**
     * @return list<array{configuration_value: string, sales_channel_id: string|null}>
     */
    private function existingValues(Connection $connection, string $oldKey): array
    {
        /** @var list<array{configuration_value: string, sales_channel_id: string|null}> $rows */
        $rows = $connection->fetchAllAssociative(
            'SELECT configuration_value, sales_channel_id
             FROM system_config
             WHERE configuration_key = :key',
            ['key' => $oldKey],
        );

        return $rows;
    }

    /**
     * @param array{configuration_value: string, sales_channel_id: string|null} $row
     */
    private function copyValue(Connection $connection, string $newKey, array $row): void
    {
        // `<=>` statt `=`, weil `sales_channel_id` für den kanalübergreifenden Wert
        // NULL ist und `NULL = NULL` in SQL nicht wahr ist. Mit `=` bliebe der bereits
        // angelegte Vorgabewert unangetastet und daneben entstünde eine zweite Zeile für
        // denselben Schlüssel.
        $updated = $connection->executeStatement(
            'UPDATE system_config
             SET configuration_value = :value, updated_at = :now
             WHERE configuration_key = :key AND sales_channel_id <=> :salesChannelId',
            [
                'value' => $row['configuration_value'],
                'now' => $this->now(),
                'key' => $newKey,
                'salesChannelId' => $row['sales_channel_id'],
            ],
        );

        if ($updated > 0) {
            return;
        }

        $connection->insert('system_config', [
            'id' => Uuid::randomBytes(),
            'configuration_key' => $newKey,
            'configuration_value' => $row['configuration_value'],
            'sales_channel_id' => $row['sales_channel_id'],
            'created_at' => $this->now(),
        ]);
    }

    /**
     * Ausdrücklich UTC: Shopware legt alle Zeitstempel in UTC ab. Ohne Angabe zöge sich
     * der Wert die Zeitzone des Servers — und ein Datensatz aus Berlin läge dann zwei
     * Stunden in der Zukunft.
     */
    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }
}
