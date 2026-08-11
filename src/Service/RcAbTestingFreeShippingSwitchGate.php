<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FrontendSwitchResolver;
use Throwable;

/**
 * Bindet den RcAbTesting-Frontend-Schalter `free_shipping_indicator` an. Der
 * Resolver ist optional (services.xml `on-invalid="null"`): fehlt RcAbTesting oder
 * läuft kein Schalter-Experiment, ist `$resolver` null und der Indikator wird nie
 * unterdrückt — RcCheckout funktioniert unverändert ohne RcAbTesting.
 *
 * Der Schlüssel/Wert ist bewusst als Literal hinterlegt statt über eine
 * RcAbTesting-Konstante, damit ohne installiertes RcAbTesting keine dortige Klasse
 * geladen wird (der nullable Typ-Hint verlangt bei null keinen Autoload).
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
            // Fail-Soft: der Indikator ist optionaler Zusatz — ein Fehler im Fremd-Plugin-Resolver
            // darf die Warenkorb-/Offcanvas-Seite nie mit einem 500 abreißen. Im Zweifel anzeigen.
            return false;
        }
    }
}
