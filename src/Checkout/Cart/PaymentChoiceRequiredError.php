<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * Es gilt noch die Platzhalter-Zahlart: Erst wählen, dann bestellen.
 */
final class PaymentChoiceRequiredError extends Error
{
    private const KEY = 'rc-checkout-payment-choice-required';

    public function __construct()
    {
        parent::__construct('Für diese Bestellung ist noch keine Zahlungsart gewählt.');
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

    /**
     * Nicht dauerhaft: Die Sperre entsteht bei jeder Berechnung neu. Ein dauerhafter Fehler wanderte in die nächste
     * Berechnung und als Hinweis auf die nächste Seite, etwa nach dem Hinzufügen eines Artikels in den Warenkorb.
     */
    public function isPersistent(): bool
    {
        return false;
    }

    public function getParameters(): array
    {
        return [];
    }
}
