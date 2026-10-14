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
 * Liefert den Speditionshinweis der Produktseite nach, dazu die Auskunft für eine eigene
 * Postleitzahl.
 *
 * Nachgeladen statt mitgerendert, weil die Spanne beim ersten Aufruf je Zone eine Handvoll
 * Warenkorb-Berechnungen kostet. Im Seitenaufbau hielte das die ganze Produktseite auf; so
 * erscheint die Seite sofort, und der Kasten kommt dazu, sobald er feststeht.
 *
 * Beide Wege sind ohne Anmeldung erreichbar und lösen Berechnungen aus, deshalb sind sie
 * begrenzt. Der Hinweis hat eine eigene, großzügigere Staffel: Er läuft bei jedem
 * Produktaufruf, der Rechner nur auf Klick.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class FreightHintController extends StorefrontController
{
    private const RATE_LIMIT_HINT = 'rc_checkout_freight_hint';

    /**
     * Derselbe Name wie beim Rechner im Warenkorb: Beide rechnen dasselbe und zählen gegen eine
     * gemeinsame Grenze, sonst verdoppelte der zweite Endpunkt das Kontingent.
     */
    private const RATE_LIMIT_ESTIMATE = 'rc_checkout_shipping_estimate';

    public function __construct(
        private readonly FreightHintService $freightHintService,
        private readonly EstimateInputValidator $inputValidator,
        private readonly ConfigService $configService,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    #[Route(
        path: '/rc-checkout/freight-hint/{productId}',
        name: 'frontend.rc-checkout.freight-hint',
        requirements: ['productId' => '[0-9a-f]{32}'],
        defaults: ['XmlHttpRequest' => true],
        methods: ['GET'],
    )]
    public function hint(string $productId, Request $request, SalesChannelContext $context): Response
    {
        $this->ensureEnabled($context);
        $this->rateLimiter->ensureAccepted(self::RATE_LIMIT_HINT, (string) $request->getClientIp());

        $hint = $this->freightHintService->forProduct($productId, $context);

        // Kein Hinweis ist eine gültige Antwort, kein Fehler: Die meisten Artikel gehen per Paket.
        if ($hint === null) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return $this->renderStorefront(
            '@Storefront/storefront/component/rc-checkout/freight-hint.html.twig',
            ['rcFreightHint' => $hint, 'rcFreightProductId' => $productId],
        );
    }

    #[Route(
        path: '/rc-checkout/freight-hint/{productId}/estimate',
        name: 'frontend.rc-checkout.freight-hint.estimate',
        requirements: ['productId' => '[0-9a-f]{32}'],
        defaults: ['XmlHttpRequest' => true],
        methods: ['POST'],
    )]
    public function estimate(string $productId, Request $request, SalesChannelContext $context): Response
    {
        $this->ensureEnabled($context);
        $this->rateLimiter->ensureAccepted(self::RATE_LIMIT_ESTIMATE, (string) $request->getClientIp());

        $countryIso = (string) $context->getShippingLocation()->getCountry()->getIso();
        $zipCode = trim((string) $request->request->get('zipCode', ''));

        $error = $this->inputValidator->validate($countryIso, $zipCode);
        if ($error !== null) {
            return $this->renderStorefront(
                '@Storefront/storefront/component/rc-checkout/shipping-estimate-result.html.twig',
                ['rcEstimateError' => $error],
            );
        }

        return $this->renderStorefront(
            '@Storefront/storefront/component/rc-checkout/shipping-estimate-result.html.twig',
            ['rcEstimate' => $this->freightHintService->estimateForZip($productId, $context, $zipCode)],
        );
    }

    private function ensureEnabled(SalesChannelContext $context): void
    {
        if (!$this->configService->isFreightHintEnabled($context->getSalesChannelId())) {
            throw new NotFoundHttpException('Der Speditionshinweis ist nicht aktiv.');
        }
    }
}
