<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\EstimateInputValidator;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreightHintService;
use Ruhrcoder\RcCheckoutEnhancer\Storefront\Controller\FreightHintController;
use Shopware\Core\Framework\RateLimiter\RateLimiter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Die beiden Endpunkte des Speditionshinweises. Geprüft wird, was vor dem Rendern entschieden wird:
 * Schalter, Begrenzung und die leere Antwort für Paketware. Das Rendern selbst prüft der Test des
 * Versandkostenrechners, der dieselbe Ergebnis-Vorlage nutzt.
 */
final class FreightHintControllerTest extends TestCase
{
    private const PRODUCT = '0189b6b5e5c47dbfa5bb5d0a8a4e2f11';

    public function testWhenSwitchedOffTheHintDoesNotExist(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller(enabled: false)->hint(self::PRODUCT, new Request(), $this->context());
    }

    public function testWhenSwitchedOffTheEstimateDoesNotExist(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller(enabled: false)->estimate(self::PRODUCT, new Request(), $this->context());
    }

    /**
     * Was: Ein Artikel, der per Paket geht.
     * Warum: Kein Hinweis ist eine gültige Antwort, kein Fehler. Die Produktseite lässt den Platz leer.
     */
    public function testParcelGoodsGetAnEmptyAnswer(): void
    {
        $response = $this->controller()->hint(self::PRODUCT, new Request(), $this->context());

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    /**
     * Was: Ein Aufruf von einer Adresse.
     * Warum: Der Endpunkt ist ohne Anmeldung erreichbar und rechnet je Aufruf mehrere Warenkörbe;
     *        die Begrenzung bremst den einzelnen Absender, nicht alle Besucher gemeinsam.
     */
    public function testTheRateLimitIsKeyedByTheCallersAddress(): void
    {
        $rateLimiter = $this->createMock(RateLimiter::class);
        $rateLimiter->expects(self::once())->method('ensureAccepted')->with('rc_checkout_freight_hint', '203.0.113.7');

        $this->controller(rateLimiter: $rateLimiter)->hint(self::PRODUCT, Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.7']), $this->context());
    }

    private function controller(bool $enabled = true, ?RateLimiter $rateLimiter = null): FreightHintController
    {
        $configService = $this->createMock(ConfigService::class);
        $configService->method('isFreightHintEnabled')->willReturn($enabled);

        $service = $this->createMock(FreightHintService::class);
        $service->method('forProduct')->willReturn(null);

        return new FreightHintController(
            $service,
            new EstimateInputValidator(),
            $configService,
            $rateLimiter ?? $this->createMock(RateLimiter::class),
        );
    }

    private function context(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-id');

        return $context;
    }
}
