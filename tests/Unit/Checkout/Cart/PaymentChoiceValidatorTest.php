<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Checkout\Cart;

use PHPUnit\Framework\Attributes\CoversClass;
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
 * Die Sperre der Platzhalter-Zahlart: überall außer in den Warenkorb-Ansichten.
 *
 * Sie schließt sicher: Eine unbekannte Route sperrt. Damit die Meldung beim Ändern des Warenkorbs nicht als Hinweis in
 * der nächsten Ansicht erscheint, ist der Fehler nicht dauerhaft.
 */
#[CoversClass(PaymentChoiceValidator::class)]
final class PaymentChoiceValidatorTest extends TestCase
{
    private const PLACEHOLDER = 'placeholder-id';

    /**
     * Was: Platzhalter im Kontext, beim Absenden der Bestellung und auf einer unbekannten Route.
     * Warum: Ohne Wahl darf keine Bestellung durchgehen, auch nicht, wenn Shopware eine Route umbenennt.
     * Erwartet: je eine sperrende, nicht dauerhafte Meldung.
     */
    public function testItBlocksOutsideTheCartViews(): void
    {
        foreach (['frontend.checkout.finish.order', 'frontend.some.renamed.route'] as $route) {
            $errors = new ErrorCollection();
            $this->validator($route)->validate(new Cart('token'), $errors, $this->context(self::PLACEHOLDER));

            self::assertCount(1, $errors, $route);
            $error = $errors->first();
            self::assertInstanceOf(PaymentChoiceRequiredError::class, $error);
            self::assertTrue($error->blockOrder());
            // Nicht dauerhaft: Shopware übernimmt sie weder in die nächste Berechnung noch als Hinweis.
            self::assertFalse($error->isPersistent());
        }
    }

    /**
     * Was: Platzhalter im Kontext, in Warenkorb, Leiste, Warenkorb-Zähler, auf der Kundendaten- und der
     *      Bestätigungsseite beim Laden.
     * Warum: Im Warenkorb gibt es keine Zahlartwahl; auf den beiden Seiten wählt der Kunde erst noch. Die Meldung kommt
     *        erst mit dem Klick auf „Bestellen" (Rene, 19.10.).
     * Erwartet: keine Meldung.
     */
    public function testItStaysQuietBeforeThePaymentChoice(): void
    {
        foreach (['frontend.checkout.cart.page', 'frontend.cart.offcanvas', 'frontend.checkout.info', 'frontend.checkout.confirm.page', 'frontend.checkout.register.page'] as $route) {
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
        $this->validator('frontend.checkout.finish.order')->validate(new Cart('token'), $errors, $this->context('prepayment'));

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
