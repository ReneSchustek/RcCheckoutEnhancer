<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\CartFingerprint;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\LastShippingEstimateStore;
use Ruhrcoder\RcCheckoutEnhancer\Struct\LastShippingEstimate;
use Ruhrcoder\RcCheckoutEnhancer\Subscriber\OffcanvasShippingEstimateSubscriber;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPage;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

/**
 * Die vier Fälle, die die Seitenleiste auseinanderhalten muss.
 */
final class OffcanvasShippingEstimateSubscriberTest extends TestCase
{
    private const SALES_CHANNEL_ID = 'sc-id';

    /**
     * Was: Eine Berechnung liegt vor, der Warenkorb ist seitdem unverändert.
     * Warum: Der eigentliche Zweck — die Auskunft ohne erneute Eingabe.
     * Erwartet: Zustand „gültig", die gespeicherte Auskunft hängt an der Seite.
     */
    public function testAKnownEstimateIsShownWhenTheCartIsUnchanged(): void
    {
        $cart = $this->cart();
        $fingerprint = (new CartFingerprint())->of($cart);

        $event = $this->event($cart);
        $this->subscriber($this->storeWith($this->estimate($fingerprint)))->onOffcanvasLoaded($event);

        $extension = $event->getPage()->getExtension('rcOffcanvasShipping');
        self::assertInstanceOf(ArrayStruct::class, $extension);
        self::assertSame('valid', $extension->get('state'));
        self::assertSame(4.95, $extension->get('estimate')->price);
    }

    /**
     * Was: Eine Berechnung liegt vor, aber der Warenkorb hat sich geändert.
     * Warum: **Der Kern des Entwurfs.** Ein veralteter Versandpreis ist schlechter als
     *        gar keiner — er ist eine Zusage, die der Shop nicht hält. Neu gerechnet wird
     *        hier trotzdem nicht: Eine Berechnung kostet so viele Warenkorb-Durchläufe,
     *        wie es Versandarten gibt, und die Leiste geht oft auf.
     * Erwartet: Zustand „veraltet".
     */
    public function testAChangedCartMarksTheEstimateAsStale(): void
    {
        $event = $this->event($this->cart(quantity: 3));
        $this->subscriber($this->storeWith($this->estimate('fingerabdruck-eines-anderen-warenkorbs')))
            ->onOffcanvasLoaded($event);

        $extension = $event->getPage()->getExtension('rcOffcanvasShipping');
        self::assertInstanceOf(ArrayStruct::class, $extension);
        self::assertSame('stale', $extension->get('state'));
    }

    /**
     * Was: Es wurde noch nie gerechnet.
     * Warum: Wer nie gerechnet hat, soll erfahren, dass er es kann — ein leerer Kasten
     *        sagt ihm das nicht.
     * Erwartet: Zustand „keine", ohne Auskunft.
     */
    public function testWithoutAnEstimateOnlyThePointerIsShown(): void
    {
        $event = $this->event($this->cart());
        $this->subscriber($this->storeWith(null))->onOffcanvasLoaded($event);

        $extension = $event->getPage()->getExtension('rcOffcanvasShipping');
        self::assertInstanceOf(ArrayStruct::class, $extension);
        self::assertSame('none', $extension->get('state'));
    }

    /**
     * Was: Ein angemeldeter Kunde bekommt die Auskunft ebenfalls.
     * Warum: Dieselbe Umkehr wie beim Rechner selbst. Bliebe die Leiste bei der alten Regel,
     *        sähe ein angemeldeter Kunde seine gerade errechnete Auskunft überall — nur nicht
     *        dort, wo er sie beim nächsten Öffnen des Warenkorbs sucht.
     */
    public function testSignedInCustomersGetTheAnswerAsWell(): void
    {
        $event = $this->event($this->cart(), loggedIn: true);
        $this->subscriber($this->storeWith(null))->onOffcanvasLoaded($event);

        self::assertTrue($event->getPage()->hasExtension('rcOffcanvasShipping'));
    }

    /**
     * Was: Der Rechner ist im Verkaufskanal abgeschaltet.
     * Warum: Ein abgeschalteter Rechner darf auch keine gespeicherte Auskunft nachliefern.
     * Erwartet: keine Erweiterung.
     */
    public function testASwitchedOffEstimatorShowsNothing(): void
    {
        $event = $this->event($this->cart());
        $this->subscriber($this->storeWith(null), enabled: false)->onOffcanvasLoaded($event);

        self::assertFalse($event->getPage()->hasExtension('rcOffcanvasShipping'));
    }

    /**
     * Was: Ein leerer Warenkorb.
     * Warum: Ohne Positionen gibt es keine Versandkosten, über die sich reden ließe.
     * Erwartet: keine Erweiterung.
     */
    public function testAnEmptyCartShowsNothing(): void
    {
        $event = $this->event(new Cart('leer'));
        $this->subscriber($this->storeWith(null))->onOffcanvasLoaded($event);

        self::assertFalse($event->getPage()->hasExtension('rcOffcanvasShipping'));
    }

    private function subscriber(LastShippingEstimateStore $store, bool $enabled = true): OffcanvasShippingEstimateSubscriber
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('isShippingEstimatorEnabled')->willReturn($enabled);

        return new OffcanvasShippingEstimateSubscriber($config, $store, new CartFingerprint());
    }

    private function storeWith(?LastShippingEstimate $estimate): LastShippingEstimateStore
    {
        $store = $this->createMock(LastShippingEstimateStore::class);
        $store->method('get')->willReturn($estimate);

        return $store;
    }

    private function estimate(string $fingerprint): LastShippingEstimate
    {
        return new LastShippingEstimate('DE', '44135', 'Standard', 4.95, 'EUR', $fingerprint);
    }

    private function cart(int $quantity = 1): Cart
    {
        $cart = new Cart('test-token');
        $cart->add(new LineItem('li-1', LineItem::PRODUCT_LINE_ITEM_TYPE, 'ref-1', $quantity));

        return $cart;
    }

    private function event(Cart $cart, bool $loggedIn = false): OffcanvasCartPageLoadedEvent
    {
        $page = new OffcanvasCartPage();
        $page->setCart($cart);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $context->method('getCustomer')->willReturn($loggedIn ? new CustomerEntity() : null);

        return new OffcanvasCartPageLoadedEvent($page, $context, new Request());
    }
}
