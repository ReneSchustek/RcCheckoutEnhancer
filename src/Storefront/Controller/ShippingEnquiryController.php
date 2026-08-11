<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Storefront\Controller;

use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingEnquiryStore;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingEnquirySummary;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Übernimmt den Warenkorb in die Sitzung und schickt den Kunden zum Kontaktformular.
 *
 * Bewusst **ohne Storefront-Javascript**: Die Schaltfläche ist ein gewöhnliches Formular,
 * das hierher absendet. Ein Skript hätte denselben Weg mit mehr Teilen abgebildet — und
 * ohne Skript funktioniert der Weg auch dann, wenn am Javascript etwas klemmt. Gerade
 * dieser Weg ist der letzte, der einem Kunden bleibt, bevor er abbricht.
 *
 * **POST und nicht GET:** Der Aufruf legt etwas in der Sitzung ab. Ein Verweis, den ein
 * Vorlade-Mechanismus des Browsers versehentlich abruft, soll das nicht auslösen.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class ShippingEnquiryController extends StorefrontController
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly CartService $cartService,
        private readonly ShippingEnquirySummary $summary,
        private readonly ShippingEnquiryStore $enquiryStore,
    ) {
    }

    #[Route(
        path: '/rc-checkout/shipping-enquiry',
        name: 'frontend.rc-checkout.shipping-enquiry',
        methods: ['POST'],
    )]
    public function handOver(SalesChannelContext $context): Response
    {
        $salesChannelId = $context->getSalesChannelId();

        if (!$this->configService->isShippingEnquiryEnabled($salesChannelId)) {
            throw new NotFoundHttpException('Der Anfrageweg ist nicht aktiv.');
        }

        $categoryId = $this->configService->getShippingEnquiryCategoryId($salesChannelId);
        if ($categoryId === null) {
            throw new NotFoundHttpException('Für den Anfrageweg ist keine Zielseite eingestellt.');
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);

        // Ein leerer Warenkorb ergibt keine Anfrage. Der Kunde landet trotzdem auf dem
        // Formular — dort kann er schreiben, was er braucht.
        $summary = $this->summary->forCart($cart, $context);
        if ($summary !== '') {
            $this->enquiryStore->remember($summary);
        }

        return $this->redirectToRoute('frontend.navigation.page', ['navigationId' => $categoryId]);
    }
}
