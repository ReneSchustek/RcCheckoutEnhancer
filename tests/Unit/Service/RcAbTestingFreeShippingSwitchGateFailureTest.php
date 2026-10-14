<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FrontendSwitchValueResolver;
use Ruhrcoder\RcCheckoutEnhancer\Service\RcAbTestingFreeShippingSwitchGate;
use RuntimeException;

/**
 * Der Ausfallschutz des A/B-Schalters.
 *
 * Der Indikator ist optionaler Zusatz. Ein Fehler im Resolver eines anderen Plugins darf
 * die Warenkorb-Seite nie mit einem Serverfehler abreißen; im Zweifel wird angezeigt.
 * Ein ungeprüfter Ausfallschutz wäre nur eine Behauptung.
 */
final class RcAbTestingFreeShippingSwitchGateFailureTest extends TestCase
{
    public function testAThrowingResolverDoesNotSuppressTheIndicator(): void
    {
        $resolver = new class () implements FrontendSwitchValueResolver {
            public function resolve(string $switchKey): ?string
            {
                throw new RuntimeException('Fremd-Plugin kaputt');
            }

            public function all(): array
            {
                return [];
            }
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::anything(),
            self::callback(static fn (array $context): bool => $context['exception'] === RuntimeException::class),
        );

        $gate = new RcAbTestingFreeShippingSwitchGate($resolver, $logger);

        // Der Ausfall bleibt sichtbar: protokolliert, aber ohne Serverfehler.
        self::assertFalse($gate->isIndicatorSuppressed());
    }

    public function testAResolverAnsweringOffSuppressesTheIndicator(): void
    {
        // Rückgabetyp bewusst enger als in der Schnittstelle (`?string`): Dieser Doppel
        // antwortet immer, und ein `?string`, das nie null wird, ist eine Angabe, die
        // nicht stimmt.
        $resolver = new class () implements FrontendSwitchValueResolver {
            public function resolve(string $switchKey): string
            {
                return 'off';
            }

            public function all(): array
            {
                return ['free_shipping_indicator' => 'off'];
            }
        };

        $gate = new RcAbTestingFreeShippingSwitchGate($resolver);

        self::assertTrue($gate->isIndicatorSuppressed());
    }
}
