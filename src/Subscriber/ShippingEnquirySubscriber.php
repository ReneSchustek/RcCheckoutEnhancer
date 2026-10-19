<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart\PickupAcknowledgementRequiredError;
use Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart\ShippingEnquiryRequiredError;
use Ruhrcoder\RcCheckoutEnhancer\Checkout\ShippingEnquiryRule;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Bietet den Anfrageweg an, wenn der Bestellvorgang keine Versandart mehr hergibt. Die übernommene Zusammenfassung
 * auf der Kontaktseite legt {@see ShippingEnquiryContactSubscriber} an.
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
}
