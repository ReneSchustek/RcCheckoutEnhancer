<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Baut aus dem Kontext des Besuchers einen Ableger, der auf eine andere Adresse
 * und eine bestimmte Versandart zeigt.
 *
 * Der Ableger bekommt ein eigenes Token. Was auf dem Weg der Berechnung gespeichert wird,
 * landet damit unter einer Wegwerf-Kennung und nie auf dem Warenkorb des Besuchers; sonst
 * könnte eine Auskunft den echten Warenkorb überschreiben, und der Kunde stünde nach einer
 * Preisabfrage im falschen Land.
 *
 * Den Steuer-Zustand erbt der Ableger unverändert. Ein Netto-Kanal liefert damit
 * Netto-Preise, ein Brutto-Kanal Brutto-Preise; auf Brutto gezwungen, sähe der B2B-Kunde
 * eine Zahl, die im Warenkorb daneben anders steht.
 *
 * Nicht `final`, weil die Tests des Rechners ihn als Test-Double ersetzen.
 */
class EstimateContextFactory
{
    /**
     * Klont den Kontext auf ein Wegwerf-Token, eine Pseudo-Adresse im Zielland und
     * die angefragte Versandart.
     *
     * `null`, wenn die Zuweisung nicht greift. `Struct::assign()` verschluckt Zuweisungsfehler
     * wortlos (`catch (\Error)`); ein Tippfehler im Property-Namen bliebe unbemerkt, und die
     * Berechnung liefe gegen das Standardland des Kanals, mit plausiblen, falschen Preisen.
     */
    public function create(
        SalesChannelContext $context,
        CountryEntity $country,
        string $zipCode,
        ShippingMethodEntity $shippingMethod,
    ): ?SalesChannelContext {
        $address = $this->pseudoAddress($country, $zipCode);

        $derived = clone $context;
        $derived->assign([
            'token' => Uuid::randomHex(),
            'shippingLocation' => ShippingLocation::createFromAddress($address),
            'customer' => $this->pseudoCustomer($address),
            'shippingMethod' => $shippingMethod,
        ]);

        return $this->assignmentTookEffect($derived, $country, $zipCode, $shippingMethod)
            ? $derived
            : null;
    }

    /**
     * Die Adresse trägt nur, was für die Regelauswertung zählt: Land und Postleitzahl.
     * Name und Straße sind Pflichtfelder der Entität, für die Berechnung aber ohne
     * Bedeutung.
     */
    private function pseudoAddress(CountryEntity $country, string $zipCode): CustomerAddressEntity
    {
        $address = new CustomerAddressEntity();
        $address->setId(Uuid::randomHex());
        $address->setFirstName('-');
        $address->setLastName('-');
        $address->setStreet('-');
        $address->setCity('-');
        $address->setZipcode($zipCode);
        $address->setCountryId($country->getId());
        $address->setCountry($country);

        return $address;
    }

    /**
     * Regeln wie `customerShippingZipCode` fragen die Postleitzahl über
     * `$context->getCustomer()->getActiveShippingAddress()` ab. Ohne diesen
     * Pseudo-Kunden greift eine PLZ-Regel nie, und der Preis wäre still zu niedrig.
     */
    private function pseudoCustomer(CustomerAddressEntity $address): CustomerEntity
    {
        $customer = new CustomerEntity();
        $customer->setId(Uuid::randomHex());
        $customer->setAccountType(CustomerEntity::ACCOUNT_TYPE_PRIVATE);
        $customer->setActiveShippingAddress($address);
        $customer->setActiveBillingAddress($address);

        return $customer;
    }

    private function assignmentTookEffect(
        SalesChannelContext $derived,
        CountryEntity $country,
        string $zipCode,
        ShippingMethodEntity $shippingMethod,
    ): bool {
        $address = $derived->getShippingLocation()->getAddress();

        return $address !== null
            && $address->getZipcode() === $zipCode
            && $derived->getShippingLocation()->getCountry()->getId() === $country->getId()
            && $derived->getShippingMethod()->getId() === $shippingMethod->getId();
    }
}
