<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * Pinning-Tests gegen die Breitenregeln der Bestätigungsseite.
 *
 * Die Regeln nehmen der Seite die Schmalfassung des Kerns ab, aber nur, solange die
 * Warenkorb-Leiste läuft. Fällt die Bindung an
 * `--with-sidebar` weg, verliert auch die Vergleichsgruppe eines A/B-Tests die Fassung, für die
 * sie sich entschieden hat. Das sieht man im Browser nur, wenn man die Leiste abschaltet — im
 * Code sieht es nach einer harmlosen Vereinfachung aus.
 */
final class ConfirmLayoutWidthContractTest extends TestCase
{
    private string $stylesheet;

    protected function setUp(): void
    {
        $this->stylesheet = (string) file_get_contents(
            \dirname(__DIR__, 3) . '/src/Resources/app/storefront/src/scss/base.scss',
        );
    }

    /**
     * Die volle Breite hängt am Markup der Leiste, nicht am Seitentyp. Ein Selektor, der nur
     * die Route prüft, träfe auch die Seite ohne Leiste.
     */
    public function testTheFullWidthIsBoundToTheSidebarMarkup(): void
    {
        self::assertStringContainsString(
            '.checkout .checkout-main:has(.rc-checkout-confirm-layout--with-sidebar)',
            $this->stylesheet
        );
    }

    /**
     * Die untere Reihe hängt an derselben Bedingung. Ohne sie stünden Zusatzangaben und
     * Zusammenfassung auch dann in der neuen Aufteilung, wenn darüber das Raster des Kerns gilt.
     */
    public function testTheBottomRowIsBoundToTheSameCondition(): void
    {
        self::assertStringContainsString(
            '.checkout .checkout-container:has(.rc-checkout-confirm-layout--with-sidebar)',
            $this->stylesheet
        );
    }

    /**
     * Die Breiten der unteren Reihe rechnen mit dem Zwischenraum des Themes. Eine feste Zahl
     * statt `var(--bs-gutter-x)` verschiebt die Kanten in jedem Theme, das anders rechnet als
     * der Kern — der Demoshop nimmt 40 Pixel, der Kern 24.
     */
    public function testTheBottomRowUsesTheThemeGutter(): void
    {
        self::assertStringContainsString('calc(66.6667% - (2 * var(--bs-gutter-x) / 3))', $this->stylesheet);
        self::assertStringContainsString('calc(33.3333% + (2 * var(--bs-gutter-x) / 3))', $this->stylesheet);
    }

    /**
     * Alle Regeln stehen oberhalb von 992 Pixeln. Darunter rutscht die Leiste über den
     * Hauptbereich, und das Raster des Kerns ist dort richtig.
     */
    public function testEveryWidthRuleStaysAboveTheBreakpoint(): void
    {
        $section = $this->confirmLayoutSection();

        foreach (['width: calc(66.6667%', 'width: calc(33.3333%', 'width: 100%'] as $regel) {
            $position = strpos($section, $regel);
            self::assertIsInt($position, sprintf('Regel "%s" steht nicht im Abschnitt.', $regel));

            $preceding = substr($section, 0, $position);
            $letzteAbfrage = strrpos($preceding, '@media (min-width: 992px)');
            self::assertIsInt($letzteAbfrage, sprintf('Regel "%s" steht außerhalb einer Breitenabfrage.', $regel));
        }
    }

    /**
     * Der Zwischenraum der Leiste folgt ab 992 Pixeln dem Raster — darunter bleibt er, wie er
     * war. Stünde die Zeile außerhalb der Abfrage, änderte sich der Abstand auch am Telefon.
     */
    public function testTheSidebarGapFollowsTheGridOnlyAboveTheBreakpoint(): void
    {
        $section = strstr($this->stylesheet, '.rc-checkout-confirm-layout--with-sidebar {');
        self::assertIsString($section);

        $position = strpos($section, 'gap: var(--bs-gutter-x, 1.5rem)');
        self::assertIsInt($position, 'Der Zwischenraum folgt dem Raster nicht.');

        $preceding = substr($section, 0, $position);
        self::assertIsInt(
            strrpos($preceding, '@media (min-width: 992px)'),
            'Der Zwischenraum des Rasters gilt auch unter 992 Pixeln.'
        );
    }

    private function confirmLayoutSection(): string
    {
        $section = strstr($this->stylesheet, '// === Confirm-Layout');
        self::assertIsString($section, 'Abschnitt des Confirm-Layouts nicht gefunden.');

        return $section;
    }
}
