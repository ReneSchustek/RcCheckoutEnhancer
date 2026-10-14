<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Ruhrcoder\RcCheckoutEnhancer\Checkout\CheckoutLayout;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\OnePageSelectionLoader;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingChoiceStore;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingMethodPreselectionApplier;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingMethodPreselector;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingPlaceholderFilter;
use Ruhrcoder\RcCheckoutEnhancer\Storefront\Controller\OnePageSelectionController;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Gateway\SalesChannel\AbstractCheckoutGatewayRoute;
use Shopware\Core\Checkout\Gateway\SalesChannel\CheckoutGatewayRouteResponse;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Content\Media\MediaUrlPlaceholderHandlerInterface;
use Shopware\Core\Content\Seo\SeoUrlPlaceholderHandlerInterface;
use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Die Route, die nach einem Wechsel von Versand- oder Zahlart nur den Ausschnitt neu liefert.
 *
 * Das Ajax-Verfahren des Kerns ersetzt den Container durch die ganze Antwort. Käme hier eine
 * vollständige Seite zurück, stünde sie samt Kopf und Fuß mitten im Formular.
 */
final class OnePageSelectionControllerTest extends TestCase
{
    /**
     * Was: Die Route rendert die Ausschnitt-Vorlage mit der geladenen Auswahl.
     * Warum: Der Hauptfall. Die Vorlage bekommt die Versandarten unter einem festen Namen.
     */
    public function testItRendersTheSelectionFragment(): void
    {
        $response = $this->controller()->selection(new Request(), $this->context());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('sm-paket', trim((string) $response->getContent()));
    }

    /**
     * Was: Die Vorlage, die gerendert wird.
     * Warum: Es muss der Ausschnitt sein, nie die Seite; ein Wechsel der Vorlage an dieser Stelle
     *        risse das Formular auseinander, ohne dass ein Fehler erscheint.
     */
    public function testTheTemplateIsTheFragment(): void
    {
        self::assertSame(
            '@RcCheckoutEnhancer/storefront/component/rc-checkout/one-page-selection.html.twig',
            OnePageSelectionController::TEMPLATE,
        );
    }

    /**
     * Was: Geführter Ablauf eingestellt, die Route wird trotzdem aufgerufen.
     * Warum: Ohne einseitigen Checkout braucht keine Seite den Ausschnitt; ein Aufruf schaltete
     *        nur die Versandart um.
     */
    public function testWithoutOnePageTheRouteDoesNotExist(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller(CheckoutLayout::GUIDED)->selection(new Request(), $this->context());
    }

    private function controller(string $layout = CheckoutLayout::ONE_PAGE): OnePageSelectionController
    {
        $shipping = new ShippingMethodEntity();
        $shipping->setId('sm-paket');
        $shipping->setUniqueIdentifier('sm-paket');

        $gateway = $this->createMock(AbstractCheckoutGatewayRoute::class);
        $gateway->method('load')->willReturn(new CheckoutGatewayRouteResponse(
            new PaymentMethodCollection(),
            new ShippingMethodCollection([$shipping]),
            new ErrorCollection(),
        ));

        $cartService = $this->createMock(CartService::class);
        $cartService->method('getCart')->willReturn(new Cart('token'));

        $configService = $this->createMock(ConfigService::class);
        $configService->method('isShippingPreselectEnabled')->willReturn(false);
        $configService->method('getCheckoutLayout')->willReturn($layout);

        $applier = new ShippingMethodPreselectionApplier(
            $configService,
            new ShippingMethodPreselector(),
            $this->createMock(AbstractContextSwitchRoute::class),
            $cartService,
            new ShippingChoiceStore(new RequestStack()),
            $this->createMock(AbstractPaymentMethodRoute::class),
        );

        $controller = new OnePageSelectionController(
            new OnePageSelectionLoader($cartService, $gateway, $applier, new ShippingPlaceholderFilter($configService)),
            $configService,
        );
        $controller->setContainer($this->container());

        return $controller;
    }

    private function container(): ContainerInterface
    {
        $request = new Request();
        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT, $this->context());

        $requestStack = new RequestStack();
        $requestStack->push($request);

        // Eine Vorlage, die ausgibt, was der Controller ihr reicht. Die echte zu laden hieße,
        // die Twig-Erweiterungen der Storefront mitzuschleppen.
        $twig = new Environment(new ArrayLoader([
            OnePageSelectionController::TEMPLATE => '{% for method in rcOnePageSelection.shippingMethods %}{{ method.id }}{% endfor %}',
        ]));

        $templateFinder = $this->createMock(TemplateFinder::class);
        $templateFinder->method('find')->willReturnArgument(0);

        $services = [
            'request_stack' => $requestStack,
            'event_dispatcher' => new EventDispatcher(),
            'twig' => $twig,
            SystemConfigService::class => $this->createMock(SystemConfigService::class),
            TemplateFinder::class => $templateFinder,
            MediaUrlPlaceholderHandlerInterface::class => $this->passThroughReplacer(),
            SeoUrlPlaceholderHandlerInterface::class => $this->passThroughReplacer(),
        ];

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => \array_key_exists($id, $services));
        $container->method('get')->willReturnCallback(static fn (string $id): mixed => $services[$id] ?? null);

        return $container;
    }

    private function passThroughReplacer(): object
    {
        return new class () {
            public function replace(string $content, ?string $host = null, mixed $context = null): string
            {
                return $content;
            }
        };
    }

    private function context(): SalesChannelContext
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

        return $context;
    }
}
