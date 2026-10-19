<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FrontendSwitchValueResolver;
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
 *
 * Gebunden ist die Schnittstelle `FrontendSwitchValueResolver`, die RcAbTesting als öffentlichen Alias
 * anbietet, nicht die `final`-Klasse dahinter. So bleibt der Dienst dekorierbar, und eine Änderung an der
 * Klasse bricht dieses Plugin nicht.
 */
final class RcAbTestingFreeShippingSwitchGate implements FreeShippingSwitchGate
{
    private const SWITCH_KEY = 'free_shipping_indicator';
    private const VALUE_OFF = 'off';

    public function __construct(
        private readonly ?FrontendSwitchValueResolver $resolver = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function isIndicatorSuppressed(): bool
    {
        if ($this->resolver === null) {
            return false;
        }

        try {
            return $this->resolver->resolve(self::SWITCH_KEY) === self::VALUE_OFF;
        } catch (Throwable $e) {
            // Ein Fehler im Fremd-Plugin darf Warenkorbseite und Leiste nicht mit einem 500 abbrechen; der Indikator
            // ist ein Zusatz und wird im Zweifel gezeigt. Protokolliert, damit ein dauerhaft defekter A/B-Test
            // auffällt.
            $this->logger->warning('A/B-Schalter für den Versandkostenfrei-Hinweis nicht lesbar', [
                'switch' => self::SWITCH_KEY,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
