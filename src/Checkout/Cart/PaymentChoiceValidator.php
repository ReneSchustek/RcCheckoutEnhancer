<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart;

use Ruhrcoder\RcCheckoutEnhancer\Service\PaymentPlaceholderFilter;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Sperrt die Bestellung, solange die Platzhalter-Zahlart gilt.
 *
 * Die Meldung entsteht nur dort, wo eine Zahlart gewählt oder die Bestellung abgeschickt wird. Jede andere Berechnung
 * des Warenkorbs, etwa beim Hinzufügen eines Artikels, gäbe sie als Hinweis an die nächste Seite weiter, und im
 * Warenkorb stünde „Bitte wählen Sie oben eine Zahlungsart" ohne Auswahl darüber.
 */
final class PaymentChoiceValidator implements CartValidatorInterface
{
    // Bestellseite, Absenden der Bestellung und die Auswahl der einseitigen Kasse.
    private const ORDER_ROUTES = [
        'frontend.checkout.confirm.page',
        'frontend.checkout.finish.order',
        'frontend.rc-checkout.one-page.selection',
    ];

    public function __construct(
        private readonly PaymentPlaceholderFilter $placeholderFilter,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        if (!StorefrontRequestScope::isStorefront($this->requestStack) || !$this->isOrderStep()) {
            return;
        }

        if ($this->placeholderFilter->isPlaceholder($context)) {
            $errors->add(new PaymentChoiceRequiredError());
        }
    }

    private function isOrderStep(): bool
    {
        // Beide Anfragen zählen: Läuft die Berechnung in einer Unteranfrage, trägt nur die Hauptanfrage die Route.
        foreach ([$this->requestStack->getCurrentRequest(), $this->requestStack->getMainRequest()] as $request) {
            if ($request !== null && \in_array($request->attributes->get('_route'), self::ORDER_ROUTES, true)) {
                return true;
            }
        }

        return false;
    }
}
