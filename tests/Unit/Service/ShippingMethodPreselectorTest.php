<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingMethodPreselector;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;

/**
 * Welche Versandart vorausgewählt wird, und wann ausdrücklich keine.
 *
 * Steht nichts vorausgewählt da, haken Kunden die kostenlose Selbstabholung an, ohne abholen zu
 * wollen. Der teuerste Fehler dieser Klasse wäre deshalb nicht, zu wenig vorzuwählen, sondern
 * die Abholung vorzuwählen.
 */
final class ShippingMethodPreselectorTest extends TestCase
{
    private const ABHOLUNG = 'sm-abholung';

    private const PLACEHOLDER = 'sm-placeholder';

    /**
     * Was: Die eingestellte Versandart ist verfügbar.
     * Warum: Die Gegenprobe zuerst. Greift die Vorauswahl hier, überschriebe sie die Wahl
     *        des Kunden bei jedem Seitenaufruf.
     */
    public function testItKeepsAnAvailableShippingMethod(): void
    {
        $available = $this->collection([
            ['id' => 'sm-paket', 'position' => 1],
            ['id' => 'sm-freight', 'position' => 2],
        ]);

        $selected = $this->preselector()->preselect($available, 'sm-paket', []);

        self::assertNull($selected);
    }

    /**
     * Was: Die eingestellte Versandart fehlt in der Liste.
     * Warum: Der typische Fall: Der Paketdienst greift nur bis 5 kg.
     */
    public function testItTakesTheFirstAvailableByPosition(): void
    {
        $available = $this->collection([
            ['id' => 'sm-freight-large', 'position' => 30],
            ['id' => 'sm-freight-small', 'position' => 20],
        ]);

        $selected = $this->preselector()->preselect($available, 'sm-paket', []);

        self::assertNotNull($selected);
        self::assertSame('sm-freight-small', $selected->getId());
    }

    /**
     * Was: Die Abholung liegt vorn, ist aber als „keine Lieferung" eingetragen.
     * Warum: Der wichtigste Test der Klasse. Die Reihenfolge im Verwaltungsbereich kann
     *        jederzeit jemand umstellen, und dann läge die Abholung als erste verfügbare vorn. Die Liste „keine Lieferung" muss auch dann tragen.
     */
    public function testItNeverPreselectsAMethodThatIsNoDelivery(): void
    {
        $available = $this->collection([
            ['id' => self::ABHOLUNG, 'position' => 1],
            ['id' => 'sm-freight', 'position' => 2],
        ]);

        $selected = $this->preselector()->preselect($available, 'sm-paket', [self::ABHOLUNG]);

        self::assertNotNull($selected);
        self::assertSame('sm-freight', $selected->getId());
    }

    /**
     * Was: Nur die Abholung ist übrig.
     * Warum: Dann wird nichts vorausgewählt — hier greift der Anfrageweg, und der Kunde
     *        entscheidet selbst, ob er eine halbe Tonne abholen will.
     */
    public function testItPreselectsNothingWhenOnlyANonDeliveryRemains(): void
    {
        $available = $this->collection([['id' => self::ABHOLUNG, 'position' => 1]]);

        $selected = $this->preselector()->preselect($available, 'sm-paket', [self::ABHOLUNG]);

        self::assertNull($selected);
    }

    /**
     * Was: Gar keine Versandart verfügbar.
     * Warum: Shopware rendert die Auswahl dann nicht einmal. Nichts vorzuwählen ist die
     *        einzig mögliche Antwort — und darf nicht in einer Fehlermeldung enden.
     */
    public function testItPreselectsNothingWhenTheListIsEmpty(): void
    {
        $selected = $this->preselector()->preselect(new ShippingMethodCollection(), 'sm-paket', []);

        self::assertNull($selected);
    }

    /**
     * Was: Zwei Versandarten auf derselben Position.
     * Warum: Die Position ist kein Pflichtfeld mit Eindeutigkeit. Ohne zweites Merkmal
     *        entschiede die Reihenfolge der Datenbank — dieselbe Bestellung sähe dann je
     *        nach Abfrage anders aus.
     */
    public function testItDecidesByNameWhenPositionsAreEqual(): void
    {
        $available = $this->collection([
            ['id' => 'sm-b', 'position' => 5, 'name' => 'Spedition B'],
            ['id' => 'sm-a', 'position' => 5, 'name' => 'Spedition A'],
        ]);

        $selected = $this->preselector()->preselect($available, 'sm-paket', []);

        self::assertNotNull($selected);
        self::assertSame('sm-a', $selected->getId());
    }

    /**
     * Was: Die Standard-Versandart des Kanals ist verfügbar, steht aber nicht vorn.
     * Warum: Sie ist die Angabe des Betreibers, was der Regelfall sein soll, typischerweise der
     *        Paketversand. Die Position entscheidet erst, wenn sie ausfällt.
     */
    public function testItPrefersTheSalesChannelDefault(): void
    {
        $available = $this->collection([
            ['id' => 'sm-freight', 'position' => 10],
            ['id' => 'sm-paket-schwer', 'position' => 40],
        ]);

        $selected = $this->preselector()->preselect($available, 'sm-paket', [], 'sm-paket-schwer');

        self::assertNotNull($selected);
        self::assertSame('sm-paket-schwer', $selected->getId());
    }

    /**
     * Was: Die Standard-Versandart ist selbst nicht verfügbar.
     * Warum: Der häufige Fall — genau deshalb gibt es die Vorauswahl überhaupt. Dann
     *        entscheidet die Position.
     */
    public function testItFallsBackToPositionWhenTheDefaultIsUnavailable(): void
    {
        $available = $this->collection([
            ['id' => 'sm-freight-large', 'position' => 30],
            ['id' => 'sm-freight-small', 'position' => 20],
        ]);

        $selected = $this->preselector()->preselect($available, 'sm-paket', [], 'sm-paket');

        self::assertNotNull($selected);
        self::assertSame('sm-freight-small', $selected->getId());
    }

    /**
     * Was: Die Standard-Versandart ist als „keine Lieferung“ eingetragen.
     * Warum: Der Ausschluss wiegt schwerer als der Vorrang. Wäre es anders, könnte eine
     *        unbedachte Voreinstellung im Kanal die Abholung zurückholen, gegen die die Liste
     *        angelegt ist.
     */
    public function testTheExclusionOutweighsTheDefault(): void
    {
        $available = $this->collection([
            ['id' => self::ABHOLUNG, 'position' => 1],
            ['id' => 'sm-freight', 'position' => 2],
        ]);

        $selected = $this->preselector()->preselect($available, 'sm-paket', [self::ABHOLUNG], self::ABHOLUNG);

        self::assertNotNull($selected);
        self::assertSame('sm-freight', $selected->getId());
    }

    /**
     * Was: Die Abholung steht im Kontext, verfügbar, aber niemand hat sie angeklickt.
     * Warum: Die Abholung ist oft die einzige Versandart ohne Gewichts- und Längengrenze;
     *        Shopware landet auf ihr, sobald der Paketdienst nicht mehr greift. Ließe die
     *        Vorauswahl sie stehen, weil sie verfügbar ist, sähe der Kunde sie angehakt, ohne
     *        sie gewählt zu haben.
     */
    public function testItLeavesANonDeliveryMethodTheCustomerNeverChose(): void
    {
        $available = $this->collection([
            ['id' => 'sm-paket', 'position' => 1],
            ['id' => self::ABHOLUNG, 'position' => 200],
        ]);

        $selected = $this->preselector()->preselect(
            $available,
            self::ABHOLUNG,
            [self::ABHOLUNG],
            null,
            false,
        );

        self::assertNotNull($selected);
        self::assertSame('sm-paket', $selected->getId());
    }

    /**
     * Was: Dieselbe Lage, aber der Kunde hat die Abholung selbst angeklickt.
     * Warum: Die Gegenprobe, und die wichtigere von beiden. Wer abholen will, muss abholen
     *        dürfen. Würde hier umgeschaltet, wäre die Abholung im Bestellvorgang nicht mehr
     *        wählbar, der Klick liefe jedes Mal ins Leere.
     */
    public function testItKeepsANonDeliveryMethodTheCustomerChose(): void
    {
        $available = $this->collection([
            ['id' => 'sm-paket', 'position' => 1],
            ['id' => self::ABHOLUNG, 'position' => 200],
        ]);

        $selected = $this->preselector()->preselect(
            $available,
            self::ABHOLUNG,
            [self::ABHOLUNG],
            null,
            true,
        );

        self::assertNull($selected);
    }

    /**
     * Was: Nur die Abholung ist übrig, und gewählt hat sie niemand.
     * Warum: Umgeschaltet werden darf nicht, denn es gibt nichts, worauf. Der
     *        Anfrageweg zeigt dann das Kontaktformular. Gäbe die Rechnung hier irgendetwas
     *        zurück, wäre es zwangsläufig die Abholung selbst — also genau das, was sie
     *        verhindern soll.
     */
    public function testItSwitchesNothingWhenOnlyTheUnchosenPickupRemains(): void
    {
        $available = $this->collection([
            ['id' => self::ABHOLUNG, 'position' => 200],
        ]);

        $selected = $this->preselector()->preselect(
            $available,
            self::ABHOLUNG,
            [self::ABHOLUNG],
            null,
            false,
        );

        self::assertNull($selected);
    }

    /**
     * Was: Eine ganz normale Lieferart, die der Kunde nicht angeklickt hat.
     * Warum: Die Bedingung „nicht angeklickt" gilt nur für Versandarten aus der Liste „keine
     *        Lieferung".
     *        Griffe sie auch sonst, würde die Vorauswahl bei jedem Seitenaufbau umschalten.
     */
    public function testAnUnchosenDeliveryMethodStays(): void
    {
        $available = $this->collection([
            ['id' => 'sm-paket', 'position' => 1],
            ['id' => 'sm-freight', 'position' => 2],
        ]);

        $selected = $this->preselector()->preselect($available, 'sm-paket', [self::ABHOLUNG], null, false);

        self::assertNull($selected);
    }

    /**
     * Was: Der Platzhalter steht im Kontext, ein passender Paketdienst ist verfügbar.
     * Warum: Der Kern landet ohne Adresse selbst auf dem Platzhalter, weil er auf Position 0
     *        liegt und gleichnamige Paketdienste über den Namen mitsperrt. Bliebe er stehen,
     *        bekäme paketfähige Ware nie ihren Paketdienst.
     */
    public function testThePlaceholderMakesWayForADeliveryMethod(): void
    {
        $available = $this->placeholderCollection(['sm-paket' => 5]);

        $selected = $this->preselector()->preselect(
            $available,
            self::PLACEHOLDER,
            [self::ABHOLUNG],
            null,
            false,
            self::PLACEHOLDER,
        );

        self::assertSame('sm-paket', $selected?->getId());
    }

    /**
     * Was: Eine nicht angeklickte Abholung steht im Kontext, Platzhalter und Paketdienst sind
     *      verfügbar.
     * Warum: Nach Position käme der Platzhalter zuerst. Er ist aber keine Lieferart, sondern
     *        nur der Ersatz, wenn keine übrig bleibt.
     */
    public function testThePlaceholderIsNeverACandidate(): void
    {
        $available = $this->placeholderCollection(['sm-paket' => 5]);

        $selected = $this->preselector()->preselect(
            $available,
            self::ABHOLUNG,
            [self::ABHOLUNG],
            null,
            false,
            self::PLACEHOLDER,
        );

        self::assertSame('sm-paket', $selected?->getId());
    }

    /**
     * Was: Nur Speditionsware ohne Adresse, also keine Lieferart verfügbar; im Kontext steht
     *      die Abholung, die niemand angeklickt hat.
     * Warum: Die Selbstabholung darf nie vorausgewählt sein. Der Platzhalter nimmt
     *        ihren Platz ein, die Abholung bleibt darunter wählbar.
     */
    public function testThePlaceholderReplacesAnUnchosenPickupWhenNoDeliveryRemains(): void
    {
        $available = $this->placeholderCollection([]);

        $selected = $this->preselector()->preselect(
            $available,
            self::ABHOLUNG,
            [self::ABHOLUNG],
            null,
            false,
            self::PLACEHOLDER,
        );

        self::assertSame(self::PLACEHOLDER, $selected?->getId());
    }

    /**
     * Was: Der Platzhalter steht im Kontext, und es bleibt keine Lieferart.
     * Warum: Dann ist er schon das Richtige; ein Umschalten auf die Abholung wäre genau der
     *        Fehler, den er verhindern soll.
     */
    public function testThePlaceholderStaysWhenNoDeliveryRemains(): void
    {
        $available = $this->placeholderCollection([]);

        $selected = $this->preselector()->preselect(
            $available,
            self::PLACEHOLDER,
            [self::ABHOLUNG],
            null,
            false,
            self::PLACEHOLDER,
        );

        self::assertNull($selected);
    }

    /**
     * Was: Der Kunde hat die Abholung selbst angeklickt.
     * Warum: Der Platzhalter verdrängt nur, was niemand gewählt hat.
     */
    public function testAChosenPickupStaysDespiteThePlaceholder(): void
    {
        $available = $this->placeholderCollection([]);

        $selected = $this->preselector()->preselect(
            $available,
            self::ABHOLUNG,
            [self::ABHOLUNG],
            null,
            true,
            self::PLACEHOLDER,
        );

        self::assertNull($selected);
    }

    /**
     * Was: Die Versandart im Kontext ist nicht mehr verfügbar, und es bleibt keine Lieferart.
     * Warum: Ohne Platzhalter gäbe es hier nichts vorzuwählen; mit ihm steht wenigstens kein
     *        falscher Betrag und keine Abholung da.
     */
    public function testAnUnavailableMethodFallsBackToThePlaceholder(): void
    {
        $available = $this->placeholderCollection([]);

        $selected = $this->preselector()->preselect(
            $available,
            'sm-paket',
            [self::ABHOLUNG],
            null,
            true,
            self::PLACEHOLDER,
        );

        self::assertSame(self::PLACEHOLDER, $selected?->getId());
    }

    /**
     * Was: Der Platzhalter ist eingestellt, aber gesperrt, etwa weil eine Adresse vorliegt.
     * Warum: Ein nicht verfügbarer Platzhalter darf nie gewählt werden; auf der
     *        Bestätigungsseite ließe sich damit nicht bestellen.
     */
    public function testAnUnavailablePlaceholderIsNeverSelected(): void
    {
        $available = $this->collection([['id' => self::ABHOLUNG, 'position' => 200]]);

        $selected = $this->preselector()->preselect(
            $available,
            self::ABHOLUNG,
            [self::ABHOLUNG],
            null,
            false,
            self::PLACEHOLDER,
        );

        self::assertNull($selected);
    }

    private function preselector(): ShippingMethodPreselector
    {
        return new ShippingMethodPreselector();
    }

    /**
     * @param list<array{id: string, position: int, name?: string}> $methods
     */
    private function collection(array $methods): ShippingMethodCollection
    {
        $entities = [];

        foreach ($methods as $data) {
            $method = new ShippingMethodEntity();
            $method->setId($data['id']);
            $method->setUniqueIdentifier($data['id']);
            $method->setPosition($data['position']);
            $method->setName($data['name'] ?? $data['id']);
            $entities[] = $method;
        }

        return new ShippingMethodCollection($entities);
    }

    /**
     * Platzhalter auf Position 0 und Abholung auf 200, wie im Shop; dazwischen die
     * übergebenen Lieferarten.
     *
     * @param array<string, int> $deliveryMethods Kennung => Position
     */
    private function placeholderCollection(array $deliveryMethods): ShippingMethodCollection
    {
        $methods = [['id' => self::PLACEHOLDER, 'position' => 0]];

        foreach ($deliveryMethods as $id => $position) {
            $methods[] = ['id' => $id, 'position' => $position];
        }

        $methods[] = ['id' => self::ABHOLUNG, 'position' => 200];

        return $this->collection($methods);
    }
}
