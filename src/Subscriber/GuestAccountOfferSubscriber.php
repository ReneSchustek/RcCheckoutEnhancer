<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use DateTimeImmutable;
use DateTimeZone;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\GuestAccountOffer;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Bietet einem Gast auf der Abschlussseite an, mit einem Kennwort ein Kundenkonto anzulegen,
 * obwohl Shopware ihn dort abmeldet.
 *
 * Das Ereignis kommt, bevor der Kern abmeldet; hier ist der Gast also noch bekannt und wird für
 * das Angebot vorgemerkt ({@see GuestAccountOffer}).
 *
 * Meldet der Kern nicht ab, zeigt er sein eigenes Angebot, und hier geschieht nichts. Verwirft er
 * beim Abmelden die ganze Sitzung (`invalidateSessionOnLogOut`), ginge die Vormerkung mit verloren;
 * dann wird nichts angeboten, statt ein Formular zu zeigen, das nicht funktionieren kann.
 */
class GuestAccountOfferSubscriber implements EventSubscriberInterface
{
    public const EXTENSION = 'rcGuestAccount';

    public function __construct(
        private readonly ConfigService $configService,
        private readonly SystemConfigService $systemConfig,
        private readonly GuestAccountOffer $offer,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [CheckoutFinishPageLoadedEvent::class => 'onFinishPage'];
    }

    public function onFinishPage(CheckoutFinishPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        $salesChannelId = $context->getSalesChannelId();
        $customer = $context->getCustomer();

        if ($customer === null
            || !$customer->getGuest()
            || !$this->configService->isGuestAccountOfferEnabled($salesChannelId)
            || !$this->systemConfig->getBool('core.cart.logoutGuestAfterCheckout', $salesChannelId)
            || $this->systemConfig->getBool('core.loginRegistration.invalidateSessionOnLogOut', $salesChannelId)) {
            return;
        }

        $this->offer->remember($customer->getId(), $customer->getEmail(), new DateTimeImmutable('now', new DateTimeZone('UTC')));

        $event->getPage()->addExtension(self::EXTENSION, new ArrayStruct(['email' => $customer->getEmail()]));
    }
}
