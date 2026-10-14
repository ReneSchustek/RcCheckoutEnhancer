<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingChoiceStore;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Event\SalesChannelContextSwitchEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Hält fest, dass der Kunde die Versandart selbst gewählt hat.
 *
 * Das Ereignis allein trennt nicht. Shopwares eigener Rückfall auf eine andere Versandart läuft
 * über `StorefrontCartFacade::updateSalesChannelContext()` und denselben Umschaltweg wie ein
 * Klick des Kunden; das Ereignis feuert in beiden Fällen. Wer nur darauf hört, trägt den
 * Rückfall als Wunsch des Kunden ein, und schon das Betreten der Bestätigungsseite ließe die
 * Abholung als „selbst gewählt" in der Sitzung stehen.
 *
 * Die Trennlinie liegt in der Anfrage. Ein Klick des Kunden ist ein `POST /checkout/configure`,
 * Route `frontend.checkout.configure`, mit der Kennung der Versandart in den Formulardaten. Der
 * Rückfall passiert beim Aufbau irgendeiner Seite. Vermerkt wird deshalb nur, was in einer
 * solchen Anfrage und in deren Formulardaten steht.
 *
 * Vermerkt wird jede Versandart, nicht nur die Abholung, weil der Vermerk auch wieder
 * verschwinden muss. Schaltet der Kunde von der Abholung zurück auf eine Lieferung,
 * überschreibt dieser Aufruf den alten Wert; sonst gälte die Abholung für den Rest der Sitzung
 * als gewollt.
 *
 * Die eigene Umschaltung des Plugins läuft nie über `frontend.checkout.configure` und wird
 * deshalb nicht vermerkt: Sie ist Automatik, keine Wahl.
 */
final class ShippingChoiceSubscriber implements EventSubscriberInterface
{
    /**
     * Der Weg, den ein Klick in der Versandart-Auswahl nimmt; in der Storefront der einzige.
     */
    private const CHOICE_ROUTE = 'frontend.checkout.configure';

    public function __construct(
        private readonly ShippingChoiceStore $store,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            SalesChannelContextSwitchEvent::class => 'onContextSwitch',
        ];
    }

    public function onContextSwitch(SalesChannelContextSwitchEvent $event): void
    {
        // Derselbe Aufruf trägt je nach Formular auch nur Zahlungsart oder Anschrift. Dann ist
        // hier nichts zu tun, und der bisherige Vermerk darf nicht verloren gehen; sonst verlöre
        // ein Kunde seine Abholung, weil er die Zahlungsart gewechselt hat.
        $shippingMethodId = $event->getRequestDataBag()->get(SalesChannelContextService::SHIPPING_METHOD_ID);

        if (!\is_string($shippingMethodId) || $shippingMethodId === '') {
            return;
        }

        if (!$this->isCustomerClick($shippingMethodId)) {
            return;
        }

        $this->store->remember($shippingMethodId);
    }

    /**
     * Kommt diese Umschaltung aus der Auswahl auf der Seite oder aus Shopwares Automatik?
     *
     * Beides feuert dasselbe Ereignis; unterschiedlich ist nur die Anfrage, in der es geschieht.
     * Die Formulardaten werden zusätzlich verglichen, weil in derselben Anfrage ein zweites Mal
     * umgeschaltet werden kann, und dann steht im Ereignis eine Kennung, die niemand
     * abgeschickt hat.
     */
    private function isCustomerClick(string $shippingMethodId): bool
    {
        $request = $this->requestStack->getMainRequest();

        if ($request === null || $request->attributes->get('_route') !== self::CHOICE_ROUTE) {
            return false;
        }

        return $request->request->get(SalesChannelContextService::SHIPPING_METHOD_ID) === $shippingMethodId;
    }
}
