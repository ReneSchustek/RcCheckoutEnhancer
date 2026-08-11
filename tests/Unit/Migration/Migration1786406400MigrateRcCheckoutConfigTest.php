<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Migration\Migration1786406400MigrateRcCheckoutConfig;

/**
 * Die Übernahme der Einstellungen aus RcCheckout.
 *
 * Warum das einen Test verdient: Der Schaden fällt nicht auf. Läuft die Migration nicht,
 * steht der Versandkostenfrei-Indikator mit Vorgabewerten da — er erscheint weiter, nur
 * mit falschem Betrag und in jedem Land. Ein stiller Rückfall auf Vorgabewerte sieht aus
 * wie „funktioniert".
 */
final class Migration1786406400MigrateRcCheckoutConfigTest extends TestCase
{
    public function testTheTimestampMatchesTheClassName(): void
    {
        self::assertSame(1786406400, (new Migration1786406400MigrateRcCheckoutConfig())->getCreationTimestamp());
    }

    /**
     * Was: Für jeden alten Schlüssel liegt ein Wert vor, keiner der neuen ist gesetzt.
     * Erwartet: vier Übernahmen, Wert und Verkaufskanal unverändert.
     */
    public function testItCopiesEveryConfiguredValue(): void
    {
        $inserted = [];
        $connection = $this->connection(
            existingOld: [
                'RcCheckout.config.enabled' => [['configuration_value' => '{"_value":true}', 'sales_channel_id' => null]],
                'RcCheckout.config.threshold' => [['configuration_value' => '{"_value":357}', 'sales_channel_id' => 'kanal-a']],
                'RcCheckout.config.freeShippingMethodIds' => [['configuration_value' => '{"_value":["sm-1"]}', 'sales_channel_id' => null]],
                'RcCheckout.config.shippingEstimatorEnabled' => [['configuration_value' => '{"_value":true}', 'sales_channel_id' => null]],
            ],
            alreadySet: [],
            inserted: $inserted,
        );

        (new Migration1786406400MigrateRcCheckoutConfig())->update($connection);

        self::assertCount(4, $inserted);
        self::assertSame('RcCheckoutEnhancer.config.freeShippingIndicatorEnabled', $inserted[0]['configuration_key']);
        self::assertSame('{"_value":true}', $inserted[0]['configuration_value']);
        self::assertSame('RcCheckoutEnhancer.config.freeShippingThreshold', $inserted[1]['configuration_key']);
        self::assertSame('kanal-a', $inserted[1]['sales_channel_id']);
    }

    /**
     * Was: Ein Wert liegt für den kanalübergreifenden Fall **und** für zwei Kanäle vor.
     * Warum: Ein Wert in `system_config` gilt entweder für alle Kanäle oder für genau
     *        einen. Wer nur die erste Zeile überträgt, vererbt still die Einstellung eines
     *        fremden Kanals.
     */
    public function testEverySalesChannelIsCarriedOverSeparately(): void
    {
        $inserted = [];
        $connection = $this->connection(
            existingOld: [
                'RcCheckout.config.threshold' => [
                    ['configuration_value' => '{"_value":50}', 'sales_channel_id' => null],
                    ['configuration_value' => '{"_value":357}', 'sales_channel_id' => 'kanal-a'],
                    ['configuration_value' => '{"_value":500}', 'sales_channel_id' => 'kanal-b'],
                ],
            ],
            alreadySet: [],
            inserted: $inserted,
        );

        (new Migration1786406400MigrateRcCheckoutConfig())->update($connection);

        self::assertCount(3, $inserted);
        self::assertSame([null, 'kanal-a', 'kanal-b'], array_column($inserted, 'sales_channel_id'));
    }

    /**
     * Was: Der neue Schlüssel steht schon in der Datenbank — mit dem Vorgabewert aus
     *      `config.xml`, den Shopware **vor** dem Lauf der Migrationen schreibt.
     * Warum: **Der Kern.** Genau hier ist die erste Fassung gescheitert: Sie legte nur an,
     *        wo nichts stand, fand deshalb immer den Vorgabewert vor und übernahm nie
     *        etwas. An einem echten Shop stand der eingeschaltete Versandkostenrechner danach
     *        auf „aus".
     * Erwartet: keine zweite Zeile, sondern die vorhandene wird überschrieben.
     */
    public function testTheOldValueBeatsTheDefaultThatIsAlreadyInPlace(): void
    {
        $inserted = [];
        $updates = [];
        $connection = $this->connection(
            existingOld: [
                'RcCheckout.config.shippingEstimatorEnabled' => [['configuration_value' => '{"_value":"true"}', 'sales_channel_id' => null]],
            ],
            alreadySet: ['RcCheckoutEnhancer.config.shippingEstimatorEnabled|'],
            inserted: $inserted,
            updates: $updates,
        );

        (new Migration1786406400MigrateRcCheckoutConfig())->update($connection);

        self::assertSame([], $inserted, 'Ein vorhandener Schlüssel darf keine zweite Zeile bekommen.');
        self::assertCount(1, $updates);
        self::assertSame('{"_value":"true"}', $updates[0]['value']);
        self::assertSame('RcCheckoutEnhancer.config.shippingEstimatorEnabled', $updates[0]['key']);
    }

    /**
     * Was: RcCheckout war nie installiert oder nie konfiguriert.
     * Erwartet: Die Migration tut nichts — und wirft nicht.
     */
    public function testWithoutOldValuesNothingHappens(): void
    {
        $inserted = [];
        $connection = $this->connection(existingOld: [], alreadySet: [], inserted: $inserted);

        (new Migration1786406400MigrateRcCheckoutConfig())->update($connection);

        self::assertSame([], $inserted);
    }

    /**
     * Ein Doppel der Datenbank-Verbindung, das nur die drei Aufrufe kennt, die die
     * Migration macht — und mitschreibt, was sie geschrieben hätte.
     *
     * @param array<string, list<array{configuration_value: string, sales_channel_id: string|null}>> $existingOld
     * @param list<string>                                                                          $alreadySet  Schlüssel und Kanal, verbunden mit `|` — was schon in der Tabelle steht
     * @param list<array<string, mixed>>                                                            $inserted
     * @param list<array<string, mixed>>                                                            $updates
     */
    private function connection(array $existingOld, array $alreadySet, array &$inserted, array &$updates = []): Connection
    {
        $connection = $this->createMock(Connection::class);

        $connection->method('fetchAllAssociative')->willReturnCallback(
            static fn (string $sql, array $params): array => $existingOld[$params['key']] ?? [],
        );

        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use ($alreadySet, &$updates): int {
                $exists = \in_array(
                    $params['key'] . '|' . ($params['salesChannelId'] ?? ''),
                    $alreadySet,
                    true,
                );

                if (!$exists) {
                    return 0;
                }

                $updates[] = $params;

                return 1;
            },
        );

        $connection->method('insert')->willReturnCallback(
            static function (string $table, array $data) use (&$inserted): int {
                $inserted[] = $data;

                return 1;
            },
        );

        return $connection;
    }
}
