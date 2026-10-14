<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingChoiceStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Die Trennlinie zwischen „der Kunde wollte das" und „dort ist Shopware gelandet".
 */
final class ShippingChoiceStoreTest extends TestCase
{
    private const ABHOLUNG = 'sm-abholung';

    /**
     * Was: Nichts vermerkt, dann nach der Abholung fragen.
     * Warum: So sieht es aus, wenn Shopware von selbst auf die Abholung zurückgefallen ist. Es
     *        gab nie einen Klick, also auch nie einen Vermerk.
     */
    public function testNothingWasChosenWithoutASwitch(): void
    {
        self::assertFalse($this->storeWithSession()->wasChosen(self::ABHOLUNG));
    }

    /**
     * Was: Der Kunde hat die Abholung angeklickt.
     * Warum: Danach muss sie stehen bleiben — sonst wäre sie im Bestellvorgang nicht wählbar.
     */
    public function testAChosenMethodIsRecognised(): void
    {
        $store = $this->storeWithSession();
        $store->remember(self::ABHOLUNG);

        self::assertTrue($store->wasChosen(self::ABHOLUNG));
    }

    /**
     * Was: Erst die Abholung wählen, dann zurück auf eine Lieferung.
     * Warum: Der Vermerk muss auch wieder verschwinden. Bliebe er stehen, gälte die Abholung
     *        für den Rest der Sitzung als gewollt, auch nachdem der Kunde sie abgewählt hat.
     */
    public function testSwitchingAwayClearsTheEarlierChoice(): void
    {
        $store = $this->storeWithSession();
        $store->remember(self::ABHOLUNG);

        $store->remember('sm-paket');

        self::assertFalse($store->wasChosen(self::ABHOLUNG));
        self::assertTrue($store->wasChosen('sm-paket'));
    }

    /**
     * Was: Gar keine Sitzung.
     * Warum: Hier ist `true` die vorsichtige Antwort. Ohne Sitzung lässt sich eine eigene Wahl
     *        nicht nachhalten, und ein stilles Umschalten wäre der schwerere Fehler, weil es
     *        niemand bemerkt und niemand rückgängig machen kann.
     */
    public function testWithoutASessionEverythingCountsAsChosen(): void
    {
        $store = new ShippingChoiceStore(new RequestStack());

        self::assertTrue($store->wasChosen(self::ABHOLUNG));
    }

    private function storeWithSession(): ShippingChoiceStore
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        $stack = new RequestStack();
        $stack->push($request);

        return new ShippingChoiceStore($stack);
    }
}
