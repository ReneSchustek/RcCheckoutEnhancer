<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

/**
 * Entscheidet, ob der Versandkostenfrei-Indikator für den aktuellen Besucher
 * unterdrückt wird, also die Brücke zu einem optionalen A/B-Test (RcAbTesting).
 * Über diese schmale Schnittstelle bleibt der Subscriber ohne harte Abhängigkeit zu
 * RcAbTesting und im Test ersetzbar. Die Anbindung an den Schalter liegt in
 * {@see RcAbTestingFreeShippingSwitchGate}.
 */
interface FreeShippingSwitchGate
{
    public function isIndicatorSuppressed(): bool;
}
