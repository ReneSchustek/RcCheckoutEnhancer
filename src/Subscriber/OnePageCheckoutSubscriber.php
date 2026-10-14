<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Checkout\CheckoutLayout;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\OnePageSelectionLoader;
use Shopware\Storefront\Page\Checkout\Register\CheckoutRegisterPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hängt der Adressseite Versand, Zahlung und Warenkorb an, wenn der einseitige Checkout
 * eingeschaltet ist oder ein A/B-Test darüber entscheidet.
 *
 * Beim A/B-Test weiß erst die Vorlage, welche Variante der Besucher sieht. Die Angaben liegen
 * deshalb für beide bereit; die geführte Variante zeigt sie nur nicht.
 */
final class OnePageCheckoutSubscriber implements EventSubscriberInterface
{
    public const EXTENSION = 'rcOnePage';

    public function __construct(
        private readonly ConfigService $configService,
        private readonly OnePageSelectionLoader $loader,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [CheckoutRegisterPageLoadedEvent::class => 'onRegisterPage'];
    }

    public function onRegisterPage(CheckoutRegisterPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();

        if (!CheckoutLayout::mayShowOnePage($this->configService->getCheckoutLayout($context->getSalesChannelId()))) {
            return;
        }

        $page = $event->getPage();
        $selection = $this->loader->load($event->getRequest(), $context, $page->getCart());

        // Hat die Vorauswahl umgeschaltet, gehört der neu gerechnete Warenkorb auch in die
        // Seitenleiste; sonst stünden dort die Versandkosten der alten Versandart.
        $page->setCart($selection->getCart());
        $page->addExtension(self::EXTENSION, $selection);
    }
}
