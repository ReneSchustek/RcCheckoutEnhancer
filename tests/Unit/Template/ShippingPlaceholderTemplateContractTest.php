<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * Wo die Platzhalter-Versandart steht, erscheint statt „0,00 €" ein Satz.
 *
 * Beide Vorlagen überschreiben Blöcke in eingebundenen Teilvorlagen des Kerns. Ein Block, den es
 * dort nicht gibt, rendert wortlos nichts; deshalb prüft der Test die Namen gegen den Kern, sobald
 * dessen Vorlagen greifbar sind.
 */
final class ShippingPlaceholderTemplateContractTest extends TestCase
{
    private const SUMMARY = 'storefront/page/checkout/summary/summary-shipping.html.twig';

    private const OFFCANVAS = 'storefront/component/checkout/offcanvas-cart-summary.html.twig';

    private const CONFIG_KEY = "config('RcCheckoutEnhancer.config.shippingPlaceholderMethodId')";

    public function testTheSummaryExtendsTheCoreAndKeepsItsOutput(): void
    {
        $template = $this->read(self::SUMMARY);

        self::assertStringContainsString("{% sw_extends '@Storefront/" . self::SUMMARY . "' %}", $template);
        self::assertStringContainsString('{% block page_checkout_summary_shipping_value %}', $template);
        self::assertStringContainsString('{{ parent() }}', $template);
        self::assertStringContainsString(self::CONFIG_KEY, $template);
        self::assertStringContainsString('rc-checkout.shippingPlaceholder.costs', $template);
    }

    public function testTheOffcanvasSummaryExtendsTheCoreAndKeepsItsOutput(): void
    {
        $template = $this->read(self::OFFCANVAS);

        self::assertStringContainsString("{% sw_extends '@Storefront/" . self::OFFCANVAS . "' %}", $template);
        self::assertStringContainsString('{% block component_offcanvas_summary_content_info %}', $template);
        self::assertStringContainsString('{{ parent() }}', $template);
        self::assertStringContainsString(self::CONFIG_KEY, $template);
        self::assertStringContainsString('rc-checkout.shippingPlaceholder.costs', $template);
    }

    /**
     * Die Leiste behält den Knopf, mit dem der Kunde die Versandart wechselt. Fehlte er, käme
     * er vom Platzhalter nicht mehr zur Abholung.
     */
    public function testTheOffcanvasKeepsTheShippingToggle(): void
    {
        self::assertStringContainsString('js-toggle-shipping-selection', $this->read(self::OFFCANVAS));
    }

    public function testBothBlocksExistInTheCore(): void
    {
        $coreDir = \dirname(__DIR__, 3) . '/vendor/shopware/storefront/Resources/views/';

        if (!is_dir($coreDir)) {
            self::markTestSkipped('Core-Vorlagen nicht verfügbar, ohne sie ist der Vergleich wertlos.');
        }

        self::assertStringContainsString(
            '{% block page_checkout_summary_shipping_value %}',
            (string) file_get_contents($coreDir . self::SUMMARY),
        );
        self::assertStringContainsString(
            '{% block component_offcanvas_summary_content_info %}',
            (string) file_get_contents($coreDir . self::OFFCANVAS),
        );
    }

    public function testTheSnippetExistsInBothLanguages(): void
    {
        foreach (['de_DE/storefront.de-DE.json', 'en_GB/storefront.en-GB.json'] as $file) {
            $path = \dirname(__DIR__, 3) . '/src/Resources/snippet/' . $file;
            $snippets = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

            self::assertIsArray($snippets);
            $text = $snippets['rc-checkout']['shippingPlaceholder']['costs'] ?? null;
            self::assertIsString($text, 'Textbaustein fehlt in ' . $file);
            self::assertNotSame('', trim($text));
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
