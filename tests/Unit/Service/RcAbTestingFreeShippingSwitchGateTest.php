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
     * Warum: An die `final`-Klasse von RcAbTesting gebunden, ließe sich der Schalter weder
     *        ersetzen noch im Test doppeln; die Schnittstelle erlaubt beides.
     * Erwartet: Die Schnittstelle, weiterhin optional, dazu der Logger.
     */
    public function testTheServiceBindsTheInterface(): void
    {
        $xml = simplexml_load_file(\dirname(__DIR__, 3) . '/src/Resources/config/services.xml');
        self::assertNotFalse($xml);
        $xml->registerXPathNamespace('s', 'http://symfony.com/schema/dic/services');
        $arguments = $xml->xpath('//s:service[@id="' . RcAbTestingFreeShippingSwitchGate::class . '"]/s:argument');

        self::assertIsArray($arguments);
        self::assertCount(2, $arguments);
        self::assertSame('Ruhrcoder\\RcAbTesting\\Service\\FrontendSwitch\\FrontendSwitchValueResolver', (string) $arguments[0]['id']);
        self::assertSame('null', (string) $arguments[0]['on-invalid']);
        // Der zweite ist der Logger: Ein Fehler im Schalter wird protokolliert.
        self::assertSame('logger', (string) $arguments[1]['id']);
    }
}
