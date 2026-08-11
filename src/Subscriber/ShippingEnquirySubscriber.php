<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingEnquiryStore;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Framework\Struct\ArrayStruct;
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

        $event->getPage()->addExtension('rcShippingEnquiry', new ArrayStruct([
            'summary' => $summary,
        ]));
    }
}
