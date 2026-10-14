<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\GuestAccountOffer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Die Vormerkung des Gasts in der Sitzung. Sie entscheidet, wessen Konto ein Kennwort bekommt; ein
 * Fehler hier gäbe einem Fremden ein Kundenkonto.
 */
final class GuestAccountOfferTest extends TestCase
{
    public function testARememberedOfferIsPending(): void
    {
        $offer = $this->offer();
        $now = new DateTimeImmutable('2026-10-05 12:00:00');

        $offer->remember('kunde-1', 'gast@example.test', $now);

        self::assertSame(['customerId' => 'kunde-1', 'email' => 'gast@example.test'], $offer->pending($now->modify('+29 minutes')));
    }

    /**
     * Was: 31 Minuten nach der Bestellung.
     * Warum: Wer später an denselben Rechner kommt, soll nichts mehr vorfinden.
     */
    public function testAnOfferExpiresAfterThirtyMinutesAndIsDropped(): void
    {
        $offer = $this->offer();
        $now = new DateTimeImmutable('2026-10-05 12:00:00');
        $offer->remember('kunde-1', 'gast@example.test', $now);

        self::assertNull($offer->pending($now->modify('+31 minutes')));
        self::assertNull($offer->pending($now), 'verworfen, nicht nur übersprungen');
    }

    public function testAForgottenOfferIsGone(): void
    {
        $offer = $this->offer();
        $now = new DateTimeImmutable();
        $offer->remember('kunde-1', 'gast@example.test', $now);

        $offer->forget();

        self::assertNull($offer->pending($now));
    }

    public function testWithoutAnOfferNothingIsPending(): void
    {
        self::assertNull($this->offer()->pending(new DateTimeImmutable()));
    }

    private function offer(): GuestAccountOffer
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);

        return new GuestAccountOffer($stack);
    }
}
