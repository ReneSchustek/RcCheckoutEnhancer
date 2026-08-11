<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\EstimateContextFactory;
use Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\SalesChannelContextBuilder;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryEntity;

final class EstimateContextFactoryTest extends TestCase
{
    public function testDerivedContextPointsAtTargetCountryAndZip(): void
    {
        $context = SalesChannelContextBuilder::build();
        $country = $this->findCountry('AT');

        $derived = (new EstimateContextFactory())->create($context, $country, '1010', $this->shippingMethod());

        self::assertNotNull($derived);
        self::assertSame($country->getId(), $derived->getShippingLocation()->getCountry()->getId());
        self::assertSame('1010', $derived->getShippingLocation()->getAddress()?->getZipcode());
    }

    public function testDerivedContextGetsItsOwnToken(): void
    {
        $context = SalesChannelContextBuilder::build();

        $derived = (new EstimateContextFactory())->create($context, $this->findCountry('AT'), '1010', $this->shippingMethod());

        self::assertNotNull($derived);
        self::assertNotSame($context->getToken(), $derived->getToken());
    }

    public function testVisitorContextStaysUntouched(): void
    {
        $context = SalesChannelContextBuilder::build();
        $tokenBefore = $context->getToken();
        $countryBefore = $context->getShippingLocation()->getCountry()->getId();
        $shippingMethodBefore = $context->getShippingMethod()->getId();

        (new EstimateContextFactory())->create($context, $this->findCountry('AT'), '1010', $this->shippingMethod());

        self::assertSame($tokenBefore, $context->getToken());
        self::assertSame($countryBefore, $context->getShippingLocation()->getCountry()->getId());
        self::assertSame($shippingMethodBefore, $context->getShippingMethod()->getId());
        self::assertNull($context->getCustomer());
    }

    public function testPseudoCustomerCarriesTargetAddressForZipRules(): void
    {
        $derived = (new EstimateContextFactory())
            ->create(SalesChannelContextBuilder::build(), $this->findCountry('AT'), '1010', $this->shippingMethod());

        self::assertNotNull($derived);
        // Regeln wie `customerShippingZipCode` lesen die Postleitzahl über diesen Weg.
        self::assertSame('1010', $derived->getCustomer()?->getActiveShippingAddress()?->getZipcode());
    }

    public function testTaxStateIsInheritedFromVisitorContext(): void
    {
        $netContext = SalesChannelContextBuilder::build(taxState: CartPrice::TAX_STATE_NET);

        $derived = (new EstimateContextFactory())->create($netContext, $this->findCountry('AT'), '1010', $this->shippingMethod());

        self::assertNotNull($derived);
        self::assertSame(CartPrice::TAX_STATE_NET, $derived->getTaxState());
    }

    public function testRequestedShippingMethodIsSet(): void
    {
        $shippingMethod = $this->shippingMethod();

        $derived = (new EstimateContextFactory())
            ->create(SalesChannelContextBuilder::build(), $this->findCountry('AT'), '1010', $shippingMethod);

        self::assertNotNull($derived);
        self::assertSame($shippingMethod->getId(), $derived->getShippingMethod()->getId());
    }

    private function findCountry(string $iso): CountryEntity
    {
        $country = new CountryEntity();
        $country->setId(Uuid::randomHex());
        $country->setIso($iso);

        return $country;
    }

    private function shippingMethod(): ShippingMethodEntity
    {
        $shippingMethod = new ShippingMethodEntity();
        $shippingMethod->setId(Uuid::randomHex());
        $shippingMethod->setName('Express');

        return $shippingMethod;
    }
}
