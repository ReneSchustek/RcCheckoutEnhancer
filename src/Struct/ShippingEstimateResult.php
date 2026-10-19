<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Struct;

use Shopware\Core\Framework\Struct\Struct;

/**
 * Das Ergebnis einer Versandkosten-Anfrage.
 *
 * Der Zustand wird mitgeführt, statt ihn aus einer leeren Liste zu erraten: „keine
 * Versandart in dieses Land" und „die Berechnung ist gescheitert" sähen sonst gleich
 * aus, verlangen dem Kunden gegenüber aber verschiedene Aussagen. Das eine ist eine
 * Auskunft, das andere eine Entschuldigung.
 */
final class ShippingEstimateResult extends Struct
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
        public readonly int $quantity = 0,
    ) {
    }

    /**
     * Dieselbe Auskunft mit der Menge, für die gerechnet wurde. Der Rechner der Produktseite nennt diese Menge im
     * Text; die eingegebene kann davon abweichen (Mindest- und Höchstabnahme, Staffel). 0 heißt unbekannt.
     */
    public function withQuantity(int $quantity): self
    {
        return new self($this->state, $this->estimates, $this->countryIso, $this->zipCode, $quantity);
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

    /**
     * Dieselbe Auskunft ohne die genannten Versandarten. Bleibt keine übrig, heißt das „kein Versand".
     *
     * @param list<string> $shippingMethodIds
     */
    public function without(array $shippingMethodIds): self
    {
        if ($this->state !== self::STATE_OK || $shippingMethodIds === []) {
            return $this;
        }

        $estimates = array_values(array_filter(
            $this->estimates,
            static fn (ShippingEstimate $estimate): bool => !\in_array($estimate->shippingMethodId, $shippingMethodIds, true),
        ));

        return $estimates === []
            ? new self(self::STATE_NO_SHIPPING, [], $this->countryIso, $this->zipCode, $this->quantity)
            : new self(self::STATE_OK, $estimates, $this->countryIso, $this->zipCode, $this->quantity);
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
