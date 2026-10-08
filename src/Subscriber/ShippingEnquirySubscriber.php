<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart\PickupAcknowledgementRequiredError;
use Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart\ShippingEnquiryRequiredError;
use Ruhrcoder\RcCheckoutEnhancer\Checkout\ShippingEnquiryRule;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingEnquiryStore;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Navigation\NavigationPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Bietet den Anfrageweg an, wenn der Bestellvorgang keine Versandart mehr hergibt.
 *
 * Er sitzt auf der Bestätigungsseite und nicht im Warenkorb, weil die Speditionstarife an
 * Postleitzahl-Zonen hängen. Vor der Anschrift weiß der Shop nicht, ob eine Versandart übrig
 * bleibt; auf der Bestätigungsseite liegt sie vor, und der Kunde steht unmittelbar vor dem
 * Absenden.
 *
 * Erkannt wird der Zustand an der Liste, die Shopware für die Bestätigungsseite ohnehin lädt:
 * Ist sie leer, rendert der Kern die Auswahl gar nicht erst. Das ist die Antwort des Kerns
 * selbst, ohne zweite Wahrheit daneben. `canShipToContextLocation()` beantwortete dieselbe
 * Frage, kostete aber je verfügbarer Versandart einen ganzen Warenkorb-Durchlauf, und das auf
 * der Seite, auf der der Kunde am ungeduldigsten ist.
 */
final class ShippingEnquirySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly ShippingEnquiryStore $enquiryStore,
        private readonly ShippingEnquiryRule $enquiryRule,
    ) {
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'onConfirmPage',
            NavigationPageLoadedEvent::class => 'onNavigationPage',
        ];
    }

    public function onConfirmPage(CheckoutConfirmPageLoadedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();
        $available = $event->getPage()->getShippingMethods();

        // Zwei Auslöser. Ohne jede Versandart rendert Shopware die Auswahl gar nicht erst.
        // Der leisere Fall ist „nur Abholung": Die Selbstabholung ist oft die einzige
        // Versandart ohne Gewichtsgrenze und bleibt übrig, wenn die Speditionsleiter endet.
        // Der Kunde stünde dann vor genau einer Möglichkeit, eine halbe Tonne selbst abzuholen,
        // und wer das nicht kann, hätte ohne diesen Zweig nur den Abbruch. Das trifft den
        // Kunden mit dem größten Warenkorb.
        if (!$this->enquiryRule->applies($available, $salesChannelId)) {
            return;
        }

        $pickupOnly = $available->count() > 0;

        if ($pickupOnly) {
            $this->replaceAcknowledgementError($event->getPage()->getCart());
        }

        $event->getPage()->addExtension('rcShippingEnquiry', new ArrayStruct([
            'hint' => $this->configService->getShippingEnquiryHint($salesChannelId),
            // Der Text muss ein anderer sein: „wir können nicht liefern" stimmt nicht, wenn
            // Abholung möglich ist. Die Vorlage wählt danach den Textbaustein.
            'pickupOnly' => $pickupOnly,
        ]));
    }

    /**
     * Tauscht die Abhol-Sperre gegen die Anfrage-Sperre.
     *
     * Eine bloß entfernte Sperre gäbe die Schaltfläche frei, bestellt werden soll hier aber
     * nicht: Bleibt nur eine Nicht-Lieferart übrig, ist keine Lieferung möglich, und die Sache
     * wird besprochen. Stehen lassen geht auch nicht, denn die Abhol-Sperre verlangt die
     * Bestätigung im Dialog, und den gibt es in diesem Zustand nicht. Der Kunde säße vor einer
     * Meldung, die er nicht auflösen kann.
     */
    private function replaceAcknowledgementError(Cart $cart): void
    {
        $errors = $cart->getErrors();

        foreach ($errors as $key => $error) {
            if ($error instanceof PickupAcknowledgementRequiredError) {
                $errors->remove($key);
            }
        }

        $errors->add(new ShippingEnquiryRequiredError());
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
