<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Struct;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Framework\Struct\Struct;

/**
 * Was der einseitige Checkout unter dem Adressformular zeigt. Die Namen der Getter sind dieselben
 * wie die der Bestätigungsseite, damit die Bausteine des Kerns sie ohne Umbau lesen.
 */
final class OnePageSelection extends Struct
{
    public function __construct(
        private readonly ShippingMethodCollection $shippingMethods,
        private readonly PaymentMethodCollection $paymentMethods,
        private readonly Cart $cart,
    ) {
    }

    public function getShippingMethods(): ShippingMethodCollection
    {
        return $this->shippingMethods;
    }

    public function getPaymentMethods(): PaymentMethodCollection
    {
        return $this->paymentMethods;
    }

    public function getCart(): Cart
    {
        return $this->cart;
    }
}
