<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingChoiceStore;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingMethodPreselectionApplier;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingMethodPreselector;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingPlaceholderFilter;
use Ruhrcoder\RcCheckoutEnhancer\Subscriber\PickupHintSubscriber;
use Ruhrcoder\RcCheckoutEnhancer\Subscriber\ShippingMethodPreselectionSubscriber;
use Shopware\Core\Checkout\Cart\Address\Error\AddressValidationError;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPage;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPage;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Die Vorauswahl auf der Bestätigungsseite: umschalten, neu rechnen, Bescheid sagen.
 *
 * Drei Dinge müssen zusammen passieren, sonst ist das Ergebnis schlimmer als ohne Vorauswahl:
 * der Kontext für die nächste Seite, die Anhakung auf dieser Seite und der Betrag, der zu
 * ihr gehört. Fehlt eines, sieht der Kunde eine Versandart angehakt und einen Preis daneben,
 * der zu einer anderen gehört.
 */
final class ShippingMethodPreselectionSubscriberTest extends TestCase
{
    private const PAKET = 'sm-paket';

    private const SPEDITION = 'sm-spedition';

    private const ABHOLUNG = 'sm-abholung';

    private const PLACEHOLDER = 'sm-placeholder';

    /**
     * Was: Die eingestellte Versandart fehlt in der Liste.
     * Warum: Der Hauptfall. Geprüft wird die Kette, nicht nur ihr erstes Glied.
     */
    public function testItSwitchesTheContextAndRecalculatesTheCart(): void
    {
        $context = $this->context(self::PAKET);
        $event = $this->confirmEvent($context);
        $neuerWarenkorb = new Cart('token-neu');

        $switchRoute = $this->createMock(AbstractContextSwitchRoute::class);
        $switchRoute->expects(self::once())->method('switchContext');

        $context->expects(self::once())
            ->method('assign')
            ->with(self::callback(
                static fn (array $daten): bool => ($daten['shippingMethod'] ?? null) instanceof ShippingMethodEntity
                    && $daten['shippingMethod']->getId() === self::SPEDITION
            ));

        $cartService = $this->createMock(CartService::class);
        $cartService->expects(self::once())
            ->method('getCart')
            ->with('token', $context, false, true)
            ->willReturn($neuerWarenkorb);

        $this->subscriber(switchRoute: $switchRoute, cartService: $cartService)->onConfirmPage($event);

        self::assertSame($neuerWarenkorb, $event->getPage()->getCart());
    }

    /**
     * Was: Bestätigungsseite mit ungültiger Rechnungsadresse, die Vorauswahl schaltet um.
     * Warum: Die Adresssperre des Kerns hängt nur am Seiten-Warenkorb. Ginge sie mit dem Ersatz
     *        verloren, wäre der Bestellknopf frei, und beim Absenden prüft niemand mehr nach.
     */
    public function testTheAddressErrorOfTheConfirmPageSurvivesTheSwitch(): void
    {
        $event = $this->confirmEvent($this->context(self::PAKET));
        $addressError = new AddressValidationError(true, new ConstraintViolationList(), 'address-id');
        $event->getPage()->getCart()->addErrors($addressError);

        $cartService = $this->createMock(CartService::class);
        $cartService->method('getCart')->willReturn(new Cart('token-neu'));

        $this->subscriber(cartService: $cartService)->onConfirmPage($event);

        self::assertSame([$addressError], array_values($event->getPage()->getCart()->getErrors()->getElements()));
    }

    /**
     * Was: Die eingestellte Versandart ist verfügbar.
     * Warum: Die wichtigere Gegenprobe. Ein Umschalten an dieser Stelle überschriebe bei
     *        jedem Seitenaufruf, was der Kunde eben selbst gewählt hat.
     */
    public function testItLeavesAnAvailableShippingMethodAlone(): void
    {
        $context = $this->context(self::SPEDITION);
        $event = $this->confirmEvent($context);

        $switchRoute = $this->createMock(AbstractContextSwitchRoute::class);
        $switchRoute->expects(self::never())->method('switchContext');

        $cartService = $this->createMock(CartService::class);
        $cartService->expects(self::never())->method('getCart');

        $this->subscriber(switchRoute: $switchRoute, cartService: $cartService)->onConfirmPage($event);

        self::assertFalse($event->getPage()->hasExtension('rcPickupHint'));
    }

    /**
     * Was: Der Schalter steht aus.
     * Warum: Wer ihn ausschaltet, will das Verhalten des Kerns — dann darf nicht einmal die
     *        Liste gelesen werden.
     */
    public function testItDoesNothingWhenTheSettingIsOff(): void
    {
        $context = $this->context(self::PAKET);
        $event = $this->confirmEvent($context);

        $switchRoute = $this->createMock(AbstractContextSwitchRoute::class);
        $switchRoute->expects(self::never())->method('switchContext');

        $this->subscriber(enabled: false, switchRoute: $switchRoute)->onConfirmPage($event);

        self::assertFalse($event->getPage()->hasExtension('rcPickupHint'));
    }

    /**
     * Was: Übrig bleibt nur eine Versandart, die als „keine Lieferung" eingetragen ist.
     * Warum: Dann greift der Anfrageweg. Würde hier die Abholung vorausgewählt, stünde sie
     *        angehakt da, ohne dass der Kunde sie gewählt hat, und das vom Plugin selbst.
     */
    public function testItPreselectsNothingWhenOnlyAPickupRemains(): void
    {
        $context = $this->context(self::PAKET);
        $event = $this->confirmEvent($context, [['id' => 'sm-abholung', 'position' => 200]]);

        $switchRoute = $this->createMock(AbstractContextSwitchRoute::class);
        $switchRoute->expects(self::never())->method('switchContext');

        $this->subscriber(
            nonDelivery: ['sm-abholung'],
            switchRoute: $switchRoute,
        )->onConfirmPage($event);

        self::assertFalse($event->getPage()->hasExtension('rcPickupHint'));
    }

    /**
     * Was: Die Ereignisliste.
     * Warum: Steht dort ein falscher Name, läuft die Vorauswahl still nie: kein Fehler, keine
     *        Meldung, nur eine Liste ohne Anhakung.
     */
    public function testItListensToConfirmCartAndOffcanvas(): void
    {
        self::assertSame(
            [
                CheckoutConfirmPageLoadedEvent::class => ['onConfirmPage', 100],
                CheckoutCartPageLoadedEvent::class => ['onCartPage', 100],
                OffcanvasCartPageLoadedEvent::class => ['onOffcanvasCartPage', 100],
            ],
            ShippingMethodPreselectionSubscriber::getSubscribedEvents()
        );
    }

    /**
     * Was: Warenkorbseite, die Abholung steht im Kontext, niemand hat sie angeklickt.
     * Warum: Dort sieht der Kunde die Versandart zum ersten Mal. Griffe die Vorauswahl erst auf
     *        der Bestätigungsseite, stünde die Abholung bis dahin angehakt da.
     */
    public function testItPreselectsOnTheCartPage(): void
    {
        $context = $this->context(self::ABHOLUNG);
        $page = $this->page(new CheckoutCartPage(), [
            ['id' => self::SPEDITION, 'position' => 20],
            ['id' => self::ABHOLUNG, 'position' => 200],
        ]);
        $newCart = new Cart('token-new');

        $switchRoute = $this->createMock(AbstractContextSwitchRoute::class);
        $switchRoute->expects(self::once())->method('switchContext');

        $cartService = $this->createMock(CartService::class);
        $cartService->method('getCart')->with('token', $context, false, false)->willReturn($newCart);

        $this->subscriber(nonDelivery: [self::ABHOLUNG], switchRoute: $switchRoute, cartService: $cartService)
            ->onCartPage(new CheckoutCartPageLoadedEvent($page, $context, new Request()));

        self::assertSame($newCart, $page->getCart());
    }

    /**
     * Was: Dieselbe Lage in der Warenkorb-Seitenleiste.
     * Warum: Die Leiste ist der erste Blick nach „In den Warenkorb"; sie lädt ihre Seite über
     *        einen eigenen Loader und ein eigenes Ereignis.
     */
    public function testItPreselectsInTheOffcanvasCart(): void
    {
        $context = $this->context(self::ABHOLUNG);
        $page = $this->page(new OffcanvasCartPage(), [
            ['id' => self::SPEDITION, 'position' => 20],
            ['id' => self::ABHOLUNG, 'position' => 200],
        ]);

        $switchRoute = $this->createMock(AbstractContextSwitchRoute::class);
        $switchRoute->expects(self::once())->method('switchContext');

        $this->subscriber(nonDelivery: [self::ABHOLUNG], switchRoute: $switchRoute)
            ->onOffcanvasCartPage(new OffcanvasCartPageLoadedEvent($page, $context, new Request()));
    }

    /**
     * Was: Die Versandart im Kontext steht in der Liste, ihre Verfügbarkeitsregel greift aber
     *      nicht.
     * Warum: Der Warenkorb-Loader des Kerns hängt die Versandart aus dem Kontext an die Liste
     *        an, auch wenn sie nicht verfügbar ist. Ohne eigene Prüfung hielte die Vorauswahl
     *        sie für verfügbar und schaltete nie um.
     */
    public function testAMethodTheCartPageAppendedDespiteItsRuleIsNotAvailable(): void
    {
        $context = $this->context(self::PAKET);
        $page = $this->page(new CheckoutCartPage(), [
            ['id' => self::SPEDITION, 'position' => 20],
            ['id' => self::PAKET, 'position' => 1, 'availabilityRuleId' => 'rule-up-to-5-kg'],
        ]);

        $switchRoute = $this->createMock(AbstractContextSwitchRoute::class);
        $switchRoute->expects(self::once())->method('switchContext');

        $context->expects(self::once())
            ->method('assign')
            ->with(self::callback(
                static fn (array $data): bool => ($data['shippingMethod'] ?? null) instanceof ShippingMethodEntity
                    && $data['shippingMethod']->getId() === self::SPEDITION
            ));

        $this->subscriber(switchRoute: $switchRoute)
            ->onCartPage(new CheckoutCartPageLoadedEvent($page, $context, new Request()));
    }

    /**
     * Was: Die Verfügbarkeitsregel der Versandart im Kontext greift.
     * Warum: Die Gegenprobe zur Prüfung oben; eine erfüllte Regel darf nicht als gesperrt
     *        gelten.
     */
    public function testAMethodWhoseRuleMatchesStaysAvailable(): void
    {
        $context = $this->context(self::PAKET, ['rule-up-to-5-kg']);
        $page = $this->page(new CheckoutCartPage(), [
            ['id' => self::SPEDITION, 'position' => 20],
            ['id' => self::PAKET, 'position' => 1, 'availabilityRuleId' => 'rule-up-to-5-kg'],
        ]);

        $switchRoute = $this->createMock(AbstractContextSwitchRoute::class);
        $switchRoute->expects(self::never())->method('switchContext');

        $this->subscriber(switchRoute: $switchRoute)
            ->onCartPage(new CheckoutCartPageLoadedEvent($page, $context, new Request()));
    }

    /**
     * Was: Ohne Adresse bleibt keine Lieferart, im Kontext steht die nie angeklickte Abholung.
     * Warum: Die eingestellte Platzhalter-Versandart muss bis in den Preselector durchgereicht
     *        werden; ohne sie bliebe die Abholung angehakt.
     */
    public function testItSwitchesToThePlaceholderWhenNoDeliveryRemains(): void
    {
        $context = $this->context(self::ABHOLUNG);
        $page = $this->page(new CheckoutCartPage(), [
            ['id' => self::PLACEHOLDER, 'position' => 0],
            ['id' => self::ABHOLUNG, 'position' => 200],
        ]);

        $context->expects(self::once())
            ->method('assign')
            ->with(self::callback(
                static fn (array $data): bool => ($data['shippingMethod'] ?? null) instanceof ShippingMethodEntity
                    && $data['shippingMethod']->getId() === self::PLACEHOLDER
            ));

        $this->subscriber(nonDelivery: [self::ABHOLUNG], placeholder: self::PLACEHOLDER)
            ->onCartPage(new CheckoutCartPageLoadedEvent($page, $context, new Request()));
    }

    /**
     * Was: Der Rang vor dem Abhol-Hinweis.
     * Warum: Beide hören auf dasselbe Ereignis. Ohne Rang entscheidet die Stelle in der
     *        Dienstliste, und kommt der Hinweis zuerst, sieht er die Abholung im Kontext und
     *        hängt den Dialog an, kurz bevor diese Rechnung auf die Spedition umschaltet. Der
     *        Kunde bekäme den Dialog zu einer Versandart, die auf der fertigen Seite gar nicht
     *        mehr angehakt ist.
     */
    public function testItRunsBeforeThePickupHint(): void
    {
        $vorauswahl = ShippingMethodPreselectionSubscriber::getSubscribedEvents()[CheckoutConfirmPageLoadedEvent::class][1];
        $hinweis = PickupHintSubscriber::getSubscribedEvents()[CheckoutConfirmPageLoadedEvent::class][1];

        self::assertGreaterThan($hinweis, $vorauswahl, 'Die Vorauswahl muss vor dem Abhol-Hinweis entscheiden.');
    }

    /**
     * Was: Die Abholung steht im Kontext, ist verfügbar, und niemand hat sie angeklickt.
     * Warum: Sie ist oft die einzige Versandart ohne Gewichts- und Längengrenze, also das,
     *        worauf Shopware zurückfällt, sobald der Paketdienst für den Warenkorb nicht mehr
     *        greift. Ließe die Vorauswahl sie stehen, weil sie verfügbar ist, fände der Gast
     *        die Selbstabholung auf der Bestätigungsseite angehakt vor.
     */
    public function testItSwitchesAwayFromAPickupTheCustomerNeverChose(): void
    {
        $context = $this->context(self::ABHOLUNG);
        $event = $this->confirmEvent($context, [
            ['id' => self::SPEDITION, 'position' => 20],
            ['id' => self::ABHOLUNG, 'position' => 200],
        ]);

        $switchRoute = $this->createMock(AbstractContextSwitchRoute::class);
        $switchRoute->expects(self::once())->method('switchContext');

        $this->subscriber(
            nonDelivery: [self::ABHOLUNG],
            switchRoute: $switchRoute,
        )->onConfirmPage($event);

        // Die Erwartung an `switchContext` oben ist der Nachweis: Es wurde umgeschaltet, und
        // zwar genau einmal. Ein Blick in den Kontext liefe hier ins Leere — er ist ein
        // Doppel, und `assign()` erreicht ihn nicht.
    }

    /**
     * Was: Dieselbe Lage, aber der Kunde hat die Abholung selbst angeklickt.
     * Warum: Die Gegenprobe, und die wichtigere. Wer abholen will, muss abholen dürfen.
     *        Würde hier umgeschaltet, wäre die Abholung nicht mehr wählbar; jeder Klick liefe
     *        ins Leere, und der Abhol-Dialog käme nie zum Zug.
     */
    public function testItKeepsAPickupTheCustomerChose(): void
    {
        $context = $this->context(self::ABHOLUNG);
        $event = $this->confirmEvent($context, [
            ['id' => self::SPEDITION, 'position' => 20],
            ['id' => self::ABHOLUNG, 'position' => 200],
        ]);

        $switchRoute = $this->createMock(AbstractContextSwitchRoute::class);
        $switchRoute->expects(self::never())->method('switchContext');

        $this->subscriber(
            nonDelivery: [self::ABHOLUNG],
            switchRoute: $switchRoute,
            chosenByCustomer: self::ABHOLUNG,
        )->onConfirmPage($event);

        self::assertFalse($event->getPage()->hasExtension('rcPickupHint'));
    }

    /**
     * Was: Warenkorbseite, Paketware ohne Adresse, Platzhalter und Paketdienst in der Liste.
     * Warum: Der Platzhalter verschwindet aus der angezeigten Liste. Stünde er da, könnte ihn der
     *        Kunde anklicken, und die Vorauswahl schaltete sofort zurück.
     */
    public function testThePlaceholderLeavesTheListWhenADeliveryMethodIsThere(): void
    {
        $page = $this->page(new CheckoutCartPage(), [
            ['id' => self::PLACEHOLDER, 'position' => 0],
            ['id' => self::PAKET, 'position' => 1],
            ['id' => self::ABHOLUNG, 'position' => 200],
        ]);

        $this->subscriber(nonDelivery: [self::ABHOLUNG], placeholder: self::PLACEHOLDER)
            ->onCartPage(new CheckoutCartPageLoadedEvent($page, $this->context(self::PAKET), new Request()));

        self::assertSame([self::PAKET, self::ABHOLUNG], array_values($page->getShippingMethods()->getIds()));
    }

    /**
     * @param list<string> $nonDelivery
     */
    private function subscriber(
        bool $enabled = true,
        array $nonDelivery = [],
        ?AbstractContextSwitchRoute $switchRoute = null,
        ?CartService $cartService = null,
        ?string $chosenByCustomer = null,
        ?string $placeholder = null,
    ): ShippingMethodPreselectionSubscriber {
        $configService = $this->createMock(ConfigService::class);
        $configService->method('isShippingPreselectEnabled')->willReturn($enabled);
        $configService->method('getNonDeliveryMethodIds')->willReturn($nonDelivery);
        $configService->method('getShippingPlaceholderMethodId')->willReturn($placeholder);

        return new ShippingMethodPreselectionSubscriber(
            new ShippingMethodPreselectionApplier(
                $configService,
                new ShippingMethodPreselector(),
                $switchRoute ?? $this->createMock(AbstractContextSwitchRoute::class),
                $cartService ?? $this->createMock(CartService::class),
                $this->choiceStore($chosenByCustomer),
                $this->createMock(AbstractPaymentMethodRoute::class),
            ),
            new ShippingPlaceholderFilter($configService),
        );
    }

    /**
     * Der Speicher der eigenen Wahl — mit echter Sitzung, damit „nichts gewählt" auch wirklich
     * „nichts gewählt" heißt und nicht der Rückzug ohne Sitzung greift.
     */
    private function choiceStore(?string $chosen): ShippingChoiceStore
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        $stack = new RequestStack();
        $stack->push($request);

        $store = new ShippingChoiceStore($stack);
        if ($chosen !== null) {
            $store->remember($chosen);
        }

        return $store;
    }

    /**
     * @param list<array{id: string, position: int}>|null $methods
     */
    private function confirmEvent(SalesChannelContext $context, ?array $methods = null): CheckoutConfirmPageLoadedEvent
    {
        $methods ??= [['id' => self::SPEDITION, 'position' => 20]];

        $entities = [];
        foreach ($methods as $data) {
            $method = new ShippingMethodEntity();
            $method->setId($data['id']);
            $method->setUniqueIdentifier($data['id']);
            $method->setPosition($data['position']);
            $method->setName($data['id']);
            $entities[] = $method;
        }

        $page = new CheckoutConfirmPage();
        $page->setCart(new Cart('token'));
        $page->setShippingMethods(new ShippingMethodCollection($entities));

        return new CheckoutConfirmPageLoadedEvent($page, $context, new Request());
    }

    /**
     * @template T of CheckoutCartPage|OffcanvasCartPage
     *
     * @param T $page
     * @param list<array{id: string, position: int, availabilityRuleId?: string}> $methods
     *
     * @return T
     */
    private function page(CheckoutCartPage|OffcanvasCartPage $page, array $methods): CheckoutCartPage|OffcanvasCartPage
    {
        $entities = [];
        foreach ($methods as $data) {
            $method = new ShippingMethodEntity();
            $method->setId($data['id']);
            $method->setUniqueIdentifier($data['id']);
            $method->setPosition($data['position']);
            $method->setName($data['id']);
            $method->setAvailabilityRuleId($data['availabilityRuleId'] ?? null);
            $entities[] = $method;
        }

        $page->setCart(new Cart('token'));
        $page->setShippingMethods(new ShippingMethodCollection($entities));

        return $page;
    }

    /**
     * @param list<string> $ruleIds
     */
    private function context(string $shippingMethodId, array $ruleIds = []): SalesChannelContext&\PHPUnit\Framework\MockObject\MockObject
    {
        $method = new ShippingMethodEntity();
        $method->setId($shippingMethodId);
        $method->setUniqueIdentifier($shippingMethodId);
        $method->setName($shippingMethodId);

        // Die Standard-Versandart des Kanals: In den Tests ist sie bewusst eine, die nie in der
        // Liste steht — so entscheidet allein die Position, und der Vorrang ist getrennt geprüft
        // (siehe ShippingMethodPreselectorTest).
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sc-id');
        $salesChannel->setUniqueIdentifier('sc-id');
        $salesChannel->setShippingMethodId('sm-standard-des-kanals');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-id');
        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getShippingMethod')->willReturn($method);
        $context->method('getToken')->willReturn('token');
        $context->method('getRuleIds')->willReturn($ruleIds);

        return $context;
    }
}
