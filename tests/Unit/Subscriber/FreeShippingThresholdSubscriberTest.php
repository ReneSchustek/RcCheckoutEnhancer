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
use Shopware\Core\Checkout\Cart\Delivery\Struct\Delivery;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryDate;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryPositionCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\System\Country\CountryEntity;
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
     * kostenlosen Versand für einen Warenkorb, den er gar nicht ausliefert — an echten
     * Versanddaten mit 530 kg gemessen.
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

    /**
     * Was: Die Schwelle ist erreicht, der Warenkorb trägt aber Versandkosten.
     * Warum: **Der Kern.** An einem Shop mit echten Versanddaten stand bei 530 kg „Glückwunsch —
     *        versandkostenfrei" über einer Zusammenfassung, die 8,93 € berechnete. Die
     *        versandkostenfreie Versandart war für das Gewicht gesperrt; geliefert hätte ein
     *        Paketdienst zum Normaltarif. Der Hinweis rechnete nur Warenwert gegen Schwelle
     *        und wusste davon nichts.
     * Erwartet: gar keine Zusage — auch kein „noch X € fehlen", die Schwelle ist ja
     *        überschritten.
     */
    public function testNoPromiseWhenTheCartStillCarriesShippingCosts(): void
    {
        $service = $this->createMock(FreeShippingService::class);
        $service->method('calculate')->willReturn(new FreeShippingStatus(357.0, 0.0, true, 'EUR'));

        $subscriber = new FreeShippingThresholdSubscriber(
            $this->configService(),
            $service,
            $this->reachability(),
            $this->estimateService(),
        );
        $event = $this->createCartEvent(shippingCosts: 8.93);

        $subscriber->onCartPageLoaded($event);

        self::assertFalse(
            $event->getPage()->hasExtension('rcFreeShipping'),
            'Versandkostenfreiheit darf nicht zugesagt werden, während der Warenkorb Versand berechnet.',
        );
    }

    /**
     * Die Gegenprobe: Kostet der Versand tatsächlich nichts, bleibt die Zusage stehen.
     * Ohne diesen Test wäre der Riegel darüber auch dann grün, wenn er den Hinweis
     * überall abschaltete.
     */
    public function testThePromiseStaysWhenShippingIsActuallyFree(): void
    {
        $service = $this->createMock(FreeShippingService::class);
        $service->method('calculate')->willReturn(new FreeShippingStatus(357.0, 0.0, true, 'EUR'));

        $subscriber = new FreeShippingThresholdSubscriber(
            $this->configService(),
            $service,
            $this->reachability(),
            $this->estimateService(),
        );
        $event = $this->createCartEvent(shippingCosts: 0.0);

        $subscriber->onCartPageLoaded($event);

        self::assertTrue($event->getPage()->hasExtension('rcFreeShipping'));
    }

    /**
     * Die Schwelle ist **nicht** erreicht und der Versand kostet etwas — der Normalfall.
     * „Noch X € bis zur versandkostenfreien Lieferung" ist genau dann richtig und muss
     * stehen bleiben; der Riegel darf nur die erreichte Zusage treffen.
     */
    public function testTheRemainingAmountIsShownEvenWhenShippingCostsSomething(): void
    {
        $service = $this->createMock(FreeShippingService::class);
        $service->method('calculate')->willReturn(new FreeShippingStatus(357.0, 338.20, false, 'EUR'));

        $subscriber = new FreeShippingThresholdSubscriber(
            $this->configService(),
            $service,
            $this->reachability(),
            $this->estimateService(),
        );
        $event = $this->createCartEvent(shippingCosts: 8.93);

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

    private function createCartEvent(bool $withLineItem = true, float $shippingCosts = 0.0): CheckoutCartPageLoadedEvent
    {
        $page = new CheckoutCartPage();
        $page->setCart($this->createCart($withLineItem, $shippingCosts));

        return new CheckoutCartPageLoadedEvent($page, $this->createContext(), new Request());
    }

    /**
     * Hängt dem Warenkorb eine Lieferung mit Versandkosten an.
     *
     * Umständlicher als ein Setter, weil `Cart::getShippingCosts()` die Kosten aus den
     * Lieferungen summiert — ein Warenkorb ohne Lieferung kostet naturgemäß nichts. Genau
     * dieser Weg wird hier geprüft.
     */
    private function attachShippingCosts(Cart $cart, float $shippingCosts): void
    {
        $country = new CountryEntity();
        $country->setId('country-de');
        $country->setUniqueIdentifier('country-de');

        $shippingMethod = new ShippingMethodEntity();
        $shippingMethod->setId('sm-1');
        $shippingMethod->setUniqueIdentifier('sm-1');

        $cart->setDeliveries(new DeliveryCollection([
            new Delivery(
                new DeliveryPositionCollection(),
                new DeliveryDate(new \DateTimeImmutable(), new \DateTimeImmutable()),
                $shippingMethod,
                ShippingLocation::createFromCountry($country),
                new CalculatedPrice($shippingCosts, $shippingCosts, new CalculatedTaxCollection(), new TaxRuleCollection()),
            ),
        ]));
    }

    private function createOffcanvasEvent(): OffcanvasCartPageLoadedEvent
    {
        $page = new OffcanvasCartPage();
        $page->setCart($this->createCart(true));

        return new OffcanvasCartPageLoadedEvent($page, $this->createContext(), new Request());
    }

    private function createCart(bool $withLineItem, float $shippingCosts = 0.0): Cart
    {
        $cart = new Cart('test-token');
        if ($withLineItem) {
            $cart->add(new LineItem('li-1', LineItem::PRODUCT_LINE_ITEM_TYPE, 'ref-1', 1));
        }

        if ($shippingCosts > 0.0) {
            $this->attachShippingCosts($cart, $shippingCosts);
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
