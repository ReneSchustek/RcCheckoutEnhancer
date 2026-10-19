<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingEnquiryStore;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Navigation\NavigationPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Der zweite Teil des Anfragewegs: Auf der Seite mit dem Kontaktformular stehen der übernommene Warenkorb und die Daten
 * des Kunden bereit. Den ersten Teil, den Hinweis auf der Bestätigungsseite, hängt {@see ShippingEnquirySubscriber} an.
 */
final class ShippingEnquiryContactSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly ShippingEnquiryStore $enquiryStore,
    ) {
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            NavigationPageLoadedEvent::class => 'onNavigationPage',
        ];
    }

    /**
     * Legt die übernommene Zusammenfassung an die Seite mit dem Kontaktformular.
     *
     * Beim Lesen wird sie vergessen: Sie gilt für genau einen Weg vom Bestellvorgang zum
     * Formular. Bliebe sie stehen, fände der Kunde sie beim nächsten Besuch der
     * Kontaktseite wieder vor — mit einem Warenkorb, den es womöglich nicht mehr gibt.
     */
    public function onNavigationPage(NavigationPageLoadedEvent $event): void
    {
        $summary = $this->enquiryStore->take();
        if ($summary === null) {
            return;
        }

        $context = $event->getSalesChannelContext();

        $event->getPage()->addExtension('rcShippingEnquiry', new ArrayStruct([
            'summary' => $summary,
            'intro' => $this->configService->getShippingEnquiryIntro($context->getSalesChannelId()),
            'customer' => $this->customerOf($context),
        ]));
    }

    /**
     * Die Daten des Kunden, mit denen das Kontaktformular vorbelegt wird.
     *
     * Der Kern füllt die Felder aus der abgesendeten Formulareingabe, nicht aus dem Konto; ein
     * angemeldeter Kunde bekäme ein leeres Formular und tippte seine Daten ausgerechnet dort neu,
     * wo er ohnehin schon aufgehalten wurde. Was er tippt, muss nicht sein Konto sein: eine
     * andere Mailadresse oder ein Zahlendreher in der Telefonnummer, und die Antwort geht ins
     * Leere. Auf der Bestätigungsseite, wo der Anfrageweg beginnt, ist der Kunde bekannt.
     *
     * Was nicht bekannt ist, bleibt leer. Geraten wird nichts, und niemand wird angemeldet, der
     * es nicht ist.
     *
     * @return array<string, string>
     */
    private function customerOf(SalesChannelContext $context): array
    {
        $customer = $context->getCustomer();
        if ($customer === null) {
            return [];
        }

        $address = $customer->getActiveBillingAddress() ?? $customer->getDefaultBillingAddress();

        return array_filter([
            'salutationId' => $customer->getSalutationId(),
            'firstName' => $customer->getFirstName(),
            'lastName' => $customer->getLastName(),
            'email' => $customer->getEmail(),
            'phone' => $address?->getPhoneNumber(),
        ], static fn (?string $value): bool => $value !== null && trim($value) !== '');
    }
}
