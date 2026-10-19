<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart;

/**
 * Die Bestellung ist gesperrt, solange keine Telefonnummer an der Rechnungsadresse steht.
 *
 * Ohne `blockOrder()` wäre es ein Hinweis, den man überliest. Der Shop verlangt die Nummer zwar im
 * Adressformular, der Weg über den PayPal-Express-Knopf zeigt dieses Formular aber nie, und eine
 * Pflicht, die nur an einer von mehreren Türen hängt, ist keine.
 */
final class PhoneNumberRequiredError extends BlockingCartError
{
    private const KEY = 'rc-checkout-phone-number-required';

    public function __construct()
    {
        parent::__construct('Für diese Bestellung fehlt eine Telefonnummer.');
    }

    protected function key(): string
    {
        return self::KEY;
    }
}
