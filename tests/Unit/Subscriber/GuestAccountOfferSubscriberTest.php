<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Subscriber;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\GuestAccountOffer;
use Ruhrcoder\RcCheckoutEnhancer\Subscriber\GuestAccountOfferSubscriber;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPage;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Wann die Abschlussseite das Kennwortfeld bekommt: nur für Gäste, nur wenn Shopware sie dort
 * abmeldet und nur wenn die Sitzung die Abmeldung übersteht.
 */
final class GuestAccountOfferSubscriberTest extends TestCase
{
    public function testItListensToTheFinishPage(): void
    {
        self::assertArrayHasKey(CheckoutFinishPageLoadedEvent::class, GuestAccountOfferSubscriber::getSubscribedEvents());
    }

    public function testAGuestWhoIsLoggedOutGetsTheOffer(): void
    {
        [$subscriber, $offer] = $this->subscriber();
        $event = $this->event(guest: true);

        $subscriber->onFinishPage($event);

        self::assertTrue($event->getPage()->hasExtension(GuestAccountOfferSubscriber::EXTENSION));
        self::assertSame(['customerId' => 'kunde-1', 'email' => 'gast@example.test'], $offer->pending(new DateTimeImmutable()));
    }

    /**
     * Was: Ein Kunde mit Konto bestellt.
     * Warum: Er hat schon ein Konto; ein Kennwortfeld wäre verwirrend.
     */
    public function testARegisteredCustomerGetsNothing(): void
    {
        $this->assertNoOffer($this->subscriber(), $this->event(guest: false));
    }

    public function testWhenSwitchedOffNothingHappens(): void
    {
        $this->assertNoOffer($this->subscriber(enabled: false), $this->event(guest: true));
    }

    /**
     * Was: Shopware meldet den Gast nicht ab.
     * Warum: Dann zeigt der Kern sein eigenes Angebot; zwei Formulare wären eines zu viel.
     */
    public function testWithoutTheLogoutTheCoreOfferStays(): void
    {
        $this->assertNoOffer($this->subscriber(logout: false), $this->event(guest: true));
    }

    /**
     * Was: Shopware verwirft beim Abmelden die ganze Sitzung.
     * Warum: Die Vormerkung ginge mit verloren; ein Formular, das nicht funktionieren kann, erscheint nicht.
     */
    public function testWhenTheSessionDiesWithTheLogoutThereIsNoOffer(): void
    {
        $this->assertNoOffer($this->subscriber(invalidateSession: true), $this->event(guest: true));
    }

    /**
     * @param array{GuestAccountOfferSubscriber, GuestAccountOffer} $setup
     */
    private function assertNoOffer(array $setup, CheckoutFinishPageLoadedEvent $event): void
    {
        [$subscriber, $offer] = $setup;

        $subscriber->onFinishPage($event);

        self::assertFalse($event->getPage()->hasExtension(GuestAccountOfferSubscriber::EXTENSION));
        self::assertNull($offer->pending(new DateTimeImmutable()));
    }

    /**
     * @return array{GuestAccountOfferSubscriber, GuestAccountOffer}
     */
    private function subscriber(bool $enabled = true, bool $logout = true, bool $invalidateSession = false): array
    {
        $configService = $this->createMock(ConfigService::class);
        $configService->method('isGuestAccountOfferEnabled')->willReturn($enabled);

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getBool')->willReturnMap([
            ['core.cart.logoutGuestAfterCheckout', 'sc-id', $logout],
            ['core.loginRegistration.invalidateSessionOnLogOut', 'sc-id', $invalidateSession],
        ]);

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);
        $offer = new GuestAccountOffer($stack);

        return [new GuestAccountOfferSubscriber($configService, $systemConfig, $offer), $offer];
    }

    private function event(bool $guest): CheckoutFinishPageLoadedEvent
    {
        $customer = new CustomerEntity();
        $customer->setId('kunde-1');
        $customer->setGuest($guest);
        $customer->setEmail('gast@example.test');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-id');
        $context->method('getCustomer')->willReturn($customer);

        return new CheckoutFinishPageLoadedEvent(new CheckoutFinishPage(), $context, new Request());
    }
}
