<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FrontendSwitchResolver;
use Ruhrcoder\RcCheckoutEnhancer\Service\RcAbTestingFreeShippingSwitchGate;
use RuntimeException;

/**
 * Der Ausfallschutz des A/B-Schalters.
 *
 * Der Indikator ist optionaler Zusatz. Ein Fehler im Resolver eines anderen Plugins darf
 * die Warenkorb-Seite nie mit einem Serverfehler abreißen — im Zweifel wird angezeigt.
 * Dieser Zweig war bis 1.5.0 ungeprüft, und ein ungeprüfter Ausfallschutz ist eine
 * Behauptung.
 */
final class RcAbTestingFreeShippingSwitchGateFailureTest extends TestCase
{
    public function testAThrowingResolverDoesNotSuppressTheIndicator(): void
    {
        $resolver = new class () extends FrontendSwitchResolver {
            public function resolve(string $switchKey): ?string
            {
                throw new RuntimeException('Fremd-Plugin kaputt');
            }
        };

        $gate = new RcAbTestingFreeShippingSwitchGate($resolver);

        self::assertFalse($gate->isIndicatorSuppressed());
    }

    public function testAResolverAnsweringOffSuppressesTheIndicator(): void
    {
        // Rückgabetyp bewusst enger als in der Oberklasse (`?string`): Dieser Doppel
        // antwortet immer, und ein `?string`, das nie null wird, ist eine Angabe, die
        // nicht stimmt.
        $resolver = new class () extends FrontendSwitchResolver {
            public function resolve(string $switchKey): string
            {
                return 'off';
            }
        };

        $gate = new RcAbTestingFreeShippingSwitchGate($resolver);

        self::assertTrue($gate->isIndicatorSuppressed());
    }
}
