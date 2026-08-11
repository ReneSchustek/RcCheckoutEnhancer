<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Struct;

use Shopware\Core\Framework\Struct\Struct;

/**
 * Das Ergebnis einer Versandkosten-Anfrage.
 *
 * Der Zustand wird ausdrücklich mitgeführt, statt ihn aus einer leeren Liste zu
 * erraten: „keine Versandart in dieses Land" und „die Berechnung ist gescheitert"
 * sehen sonst gleich aus, verlangen dem Kunden gegenüber aber gegensätzliche
 * Aussagen — das eine ist eine Auskunft, das andere eine Entschuldigung.
 */
class ShippingEstimateResult extends Struct
{
    public const STATE_OK = 'ok';
    public const STATE_NO_SHIPPING = 'no_shipping';
    public const STATE_ERROR = 'error';

    /**
     * @param list<ShippingEstimate> $estimates
     */
    private function __construct(
        public readonly string $state,
        public readonly array $estimates,
        public readonly string $countryIso,
        public readonly string $zipCode,
    ) {
    }

    /**
     * @param list<ShippingEstimate> $estimates
     */
    public static function withShippingMethods(array $estimates, string $countryIso, string $zipCode): self
    {
        return new self(self::STATE_OK, $estimates, $countryIso, $zipCode);
    }

    public static function withoutShippingMethod(string $countryIso, string $zipCode): self
    {
        return new self(self::STATE_NO_SHIPPING, [], $countryIso, $zipCode);
    }

    public static function failed(string $countryIso, string $zipCode): self
    {
        return new self(self::STATE_ERROR, [], $countryIso, $zipCode);
    }

    public function isSuccessful(): bool
    {
        return $this->state === self::STATE_OK;
    }

    public function getApiAlias(): string
    {
        return 'rc_shipping_estimate_result';
    }
}
