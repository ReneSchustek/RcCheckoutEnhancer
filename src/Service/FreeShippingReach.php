<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

/**
 * Die Antwort auf „gilt Versandkostenfreiheit für diesen Lieferort?" — mit drei
 * Ausgängen statt zwei.
 *
 * Der dritte, `unknown`, ist der wichtige: Er trennt „nein, hier nicht" von „das lässt
 * sich aus der Regel nicht ablesen". Aus einem Ja/Nein hätte der Aufrufer für beide
 * Fälle dieselbe Anzeige gewählt, und eine davon wäre falsch gewesen — entweder ein
 * Versprechen, das nicht gilt, oder ein Shop, der still aufhört zu werben.
 */
final class FreeShippingReach
{
    /**
     * @param list<string> $countryIds
     * @param list<string> $countryNames
     * @param float|null   $threshold Der Warenwert aus der Regel, ab dem versandkostenfrei
     *                                geliefert wird. `null` heißt: nicht auslesbar — dann
     *                                gilt die Einstellung als Rückfall.
     */
    private function __construct(
        public readonly bool $applies,
        public readonly bool $certain,
        public readonly array $countryIds,
        public readonly array $countryNames = [],
        public readonly ?float $threshold = null,
    ) {
    }

    /**
     * @param list<string> $countryIds
     * @param list<string> $countryNames
     */
    public static function reachable(array $countryIds, array $countryNames = [], ?float $threshold = null): self
    {
        return new self(true, true, $countryIds, $countryNames, $threshold);
    }

    public static function outOfReach(?float $threshold = null): self
    {
        return new self(false, true, [], [], $threshold);
    }

    public static function unknown(): self
    {
        return new self(true, false, []);
    }
}
