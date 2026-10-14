<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\PickupHintDecider;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryInformation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die eine Bedingung, die Dialog und Sperre gemeinsam lesen.
 *
 * Der Wortlaut ist hier kein Nebenprodukt, sondern der Gegenstand: Er wird an der Bestellung
 * festgehalten und später verglichen. Ein falscher Wortlaut wäre ein falscher Nachweis.
 */
final class PickupHintDeciderTest extends TestCase
{
    private const ABHOLUNG = 'sm-abholung';

    private const SPEDITION = 'sm-spedition';

    /**
     * Was: Der Betreiber hat den Text nie überschrieben.
     * Warum: Ein leerer Einstellungswert heißt „der Textbaustein gilt", nicht „kein Hinweis".
     *        Wer ihn als „kein Hinweis" liest, lässt den Hinweis spurlos verschwinden, und zwar
     *        im Normalfall, denn überschrieben wird selten.
     */
    public function testAnEmptyConfigurationFallsBackToTheSnippet(): void
    {
        $hint = $this->decider(configured: '')->hintFor($this->heavyCart(), $this->context(self::ABHOLUNG));

        self::assertStringStartsWith('Beides:', $hint ?? '');
    }

    /**
     * Was: Der Betreiber hat einen eigenen Text gesetzt.
     * Warum: Die Einstellung schlägt den Baustein — dasselbe Muster wie beim Anfrageweg.
     */
    public function testTheConfiguredTextBeatsTheSnippet(): void
    {
        $hint = $this->decider(configured: 'Eigener Satz.')->hintFor($this->heavyCart(), $this->context(self::ABHOLUNG));

        self::assertSame('Eigener Satz.', $hint);
    }

    /**
     * Was: Der Warenkorb wiegt 80 kg, die längste Position misst 300 mm.
     * Warum: Der Kunde soll nicht raten müssen, ob es ins Auto passt.
     *        Geprüft wird die Schreibweise mit, denn „80.0 kg" wäre in einem deutschen Satz
     *        genauso falsch wie eine fehlende Zahl.
     */
    public function testTheWordingCarriesWeightAndLength(): void
    {
        $hint = $this->decider()->hintFor($this->heavyCart(), $this->context(self::ABHOLUNG));

        self::assertStringContainsString('80,0 kg', $hint ?? '');
        self::assertStringContainsString('0,30 m', $hint ?? '');
    }

    /**
     * Was: Der Betreiber hat einen eigenen Text mit Platzhaltern gesetzt.
     * Warum: Platzhalter müssen im eigenen Text genauso wirken wie in der Vorgabe. Einer, der
     *        nur beim mitgelieferten Text funktioniert, wäre eine Falle für den Betreiber.
     */
    public function testPlaceholdersAlsoWorkInTheConfiguredText(): void
    {
        $decider = $this->decider(configured: 'Gewicht {Gewicht}, Länge {Länge}.');

        $hint = $decider->hintFor($this->heavyCart(), $this->context(self::ABHOLUNG));

        self::assertSame('Gewicht 80,0 kg, Länge 0,30 m.', $hint);
    }

    /**
     * Was: Kein Produkt im Warenkorb trägt ein Längenmaß.
     * Warum: Dafür gibt es den zweiten Baustein. „Maximale Länge von 0,00 m" wäre keine
     *        vorsichtige Aussage, sondern eine falsche, und ausgerechnet in dem Satz, mit dem
     *        der Kunde entscheiden soll, ob er die Ware mitnehmen kann.
     */
    public function testAMissingLengthIsNotClaimedAsZero(): void
    {
        $cart = $this->cart(weight: 80.0, length: 0.0);

        $hint = $this->decider()->hintFor($cart, $this->context(self::ABHOLUNG));

        self::assertStringStartsWith('Nur Gewicht:', $hint ?? '');
        self::assertStringNotContainsString('0,00 m', $hint ?? '');
    }

    /**
     * Was: Die Positionen tragen kein Gewicht, wohl aber eine Länge.
     * Warum: Die Gegenprobe — dieselbe Vorsicht in die andere Richtung.
     */
    public function testAMissingWeightIsNotClaimedAsZero(): void
    {
        $cart = $this->cart(weight: 0.0, length: 3000.0);

        $hint = $this->decider()->hintFor($cart, $this->context(self::ABHOLUNG));

        self::assertStringStartsWith('Nur Länge:', $hint ?? '');
        self::assertStringNotContainsString('0,0 kg', $hint ?? '');
    }

    /**
     * Was: Derselbe Warenkorb, dann ein Stück mehr darin.
     * Warum: Eine gewollte Nebenwirkung. Die Bestätigung wird zum Wortlaut gespeichert. Ändert
     *        sich der Warenkorb, ändert sich der Satz, und die alte Bestätigung passt nicht mehr. Zugestimmt wurde dem Transport *dieser* Ware.
     */
    public function testAChangedCartProducesADifferentWording(): void
    {
        $decider = $this->decider();

        $vorher = $decider->hintFor($this->cart(weight: 80.0, length: 300.0), $this->context(self::ABHOLUNG));
        $nachher = $decider->hintFor($this->cart(weight: 95.0, length: 300.0), $this->context(self::ABHOLUNG));

        self::assertNotSame($vorher, $nachher);
    }

    /**
     * Was: Eine Spedition ist gewählt.
     * Warum: Die Gegenprobe. Wer liefern lässt, braucht keinen Hinweis auf Verladung.
     */
    public function testNoHintWhenADeliveryMethodIsSelected(): void
    {
        self::assertNull($this->decider()->hintFor($this->heavyCart(), $this->context(self::SPEDITION)));
    }

    /**
     * Was: Abholung gewählt, aber leichter und kurzer Warenkorb.
     * Warum: Wer eine Handvoll Schrauben abholt, braucht keine Warnung vor dem Verladen.
     */
    public function testNoHintForASmallCart(): void
    {
        $cart = $this->cart(weight: 1.0, length: 100.0);

        self::assertNull($this->decider()->hintFor($cart, $this->context(self::ABHOLUNG)));
    }

    /**
     * Was: Keine einzige Schwelle eingestellt.
     * Warum: Ohne Schwelle gibt es keinen Anlass — sonst träfe der Hinweis jeden Warenkorb.
     */
    public function testNoHintWithoutAnyThreshold(): void
    {
        $decider = $this->decider(weightThreshold: null, lengthThreshold: null);

        self::assertNull($decider->hintFor($this->heavyCart(), $this->context(self::ABHOLUNG)));
    }

    private function decider(
        ?float $weightThreshold = 50.0,
        ?float $lengthThreshold = 2000.0,
        string $configured = '',
    ): PickupHintDecider {
        $configService = $this->createMock(ConfigService::class);
        $configService->method('getPickupHintWeightThreshold')->willReturn($weightThreshold);
        $configService->method('getPickupHintLengthThreshold')->willReturn($lengthThreshold);
        $configService->method('getNonDeliveryMethodIds')->willReturn([self::ABHOLUNG]);
        $configService->method('getPickupHintText')->willReturn($configured);

        // Die Bausteine tragen dieselben Platzhalter wie die ausgelieferten. Nur so läuft der
        // Test durch die echte Ersetzung und nicht an ihr vorbei. Ihr Anfang verrät zugleich,
        // welcher der drei gewählt wurde.
        $bausteine = [
            PickupHintDecider::FALLBACK_SNIPPET => 'Beides: {Gewicht} und {Länge}.',
            'rcCheckout.pickupHintWeightOnly' => 'Nur Gewicht: {Gewicht}.',
            'rcCheckout.pickupHintLengthOnly' => 'Nur Länge: {Länge}.',
        ];

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => $bausteine[$id] ?? $id,
        );

        return new PickupHintDecider($configService, $translator);
    }

    private function heavyCart(): Cart
    {
        return $this->cart(weight: 80.0, length: 300.0);
    }

    private function cart(float $weight, float $length): Cart
    {
        $lineItem = new LineItem('li-1', LineItem::PRODUCT_LINE_ITEM_TYPE, 'ref-1', 1);
        $lineItem->setGood(true);
        $lineItem->setDeliveryInformation(new DeliveryInformation(100, $weight, false, null, null, 100.0, 200.0, $length));

        $cart = new Cart('token');
        $cart->add($lineItem);

        return $cart;
    }

    private function context(string $shippingMethodId): SalesChannelContext
    {
        $shippingMethod = new ShippingMethodEntity();
        $shippingMethod->setId($shippingMethodId);
        $shippingMethod->setUniqueIdentifier($shippingMethodId);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-1');
        $context->method('getShippingMethod')->willReturn($shippingMethod);

        return $context;
    }
}
