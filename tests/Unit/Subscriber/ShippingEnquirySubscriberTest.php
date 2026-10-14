<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart\PickupAcknowledgementRequiredError;
use Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart\ShippingEnquiryRequiredError;
use Ruhrcoder\RcCheckoutEnhancer\Checkout\ShippingEnquiryRule;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingEnquiryStore;
use Ruhrcoder\RcCheckoutEnhancer\Subscriber\ShippingEnquirySubscriber;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Navigation\NavigationPage;
use Shopware\Storefront\Page\Navigation\NavigationPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

/**
 * Der Anfrageweg: wann er erscheint und wann ausdrücklich nicht.
 *
 * Der teuerste Fehler wäre, ihn zu zeigen, wo alles in Ordnung ist — dann stünde auf jeder
 * Bestätigungsseite „wir können nicht liefern". Deshalb steht die Gegenprobe hier gleich
 * neben dem Hauptfall.
 */
final class ShippingEnquirySubscriberTest extends TestCase
{
    private const KATEGORIE = 'kategorie-kontakt';

    /**
     * Was: Keine Versandart übrig.
     * Warum: Der Fall, um den es geht. Shopware rendert die Auswahl der Versandart dann
     *        gar nicht erst — die leere Liste ist die Antwort des Kerns.
     */
    public function testTheEnquiryAppearsWhenNoShippingMethodIsLeft(): void
    {
        $event = $this->confirmEvent(shippingMethods: 0);

        $this->subscriber()->onConfirmPage($event);

        self::assertTrue($event->getPage()->hasExtension('rcShippingEnquiry'));
    }

    /**
     * Die Gegenprobe: Gibt es etwas zu wählen, ist die Sendung lieferbar, und der
     * Hinweis wäre eine Absage an einen Kunden, der gerade bestellen will.
     */
    public function testNothingAppearsWhileAShippingMethodIsAvailable(): void
    {
        $event = $this->confirmEvent(shippingMethods: 1);

        $this->subscriber()->onConfirmPage($event);

        self::assertFalse($event->getPage()->hasExtension('rcShippingEnquiry'));
    }

    /**
     * Was: Es bleibt nur die Selbstabholung übrig.
     * Warum: Der Fall zwischen den beiden anderen. Die Abholung ist oft die einzige
     *        Versandart ohne Gewichtsgrenze und bleibt deshalb übrig, wenn die
     *        Speditionsleiter endet, nicht als Angebot, sondern als Rest. Der Kunde stünde
     *        sonst vor genau einer Möglichkeit: eine halbe Tonne selbst abholen. Wer das
     *        nicht kann, hätte keinen Weg außer dem Abbruch.
     * Erwartet: Der Anfrageweg erscheint zusätzlich, mit eigenem Text.
     */
    public function testTheEnquiryAppearsWhenOnlySelfCollectionIsLeft(): void
    {
        $event = $this->confirmEvent(shippingMethods: 1);

        $this->subscriber(nonDelivery: ['sm-0'])->onConfirmPage($event);

        $extension = $event->getPage()->getExtension('rcShippingEnquiry');
        self::assertInstanceOf(ArrayStruct::class, $extension);
        self::assertTrue($extension->get('pickupOnly'), 'Der Text muss der für den Abhol-Fall sein.');
    }

    /**
     * Was: Bleibt nur die Abholung, hängt an der Bestellung noch die Abhol-Sperre.
     * Warum: Sie sagt „bitte bestätigen Sie den Hinweis" und meint einen Dialog, den es in
     *        diesem Zustand nicht mehr gibt. Eine Aufforderung ins Leere ist dieselbe Sackgasse,
     *        die schon einmal auf der Warenkorbseite stand. Sie wird ersetzt, nicht entfernt —
     *        bestellt werden soll hier nichts.
     */
    public function testTheAcknowledgementBlockIsReplacedByTheEnquiryBlock(): void
    {
        $event = $this->confirmEvent(shippingMethods: 1);
        $event->getPage()->getCart()->getErrors()->add(new PickupAcknowledgementRequiredError());

        $this->subscriber(nonDelivery: ['sm-0'])->onConfirmPage($event);

        $errors = $event->getPage()->getCart()->getErrors();
        self::assertNull($errors->get('rc-checkout-pickup-acknowledgement-required'));
        $block = $errors->get('rc-checkout-shipping-enquiry-required');
        self::assertInstanceOf(ShippingEnquiryRequiredError::class, $block);
        self::assertTrue($block->blockOrder(), 'Ohne Sperre stünde die Schaltfläche wieder offen.');
    }

    /**
     * Was: Dieselbe Lage, aber ohne vorher gesetzte Abhol-Sperre.
     * Warum: Die Sperre muss auch dann stehen, wenn der Hinweis gar nicht erst fällig war —
     *        etwa bei leichter Ware, die trotzdem niemand liefert. Sonst hinge das Verbot an
     *        einer Schwelle, mit der es nichts zu tun hat.
     */
    public function testTheEnquiryBlocksTheOrderOnItsOwn(): void
    {
        $event = $this->confirmEvent(shippingMethods: 1);

        $this->subscriber(nonDelivery: ['sm-0'])->onConfirmPage($event);

        self::assertInstanceOf(
            ShippingEnquiryRequiredError::class,
            $event->getPage()->getCart()->getErrors()->get('rc-checkout-shipping-enquiry-required')
        );
    }

    /**
     * Was: Neben der Abholung steht eine echte Lieferart.
     * Warum: Dann ist alles in Ordnung, und die Abhol-Sperre gehört dem Dialog — sie darf nicht
     *        gegen eine Sperre getauscht werden, die dem Kunden den Weg abschneidet.
     */
    public function testTheAcknowledgementBlockSurvivesWhenDeliveryIsPossible(): void
    {
        $event = $this->confirmEvent(shippingMethods: 2);
        $event->getPage()->getCart()->getErrors()->add(new PickupAcknowledgementRequiredError());

        $this->subscriber(nonDelivery: ['sm-0'])->onConfirmPage($event);

        $errors = $event->getPage()->getCart()->getErrors();
        self::assertInstanceOf(PickupAcknowledgementRequiredError::class, $errors->get('rc-checkout-pickup-acknowledgement-required'));
        self::assertNull($errors->get('rc-checkout-shipping-enquiry-required'));
    }

    /**
     * Die Gegenprobe: Steht neben der Abholung noch eine echte Lieferung zur Wahl, ist
     * alles in Ordnung; dann wäre der Hinweis eine Absage an einen Kunden, der liefern
     * lassen kann.
     */
    public function testNothingAppearsWhenARealDeliveryIsAvailableBesideCollection(): void
    {
        $event = $this->confirmEvent(shippingMethods: 2);

        $this->subscriber(nonDelivery: ['sm-0'])->onConfirmPage($event);

        self::assertFalse($event->getPage()->hasExtension('rcShippingEnquiry'));
    }

    /**
     * Ohne gepflegte Liste löst nur „gar keine Versandart" aus. Wer die Einstellung nie
     * anfasst, bekommt keinen Anfrageweg neben einer einzelnen verbliebenen Versandart untergeschoben.
     */
    public function testWithoutTheListOnlyAnEmptySelectionTriggers(): void
    {
        $event = $this->confirmEvent(shippingMethods: 1);

        $this->subscriber()->onConfirmPage($event);

        self::assertFalse($event->getPage()->hasExtension('rcShippingEnquiry'));
    }

    /**
     * Bleibt gar nichts übrig, ist es keine Abholung; es gilt der Text für „gar keine
     * Versandart", nicht der für „nur Abholung".
     */
    public function testWithoutAnyMethodTheOriginalTextApplies(): void
    {
        $event = $this->confirmEvent(shippingMethods: 0);

        $this->subscriber(nonDelivery: ['sm-0'])->onConfirmPage($event);

        $extension = $event->getPage()->getExtension('rcShippingEnquiry');
        self::assertInstanceOf(ArrayStruct::class, $extension);
        self::assertFalse($extension->get('pickupOnly'));
    }

    public function testASwitchedOffEnquiryStaysSilent(): void
    {
        $event = $this->confirmEvent(shippingMethods: 0);

        $this->subscriber(enabled: false)->onConfirmPage($event);

        self::assertFalse($event->getPage()->hasExtension('rcShippingEnquiry'));
    }

    /**
     * Was: Es ist keine Zielseite eingestellt.
     * Warum: Eine Schaltfläche ins Leere ist schlimmer als keine — die Lehre aus den drei
     *        toten „Ändern"-Verweisen, die dieses Plugin schon einmal gekostet haben.
     */
    public function testWithoutATargetPageNothingAppears(): void
    {
        $event = $this->confirmEvent(shippingMethods: 0);

        $this->subscriber(categoryId: null)->onConfirmPage($event);

        self::assertFalse($event->getPage()->hasExtension('rcShippingEnquiry'));
    }

    /**
     * Der eingestellte Text schlägt den Textbaustein — Muster der Vertrauenszeile.
     */
    public function testTheConfiguredHintIsHandedToTheTemplate(): void
    {
        $event = $this->confirmEvent(shippingMethods: 0);

        $this->subscriber(hint: 'Bitte melden Sie sich bei uns.')->onConfirmPage($event);

        $extension = $event->getPage()->getExtension('rcShippingEnquiry');
        self::assertInstanceOf(ArrayStruct::class, $extension);
        self::assertSame('Bitte melden Sie sich bei uns.', $extension->get('hint'));
    }

    /**
     * Was: Die übernommene Zusammenfassung landet an der Zielseite.
     * Warum: Ohne sie wäre der Anfrageweg nur ein „bitte rufen Sie an"-Kasten — und genau
     *        der bringt nichts: Der Vertrieb nähme Artikelnummern, Mengen, Maße und
     *        Gewichte am Telefon neu auf.
     */
    public function testTheCarriedCartReachesTheTargetPage(): void
    {
        $store = $this->createMock(ShippingEnquiryStore::class);
        $store->method('take')->willReturn('10 × GVS-1 — Vordachsystem');

        $event = $this->navigationEvent();
        $this->subscriber(store: $store)->onNavigationPage($event);

        $extension = $event->getPage()->getExtension('rcShippingEnquiry');
        self::assertInstanceOf(ArrayStruct::class, $extension);
        self::assertSame('10 × GVS-1 — Vordachsystem', $extension->get('summary'));
    }

    /**
     * Ohne Übernahme bleibt jede andere Seite unberührt — die Vorlage des Kontaktformulars
     * liegt auf jeder Kontaktseite, nicht nur auf der aus dem Bestellvorgang aufgerufenen.
     */
    public function testAnOrdinaryPageVisitIsUntouched(): void
    {
        $store = $this->createMock(ShippingEnquiryStore::class);
        $store->method('take')->willReturn(null);

        $event = $this->navigationEvent();
        $this->subscriber(store: $store)->onNavigationPage($event);

        self::assertFalse($event->getPage()->hasExtension('rcShippingEnquiry'));
    }

    /**
     * Was: Das eingestellte Anschreiben liegt an der Seite.
     * Warum: Der Kunde soll ein Anschreiben vorfinden und keinen Datenblock. Steht es
     *        nicht an der Seite, kann die Vorlage es nicht setzen.
     */
    public function testTheCoveringTextIsHandedToThePage(): void
    {
        $store = $this->createMock(ShippingEnquiryStore::class);
        $store->method('take')->willReturn('10 × GVS-1 — Vordachsystem');

        $event = $this->navigationEvent();
        $this->subscriber(store: $store, intro: 'Sehr geehrte Damen und Herren,')->onNavigationPage($event);

        $extension = $event->getPage()->getExtension('rcShippingEnquiry');
        self::assertInstanceOf(ArrayStruct::class, $extension);
        self::assertSame('Sehr geehrte Damen und Herren,', $extension->get('intro'));
    }

    /**
     * Was: Die Daten des angemeldeten Kunden liegen an der Seite.
     * Warum: Shopware füllt das Kontaktformular aus der abgesendeten Eingabe, nicht aus dem
     *        Konto; ohne diese Übergabe tippt der
     *        Kunde seine Daten neu, und der Vertrieb bekommt womöglich eine andere
     *        Mailadresse als die des Kontos.
     */
    public function testTheCustomerDataIsHandedToThePage(): void
    {
        $store = $this->createMock(ShippingEnquiryStore::class);
        $store->method('take')->willReturn('10 × GVS-1 — Vordachsystem');

        $event = $this->navigationEvent($this->customer());
        $this->subscriber(store: $store)->onNavigationPage($event);

        $extension = $event->getPage()->getExtension('rcShippingEnquiry');
        self::assertInstanceOf(ArrayStruct::class, $extension);

        $customer = $extension->get('customer');
        self::assertSame('Erika', $customer['firstName']);
        self::assertSame('Mustermann', $customer['lastName']);
        self::assertSame('erika@example.invalid', $customer['email']);
        self::assertSame('0234 123456', $customer['phone']);
        self::assertSame('salutation-mrs', $customer['salutationId']);
    }

    /**
     * Die Gegenprobe: Ohne angemeldeten Kunden wird nichts geraten. Ein Formular mit
     * fremden Daten wäre schlimmer als ein leeres.
     */
    public function testWithoutACustomerNothingIsInvented(): void
    {
        $store = $this->createMock(ShippingEnquiryStore::class);
        $store->method('take')->willReturn('10 × GVS-1 — Vordachsystem');

        $event = $this->navigationEvent();
        $this->subscriber(store: $store)->onNavigationPage($event);

        $extension = $event->getPage()->getExtension('rcShippingEnquiry');
        self::assertInstanceOf(ArrayStruct::class, $extension);
        self::assertSame([], $extension->get('customer'));
    }

    /**
     * Ein Kunde ohne hinterlegte Telefonnummer darf kein leeres Feld erzeugen, das später
     * als „ausgefüllt" durchgeht.
     */
    public function testAnEmptyFieldIsLeftOutInsteadOfHandedOverBlank(): void
    {
        $store = $this->createMock(ShippingEnquiryStore::class);
        $store->method('take')->willReturn('10 × GVS-1 — Vordachsystem');

        $customer = $this->customer();
        $customer->getDefaultBillingAddress()?->setPhoneNumber('   ');

        $event = $this->navigationEvent($customer);
        $this->subscriber(store: $store)->onNavigationPage($event);

        $extension = $event->getPage()->getExtension('rcShippingEnquiry');
        self::assertInstanceOf(ArrayStruct::class, $extension);
        self::assertArrayNotHasKey('phone', $extension->get('customer'));
    }

    /**
     * @param list<string> $nonDelivery
     */
    private function subscriber(
        bool $enabled = true,
        ?string $categoryId = self::KATEGORIE,
        string $hint = '',
        ?ShippingEnquiryStore $store = null,
        array $nonDelivery = [],
        string $intro = '',
    ): ShippingEnquirySubscriber {
        $configService = $this->createMock(ConfigService::class);
        $configService->method('isShippingEnquiryEnabled')->willReturn($enabled);
        $configService->method('getShippingEnquiryCategoryId')->willReturn($categoryId);
        $configService->method('getShippingEnquiryHint')->willReturn($hint);
        $configService->method('getShippingEnquiryIntro')->willReturn($intro);
        $configService->method('getNonDeliveryMethodIds')->willReturn($nonDelivery);

        return new ShippingEnquirySubscriber(
            $configService,
            $store ?? $this->createMock(ShippingEnquiryStore::class),
            new ShippingEnquiryRule($configService),
        );
    }

    private function customer(): CustomerEntity
    {
        $address = new CustomerAddressEntity();
        $address->setId('address-1');
        $address->setUniqueIdentifier('address-1');
        $address->setPhoneNumber('0234 123456');

        $customer = new CustomerEntity();
        $customer->setId('customer-1');
        $customer->setUniqueIdentifier('customer-1');
        $customer->setSalutationId('salutation-mrs');
        $customer->setFirstName('Erika');
        $customer->setLastName('Mustermann');
        $customer->setEmail('erika@example.invalid');
        $customer->setDefaultBillingAddress($address);

        return $customer;
    }

    private function confirmEvent(int $shippingMethods): CheckoutConfirmPageLoadedEvent
    {
        $methods = [];
        for ($i = 0; $i < $shippingMethods; ++$i) {
            $method = new ShippingMethodEntity();
            $method->setId('sm-' . $i);
            $method->setUniqueIdentifier('sm-' . $i);
            $methods[] = $method;
        }

        $page = new CheckoutConfirmPage();
        $page->setCart(new Cart('token'));
        $page->setShippingMethods(new ShippingMethodCollection($methods));

        return new CheckoutConfirmPageLoadedEvent($page, $this->context(), new Request());
    }

    private function navigationEvent(?CustomerEntity $customer = null): NavigationPageLoadedEvent
    {
        return new NavigationPageLoadedEvent(new NavigationPage(), $this->context($customer), new Request());
    }

    /**
     * Was: Die Ereignisliste.
     * Warum: Ein falscher Name hier heißt, dass der Anfrageweg still nie erscheint und der
     *        Kunde vor der Sackgasse steht, gegen die er gebaut ist.
     */
    public function testItListensToBothPages(): void
    {
        self::assertSame(
            [
                CheckoutConfirmPageLoadedEvent::class => 'onConfirmPage',
                NavigationPageLoadedEvent::class => 'onNavigationPage',
            ],
            ShippingEnquirySubscriber::getSubscribedEvents()
        );
    }

    private function context(?CustomerEntity $customer = null): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-id');
        $context->method('getCustomer')->willReturn($customer);

        return $context;
    }
}
