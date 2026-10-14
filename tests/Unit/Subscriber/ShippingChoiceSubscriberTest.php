<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingChoiceStore;
use Ruhrcoder\RcCheckoutEnhancer\Subscriber\ShippingChoiceSubscriber;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Event\SalesChannelContextSwitchEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Der Vermerk, dass ein Mensch geklickt hat.
 *
 * Die Trennlinie liegt in der Anfrage, nicht im Ereignis. Shopwares eigener Rückfall feuert
 * dasselbe Ereignis wie ein Klick, über
 * `StorefrontCartFacade::updateSalesChannelContext()`. Unterscheidbar sind die beiden nur daran,
 * in welcher Anfrage sie stattfinden.
 */
final class ShippingChoiceSubscriberTest extends TestCase
{
    private const ABHOLUNG = 'sm-abholung';

    private const KLICK_ROUTE = 'frontend.checkout.configure';

    private RequestStack $stack;

    protected function setUp(): void
    {
        $this->stack = new RequestStack();
    }

    /**
     * Was: Umschalten der Versandart über `/checkout/configure`.
     * Warum: Der Hauptfall — so und nur so sieht ein Klick des Kunden aus.
     */
    public function testAShippingSwitchIsRemembered(): void
    {
        $store = $this->store($this->request(self::KLICK_ROUTE, [
            SalesChannelContextService::SHIPPING_METHOD_ID => self::ABHOLUNG,
        ]));

        $this->subscriber($store)->onContextSwitch($this->event([
            SalesChannelContextService::SHIPPING_METHOD_ID => self::ABHOLUNG,
        ]));

        self::assertTrue($store->wasChosen(self::ABHOLUNG));
    }

    /**
     * Was: Shopwares eigener Rückfall auf eine andere Versandart beim Aufbau der
     *      Bestätigungsseite.
     * Warum: Der Rückfall ruft denselben Umschaltweg auf und feuert damit dasselbe Ereignis.
     *        Ohne diese Unterscheidung trüge er sich selbst als Wahl des Kunden ein, und die
     *        Abholung stünde angehakt da, obwohl niemand sie angeklickt hat.
     */
    public function testShopwaresFallbackIsNotAChoice(): void
    {
        $store = $this->store($this->request('frontend.checkout.confirm.page'));

        $this->subscriber($store)->onContextSwitch($this->event([
            SalesChannelContextService::SHIPPING_METHOD_ID => self::ABHOLUNG,
        ]));

        self::assertFalse($store->wasChosen(self::ABHOLUNG));
    }

    /**
     * Was: Die richtige Route, aber im Formular steht eine andere Versandart.
     * Warum: Fällt Shopware während einer Umschaltung noch einmal zurück, feuert das Ereignis
     *        ein zweites Mal in derselben Anfrage, mit einer Kennung, die der Kunde nie
     *        angefasst hat. Vermerkt wird nur, was auch abgeschickt wurde.
     */
    public function testOnlyTheSubmittedMethodCounts(): void
    {
        $store = $this->store($this->request(self::KLICK_ROUTE, [
            SalesChannelContextService::SHIPPING_METHOD_ID => 'sm-paketdienst',
        ]));

        $this->subscriber($store)->onContextSwitch($this->event([
            SalesChannelContextService::SHIPPING_METHOD_ID => self::ABHOLUNG,
        ]));

        self::assertFalse($store->wasChosen(self::ABHOLUNG));
    }

    /**
     * Was: Gar keine Anfrage — Kommandozeile, Warteschlange, Test.
     * Warum: Ohne Anfrage gibt es keinen Menschen, der klicken könnte. Ein Vermerk wäre erfunden.
     *
     * Gemessen wird hier an der Sitzung selbst und nicht über `wasChosen()`: Ohne Anfrage hat der
     * Speicher keine Sitzung mehr und antwortet dann absichtlich mit „ja, gewählt" — die
     * vorsichtige Richtung. Die Frage dieses Tests ist eine andere, nämlich ob etwas geschrieben
     * wurde.
     */
    public function testWithoutARequestNothingIsRemembered(): void
    {
        $request = $this->request(self::KLICK_ROUTE, [
            SalesChannelContextService::SHIPPING_METHOD_ID => self::ABHOLUNG,
        ]);
        $store = $this->store($request);
        $this->stack->pop();

        $this->subscriber($store)->onContextSwitch($this->event([
            SalesChannelContextService::SHIPPING_METHOD_ID => self::ABHOLUNG,
        ]));

        self::assertNull($request->getSession()->get(ShippingChoiceStore::SESSION_KEY));
    }

    /**
     * Was: Der Kunde wechselt die Zahlungsart, nachdem er die Abholung gewählt hat.
     * Warum: Derselbe Aufruf trägt je nach Formular auch nur Zahlungsart oder Anschrift.
     *        Würde der Vermerk dabei überschrieben, verlöre der Kunde seine Abholung, weil er
     *        von Vorkasse auf Rechnung gewechselt hat, und niemand käme auf den Zusammenhang.
     */
    public function testAPaymentSwitchLeavesTheChoiceAlone(): void
    {
        $store = $this->store($this->request(self::KLICK_ROUTE, [
            SalesChannelContextService::PAYMENT_METHOD_ID => 'pm-rechnung',
        ]));
        $store->remember(self::ABHOLUNG);

        $this->subscriber($store)->onContextSwitch($this->event([
            SalesChannelContextService::PAYMENT_METHOD_ID => 'pm-rechnung',
        ]));

        self::assertTrue($store->wasChosen(self::ABHOLUNG));
    }

    /**
     * Was: Ein leerer Wert im Datensatz.
     * Warum: Eine leere Zeichenkette ist keine Wahl. Sie zu vermerken hieße, eine Versandart mit
     *        leerer Kennung als gewollt zu führen — und die gibt es nicht.
     */
    public function testAnEmptyValueIsIgnored(): void
    {
        $store = $this->store($this->request(self::KLICK_ROUTE, [
            SalesChannelContextService::SHIPPING_METHOD_ID => '',
        ]));
        $store->remember(self::ABHOLUNG);

        $this->subscriber($store)->onContextSwitch($this->event([
            SalesChannelContextService::SHIPPING_METHOD_ID => '',
        ]));

        self::assertTrue($store->wasChosen(self::ABHOLUNG));
    }

    /**
     * @param array<string, string> $data
     */
    private function event(array $data): SalesChannelContextSwitchEvent
    {
        return new SalesChannelContextSwitchEvent(
            $this->createMock(SalesChannelContext::class),
            new DataBag($data),
        );
    }

    private function subscriber(ShippingChoiceStore $store): ShippingChoiceSubscriber
    {
        return new ShippingChoiceSubscriber($store, $this->stack);
    }

    /**
     * @param array<string, string> $payload
     */
    private function request(string $route, array $payload = []): Request
    {
        $request = new Request([], $payload);
        $request->attributes->set('_route', $route);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function store(Request $request): ShippingChoiceStore
    {
        $this->stack->push($request);

        return new ShippingChoiceStore($this->stack);
    }
}
