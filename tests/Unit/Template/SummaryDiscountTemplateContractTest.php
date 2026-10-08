<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * Automatische Aktionen stehen als Zeile der Zusammenfassung, nicht als Position.
 *
 * Positionen und Summen kommen aus verschiedenen Vorlagen. Verschwindet die Aktion aus der
 * Liste, ohne dass eine der Summen sie zeigt, sieht der Kunde einen Betrag, der sich nicht
 * erklärt. Der Test hält beide Seiten zusammen.
 */
final class SummaryDiscountTemplateContractTest extends TestCase
{
    private const LINE_ITEM = 'storefront/component/line-item/line-item.html.twig';

    private const SUMMARY_POSITION = 'storefront/page/checkout/summary/summary-position.html.twig';

    private const OFFCANVAS_SUMMARY = 'storefront/component/checkout/offcanvas-cart-summary.html.twig';

    private const MINI_CART = 'storefront/component/rc-checkout/mini-cart.html.twig';

    /**
     * Ausgeblendet wird nur, wo ein Warenkorb angezeigt wird. Abschlussseite und Bestellhistorie
     * zeigen Bestellungen und behalten die Aktion als Position.
     */
    public function testTheLineItemIsHiddenOnlyForCarts(): void
    {
        $template = $this->read(self::LINE_ITEM);

        self::assertStringContainsString("{% sw_extends '@Storefront/" . self::LINE_ITEM . "' %}", $template);
        self::assertStringContainsString('{% block component_line_item %}', $template);
        self::assertStringContainsString('page.cart is defined', $template);
        self::assertStringContainsString('rc_is_summary_discount(lineItem)', $template);
        self::assertStringContainsString('{{ parent() }}', $template);
    }

    /**
     * Jede Stelle, an der eine Summe steht, zeigt den Warenwert vor der Aktion und die Aktion als
     * eigene Zeile.
     */
    public function testEverySummaryShowsTheDiscountRows(): void
    {
        foreach ([self::SUMMARY_POSITION, self::OFFCANVAS_SUMMARY, self::MINI_CART] as $file) {
            $template = $this->read($file);

            self::assertStringContainsString('rc_summary_discounts(', $template, $file);
            self::assertStringContainsString('rc_summary_discount_total(', $template, $file);
        }

        self::assertStringContainsString('{% block page_checkout_summary_position %}', $this->read(self::SUMMARY_POSITION));
        self::assertStringContainsString('{% block component_offcanvas_summary_total %}', $this->read(self::OFFCANVAS_SUMMARY));
    }

    public function testTheCoreBlocksExist(): void
    {
        $coreDir = \dirname(__DIR__, 3) . '/vendor/shopware/storefront/Resources/views/';
        if (!is_dir($coreDir)) {
            self::markTestSkipped('Core-Vorlagen nicht verfügbar, ohne sie ist der Vergleich wertlos.');
        }

        $expectations = [
            self::LINE_ITEM => 'component_line_item',
            self::SUMMARY_POSITION => 'page_checkout_summary_position',
            self::OFFCANVAS_SUMMARY => 'component_offcanvas_summary_total',
        ];

        foreach ($expectations as $file => $block) {
            self::assertStringContainsString('{% block ' . $block . ' %}', (string) file_get_contents($coreDir . $file), $file);
        }
    }

    private function read(string $relativePath): string
    {
        $path = \dirname(__DIR__, 3) . '/src/Resources/views/' . $relativePath;
        $content = file_get_contents($path);
        self::assertIsString($content, 'Vorlage nicht lesbar: ' . $path);

        return $content;
    }
}
