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
    // Die Schnittstelle, über die dieses Plugin die Schalterwerte liest; abgeglichen
    // gegen RcAbTesting 1.17.0.
    interface FrontendSwitchValueResolver
    {
        public function resolve(string $switchKey): ?string;

        /**
         * @return array<string, string>
         */
        public function all(): array;
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
