<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\CartFingerprint;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\EstimateInputValidator;
use Ruhrcoder\RcCheckoutEnhancer\Service\LastShippingEstimateStore;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingEstimateService;
use Ruhrcoder\RcCheckoutEnhancer\Storefront\Controller\ShippingEstimateController;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\RateLimiter\RateLimiter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Die beiden Riegel vor dem Rechner-Endpunkt.
 *
 * Sie sind kein Beiwerk: Der Endpunkt ist ohne Anmeldung erreichbar und löst pro Aufruf so
 * viele Warenkorb-Berechnungen aus, wie es Versandarten gibt. Wer ihn offen lässt, wo er
 * abgeschaltet sein soll, verschenkt genau die Grenze, die ihn schützt.
 *
 * Geprüft wird ausschließlich, was **vor** dem Rendern entschieden wird — dafür braucht es
 * keinen Container. Der Weg danach läuft im Smoke-Gate gegen echte Anfragen.
 */
final class ShippingEstimateControllerTest extends TestCase
{
    /**
     * Warum: Der Rechner ist im Auslieferungszustand aus. Ein abgeschalteter Rechner, den
     *        man per Anfrage trotzdem erreicht, wäre ein Schalter ohne Wirkung.
     */
    public function testASwitchedOffEstimatorIsNotReachable(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller(enabled: false)->estimate(new Request(), $this->context());
    }

    private function controller(bool $enabled = true): ShippingEstimateController
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('isShippingEstimatorEnabled')->willReturn($enabled);

        return new ShippingEstimateController(
            $this->createMock(ShippingEstimateService::class),
            new EstimateInputValidator(),
            $this->createMock(CartService::class),
            $config,
            $this->createMock(RateLimiter::class),
            $this->createMock(LastShippingEstimateStore::class),
            new CartFingerprint(),
        );
    }

    private function context(bool $loggedIn = false): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-id');
        $context->method('getCustomer')->willReturn($loggedIn ? new CustomerEntity() : null);

        return $context;
    }
}
