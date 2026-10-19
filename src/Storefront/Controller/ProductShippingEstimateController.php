<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Storefront\Controller;

use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\EstimateInputValidator;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreightHintService;
use Shopware\Core\Framework\RateLimiter\RateLimiter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Der Rechner „Versand für dieses Produkt berechnen" der Produktseite.
 *
 * Zwei Arten von Aufrufen mit getrennten Staffeln: Auf Klick gilt die Staffel des Warenkorb-Rechners. Hat der Browser
 * Land und Postleitzahl schon gemerkt, rechnet das Skript auf jeder Produktseite von selbst (`auto=1`); dafür gilt die
 * großzügigere Staffel des Speditionshinweises, der ebenfalls bei jedem Produktaufruf mitläuft.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
final class ProductShippingEstimateController extends StorefrontController
{
    public function __construct(
        private readonly FreightHintService $freightHintService,
        private readonly EstimateInputValidator $inputValidator,
        private readonly ConfigService $configService,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    #[Route(
        path: '/rc-checkout/product-shipping-estimate/{productId}',
        name: 'frontend.rc-checkout.product-shipping-estimate',
        requirements: ['productId' => '[0-9a-f]{32}'],
        defaults: ['XmlHttpRequest' => true],
        methods: ['POST'],
    )]
    public function estimate(string $productId, Request $request, SalesChannelContext $context): Response
    {
        if (!$this->configService->isProductShippingEstimateEnabled($context->getSalesChannelId())) {
            throw new NotFoundHttpException('Der Versandkostenrechner der Produktseite ist nicht aktiv.');
        }

        $this->rateLimiter->ensureAccepted(
            $request->request->getBoolean('auto') ? EstimateEndpoints::LIMIT_PAGE_LOAD : EstimateEndpoints::LIMIT_ESTIMATE,
            (string) $request->getClientIp(),
        );

        $countryIso = strtoupper(trim((string) $request->request->get('countryIso', '')));
        $zipCode = trim((string) $request->request->get('zipCode', ''));
        $quantity = max(0, $request->request->getInt('quantity'));

        $error = $this->inputValidator->validate($countryIso, $zipCode);
        if ($error !== null) {
            return $this->renderStorefront(
                EstimateEndpoints::RESULT_TEMPLATE,
                ['rcEstimateError' => $error],
            );
        }

        return $this->renderStorefront(
            EstimateEndpoints::RESULT_TEMPLATE,
            ['rcEstimate' => $this->freightHintService->estimateForProduct($productId, $context, $countryIso, $zipCode, $quantity)->without($this->configService->getNotShippingMethodIds($context->getSalesChannelId()))],
        );
    }
}
