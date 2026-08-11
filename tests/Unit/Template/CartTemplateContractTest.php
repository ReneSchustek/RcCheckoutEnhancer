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
        // Der früher genutzte Block existiert im Core nicht -> darf nie zurückkehren.
        // Exakter Block-Ausdruck, denn der Name ist Teilstring gültiger Blöcke
        // (page_checkout_cart_table_header etc.).
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
