<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Checkout\Cart;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart\PickupAcknowledgementRequiredError;
use Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart\PickupAcknowledgementValidator;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\PickupAcknowledgementStore;
use Ruhrcoder\RcCheckoutEnhancer\Service\PickupHintDecider;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Sperre: Ohne Bestätigung keine Bestellung — aber nur dort, wo bestätigt werden kann.
 *
 * Der teuerste Fehler wäre hier nicht die fehlende Sperre, sondern die zu breite: Eine Pflicht,
 * die auch ohne Storefront-Sitzung greift, blockiert Bestellungen über die Schnittstelle und aus
 * dem Verwaltungsbereich dauerhaft — und zwar lautlos, weil dort nie ein Dialog erscheinen kann.
 *
 * Die zweite Grenze ist die Ansicht: Auf der Warenkorbseite gibt es keine Versandartenauswahl,
 * also auch nichts zu bestätigen. Die Meldung dort wäre eine Sackgasse.
 */
final class PickupAcknowledgementValidatorTest extends TestCase
{
    private const HINWEIS = 'Verladen und Transport liegen bei Ihnen.';

    /**
     * Was: Hinweis fällig, nichts bestätigt.
     * Warum: Der Hauptfall — genau hier muss die Bestellung stehen bleiben.
     */
    public function testItBlocksWhenTheHintAppliesAndNothingWasAcknowledged(): void
    {
        $errors = new ErrorCollection();

        $this->validator(hint: self::HINWEIS, acknowledged: false, sessionAvailable: true)
            ->validate($this->heavyCart(), $errors, $this->pickupContext());

        self::assertCount(1, $errors);
        $error = $errors->first();
        self::assertInstanceOf(PickupAcknowledgementRequiredError::class, $error);
        self::assertTrue($error->blockOrder());
    }

    /**
     * Was: Hinweis fällig, derselbe Wortlaut bestätigt.
     * Warum: Nach dem Bestätigen muss der Weg frei sein — sonst wäre der Dialog eine Sackgasse.
     */
    public function testItLetsThroughWhenTheSameWordingWasAcknowledged(): void
    {
        $errors = new ErrorCollection();

        $this->validator(hint: self::HINWEIS, acknowledged: true, sessionAvailable: true)
            ->validate($this->heavyCart(), $errors, $this->pickupContext());

        self::assertCount(0, $errors);
    }

    /**
     * Was: Kein Hinweis fällig.
     * Warum: Die Gegenprobe. Eine Sperre, die auch ohne Anlass greift, hielte jede Bestellung an.
     */
    public function testItDoesNothingWhenNoHintApplies(): void
    {
        $errors = new ErrorCollection();

        $this->validator(hint: null, acknowledged: false, sessionAvailable: true)
            ->validate($this->heavyCart(), $errors, $this->pickupContext());

        self::assertCount(0, $errors);
    }

    /**
     * Was: Hinweis fällig, aber gar keine Anfrage im Stapel, etwa auf der Konsole oder in der
     *      Warteschlange.
     * Warum: Ohne Storefront-Seite gibt es keinen Dialog, in dem sich bestätigen ließe. Griffe
     *        die Pflicht dort, wären solche Bestellungen dauerhaft blockiert, und niemand käme
     *        auf die Idee, den Grund bei einem Abhol-Hinweis zu suchen.
     */
    public function testItDoesNotBlockWithoutAStorefrontSession(): void
    {
        $errors = new ErrorCollection();

        $this->validator(hint: self::HINWEIS, acknowledged: false, sessionAvailable: false)
            ->validate($this->heavyCart(), $errors, $this->pickupContext());

        self::assertCount(0, $errors);
    }

    /**
     * Was: Hinweis fällig, aber gerade wird eine Warenkorb-Ansicht gerendert.
     * Warum: Dort steht keine Versandartenauswahl und
     *        kein Dialog. Der Kunde bekäme eine rote Sperre zu einer Entscheidung, die er
     *        womöglich nie getroffen hat, und keine Möglichkeit, sie aufzulösen.
     */
    #[DataProvider('cartViewRoutes')]
    public function testItStaysSilentInTheCartViews(string $route): void
    {
        $errors = new ErrorCollection();

        $this->validator(hint: self::HINWEIS, acknowledged: false, sessionAvailable: true, route: $route)
            ->validate($this->heavyCart(), $errors, $this->pickupContext());

        self::assertCount(0, $errors, $route);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function cartViewRoutes(): array
    {
        return [
            'Warenkorbseite' => ['frontend.checkout.cart.page'],
            'ausfahrender Warenkorb' => ['frontend.cart.offcanvas'],
            'Warenkorb-Zähler im Kopfbereich' => ['frontend.checkout.info'],
        ];
    }

    /**
     * Was: Dieselbe Lage, aber auf der Bestätigungsseite.
     * Warum: Die Gegenprobe zur Ausnahme. Würde sie auch hier greifen, hätte die Ausnahme die
     *        Sperre abgeschafft statt sie einzugrenzen — und eine unbestätigte Bestellung ginge
     *        durch.
     */
    public function testItStillBlocksOnTheConfirmPage(): void
    {
        $errors = new ErrorCollection();

        $this->validator(
            hint: self::HINWEIS,
            acknowledged: false,
            sessionAvailable: true,
            route: 'frontend.checkout.confirm.page',
        )->validate($this->heavyCart(), $errors, $this->pickupContext());

        self::assertCount(1, $errors);
    }

    /**
     * Was: Eine Route, die es heute noch gar nicht gibt.
     * Warum: Deshalb eine Ausschlussliste. Eine Erlaubnisliste bliebe hier stumm, und das
     *        wäre der teure Ausgang: Die Sperre fiele aus, sobald
     *        Shopware eine Route umbenennt oder eine neue einführt.
     */
    public function testAnUnknownRouteKeepsTheBlock(): void
    {
        $errors = new ErrorCollection();

        $this->validator(
            hint: self::HINWEIS,
            acknowledged: false,
            sessionAvailable: true,
            route: 'frontend.checkout.something.new',
        )->validate($this->heavyCart(), $errors, $this->pickupContext());

        self::assertCount(1, $errors);
    }

    /**
     * Was: Eine Bestellung über die Store-API oder die Admin-API, Abholung nicht bestätigt.
     * Warum: Beide Anfragen tragen eine Sitzung, aber keine Seite mit dem Dialog. Gesperrt werden
     *        darf nur in der Storefront.
     */
    public function testItStaysQuietForStoreApiAndAdminApiOrders(): void
    {
        foreach (['store-api', 'api'] as $scope) {
            $errors = new ErrorCollection();

            $this->validator(hint: self::HINWEIS, acknowledged: false, sessionAvailable: true, scope: $scope)
                ->validate($this->heavyCart(), $errors, $this->pickupContext());

            self::assertCount(0, $errors, $scope);
        }
    }

    /**
     * Echte Mitspieler statt Attrappen: `PickupHintDecider` und `PickupAcknowledgementStore`
     * sind `final`, PHPUnit kann sie gar nicht nachbilden. Der Aufbau prüft damit die
     * Zusammenarbeit, nicht ein abgesprochenes Verhalten.
     */
    private function validator(
        ?string $hint,
        bool $acknowledged,
        bool $sessionAvailable,
        ?string $route = null,
        string $scope = 'storefront',
    ): PickupAcknowledgementValidator {
        $configService = $this->createMock(ConfigService::class);
        $configService->method('getPickupHintWeightThreshold')->willReturn($hint === null ? null : 50.0);
        $configService->method('getPickupHintLengthThreshold')->willReturn(null);
        $configService->method('getNonDeliveryMethodIds')->willReturn(['sm-abholung']);
        $configService->method('getPickupHintText')->willReturn($hint ?? '');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Textbaustein-Vorgabe');

        $stack = $this->requestStack($sessionAvailable, $route, $scope);

        $store = new PickupAcknowledgementStore($stack);
        if ($acknowledged && $hint !== null) {
            $store->acknowledge($hint, new \DateTimeImmutable());
        }

        return new PickupAcknowledgementValidator(new PickupHintDecider($configService, $translator), $store, $stack);
    }

    private function requestStack(bool $withSession, ?string $route = null, string $scope = 'storefront'): RequestStack
    {
        $stack = new RequestStack();
        if (!$withSession) {
            return $stack;
        }

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->attributes->set(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE, [$scope]);
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }
        $stack->push($request);

        return $stack;
    }

    /**
     * Der Warenkorb liegt über der Gewichtsschwelle, die Abholung ist gewählt — sonst käme der
     * Decider gar nicht bis zur Frage nach der Bestätigung.
     */
    private function heavyCart(): Cart
    {
        $cart = new Cart('token');
        $lineItem = new \Shopware\Core\Checkout\Cart\LineItem\LineItem('li-1', \Shopware\Core\Checkout\Cart\LineItem\LineItem::PRODUCT_LINE_ITEM_TYPE, 'ref-1', 1);
        $lineItem->setGood(true);
        $lineItem->setDeliveryInformation(new \Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryInformation(100, 80.0, false, null, null, 100.0, 200.0, 300.0));
        $cart->add($lineItem);

        return $cart;
    }

    private function pickupContext(): SalesChannelContext
    {
        $shippingMethod = new ShippingMethodEntity();
        $shippingMethod->setId('sm-abholung');
        $shippingMethod->setUniqueIdentifier('sm-abholung');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-1');
        $context->method('getShippingMethod')->willReturn($shippingMethod);

        return $context;
    }
}
