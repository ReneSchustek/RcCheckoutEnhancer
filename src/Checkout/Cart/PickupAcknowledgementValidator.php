<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart;

use Ruhrcoder\RcCheckoutEnhancer\Service\PickupAcknowledgementStore;
use Ruhrcoder\RcCheckoutEnhancer\Service\PickupHintDecider;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Sperrt die Bestellung, solange der Abhol-Hinweis nicht bestätigt ist.
 *
 * Serverseitig, weil eine im Browser deaktivierte Schaltfläche keine Sperre ist: Wer den
 * Bestellaufruf von Hand absetzt, käme daran vorbei. Der Hinweis sagt zu, dass Verladen und
 * Transport beim Kunden liegen, und eine Zusage, die sich mit den Entwicklerwerkzeugen umgehen
 * lässt, ist im Streitfall wertlos.
 *
 * Auf der Warenkorbseite bleibt die Meldung aus. Shopware prüft den Warenkorb bei jedem Aufruf,
 * der ihn lädt, und zeigt die Meldung dort, wo er gerendert wird; dort gibt es aber weder
 * Versandartenauswahl noch Dialog. Der Kunde sähe eine Sperre, die er nicht auflösen kann, zu
 * einer Entscheidung, die er womöglich nie getroffen hat.
 *
 * Die Routen stehen als Ausschlussliste da. Eine Erlaubnisliste fiele still ins Offene, sobald
 * Shopware eine Route umbenennt, und eine unbestätigte Bestellung ginge durch; die
 * Ausschlussliste zeigt die Meldung schlimmstenfalls an einer Stelle zu viel.
 *
 * Die Bedingung kommt aus derselben Quelle wie der Dialog ({@see PickupHintDecider}). Mit zwei
 * Kopien griffe irgendwann die Sperre, ohne dass ein Dialog erscheint, und der Kunde stünde vor
 * einer Bestellung, die sich nicht abschließen lässt, ohne zu erfahren warum.
 */
final class PickupAcknowledgementValidator implements CartValidatorInterface
{
    /**
     * Die Ansichten, in denen der Warenkorb ohne Versandartenauswahl erscheint.
     */
    private const CART_VIEW_ROUTES = [
        'frontend.checkout.cart.page',
        'frontend.cart.offcanvas',
        'frontend.checkout.info',
    ];

    public function __construct(
        private readonly PickupHintDecider $decider,
        private readonly PickupAcknowledgementStore $store,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        // Außerhalb der Storefront fehlt der Dialog, in dem die Abholung bestätigt werden könnte.
        if (!StorefrontRequestScope::isStorefront($this->requestStack)) {
            return;
        }

        if ($this->isCartView()) {
            return;
        }

        $hint = $this->decider->hintFor($cart, $context);
        if ($hint === null) {
            return;
        }

        if ($this->store->isAcknowledged($hint)) {
            return;
        }

        $errors->add(new PickupAcknowledgementRequiredError());
    }

    /**
     * Geprüft werden laufende und Haupt-Anfrage, weil der Warenkorb-Zähler im Kopfbereich je nach
     * Vorlage als Unteranfrage gerendert wird; dann trägt nur eine der beiden die Route.
     */
    private function isCartView(): bool
    {
        foreach ([$this->requestStack->getCurrentRequest(), $this->requestStack->getMainRequest()] as $request) {
            if ($request === null) {
                continue;
            }

            if (\in_array($request->attributes->get('_route'), self::CART_VIEW_ROUTES, true)) {
                return true;
            }
        }

        return false;
    }
}
