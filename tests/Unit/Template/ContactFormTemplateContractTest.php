<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * Nagelt die Einhängestellen im Kontaktformular fest — und den Rückfall auf den Kern.
 *
 * Zwei Fehler sind hier möglich, und beide sind still:
 *
 * 1. **Ein Blockname, den es im Kern nicht gibt.** Twig ignoriert ihn wortlos; das Feld
 *    bliebe einfach leer, ohne dass irgendwo etwas rot wird. Genau so ist der
 *    Versandkostenfrei-Banner von 1.1.1 monatelang nie erschienen.
 * 2. **Ein fehlender `parent()`-Zweig.** Diese Vorlage liegt auf **jeder** Kontaktseite.
 *    Ohne Rückfall verlöre jede gewöhnliche Kontaktanfrage ihre Felder — der teuerste
 *    denkbare Fehler dieses Briefs, und er beträfe Besucher, die mit dem Bestellvorgang
 *    nie in Berührung kamen.
 */
final class ContactFormTemplateContractTest extends TestCase
{
    /**
     * Die Blöcke, die dieses Plugin überschreibt. Jeder existiert im Kern-Kontaktformular
     * (`@Storefront/storefront/element/cms-element-form/form-types/contact-form.html.twig`).
     */
    private const OVERRIDDEN_BLOCKS = [
        'cms_form_contact_select_salutation',
        'cms_form_contact_input_first_name',
        'cms_form_contact_input_last_name',
        'cms_form_contact_input_email',
        'cms_form_contact_input_phone',
        'cms_form_contact_comment_textarea',
    ];

    public function testItExtendsTheCoreContactForm(): void
    {
        self::assertStringContainsString(
            "{% sw_extends '@Storefront/storefront/element/cms-element-form/form-types/contact-form.html.twig' %}",
            $this->template(),
        );
    }

    public function testEveryOverriddenBlockIsOneOfTheKnownCoreBlocks(): void
    {
        $template = $this->template();

        foreach (self::OVERRIDDEN_BLOCKS as $block) {
            self::assertStringContainsString(
                '{% block ' . $block . ' %}',
                $template,
                \sprintf('Der Block %s fehlt — dann wird an dieser Stelle nichts vorbelegt.', $block),
            );
        }
    }

    /**
     * **Die wichtigste Zusicherung dieser Datei.** Ohne übernommenen Warenkorb muss jeder
     * Block das Formular des Kerns rendern, unverändert.
     */
    public function testEveryOverriddenBlockFallsBackToTheCore(): void
    {
        $template = $this->template();

        foreach (self::OVERRIDDEN_BLOCKS as $block) {
            self::assertStringContainsString(
                '{{ parent() }}',
                $this->bodyOf($block, $template),
                \sprintf(
                    'Der Block %s hat keinen Rückfall — eine gewöhnliche Kontaktanfrage verlöre dieses Feld.',
                    $block,
                ),
            );
        }
    }

    /**
     * Das Wabenfeld der Spam-Abwehr steht in einem eigenen Block, ist ausgeblendet und muss
     * leer bleiben. Wer es anfasst, macht die Abwehr wirkungslos — auf Live ist es seit dem
     * Abschalten des Bilderrätsels die einzige verbliebene Hürde.
     */
    public function testTheCaptchaBlockIsLeftAlone(): void
    {
        self::assertStringNotContainsString(
            '{% block cms_form_contact_captcha %}',
            $this->template(),
            'Die Spam-Abwehr wird nicht überschrieben — sie ist auf Live die einzige verbliebene Hürde.',
        );
    }

    /**
     * Der Inhalt eines Blocks, vom `{% block … %}` bis zum nächsten. Nötig, weil der
     * Kommentarkopf dieser Vorlage `parent()` erwähnt — eine Zählung über die ganze Datei
     * würde davon getäuscht.
     */
    private function bodyOf(string $block, string $template): string
    {
        $start = strpos($template, '{% block ' . $block . ' %}');
        self::assertIsInt($start, \sprintf('Der Block %s fehlt.', $block));

        $next = strpos($template, '{% block ', $start + 1);

        return $next === false
            ? substr($template, $start)
            : substr($template, $start, $next - $start);
    }

    private function template(): string
    {
        $path = __DIR__
            . '/../../../src/Resources/views/storefront/element/cms-element-form/form-types/contact-form.html.twig';

        $content = file_get_contents($path);
        self::assertIsString($content, 'Die Vorlage des Kontaktformulars fehlt.');

        return $content;
    }
}
