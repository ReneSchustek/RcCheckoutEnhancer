<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingEnquirySummary;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryInformation;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Die Zusammenfassung, die der Vertrieb bekommt.
 *
 * Der Maßstab ist nicht „sieht hübsch aus", sondern: **Kann jemand danach ein Frachtangebot
 * rechnen, ohne beim Kunden nachzufragen?** Danach sind die Tests geschnitten.
 */
final class ShippingEnquirySummaryTest extends TestCase
{
    public function testAnEmptyCartYieldsNothing(): void
    {
        self::assertSame('', (new ShippingEnquirySummary())->forCart(new Cart('token'), $this->context()));
    }

    /**
     * Was: Menge, Artikelnummer, Bezeichnung und Positionspreis.
     * Warum: Ohne Artikelnummer muss der Vertrieb das Produkt suchen — genau der
     *        Telefonaufwand, den die Anfrage ersparen soll.
     */
    public function testItNamesQuantityNumberLabelAndPrice(): void
    {
        $cart = $this->cartWith($this->productLineItem());

        $text = (new ShippingEnquirySummary())->forCart($cart, $this->context());

        self::assertStringContainsString('10 × GVS-1 — Vordachsystem Komplettset', $text);
        self::assertStringContainsString('6.490,00 €', $text);
    }

    /**
     * Was: Ein Payload-Schlüssel, den Shopware nicht selbst schreibt.
     * Warum: **Der Kern.** Die RAL-Farbe von RcColorPicker und die Zuschnittlänge von
     *        TmmsProductCustomerInputs hängen genau dort. Fehlen sie in der Anfrage, ruft
     *        der Vertrieb wieder an — und bei lackierten Teilen ist eine falsche Farbe
     *        kein Umtausch, sondern Ausschuss.
     */
    public function testCustomerInputFromOtherPluginsIsCarriedOver(): void
    {
        $lineItem = $this->productLineItem();
        $lineItem->setPayloadValue('rcColorPickerRal', 'RAL 7016');
        $lineItem->setPayloadValue('tmmsCutLength', 2400);

        $text = (new ShippingEnquirySummary())->forCart($this->cartWith($lineItem), $this->context());

        self::assertStringContainsString('rcColorPickerRal: RAL 7016', $text);
        self::assertStringContainsString('tmmsCutLength: 2400', $text);
    }

    /**
     * Die Gegenprobe: Was Shopware selbst in den Payload legt, ist keine Kundeneingabe.
     * Ohne diesen Test bestünde die Anfrage zur Hälfte aus Kennungen und Lagerständen.
     */
    public function testTechnicalCorePayloadIsLeftOut(): void
    {
        $lineItem = $this->productLineItem();
        $lineItem->setPayloadValue('stock', 42);
        $lineItem->setPayloadValue('taxId', 'abc123');
        $lineItem->setPayloadValue('categoryIds', ['x', 'y']);

        $text = (new ShippingEnquirySummary())->forCart($this->cartWith($lineItem), $this->context());

        self::assertStringNotContainsString('stock', $text);
        self::assertStringNotContainsString('taxId', $text);
        self::assertStringNotContainsString('categoryIds', $text);
    }

    /**
     * `productType` setzt der Kern **außerhalb** des Blocks, aus dem die Ausschlussliste
     * stammt — einzeln über `LineItem::PAYLOAD_PRODUCT_TYPE`. Genau deshalb stand er im
     * ersten Durchlauf am 2026-08-11 als „productType: physical" in der Anfrage. Eigener
     * Test, weil er der Beleg dafür ist, dass die Liste unvollständig sein kann.
     */
    public function testTheProductTypeIsNotMistakenForCustomerInput(): void
    {
        $lineItem = $this->productLineItem();
        $lineItem->setPayloadValue('productType', 'physical');

        $text = (new ShippingEnquirySummary())->forCart($this->cartWith($lineItem), $this->context());

        self::assertStringNotContainsString('productType', $text);
    }

    /**
     * Ein Wahrheitswert ist ein Schalter für die Anzeige, keine Angabe für den Vertrieb.
     */
    public function testSwitchesAreNotListed(): void
    {
        $lineItem = $this->productLineItem();
        $lineItem->setPayloadValue('rcColorPickerActive', true);

        $text = (new ShippingEnquirySummary())->forCart($this->cartWith($lineItem), $this->context());

        self::assertStringNotContainsString('rcColorPickerActive', $text);
    }

    /**
     * Was: Gesamtgewicht und längste Position.
     * Warum: Genau daran scheitert die Sendung. Ohne beides ist die Anfrage nicht rechenbar.
     */
    public function testItSumsWeightAndNamesTheLongestItem(): void
    {
        $text = (new ShippingEnquirySummary())->forCart($this->cartWith($this->productLineItem()), $this->context());

        self::assertStringContainsString('Gesamtgewicht: 530 kg', $text);
        self::assertStringContainsString('Längste Position: 1400 mm', $text);
        self::assertStringContainsString('Warenwert:', $text);
    }

    /**
     * Was: Lieferland und Postleitzahl, sofern bekannt.
     * Warum: Der Frachtpreis hängt an der Zone. Ist die Anschrift noch nicht gewählt,
     *        steht wenigstens das Land da — und keine erfundene Postleitzahl.
     */
    public function testItNamesTheDestinationWithoutInventingAPostcode(): void
    {
        $text = (new ShippingEnquirySummary())->forCart($this->cartWith($this->productLineItem()), $this->context());

        self::assertStringContainsString('Lieferung nach: Deutschland', $text);
    }

    /**
     * Ein sehr langes Freitextfeld darf die Anfrage nicht überschwemmen.
     */
    public function testAnOverlongValueIsShortened(): void
    {
        $lineItem = $this->productLineItem();
        $lineItem->setPayloadValue('tmmsBemerkung', str_repeat('A', 500));

        $text = (new ShippingEnquirySummary())->forCart($this->cartWith($lineItem), $this->context());

        self::assertStringContainsString('tmmsBemerkung: ' . str_repeat('A', 200) . "\n", $text . "\n");
        self::assertStringNotContainsString(str_repeat('A', 201), $text);
    }

    private function cartWith(LineItem $lineItem): Cart
    {
        $cart = new Cart('token');
        $cart->add($lineItem);
        $cart->setPrice(new \Shopware\Core\Checkout\Cart\Price\Struct\CartPrice(
            5305.50,
            6313.54,
            6490.00,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
            \Shopware\Core\Checkout\Cart\Price\Struct\CartPrice::TAX_STATE_GROSS,
        ));

        return $cart;
    }

    private function productLineItem(): LineItem
    {
        $lineItem = new LineItem('li-1', LineItem::PRODUCT_LINE_ITEM_TYPE, 'ref-1', 10);
        $lineItem->setLabel('Vordachsystem Komplettset');
        $lineItem->setGood(true);
        $lineItem->setStackable(true);
        $lineItem->setPayloadValue('productNumber', 'GVS-1');
        $lineItem->setPrice(new CalculatedPrice(649.0, 6490.0, new CalculatedTaxCollection(), new TaxRuleCollection(), 10));
        $lineItem->setDeliveryInformation(new DeliveryInformation(100, 53.0, false, null, null, 100.0, 800.0, 1400.0));

        return $lineItem;
    }

    private function context(): SalesChannelContext
    {
        $country = new CountryEntity();
        $country->setId('country-de');
        $country->setUniqueIdentifier('country-de');
        $country->setName('Deutschland');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getShippingLocation')->willReturn(ShippingLocation::createFromCountry($country));

        return $context;
    }
}
