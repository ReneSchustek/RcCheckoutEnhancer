<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Struct;

use Shopware\Core\Framework\Struct\Struct;

/**
 * Was die Produktseite über den Speditionsversand eines Artikels sagt.
 *
 * Die Spanne reicht vom niedrigsten bis zum höchsten Preis der günstigsten Lieferung je
 * eingestellter Postleitzahl, also je Zone, gezählt nur dort, wo diese Lieferung eine
 * Spedition ist. Stimmen beide überein, gibt es nur einen Preis, und die Vorlage schreibt
 * keinen „je nach Postleitzahl"-Satz dazu, der nichts bedeutet.
 *
 * Der Grund (`length` oder `weight`) ist eine Auskunft über den Artikel, nicht über die
 * Versandregel: Die Regeln des Shops kennen daneben noch Schlagworte und Eigenschaften, die
 * man dem Kunden nicht erklären kann. Findet sich kein Maß über den Schwellen, steht kein
 * Grund da, statt eines erfundenen.
 */
class FreightHint extends Struct
{
    public const REASON_LENGTH = 'length';

    public const REASON_WEIGHT = 'weight';

    public function __construct(
        public readonly float $minPrice,
        public readonly float $maxPrice,
        public readonly string $currencyIsoCode,
        public readonly string $countryName,
        public readonly int $quantity,
        public readonly ?string $reason = null,
        public readonly ?string $measure = null,
    ) {
    }

    /**
     * Unter einem halben Cent Abstand zeigen beide Preise gerundet denselben Betrag.
     */
    public function isRange(): bool
    {
        return abs($this->maxPrice - $this->minPrice) >= 0.005;
    }

    public function getApiAlias(): string
    {
        return 'rc_freight_hint';
    }
}
