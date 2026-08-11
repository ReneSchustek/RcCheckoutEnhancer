<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Struct;

use Shopware\Core\Framework\Struct\Struct;

/**
 * Die zuletzt abgefragte Versandkosten-Auskunft, so wie die Warenkorb-Seitenleiste
 * sie braucht.
 *
 * Mitgeführt wird ausdrücklich auch der Fingerabdruck des Warenkorbs, für den die
 * Auskunft gilt. Ohne ihn gäbe es nur zwei Möglichkeiten, und beide sind schlecht:
 * in der Leiste jedes Mal neu rechnen — eine Berechnung kostet so viele
 * Warenkorb-Durchläufe, wie es Versandarten gibt, und die Leiste geht oft auf —,
 * oder den gespeicherten Preis einfach anzeigen, womit nach jeder Mengenänderung
 * eine Zahl dasteht, die nicht mehr stimmt. Ein veralteter Versandpreis ist
 * schlechter als gar keiner: Er ist eine Zusage, die der Shop nicht hält.
 */
final class LastShippingEstimate extends Struct
{
    public function __construct(
        public readonly string $countryIso,
        public readonly string $zipCode,
        public readonly string $shippingMethodName,
        public readonly float $price,
        public readonly string $currencyIsoCode,
        public readonly string $cartFingerprint,
    ) {
    }

    /**
     * @return array<string, string|float>
     */
    public function toArray(): array
    {
        return [
            'countryIso' => $this->countryIso,
            'zipCode' => $this->zipCode,
            'shippingMethodName' => $this->shippingMethodName,
            'price' => $this->price,
            'currencyIsoCode' => $this->currencyIsoCode,
            'cartFingerprint' => $this->cartFingerprint,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        foreach (['countryIso', 'zipCode', 'shippingMethodName', 'currencyIsoCode', 'cartFingerprint'] as $field) {
            if (!\is_string($data[$field] ?? null) || $data[$field] === '') {
                return null;
            }
        }

        if (!\is_float($data['price'] ?? null) && !\is_int($data['price'] ?? null)) {
            return null;
        }

        return new self(
            (string) $data['countryIso'],
            (string) $data['zipCode'],
            (string) $data['shippingMethodName'],
            (float) $data['price'],
            (string) $data['currencyIsoCode'],
            (string) $data['cartFingerprint'],
        );
    }

    public function getApiAlias(): string
    {
        return 'rc_last_shipping_estimate';
    }
}
