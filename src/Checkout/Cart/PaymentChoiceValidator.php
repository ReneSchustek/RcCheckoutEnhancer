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
 * In Warenkorb und Leiste schweigt die Sperre ({@see CartViewRoutes}); dort gibt es keine Zahlartwahl, und „Bitte
 * wählen Sie oben eine Zahlungsart" führte ins Leere. Auf der Kundendaten- und der Bestätigungsseite schweigt sie beim
 * Laden ebenfalls: Der Kunde wählt dort erst noch. Beim Absenden der Bestellung greift sie, und Shopware zeigt die
 * Meldung dann oben auf der Bestätigungsseite.
 */
final class PaymentChoiceValidator implements CartValidatorInterface
{
    public function __construct(
        private readonly PaymentPlaceholderFilter $placeholderFilter,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        if (!StorefrontRequestScope::isStorefront($this->requestStack) || CartViewRoutes::isBeforePaymentChoice($this->requestStack)) {
            return;
        }

        if ($this->placeholderFilter->isPlaceholder($context)) {
            $errors->add(new PaymentChoiceRequiredError());
        }
    }
}
