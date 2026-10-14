<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Service\CartAccessoryService;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hängt „Dazu passt" und „Haben Sie an alles gedacht?" an Warenkorbseite und Warenkorb-Leiste (`rcCartAccessories`).
 * Die Kasse bekommt sie nicht: Wer die Adresse eingibt, hat sich entschieden.
 */
class CartAccessorySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly CartAccessoryService $accessoryService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutCartPageLoadedEvent::class => 'onCartPageLoaded',
            OffcanvasCartPageLoadedEvent::class => 'onCartPageLoaded',
        ];
    }

    public function onCartPageLoaded(CheckoutCartPageLoadedEvent|OffcanvasCartPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        if (!$this->configService->isCartAccessoriesEnabled($context->getSalesChannelId())) {
            return;
        }

        $accessories = $this->accessoryService->load($event->getPage()->getCart(), $context);
        if ($accessories !== null) {
            $event->getPage()->addExtension('rcCartAccessories', new ArrayStruct($accessories));
        }
    }
}
