<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreightHintService;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingEstimateService;
use Ruhrcoder\RcCheckoutEnhancer\Struct\FreightHint;
use Ruhrcoder\RcCheckoutEnhancer\Struct\ShippingEstimate;
use Ruhrcoder\RcCheckoutEnhancer\Struct\ShippingEstimateResult;
use RuntimeException;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerGroup\CustomerGroupEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Der Speditionshinweis auf der Produktseite: eine Preisspanne über die eingestellten
 * Postleitzahl-Zonen, nur für Speditionsware, nur im Heimatland des Shops.
 */
final class FreightHintServiceTest extends TestCase
{
    private const PRODUCT = '0189b6b5e5c47dbfa5bb5d0a8a4e2f11';

    private const SPEDITION = 'sm-spedition';

    private const PAKET = 'sm-paket';

    private const DE = 'country-de';

    /**
     * Was: Zwei Zonen, je die Spedition als günstigste Lieferung.
     * Warum: Der Hauptfall; die Spanne reicht vom niedrigsten bis zum höchsten Zonenpreis.
     */
    public function testItShowsTheRangeOverTheZones(): void
    {
        $hint = $this->service()->forProduct(self::PRODUCT, $this->context());

        self::assertInstanceOf(FreightHint::class, $hint);
        self::assertSame([89.0, 149.0, 'EUR'], [$hint->minPrice, $hint->maxPrice, $hint->currencyIsoCode]);
        self::assertTrue($hint->isRange());
    }

    /**
     * Was: Die günstigste Lieferung ist ein Paketdienst.
     * Warum: Dann ist der Artikel keine Speditionsware, und ein Hinweis darauf wäre falsch.
     */
    public function testParcelGoodsGetNoHint(): void
    {
        self::assertNull($this->service(cheapest: self::PAKET)->forProduct(self::PRODUCT, $this->context()));
    }

    public function testWhenSwitchedOffThereIsNoHint(): void
    {
        self::assertNull($this->service(enabled: false)->forProduct(self::PRODUCT, $this->context()));
    }

    /**
     * Was: Besucher mit Lieferland Österreich.
     * Warum: Die Zonen sind deutsche Postleitzahlen; für ein anderes Land stimmte die Spanne nicht.
     */
    public function testOutsideTheHomeCountryThereIsNoHint(): void
    {
        self::assertNull($this->service()->forProduct(self::PRODUCT, $this->context(countryId: 'country-at')));
    }

    public function testAnInvalidProductIdGivesNoHint(): void
    {
        self::assertNull($this->service()->forProduct('kein-produkt', $this->context()));
    }

    /**
     * Was: Ein Artikel, der sich nicht in einen Warenkorb legen lässt, etwa ein Elternartikel.
     * Warum: Ohne Warenkorb keine Berechnung; der Hinweis entfällt still, die Seite bleibt heil.
     */
    public function testAProductThatCannotBeAddedGivesNoHint(): void
    {
        self::assertNull($this->service(lineItemFails: true)->forProduct(self::PRODUCT, $this->context()));
    }

    /**
     * Was: Ein 6 m langer Handlauf, Grenze für die Länge 2000 mm.
     * Warum: Der Grund steht im Hinweis, damit der Kunde versteht, warum keine Paketzustellung geht.
     */
    public function testTheReasonNamesTheLength(): void
    {
        $hint = $this->service(length: 6000.0)->forProduct(self::PRODUCT, $this->context());

        self::assertInstanceOf(FreightHint::class, $hint);
        self::assertSame([FreightHint::REASON_LENGTH, '6,00 m'], [$hint->reason, $hint->measure]);
    }

    /**
     * Was: Zweimal derselbe Artikel.
     * Warum: Die Spanne kostet je Zone eine Warenkorb-Berechnung; der zweite Aufruf liest den Speicher.
     */
    public function testTheRangeIsComputedOnlyOnce(): void
    {
        $estimates = $this->createMock(ShippingEstimateService::class);
        $estimates->expects(self::exactly(2))->method('estimate')->willReturnCallback($this->estimate(self::SPEDITION));

        $service = $this->service(estimates: $estimates);
        $service->forProduct(self::PRODUCT, $this->context());
        $service->forProduct(self::PRODUCT, $this->context());
    }

    public function testAnEstimateForAZipWithoutProductReportsNoShipping(): void
    {
        $result = $this->service()->estimateForZip('kein-produkt', $this->context(), '44787');

        self::assertFalse($result->isSuccessful());
    }

    private function service(
        bool $enabled = true,
        string $cheapest = self::SPEDITION,
        bool $lineItemFails = false,
        float $length = 0.0,
        ?ShippingEstimateService $estimates = null,
    ): FreightHintService {
        $configService = $this->createMock(ConfigService::class);
        $configService->method('isFreightHintEnabled')->willReturn($enabled);
        $configService->method('getFreightShippingMethodIds')->willReturn([self::SPEDITION]);
        $configService->method('getFreightHintZipCodes')->willReturn(['44787', '80331']);
        $configService->method('getNonDeliveryMethodIds')->willReturn(['sm-abholung']);
        $configService->method('getPickupHintLengthThreshold')->willReturn(2000.0);
        $configService->method('getPickupHintWeightThreshold')->willReturn(50.0);

        if ($estimates === null) {
            $estimates = $this->createMock(ShippingEstimateService::class);
            $estimates->method('estimate')->willReturnCallback($this->estimate($cheapest));
        }

        $product = new SalesChannelProductEntity();
        $product->setId(self::PRODUCT);
        $product->setUniqueIdentifier(self::PRODUCT);
        $product->setLength($length);
        $product->setWeight(10.0);

        $products = $this->createMock(SalesChannelRepository::class);
        $products->method('search')->willReturn(new EntitySearchResult(
            'product',
            1,
            new ProductCollection([$product]),
            null,
            new Criteria(),
            Context::createDefaultContext(),
        ));

        $lineItems = $this->createMock(LineItemFactoryRegistry::class);
        if ($lineItemFails) {
            $lineItems->method('create')->willThrowException(new RuntimeException('Elternartikel'));
        } else {
            $lineItems->method('create')->willReturn(new LineItem('li', LineItem::PRODUCT_LINE_ITEM_TYPE, self::PRODUCT));
        }

        return new FreightHintService(
            $configService,
            $estimates,
            $products,
            $lineItems,
            new ArrayAdapter(),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * Zone 44787 kostet 89, Zone 80331 kostet 149; die genannte Versandart ist jeweils die günstigste.
     *
     * @return callable(Cart, SalesChannelContext, string, string): ShippingEstimateResult
     */
    private function estimate(string $cheapest): callable
    {
        return static fn (Cart $cart, SalesChannelContext $context, string $country, string $zip): ShippingEstimateResult => ShippingEstimateResult::withShippingMethods([
            new ShippingEstimate($cheapest, $cheapest, $zip === '44787' ? 89.0 : 149.0, 'EUR'),
        ], $country, $zip);
    }

    private function context(string $countryId = self::DE): SalesChannelContext
    {
        $country = new CountryEntity();
        $country->setId($countryId);
        $country->setIso($countryId === self::DE ? 'DE' : 'AT');
        $country->setTranslated(['name' => 'Deutschland']);

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sc-id');
        $salesChannel->setCountryId(self::DE);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-id');
        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getShippingLocation')->willReturn(ShippingLocation::createFromCountry($country));
        $context->method('getCurrencyId')->willReturn('eur');
        $context->method('getTaxState')->willReturn(CartPrice::TAX_STATE_GROSS);
        $context->method('getCurrentCustomerGroup')->willReturn($this->customerGroup());

        return $context;
    }

    private function customerGroup(): CustomerGroupEntity
    {
        $group = new CustomerGroupEntity();
        $group->setId('kundengruppe');

        return $group;
    }
}
