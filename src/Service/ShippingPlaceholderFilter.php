<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Ruhrcoder\RcCheckoutEnhancer\Checkout\ShippingMethodAvailability;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Nimmt die Platzhalter-Versandart aus der angezeigten Liste, solange eine Lieferart da ist.
 *
 * Der Platzhalter bleibt nie neben einer Lieferart stehen; die Vorauswahl schaltet von ihm weg.
 * Stünde er trotzdem in der Liste, könnte ihn der Kunde anklicken und sähe seine Wahl sofort
 * zurückspringen. Gefiltert wird erst nach der Vorauswahl, die ihn auf der vollen Liste finden
 * muss, wenn er gebraucht wird.
 */
final class ShippingPlaceholderFilter
{
    public function __construct(private readonly ConfigService $configService)
    {
    }

    public function forDisplay(ShippingMethodCollection $listed, SalesChannelContext $context): ShippingMethodCollection
    {
        $salesChannelId = $context->getSalesChannelId();
        $placeholderId = $this->configService->getShippingPlaceholderMethodId($salesChannelId);

        // Steht der Platzhalter noch im Kontext, weil die Vorauswahl aus ist, bleibt er sichtbar.
        // Sonst wäre in der Liste nichts angehakt.
        if ($placeholderId === null
            || !$listed->has($placeholderId)
            || $context->getShippingMethod()->getId() === $placeholderId) {
            return $listed;
        }

        $nonDeliveryIds = $this->configService->getNonDeliveryMethodIds($salesChannelId);

        foreach ($listed as $method) {
            $isDelivery = $method->getId() !== $placeholderId
                && !\in_array($method->getId(), $nonDeliveryIds, true);

            if ($isDelivery && ShippingMethodAvailability::isAvailable($method, $context)) {
                return $listed->filter(static fn (ShippingMethodEntity $candidate): bool => $candidate->getId() !== $placeholderId);
            }
        }

        return $listed;
    }
}
