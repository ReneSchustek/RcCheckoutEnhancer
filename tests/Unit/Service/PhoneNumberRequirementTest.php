<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\PhoneNumberRequirement;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Die eine Frage: Fehlt die Nummer?
 *
 * Der teuerste Fehler wäre hier ein Ja, das keines ist. Die Antwort sperrt eine Bestellung.
 * Sagt sie „fehlt", obwohl eine Nummer dasteht, hält der Shop einen zahlenden Kunden auf — und
 * zwar an der Stelle, an der ein Bestellvorgang am teuersten abbricht.
 */
final class PhoneNumberRequirementTest extends TestCase
{
    /**
     * Was: Der Schalter ist aus.
     * Warum: Vorgabe des Plugins. Wer den Schalter nicht kennt, darf von diesem Vorgang nichts
     *        merken — auch dann nicht, wenn im ganzen Shop keine Nummer gepflegt ist.
     */
    public function testItStaysQuietWhileTheSwitchIsOff(): void
    {
        $requirement = $this->requirement(enabled: false);

        self::assertFalse($requirement->isMissing($this->contextWith($this->addressWithout())));
    }

    /**
     * Was: Kein angemeldeter Kunde.
     * Warum: Ohne Kunden gibt es keine Adresse, in die sich etwas schreiben ließe. Eine Sperre
     *        wäre hier eine Sackgasse ohne Ausweg.
     */
    public function testItStaysQuietWithoutACustomer(): void
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-id');
        $context->method('getCustomer')->willReturn(null);

        self::assertFalse($this->requirement()->isMissing($context));
    }

    /**
     * Was: Die Rechnungsadresse trägt eine Nummer.
     * Warum: Der Normalfall jeder regulären Bestellung — dort darf sich nichts ändern.
     */
    public function testItStaysQuietWhenTheAddressCarriesANumber(): void
    {
        $address = $this->addressWithout();
        $address->setPhoneNumber('09568 8039770');

        self::assertFalse($this->requirement()->isMissing($this->contextWith($address)));
    }

    /**
     * Was: Die Rechnungsadresse ohne Nummer.
     * Warum: Der Fall, um den es geht — so kommt eine Adresse aus dem PayPal-Express-Weg heraus.
     */
    public function testItReportsAMissingNumber(): void
    {
        $address = $this->addressWithout();

        $requirement = $this->requirement();
        $context = $this->contextWith($address);

        self::assertTrue($requirement->isMissing($context));
        self::assertSame($address, $requirement->addressWithoutNumber($context));
    }

    /**
     * Was: Eine Nummer aus einem einzelnen Leerzeichen.
     * Warum: In der Datenbank sieht das aus wie eine gepflegte Nummer. Es ist keine — und wer
     *        sich darauf verlässt, ruft nirgendwo an.
     */
    public function testItTreatsWhitespaceAsMissing(): void
    {
        $address = $this->addressWithout();
        $address->setPhoneNumber('   ');

        self::assertTrue($this->requirement()->isMissing($this->contextWith($address)));
    }

    /**
     * Was: Die Adresse, in die geschrieben werden soll, wenn nichts fehlt.
     * Warum: Der Endpunkt nimmt die Kennung von hier. Gäbe es sie auch dann, wenn eine Nummer
     *        dasteht, ließe sich eine gepflegte Nummer von außen überschreiben.
     */
    public function testItHandsOutNoAddressWhenNothingIsMissing(): void
    {
        $address = $this->addressWithout();
        $address->setPhoneNumber('09568 8039770');

        self::assertNull($this->requirement()->addressWithoutNumber($this->contextWith($address)));
    }

    private function requirement(bool $enabled = true): PhoneNumberRequirement
    {
        $configService = $this->createMock(ConfigService::class);
        $configService->method('isPhoneNumberRequired')->willReturn($enabled);

        return new PhoneNumberRequirement($configService);
    }

    private function addressWithout(): CustomerAddressEntity
    {
        $address = new CustomerAddressEntity();
        $address->setId(Uuid::randomHex());

        return $address;
    }

    private function contextWith(CustomerAddressEntity $address): SalesChannelContext
    {
        $customer = new CustomerEntity();
        $customer->setId(Uuid::randomHex());
        $customer->setDefaultBillingAddress($address);
        $customer->setActiveBillingAddress($address);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-id');
        $context->method('getCustomer')->willReturn($customer);

        return $context;
    }
}
