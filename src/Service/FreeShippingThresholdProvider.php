<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Die eine Stelle, an der der Versandkostenfrei-Betrag erfragt wird.
 *
 * Die Vertrauensleiste fragt hier, damit sie keine eigene Zahl pflegt. Ein Freitext
 * „Kostenloser Versand ab 50 €" neben einer Regel, die 357 € verlangt, läuft früher oder
 * später auseinander, und der Kunde liest dann zwei Beträge für dieselbe Sache.
 *
 * Nicht `final`, weil die Tests des {@see \Ruhrcoder\RcCheckoutEnhancer\Subscriber\CheckoutSubscriber}
 * ihn als Test-Double ersetzen.
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
