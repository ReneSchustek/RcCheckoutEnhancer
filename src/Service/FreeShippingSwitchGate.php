<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

/**
 * Entscheidet, ob der Versandkostenfrei-Indikator für den aktuellen Besucher
 * unterdrückt wird — die Brücke zu einem optionalen A/B-Test (RcAbTesting).
 * Bewusst ein eigenes, schmales Interface: der Subscriber bleibt so ohne harte
 * Abhängigkeit zu RcAbTesting und im Test mockbar. Die konkrete Anbindung an den
 * Schalter liegt in {@see RcAbTestingFreeShippingSwitchGate}.
 */
interface FreeShippingSwitchGate
{
    public function isIndicatorSuppressed(): bool;
}
