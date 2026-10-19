<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Zahlen in der Schreibweise der Oberfläche, für Gewicht und Länge in Hinweistexten.
 *
 * Ohne `intl`, weil es nur um Komma oder Punkt geht. Eine Erweiterung vorauszusetzen, die auf manchen Servern fehlt,
 * riskierte einen Ausfall für einen Satz Text. Eine englische Oberfläche bekommt die getauschten Trennzeichen, jede
 * andere die deutschen.
 */
final class LocalNumber
{
    public static function format(float $value, int $decimals, TranslatorInterface $translator): string
    {
        $german = !($translator instanceof LocaleAwareInterface) || !str_starts_with($translator->getLocale(), 'en');

        return $german
            ? number_format($value, $decimals, ',', '.')
            : number_format($value, $decimals, '.', ',');
    }
}
