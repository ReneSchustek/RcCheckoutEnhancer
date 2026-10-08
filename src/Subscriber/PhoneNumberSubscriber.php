<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Service\PhoneNumberRequirement;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Stellt das Feld für die Telefonnummer auf die Bestätigungsseite, wenn sie fehlt.
 *
 * Auf der Bestätigungsseite und nicht auf einer eigenen Zwischenseite, weil der Kunde vom
 * PayPal-Express-Knopf genau hier herauskommt und hier auch die Sperre steht, die ihn aufhält.
 * Eine Zwischenseite wäre ein zusätzlicher Schritt an der Stelle, an der ein Bestellvorgang am
 * teuersten abbricht.
 *
 * Die Bedingung ist dieselbe wie die der Sperre und kommt deshalb aus derselben Quelle
 * ({@see PhoneNumberRequirement}); eine Abfrage in Twig wäre die zweite Wahrheit daneben.
 */
final class PhoneNumberSubscriber implements EventSubscriberInterface
{
    public const EXTENSION_NAME = 'rcPhoneNumber';

    public function __construct(private readonly PhoneNumberRequirement $requirement)
    {
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'onConfirmPage',
        ];
    }

    public function onConfirmPage(CheckoutConfirmPageLoadedEvent $event): void
    {
        if (!$this->requirement->isMissing($event->getSalesChannelContext())) {
            return;
        }

        $event->getPage()->addExtension(self::EXTENSION_NAME, new ArrayStruct(['missing' => true]));
    }
}
