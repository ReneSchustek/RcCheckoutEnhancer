<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreeShippingReach;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreeShippingReachability;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreeShippingService;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreeShippingStatus;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreeShippingSwitchGate;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingEstimateService;
use Ruhrcoder\RcCheckoutEnhancer\Subscriber\FreeShippingThresholdSubscriber;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPage;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPage;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

final class FreeShippingThresholdSubscriberTest extends TestCase
{
    public function testGetSubscribedEventsIncludesBothCartEvents(): void
    {
        $events = FreeShippingThresholdSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(CheckoutCartPageLoadedEvent::class, $events);
        self::assertArrayHasKey(OffcanvasCartPageLoadedEvent::class, $events);
    }

    public function testOnCartPageDoesNothingWhenDisabled(): void
    {
        $service = $this->createMock(FreeShippingService::class);
        $service->expects($this->never())->method('calculate');

        $subscriber = new FreeShippingThresholdSubscriber(
            $this->configService(enabled: false),
            $service,
            $this->reachability(),
            $this->estimateService(),
        );
        $event = $this->createCartEvent();

        $subscriber->onCartPageLoaded($event);

        self::assertFalse($event->getPage()->hasExtension('rcFreeShipping'));
    }

    public function testOnCartPageDoesNothingWhenThresholdZero(): void
    {
        $service = $this->createMock(FreeShippingService::class);
        $service->expects($this->never())->method('calculate');

        $subscriber = new FreeShippingThresholdSubscriber(
            $this->configService(threshold: 0.0),
            $service,
            $this->reachability(),
            $this->estimateService(),
        );
        $event = $this->createCartEvent();

        $subscriber->onCartPageLoaded($event);

        self::assertFalse($event->getPage()->hasExtension('rcFreeShipping'));
    }

    /**
     * Der Fall, für den es die zweite Prüfung gibt.
     *
     * Bis 1.5.3 leitete der Hinweis seine Zusage allein aus den Verfügbarkeits-Regeln ab. Die
     * Gewichtsgrenze steht aber in den Preisbändern: Oberhalb des obersten Bands bleibt eine
     * Versandart *verfügbar* und scheitert erst am fehlenden Preis. Der Shop versprach dort
     * kostenlosen Versand für einen Warenkorb, den er gar nicht ausliefert — am 2026-08-10 auf
     * live-clone mit 530 kg gemessen.
     */
    public function testOnCartPageStaysSilentWhenTheCartCannotBeShipped(): void
    {
        $service = $this->createMock(FreeShippingService::class);
        $service->expects($this->never())->method('calculate');

        $subscriber = new FreeShippingThresholdSubscriber(
            $this->configService(),
            $service,
            $this->reachability(),
            $this->estimateService(false),
        );
        $event = $this->createCartEvent();

        $subscriber->onCartPageLoaded($event);

        self::assertFalse(
            $event->getPage()->hasExtension('rcFreeShipping'),
            'Ohne lieferbare Versandart darf keine Zusage an die Seite gehen.',
        );
    }

    public function testOnCartPageAddsExtensionWhenEnabled(): void
    {
        $expectedStatus = new FreeShippingStatus(50.0, 30.0, false, 'EUR');

        $service = $this->createMock(FreeShippingService::class);
        $service->expects($this->once())
            ->method('calculate')
            ->with($this->isInstanceOf(Cart::class), $this->isInstanceOf(SalesChannelContext::class), 50.0)
            ->willReturn($expectedStatus);

        $subscriber = new FreeShippingThresholdSubscriber(
            $this->configService(),
            $service,
            $this->reachability(),
            $this->estimateService(),
        );
        $event = $this->createCartEvent();

        $subscriber->onCartPageLoaded($event);

        self::assertTrue($event->getPage()->hasExtension('rcFreeShipping'));
        self::assertSame($expectedStatus, $event->getPage()->getExtension('rcFreeShipping'));
    }

    public function testSuppressesIndicatorWhenExperimentSwitchesItOff(): void
    {
        $service = $this->createMock(FreeShippingService::class);
        $service->expects($this->never())->method('calculate');

        $gate = $this->createMock(FreeShippingSwitchGate::class);
        $gate->method('isIndicatorSuppressed')->willReturn(true);

        $subscriber = new FreeShippingThresholdSubscriber(
            $this->configService(),
            $service,
            $this->reachability(),
            $this->estimateService(),
            $gate,
        );
        $event = $this->createCartEvent();

        $subscriber->onCartPageLoaded($event);

        self::assertFalse($event->getPage()->hasExtension('rcFreeShipping'));
    }

    public function testShowsIndicatorWhenGateDoesNotSuppress(): void
    {
        $service = $this->createMock(FreeShippingService::class);
        $service->method('calculate')->willReturn(new FreeShippingStatus(50.0, 30.0, false, 'EUR'));

        $gate = $this->createMock(FreeShippingSwitchGate::class);
        $gate->method('isIndicatorSuppressed')->willReturn(false);

        $subscriber = new FreeShippingThresholdSubscriber(
            $this->configService(),
            $service,
            $this->reachability(),
            $this->estimateService(),
            $gate,
        );
        $event = $this->createCartEvent();

        $subscriber->onCartPageLoaded($event);

        self::assertTrue($event->getPage()->hasExtension('rcFreeShipping'));
    }

    /**
     * Gibt die Einstellung keinen brauchbaren Betrag her, rechnet der Indikator gegen
     * seinen eingebauten Rückfall — er hört nicht auf zu werben, nur weil im Admin
     * nichts steht.
     */
    public function testOnCartPageUsesDefaultThresholdWhenNoneConfigured(): void
    {
        $service = $this->createMock(FreeShippingService::class);
        $service->expects($this->once())
            ->method('calculate')
            ->with($this->anything(), $this->anything(), 50.0)
            ->willReturn(new FreeShippingStatus(50.0, 50.0, false, 'EUR'));

        $subscriber = new FreeShippingThresholdSubscriber(
            $this->configService(threshold: null),
            $service,
            $this->reachability(),
            $this->estimateService(),
        );
        $event = $this->createCartEvent();

        $subscriber->onCartPageLoaded($event);

        self::assertTrue($event->getPage()->hasExtension('rcFreeShipping'));
    }

    public function testOnCartPageDoesNothingWhenCartEmpty(): void
    {
        $service = $this->createMock(FreeShippingService::class);
        $service->expects($this->never())->method('calculate');

        $subscriber = new FreeShippingThresholdSubscriber(
            $this->configService(),
            $service,
            $this->reachability(),
            $this->estimateService(),
        );
        $event = $this->createCartEvent(withLineItem: false);

        $subscriber->onCartPageLoaded($event);

        self::assertFalse($event->getPage()->hasExtension('rcFreeShipping'));
    }

    public function testOffcanvasEventAddsExtension(): void
    {
        $service = $this->createMock(FreeShippingService::class);
        $service->method('calculate')->willReturn(new FreeShippingStatus(50.0, 30.0, false, 'EUR'));

        $subscriber = new FreeShippingThresholdSubscriber(
            $this->configService(),
            $service,
            $this->reachability(),
            $this->estimateService(),
        );
        $event = $this->createOffcanvasEvent();

        $subscriber->onCartPageLoaded($event);

        self::assertTrue($event->getPage()->hasExtension('rcFreeShipping'));
    }

    private function configService(bool $enabled = true, ?float $threshold = 50.0): ConfigService
    {
        $configService = $this->createMock(ConfigService::class);
        $configService->method('isFreeShippingIndicatorEnabled')->willReturn($enabled);
        $configService->method('getFreeShippingThreshold')->willReturn($threshold);
        $configService->method('getFreeShippingMethodIds')->willReturn([]);

        return $configService;
    }

    /**
     * Vorgabe: Der Warenkorb ist lieferbar. Die Tests hier prüfen andere Bedingungen;
     * der Sonderfall „nicht lieferbar" hat einen eigenen Test.
     */
    private function estimateService(bool $shippable = true): ShippingEstimateService
    {
        $service = $this->createMock(ShippingEstimateService::class);
        $service->method('canShipToContextLocation')->willReturn($shippable);

        return $service;
    }

    /**
     * Die Erreichbarkeit sagt „lässt sich nicht ablesen", also gilt der Hinweis — das ist
     * genau das Verhalten von vor 1.3.0, gegen das die Tests hier geschrieben wurden.
     */
    private function reachability(): FreeShippingReachability
    {
        $reachability = $this->createMock(FreeShippingReachability::class);
        $reachability->method('reachableFrom')->willReturn(FreeShippingReach::unknown());

        return $reachability;
    }

    private function createCartEvent(bool $withLineItem = true): CheckoutCartPageLoadedEvent
    {
        $page = new CheckoutCartPage();
        $page->setCart($this->createCart($withLineItem));

        return new CheckoutCartPageLoadedEvent($page, $this->createContext(), new Request());
    }

    private function createOffcanvasEvent(): OffcanvasCartPageLoadedEvent
    {
        $page = new OffcanvasCartPage();
        $page->setCart($this->createCart(true));

        return new OffcanvasCartPageLoadedEvent($page, $this->createContext(), new Request());
    }

    private function createCart(bool $withLineItem): Cart
    {
        $cart = new Cart('test-token');
        if ($withLineItem) {
            $cart->add(new LineItem('li-1', LineItem::PRODUCT_LINE_ITEM_TYPE, 'ref-1', 1));
        }

        return $cart;
    }

    private function createContext(): SalesChannelContext
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sc-id');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannel')->willReturn($salesChannel);

        return $context;
    }
}
