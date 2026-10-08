<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\PickupAcknowledgementStore;
use Ruhrcoder\RcCheckoutEnhancer\Service\PickupHintDecider;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingMethodPreselector;
use Ruhrcoder\RcCheckoutEnhancer\Subscriber\PickupHintSubscriber;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryInformation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Der Abhol-Hinweis: wann er erscheint und wann ausdrücklich nicht.
 *
 * Der teuerste Fehler wäre hier derselbe wie beim Anfrageweg — ihn zu zeigen, wo alles in
 * Ordnung ist. Wer eine Spedition gewählt hat, braucht keinen Hinweis auf Verladung, und
 * wer eine Handvoll Schrauben abholt, erst recht nicht. Beide Gegenproben stehen deshalb
 * gleich neben dem Hauptfall.
 */
final class PickupHintSubscriberTest extends TestCase
{
    private const ABHOLUNG = 'sm-abholung';

    private const SPEDITION = 'sm-spedition';

    /**
     * Was: Abholung gewählt, Warenkorb über der Gewichtsschwelle.
     * Warum: Der Fall, um den es geht — 300 kg passen nicht in den Kombi.
     */
    public function testTheHintAppearsForAHeavyCartWithCollectionSelected(): void
    {
        $event = $this->confirmEvent(self::ABHOLUNG, weight: 60.0, length: 400.0);

        $this->subscriber()->onConfirmPage($event);

        self::assertTrue($event->getPage()->hasExtension('rcPickupHint'));
    }

    /**
     * Was: Neben Abholung und Spedition steht der Platzhalter an erster Stelle.
     * Warum: Schließt der Kunde den Dialog, wird auf eine Lieferart zurückgestellt. Der
     *        Platzhalter ist keine, und weil er vor dem Paket sortiert, gewänne er sonst.
     */
    public function testThePlaceholderIsNeverTheRevertTarget(): void
    {
        $event = $this->confirmEvent(self::ABHOLUNG, weight: 60.0, length: 400.0, available: ['sm-adresse-fehlt', 'sm-paket', self::ABHOLUNG]);

        $this->subscriber(placeholder: 'sm-adresse-fehlt')->onConfirmPage($event);

        $extension = $event->getPage()->getExtension('rcPickupHint');
        self::assertInstanceOf(ArrayStruct::class, $extension);
        self::assertSame('sm-paket', $extension->get('revertToId'));
    }

    /**
     * Was: Leichte, aber sechs Meter lange Ware.
     * Warum: Die Länge ist der eigentliche Grund. Ein Handlauf wiegt nichts und passt
     *        trotzdem nicht ins Auto. Hinge der Hinweis nur am Gewicht, ginge genau dieser
     *        Kunde leer aus.
     */
    public function testTheHintAppearsForLongGoodsBelowTheWeightThreshold(): void
    {
        $event = $this->confirmEvent(self::ABHOLUNG, weight: 3.0, length: 6000.0);

        $this->subscriber()->onConfirmPage($event);

        self::assertTrue($event->getPage()->hasExtension('rcPickupHint'));
    }

    /**
     * Was: Es steht nur die Abholung zur Auswahl, keine einzige Lieferart.
     * Warum: Bleibt nur die Abholung, ist keine Lieferung möglich, und dann gehört die Seite
     *        dem Kontaktformular. Ein Dialog daneben stellt
     *        dem Kunden zwei Fragen auf einmal, und die falsche zuerst.
     */
    public function testNothingAppearsWhenOnlyCollectionIsLeft(): void
    {
        $event = $this->confirmEvent(self::ABHOLUNG, weight: 60.0, length: 400.0, available: [self::ABHOLUNG]);

        $this->subscriber()->onConfirmPage($event);

        self::assertFalse($event->getPage()->hasExtension('rcPickupHint'));
    }

    /**
     * Was: Gar keine Versandart verfügbar.
     * Warum: Derselbe Zustand aus anderer Richtung — auch hier gibt es nichts zu bestätigen,
     *        sondern etwas anzufragen.
     */
    public function testNothingAppearsWithoutAnyShippingMethod(): void
    {
        $event = $this->confirmEvent(self::ABHOLUNG, weight: 60.0, length: 400.0, available: []);

        $this->subscriber()->onConfirmPage($event);

        self::assertFalse($event->getPage()->hasExtension('rcPickupHint'));
    }

    /**
     * Die erste Gegenprobe: Wer liefern lässt, verlädt nichts selbst.
     */
    public function testNothingAppearsWhileADeliveryIsSelected(): void
    {
        $event = $this->confirmEvent(self::SPEDITION, weight: 300.0, length: 6000.0);

        $this->subscriber()->onConfirmPage($event);

        self::assertFalse($event->getPage()->hasExtension('rcPickupHint'));
    }

    /**
     * Die zweite Gegenprobe: Ein Warenkorb unter beiden Schwellen ist ein gewöhnlicher
     * Abholfall, der Hinweis wäre Lärm.
     */
    public function testNothingAppearsForALightAndShortCart(): void
    {
        $event = $this->confirmEvent(self::ABHOLUNG, weight: 3.0, length: 400.0);

        $this->subscriber()->onConfirmPage($event);

        self::assertFalse($event->getPage()->hasExtension('rcPickupHint'));
    }

    /**
     * Was: Beide Schwellen leer.
     * Warum: So ist der Hinweis abgeschaltet, und dann erscheint er nie, auch nicht bei einer
     *        halben Tonne.
     */
    public function testWithoutThresholdsTheHintIsCompletelyOff(): void
    {
        $event = $this->confirmEvent(self::ABHOLUNG, weight: 300.0, length: 6000.0);

        $this->subscriber(weightThreshold: null, lengthThreshold: null)->onConfirmPage($event);

        self::assertFalse($event->getPage()->hasExtension('rcPickupHint'));
    }

    /**
     * Was: Nur die Längenschwelle gepflegt.
     * Warum: Die beiden Felder wirken einzeln. Wer nur die Länge einstellt, will keinen
     *        Hinweis über das Gewicht — und bekommt auch keinen.
     */
    public function testASingleThresholdWorksOnItsOwn(): void
    {
        $schwerUndKurz = $this->confirmEvent(self::ABHOLUNG, weight: 300.0, length: 400.0);
        $leichtUndLang = $this->confirmEvent(self::ABHOLUNG, weight: 3.0, length: 6000.0);

        $subscriber = $this->subscriber(weightThreshold: null);
        $subscriber->onConfirmPage($schwerUndKurz);
        $subscriber->onConfirmPage($leichtUndLang);

        self::assertFalse($schwerUndKurz->getPage()->hasExtension('rcPickupHint'));
        self::assertTrue($leichtUndLang->getPage()->hasExtension('rcPickupHint'));
    }

    /**
     * Was: Die Liste der Versandarten „keine Lieferung" ist leer.
     * Warum: Ohne gepflegte Liste weiß das Plugin nicht, welche Versandart eine Abholung
     *        ist — und rät nicht am Namen entlang.
     */
    public function testWithoutTheNonDeliveryListNothingAppears(): void
    {
        $event = $this->confirmEvent(self::ABHOLUNG, weight: 300.0, length: 6000.0);

        $this->subscriber(nonDelivery: [])->onConfirmPage($event);

        self::assertFalse($event->getPage()->hasExtension('rcPickupHint'));
    }

    /**
     * Der eingestellte Text schlägt den Textbaustein — Muster des Anfragewegs.
     */
    public function testTheConfiguredHintIsHandedToTheTemplate(): void
    {
        $event = $this->confirmEvent(self::ABHOLUNG, weight: 60.0, length: 400.0);

        $this->subscriber(hint: 'Bitte bringen Sie einen Anhänger mit.')->onConfirmPage($event);

        $extension = $event->getPage()->getExtension('rcPickupHint');
        self::assertInstanceOf(ArrayStruct::class, $extension);
        self::assertSame('Bitte bringen Sie einen Anhänger mit.', $extension->get('hint'));
    }

    /**
     * Ohne eingestellten Text steht der Textbaustein im Feld, aufgelöst und nicht leer.
     *
     * An der Bestellung wird der Satz festgehalten, den der Kunde gelesen hat. Bliebe das Feld
     * leer und griffe erst die Vorlage zum Baustein, wäre der Nachweis genau dann eine leere
     * Zeichenkette, wenn der Betreiber die Vorgabe nie überschrieben hat, also im Normalfall.
     */
    public function testWithoutAConfiguredTextTheSnippetDecides(): void
    {
        $event = $this->confirmEvent(self::ABHOLUNG, weight: 60.0, length: 400.0);

        $this->subscriber()->onConfirmPage($event);

        $extension = $event->getPage()->getExtension('rcPickupHint');
        self::assertInstanceOf(ArrayStruct::class, $extension);
        self::assertSame('Textbaustein-Vorgabe', $extension->get('hint'));
    }

    public function testItListensToTheConfirmPage(): void
    {
        self::assertSame(
            [CheckoutConfirmPageLoadedEvent::class => ['onConfirmPage', -100]],
            PickupHintSubscriber::getSubscribedEvents()
        );
    }

    /**
     * @param list<string> $nonDelivery
     */
    private function subscriber(
        ?float $weightThreshold = 50.0,
        ?float $lengthThreshold = 2000.0,
        array $nonDelivery = [self::ABHOLUNG],
        string $hint = '',
        ?string $placeholder = null,
    ): PickupHintSubscriber {
        $configService = $this->createMock(ConfigService::class);
        $configService->method('getPickupHintWeightThreshold')->willReturn($weightThreshold);
        $configService->method('getPickupHintLengthThreshold')->willReturn($lengthThreshold);
        $configService->method('getNonDeliveryMethodIds')->willReturn($nonDelivery);
        $configService->method('getPickupHintText')->willReturn($hint);
        $configService->method('getShippingPlaceholderMethodId')->willReturn($placeholder);

        // Der leere Vorgabewert heißt „der Textbaustein gilt". Der Decider löst das auf, damit
        // an der Bestellung der Satz steht, den der Kunde gelesen hat.
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Textbaustein-Vorgabe');

        // Echter Speicher ohne Sitzung: PickupAcknowledgementStore ist `final`, PHPUnit kann
        // ihn nicht nachbilden. Ohne Sitzung meldet er „nicht bestätigt" — genau der Zustand,
        // den diese Tests brauchen.
        $store = new PickupAcknowledgementStore(new RequestStack());

        return new PickupHintSubscriber(
            new PickupHintDecider($configService, $translator),
            $store,
            new ShippingMethodPreselector(),
            $configService,
        );
    }

    /**
     * @param list<string> $available Versandarten, die auf der Seite zur Auswahl stehen
     */
    private function confirmEvent(
        string $selectedShippingMethodId,
        float $weight,
        float $length,
        array $available = [self::SPEDITION, self::ABHOLUNG],
    ): CheckoutConfirmPageLoadedEvent {
        $lineItem = new LineItem('li-1', LineItem::PRODUCT_LINE_ITEM_TYPE, 'ref-1', 1);
        $lineItem->setGood(true);
        $lineItem->setPrice(new CalculatedPrice(1.0, 1.0, new CalculatedTaxCollection(), new TaxRuleCollection(), 1));
        $lineItem->setDeliveryInformation(new DeliveryInformation(100, $weight, false, null, null, 100.0, 200.0, $length));

        $cart = new Cart('token');
        $cart->add($lineItem);

        $page = new CheckoutConfirmPage();
        $page->setCart($cart);

        // Die Liste der verfügbaren Versandarten trägt Bedeutung: Aus ihr ergibt sich, worauf
        // beim Abbrechen zurückgestellt wird — und ob überhaupt eine Lieferung möglich ist.
        // Bleibt darin nur die Abholung, gehört die Seite dem Anfrageweg.
        $methods = new ShippingMethodCollection();
        foreach ($available as $id) {
            $method = new ShippingMethodEntity();
            $method->setId($id);
            $method->setUniqueIdentifier($id);
            $method->setName($id);
            $methods->add($method);
        }
        $page->setShippingMethods($methods);

        return new CheckoutConfirmPageLoadedEvent($page, $this->context($selectedShippingMethodId), new Request());
    }

    private function context(string $shippingMethodId): SalesChannelContext
    {
        $shippingMethod = new ShippingMethodEntity();
        $shippingMethod->setId($shippingMethodId);
        $shippingMethod->setUniqueIdentifier($shippingMethodId);

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sc-1');
        $salesChannel->setUniqueIdentifier('sc-1');
        $salesChannel->setShippingMethodId(self::SPEDITION);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getSalesChannelId')->willReturn('sc-id');
        $context->method('getShippingMethod')->willReturn($shippingMethod);

        return $context;
    }
}
