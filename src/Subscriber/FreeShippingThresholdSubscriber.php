<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Service\FreeShippingHint;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hängt den Versandkostenfrei-Hinweis an Warenkorbseite und Leiste. Ob er erscheint, entscheidet {@see FreeShippingHint}.
 */
final class FreeShippingThresholdSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly FreeShippingHint $hint)
    {
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
        $hint = $this->hint->for($event->getPage()->getCart(), $event->getSalesChannelContext());
        if ($hint === null) {
            return;
        }

        $event->getPage()->addExtension('rcFreeShipping', $hint['status']);

        // Steht das Lieferland noch nicht fest, bleibt der Hinweis sichtbar, sagt aber dazu, wofür er gilt.
        $event->getPage()->addExtension('rcFreeShippingReach', new ArrayStruct([
            'qualify' => $hint['qualify'],
            'countryNames' => $hint['countryNames'],
        ]));
    }
}
