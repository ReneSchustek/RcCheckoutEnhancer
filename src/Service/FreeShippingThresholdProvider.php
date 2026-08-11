<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Die eine Stelle, an der der Versandkostenfrei-Betrag erfragt wird.
 *
 * Sie existiert, damit die Vertrauensleiste nicht ihre eigene Zahl pflegen muss. Bis
 * 1.4.0 tat sie das: Dort stand „Kostenloser Versand ab 50 €" als Freitext, während die
 * Regel 357 € verlangte und der Indikator gegen 500 € rechnete. Drei Stellen für
 * dieselbe Zahl, alle drei verschieden.
 *
 * Bis zur Zusammenführung lagen Indikator und Vertrauensleiste in zwei Plugins; die
 * Leiste suchte diese Klasse deshalb über `class_exists()`. Diese Brücke ist entfallen —
 * beide stehen jetzt im selben Plugin und sind ganz normal verdrahtet.
 */
class FreeShippingThresholdProvider
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly FreeShippingReachability $reachability,
    ) {
    }

    /**
     * Der Warenwert, ab dem versandkostenfrei geliefert wird — aus der Regel, sonst aus
     * der Einstellung. `null`, wenn beides nichts hergibt.
     */
    public function thresholdFor(SalesChannelContext $context): ?float
    {
        $salesChannelId = $context->getSalesChannelId();

        $reach = $this->reachability->reachableFrom(
            $this->configService->getFreeShippingMethodIds($salesChannelId),
            $context,
        );

        return $reach->threshold ?? $this->configService->getFreeShippingThreshold($salesChannelId);
    }
}
