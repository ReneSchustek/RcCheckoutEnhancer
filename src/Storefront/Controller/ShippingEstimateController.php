<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Storefront\Controller;

use Ruhrcoder\RcCheckoutEnhancer\Service\CartFingerprint;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\EstimateInputValidator;
use Ruhrcoder\RcCheckoutEnhancer\Service\LastShippingEstimateStore;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingEstimateService;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\RateLimiter\RateLimiter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Nimmt Land und Postleitzahl entgegen und gibt die Versandkosten je Versandart
 * als gerendertes Teilstück zurück.
 *
 * Der Endpunkt ist ohne Anmeldung erreichbar und löst pro Aufruf so viele
 * Warenkorb-Berechnungen aus, wie es Versandarten gibt. Ohne Begrenzung wäre das
 * eine offene Einladung, den Shop mit einer Schleife lahmzulegen — deshalb der
 * Rate-Limiter und die harte Längengrenze auf der Postleitzahl.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class ShippingEstimateController extends StorefrontController
{
    private const RATE_LIMIT_ROUTE = 'rc_checkout_shipping_estimate';

    public function __construct(
        private readonly ShippingEstimateService $estimateService,
        private readonly EstimateInputValidator $inputValidator,
        private readonly CartService $cartService,
        private readonly ConfigService $configService,
        private readonly RateLimiter $rateLimiter,
        private readonly LastShippingEstimateStore $lastEstimateStore,
        private readonly CartFingerprint $cartFingerprint,
    ) {
    }

    #[Route(
        path: '/rc-checkout/shipping-estimate',
        name: 'frontend.rc-checkout.shipping-estimate',
        defaults: ['XmlHttpRequest' => true],
        methods: ['POST'],
    )]
    public function estimate(Request $request, SalesChannelContext $context): Response
    {
        if (!$this->configService->isShippingEstimatorEnabled($context->getSalesChannelId())) {
            throw new NotFoundHttpException('Der Versandkostenrechner ist nicht aktiv.');
        }

        // Schlüssel ist die aufrufende Adresse: die Begrenzung soll den einzelnen
        // Absender bremsen, nicht alle Besucher gemeinsam.
        $this->rateLimiter->ensureAccepted(
            self::RATE_LIMIT_ROUTE,
            (string) $request->getClientIp(),
        );

        $countryIso = strtoupper(trim((string) $request->request->get('countryIso', '')));
        $zipCode = trim((string) $request->request->get('zipCode', ''));

        $error = $this->inputValidator->validate($countryIso, $zipCode);
        if ($error !== null) {
            return $this->renderStorefront(
                '@Storefront/storefront/component/rc-checkout/shipping-estimate-result.html.twig',
                ['rcEstimateError' => $error],
            );
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);
        $result = $this->estimateService->estimate($cart, $context, $countryIso, $zipCode);

        // Zusammen mit dem Fingerabdruck des Warenkorbs, für den sie gilt. Die
        // Seitenleiste zeigt die Auskunft nur, solange er stimmt — sonst stünde dort
        // nach der nächsten Mengenänderung eine Zahl, die der Shop nicht hält.
        $this->lastEstimateStore->remember($result, $this->cartFingerprint->of($cart));

        return $this->renderStorefront(
            '@Storefront/storefront/component/rc-checkout/shipping-estimate-result.html.twig',
            ['rcEstimate' => $result],
        );
    }
}
