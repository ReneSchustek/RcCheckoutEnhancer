<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * Pinning-Tests gegen die Einbindung des Abhol-Hinweises.
 *
 * Die Versandart-Auswahl liegt in `confirm-shipping.html.twig` und wird per `sw_include`
 * eingebunden. Blöcke einer eingebundenen Vorlage sind von der Seitenvorlage aus nicht
 * erreichbar; ein `{% block page_checkout_confirm_shipping_inner %}` in
 * `confirm/index.html.twig` täte still nichts. Deshalb gibt es eine eigene Vorlagendatei, die
 * `confirm-shipping.html.twig` erweitert, und diese Tests halten sie fest.
 */
final class PickupHintTemplateContractTest extends TestCase
{
    private string $shippingTemplate;

    private string $hintTemplate;

    protected function setUp(): void
    {
        $wurzel = \dirname(__DIR__, 3) . '/src/Resources/views/storefront';
        $this->shippingTemplate = (string) file_get_contents($wurzel . '/page/checkout/confirm/confirm-shipping.html.twig');
        $this->hintTemplate = (string) file_get_contents($wurzel . '/component/rc-checkout/pickup-hint.html.twig');
    }

    /**
     * Erweitert werden muss die Vorlage, in der der Block wirklich steht, sonst
     * überschreibt der Block nichts.
     */
    public function testItExtendsTheTemplateThatOwnsTheBlock(): void
    {
        self::assertStringContainsString(
            "{% sw_extends '@Storefront/storefront/page/checkout/confirm/confirm-shipping.html.twig' %}",
            $this->shippingTemplate
        );
        self::assertStringContainsString('{% block page_checkout_confirm_shipping_inner %}', $this->shippingTemplate);
    }

    /**
     * Ohne `parent()` verschwände die Versandart-Auswahl selbst — der Kunde könnte nicht
     * mehr wählen, und der Hinweis stünde allein da.
     */
    public function testTheShippingSelectionItselfSurvives(): void
    {
        self::assertStringContainsString('{{ parent() }}', $this->shippingTemplate);
    }

    /**
     * Der Hinweis hängt an der Erweiterung, die der Subscriber setzt — und nur an ihr.
     * Ohne diese Bedingung stünde er auf jeder Bestätigungsseite.
     */
    public function testTheHintIsBoundToTheSubscriberExtension(): void
    {
        self::assertStringContainsString('page.extensions.rcPickupHint is defined', $this->shippingTemplate);
        self::assertStringContainsString('pickup-hint.html.twig', $this->shippingTemplate);
    }

    /**
     * Der Wortlaut kommt fertig aus dem Decider; die Vorlage entscheidet nichts.
     *
     * An der Bestellung festgehalten wird der Satz, den der Kunde gelesen hat, und den kennt nur,
     * wer den Rückfall auf den Textbaustein auflöst. Trüge die Vorlage den Rückfall selbst
     * (`rcPickup.hint ?: '…'|trans`), stünde am Nachweis eine leere Zeichenkette, sobald der
     * Betreiber die Vorgabe nie überschrieben hat.
     */
    public function testTheWordingComesReadyFromTheDecider(): void
    {
        self::assertStringContainsString('{{ rcPickup.hint }}', $this->hintTemplate);
        self::assertStringNotContainsString("rcPickup.hint ?:", $this->hintTemplate);
    }

    /**
     * Ohne Javascript bleibt der Hinweis lesbar, und die Bestellung trotzdem gesperrt.
     *
     * Der Dialog braucht ein Skript, der Kasten nicht: Er wird serverseitig gerendert und steht
     * auch dann da, wenn kein Skript läuft; die Sperre hängt ohnehin am Server. Ein
     * eingebettetes `<script>` ist verboten, das Skript gehört in die gebaute Datei, nicht in
     * die Vorlage.
     */
    public function testTheHintRemainsReadableWithoutJavascript(): void
    {
        self::assertStringContainsString('data-rc-pickup-box', $this->hintTemplate);
        self::assertStringNotContainsString('<script', $this->hintTemplate);
    }

    /**
     * Abbrechen stellt die Versandart zurück, statt den Dialog nur zuzuklappen.
     *
     * Wer schließt, will nicht abholen, und dann gehört die Versandart zurückgestellt. Danach trifft die Bedingung nicht mehr zu, der Hinweis
     * verschwindet, und der Warenkorb ist wieder gültig. Damit gibt es die Sackgasse gar nicht,
     * und Escape muss trotzdem keine Zustimmung sein.
     *
     * Über Shopwares eigenen Umschaltweg als Formular; so wirkt der Abbruch auch ohne
     * JavaScript, und die Umschaltung läuft durch dieselbe Prüfung wie jede andere Wahl.
     */
    public function testCancellingRevertsTheShippingMethod(): void
    {
        self::assertStringContainsString("path('frontend.checkout.configure')", $this->hintTemplate);
        self::assertStringContainsString('rcPickup.revertToId', $this->hintTemplate);
    }

    /**
     * Der Kasten trägt einen Rückweg in den Dialog.
     *
     * Ohne ihn wäre das Schließen eine Sackgasse: Die Bestellung bleibt serverseitig gesperrt,
     * der Dialog ist zu, und der einzige Ausweg wäre ein Neuladen der Seite. „Escape zählt als
     * Bestätigung" ist kein Ausweg, denn eine Zusage, die durch Wegklicken zustande kommt, wäre
     * als Nachweis nicht schwach, sondern falsch.
     */
    public function testTheBoxOffersAWayBackIntoTheDialog(): void
    {
        self::assertStringContainsString('data-rc-pickup-reopen', $this->hintTemplate);
    }

    /**
     * Der Dialog erscheint nicht mehr, sobald bestätigt wurde.
     *
     * Ohne diese Bedingung ginge er bei jedem weiteren Seitenaufbau erneut auf — die Bedingung
     * für den Hinweis trifft ja weiterhin zu, nur die Sperre ist weg.
     */
    public function testTheDialogIsGatedByTheAcknowledgement(): void
    {
        self::assertStringContainsString('{% if not rcPickup.acknowledged %}', $this->hintTemplate);
    }
}
