<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Nimmt die Platzhalter-Zahlart aus jeder Auswahl.
 *
 * Sie ist nur die Vorgabe des Verkaufskanals, damit keine echte Zahlart vorausgewählt ist. Wäre Vorkasse
 * vorgewählt, stünde ihr Rabatt schon im Warenkorb, und beim Wechsel der Zahlart stiege der Preis. Steht der
 * Platzhalter im Kontext, ist in der Liste nichts angehakt; das ist gewollt.
 */
final class PaymentPlaceholderFilter
{
    public function __construct(private readonly ConfigService $configService)
    {
    }

    public function forDisplay(PaymentMethodCollection $listed, SalesChannelContext $context): PaymentMethodCollection
    {
        return self::without($listed, $this->configService->getPaymentPlaceholderMethodId($context->getSalesChannelId()));
    }

    public function isPlaceholder(SalesChannelContext $context): bool
    {
        $placeholderId = $this->configService->getPaymentPlaceholderMethodId($context->getSalesChannelId());

        return $placeholderId !== null && $context->getPaymentMethod()->getId() === $placeholderId;
    }

    /**
     * Ohne den Platzhalter; bliebe nichts übrig, die Liste unverändert (dann fehlt eine echte Zahlart, und eine leere
     * Liste verbärge den Fehler).
     */
    public static function without(PaymentMethodCollection $listed, ?string $placeholderId): PaymentMethodCollection
    {
        if ($placeholderId === null || !$listed->has($placeholderId)) {
            return $listed;
        }

        $rest = $listed->filter(static fn (PaymentMethodEntity $method): bool => $method->getId() !== $placeholderId);

        return $rest->count() > 0 ? $rest : $listed;
    }
}
