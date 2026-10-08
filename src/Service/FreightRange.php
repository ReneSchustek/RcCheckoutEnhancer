<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Ruhrcoder\RcCheckoutEnhancer\Struct\ShippingEstimate;
use Ruhrcoder\RcCheckoutEnhancer\Struct\ShippingEstimateResult;

/**
 * Liest aus den Auskünften je Postleitzahl ab, ob ein Artikel per Spedition geht, und zu
 * welcher Spanne.
 *
 * Maßgeblich ist die günstigste Lieferung, nicht irgendeine Speditionsart. Steht an einer
 * Postleitzahl neben der Spedition auch der Paketdienst zur Wahl, nimmt der Kunde den
 * Paketdienst, und die Spedition ist für ihn keine Auskunft wert. Die Abholung zählt dabei
 * nicht mit: Sie kostet nichts und wäre sonst immer die günstigste „Lieferung".
 *
 * Eigener Baustein ohne Abhängigkeiten, weil diese Entscheidung falsch werden kann, ohne dass
 * etwas abstürzt; so lässt sie sich lückenlos testen.
 */
final class FreightRange
{
    /**
     * @param array<array-key, ShippingEstimateResult> $resultsByZip Schlüssel ist die Postleitzahl; PHP macht aus „96215" eine Ganzzahl
     * @param list<string>                          $freightMethodIds
     * @param list<string>                          $nonDeliveryMethodIds
     *
     * @return array{min: float, max: float, currency: string}|null
     */
    public static function of(array $resultsByZip, array $freightMethodIds, array $nonDeliveryMethodIds): ?array
    {
        $prices = [];
        $currency = null;

        foreach ($resultsByZip as $result) {
            if (!$result->isSuccessful()) {
                continue;
            }

            $cheapest = self::cheapestDelivery($result->estimates, $nonDeliveryMethodIds);
            if ($cheapest === null || !\in_array($cheapest->shippingMethodId, $freightMethodIds, true)) {
                continue;
            }

            $prices[] = $cheapest->price;
            $currency ??= $cheapest->currencyIsoCode;
        }

        if ($prices === [] || $currency === null) {
            return null;
        }

        return ['min' => min($prices), 'max' => max($prices), 'currency' => $currency];
    }

    /**
     * @param list<ShippingEstimate> $estimates
     * @param list<string>           $nonDeliveryMethodIds
     */
    public static function cheapestDelivery(array $estimates, array $nonDeliveryMethodIds): ?ShippingEstimate
    {
        $cheapest = null;

        foreach ($estimates as $estimate) {
            if (\in_array($estimate->shippingMethodId, $nonDeliveryMethodIds, true)) {
                continue;
            }

            if ($cheapest === null || $estimate->price < $cheapest->price) {
                $cheapest = $estimate;
            }
        }

        return $cheapest;
    }
}
