<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreightRange;
use Ruhrcoder\RcCheckoutEnhancer\Struct\ShippingEstimate;
use Ruhrcoder\RcCheckoutEnhancer\Struct\ShippingEstimateResult;

/**
 * Die Entscheidung, ob ein Artikel per Spedition geht, und zu welcher Spanne.
 *
 * Sie kann falsch werden, ohne dass etwas abstürzt: Ein Paketartikel mit Speditionshinweis
 * schreckt ab, und ein Speditionsartikel ohne ihn überrascht den Kunden erst im Warenkorb mit
 * dem Frachtpreis.
 */
final class FreightRangeTest extends TestCase
{
    private const SPEDITION_ZONE_1 = 'sm-sped-1';

    private const SPEDITION_ZONE_2 = 'sm-sped-2';

    private const PAKET = 'sm-paket';

    private const ABHOLUNG = 'sm-abholung';

    private const FREIGHT = [self::SPEDITION_ZONE_1, self::SPEDITION_ZONE_2];

    private const NON_DELIVERY = [self::ABHOLUNG];

    /**
     * Was: Zwei Zonen, je nur Spedition und Abholung.
     * Warum: Der Normalfall des langen Rohrs — die Spanne reicht von der günstigsten zur teuersten Zone.
     */
    public function testTheRangeSpansTheZones(): void
    {
        $range = FreightRange::of([
            '96215' => $this->ok([$this->estimate(self::SPEDITION_ZONE_1, 101.15), $this->estimate(self::ABHOLUNG, 0.0)]),
            '34117' => $this->ok([$this->estimate(self::SPEDITION_ZONE_2, 104.72), $this->estimate(self::ABHOLUNG, 0.0)]),
        ], self::FREIGHT, self::NON_DELIVERY);

        self::assertSame(['min' => 101.15, 'max' => 104.72, 'currency' => 'EUR'], $range);
    }

    /**
     * Was: Die Abholung kostet 0,00 € und steht in jeder Auskunft.
     * Warum: Zählte sie als Lieferung, wäre sie immer die günstigste, und
     *        kein Artikel bekäme je einen Hinweis.
     */
    public function testSelfCollectionIsNotADelivery(): void
    {
        $range = FreightRange::of([
            '96215' => $this->ok([$this->estimate(self::ABHOLUNG, 0.0), $this->estimate(self::SPEDITION_ZONE_1, 101.15)]),
        ], self::FREIGHT, self::NON_DELIVERY);

        self::assertNotNull($range);
        self::assertSame(101.15, $range['min']);
    }

    /**
     * Was: Neben der Spedition steht auch der Paketdienst zur Wahl.
     * Warum: Dann nimmt der Kunde den Paketdienst. Ein Speditionshinweis wäre eine falsche
     *        Auskunft über einen Artikel, der gar nicht per Spedition kommen muss.
     */
    public function testACheaperParcelServiceMeansNoHint(): void
    {
        $range = FreightRange::of([
            '96215' => $this->ok([$this->estimate(self::PAKET, 8.93), $this->estimate(self::SPEDITION_ZONE_1, 101.15)]),
        ], self::FREIGHT, self::NON_DELIVERY);

        self::assertNull($range);
    }

    /**
     * Was: Der Artikel geht nur per Paket.
     * Warum: Der häufigste Fall überhaupt — kein Hinweis.
     */
    public function testAParcelOnlyItemGetsNoHint(): void
    {
        $range = FreightRange::of([
            '96215' => $this->ok([$this->estimate(self::PAKET, 8.93)]),
            '34117' => $this->ok([$this->estimate(self::PAKET, 8.93)]),
        ], self::FREIGHT, self::NON_DELIVERY);

        self::assertNull($range);
    }

    /**
     * Was: Eine Zone liefert keine Versandart, eine andere scheitert, eine dritte rechnet.
     * Warum: Die Spanne stützt sich auf das, was belegt ist. Eine gescheiterte Berechnung darf
     *        weder als 0,00 € noch als „keine Spedition" hineinrutschen.
     */
    public function testFailedAndEmptyZonesAreLeftOut(): void
    {
        $range = FreightRange::of([
            '96215' => ShippingEstimateResult::failed('DE', '96215'),
            '34117' => ShippingEstimateResult::withoutShippingMethod('DE', '34117'),
            '20095' => $this->ok([$this->estimate(self::SPEDITION_ZONE_2, 107.10)]),
        ], self::FREIGHT, self::NON_DELIVERY);

        self::assertSame(['min' => 107.10, 'max' => 107.10, 'currency' => 'EUR'], $range);
    }

    /**
     * Was: Keine Versandart ist als Spedition eingestellt.
     * Warum: Ob eine Versandart eine Spedition ist, steht nirgends im Kern. Ohne Einstellung
     *        gibt es keinen Hinweis — geraten wird nicht.
     */
    public function testWithoutConfiguredFreightMethodsThereIsNoHint(): void
    {
        $range = FreightRange::of([
            '96215' => $this->ok([$this->estimate(self::SPEDITION_ZONE_1, 101.15)]),
        ], [], self::NON_DELIVERY);

        self::assertNull($range);
    }

    /**
     * @param list<ShippingEstimate> $estimates
     */
    private function ok(array $estimates): ShippingEstimateResult
    {
        return ShippingEstimateResult::withShippingMethods($estimates, 'DE', '00000');
    }

    private function estimate(string $id, float $price): ShippingEstimate
    {
        return new ShippingEstimate($id, $id, $price, 'EUR');
    }
}
