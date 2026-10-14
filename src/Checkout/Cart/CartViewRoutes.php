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

    public static function isCartView(RequestStack $requestStack): bool
    {
        // Beide Anfragen zählen: Der Warenkorb-Zähler im Kopf wird je nach Vorlage als Unteranfrage gerendert, dann
        // trägt nur eine der beiden die Route.
        foreach ([$requestStack->getCurrentRequest(), $requestStack->getMainRequest()] as $request) {
            if ($request !== null && \in_array($request->attributes->get('_route'), self::ROUTES, true)) {
                return true;
            }
        }

        return false;
    }
}
