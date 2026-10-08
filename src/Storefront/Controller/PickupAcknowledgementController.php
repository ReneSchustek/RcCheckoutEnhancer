<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Storefront\Controller;

use Ruhrcoder\RcCheckoutEnhancer\Checkout\ShippingEnquiryRule;
use Ruhrcoder\RcCheckoutEnhancer\Service\PickupAcknowledgementStore;
use Ruhrcoder\RcCheckoutEnhancer\Service\PickupHintDecider;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Gateway\SalesChannel\AbstractCheckoutGatewayRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Nimmt die Bestätigung des Abhol-Hinweises entgegen.
 *
 * Der Aufruf trägt keine Nutzlast. Was bestätigt wird, bestimmt allein der Server über den
 * {@see PickupHintDecider}, dieselbe Bedingung, die den Dialog zeigt und die Bestellung sperrt.
 * Nähme der Endpunkt einen Text entgegen, könnte jeder eine beliebige Zeichenkette als
 * „bestätigt" hinterlegen, und der Nachweis an der Bestellung wäre wertlos.
 *
 * Ist gar kein Hinweis fällig, lehnt er ab; sonst ließe sich eine Bestätigung auf Vorrat
 * setzen, die später auf einen anderen Warenkorb passt.
 *
 * Er lehnt auch ab, wenn statt der Bestellung die Anfrage gilt ({@see ShippingEnquiryRule}). Die
 * Bestätigungsseite zeigt in diesem Fall keinen Dialog; wer den Endpunkt trotzdem direkt aufruft,
 * soll die Abholung nicht bestätigen und damit am Anfrageweg vorbei bestellen können.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class PickupAcknowledgementController extends StorefrontController
{
    public function __construct(
        private readonly PickupHintDecider $decider,
        private readonly PickupAcknowledgementStore $store,
        private readonly CartService $cartService,
        private readonly AbstractCheckoutGatewayRoute $gatewayRoute,
        private readonly ShippingEnquiryRule $enquiryRule,
    ) {
    }

    #[Route(
        path: '/rc-checkout/pickup-acknowledgement',
        name: 'frontend.rc-checkout.pickup-acknowledgement',
        defaults: ['XmlHttpRequest' => true],
        methods: ['POST'],
    )]
    public function acknowledge(Request $request, SalesChannelContext $context): JsonResponse
    {
        $cart = $this->cartService->getCart($context->getToken(), $context);

        $hint = $this->decider->hintFor($cart, $context);
        if ($hint === null) {
            return new JsonResponse(
                ['acknowledged' => false, 'reason' => 'not-applicable'],
                Response::HTTP_CONFLICT,
            );
        }

        $available = $this->gatewayRoute
            ->load($request->duplicate(['onlyAvailable' => true]), $cart, $context)
            ->getShippingMethods();

        if ($this->enquiryRule->applies($available, $context->getSalesChannelId())) {
            return new JsonResponse(
                ['acknowledged' => false, 'reason' => 'enquiry-required'],
                Response::HTTP_CONFLICT,
            );
        }

        // UTC, weil der Zeitstempel ein Nachweis ist und womöglich Monate später gelesen wird;
        // dann darf nicht die Zeitzone des Servers entscheiden, was er bedeutet.
        $at = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if (!$this->store->acknowledge($hint, $at)) {
            // Ohne Sitzung ließe sich die Bestätigung weder prüfen noch an die Bestellung
            // hängen. Ein gemeldeter Erfolg, den es nicht gibt, ließe den Kunden weiterklicken und
            // in die Sperre laufen, ohne zu verstehen warum.
            return new JsonResponse(
                ['acknowledged' => false, 'reason' => 'no-session'],
                Response::HTTP_CONFLICT,
            );
        }

        return new JsonResponse(['acknowledged' => true]);
    }
}
