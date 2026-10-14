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
 * Nicht im Warenkorb und in der Leiste: Dort gibt es keine Zahlartwahl, und eine Sperre ließe „Zur Kasse" grau werden.
 * Gewählt wird auf der Bestellseite; dort steht die Meldung, bis der Kunde eine Zahlart anklickt.
 */
final class PaymentChoiceValidator implements CartValidatorInterface
{
    private const CART_VIEW_ROUTES = [
        'frontend.checkout.cart.page',
        'frontend.cart.offcanvas',
        'frontend.checkout.info',
    ];

    public function __construct(
        private readonly PaymentPlaceholderFilter $placeholderFilter,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        if (!StorefrontRequestScope::isStorefront($this->requestStack) || $this->isCartView()) {
            return;
        }

        if ($this->placeholderFilter->isPlaceholder($context)) {
            $errors->add(new PaymentChoiceRequiredError());
        }
    }

    private function isCartView(): bool
    {
        foreach ([$this->requestStack->getCurrentRequest(), $this->requestStack->getMainRequest()] as $request) {
            if ($request !== null && \in_array($request->attributes->get('_route'), self::CART_VIEW_ROUTES, true)) {
                return true;
            }
        }

        return false;
    }
}
