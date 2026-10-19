<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart;

/**
 * Es gilt noch die Platzhalter-Zahlart: Erst wählen, dann bestellen.
 */
final class PaymentChoiceRequiredError extends BlockingCartError
{
    private const KEY = 'rc-checkout-payment-choice-required';

    public function __construct()
    {
        parent::__construct('Für diese Bestellung ist noch keine Zahlungsart gewählt.');
    }

    protected function key(): string
    {
        return self::KEY;
    }
}
