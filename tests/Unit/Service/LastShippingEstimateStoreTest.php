<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\LastShippingEstimateStore;
use Ruhrcoder\RcCheckoutEnhancer\Struct\ShippingEstimate;
use Ruhrcoder\RcCheckoutEnhancer\Struct\ShippingEstimateResult;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class LastShippingEstimateStoreTest extends TestCase
{
    /**
     * Was: Mehrere Versandarten mit verschiedenen Preisen.
     * Warum: Die Leiste hat Platz für eine Zeile. Gemerkt wird die günstigste — die
     *        vollständige Liste steht auf der Warenkorb-Seite, wo sie hingehört.
     */
    public function testTheCheapestMethodIsRemembered(): void
    {
        $store = $this->store($session);

        $store->remember(ShippingEstimateResult::withShippingMethods([
            new ShippingEstimate('sm-1', 'Express', 12.90, 'EUR'),
            new ShippingEstimate('sm-2', 'Standard', 4.95, 'EUR'),
        ], 'DE', '44135'), 'fingerabdruck');

        $remembered = $store->get();

        self::assertNotNull($remembered);
        self::assertSame('Standard', $remembered->shippingMethodName);
        self::assertSame(4.95, $remembered->price);
        self::assertSame('fingerabdruck', $remembered->cartFingerprint);
    }

    /**
     * Was: Eine Abfrage, für die es keine Versandart gibt.
     * Warum: „Kein Versand in dieses Land" ist keine Auskunft, die in die Leiste gehört —
     *        das wäre eine Absage ohne Zusammenhang. Außerdem darf eine frühere, gültige
     *        Auskunft dadurch nicht stehen bleiben.
     * Erwartet: nichts gemerkt, und Vorheriges ist weg.
     */
    public function testAResultWithoutShippingClearsWhatWasRemembered(): void
    {
        $store = $this->store($session);

        $store->remember(ShippingEstimateResult::withShippingMethods([
            new ShippingEstimate('sm-1', 'Standard', 4.95, 'EUR'),
        ], 'DE', '44135'), 'fingerabdruck');
        self::assertNotNull($store->get());

        $store->remember(ShippingEstimateResult::withoutShippingMethod('AT', '1010'), 'fingerabdruck');

        self::assertNull($store->get());
    }

    /**
     * Was: Eine gescheiterte Berechnung.
     * Warum: Eine Entschuldigung gehört nicht an eine Stelle, an der niemand danach
     *        gefragt hat.
     */
    public function testAFailedResultIsNotRemembered(): void
    {
        $store = $this->store($session);

        $store->remember(ShippingEstimateResult::failed('DE', '44135'), 'fingerabdruck');

        self::assertNull($store->get());
    }

    public function testWithoutAnythingRememberedTheAnswerIsNull(): void
    {
        self::assertNull($this->store($session)->get());
    }

    /**
     * Reicht die angelegte Sitzung an den Aufrufer zurück, damit er hineinschauen kann.
     *
     * @param-out Session $session
     */
    private function store(?Session &$session = null): LastShippingEstimateStore
    {
        $session = new Session(new MockArraySessionStorage());

        $requestStack = $this->createMock(RequestStack::class);
        $requestStack->method('getSession')->willReturn($session);

        return new LastShippingEstimateStore($requestStack);
    }
}
