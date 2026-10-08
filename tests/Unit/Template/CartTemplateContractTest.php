<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * Nagelt die Einhängestellen von Versandkostenfrei-Indikator und Versandkostenrechner fest.
 *
 * Ein `sw_extends`-Block, den es im Ziel-Template nicht gibt, wird von Twig stillschweigend
 * ignoriert — das Markup rendert dann nie. Genau das war der Fehler von 1.1.1:
 * `page_checkout_cart_table` existiert im Core nicht, und der Banner erschien monatelang
 * nirgends, ohne dass irgendwo etwas rot wurde.
 */
final class CartTemplateContractTest extends TestCase
{
    public function testExtendsTheCoreCartPage(): void
    {
        self::assertStringContainsString(
            "{% sw_extends '@Storefront/storefront/page/checkout/cart/index.html.twig' %}",
            $this->cartTemplate(),
        );
    }

    public function testItOverridesABlockThatExistsInTheCore(): void
    {
        // page_checkout_cart_product_table ist ein realer Block der Core-Warenkorb-Seite.
        self::assertStringContainsString(
            '{% block page_checkout_cart_product_table %}',
            $this->cartTemplate(),
        );
    }

    public function testNoPhantomBlock(): void
    {
        // `page_checkout_cart_table` gibt es im Kern nicht; ein Override darauf rendert still
        // nichts. Gesucht wird der exakte Block-Ausdruck, denn der Name ist Teilstring gültiger
        // Blöcke wie `page_checkout_cart_table_header`.
        self::assertStringNotContainsString(
            '{% block page_checkout_cart_table %}',
            $this->cartTemplate(),
        );
    }

    public function testTheParentBlockIsKept(): void
    {
        self::assertStringContainsString('{{ parent() }}', $this->cartTemplate());
    }

    /**
     * Die Warenkorb-Seite trägt seit der Zusammenführung beides: die Bausteine des
     * Bestellvorgangs am Basis-Block und Indikator plus Rechner an der Positionstabelle.
     * Beim Zusammenlegen zweier Overrides in eine Datei ist genau das die Stelle, an der
     * einer der beiden still verlorengeht.
     */
    public function testBothOverridesSurviveInOneFile(): void
    {
        $template = $this->cartTemplate();

        self::assertStringContainsString('{% block base_main_inner %}', $template);
        self::assertStringContainsString('progress-bar.html.twig', $template);
        self::assertStringContainsString('trust-badges.html.twig', $template);
        self::assertStringContainsString('rcFreeShipping', $template);
        self::assertStringContainsString('shipping-estimate.html.twig', $template);
    }

    public function testTheOffcanvasExtendsTheCoreOffcanvasCart(): void
    {
        self::assertStringContainsString(
            "{% sw_extends '@Storefront/storefront/component/checkout/offcanvas-cart.html.twig' %}",
            $this->offcanvasTemplate(),
        );
    }

    public function testTheOffcanvasOverridesABlockThatExistsInTheCore(): void
    {
        // component_offcanvas_cart_actions ist ein realer Block des Core-Offcanvas-Warenkorbs.
        self::assertStringContainsString(
            '{% block component_offcanvas_cart_actions %}',
            $this->offcanvasTemplate(),
        );
        self::assertStringContainsString('{{ parent() }}', $this->offcanvasTemplate());
    }

    /**
     * In der Leiste steht der Rechner zugeklappt; aufgeklappt schöbe er die Schaltfläche zur
     * Kasse aus dem Fenster. Fällt die Hülle beim Umbau weg, ist der Rechner offen, und nichts
     * wird rot, weil er ja weiter funktioniert.
     */
    public function testTheOffcanvasEstimateIsCollapsed(): void
    {
        $template = $this->offcanvasTemplate();

        $open = strpos($template, '<details class="rc-offcanvas-estimate');
        $include = strpos($template, 'shipping-estimate.html.twig');
        $close = strpos($template, '</details>');

        self::assertNotFalse($open, 'Der Rechner in der Leiste steht nicht mehr in <details>.');
        self::assertNotFalse($include);
        self::assertNotFalse($close);
        self::assertTrue($open < $include && $include < $close, 'Der Rechner steht außerhalb der Hülle.');
        self::assertStringContainsString('rc-checkout.offcanvasShipping.toggle', $template);
    }

    /**
     * Die Beschriftung der Zeile zum Aufklappen muss in beiden Sprachen stehen — sonst zeigt die
     * Leiste den Schlüssel statt eines Textes.
     */
    public function testTheToggleLabelExistsInBothLanguages(): void
    {
        foreach (['de_DE/storefront.de-DE.json', 'en_GB/storefront.en-GB.json'] as $file) {
            $path = \dirname(__DIR__, 3) . '/src/Resources/snippet/' . $file;
            $snippets = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

            self::assertIsArray($snippets);
            $label = $snippets['rc-checkout']['offcanvasShipping']['toggle'] ?? null;
            self::assertIsString($label, 'Beschriftung fehlt in ' . $file);
            self::assertNotSame('', trim($label));
        }
    }

    /**
     * Der Speditionshinweis hängt an `buy_widget_tax`, einem realen Block des Kaufbereichs, und
     * behält dessen Inhalt; sonst verschwände „zzgl. Versandkosten".
     */
    public function testTheFreightHintExtendsTheCoreBuyWidget(): void
    {
        $template = $this->read('storefront/component/buy-widget/buy-widget.html.twig');

        self::assertStringContainsString(
            "{% sw_extends '@Storefront/storefront/component/buy-widget/buy-widget.html.twig' %}",
            $template,
        );
        self::assertStringContainsString('{% block buy_widget_tax %}', $template);
        self::assertStringContainsString('{{ parent() }}', $template);
        self::assertStringContainsString('data-rc-freight-hint', $template);
    }

    private function cartTemplate(): string
    {
        return $this->read('storefront/page/checkout/cart/index.html.twig');
    }

    private function offcanvasTemplate(): string
    {
        return $this->read('storefront/component/checkout/offcanvas-cart.html.twig');
    }

    private function read(string $relativePath): string
    {
        $path = \dirname(__DIR__, 3) . '/src/Resources/views/' . $relativePath;
        $content = file_get_contents($path);
        self::assertIsString($content, 'Vorlage nicht lesbar: ' . $path);

        return $content;
    }
}
