<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Checkout;

use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;

/**
 * Wann statt einer Bestellung die Anfrage gilt: wenn der Anfrageweg eingerichtet ist und unter den
 * verfügbaren Versandarten keine Lieferung mehr steht, nur noch Abholung oder gar nichts.
 *
 * Eine Stelle für die Bestätigungsseite und den Endpunkt der Abhol-Bestätigung. Hinge die Regel nur
 * an der Seite, ließe sie sich mit einem direkten Aufruf des Endpunkts umgehen: Abholung bestätigen
 * und bestellen, obwohl der Shop für diese Ware die Anfrage vorsieht.
 */
class ShippingEnquiryRule
{
    public function __construct(private readonly ConfigService $configService)
    {
    }

    public function applies(ShippingMethodCollection $available, ?string $salesChannelId): bool
    {
        return $this->configService->isShippingEnquiryEnabled($salesChannelId)
            && $this->configService->getShippingEnquiryCategoryId($salesChannelId) !== null
            && $this->hasNoDeliveryOption($available, $salesChannelId);
    }

    private function hasNoDeliveryOption(ShippingMethodCollection $available, ?string $salesChannelId): bool
    {
        if ($available->count() === 0) {
            return true;
        }

        $nonDeliveryIds = $this->configService->getNonDeliveryMethodIds($salesChannelId);
        if ($nonDeliveryIds === []) {
            return false;
        }

        foreach ($available->getIds() as $id) {
            if (!\in_array($id, $nonDeliveryIds, true)) {
                return false;
            }
        }

        return true;
    }
}
