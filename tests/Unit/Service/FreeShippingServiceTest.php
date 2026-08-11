<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreeShippingService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class FreeShippingServiceTest extends TestCase
{
    public function testCalculateBelowThresholdReportsRemaining(): void
    {
        $cart = $this->createCart(30.0);
        $context = $this->createContext('EUR');

        $service = new FreeShippingService();
        $status = $service->calculate($cart, $context, 50.0);

        self::assertSame(50.0, $status->threshold);
        self::assertSame(20.0, $status->remaining);
        self::assertFalse($status->achieved);
        self::assertSame('EUR', $status->currencyIsoCode);
    }

    public function testCalculateAboveThresholdReportsAchieved(): void
    {
        $cart = $this->createCart(60.0);
        $context = $this->createContext('EUR');

        $service = new FreeShippingService();
        $status = $service->calculate($cart, $context, 50.0);

        self::assertSame(0.0, $status->remaining);
        self::assertTrue($status->achieved);
    }

    public function testCalculateExactlyAtThresholdReportsAchieved(): void
    {
        $cart = $this->createCart(50.0);
        $context = $this->createContext('EUR');

        $service = new FreeShippingService();
        $status = $service->calculate($cart, $context, 50.0);

        self::assertSame(0.0, $status->remaining);
        self::assertTrue($status->achieved);
    }

    public function testCalculateWithEmptyCartReportsFullThresholdRemaining(): void
    {
        $cart = $this->createCart(0.0);
        $context = $this->createContext('EUR');

        $service = new FreeShippingService();
        $status = $service->calculate($cart, $context, 50.0);

        self::assertSame(50.0, $status->remaining);
        self::assertFalse($status->achieved);
    }

    public function testCalculateUsesCurrencyFromContext(): void
    {
        $cart = $this->createCart(10.0);
        $context = $this->createContext('USD');

        $service = new FreeShippingService();
        $status = $service->calculate($cart, $context, 50.0);

        self::assertSame('USD', $status->currencyIsoCode);
    }

    public function testTheCurrencyFactorIsAppliedToTheThreshold(): void
    {
        // Schwelle 50 (Standardwährung), Kontext-Währung mit Faktor 1,1 -> effektive Schwelle 55.
        // Warenkorb 52,80 (Kontext-Währung) liegt darunter: noch 2,20 offen, nicht erreicht.
        $service = new FreeShippingService();
        $cart = $this->createCart(52.80);
        $context = $this->createContext('USD', 1.1);

        $status = $service->calculate($cart, $context, 50.0);

        self::assertSame(55.0, $status->threshold);
        self::assertSame(2.2, $status->remaining);
        self::assertFalse($status->achieved);
    }

    private function createCart(float $positionPrice): Cart
    {
        $cart = new Cart('test-token');
        $cart->setPrice(new CartPrice(
            $positionPrice,
            $positionPrice,
            $positionPrice,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
            CartPrice::TAX_STATE_GROSS,
        ));

        return $cart;
    }

    private function createContext(string $currencyIso, float $factor = 1.0): SalesChannelContext
    {
        $currency = new CurrencyEntity();
        $currency->setIsoCode($currencyIso);
        $currency->setFactor($factor);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getCurrency')->willReturn($currency);

        return $context;
    }
}
