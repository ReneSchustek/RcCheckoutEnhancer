<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * Die Bestellung ist gesperrt, solange keine Telefonnummer an der Rechnungsadresse steht.
 *
 * Ohne `blockOrder()` wäre es ein Hinweis, den man überliest. Der Shop verlangt die Nummer zwar im
 * Adressformular, der Weg über den PayPal-Express-Knopf zeigt dieses Formular aber nie, und eine
 * Pflicht, die nur an einer von mehreren Türen hängt, ist keine.
 */
final class PhoneNumberRequiredError extends Error
{
    private const KEY = 'rc-checkout-phone-number-required';

    public function __construct()
    {
        parent::__construct('Für diese Bestellung fehlt eine Telefonnummer.');
    }

    public function getId(): string
    {
        return self::KEY;
    }

    public function getMessageKey(): string
    {
        return self::KEY;
    }

    public function getLevel(): int
    {
        return self::LEVEL_ERROR;
    }

    public function blockOrder(): bool
    {
        return true;
    }

    public function getParameters(): array
    {
        return [];
    }
}
