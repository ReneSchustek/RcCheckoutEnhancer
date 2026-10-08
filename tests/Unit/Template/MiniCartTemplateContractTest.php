<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * Festnagelungen an der Warenkorb-Leiste.
 *
 * Zwei Dinge, die beim nächsten Umbau still verloren gehen können.
 *
 * Die Positionen kommen aus dem Core-Template. Auf der Bestätigungsseite ist die Leiste die
 * einzige Warenkorb-Darstellung; umginge ihr Markup den Erweiterungspunkt der Positionszeilen,
 * fehlte etwa die von `RcColorPicker` gewählte RAL-Farbe genau auf der Seite, auf der der Kunde
 * bestätigt.
 *
 * Die Auszeichnung des Umschalters. `aria-controls` und `aria-hidden` sind unsichtbar; wer sie
 * beim Umbauen verliert, merkt es nicht, solange er nicht mit einer Vorlesehilfe zuhört.
 *
 * Der Smoke-Test deckt den ersten Punkt end-to-end ab. Diese Tests laufen in Millisekunden und
 * sagen, was kaputt ist, nicht nur dass etwas fehlt.
 */
final class MiniCartTemplateContractTest extends TestCase
{
    private string $template;

    protected function setUp(): void
    {
        $path = \dirname(__DIR__, 3) . '/src/Resources/views/storefront/component/rc-checkout/mini-cart.html.twig';
        $this->template = (string) file_get_contents($path);
    }

    /**
     * Was: Die Positionen kommen über das Core-Template.
     * Warum: Eigenes Markup umgeht die Blöcke, an denen andere Plugins ihre Positionsangaben
     *        aufhängen — Farbe, Zweitpreis, Kundeneingabe. Auf der Confirm-Seite ist die Leiste
     *        die einzige Darstellung; was hier fehlt, sieht der Kunde nirgends.
     * Erwartet: Das Core-Template wird eingebunden.
     */
    public function testLineItemsAreRenderedThroughTheCoreTemplate(): void
    {
        self::assertStringContainsString(
            '@Storefront/storefront/component/line-item/type/product.html.twig',
            $this->template,
        );
    }

    /**
     * Was: `showSubtotal` bleibt an.
     * Warum: Der Positionspreis ist der Block, an dem Preis-Erweiterungen hängen — RcDualPrice
     *        setzt seinen Netto-Zweitpreis dort hinein. Mit `false` rendert der Kern den Block
     *        gar nicht: gemessen zeigte der Warenkorb den Zweitpreis zweimal, die Confirm-Seite
     *        keinmal. Derselbe Fehler wie bei der Farbe, nur eine Ebene tiefer.
     * Erwartet: `showSubtotal: true`.
     */
    public function testTheSubtotalBlockStaysEnabled(): void
    {
        self::assertMatchesRegularExpression('/showSubtotal:\s*true/', $this->template);
    }

    /**
     * Was: Die Leiste bearbeitet den Warenkorb nicht.
     * Warum: Auf der Bestätigungsseite wird nicht mehr geändert. Ein Entfernen-Weg dort führt zu
     *        einer halb abgesendeten Bestellung.
     * Erwartet: `showRemoveButton: false`.
     */
    public function testTheSidebarDoesNotOfferToChangeTheCart(): void
    {
        self::assertMatchesRegularExpression('/showRemoveButton:\s*false/', $this->template);
    }

    /**
     * Was: Der Umschalter benennt den Bereich, den er auf- und zuklappt.
     * Warum: `aria-expanded` allein beschreibt einen Zustand ohne Gegenstand — eine Vorlesehilfe
     *        weiß, dass etwas ausklappt, aber nicht was.
     * Erwartet: `aria-controls` zeigt auf die Kennung des Bereichs.
     */
    public function testTheToggleNamesTheRegionItControls(): void
    {
        self::assertStringContainsString('aria-controls="rcMiniCartCollapse"', $this->template);
        self::assertStringContainsString('id="rcMiniCartCollapse"', $this->template);
    }

    /**
     * Was: Das Pfeilzeichen wird nicht vorgelesen.
     * Warum: Es ist Schmuck; der Zustand steht bereits in `aria-expanded`. Ohne `aria-hidden`
     *        hörte der Nutzer zusätzlich „Dreieck nach unten".
     * Erwartet: Das Zeichen trägt `aria-hidden`.
     */
    public function testTheDecorativeArrowIsHiddenFromScreenReaders(): void
    {
        self::assertMatchesRegularExpression(
            '/rc-checkout-mini-cart__toggle-icon"\s+aria-hidden="true"/',
            $this->template,
        );
    }

    /**
     * Was: Die Klassennamen, die das Stilblatt ausblendet, gibt es im Core-Template wirklich.
     * Warum: Eine Regel auf einen Namen, den es nicht gibt, ist still wirkungslos. Nichts wird
     *        rot, nichts bricht; es sieht nur falsch aus, und zwar auf der Seite, auf der der
     *        Kunde bestätigt. Namen wie `.line-item-quantity-select` oder
     *        `.line-item-ordernumber` kennt Shopware 6.7 nicht; eine Regel darauf ließe eine
     *        bedienbare Mengen-Auswahl in der schmalen Spalte stehen, deren Zahl abgeschnitten
     *        ist. Dieselbe Art Fehler wie ein Twig-Block, den der Kern nicht kennt.
     * Erwartet: Jeder ausgeblendete Name steht im Core-Template.
     */
    public function testEveryHiddenClassExistsInTheCoreTemplate(): void
    {
        // Der ganze Baustein, nicht nur `product.html.twig`: Die Positionszeile setzt sich aus
        // eingebundenen Teilstücken zusammen — die Bezeichnung etwa kommt aus `element/label`.
        $coreDir = \dirname(__DIR__, 3)
            . '/vendor/shopware/storefront/Resources/views/storefront/component/line-item';

        if (!is_dir($coreDir)) {
            self::markTestSkipped('Core-Vorlagen nicht verfügbar — ohne sie ist der Vergleich wertlos.');
        }

        $coreTemplate = '';
        $dateien = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($coreDir));
        foreach ($dateien as $datei) {
            if ($datei instanceof \SplFileInfo && $datei->getExtension() === 'twig') {
                $coreTemplate .= (string) file_get_contents($datei->getPathname());
            }
        }

        $scss = (string) file_get_contents(
            \dirname(__DIR__, 3) . '/src/Resources/app/storefront/src/scss/base.scss',
        );

        // Nur der Abschnitt der Leiste: Was außerhalb steht, zielt auf eigenes Markup und hat
        // mit dem Core-Template nichts zu tun.
        $sidebar = strstr($scss, '.rc-checkout-mini-cart');
        self::assertIsString($sidebar, 'Abschnitt der Warenkorb-Leiste nicht im Stilblatt gefunden.');

        // Kommentare heraus, bevor gesucht wird: Dort können falsche Namen als
        // Warnung für den nächsten Leser. Ohne diesen Schritt meldete der Test genau die
        // Erklärung als Fehler, die den Fehler beschreibt.
        $sidebar = (string) preg_replace('#//[^\n]*#', '', $sidebar);

        preg_match_all('/\.(line-item-[a-z0-9-]+)/', $sidebar, $treffer);
        $verwendet = array_unique($treffer[1]);

        self::assertNotEmpty($verwendet, 'Keine Core-Klassen im Abschnitt — dann prüft dieser Test nichts.');

        foreach ($verwendet as $klasse) {
            // Ganzer Name, nicht Teilzeichenfolge. Eine Prüfung auf Enthaltensein ließe
            // `.line-item-quantity-select` durchgehen, weil der Core ein
            // `line-item-quantity-select-wrapper` rendert. Ein CSS-Klassenselektor greift auf
            // ganze Namen, eine Teilzeichenfolge sagt darüber nichts.
            self::assertMatchesRegularExpression(
                '/\b' . preg_quote($klasse, '/') . '(?![\w-])/',
                $coreTemplate,
                \sprintf('Das Stilblatt zielt auf ".%s" — diese Klasse rendert der Core nirgends.', $klasse),
            );
        }
    }
}
