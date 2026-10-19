<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\CartAccessoryService;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\EstimateFormData;
use Ruhrcoder\RcCheckoutEnhancer\Service\PaymentPlaceholderFilter;
use Ruhrcoder\RcCheckoutEnhancer\Subscriber\CartAccessorySubscriber;
use Ruhrcoder\RcCheckoutEnhancer\Subscriber\PaymentPlaceholderSubscriber;
use Ruhrcoder\RcCheckoutEnhancer\Subscriber\ProductShippingEstimateSubscriber;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Content\Product\SalesChannel\CrossSelling\AbstractProductCrossSellingRoute;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Country\SalesChannel\AbstractCountryRoute;
use Shopware\Core\System\Country\SalesChannel\CountryRouteResponse;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPage;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Product\ProductPage;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\HttpFoundation\Request;

/**
 * Die drei kleinen Subscriber, die nur einen Dienst an eine Seite hängen: Zubehör im Warenkorb, Platzhalter-Zahlart
 * und der Rechner der Produktseite. Geprüft wird, was sie entscheiden; die Dienste haben eigene Tests.
 */
#[CoversClass(CartAccessorySubscriber::class)]
#[CoversClass(PaymentPlaceholderSubscriber::class)]
#[CoversClass(ProductShippingEstimateSubscriber::class)]
final class SmallPageSubscribersTest extends TestCase
{
    /**
     * Was: Zubehör ausgeschaltet.
     * Warum: Ausgeschaltet darf keine Querverkaufs-Abfrage laufen; die Leiste geht nach jedem Hinzufügen auf.
     * Erwartet: keine Abfrage, keine Erweiterung.
     */
    public function testAccessoriesStayOffWhenDisabled(): void
    {
        $route = $this->createMock(AbstractProductCrossSellingRoute::class);
        $route->expects(self::never())->method('load');
        $repository = $this->createMock(SalesChannelRepository::class);
        $repository->expects(self::never())->method('search');
        $config = $this->createStub(ConfigService::class);
        $config->method('isCartAccessoriesEnabled')->willReturn(false);

        $page = new CheckoutCartPage();
        $page->setCart(new Cart('token'));
        $subscriber = new CartAccessorySubscriber($config, new CartAccessoryService($route, $repository, $config, new TagAwareAdapter(new ArrayAdapter())));
        $subscriber->onCartPageLoaded(new CheckoutCartPageLoadedEvent($page, $this->context(), new Request()));

        self::assertFalse($page->hasExtension('rcCartAccessories'));
    }

    /**
     * Was: Bestätigungsseite mit Platzhalter-Zahlart in der Liste.
     * Warum: Der Platzhalter steht nur vorne, bis der Kunde wählt; als wählbare Zahlart wäre er eine Falle.
     * Erwartet: Die Seite listet ihn nicht mehr.
     */
    public function testThePlaceholderLeavesThePaymentList(): void
    {
        $config = $this->createStub(ConfigService::class);
        $config->method('getPaymentPlaceholderMethodId')->willReturn('platzhalter');

        $page = new CheckoutConfirmPage();
        $page->setPaymentMethods(new PaymentMethodCollection([$this->payment('platzhalter'), $this->payment('vorkasse')]));
        (new PaymentPlaceholderSubscriber(new PaymentPlaceholderFilter($config)))
            ->onPageLoaded(new CheckoutConfirmPageLoadedEvent($page, $this->context(), new Request()));

        self::assertSame(['vorkasse'], array_values($page->getPaymentMethods()->getIds()));
    }

    /**
     * Was: Produktseite eines angemeldeten Kunden mit Postleitzahl im Konto.
     * Warum: Die Produktseite liegt im Seiten-Zwischenspeicher; eine Anschrift darin sähe der nächste Besucher.
     * Erwartet: Länder und Land kommen mit, die Postleitzahl bleibt leer; ausgeschaltet keine Erweiterung.
     */
    public function testTheProductCalculatorNeverCarriesTheZipCode(): void
    {
        $countryRoute = $this->createStub(AbstractCountryRoute::class);
        $countryRoute->method('load')->willReturn(new CountryRouteResponse(new EntitySearchResult(
            'country',
            0,
            new CountryCollection(),
            null,
            new Criteria(),
            new Context(new SystemSource()),
        )));

        $page = new ProductPage();
        $this->productSubscriber(true, $countryRoute)->onProductPageLoaded(new ProductPageLoadedEvent($page, $this->context(withZip: true), new Request()));

        $extension = $page->getExtension('rcProductShippingEstimate');
        self::assertInstanceOf(ArrayStruct::class, $extension);
        self::assertSame('', $extension->get('currentZipCode'));
        self::assertSame('de', $extension->get('currentCountryId'));

        $off = new ProductPage();
        $this->productSubscriber(false, $countryRoute)->onProductPageLoaded(new ProductPageLoadedEvent($off, $this->context(), new Request()));
        self::assertFalse($off->hasExtension('rcProductShippingEstimate'));
    }

    private function productSubscriber(bool $enabled, AbstractCountryRoute $countryRoute): ProductShippingEstimateSubscriber
    {
        $config = $this->createStub(ConfigService::class);
        $config->method('isProductShippingEstimateEnabled')->willReturn($enabled);

        return new ProductShippingEstimateSubscriber($config, new EstimateFormData($countryRoute));
    }

    private function payment(string $id): PaymentMethodEntity
    {
        $method = new PaymentMethodEntity();
        $method->setId($id);

        return $method;
    }

    private function context(bool $withZip = false): SalesChannelContext
    {
        $country = new CountryEntity();
        $country->setId('de');
        $address = null;
        if ($withZip) {
            $address = new CustomerAddressEntity();
            $address->setZipcode('44787');
        }

        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('kanal');
        $context->method('getShippingLocation')->willReturn(new ShippingLocation($country, null, $address));

        return $context;
    }
}
