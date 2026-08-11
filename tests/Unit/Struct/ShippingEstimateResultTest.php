<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Struct;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Struct\ShippingEstimate;
use Ruhrcoder\RcCheckoutEnhancer\Struct\ShippingEstimateResult;
use Shopware\Core\Framework\Uuid\Uuid;

final class ShippingEstimateResultTest extends TestCase
{
    public function testWithShippingMethodsIsSuccessful(): void
    {
        $estimate = new ShippingEstimate(Uuid::randomHex(), 'Standard', 4.9, 'EUR', '3-5 Werktage');

        $result = ShippingEstimateResult::withShippingMethods([$estimate], 'DE', '44787');

        self::assertSame(ShippingEstimateResult::STATE_OK, $result->state);
        self::assertTrue($result->isSuccessful());
        self::assertSame([$estimate], $result->estimates);
        self::assertSame('DE', $result->countryIso);
        self::assertSame('44787', $result->zipCode);
    }

    /**
     * „Keine Versandart dorthin" und „die Berechnung ist gescheitert" sehen in einer
     * leeren Liste gleich aus, verlangen dem Kunden gegenüber aber gegensätzliche
     * Aussagen — eine Auskunft gegen eine Entschuldigung. Deshalb der ausdrückliche
     * Zustand statt einer Vermutung aus der Listenlänge.
     */
    public function testWithoutShippingMethodIsNotAnError(): void
    {
        $result = ShippingEstimateResult::withoutShippingMethod('AT', '1010');

        self::assertSame(ShippingEstimateResult::STATE_NO_SHIPPING, $result->state);
        self::assertFalse($result->isSuccessful());
        self::assertSame([], $result->estimates);
        self::assertNotSame($result->state, ShippingEstimateResult::failed('AT', '1010')->state);
    }

    public function testFailedCarriesTheErrorState(): void
    {
        $result = ShippingEstimateResult::failed('DE', '44787');

        self::assertSame(ShippingEstimateResult::STATE_ERROR, $result->state);
        self::assertFalse($result->isSuccessful());
        self::assertSame([], $result->estimates);
    }

    public function testApiAliases(): void
    {
        self::assertSame(
            'rc_shipping_estimate_result',
            ShippingEstimateResult::failed('DE', '44787')->getApiAlias(),
        );
        self::assertSame(
            'rc_shipping_estimate',
            (new ShippingEstimate(Uuid::randomHex(), 'Standard', 0.0, 'EUR', null))->getApiAlias(),
        );
    }

    public function testEstimateCarriesItsValues(): void
    {
        $id = Uuid::randomHex();

        $estimate = new ShippingEstimate($id, 'Express', 12.5, 'CHF', null);

        self::assertSame($id, $estimate->shippingMethodId);
        self::assertSame('Express', $estimate->name);
        self::assertSame(12.5, $estimate->price);
        self::assertSame('CHF', $estimate->currencyIsoCode);
        self::assertNull($estimate->deliveryTimeName);
    }
}
