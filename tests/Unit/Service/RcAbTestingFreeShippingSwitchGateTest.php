<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\RcAbTestingFreeShippingSwitchGate;

/**
 * Der Schalter zum A/B-Test des Versandkosten-Hinweises, wenn RcAbTesting nicht installiert ist.
 * Den Ausfall des Fremd-Plugins prüft {@see RcAbTestingFreeShippingSwitchGateFailureTest}.
 */
final class RcAbTestingFreeShippingSwitchGateTest extends TestCase
{
    public function testIsNotSuppressedWhenResolverMissing(): void
    {
        // Ohne RcAbTesting (Resolver null) darf der Hinweis nie unterdrückt werden; er läuft
        // dann ohne A/B-Test.
        $gate = new RcAbTestingFreeShippingSwitchGate(null);

        self::assertFalse($gate->isIndicatorSuppressed());
    }

    /**
     * Was: die Dienstdefinition des Schalters.
     * Warum: Gebunden war die `final`-Klasse von RcAbTesting statt ihrer Schnittstelle.
     * Erwartet: Die Schnittstelle, weiterhin optional.
     */
    public function testTheServiceBindsTheInterface(): void
    {
        $xml = simplexml_load_file(\dirname(__DIR__, 3) . '/src/Resources/config/services.xml');
        self::assertNotFalse($xml);
        $xml->registerXPathNamespace('s', 'http://symfony.com/schema/dic/services');
        $arguments = $xml->xpath('//s:service[@id="' . RcAbTestingFreeShippingSwitchGate::class . '"]/s:argument');

        self::assertIsArray($arguments);
        self::assertCount(1, $arguments);
        self::assertSame('Ruhrcoder\\RcAbTesting\\Service\\FrontendSwitch\\FrontendSwitchValueResolver', (string) $arguments[0]['id']);
        self::assertSame('null', (string) $arguments[0]['on-invalid']);
    }
}
