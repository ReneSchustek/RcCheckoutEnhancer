<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Checkout\AccessoryChoice;
use Ruhrcoder\RcCheckoutEnhancer\Service\CartAccessoryService;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\SalesChannel\CrossSelling\AbstractProductCrossSellingRoute;
use Shopware\Core\Content\Product\SalesChannel\CrossSelling\CrossSellingElement;
use Shopware\Core\Content\Product\SalesChannel\CrossSelling\CrossSellingElementCollection;
use Shopware\Core\Content\Product\SalesChannel\CrossSelling\ProductCrossSellingRouteResponse;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

/**
 * Prüft die Mischung von „Dazu passt" und die Erkennung von Bausätzen für „Haben Sie an alles gedacht?".
 */
#[CoversClass(CartAccessoryService::class)]
#[CoversClass(AccessoryChoice::class)]
final class CartAccessoryServiceTest extends TestCase
{
    /**
     * Was: Zwei Listen „Oft zusammen gekauft", ein Produkt liegt schon im Warenkorb (als Variante), eines steht in
     * beiden.
     * Erwartet: abwechselnd gemischt, ohne Warenkorb und ohne Doppelte, höchstens so viele wie verlangt.
     */
    public function testMixAlternatesAndSkipsOwnedAndDuplicates(): void
    {
        $a = [['id' => 'a1', 'parentId' => null], ['id' => 'a2', 'parentId' => null], ['id' => 'a3', 'parentId' => null]];
        $b = [['id' => 'b1v', 'parentId' => 'b1'], ['id' => 'a2', 'parentId' => null], ['id' => 'b3', 'parentId' => null]];

        self::assertSame(['a1', 'a2', 'a3', 'b3'], AccessoryChoice::mix([$a, $b], ['b1' => true], 4));
        self::assertSame(['a1'], AccessoryChoice::mix([$a, $b], ['b1' => true], 1));
        self::assertSame([], AccessoryChoice::mix([], [], 4));
    }

    public function testIsKit(): void
    {
        self::assertTrue(AccessoryChoice::isKit(['ROOT', 'KITS', 'GLASS'], ['kits']));
        self::assertFalse(AccessoryChoice::isKit(['root', 'parts'], ['kits']));
        self::assertFalse(AccessoryChoice::isKit(['kits'], []));
    }

    /**
     * Was: Im Warenkorb eine Variante eines Bausatzes und ein Einzelteil. Der Bausatz hat „Oft zusammen gekauft" und
     *      zwei weitere Gruppen, aus einer davon liegt schon etwas im Warenkorb.
     * Warum: Das ist die ganze Auswahl über `load()`: Hauptartikel statt Variante, „Dazu passt" ohne Vorhandenes,
     *        Erinnerung nur an Gruppen, aus denen nichts im Warenkorb liegt.
     * Erwartet: Vorschlag „s1" (nicht das vorhandene Einzelteil), eine Erinnerung an „Endkappen".
     */
    public function testLoadSuggestsAndRemindsForAKit(): void
    {
        $route = $this->route([
            'kit' => [
                'Oft zusammen gekauft' => [['id' => 'part', 'parentId' => null], ['id' => 's1', 'parentId' => null]],
                'Befestigung' => [['id' => 'part', 'parentId' => null]],
                'Endkappen' => [['id' => 'cap', 'parentId' => null]],
            ],
        ]);

        $result = $this->service($route)->load($this->cart(['kitv', 'part']), $this->context());

        self::assertNotNull($result);
        self::assertSame(['s1'], array_map(static fn (SalesChannelProductEntity $p): string => $p->getId(), $result['suggestions']));
        self::assertSame([['name' => 'Endkappen', 'productId' => 'kit', 'productName' => 'Bausatz']], $result['checklist']);
    }

    /**
     * Was: Derselbe Warenkorb zweimal.
     * Warum: Die Leiste geht nach jedem Hinzufügen auf; ohne Zwischenspeicher liefe je Hauptartikel jedes Mal die
     *        Querverkaufs-Route mit mehreren Abfragen.
     * Erwartet: Die Route läuft je Hauptartikel einmal, das Ergebnis ist gleich.
     */
    public function testTheCrossSellingIsCachedPerMainProduct(): void
    {
        $route = $this->route(['kit' => ['Oft zusammen gekauft' => [['id' => 's1', 'parentId' => null]]], 'part' => []], expectedCalls: 2);
        $service = $this->service($route);

        $first = $service->load($this->cart(['kitv', 'part']), $this->context());
        $second = $service->load($this->cart(['kitv', 'part']), $this->context());

        self::assertEquals($first, $second);
    }

    /**
     * Was: Ein Warenkorb ohne Produkte (nur ein Gutschein).
     * Erwartet: nichts, ohne eine Abfrage.
     */
    public function testWithoutProductsThereIsNothing(): void
    {
        $cart = new Cart('token');
        $cart->add(new LineItem('promo', LineItem::PROMOTION_LINE_ITEM_TYPE, 'promo'));

        self::assertNull($this->service($this->route([], expectedCalls: 0))->load($cart, $this->context()));
    }

    /**
     * @param array<string, array<string, list<array{id: string, parentId: ?string}>>> $groups je Hauptartikel
     */
    private function route(array $groups, ?int $expectedCalls = null): AbstractProductCrossSellingRoute
    {
        $route = $this->createMock(AbstractProductCrossSellingRoute::class);
        $route->expects($expectedCalls === null ? self::any() : self::exactly($expectedCalls))->method('load')->willReturnCallback(
            static function (string $productId) use ($groups): ProductCrossSellingRouteResponse {
                $elements = new CrossSellingElementCollection();
                foreach ($groups[$productId] ?? [] as $name => $pairs) {
                    $crossSelling = new ProductCrossSellingEntity();
                    $crossSelling->setId(md5($name));
                    $crossSelling->setName($name);
                    $products = new ProductCollection();
                    foreach ($pairs as $pair) {
                        $product = new ProductEntity();
                        $product->setId($pair['id']);
                        $product->setParentId($pair['parentId']);
                        $products->add($product);
                    }
                    $element = new CrossSellingElement();
                    $element->setCrossSelling($crossSelling);
                    $element->setProducts($products);
                    $element->setTotal($products->count());
                    $elements->add($element);
                }

                return new ProductCrossSellingRouteResponse($elements);
            },
        );

        return $route;
    }

    private function service(AbstractProductCrossSellingRoute $route): CartAccessoryService
    {
        $catalog = [
            'kitv' => ['parent' => 'kit', 'name' => 'Bausatz', 'tree' => ['root', 'kits']],
            'part' => ['parent' => null, 'name' => 'Einzelteil', 'tree' => ['root', 'parts']],
            's1' => ['parent' => null, 'name' => 'Vorschlag', 'tree' => []],
        ];

        $repository = $this->createStub(SalesChannelRepository::class);
        $repository->method('search')->willReturnCallback(static function (Criteria $criteria, SalesChannelContext $context) use ($catalog): EntitySearchResult {
            $products = new SalesChannelProductCollection();
            foreach ($criteria->getIds() as $id) {
                if (!isset($catalog[$id])) {
                    continue;
                }
                $product = new SalesChannelProductEntity();
                $product->setId($id);
                $product->setParentId($catalog[$id]['parent']);
                $product->setName($catalog[$id]['name']);
                $product->setCategoryTree($catalog[$id]['tree']);
                $products->add($product);
            }

            return new EntitySearchResult('product', $products->count(), $products, null, $criteria, new Context(new SystemSource()));
        });

        $config = $this->createStub(ConfigService::class);
        $config->method('getCartAccessoryKitCategoryIds')->willReturn(['kits']);
        $config->method('getCartAccessoryGroupNames')->willReturn(['oft zusammen gekauft']);

        return new CartAccessoryService($route, $repository, $config, new TagAwareAdapter(new ArrayAdapter()));
    }

    /**
     * @param list<string> $productIds
     */
    private function cart(array $productIds): Cart
    {
        $cart = new Cart('token');
        foreach ($productIds as $id) {
            $cart->add(new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, $id));
        }

        return $cart;
    }

    private function context(): SalesChannelContext
    {
        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('kanal');
        $context->method('getLanguageId')->willReturn('sprache');
        $context->method('getCurrencyId')->willReturn('eur');
        $context->method('getRuleIds')->willReturn(['r2', 'r1']);

        return $context;
    }
}
