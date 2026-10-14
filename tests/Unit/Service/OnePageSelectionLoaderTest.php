<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\OnePageSelectionLoader;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingChoiceStore;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingMethodPreselectionApplier;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingMethodPreselector;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingPlaceholderFilter;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Gateway\SalesChannel\AbstractCheckoutGatewayRoute;
use Shopware\Core\Checkout\Gateway\SalesChannel\CheckoutGatewayRouteResponse;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Was der einseitige Checkout unter dem Adressformular zeigt: Versandarten, Zahlarten, Warenkorb.
 *
 * Die Liste kommt aus derselben Quelle wie auf der Warenkorbseite, der Checkout-Gateway-Route des
 * Kerns, und die Vorauswahl läuft mit. Sonst stünde auf dieser Seite wieder eine Abholung angehakt,
 * die niemand gewählt hat.
 */
final class OnePageSelectionLoaderTest extends TestCase
{
    private const PAKET = 'sm-paket';

    private const ABHOLUNG = 'sm-abholung';

    /**
     * Was: Nichts ist umzuschalten.
     * Warum: Der Regelfall. Die Methoden kommen unverändert aus der Gateway-Route, der Warenkorb
     *        ist der übergebene.
     */
    public function testItReturnsTheGatewayMethodsAndTheGivenCart(): void
    {
        $cart = new Cart('token');
        $gateway = $this->gateway([[self::PAKET, 1]], ['pm-vorkasse']);
        $gateway->expects(self::once())->method('load');

        $selection = $this->loader($gateway)->load(new Request(), $this->context(self::PAKET), $cart);

        self::assertSame($cart, $selection->getCart());
        self::assertSame([self::PAKET], array_values($selection->getShippingMethods()->getIds()));
        self::assertSame(['pm-vorkasse'], array_values($selection->getPaymentMethods()->getIds()));
    }

    /**
     * Was: Ohne übergebenen Warenkorb holt der Lader ihn selbst.
     * Warum: So ruft ihn die Route des Ausschnitts auf; sie hat keine Seite, die ihn schon trägt.
     */
    public function testWithoutACartItLoadsTheCurrentOne(): void
    {
        $cart = new Cart('token');
        $cartService = $this->createMock(CartService::class);
        $cartService->expects(self::once())->method('getCart')->with('token')->willReturn($cart);

        $selection = $this->loader($this->gateway([[self::PAKET, 1]], []), $cartService)
            ->load(new Request(), $this->context(self::PAKET));

        self::assertSame($cart, $selection->getCart());
    }

    /**
     * Was: Im Kontext steht eine Abholung, die niemand angeklickt hat.
     * Warum: Die Vorauswahl schaltet um. Danach kann sich die Liste der Zahlarten ändern, weil
     *        ihre Regeln am Warenkorb hängen; der Lader fragt deshalb ein zweites Mal und reicht
     *        den neu gerechneten Warenkorb weiter.
     */
    public function testAfterASwitchItReloadsTheMethodsAndPassesTheNewCart(): void
    {
        $recalculated = new Cart('token-neu');
        $cartService = $this->createMock(CartService::class);
        $cartService->method('getCart')->willReturn($recalculated);

        $gateway = $this->gateway([[self::PAKET, 1], [self::ABHOLUNG, 200]], []);
        $gateway->expects(self::exactly(2))->method('load');

        $switchRoute = $this->createMock(AbstractContextSwitchRoute::class);
        $switchRoute->expects(self::once())->method('switchContext');

        $selection = $this->loader($gateway, $cartService, $switchRoute, [self::ABHOLUNG])
            ->load(new Request(), $this->context(self::ABHOLUNG), new Cart('token'));

        self::assertSame($recalculated, $selection->getCart());
    }

    /**
     * Was: Die Anfrage an die Gateway-Route.
     * Warum: Ohne `onlyAvailable` lieferte sie auch Methoden, deren Regel nicht greift; der Kunde
     *        könnte eine wählen, die beim Bestellen gesperrt ist.
     */
    public function testItAsksTheGatewayForAvailableMethodsOnly(): void
    {
        $gateway = $this->gateway([[self::PAKET, 1]], []);
        $gateway->expects(self::once())
            ->method('load')
            ->with(self::callback(static fn (Request $request): bool => $request->query->getBoolean('onlyAvailable')));

        $this->loader($gateway)->load(new Request(), $this->context(self::PAKET), new Cart('token'));
    }

    /**
     * Was: Paketware ohne Adresse, der Platzhalter ist verfügbar.
     * Warum: In der angezeigten Liste steht er dann nicht; angehakt ist der Paketdienst.
     */
    public function testThePlaceholderIsNotOfferedNextToADeliveryMethod(): void
    {
        $selection = $this->loader($this->gateway([['sm-placeholder', 0], [self::PAKET, 1]], []), placeholder: 'sm-placeholder')
            ->load(new Request(), $this->context(self::PAKET), new Cart('token'));

        self::assertSame([self::PAKET], array_values($selection->getShippingMethods()->getIds()));
    }

    /**
     * @param list<string> $nonDelivery
     */
    private function loader(
        AbstractCheckoutGatewayRoute $gateway,
        ?CartService $cartService = null,
        ?AbstractContextSwitchRoute $switchRoute = null,
        array $nonDelivery = [],
        ?string $placeholder = null,
    ): OnePageSelectionLoader {
        $cartService ??= $this->createMock(CartService::class);

        $configService = $this->createMock(ConfigService::class);
        $configService->method('isShippingPreselectEnabled')->willReturn(true);
        $configService->method('getNonDeliveryMethodIds')->willReturn($nonDelivery);
        $configService->method('getShippingPlaceholderMethodId')->willReturn($placeholder);

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);

        $applier = new ShippingMethodPreselectionApplier(
            $configService,
            new ShippingMethodPreselector(),
            $switchRoute ?? $this->createMock(AbstractContextSwitchRoute::class),
            $cartService,
            new ShippingChoiceStore($stack),
            $this->createMock(AbstractPaymentMethodRoute::class),
        );

        return new OnePageSelectionLoader($cartService, $gateway, $applier, new ShippingPlaceholderFilter($configService));
    }

    /**
     * @param list<array{0: string, 1: int}> $shipping Kennung und Position
     * @param list<string> $payment
     */
    private function gateway(array $shipping, array $payment): AbstractCheckoutGatewayRoute&MockObject
    {
        $shippingMethods = [];
        foreach ($shipping as [$id, $position]) {
            $method = new ShippingMethodEntity();
            $method->setId($id);
            $method->setUniqueIdentifier($id);
            $method->setName($id);
            $method->setPosition($position);
            $shippingMethods[] = $method;
        }

        $paymentMethods = [];
        foreach ($payment as $id) {
            $method = new PaymentMethodEntity();
            $method->setId($id);
            $method->setUniqueIdentifier($id);
            $paymentMethods[] = $method;
        }

        $gateway = $this->createMock(AbstractCheckoutGatewayRoute::class);
        $gateway->method('load')->willReturn(new CheckoutGatewayRouteResponse(
            new PaymentMethodCollection($paymentMethods),
            new ShippingMethodCollection($shippingMethods),
            new ErrorCollection(),
        ));

        return $gateway;
    }

    private function context(string $shippingMethodId): SalesChannelContext
    {
        $method = new ShippingMethodEntity();
        $method->setId($shippingMethodId);
        $method->setUniqueIdentifier($shippingMethodId);
        $method->setName($shippingMethodId);

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sc-id');
        $salesChannel->setUniqueIdentifier('sc-id');
        $salesChannel->setShippingMethodId('sm-standard-des-kanals');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-id');
        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getShippingMethod')->willReturn($method);
        $context->method('getToken')->willReturn('token');
        $context->method('getRuleIds')->willReturn([]);

        return $context;
    }
}
