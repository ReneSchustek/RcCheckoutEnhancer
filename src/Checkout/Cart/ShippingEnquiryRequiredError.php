<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * Für diesen Warenkorb gibt es keinen Weg durch die Kasse — nur eine Anfrage.
 *
 * Neben der Abhol-Sperre steht diese Meldung, weil beide Verschiedenes sagen. Die Abhol-Sperre
 * verlangt die Bestätigung eines Dialogs, den der Kunde öffnen kann. Bleibt nur eine
 * Nicht-Lieferart übrig, gibt es diesen Dialog nicht, und „bitte bestätigen" ginge ins Leere.
 *
 * Gesperrt wird trotzdem: Bleibt nur die Abholung, ist keine Lieferung möglich, und die Sache wird
 * besprochen statt bestellt. Der Anfrageweg daneben trägt den Satz und die Schaltfläche zum
 * Kontaktformular; diese Meldung sagt nur, dass es hier nicht weitergeht.
 */
final class ShippingEnquiryRequiredError extends Error
{
    private const KEY = 'rc-checkout-shipping-enquiry-required';

    public function __construct()
    {
        parent::__construct('Für diesen Warenkorb ist keine Bestellung möglich, sondern nur eine Anfrage.');
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
