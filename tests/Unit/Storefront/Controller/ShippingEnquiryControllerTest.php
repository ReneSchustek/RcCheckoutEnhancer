<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingEnquiryStore;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingEnquirySummary;
use Ruhrcoder\RcCheckoutEnhancer\Storefront\Controller\ShippingEnquiryController;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Der Weg vom Bestellvorgang zum Kontaktformular.
 *
 * Der Kunde steht auf diesem Weg vor einer Sackgasse: Er hat eine Ware im Korb, die der Shop nicht versenden kann. Landet er auf einer
 * Seite ohne Formular oder ohne seinen Warenkorb, hat er zweimal umsonst getippt. Deshalb sind
 * die beiden Abbruchfälle hier genauso festgehalten wie der Normalfall.
 */
final class ShippingEnquiryControllerTest extends TestCase
{
    private const KATEGORIE = 'kategorie-kontakt';

    /**
     * Was: Der Anfrageweg ist abgeschaltet.
     * Warum: Dann gibt es diese Adresse nicht — sie darf auch nicht ins Leere weiterleiten.
     */
    public function testItRefusesWhenTheEnquiryPathIsSwitchedOff(): void
    {
        $controller = $this->controller(enabled: false);

        $this->expectException(NotFoundHttpException::class);

        $controller->handOver($this->context());
    }

    /**
     * Was: Keine Zielseite eingestellt.
     * Warum: Der wichtigere der beiden Abbrüche. Ohne Zielseite gibt es kein Formular; eine
     *        Weiterleitung führte auf eine leere Seite, und der Warenkorb wäre trotzdem in der
     *        Sitzung gelandet.
     */
    public function testItRefusesWhenNoTargetPageIsConfigured(): void
    {
        $controller = $this->controller(categoryId: null);

        $this->expectException(NotFoundHttpException::class);

        $controller->handOver($this->context());
    }

    /**
     * Was: Der Normalfall.
     * Warum: Zusammenfassung merken und weiterleiten gehören zusammen; eines allein lässt den
     *        Kunden ohne Formular oder ohne seinen Warenkorb stehen.
     */
    public function testItRemembersTheCartAndRedirectsToTheForm(): void
    {
        $store = $this->createMock(ShippingEnquiryStore::class);
        $store->expects(self::once())->method('remember')->with('2 × Handlauf');

        $antwort = $this->controller(summary: '2 × Handlauf', store: $store)->handOver($this->context());

        self::assertInstanceOf(RedirectResponse::class, $antwort);
        self::assertSame('/kontakt', $antwort->getTargetUrl());
    }

    /**
     * Was: Ein Warenkorb, aus dem sich keine Zusammenfassung bilden lässt.
     * Warum: Der Kunde soll trotzdem auf dem Formular landen — dort kann er schreiben, was er
     *        braucht. Gemerkt wird aber nichts, sonst stünde später ein leerer Kasten dort.
     */
    public function testItRedirectsWithoutRememberingAnEmptySummary(): void
    {
        $store = $this->createMock(ShippingEnquiryStore::class);
        $store->expects(self::never())->method('remember');

        $antwort = $this->controller(summary: '', store: $store)->handOver($this->context());

        self::assertInstanceOf(RedirectResponse::class, $antwort);
    }

    private function controller(
        bool $enabled = true,
        ?string $categoryId = self::KATEGORIE,
        string $summary = 'Warenkorb',
        ?ShippingEnquiryStore $store = null,
    ): ShippingEnquiryController {
        $configService = $this->createMock(ConfigService::class);
        $configService->method('isShippingEnquiryEnabled')->willReturn($enabled);
        $configService->method('getShippingEnquiryCategoryId')->willReturn($categoryId);

        $cartService = $this->createMock(CartService::class);
        $cartService->method('getCart')->willReturn(new Cart('token'));

        $summaryService = $this->createMock(ShippingEnquirySummary::class);
        $summaryService->method('forCart')->willReturn($summary);

        $controller = new ShippingEnquiryController(
            $configService,
            $cartService,
            $summaryService,
            $store ?? $this->createMock(ShippingEnquiryStore::class),
        );

        $controller->setContainer($this->container());

        return $controller;
    }

    private function container(): Container
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/kontakt');

        $container = new Container();
        $container->set('router', $router);
        // Der StorefrontController meldet jede Weiterleitung als Ereignis. Ohne Verteiler
        // bricht `redirectToRoute()` ab, bevor die Antwort entsteht.
        $container->set('event_dispatcher', new EventDispatcher());

        return $container;
    }

    private function context(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-id');
        $context->method('getToken')->willReturn('token');

        return $context;
    }
}
