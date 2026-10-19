<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingChoiceStore;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingMethodPreselectionApplier;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingMethodPreselector;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Payment\Cart\Error\PaymentMethodBlockedError;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Checkout\Payment\SalesChannel\PaymentMethodRouteResponse;
use Shopware\Core\Checkout\Shipping\Cart\Error\ShippingMethodBlockedError;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\ContextTokenResponse;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Umschalten der Versandart und was danach nachgezogen werden muss: der Betrag und, wenn die neue
 * Versandart sie sperrt, die Zahlart.
 */
final class ShippingMethodPreselectionApplierTest extends TestCase
{
    private const ABHOLUNG = 'sm-abholung';

    private const PAKET = 'sm-paket';

    private const SPEDITION = 'sm-spedition';

    private const PLATZHALTER = 'sm-platzhalter';

    private const BAR = 'pm-bar';

    private const VORKASSE = 'pm-vorkasse';

    public function testItSwitchesAndReturnsTheRecalculatedCart(): void
    {
        $recalculated = new Cart('token');
        $switched = [];

        $result = $this->applier($switched, [$recalculated])->apply($this->listed(), $this->context());

        self::assertSame($recalculated, $result);
        self::assertSame([[SalesChannelContextService::SHIPPING_METHOD_ID => self::PAKET]], $switched);
    }

    /**
     * Was: Barzahlung ist nur bei Abholung erlaubt; die Vorauswahl schaltet von der Abholung auf das
     *      Paket.
     * Warum: Danach stünde eine gesperrte Zahlart im Kontext, und der Kunde sähe eine Sperre ohne
     *        passende Auswahl. Nachgezogen wird auf die Standard-Zahlart des Kanals.
     */
    public function testABlockedPaymentMethodIsReplacedByTheDefault(): void
    {
        $blocked = new Cart('token');
        $blocked->addErrors(new PaymentMethodBlockedError(self::BAR, 'nur bei Abholung'));
        $final = new Cart('token');
        $switched = [];

        $result = $this->applier($switched, [$blocked, $final])->apply($this->listed(), $this->context());

        self::assertSame($final, $result);
        self::assertSame([
            [SalesChannelContextService::SHIPPING_METHOD_ID => self::PAKET],
            [SalesChannelContextService::PAYMENT_METHOD_ID => self::VORKASSE],
        ], $switched);
    }

    /**
     * Was: Gast ohne Adresse, im Kontext der Platzhalter. Die Vorauswahl nimmt die Spedition, deren Preise an
     *      PLZ-Zonen hängen; der Warenkorb findet keinen Preis und meldet sie als gesperrt.
     * Warum: Im Warenkorb stünde sonst rot „nicht verfügbar" und 0,00 € Versand (BRIEF059).
     * Erwartet: Zurück auf den Platzhalter, sein Warenkorb ist das Ergebnis.
     */
    public function testABlockedMethodIsSkippedAndThePlaceholderStays(): void
    {
        $blocked = new Cart('token');
        $blocked->addErrors(new ShippingMethodBlockedError('Spedition', self::SPEDITION, 'no shipping costs found'));
        $placeholderCart = new Cart('token');
        $switched = [];

        $result = $this->applier($switched, [$blocked, $placeholderCart], placeholder: self::PLATZHALTER)
            ->apply($this->listed([self::PLATZHALTER => 1, self::SPEDITION => 5, self::ABHOLUNG => 200]), $this->context(self::PLATZHALTER));

        self::assertSame($placeholderCart, $result);
        self::assertSame([
            [SalesChannelContextService::SHIPPING_METHOD_ID => self::SPEDITION],
            [SalesChannelContextService::SHIPPING_METHOD_ID => self::PLATZHALTER],
        ], $switched);
    }

    /**
     * Was: Die eingestellte Spedition ist schon im gespeicherten Warenkorb gesperrt, der Paketversand hat einen Preis.
     * Warum: Eine gesperrte Versandart darf nicht stehen bleiben, nur weil die Regel sie zulässt.
     * Erwartet: Umschalten auf den Paketversand, ohne die Spedition noch einmal zu versuchen.
     */
    public function testABlockedCurrentMethodIsLeft(): void
    {
        $stored = new Cart('token');
        $stored->addErrors(new ShippingMethodBlockedError('Spedition', self::SPEDITION, 'no shipping costs found'));
        $paket = new Cart('token');
        $switched = [];

        $result = $this->applier($switched, [$paket])
            ->apply($this->listed([self::SPEDITION => 5, self::PAKET => 10, self::ABHOLUNG => 200]), $this->context(self::SPEDITION), false, $stored);

        self::assertSame($paket, $result);
        self::assertSame([[SalesChannelContextService::SHIPPING_METHOD_ID => self::PAKET]], $switched);
    }

    public function testWhenSwitchedOffNothingHappens(): void
    {
        $switched = [];

        self::assertNull($this->applier($switched, [], enabled: false)->apply($this->listed(), $this->context()));
        self::assertSame([], $switched);
    }

    /**
     * @param list<array<string, string>> $switched
     * @param list<Cart>                  $carts
     */
    private function applier(array &$switched, array $carts, bool $enabled = true, ?string $placeholder = null): ShippingMethodPreselectionApplier
    {
        $configService = $this->createMock(ConfigService::class);
        $configService->method('isShippingPreselectEnabled')->willReturn($enabled);
        $configService->method('getNonDeliveryMethodIds')->willReturn([self::ABHOLUNG]);
        $configService->method('getShippingPlaceholderMethodId')->willReturn($placeholder);

        $switchRoute = $this->recordingSwitchRoute($switched);

        $cartService = $this->createMock(CartService::class);
        $cartService->method('getCart')->willReturnOnConsecutiveCalls(...$carts);

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);

        $paymentRoute = $this->createMock(AbstractPaymentMethodRoute::class);
        $paymentRoute->method('load')->willReturn(new PaymentMethodRouteResponse(new EntitySearchResult(
            'payment_method',
            1,
            new PaymentMethodCollection([$this->payment(self::VORKASSE)]),
            null,
            new Criteria(),
            Context::createDefaultContext(),
        )));

        return new ShippingMethodPreselectionApplier(
            $configService,
            new ShippingMethodPreselector(),
            $switchRoute,
            $cartService,
            new ShippingChoiceStore($stack),
            $paymentRoute,
        );
    }

    /**
     * Die Umschalt-Route, die nur festhält, was übergeben wurde.
     *
     * @param list<array<string, string>> $switched
     */
    private function recordingSwitchRoute(array &$switched): AbstractContextSwitchRoute
    {
        $route = $this->createMock(AbstractContextSwitchRoute::class);
        $route->method('switchContext')->willReturnCallback(
            function (RequestDataBag $data) use (&$switched): ContextTokenResponse {
                $switched[] = array_map('strval', $data->all());

                return new ContextTokenResponse('token');
            },
        );

        return $route;
    }

    /**
     * @param array<string, int> $positions
     */
    private function listed(array $positions = [self::PAKET => 10, self::ABHOLUNG => 200]): ShippingMethodCollection
    {
        $methods = new ShippingMethodCollection();
        foreach ($positions as $id => $position) {
            $method = new ShippingMethodEntity();
            $method->setId($id);
            $method->setUniqueIdentifier($id);
            $method->setPosition($position);
            $method->setName($id);
            $methods->add($method);
        }

        return $methods;
    }

    private function payment(string $id): PaymentMethodEntity
    {
        $payment = new PaymentMethodEntity();
        $payment->setId($id);
        $payment->setUniqueIdentifier($id);

        return $payment;
    }

    private function context(string $currentId = self::ABHOLUNG): SalesChannelContext
    {
        $current = new ShippingMethodEntity();
        $current->setId($currentId);

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sc-id');
        $salesChannel->setShippingMethodId(self::PAKET);
        $salesChannel->setPaymentMethodId(self::VORKASSE);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-id');
        $context->method('getSalesChannel')->willReturn($salesChannel);
        // Wie der echte Kontext: Nach dem Umschalten trägt er die neue Versandart.
        $context->method('getShippingMethod')->willReturnCallback(static function () use (&$current): ShippingMethodEntity {
            return $current;
        });
        $context->method('assign')->willReturnCallback(static function (array $values) use (&$current, &$context): SalesChannelContext {
            $current = $values['shippingMethod'] ?? $current;

            return $context;
        });
        $context->method('getPaymentMethod')->willReturn($this->payment(self::BAR));
        $context->method('getToken')->willReturn('token');
        $context->method('getRuleIds')->willReturn([]);

        return $context;
    }
}
