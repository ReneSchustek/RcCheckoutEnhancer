<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Ruhrcoder\RcCheckoutEnhancer\Struct\OnePageSelection;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Gateway\SalesChannel\AbstractCheckoutGatewayRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lädt Versandarten, Zahlarten und Warenkorb für den einseitigen Checkout.
 *
 * Die Methoden kommen aus der Checkout-Gateway-Route des Kerns, derselben Quelle wie auf der
 * Warenkorbseite. Die Vorauswahl läuft mit, sonst stünde unter dem Adressformular wieder eine
 * Abholung angehakt, die niemand gewählt hat.
 */
final class OnePageSelectionLoader
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly AbstractCheckoutGatewayRoute $gatewayRoute,
        private readonly ShippingMethodPreselectionApplier $applier,
        private readonly ShippingPlaceholderFilter $placeholderFilter,
    ) {
    }

    /**
     * @param Cart|null $cart der Warenkorb der Seite, wenn sie ihn schon geladen hat
     */
    public function load(Request $request, SalesChannelContext $context, ?Cart $cart = null): OnePageSelection
    {
        $cart ??= $this->cartService->getCart($context->getToken(), $context);
        $gatewayRequest = $request->duplicate(['onlyAvailable' => true]);

        $methods = $this->gatewayRoute->load($gatewayRequest, $cart, $context);
        $switched = $this->applier->apply($methods->getShippingMethods(), $context);

        // Nach dem Umschalten können andere Zahlarten verfügbar sein, weil ihre Regeln am
        // Warenkorb hängen. Die Liste von vorhin stimmt dann nicht mehr.
        if ($switched !== null) {
            $cart = $switched;
            $methods = $this->gatewayRoute->load($gatewayRequest, $cart, $context);
        }

        return new OnePageSelection(
            $this->placeholderFilter->forDisplay($methods->getShippingMethods(), $context),
            $methods->getPaymentMethods(),
            $cart,
        );
    }
}
