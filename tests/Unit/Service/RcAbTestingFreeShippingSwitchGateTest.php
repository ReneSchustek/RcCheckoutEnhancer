<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\RcAbTestingFreeShippingSwitchGate;

final class RcAbTestingFreeShippingSwitchGateTest extends TestCase
{
    public function testIsNotSuppressedWhenResolverMissing(): void
    {
        // Ohne RcAbTesting (Resolver null) darf der Indikator nie unterdrückt
        // werden — RcCheckout läuft dann unverändert.
        $gate = new RcAbTestingFreeShippingSwitchGate(null);

        self::assertFalse($gate->isIndicatorSuppressed());
    }
}
