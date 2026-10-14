<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Checkout\CheckoutLayout;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\OnePageSelectionLoader;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingChoiceStore;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingMethodPreselectionApplier;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingMethodPreselector;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingPlaceholderFilter;
use Ruhrcoder\RcCheckoutEnhancer\Struct\OnePageSelection;
use Ruhrcoder\RcCheckoutEnhancer\Subscriber\OnePageCheckoutSubscriber;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Gateway\SalesChannel\AbstractCheckoutGatewayRoute;
use Shopware\Core\Checkout\Gateway\SalesChannel\CheckoutGatewayRouteResponse;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Storefront\Page\Checkout\Register\CheckoutRegisterPage;
use Shopware\Storefront\Page\Checkout\Register\CheckoutRegisterPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Die Adressseite bekommt Versand, Zahlung und Warenkorb nur, wenn der einseitige Checkout
 * eingeschaltet ist oder ein A/B-Test darüber entscheidet.
 */
final class OnePageCheckoutSubscriberTest extends TestCase
{
    /**
     * Was: Die Ereignisliste.
     * Warum: Ein falscher Name ließe den Ausschnitt still weg; die Seite zeigte nur das Formular.
     */
    public function testItListensToTheRegisterPage(): void
    {
        self::assertSame(
            [CheckoutRegisterPageLoadedEvent::class => 'onRegisterPage'],
            OnePageCheckoutSubscriber::getSubscribedEvents(),
        );
    }

    /**
     * Was: Geführter Checkout.
     * Warum: Dann darf nicht einmal die Gateway-Route gefragt werden; die Seite bleibt, wie sie war.
     */
    public function testInTheGuidedLayoutNothingIsLoaded(): void
    {
        $gateway = $this->createMock(AbstractCheckoutGatewayRoute::class);
        $gateway->expects(self::never())->method('load');

        $page = $this->page();
        $this->subscriber(CheckoutLayout::GUIDED, $gateway)->onRegisterPage($this->event($page));

        self::assertFalse($page->hasExtension(OnePageCheckoutSubscriber::EXTENSION));
    }

    public function testForOnePageTheSelectionIsAttached(): void
    {
        $page = $this->page();
        $this->subscriber(CheckoutLayout::ONE_PAGE, $this->gateway())->onRegisterPage($this->event($page));

        self::assertInstanceOf(OnePageSelection::class, $page->getExtension(OnePageCheckoutSubscriber::EXTENSION));
    }

    /**
     * Was: A/B-Test eingestellt, RcAbTesting fehlt (wie in dieser Testumgebung).
     * Warum: Ohne `ab_switch()` zeigt die Vorlage den geführten Ablauf. Die Auswahl zu laden
     *        kostete Gateway und Vorauswahl, ohne dass sie erscheint.
     */
    public function testAnAbTestWithoutAbTestingLoadsNothing(): void
    {
        $gateway = $this->createMock(AbstractCheckoutGatewayRoute::class);
        $gateway->expects(self::never())->method('load');

        $page = $this->page();
        $this->subscriber(CheckoutLayout::AB_TEST, $gateway)->onRegisterPage($this->event($page));

        self::assertFalse($page->hasExtension(OnePageCheckoutSubscriber::EXTENSION));
    }

    /**
     * Was: Der Ausschnitt rechnet mit dem Warenkorb der Seite.
     * Warum: Ein zweites Laden kostete eine Berechnung und könnte einen anderen Stand zeigen als
     *        die Seitenleiste daneben.
     */
    public function testItUsesThePageCart(): void
    {
        $cartService = $this->createMock(CartService::class);
        $cartService->expects(self::never())->method('getCart');

        $page = $this->page();
        $this->subscriber(CheckoutLayout::ONE_PAGE, $this->gateway(), $cartService)->onRegisterPage($this->event($page));

        $selection = $page->getExtension(OnePageCheckoutSubscriber::EXTENSION);
        self::assertInstanceOf(OnePageSelection::class, $selection);
        self::assertSame($page->getCart(), $selection->getCart());
    }

    private function subscriber(
        string $layout,
        AbstractCheckoutGatewayRoute $gateway,
        ?CartService $cartService = null,
    ): OnePageCheckoutSubscriber {
        $cartService ??= $this->createMock(CartService::class);

        $configService = $this->createMock(ConfigService::class);
        $configService->method('getCheckoutLayout')->willReturn($layout);
        $configService->method('isShippingPreselectEnabled')->willReturn(false);

        $applier = new ShippingMethodPreselectionApplier(
            $configService,
            new ShippingMethodPreselector(),
            $this->createMock(AbstractContextSwitchRoute::class),
            $cartService,
            new ShippingChoiceStore(new RequestStack()),
            $this->createMock(AbstractPaymentMethodRoute::class),
        );

        return new OnePageCheckoutSubscriber(
            $configService,
            new OnePageSelectionLoader($cartService, $gateway, $applier, new ShippingPlaceholderFilter($configService)),
        );
    }

    private function gateway(): AbstractCheckoutGatewayRoute
    {
        $gateway = $this->createMock(AbstractCheckoutGatewayRoute::class);
        $gateway->method('load')->willReturn(new CheckoutGatewayRouteResponse(
            new PaymentMethodCollection(),
            new ShippingMethodCollection(),
            new ErrorCollection(),
        ));

        return $gateway;
    }

    private function page(): CheckoutRegisterPage
    {
        $page = new CheckoutRegisterPage();
        $page->setCart(new Cart('token'));

        return $page;
    }

    private function event(CheckoutRegisterPage $page): CheckoutRegisterPageLoadedEvent
    {
        $method = new ShippingMethodEntity();
        $method->setId('sm-paket');
        $method->setUniqueIdentifier('sm-paket');

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sc-id');
        $salesChannel->setUniqueIdentifier('sc-id');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-id');
        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getShippingMethod')->willReturn($method);
        $context->method('getToken')->willReturn('token');

        return new CheckoutRegisterPageLoadedEvent($page, $context, new Request());
    }
}
