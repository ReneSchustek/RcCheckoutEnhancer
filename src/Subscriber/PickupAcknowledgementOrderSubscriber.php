<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcCheckoutEnhancer\Service\PickupAcknowledgementStore;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Schreibt die Bestätigung des Abhol-Hinweises an die Bestellung.
 *
 * An die Bestellung und nicht nur in die Sitzung, weil die Sitzung endet und die Zusage nicht.
 * Der Hinweis sagt zu, dass Verladen und Transport beim Kunden liegen; wer das bestreitet, tut
 * es Wochen später, und dann muss der Nachweis noch da sein.
 *
 * Mitgeschrieben wird der Wortlaut, weil der Text in der Konfiguration steht und sich ändern
 * darf. Ein bloßes Häkchen sagte hinterher nicht mehr, was bestätigt wurde.
 *
 * Ein Fehlschlag kippt die Bestellung nicht. Sie ist an dieser Stelle schon angelegt, und eine
 * Ausnahme ließe den Kunden mit einer Fehlerseite zurück, obwohl sein Kauf durch ist; der
 * Fehlschlag gehört ins Protokoll.
 */
final class PickupAcknowledgementOrderSubscriber implements EventSubscriberInterface
{
    public const CUSTOM_FIELD = 'rc_checkout_pickup_acknowledgement';

    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly PickupAcknowledgementStore $store,
        private readonly EntityRepository $orderRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Rang −500 lässt die übrigen Zuhörer zuerst laufen. Das Zusatzfeld, das dieser nachträgt,
     * braucht keiner von ihnen. Die Abläufe des Flow Builders, die
     * Belege und Mails erzeugen, starten ohnehin erst nach allen Zuhörern.
     *
     * @return array<class-string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [CheckoutOrderPlacedEvent::class => ['onOrderPlaced', -500]];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $acknowledgement = $this->store->stored();
        if ($acknowledgement === null) {
            return;
        }

        $orderId = $event->getOrder()->getId();

        // Der Kontext des Ereignisses, nicht ein frisch gebauter Standardkontext: Der trüge
        // weder Sprache noch Verkaufskanal dieser Bestellung.
        try {
            $this->orderRepository->update([[
                'id' => $orderId,
                'customFields' => [self::CUSTOM_FIELD => $acknowledgement],
            ]], $event->getContext());
        } catch (\Throwable $exception) {
            $this->logger->error('Bestätigung des Abhol-Hinweises konnte nicht an die Bestellung geschrieben werden.', [
                'orderId' => $orderId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        // Nach dem Abschluss beginnt der nächste Warenkorb bei null. Bliebe die Bestätigung
        // stehen, trüge die nächste Bestellung desselben Besuchers sie mit, ohne dass je ein
        // Dialog erschienen wäre.
        $this->store->clear();
    }
}
