<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * Ein Warenkorb-Fehler, der die Bestellung sperrt, bis der Kunde etwas nachholt. Die Kennung ist zugleich der
 * Textbaustein der Meldung.
 */
abstract class BlockingCartError extends Error
{
    abstract protected function key(): string;

    public function getId(): string
    {
        return $this->key();
    }

    public function getMessageKey(): string
    {
        return $this->key();
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
