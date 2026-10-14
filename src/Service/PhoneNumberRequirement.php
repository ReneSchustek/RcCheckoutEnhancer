<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Beantwortet die eine Frage: Fehlt an dieser Bestellung die Telefonnummer?
 *
 * Die Antwort wird an drei Stellen gebraucht: von der Sperre im Warenkorb, dem Feld auf der
 * Bestätigungsseite und dem Endpunkt, der die Nummer entgegennimmt. Mit drei Kopien griffe
 * irgendwann die Sperre, ohne dass ein Feld erscheint, und der Kunde stünde vor einer Bestellung,
 * die sich nicht abschließen lässt, ohne zu erfahren warum. Dasselbe Muster hält
 * {@see PickupHintDecider} für den Abhol-Hinweis.
 *
 * Maßgeblich ist die Rechnungsadresse, weil die Nummer für Rückfragen zur Bestellung gebraucht
 * wird und der Rechnungsempfänger der Besteller ist. Beim Weg über den PayPal-Express-Knopf sind
 * ohnehin beide dieselbe Adresse, PayPal liefert genau eine.
 *
 * Nicht `final`, weil die Tests von Sperre, Feld und Endpunkt ihn als Test-Double ersetzen.
 */
class PhoneNumberRequirement
{
    public function __construct(private readonly ConfigService $configService)
    {
    }

    /**
     * Fehlt die Nummer, und soll sie nachgefordert werden?
     */
    public function isMissing(SalesChannelContext $context): bool
    {
        if (!$this->configService->isPhoneNumberRequired($context->getSalesChannelId())) {
            return false;
        }

        return $this->addressWithoutNumber($context) !== null;
    }

    /**
     * Die Adresse, an der die Nummer fehlt, oder `null`, wenn nichts fehlt.
     *
     * Der Endpunkt schreibt genau in diese Adresse zurück. Die Kennung kommt damit vom Server
     * und nie aus der Anfrage; sonst ließe sich über den Endpunkt eine fremde Adresse
     * beschreiben, indem jemand eine andere Kennung mitschickt.
     */
    public function addressWithoutNumber(SalesChannelContext $context): ?CustomerAddressEntity
    {
        $customer = $context->getCustomer();
        if ($customer === null) {
            return null;
        }

        $address = $customer->getActiveBillingAddress();
        if (!$address instanceof CustomerAddressEntity) {
            return null;
        }

        return self::isBlank($address->getPhoneNumber()) ? $address : null;
    }

    /**
     * Zählt eine Nummer aus Leerzeichen als vorhanden?
     *
     * Nein. Shopware speichert ein leergelassenes Pflichtfeld als leere Zeichenkette, und aus
     * einer Übernahme kann eine Zeile mit einem einzelnen Leerzeichen kommen. Beides ist keine
     * Rufnummer, sähe in der Datenbank aber aus wie eine.
     */
    private static function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
