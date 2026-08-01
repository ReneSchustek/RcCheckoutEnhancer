<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * Pinning-Tests gegen die Confirm-Seiten-Integration. Hintergrund: das Plugin
 * ueberschrieb urspruenglich `page_checkout_confirm_container` — einen Block, den
 * der Storefront-Core in keiner unterstuetzten Version kennt — wodurch Mini-Cart
 * und Order-Summary auf der Bestaetigungsseite still nicht rendern. Diese Tests
 * halten die Korrektur fest, bis ein voller Render-Smoke-Test steht.
 */
final class ConfirmTemplateContractTest extends TestCase
{
    private string $template;

    protected function setUp(): void
    {
        $path = \dirname(__DIR__, 3) . '/src/Resources/views/storefront/page/checkout/confirm/index.html.twig';
        $this->template = (string) file_get_contents($path);
    }

    public function testOverridesAnExistingCoreBlock(): void
    {
        self::assertStringContainsString('{% block page_checkout_confirm %}', $this->template);
        self::assertStringNotContainsString('page_checkout_confirm_container', $this->template);
    }

    public function testCallsParentSoCoreContentSurvives(): void
    {
        self::assertStringContainsString('{{ parent() }}', $this->template);
    }

    public function testIncludesSidebarComponents(): void
    {
        self::assertStringContainsString('mini-cart.html.twig', $this->template);
    }

    /**
     * Die Seitenleiste zeigt ausschliesslich den Warenkorb.
     *
     * Sie enthielt zusaetzlich eine Bestelluebersicht mit Adresse, Versand- und Zahlungsart —
     * alles Angaben, die der Hauptbereich vollstaendig und mit funktionierenden Aendern-Wegen
     * fuehrt. Doppelte Anzeige derselben Sache auf einer Seite irritiert und wird fuer
     * Screenreader zur doppelten Vorlesung; ihre eigenen Aendern-Schaltflaechen sprangen
     * ausserdem auf Anker, die es im Dokument nicht gibt. Dieser Test haelt fest, dass sie
     * nicht zurueckkehrt.
     */
    /**
     * Zeigt die Leiste den Warenkorb, darf der Hauptbereich seine Positionstabelle nicht
     * ebenfalls rendern — sonst steht dieselbe Bestellung zweimal auf der Seite. Der Test
     * haelt beide Haelften der Regel fest: Block ueberschrieben UND an die Leiste gekoppelt.
     */
    public function testProductTableIsSuppressedWhileSidebarShowsTheCart(): void
    {
        self::assertStringContainsString('{% block page_checkout_confirm_product_table %}', $this->template);
        self::assertStringContainsString(
            '{% if not page.extensions.rcCheckoutEnhancer.miniCartEnabled %}',
            $this->template,
        );
    }

    public function testSidebarShowsCartOnly(): void
    {
        self::assertStringContainsString(
            '{% set rcSidebar = page.extensions.rcCheckoutEnhancer.miniCartEnabled %}',
            $this->template,
        );
        self::assertStringNotContainsString('order-summary', $this->template);
        self::assertStringNotContainsString('orderSummaryEnabled', $this->template);
    }

    /**
     * Die drei weiteren Checkout-Overrides (cart/address/finish) haengen ihre Progress-Bar/
     * Trust-Badges an den Basis-Block `base_main_inner` (aus base.html.twig, transitiv geerbt).
     * Verschwindet der Block im Core, wuerde das Markup still nicht rendern — dieselbe
     * Phantom-Klasse wie der historische Confirm-Bug. Hier gegen Rueckfall gepinnt.
     *
     * @return array<string, array{0: string}>
     */
    public static function overridePathProvider(): array
    {
        return [
            'cart' => ['storefront/page/checkout/cart/index.html.twig'],
            'address' => ['storefront/page/checkout/address/index.html.twig'],
            'finish' => ['storefront/page/checkout/finish/index.html.twig'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('overridePathProvider')]
    public function testAdditionalOverridesTargetValidBaseBlock(string $relativePath): void
    {
        $content = (string) file_get_contents(\dirname(__DIR__, 3) . '/src/Resources/views/' . $relativePath);

        self::assertStringContainsString('{% block base_main_inner %}', $content, $relativePath);
        self::assertStringContainsString('{{ parent() }}', $content, $relativePath);
    }
}
