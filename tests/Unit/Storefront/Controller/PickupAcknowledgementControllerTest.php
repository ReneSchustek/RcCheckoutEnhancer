<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Checkout\ShippingEnquiryRule;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\PickupAcknowledgementStore;
use Ruhrcoder\RcCheckoutEnhancer\Service\PickupHintDecider;
use Ruhrcoder\RcCheckoutEnhancer\Storefront\Controller\PickupAcknowledgementController;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryInformation;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Gateway\SalesChannel\AbstractCheckoutGatewayRoute;
use Shopware\Core\Checkout\Gateway\SalesChannel\CheckoutGatewayRouteResponse;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Der Endpunkt, der die Abholung schwerer Ware bestätigt. Er bestimmt selbst, was bestätigt wird,
 * und lehnt ab, wo statt einer Bestellung die Anfrage gilt.
 */
final class PickupAcknowledgementControllerTest extends TestCase
{
    private const ABHOLUNG = 'sm-abholung';

    private const SPEDITION = 'sm-spedition';

    private PickupAcknowledgementStore $store;

    protected function setUp(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);

        $this->store = new PickupAcknowledgementStore($stack);
    }

    public function testAHeavyPickupIsAcknowledged(): void
    {
        $response = $this->controller(available: [self::SPEDITION, self::ABHOLUNG])->acknowledge(new Request(), $this->context(self::ABHOLUNG));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['acknowledged' => true], json_decode((string) $response->getContent(), true));
        self::assertNotNull($this->store->stored());
    }

    /**
     * Was: Nur die Abholung ist übrig, der Anfrageweg ist eingerichtet, der Endpunkt wird direkt
     *      aufgerufen.
     * Warum: Die Seite zeigt dann keinen Dialog, sondern die Anfrage. Bestätigte der Endpunkt
     *        trotzdem, ließe sich am Anfrageweg vorbei bestellen.
     */
    public function testWhenOnlyPickupRemainsTheEnquiryApplies(): void
    {
        $response = $this->controller(available: [self::ABHOLUNG])->acknowledge(new Request(), $this->context(self::ABHOLUNG));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('enquiry-required', json_decode((string) $response->getContent(), true)['reason'] ?? null);
        self::assertNull($this->store->stored());
    }

    /**
     * Was: Keine Abholung gewählt, also kein Hinweis fällig.
     * Warum: Eine Bestätigung auf Vorrat passte später auf einen anderen Warenkorb.
     */
    public function testWithoutAHintThereIsNothingToAcknowledge(): void
    {
        $response = $this->controller(available: [self::SPEDITION, self::ABHOLUNG])->acknowledge(new Request(), $this->context(self::SPEDITION));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('not-applicable', json_decode((string) $response->getContent(), true)['reason'] ?? null);
    }

    /**
     * @param list<string> $available
     */
    private function controller(array $available): PickupAcknowledgementController
    {
        $configService = $this->createMock(ConfigService::class);
        $configService->method('getPickupHintWeightThreshold')->willReturn(50.0);
        $configService->method('getPickupHintLengthThreshold')->willReturn(null);
        $configService->method('getNonDeliveryMethodIds')->willReturn([self::ABHOLUNG]);
        $configService->method('getPickupHintText')->willReturn('Verladen und Transport übernehmen Sie selbst.');
        $configService->method('isShippingEnquiryEnabled')->willReturn(true);
        $configService->method('getShippingEnquiryCategoryId')->willReturn('kategorie-kontakt');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $cartService = $this->createMock(CartService::class);
        $cartService->method('getCart')->willReturn($this->heavyCart());

        $methods = new ShippingMethodCollection();
        foreach ($available as $id) {
            $method = new ShippingMethodEntity();
            $method->setId($id);
            $method->setUniqueIdentifier($id);
            $methods->add($method);
        }

        $gateway = $this->createMock(AbstractCheckoutGatewayRoute::class);
        $gateway->method('load')->willReturn(new CheckoutGatewayRouteResponse(new PaymentMethodCollection(), $methods, new ErrorCollection()));

        return new PickupAcknowledgementController(
            new PickupHintDecider($configService, $translator),
            $this->store,
            $cartService,
            $gateway,
            new ShippingEnquiryRule($configService),
        );
    }

    private function heavyCart(): Cart
    {
        $lineItem = new LineItem('li-1', LineItem::PRODUCT_LINE_ITEM_TYPE, 'ref-1', 1);
        $lineItem->setGood(true);
        $lineItem->setPrice(new CalculatedPrice(1.0, 1.0, new CalculatedTaxCollection(), new TaxRuleCollection(), 1));
        $lineItem->setDeliveryInformation(new DeliveryInformation(100, 120.0, false, null, null, 100.0, 200.0, 400.0));

        $cart = new Cart('token');
        $cart->add($lineItem);

        return $cart;
    }

    private function context(string $shippingMethodId): SalesChannelContext
    {
        $shippingMethod = new ShippingMethodEntity();
        $shippingMethod->setId($shippingMethodId);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-id');
        $context->method('getToken')->willReturn('token');
        $context->method('getShippingMethod')->willReturn($shippingMethod);

        return $context;
    }
}
