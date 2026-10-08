<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FrontendSwitchResolver;
use Throwable;

/**
 * Bindet den RcAbTesting-Frontend-Schalter `free_shipping_indicator` an. Der
 * Resolver ist optional (services.xml `on-invalid="null"`): fehlt RcAbTesting oder
 * läuft kein Schalter-Experiment, ist `$resolver` null und der Indikator wird nie
 * unterdrückt; er läuft dann ohne A/B-Test.
 *
 * Schlüssel und Wert stehen als Literal da und nicht als RcAbTesting-Konstante, damit
 * ohne installiertes RcAbTesting keine dortige Klasse geladen wird. Der nullable
 * Typ-Hint verlangt bei null keinen Autoload.
 */
final class RcAbTestingFreeShippingSwitchGate implements FreeShippingSwitchGate
{
    private const SWITCH_KEY = 'free_shipping_indicator';
    private const VALUE_OFF = 'off';

    public function __construct(
        private readonly ?FrontendSwitchResolver $resolver = null,
    ) {
    }

    public function isIndicatorSuppressed(): bool
    {
        if ($this->resolver === null) {
            return false;
        }

        try {
            return $this->resolver->resolve(self::SWITCH_KEY) === self::VALUE_OFF;
        } catch (Throwable) {
            // Ein Fehler im Fremd-Plugin darf Warenkorbseite und Leiste nicht mit einem 500
            // abbrechen; der Indikator ist ein Zusatz. Im Zweifel wird er gezeigt.
            return false;
        }
    }
}
