<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Struct;

use Shopware\Core\Framework\Struct\Struct;

/**
 * Die zuletzt abgefragte Versandkosten-Auskunft, so wie die Warenkorb-Seitenleiste
 * sie braucht.
 *
 * Mitgeführt wird auch der Fingerabdruck des Warenkorbs, für den die Auskunft gilt.
 * Ohne ihn bliebe nur, in der Leiste jedes Mal neu zu rechnen, was je verfügbarer
 * Versandart einen Warenkorb-Durchlauf kostet und bei einer oft geöffneten Leiste ins
 * Gewicht fällt, oder den gespeicherten Preis einfach anzuzeigen, der nach jeder
 * Mengenänderung nicht mehr stimmt. Ein veralteter Versandpreis ist schlechter als gar
 * keiner, weil er eine Zusage ist, die der Shop nicht hält.
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
