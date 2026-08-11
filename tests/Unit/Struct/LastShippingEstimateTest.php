<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Struct;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Struct\LastShippingEstimate;

/**
 * Diese Struktur kommt aus der **Sitzung** zurück — also aus einer Quelle, die zwischen
 * zwei Fassungen des Plugins beliebig alt sein kann. Ein Eintrag aus 1.3.0, der nach
 * einem Update auf ein anderes Format trifft, darf keinen Fehler auslösen, sondern muss
 * schlicht als „nichts gemerkt" gelten.
 */
final class LastShippingEstimateTest extends TestCase
{
    public function testARoundTripKeepsEveryField(): void
    {
        $original = new LastShippingEstimate('DE', '44135', 'Standard', 4.95, 'EUR', 'abc');

        $wieder = LastShippingEstimate::fromArray($original->toArray());

        self::assertNotNull($wieder);
        self::assertSame('DE', $wieder->countryIso);
        self::assertSame('44135', $wieder->zipCode);
        self::assertSame('Standard', $wieder->shippingMethodName);
        self::assertSame(4.95, $wieder->price);
        self::assertSame('EUR', $wieder->currencyIsoCode);
        self::assertSame('abc', $wieder->cartFingerprint);
    }

    /**
     * Was: Ein Eintrag, dem ein Feld fehlt.
     * Warum: Genau das liegt in der Sitzung, wenn sich das Format zwischen zwei Fassungen
     *        ändert. Ein Fehler an dieser Stelle wäre eine weiße Seite beim Öffnen der
     *        Warenkorb-Leiste — für eine Bequemlichkeitsanzeige ein absurder Preis.
     * Erwartet: `null`, kein Fehler.
     */
    public function testAnIncompleteEntryIsRejected(): void
    {
        self::assertNull(LastShippingEstimate::fromArray([
            'countryIso' => 'DE',
            'zipCode' => '44135',
            // shippingMethodName fehlt
            'price' => 4.95,
            'currencyIsoCode' => 'EUR',
            'cartFingerprint' => 'abc',
        ]));
    }

    public function testAnEmptyStringIsRejected(): void
    {
        self::assertNull(LastShippingEstimate::fromArray([
            'countryIso' => '',
            'zipCode' => '44135',
            'shippingMethodName' => 'Standard',
            'price' => 4.95,
            'currencyIsoCode' => 'EUR',
            'cartFingerprint' => 'abc',
        ]));
    }

    /**
     * Was: Ein Preis, der keiner ist.
     * Warum: Wer die Sitzung von Hand bearbeitet oder ein altes Format erwischt, bekommt
     *        sonst eine Typumwandlung, die stillschweigend 0,00 € anzeigt.
     */
    public function testANonNumericPriceIsRejected(): void
    {
        self::assertNull(LastShippingEstimate::fromArray([
            'countryIso' => 'DE',
            'zipCode' => '44135',
            'shippingMethodName' => 'Standard',
            'price' => 'kostenlos',
            'currencyIsoCode' => 'EUR',
            'cartFingerprint' => 'abc',
        ]));
    }

    /**
     * Was: Ein ganzzahliger Preis.
     * Warum: Aus der Sitzung kommt `5` und nicht `5.0`, wenn der Betrag glatt war —
     *        eine Prüfung, die nur `float` durchlässt, verwürfe einen gültigen Eintrag.
     */
    public function testAnIntegerPriceIsAccepted(): void
    {
        $eintrag = LastShippingEstimate::fromArray([
            'countryIso' => 'DE',
            'zipCode' => '44135',
            'shippingMethodName' => 'Standard',
            'price' => 5,
            'currencyIsoCode' => 'EUR',
            'cartFingerprint' => 'abc',
        ]);

        self::assertNotNull($eintrag);
        self::assertSame(5.0, $eintrag->price);
    }

    public function testGetApiAlias(): void
    {
        self::assertSame(
            'rc_last_shipping_estimate',
            (new LastShippingEstimate('DE', '1', 'S', 1.0, 'EUR', 'f'))->getApiAlias(),
        );
    }
}
