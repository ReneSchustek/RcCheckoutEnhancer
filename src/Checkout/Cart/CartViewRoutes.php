<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Die Ansichten, in denen der Warenkorb ohne Auswahl von Versand- und Zahlart erscheint.
 *
 * Die Bestellsperren dieses Plugins schweigen dort, weil sich die Meldung an dieser Stelle nicht auflösen lässt. Die
 * Routen stehen als Ausschlussliste da: Eine Erlaubnisliste fiele still ins Offene, sobald Shopware eine Route
 * umbenennt, und eine gesperrte Bestellung ginge durch. Die Ausschlussliste zeigt die Meldung schlimmstenfalls an einer
 * Stelle zu viel.
 *
 * Beim Ändern des Warenkorbs (Hinzufügen, Menge, Gutschein) laufen die Sperren mit; ihre Fehler sind nicht dauerhaft
 * (`isPersistent()` ist `false`), deshalb übernimmt Shopware sie weder in die nächste Berechnung noch als Hinweis auf
 * die nächste Seite.
 */
final class CartViewRoutes
{
    private const ROUTES = [
        'frontend.checkout.cart.page',
        'frontend.cart.offcanvas',
        'frontend.checkout.info',
    ];

    /**
     * Seiten, auf denen der Kunde die Zahlart erst noch wählt. Dort ist „Bitte wählen Sie oben eine Zahlungsart" beim
     * Laden ein Vorwurf, bevor er etwas versucht hat; die Meldung kommt erst mit dem Klick auf „Bestellen" (die Sperre
     * greift beim Absenden, und Shopware zeigt sie dann oben auf der Bestätigungsseite).
     */
    private const PAYMENT_CHOICE_ROUTES = [
        'frontend.checkout.confirm.page',
        'frontend.checkout.register.page',
    ];

    public static function isCartView(RequestStack $requestStack): bool
    {
        return self::matches($requestStack, self::ROUTES);
    }

    /**
     * Warenkorb, Leiste und die Seiten mit der Zahlartwahl. Beim Absenden der Bestellung trägt die Hauptanfrage die
     * Bestellroute, dort greift die Sperre.
     */
    public static function isBeforePaymentChoice(RequestStack $requestStack): bool
    {
        $main = $requestStack->getMainRequest();
        if ($main !== null && $main->attributes->get('_route') === 'frontend.checkout.finish.order') {
            return false;
        }

        return self::matches($requestStack, [...self::ROUTES, ...self::PAYMENT_CHOICE_ROUTES]);
    }

    /**
     * @param list<string> $routes
     */
    private static function matches(RequestStack $requestStack, array $routes): bool
    {
        // Beide Anfragen zählen: Der Warenkorb-Zähler im Kopf wird je nach Vorlage als Unteranfrage gerendert, dann
        // trägt nur eine der beiden die Route.
        foreach ([$requestStack->getCurrentRequest(), $requestStack->getMainRequest()] as $request) {
            if ($request !== null && \in_array($request->attributes->get('_route'), $routes, true)) {
                return true;
            }
        }

        return false;
    }
}
