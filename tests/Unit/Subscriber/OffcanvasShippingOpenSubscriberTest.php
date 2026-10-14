<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Subscriber\OffcanvasShippingOpenSubscriber;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\Delivery;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryDate;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryPositionCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPage;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

/**
 * Wann die Leiste die Versandkosten als offen behandelt und die Zeile mit 0,00 € weglässt.
 */
final class OffcanvasShippingOpenSubscriberTest extends TestCase
{
    private const SPEDITION = 'spedition-id';
    private const PICKUP = 'abholung-id';

    /**
     * Was: Besucher ohne Konto, vorgewählte Spedition rechnet 0,00 €.
     * Warum: Der Fall aus dem Shop; ohne Adresse ist 0,00 € kein Preis.
     * Erwartet: offen.
     */
    public function testAGuestWithoutAddressAndZeroCostsIsOpen(): void
    {
        self::assertTrue($this->open(self::SPEDITION, 0.0, loggedIn: false));
    }

    /**
     * Was: Derselbe Warenkorb, aber mit Kunde.
     * Warum: Mit Kunde gibt es eine Lieferadresse, der Betrag gilt.
     * Erwartet: nicht offen.
     */
    public function testACustomerHasKnownCosts(): void
    {
        self::assertFalse($this->open(self::SPEDITION, 0.0, loggedIn: true));
    }

    /**
     * Was: Besucher ohne Konto, die Versandart rechnet einen Betrag.
     * Warum: Ein Betrag über null ist eine Auskunft und bleibt sichtbar.
     * Erwartet: nicht offen.
     */
    public function testAPriceAboveZeroIsShown(): void
    {
        self::assertFalse($this->open(self::SPEDITION, 4.95, loggedIn: false));
    }

    /**
     * Was: Besucher ohne Konto hat die Abholung gewählt.
     * Warum: Abholen kostet wirklich nichts; 0,00 € ist dort die richtige Aussage.
     * Erwartet: nicht offen.
     */
    public function testPickupIsNeverOpen(): void
    {
        self::assertFalse($this->open(self::PICKUP, 0.0, loggedIn: false));
    }

    /**
     * Was: Warenkorb ohne Lieferung.
     * Warum: Ohne Lieferung zeigt der Kern keine Zeile; es gibt nichts auszublenden.
     * Erwartet: nicht offen.
     */
    public function testWithoutDeliveryNothingIsOpen(): void
    {
        $event = $this->event(new Cart('leer'), loggedIn: false);
        $this->subscriber()->onOffcanvasLoaded($event);

        self::assertFalse($this->extension($event)->get('open'));
    }

    public function testItListensToTheOffcanvasCart(): void
    {
        self::assertArrayHasKey(OffcanvasCartPageLoadedEvent::class, OffcanvasShippingOpenSubscriber::getSubscribedEvents());
    }

    private function open(string $methodId, float $costs, bool $loggedIn): bool
    {
        $event = $this->event($this->cartWithDelivery($methodId, $costs), $loggedIn);
        $this->subscriber()->onOffcanvasLoaded($event);

        return (bool) $this->extension($event)->get('open');
    }

    private function extension(OffcanvasCartPageLoadedEvent $event): ArrayStruct
    {
        $extension = $event->getPage()->getExtension('rcShippingOpen');
        self::assertInstanceOf(ArrayStruct::class, $extension);

        return $extension;
    }

    private function subscriber(): OffcanvasShippingOpenSubscriber
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('getNonDeliveryMethodIds')->willReturn([self::PICKUP]);

        return new OffcanvasShippingOpenSubscriber($config);
    }

    private function cartWithDelivery(string $methodId, float $costs): Cart
    {
        $method = new ShippingMethodEntity();
        $method->setId($methodId);

        $country = new CountryEntity();
        $country->setId('de');

        $cart = new Cart('test-token');
        $cart->setDeliveries(new DeliveryCollection([
            new Delivery(
                new DeliveryPositionCollection(),
                new DeliveryDate(new \DateTimeImmutable(), new \DateTimeImmutable()),
                $method,
                ShippingLocation::createFromCountry($country),
                new CalculatedPrice($costs, $costs, new CalculatedTaxCollection(), new TaxRuleCollection()),
            ),
        ]));

        return $cart;
    }

    private function event(Cart $cart, bool $loggedIn): OffcanvasCartPageLoadedEvent
    {
        $page = new OffcanvasCartPage();
        $page->setCart($cart);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-id');
        $context->method('getCustomer')->willReturn($loggedIn ? new CustomerEntity() : null);

        return new OffcanvasCartPageLoadedEvent($page, $context, new Request());
    }
}
