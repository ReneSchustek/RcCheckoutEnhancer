<?php

declare(strict_types=1);

/**
 * PHPStan-Stubs der optionalen RcAbTesting-Integration (RCHK02). RcAbTesting ist keine
 * harte Abhängigkeit (Runtime via services.xml on-invalid="null"); diese Stubs liefern
 * PHPStan nur die Signaturen der referenzierten Symbole, damit die statische Analyse ohne
 * ausgechecktes Nachbar-Plugin (z. B. im CI) durchläuft.
 *
 * Bewusst minimal: hier steht ausschließlich, was RcCheckout-Code und -Tests tatsächlich
 * benutzen. Wer eine weitere RcAbTesting-Signatur braucht, ergänzt sie hier — abgeglichen
 * gegen das echte Plugin, nicht geraten.
 */

namespace Ruhrcoder\RcAbTesting\Service\FrontendSwitch {
    // Bewusst nicht `final`: Der Ausfallschutz des Schalters wird gegen einen Doppel
    // geprüft, und eine `final`-Klasse lässt sich nicht ableiten. Im echten Plugin
    // bleibt die Klasse `final` — dieser Stub steht nur für Analyse und Tests.
    class FrontendSwitchResolver
    {
        public function resolve(string $switchKey): ?string
        {
            return null;
        }

        public function reset(): void
        {
        }
    }
}

namespace Ruhrcoder\RcAbTesting\Service {
    final class ExperimentRegistry
    {
        public function invalidate(): void
        {
        }
    }

    final class RequestVariantResolver
    {
        public function reset(): void
        {
        }
    }

    final class VisitorIdResolver
    {
        public const REQUEST_ATTRIBUTE = 'rc_ab_visitor_id';
        public const PERSISTENT_ATTRIBUTE = 'rc_ab_visitor_persistent';
    }
}

namespace Ruhrcoder\RcAbTesting\Core\Content\AbExperiment {
    final class AbExperimentStatus
    {
        public const RUNNING = 'running';
    }
}
