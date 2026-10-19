<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\EstimateInputValidator;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreightHintService;
use Ruhrcoder\RcCheckoutEnhancer\Storefront\Controller\ProductShippingEstimateController;
use Shopware\Core\Framework\RateLimiter\RateLimiter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Der Endpunkt des Rechners auf der Produktseite. Geprüft wird, was vor dem Rendern entschieden wird; das Rendern
 * prüft der Test des Warenkorb-Rechners, der dieselbe Ergebnis-Vorlage nutzt.
 */
#[CoversClass(ProductShippingEstimateController::class)]
final class ProductShippingEstimateControllerTest extends TestCase
{
    private const PRODUCT = '0189b6b5e5c47dbfa5bb5d0a8a4e2f11';

    public function testWhenSwitchedOffTheEndpointDoesNotExist(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller(enabled: false)->estimate(self::PRODUCT, new Request(), $this->context());
    }

    /**
     * Was: Ein Aufruf beim Laden der Seite (`auto`) und einer nach einem Klick.
     * Warum: Rechnungen ohne Klick laufen auf jeder Produktseite mit gemerkter Anschrift; sie zählen in der großzügigeren
     *        Staffel, damit ein Besucher, der durch den Katalog blättert, nicht in die Begrenzung läuft.
     * Erwartet: zwei verschiedene Begrenzungen, beide je Absenderadresse.
     */
    public function testAutomaticAndClickedEstimatesUseTheirOwnLimit(): void
    {
        $calls = [];
        $rateLimiter = $this->createMock(RateLimiter::class);
        $rateLimiter->method('ensureAccepted')->willReturnCallback(static function (string $route, string $key) use (&$calls): void {
            $calls[] = [$route, $key];
            // Danach würde gerendert; der Test endet an der Begrenzung.
            throw new \RuntimeException('Ende des Tests');
        });

        foreach (['1', ''] as $auto) {
            try {
                $this->controller(rateLimiter: $rateLimiter)->estimate(
                    self::PRODUCT,
                    Request::create('/', 'POST', ['auto' => $auto], [], [], ['REMOTE_ADDR' => '203.0.113.7']),
                    $this->context(),
                );
            } catch (\RuntimeException) {
            }
        }

        self::assertSame([['rc_checkout_freight_hint', '203.0.113.7'], ['rc_checkout_shipping_estimate', '203.0.113.7']], $calls);
    }

    private function controller(bool $enabled = true, ?RateLimiter $rateLimiter = null): ProductShippingEstimateController
    {
        $configService = $this->createStub(ConfigService::class);
        $configService->method('isProductShippingEstimateEnabled')->willReturn($enabled);

        return new ProductShippingEstimateController(
            $this->createStub(FreightHintService::class),
            new EstimateInputValidator(),
            $configService,
            $rateLimiter ?? $this->createStub(RateLimiter::class),
        );
    }

    private function context(): SalesChannelContext
    {
        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-id');

        return $context;
    }
}
