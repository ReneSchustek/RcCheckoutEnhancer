<?php

declare(strict_types=1);

require_once \dirname(__DIR__) . '/vendor/autoload.php';

// Die Stubs der optionalen RcAbTesting-Integration. Sie liegen ohnehin für die statische
// Analyse bereit; ohne sie ließe sich der Ausfallschutz des A/B-Schalters nicht prüfen —
// und ein ungeprüfter Ausfallschutz ist eine Behauptung, keine Zusicherung.
$stubs = \dirname(__DIR__) . '/phpstan-stubs/RcAbTesting.php';
if (file_exists($stubs)) {
    require_once $stubs;
}
