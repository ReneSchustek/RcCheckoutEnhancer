<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class CheckoutSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ConfigService $configService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutCartPageLoadedEvent::class => 'onCheckoutPage',
            CheckoutConfirmPageLoadedEvent::class => 'onCheckoutPage',
            CheckoutFinishPageLoadedEvent::class => 'onCheckoutPage',
        ];
    }

    public function onCheckoutPage(CheckoutCartPageLoadedEvent|CheckoutConfirmPageLoadedEvent|CheckoutFinishPageLoadedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannel()->getId();

        $step = match (true) {
            $event instanceof CheckoutCartPageLoadedEvent => 1,
            $event instanceof CheckoutConfirmPageLoadedEvent => 3,
            $event instanceof CheckoutFinishPageLoadedEvent => 4,
            default => 1,
        };

        $labels = $this->configService->getProgressStepLabels($salesChannelId);

        $event->getPage()->addExtension('rcCheckoutEnhancer', new ArrayEntity([
            'currentStep' => $step,
            'totalSteps' => 4,
            'stepLabels' => $labels,
            'progressBarEnabled' => $this->configService->isProgressBarEnabled($salesChannelId),
            'trustBadgesEnabled' => $this->configService->isTrustBadgesEnabled($salesChannelId),
            'trustBadges' => $this->configService->getTrustBadges($salesChannelId),
            'miniCartEnabled' => $this->configService->isMiniCartEnabled($salesChannelId),
            'orderSummaryEnabled' => $this->configService->isOrderSummaryEnabled($salesChannelId),
            'deliveryTimeEnabled' => $this->configService->isDeliveryTimeEnabled($salesChannelId),
            'estimatedDeliveryTime' => $this->configService->getEstimatedDeliveryTime($salesChannelId),
        ]));
    }
}
