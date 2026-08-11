<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Ruhrcoder\RcCheckoutEnhancer\Service\CartFingerprint;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\EstimateInputValidator;
use Ruhrcoder\RcCheckoutEnhancer\Service\LastShippingEstimateStore;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingEstimateService;
use Ruhrcoder\RcCheckoutEnhancer\Storefront\Controller\ShippingEstimateController;
use Ruhrcoder\RcCheckoutEnhancer\Struct\ShippingEstimate;
use Ruhrcoder\RcCheckoutEnhancer\Struct\ShippingEstimateResult;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Media\MediaUrlPlaceholderHandlerInterface;
use Shopware\Core\Content\Seo\SeoUrlPlaceholderHandlerInterface;
use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Shopware\Core\Framework\RateLimiter\RateLimiter;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Der erfolgreiche Weg durch den Rechner-Endpunkt — inklusive Rendern.
 *
 * Dieser Test braucht ein Gerüst: `renderStorefront()` holt sich Anfrage, Ereignis-Verteiler,
 * Einstellungen und Twig aus dem Container. Das prüft streng genommen mehr Rahmen als
 * Entscheidung, und es war die bewusste Grenze, die dieses Plugin bis 1.5.1 gezogen hat.
 *
 * Gebaut wurde es trotzdem, weil die Marke aus `prinzipien.md` gelten soll. Der Nutzen ist
 * nicht null: Belegt ist, dass die Auskunft **gemerkt** wird, bevor sie hinausgeht — genau
 * die Reihenfolge, von der die Warenkorb-Seitenleiste lebt.
 */
final class ShippingEstimateControllerRenderTest extends TestCase
{
    private const TEMPLATE = '@Storefront/storefront/component/rc-checkout/shipping-estimate-result.html.twig';

    /**
     * Was: Land und Postleitzahl kommen an, die Berechnung gelingt.
     * Erwartet: HTTP 200 — und die Auskunft liegt im Speicher für die Seitenleiste.
     */
    public function testASuccessfulEstimateIsRenderedAndRemembered(): void
    {
        $store = $this->createMock(LastShippingEstimateStore::class);
        $store->expects($this->once())->method('remember');

        $controller = $this->controller($store);

        $request = new Request([], ['countryIso' => 'DE', 'zipCode' => '44135']);
        $response = $controller->estimate($request, $this->context());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Standard', (string) $response->getContent());
    }

    /**
     * Was: Eine unbrauchbare Eingabe.
     * Warum: Der Fehlerweg rendert dieselbe Vorlage. Er darf nichts merken — sonst stünde in
     *        der Seitenleiste eine Auskunft, die nie berechnet wurde.
     */
    public function testAnInvalidInputRendersTheErrorAndRemembersNothing(): void
    {
        $store = $this->createMock(LastShippingEstimateStore::class);
        $store->expects($this->never())->method('remember');

        $controller = $this->controller($store);

        $request = new Request([], ['countryIso' => '', 'zipCode' => '']);
        $response = $controller->estimate($request, $this->context());

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Was: Ein angemeldeter Kunde bekommt eine Auskunft statt einer Absage.
     * Warum: **Bewusste Umkehr.** Bis 1.8.1 wies der Endpunkt Angemeldete ab. Wird der
     *        Rechner ihnen im Warenkorb angeboten, muss er auch antworten — sonst wäre die
     *        Schaltfläche sichtbar und die Anfrage abgelehnt.
     */
    public function testSignedInCustomersGetAnAnswerInsteadOfARejection(): void
    {
        $controller = $this->controller($this->createMock(LastShippingEstimateStore::class));

        $request = new Request([], ['countryIso' => 'DE', 'zipCode' => '44135']);
        $response = $controller->estimate($request, $this->context(angemeldet: true));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Standard', (string) $response->getContent());
    }

    private function controller(LastShippingEstimateStore $store): ShippingEstimateController
    {
        $configService = $this->createMock(ConfigService::class);
        $configService->method('isShippingEstimatorEnabled')->willReturn(true);

        $estimateService = $this->createMock(ShippingEstimateService::class);
        $estimateService->method('estimate')->willReturn(
            ShippingEstimateResult::withShippingMethods(
                [new ShippingEstimate('sm-1', 'Standard', 4.95, 'EUR')],
                'DE',
                '44135',
            ),
        );

        $cart = new Cart('token');
        $cart->add(new LineItem('li-1', LineItem::PRODUCT_LINE_ITEM_TYPE, 'ref-1', 1));

        $cartService = $this->createMock(CartService::class);
        $cartService->method('getCart')->willReturn($cart);

        $controller = new ShippingEstimateController(
            $estimateService,
            new EstimateInputValidator(),
            $cartService,
            $configService,
            $this->createMock(RateLimiter::class),
            $store,
            new CartFingerprint(),
        );

        $controller->setContainer($this->container());

        return $controller;
    }

    private function container(): ContainerInterface
    {
        $salesChannelContext = $this->context();

        $request = new Request();
        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT, $salesChannelContext);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        // Eine Vorlage, die genug tut, um den Weg zu belegen: Sie gibt aus, was der
        // Controller ihr reicht. Die echte Vorlage zu laden hieße, Twig-Erweiterungen der
        // Storefront mitzuschleppen — geprüft wird hier der Controller, nicht das Rendern.
        $twig = new Environment(new ArrayLoader([
            self::TEMPLATE => '{% if rcEstimate is defined %}{% for e in rcEstimate.estimates %}{{ e.name }}{% endfor %}{% endif %}',
        ]));

        // `renderStorefront()` geht über den Template-Finder der Storefront. Er löst
        // Vererbungsketten über Bundles auf — hier gibt es nur eine Vorlage, also reicht
        // die Kennung unverändert zurück.
        $templateFinder = $this->createMock(TemplateFinder::class);
        $templateFinder->method('find')->willReturnArgument(0);

        $services = [
            'request_stack' => $requestStack,
            'event_dispatcher' => new EventDispatcher(),
            'twig' => $twig,
            SystemConfigService::class => $this->createMock(SystemConfigService::class),
            TemplateFinder::class => $templateFinder,
            // Nach dem Rendern ersetzt die Storefront noch Platzhalter für Medien- und
            // SEO-Adressen. Beide geben den Inhalt hier unverändert zurück — was sie tun,
            // ist nicht Gegenstand dieses Tests.
            MediaUrlPlaceholderHandlerInterface::class => $this->passThroughReplacer(),
            SeoUrlPlaceholderHandlerInterface::class => $this->passThroughReplacer(),
        ];

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn (string $id): bool => \array_key_exists($id, $services),
        );
        $container->method('get')->willReturnCallback(
            static fn (string $id): mixed => $services[$id] ?? null,
        );

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

    private function context(bool $angemeldet = false): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-id');
        $context->method('getCustomer')->willReturn($angemeldet ? new CustomerEntity() : null);
        $context->method('getToken')->willReturn('token');

        return $context;
    }
}
