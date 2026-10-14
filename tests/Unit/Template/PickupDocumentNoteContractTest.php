<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * Pinning-Tests gegen die Beleg-Vorlage.
 *
 * Was auf einem Beleg landet, sieht man erst am fertigen
 * Beleg — und der entsteht außerhalb der Storefront, in einem Weg, den kein Unit-Test durchläuft.
 * Zwei Eigenschaften dieser Vorlage dürfen deshalb nicht still verlorengehen: der Anker am
 * richtigen Block und der Rückfall auf den ausgeschriebenen Satz.
 */
final class PickupDocumentNoteContractTest extends TestCase
{
    private string $template;

    protected function setUp(): void
    {
        $this->template = (string) file_get_contents(
            \dirname(__DIR__, 3) . '/src/Resources/views/documents/base.html.twig',
        );
    }

    /**
     * Der Hinweis hängt am Block mit der Versandart.
     *
     * Ein Override auf einen Block, den es im Elternteil nicht gibt, rendert wortlos nichts,
     * ohne Fehler und ohne Warnung. Der Hinweis verschwände von jedem Beleg,
     * und bemerkt würde es im Streitfall.
     */
    public function testTheNoteHangsOnTheShippingBlock(): void
    {
        self::assertStringContainsString("{% block document_shipping %}", $this->template);
        self::assertStringContainsString('{{ parent() }}', $this->template);
    }

    /**
     * Die Basisvorlage wird erweitert, nicht die Rechnung allein.
     *
     * Über sie wirkt der Hinweis auf Rechnung, Lieferschein, Storno und Gutschrift zugleich.
     * Ein Wechsel auf `invoice.html.twig` nähme ihn drei Belegarten weg.
     */
    public function testItExtendsTheSharedBase(): void
    {
        self::assertStringContainsString("{% sw_extends '@Framework/documents/base.html.twig' %}", $this->template);
    }

    /**
     * Ohne aufgelösten Textbaustein steht trotzdem ein Satz da — nie ein Schlüssel.
     *
     * Der teuerste denkbare Fehlschlag dieser Stelle wäre `rcCheckout.pickupDocumentNote` auf
     * der Rechnung eines Kunden. Ob Textbausteine der Erweiterung in Belegen aufgelöst werden,
     * ist hier nicht prüfbar; dass im Fehlerfall kein Schlüssel dasteht, schon.
     */
    public function testTheSentenceSurvivesAnUnresolvedSnippet(): void
    {
        self::assertStringContainsString(
            "{% if rcPickupText == 'rcCheckout.pickupDocumentNote' %}",
            $this->template
        );
        self::assertStringContainsString(
            'Selbstabholung: Verladen und Transport der Ware liegen beim Käufer.',
            $this->template
        );
    }

    /**
     * Auch die Bestätigungszeile trägt ihren Rückfall.
     */
    public function testTheConfirmationLineSurvivesAnUnresolvedSnippet(): void
    {
        self::assertStringContainsString(
            "{% if rcPickupConfirmed == 'rcCheckout.pickupDocumentConfirmedAt' %}",
            $this->template
        );
        self::assertStringContainsString("'Bestätigt am ' ~ rcPickupNote.confirmedAt", $this->template);
    }

    /**
     * Ohne Bestätigung keine Datumszeile.
     *
     * „Bestätigt am …" ist eine Tatsachenbehauptung. Fiele die Bedingung weg, stünde sie auch auf
     * Belegen zu Bestellungen, bei denen niemand etwas bestätigt hat.
     */
    public function testTheDateLineIsBoundToAConfirmation(): void
    {
        self::assertStringContainsString('{% if rcPickupNote.confirmedAt %}', $this->template);
    }
}
