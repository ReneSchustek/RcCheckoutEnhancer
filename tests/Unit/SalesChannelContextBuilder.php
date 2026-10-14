<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit;

use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerGroup\CustomerGroupEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Context\LanguageInfo;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\Tax\TaxCollection;

/**
 * Baut einen echten SalesChannelContext statt eines Doubles.
 *
 * Ein Mock wäre hier wertlos: Die Fabrik arbeitet über `Struct::assign()`, und
 * genau dessen Verhalten — inklusive des stillen Verschluckens von Fehlern — ist
 * das, was die Tests nachweisen sollen.
 */
final class SalesChannelContextBuilder
{
    public static function build(string $taxState = CartPrice::TAX_STATE_GROSS, string $countryIso = 'DE'): SalesChannelContext
    {
        $country = new CountryEntity();
        $country->setId(Uuid::randomHex());
        $country->setIso($countryIso);

        $currency = new CurrencyEntity();
        $currency->setId(Defaults::CURRENCY);
        $currency->setIsoCode('EUR');

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId(Uuid::randomHex());

        $customerGroup = new CustomerGroupEntity();
        $customerGroup->setId(Uuid::randomHex());

        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId(Uuid::randomHex());

        $shippingMethod = new ShippingMethodEntity();
        $shippingMethod->setId(Uuid::randomHex());
        $shippingMethod->setName('Standard');

        $rounding = new CashRoundingConfig(2, 0.01, true);

        return new SalesChannelContext(
            new Context(
                new SalesChannelApiSource($salesChannel->getId()),
                [],
                Defaults::CURRENCY,
                [Defaults::LANGUAGE_SYSTEM],
                Defaults::LIVE_VERSION,
                1.0,
                true,
                $taxState,
                $rounding,
            ),
            Uuid::randomHex(),
            null,
            $salesChannel,
            $currency,
            $customerGroup,
            new TaxCollection(),
            $paymentMethod,
            $shippingMethod,
            ShippingLocation::createFromCountry($country),
            null,
            $rounding,
            $rounding,
            new LanguageInfo('Deutsch', 'de-DE'),
        );
    }
}
