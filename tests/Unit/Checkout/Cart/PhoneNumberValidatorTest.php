<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Checkout\Cart;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart\PhoneNumberRequiredError;
use Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart\PhoneNumberValidator;
use Ruhrcoder\RcCheckoutEnhancer\Service\PhoneNumberRequirement;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Die Sperre: ohne Telefonnummer keine Bestellung — aber nur dort, wo sie sich eintragen lässt.
 *
 * Der teuerste Fehler wäre hier nicht die fehlende Sperre, sondern die zu breite. Eine Pflicht,
 * die auch ohne Storefront greift, blockiert Bestellungen über die Schnittstelle und aus dem
 * Verwaltungsbereich dauerhaft, und zwar lautlos: Dort kann nie ein Feld erscheinen.
 */
final class PhoneNumberValidatorTest extends TestCase
{
    /**
     * Was: Die Nummer fehlt, der Kunde steht auf der Bestätigungsseite.
     * Warum: Der Hauptfall — genau hier muss die Bestellung stehen bleiben.
     */
    public function testItBlocksWhenTheNumberIsMissing(): void
    {
        $errors = new ErrorCollection();

        $this->validator(missing: true, route: 'frontend.checkout.confirm.page')
            ->validate(new Cart('token'), $errors, $this->context());

        self::assertCount(1, $errors);
        $error = $errors->first();
        self::assertInstanceOf(PhoneNumberRequiredError::class, $error);
        self::assertTrue($error->blockOrder());
    }

    /**
     * Was: Die Nummer steht da.
     * Warum: Der Normalfall jeder regulären Bestellung. Hier darf nichts passieren.
     */
    public function testItLetsThroughWhenTheNumberIsThere(): void
    {
        $errors = new ErrorCollection();

        $this->validator(missing: false, route: 'frontend.checkout.confirm.page')
            ->validate(new Cart('token'), $errors, $this->context());

        self::assertCount(0, $errors);
    }

    /**
     * Was: Dieselbe fehlende Nummer, aber auf der Warenkorbseite.
     * Warum: Dort gibt es kein Feld für die Nummer. Der Kunde sähe eine rote Sperre, die er an
     *        dieser Stelle nicht auflösen kann — und würde den Warenkorb verlassen.
     */
    public function testItStaysQuietOnTheCartPage(): void
    {
        $errors = new ErrorCollection();

        $this->validator(missing: true, route: 'frontend.checkout.cart.page')
            ->validate(new Cart('token'), $errors, $this->context());

        self::assertCount(0, $errors);
    }

    /**
     * Was: Dieselbe fehlende Nummer, aber im seitlich einfahrenden Warenkorb.
     * Warum: Dieselbe Begründung, andere Ansicht — und sie wird als Unteranfrage gerendert,
     *        weshalb die Route auch dort erkannt werden muss.
     */
    public function testItStaysQuietInTheOffcanvasCart(): void
    {
        $errors = new ErrorCollection();

        $this->validator(missing: true, route: 'frontend.cart.offcanvas')
            ->validate(new Cart('token'), $errors, $this->context());

        self::assertCount(0, $errors);
    }

    /**
     * Was: Kein Aufruf im Stapel — Konsole, Warteschlange, Store-API.
     * Warum: Ohne Storefront gibt es keine Seite, auf der sich die
     *        Nummer nachreichen ließe. Eine Sperre dort blockierte solche Bestellungen für
     *        immer, ohne dass irgendwo etwas erschiene.
     */
    public function testItStaysQuietWithoutAStorefrontRequest(): void
    {
        $errors = new ErrorCollection();

        $requirement = $this->createMock(PhoneNumberRequirement::class);
        $requirement->expects(self::never())->method('isMissing');

        (new PhoneNumberValidator($requirement, new RequestStack()))
            ->validate(new Cart('token'), $errors, $this->context());

        self::assertCount(0, $errors);
    }

    /**
     * Was: Eine Bestellung über die Store-API oder die Admin-API, ohne Telefonnummer.
     * Warum: Dort gibt es keine Seite, auf der die Nummer nachgereicht werden könnte. Eine Sperre
     *        machte eine im Verwaltungsbereich angelegte Bestellung unmöglich. Beide Anfragen tragen
     *        eine Sitzung; an ihr lässt sich das deshalb nicht erkennen, nur am Route-Scope.
     */
    public function testItStaysQuietForStoreApiAndAdminApiOrders(): void
    {
        foreach (['store-api', 'api'] as $scope) {
            $errors = new ErrorCollection();

            $this->validator(missing: true, route: 'store-api.checkout.cart.order', scope: $scope)
                ->validate(new Cart('token'), $errors, $this->context());

            self::assertCount(0, $errors, $scope);
        }
    }

    private function validator(bool $missing, string $route, string $scope = 'storefront'): PhoneNumberValidator
    {
        $requirement = $this->createMock(PhoneNumberRequirement::class);
        $requirement->method('isMissing')->willReturn($missing);

        $request = new Request();
        $request->attributes->set('_route', $route);
        $request->attributes->set(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE, [$scope]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $stack = new RequestStack();
        $stack->push($request);

        return new PhoneNumberValidator($requirement, $stack);
    }

    private function context(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-id');

        return $context;
    }
}
