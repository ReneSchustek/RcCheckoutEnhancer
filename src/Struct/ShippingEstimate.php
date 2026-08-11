<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Struct;

use Shopware\Core\Framework\Struct\Struct;

/**
 * Eine Versandart mit dem Preis, den sie für den aktuellen Warenkorb und die
 * angefragte Adresse kostet.
 *
 * Der Preis kommt aus der Shopware-eigenen Berechnung und trägt den Steuer-Zustand
 * des Verkaufskanals — bei einem Netto-Kanal steht hier netto, bei einem Brutto-Kanal
 * brutto. Wer ihn anzeigt, darf ihn deshalb nicht nachträglich umrechnen.
 */
class ShippingEstimate extends Struct
{
    public function __construct(
        public readonly string $shippingMethodId,
        public readonly string $name,
        public readonly float $price,
        public readonly string $currencyIsoCode,
        public readonly ?string $deliveryTimeName = null,
    ) {
    }

    public function getApiAlias(): string
    {
        return 'rc_shipping_estimate';
    }
}
