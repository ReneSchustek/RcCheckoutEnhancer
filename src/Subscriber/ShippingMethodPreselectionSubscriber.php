<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingMethodPreselectionApplier;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingPlaceholderFilter;
use Shopware\Core\Checkout\Cart\Address\Error\AddressValidationError;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPage;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPage;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Wählt auf Warenkorbseite, Warenkorb-Leiste und Bestätigungsseite eine Versandart vor, wenn die
 * eingestellte für diesen Warenkorb nicht verfügbar ist oder eine nie angeklickte Abholung im
 * Kontext steht. Entschieden und umgeschaltet wird im {@see ShippingMethodPreselectionApplier};
 * die Liste hat jede dieser Seiten schon geladen.
 */
final class ShippingMethodPreselectionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ShippingMethodPreselectionApplier $applier,
        private readonly ShippingPlaceholderFilter $placeholderFilter,
    ) {
    }

    /**
     * Rang 100: Die Vorauswahl läuft vor den übrigen Zuhörern dieser Seiten.
     *
     * Der Abhol-Hinweis entscheidet an der Versandart im Kontext, ob er den Dialog anhängt.
     * Läuft er vor der Vorauswahl, sieht er die Abholung, die Shopware zurückgelassen hat, und
     * hängt den Dialog an eine Versandart, die gleich abgewählt wird. Ohne Rang entschiede die
     * Reihenfolge in der services.xml, und die kippt beim nächsten Umsortieren.
     *
     * @return array<class-string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => ['onConfirmPage', 100],
            CheckoutCartPageLoadedEvent::class => ['onCartPage', 100],
            OffcanvasCartPageLoadedEvent::class => ['onOffcanvasCartPage', 100],
        ];
    }

    public function onConfirmPage(CheckoutConfirmPageLoadedEvent $event): void
    {
        $this->preselectFor($event->getPage(), $event->getSalesChannelContext());
    }

    public function onCartPage(CheckoutCartPageLoadedEvent $event): void
    {
        $this->preselectFor($event->getPage(), $event->getSalesChannelContext());
    }

    public function onOffcanvasCartPage(OffcanvasCartPageLoadedEvent $event): void
    {
        $this->preselectFor($event->getPage(), $event->getSalesChannelContext());
    }

    private function preselectFor(
        CheckoutConfirmPage|CheckoutCartPage|OffcanvasCartPage $page,
        SalesChannelContext $context,
    ): void {
        $isConfirmPage = $page instanceof CheckoutConfirmPage;
        $cart = $this->applier->apply($page->getShippingMethods(), $context, $isConfirmPage);

        if ($cart !== null) {
            // Die Adressprüfung hängt der Kern erst nach der Berechnung an den Seiten-Warenkorb, eine
            // Neuberechnung kennt sie nicht. Ohne Übernahme wäre der Bestellknopf trotz ungültiger
            // Adresse frei, denn beim Absenden prüft der Kern die Adresse nicht noch einmal.
            if ($isConfirmPage) {
                $cart->addErrors(...$page->getCart()->getErrors()->filterInstance(AddressValidationError::class));
            }

            $page->setCart($cart);
        }

        $page->setShippingMethods($this->placeholderFilter->forDisplay($page->getShippingMethods(), $context));
    }
}
