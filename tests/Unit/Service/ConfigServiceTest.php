<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Checkout\CheckoutLayout;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Hält die Vorgabewerte der Plugin-Einstellungen fest und wie leere, fehlende oder unpassende
 * Werte gelesen werden; dazu den Zwischenspeicher je Verkaufskanal.
 */
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

    /**
     * Was: Eine Einstellung ändert sich, während der Prozess weiterläuft.
     * Warum: Ohne `reset()` sähe ein Messenger-Worker die Änderung erst nach seinem Neustart.
     */
    #[Test]
    public function aResetReadsTheSettingAgain(): void
    {
        $this->systemConfigService->expects(self::exactly(2))->method('get')->willReturnOnConsecutiveCalls(true, false);

        self::assertTrue($this->configService->isProgressBarEnabled());
        self::assertTrue($this->configService->isProgressBarEnabled(), 'zwischengespeichert');

        $this->configService->reset();

        self::assertFalse($this->configService->isProgressBarEnabled());
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

    /**
     * Was: Ein leeres Schwellenfeld.
     * Warum: Ein leeres Feld liefert je nach Eingabe `''`,
     *        `null` oder `0`. Käme davon eine Null durch, träfe sie jeden Warenkorb — der
     *        Hinweis stünde dauerhaft unter der Versandart-Auswahl.
     */
    #[Test]
    public function anEmptyPickupThresholdIsNull(): void
    {
        $this->systemConfigService->method('get')->willReturnCallback(
            static fn (string $key): mixed => match ($key) {
                'RcCheckoutEnhancer.config.pickupHintWeightThreshold' => '',
                default => 0,
            }
        );

        self::assertNull($this->configService->getPickupHintWeightThreshold());
        self::assertNull($this->configService->getPickupHintLengthThreshold(), 'Auch die Null zählt als „nicht gesetzt".');
    }

    #[Test]
    public function aPickupThresholdIsReadAsANumber(): void
    {
        $this->systemConfigService->method('get')->willReturnCallback(
            static fn (string $key): mixed => match ($key) {
                'RcCheckoutEnhancer.config.pickupHintWeightThreshold' => 31.5,
                default => '2000',
            }
        );

        self::assertSame(31.5, $this->configService->getPickupHintWeightThreshold());
        self::assertSame(2000.0, $this->configService->getPickupHintLengthThreshold(), 'Auch eine Zeichenkette aus dem Feld zählt.');
    }

    /**
     * Ein negativer Wert ist keine Schwelle, sondern ein Tippfehler — und er träfe jeden
     * Warenkorb.
     */
    #[Test]
    public function aNegativePickupThresholdIsTreatedAsUnset(): void
    {
        $this->systemConfigService->method('get')->willReturn(-5.0);

        self::assertNull($this->configService->getPickupHintWeightThreshold());
    }

    #[Test]
    public function thePickupHintTextIsTrimmed(): void
    {
        $this->systemConfigService->method('get')->willReturn('  Bitte einen Anhänger mitbringen.  ');

        self::assertSame('Bitte einen Anhänger mitbringen.', $this->configService->getPickupHintText());
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

    /**
     * Der Anfrageweg ist ab Werk an — er ist die Rettung aus einer Sackgasse, kein Zusatz.
     */
    #[Test]
    public function shippingEnquiryIsEnabledByDefault(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);

        self::assertTrue($this->configService->isShippingEnquiryEnabled());
    }

    /**
     * Die Vorauswahl ebenfalls: Eine Liste ohne Anhakung ist für den Kunden kein neutraler
     * Zustand.
     */
    #[Test]
    public function shippingPreselectIsEnabledByDefault(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);

        self::assertTrue($this->configService->isShippingPreselectEnabled());
    }

    #[Test]
    public function shippingPreselectCanBeSwitchedOff(): void
    {
        $this->systemConfigService->method('get')->willReturn(false);

        self::assertFalse($this->configService->isShippingPreselectEnabled());
    }

    /**
     * Ohne Zielseite kein Anfrageweg. Solange hier nichts steht, darf keine Schaltfläche
     * erscheinen; eine, die ins Leere führt, ist schlimmer als keine.
     */
    #[Test]
    public function theEnquiryTargetIsNullWhenNothingIsSelected(): void
    {
        $this->systemConfigService->method('get')->willReturn('');

        self::assertNull($this->configService->getShippingEnquiryCategoryId());
    }

    /**
     * Leerzeichen sind keine Auswahl. Im Verwaltungsbereich lässt sich ein Feld leerräumen,
     * ohne dass die Zeichenkette verschwindet.
     */
    #[Test]
    public function theEnquiryTargetIgnoresWhitespace(): void
    {
        $this->systemConfigService->method('get')->willReturn('   ');

        self::assertNull($this->configService->getShippingEnquiryCategoryId());
    }

    #[Test]
    public function theEnquiryTargetIsTrimmed(): void
    {
        $this->systemConfigService->method('get')->willReturn('  kategorie-id  ');

        self::assertSame('kategorie-id', $this->configService->getShippingEnquiryCategoryId());
    }

    /**
     * Ein Wert, der keine Zeichenkette ist, kommt aus einer von Hand geänderten Einstellung.
     * Er darf nicht als Kennung durchgehen.
     */
    #[Test]
    public function theEnquiryTargetRejectsNonStrings(): void
    {
        $this->systemConfigService->method('get')->willReturn(['unsinn']);

        self::assertNull($this->configService->getShippingEnquiryCategoryId());
    }

    /**
     * Ohne Einstellung bleibt der Checkout, wie er war. Ein Ausrollen darf ihn nicht nebenbei
     * auf eine Seite umstellen.
     */
    #[Test]
    public function theCheckoutLayoutIsGuidedByDefault(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);

        self::assertSame(CheckoutLayout::GUIDED, $this->configService->getCheckoutLayout());
    }

    #[Test]
    public function theCheckoutLayoutAcceptsTheKnownValues(): void
    {
        $this->systemConfigService->method('get')->willReturnOnConsecutiveCalls(
            CheckoutLayout::ONE_PAGE,
            CheckoutLayout::AB_TEST,
        );

        self::assertSame(CheckoutLayout::ONE_PAGE, $this->configService->getCheckoutLayout('sc-a'));
        self::assertSame(CheckoutLayout::AB_TEST, $this->configService->getCheckoutLayout('sc-b'));
    }

    /**
     * Ein Wert, den es nicht gibt, kommt aus einer von Hand geänderten Einstellung oder einer
     * späteren Fassung. Er darf den Checkout nicht in einen Zustand bringen, den keine Vorlage kennt.
     */
    #[Test]
    public function anUnknownCheckoutLayoutFallsBackToGuided(): void
    {
        $this->systemConfigService->method('get')->willReturn('single_page');

        self::assertSame(CheckoutLayout::GUIDED, $this->configService->getCheckoutLayout());
    }

    /**
     * Leer heißt: kein Platzhalter, die Vorauswahl verhält sich wie ohne die Einstellung.
     */
    #[Test]
    public function thePlaceholderIsNullWhenNothingIsSelected(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);

        self::assertNull($this->configService->getShippingPlaceholderMethodId());
    }

    #[Test]
    public function thePlaceholderIgnoresWhitespaceAndNonStrings(): void
    {
        $this->systemConfigService->method('get')->willReturnOnConsecutiveCalls('   ', ['sm-placeholder']);

        self::assertNull($this->configService->getShippingPlaceholderMethodId());
        self::assertNull($this->configService->getShippingPlaceholderMethodId('other-channel'));
    }

    #[Test]
    public function thePlaceholderIsReadFromItsOwnKey(): void
    {
        $this->systemConfigService->expects(self::once())
            ->method('get')
            ->with('RcCheckoutEnhancer.config.shippingPlaceholderMethodId', 'sc-id')
            ->willReturn(' sm-placeholder ');

        self::assertSame('sm-placeholder', $this->configService->getShippingPlaceholderMethodId('sc-id'));
    }

    /**
     * Die Liste „keine Lieferung“ entscheidet mit, ob die Selbstabholung vorausgewählt werden
     * darf. Was hier durchrutscht, kostet im Zweifel eine Fehlbestellung.
     */
    #[Test]
    public function nonDeliveryMethodsDropEmptyAndNonStringEntries(): void
    {
        $this->systemConfigService->method('get')->willReturn(['sm-abholung', '', 42, 'sm-zweite']);

        self::assertSame(['sm-abholung', 'sm-zweite'], $this->configService->getNonDeliveryMethodIds());
    }

    #[Test]
    public function nonDeliveryMethodsAreEmptyWhenTheValueIsNoList(): void
    {
        $this->systemConfigService->method('get')->willReturn('sm-abholung');

        self::assertSame([], $this->configService->getNonDeliveryMethodIds());
    }

    #[Test]
    public function freeShippingMethodsAreEmptyWhenTheValueIsNoList(): void
    {
        $this->systemConfigService->method('get')->willReturn('sm-paket');

        self::assertSame([], $this->configService->getFreeShippingMethodIds());
    }

    /**
     * Die Texte kommen aus Textfeldern des Verwaltungsbereichs. Ein Feld, in dem nur ein
     * Zeilenumbruch steht, ist leer — sonst erschiene ein leerer Kasten.
     */
    #[Test]
    public function theEnquiryTextsAreTrimmed(): void
    {
        $this->systemConfigService->method('get')->willReturn("  Text  
");

        self::assertSame('Text', $this->configService->getShippingEnquiryHint());
        self::assertSame('Text', $this->configService->getShippingEnquiryIntro());
    }

    /**
     * Kennung und Vergleichsgruppe des A/B-Tests: Ein unbemerktes Leerzeichen würde den
     * Vergleich mit der Variante scheitern lassen — und das Plugin liefe in beiden Gruppen.
     */
    #[Test]
    public function theAbSettingsAreTrimmed(): void
    {
        $this->systemConfigService->method('get')->willReturn('  wert  ');

        self::assertSame('wert', $this->configService->getAbExperimentKey());
        self::assertSame('wert', $this->configService->getAbSuppressVariant());
    }

    /**
     * Der Speditionshinweis zeigt dem Kunden etwas Neues — Vorgabe aus, wie beim Telefonfeld.
     */
    #[Test]
    public function theFreightHintIsOffByDefault(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);

        self::assertFalse($this->configService->isFreightHintEnabled());
        self::assertSame([], $this->configService->getFreightShippingMethodIds());
        self::assertSame([], $this->configService->getFreightHintZipCodes());
    }

    /**
     * Die Postleitzahlen tippt ein Mensch ein: mit Komma, Leerzeichen, doppelt, mit Tippfehler.
     * Was nicht wie eine Postleitzahl aussieht, fällt heraus; mehr als fünf werden nicht
     * gerechnet, denn jede kostet eine Handvoll Warenkorb-Berechnungen.
     */
    #[Test]
    public function theFreightZipCodesAreCleanedAndCapped(): void
    {
        $this->systemConfigService->method('get')->willReturn(' 96215, 34117;20095  96215, x, 01067, 80331, 10115 ');

        self::assertSame(
            ['96215', '34117', '20095', '01067', '80331'],
            $this->configService->getFreightHintZipCodes(),
        );
    }
}
