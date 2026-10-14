<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Merkt sich, welche Versandart der Kunde selbst gewählt hat.
 *
 * Greift die Standard-Versandart nicht, etwa der Paketdienst bei einem langen Teil, landet
 * Shopware von selbst auf der Versandart, die als einzige nie durchfällt, oft der Abholung. Auf
 * der Bestätigungsseite stünde sie dann angehakt, obwohl niemand sie angeklickt hat.
 *
 * Aus dem Kontext allein geht nicht hervor, ob eine Versandart dort steht, weil der Kunde sie
 * wollte, oder weil sonst nichts übrig blieb. Der Unterschied liegt im Weg dorthin: Ein eigener
 * Klick ist ein `POST /checkout/configure` und trägt die Kennung in seinen Formulardaten,
 * Shopwares Rückfall geschieht beim Aufbau einer Seite. Beide feuern dasselbe Ereignis;
 * festgehalten wird der Unterschied im
 * {@see \Ruhrcoder\RcCheckoutEnhancer\Subscriber\ShippingChoiceSubscriber}.
 *
 * In der Sitzung, weil die Wahl eine Aussage des Menschen vor dem Bildschirm ist und keine
 * Eigenschaft des Warenkorbs, wie beim {@see PickupAcknowledgementStore}. Wer neu anfängt, wählt
 * neu.
 */
final class ShippingChoiceStore
{
    public const SESSION_KEY = 'rc_checkout_enhancer.shipping_choice';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    /**
     * Hält die eigene Wahl des Kunden fest.
     */
    public function remember(string $shippingMethodId): void
    {
        $this->session()?->set(self::SESSION_KEY, $shippingMethodId);
    }

    /**
     * Hat der Kunde genau diese Versandart selbst gewählt?
     *
     * Ohne Sitzung, etwa ohne laufende Anfrage, lautet die Antwort `true`. Das ist die
     * vorsichtige Richtung: Wo sich eine eigene Wahl nicht nachhalten lässt, wird nicht
     * ungefragt umgeschaltet, denn ein stilles Umschalten bemerkt niemand und macht niemand
     * rückgängig.
     */
    public function wasChosen(string $shippingMethodId): bool
    {
        $session = $this->session();
        if ($session === null) {
            return true;
        }

        return $session->get(self::SESSION_KEY) === $shippingMethodId;
    }

    private function session(): ?SessionInterface
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }

        return $request->getSession();
    }
}
