<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * Hält die Vorlagen des einseitigen Checkouts fest.
 *
 * Fast alles, was hier schiefgehen kann, geht still schief: Ein Block, den der Kern nicht kennt,
 * rendert nichts; ein Ausschnitt, der eine ganze Seite liefert, setzt Kopf und Fuß mitten ins
 * Formular; ein `ab_switch()` ohne RcAbTesting legt den Checkout lahm.
 */
final class OnePageCheckoutTemplateContractTest extends TestCase
{
    private const ADDRESS_PAGE = 'storefront/page/checkout/address/index.html.twig';

    private const ONE_PAGE_ADDRESS = 'storefront/component/rc-checkout/one-page-address.html.twig';

    private const REGISTER_FORM = 'storefront/page/checkout/address/register.html.twig';

    private const LAYOUT = 'storefront/component/rc-checkout/checkout-layout.html.twig';

    private const AB_LAYOUT = 'storefront/component/rc-checkout/ab-checkout-layout.html.twig';

    private const SELECTION = 'storefront/component/rc-checkout/one-page-selection.html.twig';

    private const PROGRESS = 'storefront/component/rc-checkout/progress-bar.html.twig';

    /**
     * `ab_switch()` gibt es nur mit RcAbTesting, und Twig sucht eine Funktion schon beim
     * Übersetzen. Die Datei mit dem Aufruf darf deshalb nur eingebunden werden, wenn das Tor
     * offen ist, und nirgends sonst darf der Aufruf stehen.
     */
    public function testAbSwitchIsCalledInExactlyOneTemplate(): void
    {
        $callers = [];
        foreach ($this->allTemplates() as $path => $content) {
            if (preg_match('/ab_switch\(/', (string) preg_replace('/\{#.*?#\}/s', '', $content)) === 1) {
                $callers[] = $path;
            }
        }

        self::assertSame([self::AB_LAYOUT], $callers);
    }

    public function testTheAbPartialIsOnlyIncludedBehindTheGate(): void
    {
        $layout = $this->read(self::LAYOUT);

        self::assertStringContainsString('abSwitchAvailable', $layout);
        self::assertStringContainsString('ab-checkout-layout.html.twig', $layout);
        self::assertLessThan(
            strpos($layout, 'ab-checkout-layout.html.twig'),
            strpos($layout, 'abSwitchAvailable'),
            'Das Tor muss vor dem Einbinden geprüft werden.',
        );
    }

    public function testTheAddressPageChoosesItsLayoutThroughThePartial(): void
    {
        $template = $this->read(self::ADDRESS_PAGE);

        self::assertStringContainsString('checkout-layout.html.twig', $template);
        self::assertStringContainsString('one-page-address.html.twig', $template);
        self::assertStringContainsString('{% block page_checkout_address %}', $template);
        self::assertStringContainsString('{{ parent() }}', $template);
    }

    /**
     * Die drei Wege stehen gleichwertig oben: Gastbestellung, Bereits Kunde, Neues Kundenkonto.
     */
    public function testTheThreePathsAreOffered(): void
    {
        $template = $this->read(self::ONE_PAGE_ADDRESS);

        foreach (['guest', 'login', 'register'] as $path) {
            self::assertStringContainsString('data-rc-checkout-path-choice="' . $path . '"', $template);
        }
        self::assertStringContainsString('data-rc-checkout-path', $template);
    }

    /**
     * „Weiter" steht unter Versandart, Zahlart und Summen. Stünde es im Formular darüber, klickte
     * der Kunde es nach der Adresse und sähe die Auswahl darunter nie.
     */
    public function testTheContinueButtonComesAfterTheSelection(): void
    {
        $template = $this->read(self::ONE_PAGE_ADDRESS);

        $selection = strpos($template, 'data-rc-one-page-selection');
        $continue = strpos($template, 'data-rc-checkout-path-continue');
        self::assertIsInt($selection);
        self::assertIsInt($continue);
        self::assertGreaterThan($selection, $continue);

        // Der Knopf im Formular bleibt für den Fall ohne Skript; das Skript blendet ihn aus.
        self::assertStringContainsString('{% block component_account_register_submit %}', $this->read(self::REGISTER_FORM));
        self::assertStringContainsString('data-rc-checkout-path-form-submit', $this->read(self::REGISTER_FORM));
    }

    /**
     * Das Häkchen „Kundenkonto anlegen" bleibt im Formular, damit der Kern weiß, was er anlegt;
     * zu sehen ist es nur, wenn kein Skript die Knöpfe bedient.
     */
    public function testTheGuestCheckboxStaysInTheForm(): void
    {
        $template = $this->read(self::REGISTER_FORM);

        self::assertStringContainsString('{% block page_checkout_register_personal_guest %}', $template);
        self::assertStringContainsString('{{ parent() }}', $template);
    }

    /**
     * Das Ajax-Verfahren des Kerns ersetzt den Container durch die ganze Antwort. Der Ausschnitt
     * darf deshalb weder eine Seite erweitern noch seinen eigenen Container mitbringen.
     */
    public function testTheSelectionIsAFragmentWithoutItsOwnContainer(): void
    {
        $template = $this->read(self::SELECTION);

        self::assertStringNotContainsString('sw_extends', $template);
        self::assertSame(0, preg_match('/<[a-z]+[^>]*\sdata-rc-one-page-selection[\s>]/', $template));
        self::assertStringContainsString('data-rc-one-page-selection', $this->read(self::ONE_PAGE_ADDRESS));
    }

    /**
     * Versand- und Zahlart schicken per Ajax ab und lassen sich danach den Ausschnitt geben.
     * Ohne das lüde jeder Wechsel die Seite neu, und das eingetippte Adressformular wäre leer.
     */
    public function testTheFormsSubmitByAjaxIntoTheContainer(): void
    {
        $template = $this->read(self::SELECTION);

        self::assertSame(2, substr_count($template, "path('frontend.checkout.configure')"));
        self::assertSame(2, substr_count($template, 'frontend.rc-checkout.one-page.selection'));
        self::assertSame(2, substr_count($template, 'useAjax: true'));
        self::assertSame(2, substr_count($template, "ajaxContainerSelector: '[data-rc-one-page-selection]'"));
    }

    public function testTheProgressBarKnowsTheOnePageLayout(): void
    {
        $template = $this->read(self::PROGRESS);

        self::assertStringContainsString('checkout-layout.html.twig', $template);
        self::assertStringContainsString('rcCheckout.onePage.progressStep', $template);
    }

    /**
     * Die Blöcke, an denen der einseitige Checkout hängt, müssen im Kern existieren.
     */
    public function testTheCoreBlocksExist(): void
    {
        $coreDir = \dirname(__DIR__, 3) . '/vendor/shopware/storefront/Resources/views/';
        if (!is_dir($coreDir)) {
            self::markTestSkipped('Core-Vorlagen nicht verfügbar, ohne sie ist der Vergleich wertlos.');
        }

        $expectations = [
            self::ADDRESS_PAGE => ['page_checkout_address', 'page_checkout_aside_summary'],
            'storefront/page/checkout/_page.html.twig' => ['page_checkout_summary_header', 'page_checkout_summary_list'],
            self::REGISTER_FORM => ['page_checkout_register_personal_guest'],
            'storefront/component/account/register.html.twig' => ['component_account_register_submit'],
        ];

        foreach ($expectations as $file => $blocks) {
            $core = (string) file_get_contents($coreDir . $file);
            foreach ($blocks as $block) {
                self::assertStringContainsString('{% block ' . $block . ' %}', $core, $file . ': ' . $block);
            }
        }
    }

    public function testTheSnippetsExistInBothLanguages(): void
    {
        $keys = ['pathLegend', 'pathGuest', 'pathLogin', 'pathRegister', 'finalStepHint', 'progressStep'];

        foreach (['de_DE/storefront.de-DE.json', 'en_GB/storefront.en-GB.json'] as $file) {
            $path = \dirname(__DIR__, 3) . '/src/Resources/snippet/' . $file;
            $snippets = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($snippets);

            foreach ($keys as $key) {
                $text = $snippets['rcCheckout']['onePage'][$key] ?? null;
                self::assertIsString($text, $file . ': ' . $key);
                self::assertNotSame('', trim($text));
            }
        }
    }

    public function testThePathChooserIsRegistered(): void
    {
        $main = (string) file_get_contents(\dirname(__DIR__, 3) . '/src/Resources/app/storefront/src/main.js');

        self::assertStringContainsString("'[data-rc-checkout-path]'", $main);
    }

    /**
     * @return array<string, string> relativer Pfad => Inhalt
     */
    private function allTemplates(): array
    {
        $base = \dirname(__DIR__, 3) . '/src/Resources/views/';
        $templates = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'twig') {
                $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen($base)));
                $templates[$relative] = (string) file_get_contents($file->getPathname());
            }
        }

        return $templates;
    }

    private function read(string $relativePath): string
    {
        $path = \dirname(__DIR__, 3) . '/src/Resources/views/' . $relativePath;
        $content = file_get_contents($path);
        self::assertIsString($content, 'Vorlage nicht lesbar: ' . $path);

        return $content;
    }
}
