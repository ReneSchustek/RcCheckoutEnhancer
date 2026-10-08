<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Benchmarks;

use PhpBench\Attributes as Bench;
use Psr\Log\NullLogger;
use Ruhrcoder\RcCheckoutEnhancer\Benchmarks\Support\ShopwareFixture;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreightHintService;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingEstimateService;
use RuntimeException;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Der Speditionshinweis der Produktseite, einmal voll gerechnet und einmal aus dem
 * Zwischenspeicher.
 *
 * Die volle Rechnung fällt auf Live beim ersten Aufruf je Artikel, Land und Menge an, danach
 * für die Lebensdauer des Speichers nicht mehr. Unter `APP_ENV=dev` überlebt der Speicher keinen
 * Prozess; deshalb wird er hier ausdrücklich gesetzt, statt den der Instanz zu nehmen.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Revs(5)]
#[Bench\Iterations(5)]
#[Bench\Warmup(1)]
final class FreightHintBench
{
    private FreightHintService $uncached;

    private FreightHintService $cached;

    private SalesChannelContext $context;

    public function setUp(): void
    {
        $this->context = ShopwareFixture::guestContext();
        $this->uncached = $this->service(new NullAdapter());
        $this->cached = $this->service(new ArrayAdapter());

        if ($this->cached->forProduct(ShopwareFixture::PRODUCT_ID, $this->context) === null) {
            throw new RuntimeException('Für den Messartikel entsteht kein Speditionshinweis; Hinweis eingeschaltet, Speditionsarten gesetzt?');
        }
    }

    public function benchUncached(): void
    {
        $this->uncached->forProduct(ShopwareFixture::PRODUCT_ID, $this->context);
    }

    public function benchCached(): void
    {
        $this->cached->forProduct(ShopwareFixture::PRODUCT_ID, $this->context);
    }

    private function service(CacheInterface $cache): FreightHintService
    {
        return new FreightHintService(
            ShopwareFixture::service(ConfigService::class),
            ShopwareFixture::service(ShippingEstimateService::class),
            ShopwareFixture::service(SalesChannelRepository::class, 'sales_channel.product.repository'),
            ShopwareFixture::service(LineItemFactoryRegistry::class),
            $cache,
            ShopwareFixture::service(TranslatorInterface::class, 'translator'),
            new NullLogger(),
        );
    }
}
