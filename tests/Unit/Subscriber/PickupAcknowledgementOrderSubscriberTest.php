<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ruhrcoder\RcCheckoutEnhancer\Service\PickupAcknowledgementStore;
use Ruhrcoder\RcCheckoutEnhancer\Subscriber\PickupAcknowledgementOrderSubscriber;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Der Nachweis der Abhol-Zusage an der Bestellung: der einzige Beleg, wenn ein Kunde Wochen später bestreitet, dass
 * Verladen und Transport bei ihm lagen.
 */
#[CoversClass(PickupAcknowledgementOrderSubscriber::class)]
final class PickupAcknowledgementOrderSubscriberTest extends TestCase
{
    /**
     * Was: Bestellung nach bestätigtem Hinweis.
     * Warum: Wortlaut und Zeitpunkt gehören an die Bestellung, im Kontext des Ereignisses; danach darf die Bestätigung
     *        nicht an der nächsten Bestellung desselben Besuchers hängen.
     * Erwartet: Zusatzfeld mit Wortlaut und Zeitpunkt an genau dieser Bestellung, Sitzung danach leer.
     */
    public function testTheAcknowledgementIsWrittenToTheOrderAndCleared(): void
    {
        $store = $this->store();
        $store->acknowledge('Verladen ist Sache des Käufers.', new \DateTimeImmutable('2026-10-19T10:00:00+02:00'));
        $context = new Context(new SystemSource());

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('update')->with(
            self::callback(static function (array $payload): bool {
                $field = $payload[0]['customFields'][PickupAcknowledgementOrderSubscriber::CUSTOM_FIELD] ?? null;

                return $payload[0]['id'] === 'bestellung-1'
                    && \is_array($field)
                    && $field['hint'] === 'Verladen ist Sache des Käufers.'
                    && str_starts_with($field['at'], '2026-10-19');
            }),
            $context,
        );

        (new PickupAcknowledgementOrderSubscriber($store, $repository, new NullLogger()))->onOrderPlaced($this->event($context));

        self::assertNull($store->stored());
    }

    /**
     * Was: Das Schreiben an die Bestellung schlägt fehl.
     * Warum: Die Bestellung ist schon angelegt; eine Ausnahme ließe den Kunden vor einer Fehlerseite stehen. Der
     *        Fehlschlag gehört ins Protokoll, und die Bestätigung bleibt für einen zweiten Versuch in der Sitzung.
     * Erwartet: keine Ausnahme, ein Protokolleintrag, Bestätigung noch da.
     */
    public function testAFailureIsLoggedAndKeepsTheAcknowledgement(): void
    {
        $store = $this->store();
        $store->acknowledge('Hinweis', new \DateTimeImmutable());

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('update')->willThrowException(new \RuntimeException('Datenbank weg'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::anything(), self::callback(
            static fn (array $context): bool => $context['orderId'] === 'bestellung-1' && $context['message'] === 'Datenbank weg',
        ));

        (new PickupAcknowledgementOrderSubscriber($store, $repository, $logger))->onOrderPlaced($this->event(new Context(new SystemSource())));

        self::assertNotNull($store->stored());
    }

    /**
     * Was: Bestellung ohne Bestätigung (kein Abhol-Hinweis nötig).
     * Erwartet: keine Schreibabfrage.
     */
    public function testWithoutAcknowledgementNothingIsWritten(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('update');

        (new PickupAcknowledgementOrderSubscriber($this->store(), $repository, new NullLogger()))->onOrderPlaced($this->event(new Context(new SystemSource())));
    }

    /**
     * Was: Rang am Bestell-Ereignis.
     * Warum: Die übrigen Zuhörer laufen zuerst; keiner braucht das Zusatzfeld.
     * Erwartet: `onOrderPlaced` mit Rang −500.
     */
    public function testItRunsLateOnOrderPlaced(): void
    {
        self::assertSame(
            [CheckoutOrderPlacedEvent::class => ['onOrderPlaced', -500]],
            PickupAcknowledgementOrderSubscriber::getSubscribedEvents(),
        );
    }

    private function store(): PickupAcknowledgementStore
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);

        return new PickupAcknowledgementStore($stack);
    }

    private function event(Context $context): CheckoutOrderPlacedEvent
    {
        $order = new OrderEntity();
        $order->setId('bestellung-1');

        $salesChannelContext = $this->createStub(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn($context);

        return new CheckoutOrderPlacedEvent($salesChannelContext, $order);
    }
}
