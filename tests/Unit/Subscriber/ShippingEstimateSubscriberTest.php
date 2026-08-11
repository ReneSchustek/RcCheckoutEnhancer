<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Subscriber\ShippingEstimateSubscriber;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Country\SalesChannel\AbstractCountryRoute;
use Shopware\Core\System\Country\SalesChannel\CountryRouteResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPage;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

/**
 * Die Bedingungen, unter denen der Versandkostenrechner überhaupt auf die Seite kommt.
 *
 * Bis 1.5.0 lief dieser Einstiegspunkt nur im Smoke-Test gegen echte Anfragen. Das prüft,
 * **dass** er funktioniert, aber nicht, **warum** er in den drei Fällen schweigt — und
 * genau die sind es, die beim nächsten Umbau kippen.
 */
final class ShippingEstimateSubscriberTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'sc-id';

    public function testItAddsTheCountriesWhenEnabled(): void
    {
        $event = $this->event();
        $this->subscriber()->onCartPageLoaded($event);

        $extension = $event->getPage()->getExtension('rcShippingEstimate');
        self::assertInstanceOf(ArrayStruct::class, $extension);
        self::assertInstanceOf(CountryCollection::class, $extension->get('countries'));
    }

    /**
     * Warum: Der Rechner ist im Auslieferungszustand aus und muss je Verkaufskanal
     *        eingeschaltet werden.
     */
    public function testASwitchedOffEstimatorAddsNothing(): void
    {
        $event = $this->event();
        $this->subscriber(enabled: false)->onCartPageLoaded($event);

        self::assertFalse($event->getPage()->hasExtension('rcShippingEstimate'));
    }

    /**
     * Was: Ein angemeldeter Kunde sieht den Rechner ebenfalls.
     * Warum: **Bewusste Umkehr einer früheren Entscheidung.** Bis 1.8.1 blieb er Gästen
     *        vorbehalten — wer angemeldet ist, habe seine Adresse im Konto, und eine zweite
     *        Zahl daneben wäre irreführend. Das galt, solange der Bestellvorgang immer zu
     *        einem Ergebnis führte. Seit belegt ist, dass er in eine Sackgasse laufen kann,
     *        ist der Rechner die einzige Stelle im Warenkorb, an der jemand erfährt, dass es
     *        für seine Sendung gar keine Versandart gibt — und ausgerechnet der Stammkunde
     *        mit der großen Bestellung sah das nicht.
     */
    public function testSignedInCustomersSeeTheEstimatorAsWell(): void
    {
        $event = $this->event(loggedIn: true);
        $this->subscriber()->onCartPageLoaded($event);

        self::assertTrue($event->getPage()->hasExtension('rcShippingEstimate'));
    }

    /**
     * Warum: Ein leerer Warenkorb hat keine Versandkosten, über die sich reden ließe.
     */
    public function testAnEmptyCartGetsNoEstimator(): void
    {
        $event = $this->event(empty: true);
        $this->subscriber()->onCartPageLoaded($event);

        self::assertFalse($event->getPage()->hasExtension('rcShippingEstimate'));
    }

    private function subscriber(bool $enabled = true): ShippingEstimateSubscriber
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('isShippingEstimatorEnabled')->willReturn($enabled);

        $country = new CountryEntity();
        $country->setId('country-de');
        $country->setUniqueIdentifier('country-de');

        $response = $this->createMock(CountryRouteResponse::class);
        $response->method('getCountries')->willReturn(new CountryCollection([$country]));

        $route = $this->createMock(AbstractCountryRoute::class);
        $route->method('load')->willReturn($response);

        return new ShippingEstimateSubscriber($config, $route);
    }

    private function event(bool $loggedIn = false, bool $empty = false): CheckoutCartPageLoadedEvent
    {
        $cart = new Cart('token');
        if (!$empty) {
            $cart->add(new LineItem('li-1', LineItem::PRODUCT_LINE_ITEM_TYPE, 'ref-1', 1));
        }

        $page = new CheckoutCartPage();
        $page->setCart($cart);

        $country = new CountryEntity();
        $country->setId('country-de');
        $country->setUniqueIdentifier('country-de');

        $location = $this->createMock(\Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation::class);
        $location->method('getCountry')->willReturn($country);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $context->method('getCustomer')->willReturn($loggedIn ? new CustomerEntity() : null);
        $context->method('getShippingLocation')->willReturn($location);

        return new CheckoutCartPageLoadedEvent($page, $context, new Request());
    }
}
