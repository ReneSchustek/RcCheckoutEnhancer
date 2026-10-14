<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\EstimateFormData;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hängt die Länderliste für „Versand für dieses Produkt berechnen" an die Produktseite. Die Postleitzahl setzt das
 * Skript aus dem, was der Browser sich gemerkt hat.
 */
class ProductShippingEstimateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly EstimateFormData $formData,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageLoadedEvent::class => 'onProductPageLoaded',
        ];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        if (!$this->configService->isProductShippingEstimateEnabled($context->getSalesChannelId())) {
            return;
        }

        $data = $this->formData->forContext($context);
        // Keine Postleitzahl aus dem Konto: Die Produktseite liegt im Seiten-Zwischenspeicher, und eine Anschrift
        // darin sähe der nächste Besucher.
        $data['currentZipCode'] = '';

        $event->getPage()->addExtension('rcProductShippingEstimate', new ArrayStruct($data));
    }
}
