<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * Die Bestellung ist gesperrt, solange der Abhol-Hinweis nicht bestätigt ist.
 *
 * `blockOrder()` ist der Kern: Ohne ihn wäre es eine Meldung, die man wegklickt. Mit ihm weist
 * Shopware die Bestellung ab, auch wenn jemand den Bestellaufruf von Hand absetzt — die im
 * Browser gesperrte Schaltfläche allein wäre keine Sperre.
 */
final class PickupAcknowledgementRequiredError extends Error
{
    private const KEY = 'rc-checkout-pickup-acknowledgement-required';

    public function __construct()
    {
        parent::__construct('Der Hinweis zur Selbstabholung muss bestätigt werden, bevor die Bestellung abgeschlossen werden kann.');
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
