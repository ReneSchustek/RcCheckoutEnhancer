<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressDefinition;

/**
 * Prüft und säubert die eingegebene Telefonnummer.
 *
 * Nicht streng nach E.164, weil hier ein Mensch in ein Feld schreibt, mit Vorwahl in Klammern,
 * mit Schrägstrich, mit Durchwahl. Wer das ablehnt, verliert die Bestellung und bekommt trotzdem
 * keine bessere Nummer. Gebraucht wird eine Nummer, unter der jemand zurückrufen kann.
 *
 * Geprüft wird dagegen, dass etwas anderes als eine Rufnummer im Feld landet: ein Satz, eine
 * Adresse, eine Bestellnummer, also alles, was den Sachbearbeiter später glauben ließe, er hätte
 * eine Nummer. Daher die zwei Bedingungen: genug Ziffern und keine Zeichen, die in einer
 * Rufnummer nichts zu suchen haben.
 */
final class PhoneNumberInput
{
    /**
     * Weniger Ziffern trägt keine Rufnummer, mit der sich jemand erreichen ließe. Vier wären
     * eine Hausnummer, drei ein Tippfehler.
     */
    private const MIN_DIGITS = 5;

    /**
     * So viel fasst die Spalte des Kerns (`CustomerAddressDefinition::MAX_LENGTH_PHONE_NUMBER`).
     * Eine längere Eingabe bestünde hier und scheiterte erst beim Schreiben der Adresse. Eine
     * Rufnummer mit Landesvorwahl, Trennern und Durchwahl passt hinein.
     */
    private const MAX_LENGTH = CustomerAddressDefinition::MAX_LENGTH_PHONE_NUMBER;

    /**
     * Ziffern und das, was in geschriebenen Rufnummern üblich ist: Landesvorwahl, Trenner,
     * Klammern um die Ortsvorwahl, Punkt als Trenner in der Schweiz.
     */
    private const ALLOWED = '/^[0-9+\-\/() .]+$/';

    /**
     * Die gesäuberte Nummer, oder `null`, wenn das keine ist.
     */
    public function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        // Mehrfache Leerzeichen entstehen beim Einfügen aus einer Mail oder einem Dokument.
        $value = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');

        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        if (preg_match(self::ALLOWED, $value) !== 1) {
            return null;
        }

        if (preg_match_all('/[0-9]/', $value) < self::MIN_DIGITS) {
            return null;
        }

        return $value;
    }
}
