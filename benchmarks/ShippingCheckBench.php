<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Benchmarks;

use PhpBench\Attributes as Bench;
use Ruhrcoder\RcCheckoutEnhancer\Benchmarks\Support\ShopwareFixture;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingEstimateService;
use RuntimeException;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartRuleLoader;
use Shopware\Core\Checkout\Shipping\Cart\Error\ShippingMethodBlockedError;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Die Lieferbarkeits-Prüfung des Versandkostenfrei-Hinweises. Sie läuft bei jedem Aufruf von
 * Warenkorb und Warenkorb-Leiste mit.
 *
 * Zwei Wege: Trägt der Warenkorb eine bepreiste Lieferung, wie nach der Vorauswahl auf der Seite,
 * steht die Antwort schon in ihm. Ist die gewählte Versandart gesperrt, rechnet die Prüfung über
 * alle verfügbaren Versandarten. Daneben eine einzelne Neuberechnung als Maßstab.
 */
#[Bench\Revs(10)]
#[Bench\Iterations(5)]
#[Bench\Warmup(1)]
final class ShippingCheckBench
{
    private ShippingEstimateService $estimateService;

    private SalesChannelContext $context;

    private Cart $cart;

    public function setUpPricedDelivery(): void
    {
        $this->setUpWith(ShopwareFixture::FREIGHT_METHOD, blocked: false);
    }

    public function setUpBlockedDelivery(): void
    {
        $this->setUpWith(ShopwareFixture::PARCEL_METHOD, blocked: true);
    }

    #[Bench\BeforeMethods('setUpPricedDelivery')]
    public function benchCanShipWithPricedDelivery(): void
    {
        $this->estimateService->canShipToContextLocation($this->cart, $this->context);
    }

    #[Bench\BeforeMethods('setUpBlockedDelivery')]
    public function benchCanShipWithBlockedDelivery(): void
    {
        $this->estimateService->canShipToContextLocation($this->cart, $this->context);
    }

    #[Bench\BeforeMethods('setUpPricedDelivery')]
    public function benchSingleCartRecalculation(): void
    {
        ShopwareFixture::service(CartRuleLoader::class)->loadByCart(
            $this->context,
            clone $this->cart,
            new CartBehavior($this->context->getPermissions()),
            true,
        );
    }

    /**
     * Prüft, dass der Messwarenkorb den Weg nimmt, den die Messung zu messen behauptet, und dass
     * die Antwort „lieferbar" lautet; sonst bricht der Lauf ab.
     */
    private function setUpWith(string $shippingMethodName, bool $blocked): void
    {
        $this->estimateService = ShopwareFixture::service(ShippingEstimateService::class);
        $this->context = ShopwareFixture::guestContext($shippingMethodName);
        $this->cart = ShopwareFixture::calculatedCart($this->context);

        if ($this->cart->getErrors()->filterInstance(ShippingMethodBlockedError::class)->count() > 0 !== $blocked) {
            throw new RuntimeException(\sprintf('„%s" ist für den Messwarenkorb %s gesperrt; die Messung träfe den falschen Weg.', $shippingMethodName, $blocked ? 'nicht' : ''));
        }

        if (!$this->estimateService->canShipToContextLocation($this->cart, $this->context)) {
            throw new RuntimeException('Der Messwarenkorb gilt als nicht lieferbar; gemessen würde der Weg ohne Treffer.');
        }
    }
}
