<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\PickupDocumentNote;
use Ruhrcoder\RcCheckoutEnhancer\Subscriber\PickupAcknowledgementOrderSubscriber;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;

/**
 * Der Hinweis auf den Belegen: Verladen und Transport liegen beim Käufer.
 *
 * Die Versandart entscheidet, nicht die Bestätigung. Abholbestellungen ohne Dialog gibt es, etwa
 * wenn die Bestätigung abgeschaltet ist; wer so abholt, sieht nie einen Dialog, und sein Beleg
 * braucht den Satz genauso.
 */
final class PickupDocumentNoteTest extends TestCase
{
    private const ABHOLUNG = 'sm-abholung';

    private const PAKETDIENST = 'sm-paketdienst';

    private const KANAL = 'sales-channel-1';

    /**
     * Was: Abholbestellung, deren Kunde den Dialog bestätigt hat.
     * Warum: Der Regelfall seit dem Dialog — Satz und Datum gehören auf den Beleg.
     */
    public function testAPickupOrderWithAcknowledgementCarriesTheDate(): void
    {
        $note = $this->note()->forOrder($this->order(self::ABHOLUNG, [
            PickupAcknowledgementOrderSubscriber::CUSTOM_FIELD => [
                'hint' => 'Sie haben Selbstabholung gewählt.',
                'at' => '2026-08-26T09:15:00+02:00',
            ],
        ]));

        self::assertSame(['confirmedAt' => '26.08.2026'], $note);
    }

    /**
     * Was: Abholbestellung ohne jede Bestätigung.
     * Warum: Ein erfundenes Datum wäre auf einem Beleg das Schlimmste von allem. Es sähe aus
     *        wie ein Nachweis und wäre keiner. Der Satz steht trotzdem da, er beschreibt die Lage.
     */
    public function testAPickupOrderWithoutAcknowledgementCarriesNoDate(): void
    {
        $note = $this->note()->forOrder($this->order(self::ABHOLUNG, null));

        self::assertSame(['confirmedAt' => null], $note);
    }

    /**
     * Was: Eine Bestellung mit einer echten Lieferart.
     * Warum: Die Gegenprobe. Stünde der Satz auch dort, wäre er auf jeder Rechnung des Shops
     *        falsch — und niemand läse ihn mehr.
     */
    public function testADeliveryOrderGetsNothing(): void
    {
        self::assertNull($this->note()->forOrder($this->order(self::PAKETDIENST, null)));
    }

    /**
     * Was: Eine Bestellung ohne Lieferung im Datensatz.
     * Warum: Ohne Versandart lässt sich nichts entscheiden. Raten heißt hier: einen Rechtssatz auf
     *        einen Beleg schreiben, der vielleicht nicht zutrifft.
     */
    public function testAnOrderWithoutDeliveryGetsNothing(): void
    {
        $order = new OrderEntity();
        $order->setSalesChannelId(self::KANAL);

        self::assertNull($this->note()->forOrder($order));
    }

    /**
     * Was: Die Bestätigung trägt einen unbrauchbaren Zeitpunkt.
     * Warum: Das Feld ist über Jahre gewachsener Bestand; ein alter Datensatz kann alles Mögliche
     *        enthalten. Der Satz bleibt, das Datum fällt weg — statt einer Ausnahme mitten in der
     *        Rechnungserzeugung.
     */
    public function testAnUnreadableDateIsDropped(): void
    {
        $note = $this->note()->forOrder($this->order(self::ABHOLUNG, [
            PickupAcknowledgementOrderSubscriber::CUSTOM_FIELD => ['hint' => 'x', 'at' => 'gestern'],
        ]));

        self::assertSame(['confirmedAt' => null], $note);
    }

    /**
     * Was: Eine Bestätigung kurz nach Mitternacht deutscher Zeit.
     * Warum: Derselbe Augenblick ist in UTC noch der Vortag. Auf einem deutschen Beleg stünde dann
     *        ein Datum, das der Kunde nicht wiedererkennt — und das im Streitfall nach einer
     *        nachträglichen Änderung aussieht.
     */
    public function testTheDateFollowsTheShopsDayNotUtc(): void
    {
        $note = $this->note()->forOrder($this->order(self::ABHOLUNG, [
            PickupAcknowledgementOrderSubscriber::CUSTOM_FIELD => [
                'hint' => 'x',
                'at' => '2026-08-26T00:30:00+02:00',
            ],
        ]));

        self::assertSame(['confirmedAt' => '26.08.2026'], $note);
    }

    /**
     * Was: Ein Verkaufskanal, in dem keine Versandart als „keine Lieferung" eingetragen ist.
     * Warum: Ohne diese Angabe weiß niemand, was eine Abholung ist. Dann schweigt der Beleg —
     *        dieselbe Zurückhaltung wie bei der Vorauswahl.
     */
    public function testWithoutAConfiguredPickupMethodNothingIsAdded(): void
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('getNonDeliveryMethodIds')->willReturn([]);

        $note = (new PickupDocumentNote($config))->forOrder($this->order(self::ABHOLUNG, null));

        self::assertNull($note);
    }

    private function note(): PickupDocumentNote
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('getNonDeliveryMethodIds')
            ->with(self::KANAL)
            ->willReturn([self::ABHOLUNG]);

        return new PickupDocumentNote($config);
    }

    /**
     * @param array<string, mixed>|null $customFields
     */
    private function order(string $shippingMethodId, ?array $customFields): OrderEntity
    {
        $delivery = new OrderDeliveryEntity();
        $delivery->setId('delivery-1');
        $delivery->setShippingMethodId($shippingMethodId);

        $order = new OrderEntity();
        $order->setSalesChannelId(self::KANAL);
        $order->setDeliveries(new OrderDeliveryCollection([$delivery]));
        $order->setCustomFields($customFields);

        return $order;
    }
}
