<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

/**
 * Prüft Land und Postleitzahl, bevor sie in die Regelauswertung gehen.
 *
 * Eigene Klasse statt einer privaten Methode im Controller, weil das hier
 * Eingabeprüfung an einem Endpunkt ohne Anmeldung ist. Solche Logik gehört
 * lückenlos getestet, und ein `StorefrontController` lässt sich dafür nur mit
 * halbem Framework hochziehen.
 */
class EstimateInputValidator
{
    /**
     * Die längsten Postleitzahlen kommen mit Trennzeichen auf elf Zeichen (Iran, ‚12345-67890');
     * 12 schneidet keine echte ab. Ohne Grenze landete eine beliebig lange Zeichenkette in der
     * Regelauswertung und im Log.
     */
    private const ZIP_MAX_LENGTH = 12;

    private const ERROR_COUNTRY = 'rc-checkout.shippingEstimate.errorCountry';
    private const ERROR_ZIP_REQUIRED = 'rc-checkout.shippingEstimate.errorZipRequired';
    private const ERROR_ZIP_INVALID = 'rc-checkout.shippingEstimate.errorZipInvalid';

    /**
     * Der Snippet-Schlüssel der Fehlermeldung, oder `null`, wenn die Eingabe brauchbar ist.
     */
    public function validate(string $countryIso, string $zipCode): ?string
    {
        if (preg_match('/^[A-Z]{2,3}$/', $countryIso) !== 1) {
            return self::ERROR_COUNTRY;
        }

        if ($zipCode === '') {
            return self::ERROR_ZIP_REQUIRED;
        }

        if (mb_strlen($zipCode) > self::ZIP_MAX_LENGTH) {
            return self::ERROR_ZIP_INVALID;
        }

        // Weltweit gibt es Postleitzahlen mit Ziffern, Buchstaben, Leerzeichen und
        // Bindestrich (NL „1234 AB", UK „SW1A 1AA", CA „K1A 0B1"). Mehr als diese
        // vier Zeichenklassen braucht keine.
        if (preg_match('/^[A-Za-z0-9 \-]+$/', $zipCode) !== 1) {
            return self::ERROR_ZIP_INVALID;
        }

        return null;
    }
}
