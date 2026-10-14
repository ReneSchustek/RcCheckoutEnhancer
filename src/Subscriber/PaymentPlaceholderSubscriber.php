<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Service\PaymentPlaceholderFilter;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Nimmt die Platzhalter-Zahlart aus der Auswahl der Bestellseite und der Seite „Bestellung bearbeiten".
 */
class PaymentPlaceholderSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PaymentPlaceholderFilter $placeholderFilter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'onPageLoaded',
            AccountEditOrderPageLoadedEvent::class => 'onPageLoaded',
        ];
    }

    public function onPageLoaded(CheckoutConfirmPageLoadedEvent|AccountEditOrderPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $page->setPaymentMethods($this->placeholderFilter->forDisplay($page->getPaymentMethods(), $event->getSalesChannelContext()));
    }
}
