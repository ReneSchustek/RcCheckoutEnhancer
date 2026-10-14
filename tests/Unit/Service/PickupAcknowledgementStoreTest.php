<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\PickupAcknowledgementStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Der Speicher der Bestätigung — und warum er am Wortlaut hängt, nicht an einem Häkchen.
 */
final class PickupAcknowledgementStoreTest extends TestCase
{
    private const HINWEIS = 'Verladen und Transport liegen bei Ihnen.';

    /**
     * Was: Bestätigen, dann denselben Wortlaut abfragen.
     * Warum: Der Hauptfall.
     */
    public function testAnAcknowledgedWordingIsRecognised(): void
    {
        $store = $this->storeWithSession();

        self::assertTrue($store->acknowledge(self::HINWEIS, new \DateTimeImmutable()));
        self::assertTrue($store->isAcknowledged(self::HINWEIS));
    }

    /**
     * Was: Der Betreiber ändert den Hinweistext, während der Kunde im Bestellvorgang steht.
     * Warum: Deshalb steht der Wortlaut im Speicher. Eine Bestätigung gilt für den Satz, der
     *        bestätigt wurde, nicht für den, der danach dort steht. Sonst hätte
     *        der Kunde einer Zusage zugestimmt, die er nie gesehen hat.
     */
    public function testAChangedWordingNeedsANewAcknowledgement(): void
    {
        $store = $this->storeWithSession();
        $store->acknowledge(self::HINWEIS, new \DateTimeImmutable());

        self::assertFalse($store->isAcknowledged('Ein anderer Satz mit anderen Zusagen.'));
    }

    /**
     * Was: Der festgehaltene Datensatz, wie er an die Bestellung wandert.
     * Warum: Zeitpunkt und Wortlaut müssen beide dastehen. Der Zeitpunkt allein sagt nicht,
     *        was bestätigt wurde, der Wortlaut allein nicht wann.
     */
    public function testTheStoredRecordCarriesWordingAndTimestamp(): void
    {
        $store = $this->storeWithSession();
        $store->acknowledge(self::HINWEIS, new \DateTimeImmutable('2026-08-25T09:30:00+02:00'));

        $stored = $store->stored();

        self::assertNotNull($stored);
        self::assertSame(self::HINWEIS, $stored['hint']);
        self::assertSame('2026-08-25T09:30:00+02:00', $stored['at']);
    }

    /**
     * Was: Gar keine Sitzung.
     * Warum: Der Rückgabewert `false` sagt dem Aufrufer, dass er keinen Erfolg melden darf.
     */
    public function testWithoutASessionNothingIsStored(): void
    {
        $store = new PickupAcknowledgementStore(new RequestStack());

        self::assertFalse($store->acknowledge(self::HINWEIS, new \DateTimeImmutable()));
        self::assertNull($store->stored());
        self::assertFalse($store->isAcknowledged(self::HINWEIS));
    }

    /**
     * Was: Nach dem Bestellabschluss aufräumen.
     * Warum: Bliebe die Bestätigung stehen, trüge die nächste Bestellung desselben Besuchers sie
     *        mit, ohne dass je ein Dialog erschienen wäre.
     */
    public function testClearingRemovesTheAcknowledgement(): void
    {
        $store = $this->storeWithSession();
        $store->acknowledge(self::HINWEIS, new \DateTimeImmutable());

        $store->clear();

        self::assertFalse($store->isAcknowledged(self::HINWEIS));
    }

    private function storeWithSession(): PickupAcknowledgementStore
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        $stack = new RequestStack();
        $stack->push($request);

        return new PickupAcknowledgementStore($stack);
    }
}
