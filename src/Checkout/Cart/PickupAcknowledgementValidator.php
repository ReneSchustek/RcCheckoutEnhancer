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

        if (CartViewRoutes::isCartView($this->requestStack)) {
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
}
