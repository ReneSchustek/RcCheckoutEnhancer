<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Checkout\Cart;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart\PaymentChoiceRequiredError;
use Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart\PaymentChoiceValidator;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\PaymentPlaceholderFilter;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Die Sperre der Platzhalter-Zahlart: nur dort, wo gewählt oder bestellt wird.
 *
 * Eine zu breite Sperre wäre sichtbar und falsch: Beim Hinzufügen eines Artikels entstanden, stünde der Hinweis
 * „Bitte wählen Sie oben eine Zahlungsart" im Warenkorb, wo es keine Auswahl gibt.
 *
 * @covers \Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart\PaymentChoiceValidator
 */
final class PaymentChoiceValidatorTest extends TestCase
{
    private const PLACEHOLDER = 'placeholder-id';

    /**
     * Was: Platzhalter im Kontext, auf der Bestellseite, beim Absenden und in der einseitigen Kasse.
     * Warum: Dort wählt der Kunde die Zahlart; ohne Wahl darf die Bestellung nicht durchgehen.
     * Erwartet: je eine sperrende Meldung.
     */
    public function testItBlocksWhereThePaymentIsChosen(): void
    {
        foreach (['frontend.checkout.confirm.page', 'frontend.checkout.finish.order', 'frontend.rc-checkout.one-page.selection'] as $route) {
            $errors = new ErrorCollection();
            $this->validator($route)->validate(new Cart('token'), $errors, $this->context(self::PLACEHOLDER));

            self::assertCount(1, $errors, $route);
            $error = $errors->first();
            self::assertInstanceOf(PaymentChoiceRequiredError::class, $error);
            self::assertTrue($error->blockOrder());
        }
    }

    /**
     * Was: Platzhalter im Kontext, aber beim Hinzufügen eines Artikels, im Warenkorb und in der Leiste.
     * Warum: Eine dort entstandene Meldung zeigte Shopware auf der nächsten Seite an, ohne Zahlartwahl darüber.
     * Erwartet: keine Meldung.
     */
    public function testItStaysQuietOutsideTheOrderSteps(): void
    {
        foreach (['frontend.checkout.line-item.add', 'frontend.checkout.cart.page', 'frontend.cart.offcanvas'] as $route) {
            $errors = new ErrorCollection();
            $this->validator($route)->validate(new Cart('token'), $errors, $this->context(self::PLACEHOLDER));

            self::assertCount(0, $errors, $route);
        }
    }

    /**
     * Was: Auf der Bestellseite ist eine echte Zahlart gewählt.
     * Erwartet: keine Meldung.
     */
    public function testAChosenPaymentPasses(): void
    {
        $errors = new ErrorCollection();
        $this->validator('frontend.checkout.confirm.page')->validate(new Cart('token'), $errors, $this->context('prepayment'));

        self::assertCount(0, $errors);
    }

    private function validator(string $route): PaymentChoiceValidator
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key): ?string => str_ends_with($key, '.paymentPlaceholderMethodId') ? self::PLACEHOLDER : null,
        );

        $request = new Request();
        $request->attributes->set('_route', $route);
        $request->attributes->set(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE, ['storefront']);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $stack = new RequestStack();
        $stack->push($request);

        return new PaymentChoiceValidator(new PaymentPlaceholderFilter(new ConfigService($systemConfig)), $stack);
    }

    private function context(string $paymentMethodId): SalesChannelContext
    {
        $method = new PaymentMethodEntity();
        $method->setId($paymentMethodId);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-id');
        $context->method('getPaymentMethod')->willReturn($method);

        return $context;
    }
}
