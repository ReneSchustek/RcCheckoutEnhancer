<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingEnquiryStore;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Navigation\NavigationPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Bietet den Anfrageweg an, wenn der Bestellvorgang keine Versandart mehr hergibt.
 *
 * **Warum auf der Bestätigungsseite und nicht im Warenkorb:** Die Speditionstarife hängen
 * an Postleitzahl-Zonen. Vor der Anschrift kann der Shop gar nicht wissen, ob eine
 * Versandart übrig bleibt — dort wäre jede Aussage geraten. Auf der Bestätigungsseite
 * liegt die Anschrift vor, und der Kunde steht unmittelbar vor dem Absenden.
 *
 * **Woran der Zustand erkannt wird:** Shopware lädt für die Bestätigungsseite die
 * verfügbaren Versandarten und rendert die Auswahl gar nicht erst, wenn keine übrig ist —
 * am 2026-08-10 mit 530 kg gemessen. Eine leere Liste ist damit die Antwort des Kerns
 * selbst, kostenlos zu haben und ohne zweite Wahrheit daneben.
 *
 * Ausdrücklich **nicht** über eine eigene Berechnung: `canShipToContextLocation()` würde
 * dieselbe Frage beantworten, aber je verfügbarer Versandart einen ganzen
 * Warenkorb-Durchlauf kosten — auf der Seite, auf der der Kunde am ungeduldigsten ist.
 */
final class ShippingEnquirySubscriber implements EventSubscriberInterface
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
            CheckoutConfirmPageLoadedEvent::class => 'onConfirmPage',
            NavigationPageLoadedEvent::class => 'onNavigationPage',
        ];
    }

    public function onConfirmPage(CheckoutConfirmPageLoadedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();

        if (!$this->configService->isShippingEnquiryEnabled($salesChannelId)) {
            return;
        }

        $categoryId = $this->configService->getShippingEnquiryCategoryId($salesChannelId);
        if ($categoryId === null) {
            return;
        }

        $available = $event->getPage()->getShippingMethods();

        // Zwei Auslöser, und der zweite ist der leisere.
        //
        // **Keine Versandart** ist der offensichtliche Fall: Shopware rendert die Auswahl gar
        // nicht erst, die Sendung geht nirgendwohin.
        //
        // **Nur Abholung** ist der Fall dazwischen. Die Selbstabholung ist oft die einzige
        // Versandart ohne Gewichtsgrenze und bleibt deshalb übrig, wenn die Speditionsleiter
        // endet — nicht, weil jemand sie für schwere Sendungen vorgesehen hätte, sondern weil
        // sie das Letzte ist, was durchfällt. Der Kunde stünde dann vor genau einer
        // Möglichkeit: eine halbe Tonne selbst abholen. Wer das nicht kann, hat ohne diesen
        // Zweig keinen Weg außer dem Abbruch — und das ist der Kunde mit dem größten
        // Warenkorb.
        if (!$this->hasNoDeliveryOption($available, $salesChannelId)) {
            return;
        }

        $pickupOnly = $available->count() > 0;

        $event->getPage()->addExtension('rcShippingEnquiry', new ArrayStruct([
            'hint' => $this->configService->getShippingEnquiryHint($salesChannelId),
            // Der Text muss ein anderer sein: „wir können nicht liefern" stimmt nicht, wenn
            // Abholung möglich ist. Die Vorlage wählt danach den Textbaustein.
            'pickupOnly' => $pickupOnly,
        ]));
    }

    /**
     * Bleibt für diesen Warenkorb nichts übrig, womit tatsächlich geliefert würde?
     *
     * `true` heißt: keine Versandart — oder nur solche, die der Betreiber als „keine
     * Lieferung" eingetragen hat. Ist die Liste leer, bleibt es beim ersten Fall; dann
     * verhält sich das Plugin wie bis 1.9.0.
     */
    private function hasNoDeliveryOption(ShippingMethodCollection $available, ?string $salesChannelId): bool
    {
        if ($available->count() === 0) {
            return true;
        }

        $nonDeliveryIds = $this->configService->getNonDeliveryMethodIds($salesChannelId);
        if ($nonDeliveryIds === []) {
            return false;
        }

        foreach ($available->getIds() as $id) {
            if (!\in_array($id, $nonDeliveryIds, true)) {
                return false;
            }
        }

        return true;
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
     * **Warum überhaupt:** Der Kern füllt die Felder aus der abgesendeten Formulareingabe,
     * nicht aus dem Konto — ein angemeldeter Kunde bekommt ein leeres Formular. Er tippt
     * dann seine Daten neu, ausgerechnet an der Stelle, an der er ohnehin schon aufgehalten
     * wurde. Und was er tippt, muss nicht sein Konto sein: eine andere Mailadresse, ein
     * Zahlendreher in der Telefonnummer, und die Antwort geht ins Leere.
     *
     * Der Anfrageweg beginnt auf der Bestätigungsseite — dort ist der Kunde bekannt. Genau
     * deshalb sitzt er dort und nicht im Warenkorb.
     *
     * **Was nicht bekannt ist, bleibt leer.** Geraten wird nichts, und niemand wird
     * angemeldet, der es nicht ist.
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
