<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * Die Barrierefreiheit der Fortschrittsleiste — festgenagelt.
 *
 * Die Angaben hier sind unsichtbar. Wer sie beim Umbauen verliert, merkt es nicht, solange er
 * nicht mit einer Vorlesehilfe zuhört oder Kontraste nachrechnet. Das BFSG gilt seit dem
 * 28.06.2025; ein stiller Rückfall ist hier kein Schönheitsfehler.
 *
 * Der Kontrast-Test rechnet die Farbpaare nach, statt nur auf Variablennamen zu prüfen. Der
 * Grund steht im Audit vom 2026-08-03: Mit Bootstraps Vorgabewerten sah das alte Paar mit 3,60:1
 * knapp aus, mit den Werten des Trummer-Themes waren es **2,10:1**. Ein Test, der nur
 * `gray-600` verbietet, hätte den nächsten schlechten Wert wieder durchgelassen.
 */
final class ProgressBarAccessibilityTest extends TestCase
{
    /** Die Farbwerte, die das Trummer-Theme tatsächlich setzt (aus dem kompilierten CSS). */
    private const THEME_COLORS = [
        'gray-300' => '#bcc1c7',
        'gray-600' => '#798490',
        'gray-700' => '#495057',
        'primary' => '#3c475d',
        'success' => '#50617c',
        'white' => '#ffffff',
    ];

    private string $template;

    private string $styles;

    protected function setUp(): void
    {
        $base = \dirname(__DIR__, 3) . '/src/Resources';
        $this->template = (string) file_get_contents($base . '/views/storefront/component/rc-checkout/progress-bar.html.twig');
        $this->styles = (string) file_get_contents($base . '/app/storefront/src/scss/base.scss');
    }

    /**
     * Was: Die Semantik der Leiste.
     * Warum: Eine Folge von Schritten ist eine geordnete Liste. Ohne `<nav>` mit Beschriftung
     *        findet niemand sie über die Bereichsnavigation, ohne `aria-current` weiß niemand,
     *        wo er steht.
     */
    public function testTheProgressBarIsSemanticallyMarkedUp(): void
    {
        self::assertStringContainsString('<nav class="rc-checkout-progress', $this->template);
        self::assertStringContainsString('aria-label=', $this->template);
        self::assertStringContainsString('<ol class="rc-checkout-progress__list">', $this->template);
        self::assertStringContainsString('aria-current="step"', $this->template);
    }

    /**
     * Was: Der Zustand steht nicht nur in der Farbe.
     * Warum: WCAG 1.4.1 — wer Farben nicht unterscheidet, muss dieselbe Aussage bekommen.
     *        Deshalb tragen erledigte und aktive Schritte einen nur für Vorlesehilfen
     *        sichtbaren Text.
     */
    public function testTheStepStateIsNotConveyedByColourAlone(): void
    {
        self::assertStringContainsString('visually-hidden', $this->template);
        self::assertStringContainsString('rcCheckout.statusDone', $this->template);
        self::assertStringContainsString('rcCheckout.statusCurrent', $this->template);
    }

    /**
     * Was: Das Häkchen ist Schmuck.
     * Warum: Die Aussage steht im Text daneben; ohne `aria-hidden` läse eine Vorlesehilfe
     *        zusätzlich „Häkchen" oder die Ziffer vor.
     */
    public function testTheIndicatorGlyphIsHiddenFromScreenReaders(): void
    {
        self::assertMatchesRegularExpression(
            '/rc-checkout-progress__indicator"\s+aria-hidden="true"/',
            $this->template,
        );
    }

    /**
     * Was: Die Kontraste der Fortschrittsleiste, nachgerechnet.
     * Warum: **Der Befund des Audits.** Der noch nicht erreichte Schritt lag bei 2,10:1 — WCAG
     *        1.4.3 verlangt 4,5:1. Gerechnet wird mit den echten Theme-Werten, nicht mit den
     *        Fallbacks im Quelltext; genau diese Lücke hatte den Fehler verdeckt.
     */
    public function testAllColourPairsMeetTheContrastRequirement(): void
    {
        $indicatorColor = $this->extractColorVariable('&__indicator');
        $labelColor = $this->extractColorVariable('&__label');

        $pairs = [
            'Ziffer, noch nicht erreicht' => [$indicatorColor, 'gray-300'],
            'Beschriftung, noch nicht erreicht' => [$labelColor, 'white'],
        ];

        foreach ($pairs as $description => [$foreground, $background]) {
            self::assertGreaterThanOrEqual(
                4.5,
                $this->contrast(self::THEME_COLORS[$foreground], self::THEME_COLORS[$background]),
                sprintf('%s: unter 4,5:1 (WCAG 1.4.3)', $description),
            );
        }
    }

    /**
     * Was: Abbestellte Bewegung wird beachtet.
     * Warum: Die Systemeinstellung zu ignorieren ist eine Entscheidung gegen den Nutzer — auch
     *        bei einer kleinen Drehung.
     */
    public function testReducedMotionIsRespected(): void
    {
        self::assertStringContainsString('prefers-reduced-motion', $this->styles);
    }

    /** Liest den Namen der Farbvariablen aus einem SCSS-Block. */
    private function extractColorVariable(string $selector): string
    {
        $position = strpos($this->styles, $selector);
        self::assertNotFalse($position, sprintf('Selektor %s nicht gefunden', $selector));

        // Großzügig gefasst: Zwischen Selektor und Farbe steht die Begründung, warum es diese
        // Farbe ist — und die ist lang, weil der Fehler teuer war. Gesucht wird die **erste**
        // `color: var(--bs-gray-…)`-Zeile nach dem Selektor; die Zustandsvarianten darunter
        // setzen `#fff` und werden davon nicht getroffen.
        $block = substr($this->styles, $position, 2500);
        $matched = preg_match('/color:\s*var\(--bs-(gray-\d+)/', $block, $matches);
        self::assertSame(1, $matched, sprintf('Keine Farbvariable in %s gefunden', $selector));

        return $matches[1];
    }

    private function contrast(string $first, string $second): float
    {
        $a = $this->luminance($first);
        $b = $this->luminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    private function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');

        $channels = [];
        foreach ([0, 2, 4] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
