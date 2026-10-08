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
}
