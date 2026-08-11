<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * Pinning-Tests gegen die Confirm-Seiten-Integration. Hintergrund: das Plugin
 * überschrieb ursprünglich `page_checkout_confirm_container` — einen Block, den
 * der Storefront-Core in keiner unterstützten Version kennt — wodurch Mini-Cart
 * und Order-Summary auf der Bestätigungsseite still nicht rendern. Diese Tests
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
     * Die Seitenleiste zeigt ausschließlich den Warenkorb.
     *
     * Sie enthielt zusätzlich eine Bestellübersicht mit Adresse, Versand- und Zahlungsart —
     * alles Angaben, die der Hauptbereich vollständig und mit funktionierenden Ändern-Wegen
     * führt. Doppelte Anzeige derselben Sache auf einer Seite irritiert und wird für
     * Screenreader zur doppelten Vorlesung; ihre eigenen Ändern-Schaltflächen sprangen
     * außerdem auf Anker, die es im Dokument nicht gibt. Dieser Test hält fest, dass sie
     * nicht zurückkehrt.
     */
    /**
     * Zeigt die Leiste den Warenkorb, darf der Hauptbereich seine Positionstabelle nicht
     * ebenfalls rendern — sonst steht dieselbe Bestellung zweimal auf der Seite. Der Test
     * hält beide Hälften der Regel fest: Block überschrieben UND an die Leiste gekoppelt.
     */
    public function testProductTableIsSuppressedWhileSidebarShowsTheCart(): void
    {
        self::assertStringContainsString('{% block page_checkout_confirm_product_table %}', $this->template);
        self::assertStringContainsString('not rc.miniCartEnabled', $this->template);
    }

    /**
     * Hält sich die Leiste wegen eines A/B-Tests zurück, muss die Tabelle zurückkommen.
     *
     * **Das ist der gefährlichste Punkt am ganzen Test.** Bliebe die Tabelle unterdrückt und die
     * Leiste weg, sähe die Vergleichsgruppe auf der Bestätigungsseite überhaupt keinen Warenkorb
     * — und bestätigte eine Bestellung, die sie nicht mehr prüfen kann. Derselbe Fehler wie am
     * 2026-07-28, nur eine Stufe schlimmer: damals fehlte eine Angabe, hier die ganze Übersicht.
     */
    public function testTheProductTableReturnsWhenTheSidebarIsSuppressed(): void
    {
        self::assertStringContainsString('{% if rcSuppressed or not rc.miniCartEnabled %}', $this->template);
    }

    public function testSidebarShowsCartOnly(): void
    {
        self::assertStringContainsString(
            '{% set rcSidebar = not rcSuppressed and rc.miniCartEnabled %}',
            $this->template,
        );
        self::assertStringNotContainsString('order-summary', $this->template);
        self::assertStringNotContainsString('orderSummaryEnabled', $this->template);
    }

    /**
     * `ab_variant()` darf nur an einer einzigen Stelle stehen.
     *
     * Die Funktion gibt es nur mit RcAbTesting, und Twig bricht bei einer unbekannten Funktion
     * schon beim Übersetzen ab — nicht erst beim Aufruf. Stünde sie in einer Vorlage, die immer
     * übersetzt wird, stünde ohne RcAbTesting der ganze Checkout. Ausgelagert in eine eigene
     * Datei, die nur eingebunden wird, wenn ein Experiment konfiguriert **und** das Plugin
     * geladen ist, wird sie nie gesucht.
     */
    public function testTheAbFunctionLivesInExactlyOneTemplate(): void
    {
        $viewsDir = \dirname(__DIR__, 3) . '/src/Resources/views';
        $withCall = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($viewsDir));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'twig') {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), 'ab_variant(')) {
                $withCall[] = $file->getFilename();
            }
        }

        self::assertSame(['ab-suppressed.html.twig'], $withCall);
    }

    /**
     * Die drei weiteren Checkout-Overrides (cart/address/finish) hängen ihre Progress-Bar/
     * Trust-Badges an den Basis-Block `base_main_inner` (aus base.html.twig, transitiv geerbt).
     * Verschwindet der Block im Core, würde das Markup still nicht rendern — dieselbe
     * Phantom-Klasse wie der historische Confirm-Bug. Hier gegen Rückfall gepinnt.
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
