<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Twig;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Promotion\Aggregate\PromotionDiscount\PromotionDiscountEntity;
use Shopware\Core\Checkout\Promotion\Cart\PromotionItemBuilder;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Sagt den Vorlagen, welche Aktionen als Zeile der Zusammenfassung stehen statt als Position.
 *
 * Gemeint sind Aktionen ohne Code, die Shopware selbst anwendet, etwa ein Rabatt für eine
 * Zahlart. Als Position sähen sie aus wie ein Artikel mit Löschen-Knopf und veränderten die
 * Summe ohne Erklärung an dieser Stelle. Einen Gutschein hat der Kunde dagegen selbst eingegeben;
 * er bleibt Position, damit er ihn wieder entfernen kann.
 *
 * Nur Warenkorbpositionen zählen. Bestellungen auf der Abschlussseite, in der Historie und auf
 * den Belegen behalten die Aktion als Position; daran hängen Buchhaltung und Warenwirtschaft.
 *
 *   {% if page.cart is defined and rc_is_summary_discount(lineItem) %}…{% endif %}
 *   {% for discount in rc_summary_discounts(cart.lineItems) %}…{% endfor %}
 */
final class SummaryDiscountExtension extends AbstractExtension
{
    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('rc_is_summary_discount', $this->isSummaryDiscount(...)),
            new TwigFunction('rc_summary_discounts', $this->summaryDiscounts(...)),
            new TwigFunction('rc_summary_discount_total', $this->summaryDiscountTotal(...)),
        ];
    }

    public function isSummaryDiscount(mixed $lineItem): bool
    {
        // Versandkosten-Aktionen ausgenommen: Ihr Abzug steckt in den Versandkosten, die Position
        // trägt 0,00 €. Als Zeile stünde dort „− 0,00 €".
        return $lineItem instanceof LineItem
            && $lineItem->getType() === LineItem::PROMOTION_LINE_ITEM_TYPE
            && $lineItem->getPayloadValue('promotionCodeType') === PromotionItemBuilder::PROMOTION_TYPE_GLOBAL
            && $lineItem->getPayloadValue('discountScope') !== PromotionDiscountEntity::SCOPE_DELIVERY;
    }

    /**
     * @return list<LineItem>
     */
    public function summaryDiscounts(?LineItemCollection $lineItems): array
    {
        if ($lineItems === null) {
            return [];
        }

        return array_values($lineItems->filter(fn (LineItem $item): bool => $this->isSummaryDiscount($item))->getElements());
    }

    /**
     * Die Summe der Zeilen, als negative Zahl wie die Positionen selbst.
     */
    public function summaryDiscountTotal(?LineItemCollection $lineItems): float
    {
        $total = 0.0;
        foreach ($this->summaryDiscounts($lineItems) as $discount) {
            $total += $discount->getPrice()?->getTotalPrice() ?? 0.0;
        }

        return $total;
    }
}
