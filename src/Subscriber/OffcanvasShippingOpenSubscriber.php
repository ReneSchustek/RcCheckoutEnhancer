<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Sagt der Warenkorb-Leiste, ob die Versandkosten noch offen sind.
 *
 * Ohne Kunden kennt der Shop keine Lieferadresse. Die vorgewählte Versandart rechnet dann oft
 * 0,00 €, weil ihre Preise erst mit Land und Postleitzahl greifen. In der Leiste läse sich das
 * als „kostenlos“; die Vorlage lässt die Zeile in diesem Fall weg, es bleibt „zzgl.
 * Versandkosten“ samt Rechner.
 *
 * Eine Abholung kostet wirklich nichts und gilt deshalb nie als offen. Erreichte
 * Versandkostenfreiheit prüft die Vorlage über `rcFreeShipping`, damit die Schwelle nur an
 * einer Stelle gerechnet wird.
 */
class OffcanvasShippingOpenSubscriber implements EventSubscriberInterface
{
    // Wie im Versandkostenfrei-Hinweis: Rundungsreste zählen nicht als Preis.
    private const CENT_TOLERANCE = 0.005;

    public function __construct(private readonly ConfigService $configService)
    {
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            OffcanvasCartPageLoadedEvent::class => 'onOffcanvasLoaded',
        ];
    }

    public function onOffcanvasLoaded(OffcanvasCartPageLoadedEvent $event): void
    {
        $event->getPage()->addExtension('rcShippingOpen', new ArrayStruct([
            'open' => $this->isOpen($event->getPage()->getCart(), $event->getSalesChannelContext()),
        ]));
    }

    private function isOpen(Cart $cart, SalesChannelContext $context): bool
    {
        // Ein Kunde, auch ein Gast nach der Adresseingabe, hat eine Lieferadresse; der Preis gilt.
        if ($context->getCustomer() !== null) {
            return false;
        }

        $delivery = $cart->getDeliveries()->first();
        if ($delivery === null) {
            return false;
        }

        if ($cart->getDeliveries()->getShippingCosts()->sum()->getTotalPrice() > self::CENT_TOLERANCE) {
            return false;
        }

        $pickupMethodIds = $this->configService->getNonDeliveryMethodIds($context->getSalesChannelId());

        return !\in_array($delivery->getShippingMethod()->getId(), $pickupMethodIds, true);
    }
}
