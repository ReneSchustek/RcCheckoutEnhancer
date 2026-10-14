<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart;

use Ruhrcoder\RcCheckoutEnhancer\Service\PhoneNumberRequirement;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Sperrt die Bestellung, solange an der Rechnungsadresse keine Telefonnummer steht.
 *
 * Serverseitig statt als Pflichtfeld, weil das Adressformular genau der Weg ist, den der
 * PayPal-Express-Knopf überspringt. PayPal liefert die Anschrift, der Shop legt die Adresse damit
 * an, und ein Pflichtfeld, das nie angezeigt wird, verlangt nichts. Der Warenkorb dagegen wird auf
 * jedem Weg geprüft.
 *
 * Die Sperre trifft auch reguläre Bestellungen. Shopware erzwingt die Nummer nur beim Anlegen und
 * Bearbeiten einer Adresse, nicht beim Verwenden einer gespeicherten; eine einmal ohne Nummer
 * angelegte Adresse bliebe sonst dauerhaft ohne und käme bei jeder Folgebestellung wieder.
 *
 * Auf der Warenkorbseite bleibt die Meldung aus. Shopware prüft den Warenkorb bei jedem Aufruf,
 * der ihn lädt, und zeigt die Meldung dort, wo er gerendert wird; dort gibt es aber kein Feld für
 * die Nummer, und der Kunde sähe eine Sperre, die er nicht auflösen kann. Dieselbe Grenze zieht
 * {@see PickupAcknowledgementValidator}.
 */
final class PhoneNumberValidator implements CartValidatorInterface
{
    /**
     * Die Ansichten, in denen der Warenkorb ohne das Feld für die Nummer erscheint.
     */
    private const CART_VIEW_ROUTES = [
        'frontend.checkout.cart.page',
        'frontend.cart.offcanvas',
        'frontend.checkout.info',
    ];

    public function __construct(
        private readonly PhoneNumberRequirement $requirement,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        // Außerhalb der Storefront fehlt das Feld, in dem die Nummer nachgereicht werden könnte.
        if (!StorefrontRequestScope::isStorefront($this->requestStack)) {
            return;
        }

        if ($this->isCartView()) {
            return;
        }

        if (!$this->requirement->isMissing($context)) {
            return;
        }

        $errors->add(new PhoneNumberRequiredError());
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
