<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Twig\SummaryDiscountExtension;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Promotion\Aggregate\PromotionDiscount\PromotionDiscountEntity;
use Shopware\Core\Checkout\Promotion\Cart\PromotionItemBuilder;

/**
 * Welche Aktionen als Zeile der Zusammenfassung stehen statt als Position.
 *
 * Nur Aktionen ohne Code, die Shopware selbst anwendet, etwa „Vorkasse Rabatt 3 %". Einen
 * Gutschein hat der Kunde eingegeben und will ihn wieder entfernen können; er bleibt Position.
 */
final class SummaryDiscountExtensionTest extends TestCase
{
    public function testAnAutomaticPromotionIsASummaryDiscount(): void
    {
        self::assertTrue($this->extension()->isSummaryDiscount($this->promotion('vorkasse', PromotionItemBuilder::PROMOTION_TYPE_GLOBAL, -0.27)));
    }

    /**
     * Was: Ein Gutschein mit Code.
     * Warum: Er bleibt Position mit Löschen-Knopf; der Kunde hat ihn eingegeben.
     */
    public function testAVoucherStaysALineItem(): void
    {
        self::assertFalse($this->extension()->isSummaryDiscount($this->promotion('gutschein', PromotionItemBuilder::PROMOTION_TYPE_FIXED, -5.0)));
    }

    /**
     * Was: Eine automatische Aktion auf die Versandkosten.
     * Warum: Ihr Abzug steckt in den Versandkosten, die Position trägt 0,00 €. Als Zeile stünde
     *        „− 0,00 €" in der Zusammenfassung.
     */
    public function testADeliveryPromotionStaysALineItem(): void
    {
        $promotion = $this->promotion('versandfrei', PromotionItemBuilder::PROMOTION_TYPE_GLOBAL, 0.0);
        $promotion->setPayloadValue('discountScope', PromotionDiscountEntity::SCOPE_DELIVERY);

        self::assertFalse($this->extension()->isSummaryDiscount($promotion));
    }

    public function testAProductIsNoSummaryDiscount(): void
    {
        self::assertFalse($this->extension()->isSummaryDiscount(new LineItem('p1', LineItem::PRODUCT_LINE_ITEM_TYPE, 'p1')));
    }

    /**
     * Was: Eine Bestellposition statt einer Warenkorbposition.
     * Warum: Abschlussseite und Bestellhistorie zeigen Bestellungen; dort bleibt alles, wie es ist.
     */
    public function testAnOrderLineItemIsNeverASummaryDiscount(): void
    {
        $orderLineItem = new OrderLineItemEntity();
        $orderLineItem->setType(LineItem::PROMOTION_LINE_ITEM_TYPE);
        $orderLineItem->setPayload(['promotionCodeType' => PromotionItemBuilder::PROMOTION_TYPE_GLOBAL]);

        self::assertFalse($this->extension()->isSummaryDiscount($orderLineItem));
    }

    /**
     * Was: Die Summe der Zeilen.
     * Warum: Die Zwischensumme des Kerns enthält die Aktion schon. Die Zusammenfassung zeigt den
     *        Warenwert davor, sonst zöge der Leser den Rabatt doppelt ab.
     */
    public function testTheDiscountsAndTheirTotalAreCollected(): void
    {
        $lineItems = new LineItemCollection([
            new LineItem('p1', LineItem::PRODUCT_LINE_ITEM_TYPE, 'p1'),
            $this->promotion('vorkasse', PromotionItemBuilder::PROMOTION_TYPE_GLOBAL, -0.27),
            $this->promotion('sommer', PromotionItemBuilder::PROMOTION_TYPE_GLOBAL, -1.00),
            $this->promotion('gutschein', PromotionItemBuilder::PROMOTION_TYPE_FIXED, -5.00),
        ]);

        $discounts = $this->extension()->summaryDiscounts($lineItems);

        self::assertSame(['vorkasse', 'sommer'], array_map(static fn (LineItem $item): string => $item->getId(), $discounts));
        self::assertEqualsWithDelta(-1.27, $this->extension()->summaryDiscountTotal($lineItems), 0.0001);
    }

    public function testTheFunctionsAreRegistered(): void
    {
        $names = array_map(static fn ($function): string => $function->getName(), $this->extension()->getFunctions());

        self::assertSame(['rc_is_summary_discount', 'rc_summary_discounts', 'rc_summary_discount_total'], $names);
    }

    private function extension(): SummaryDiscountExtension
    {
        return new SummaryDiscountExtension();
    }

    private function promotion(string $id, string $codeType, float $total): LineItem
    {
        $item = new LineItem($id, LineItem::PROMOTION_LINE_ITEM_TYPE);
        $item->setPayloadValue('promotionCodeType', $codeType);
        $item->setPrice(new CalculatedPrice($total, $total, new CalculatedTaxCollection(), new TaxRuleCollection()));

        return $item;
    }
}
