<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingEnquiryStore;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Der Weg der Warenkorb-Zusammenfassung von der Bestätigungsseite zum Kontaktformular.
 *
 * Der wichtigste Punkt ist das Vergessen. Der Eintrag gilt für genau einen Weg. Bliebe er
 * stehen, fände der Kunde beim nächsten Besuch der Kontaktseite einen Warenkorb vor, den es
 * womöglich nicht mehr gibt, und schickte ihn ahnungslos ab.
 */
final class ShippingEnquiryStoreTest extends TestCase
{
    public function testItHandsTheSummaryOverExactlyOnce(): void
    {
        $store = $this->store();

        $store->remember('2 × Handlauf, 47,50 €');

        self::assertSame('2 × Handlauf, 47,50 €', $store->take());
        self::assertNull($store->take(), 'Der Eintrag muss beim Lesen vergessen werden.');
    }

    /**
     * Ohne vorherigen Eintrag darf nichts kommen — sonst erschiene auf der Kontaktseite ein
     * Kasten ohne Inhalt.
     */
    public function testItReturnsNothingWhenNothingWasRemembered(): void
    {
        self::assertNull($this->store()->take());
    }

    /**
     * Eine leere Zeichenkette ist kein Warenkorb. Sie entsteht, wenn die Zusammenfassung aus
     * einem Warenkorb ohne Positionen gebaut wird.
     */
    public function testItTreatsAnEmptySummaryAsNothing(): void
    {
        $store = $this->store();

        $store->remember('');

        self::assertNull($store->take());
    }

    private function store(): ShippingEnquiryStore
    {
        $session = new Session(new MockArraySessionStorage());
        $requestStack = new RequestStack();
        $requestStack->push(new \Symfony\Component\HttpFoundation\Request());
        $requestStack->getCurrentRequest()?->setSession($session);

        return new ShippingEnquiryStore($requestStack);
    }
}
