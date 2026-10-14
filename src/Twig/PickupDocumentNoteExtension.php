<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Twig;

use Ruhrcoder\RcCheckoutEnhancer\Service\PickupDocumentNote;
use Shopware\Core\Checkout\Order\OrderEntity;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Reicht die Entscheidung über den Abhol-Hinweis an die Beleg-Vorlagen durch.
 *
 * Die Vorlage fragt, der Server entscheidet — dasselbe Vorgehen wie beim Hinweis im
 * Bestellvorgang. Eine Vorlage, die selbst nachrechnet, ob eine Bestellung eine Abholung ist,
 * wäre eine zweite Wahrheit, die irgendwann von der ersten abweicht.
 *
 *   {% set rcPickup = rc_pickup_document_note(order) %}
 *   {% if rcPickup %} … {% if rcPickup.confirmedAt %} … {% endif %} … {% endif %}
 */
final class PickupDocumentNoteExtension extends AbstractExtension
{
    public function __construct(private readonly PickupDocumentNote $note)
    {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('rc_pickup_document_note', $this->forOrder(...)),
        ];
    }

    /**
     * @return array{confirmedAt: string|null}|null
     */
    public function forOrder(?OrderEntity $order): ?array
    {
        if ($order === null) {
            return null;
        }

        return $this->note->forOrder($order);
    }
}
