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
 * Der Endpunkt ist ohne Anmeldung erreichbar und löst je Aufruf eine
 * Warenkorb-Berechnung je verfügbarer Versandart aus. Ohne Begrenzung ließe sich der
 * Shop mit einer Schleife lahmlegen; deshalb der Rate-Limiter und die harte
 * Längengrenze auf der Postleitzahl.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
final class ShippingEstimateController extends StorefrontController
{
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
            EstimateEndpoints::LIMIT_ESTIMATE,
            (string) $request->getClientIp(),
        );

        $countryIso = strtoupper(trim((string) $request->request->get('countryIso', '')));
        $zipCode = trim((string) $request->request->get('zipCode', ''));

        $error = $this->inputValidator->validate($countryIso, $zipCode);
        if ($error !== null) {
            return $this->renderStorefront(
                EstimateEndpoints::RESULT_TEMPLATE,
                ['rcEstimateError' => $error],
            );
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);
        $result = $this->estimateService->estimate($cart, $context, $countryIso, $zipCode);

        // Zusammen mit dem Fingerabdruck des Warenkorbs, für den sie gilt. Die
        // Seitenleiste zeigt die Auskunft nur, solange er stimmt; sonst stünde dort
        // nach der nächsten Mengenänderung eine Zahl, die der Shop nicht hält.
        $this->lastEstimateStore->remember($result, $this->cartFingerprint->of($cart), $this->configService->getNotShippingMethodIds($context->getSalesChannelId()));

        // Abholung und Platzhalter sind kein Versand und gehören nicht in „Versandkosten berechnen"; mit 0,00 € stünden
        // sie dort als günstigste Lieferung.
        return $this->renderStorefront(
            EstimateEndpoints::RESULT_TEMPLATE,
            ['rcEstimate' => $result->without($this->configService->getNotShippingMethodIds($context->getSalesChannelId()))],
        );
    }
}
