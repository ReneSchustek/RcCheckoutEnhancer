<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\CrossSelling\AbstractProductCrossSellingRoute;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Zubehör im Warenkorb: „Dazu passt" und „Haben Sie an alles gedacht?".
 *
 * „Dazu passt" zeigt Produkte aus der Querverkaufsgruppe „Oft zusammen gekauft" der Artikel im Warenkorb (sie wird
 * aus echten Bestellungen errechnet). Die Artikel kommen abwechselnd zum Zug, damit nicht einer alle Plätze belegt;
 * was schon im Warenkorb liegt, auch als andere Variante, fällt weg.
 *
 * „Haben Sie an alles gedacht?" gibt es nur für Bausätze (Kategorien aus der Einstellung). Es nennt die übrigen,
 * gepflegten Querverkaufsgruppen des Bausatzes, aus denen noch kein Produkt im Warenkorb liegt, mit Verweis auf den
 * Zubehörblock der Produktseite.
 *
 * Gelesen wird über die Querverkaufs-Route des Kerns: dieselben Produkte, die die Produktseite zeigt, mit denselben
 * Regeln für Sichtbarkeit und Verfügbarkeit, gepflegte Listen wie dynamische Produktgruppen.
 */
class CartAccessoryService
{
    // Verglichen wird kleingeschrieben, damit „Oft zusammen gekauft" in jeder Schreibweise greift.
    public const ALSO_BOUGHT = 'oft zusammen gekauft';
    // Eine Kachelreihe auf der Warenkorbseite (vier Spalten), am Telefon zwei Zeilen zu zwei.
    private const SUGGESTION_LIMIT = 4;
    // Mehr als vier Erinnerungen liest unter den Positionen niemand mehr.
    private const CHECKLIST_LIMIT = 4;
    // Jeder Hauptartikel kostet eine Abfrage der Querverkaufs-Route; bei großen Warenkörben reichen die ersten zehn.
    private const PRODUCT_LIMIT = 10;

    /**
     * @param SalesChannelRepository<SalesChannelProductCollection> $productRepository
     */
    public function __construct(
        private readonly AbstractProductCrossSellingRoute $crossSellingRoute,
        private readonly SalesChannelRepository $productRepository,
        private readonly ConfigService $configService,
    ) {
    }

    /**
     * @return array{suggestions: list<SalesChannelProductEntity>, checklist: list<array{name: string, productId: string, productName: string}>}|null
     */
    public function load(Cart $cart, SalesChannelContext $context): ?array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (LineItem $item): ?string => $item->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE ? $item->getReferencedId() : null,
            $cart->getLineItems()->getFlat(),
        ))));
        if ($ids === []) {
            return null;
        }

        $inCart = $this->productRepository->search(new Criteria($ids), $context)->getEntities();
        // Eine Variante im Warenkorb gilt auch für ihren Hauptartikel als vorhanden. Querverkauf und Kategorien
        // hängen am Hauptartikel; die erste Variante je Hauptartikel steht für ihn.
        $owned = [];
        $mains = [];
        foreach ($inCart as $product) {
            $owned[$product->getId()] = true;
            $main = $product->getParentId() ?? $product->getId();
            $owned[$main] = true;
            $mains[$main] ??= $product;
        }

        $kitCategories = $this->configService->getCartAccessoryKitCategoryIds($context->getSalesChannelId());
        $bought = [];
        $checklist = [];
        foreach (\array_slice(array_keys($mains), 0, self::PRODUCT_LIMIT) as $mainId) {
            $isKit = self::isKit(array_values($mains[$mainId]->getCategoryTree() ?? []), $kitCategories);
            $elements = $this->crossSellingRoute->load($mainId, new Request(), $context, new Criteria())->getResult();

            foreach ($elements as $element) {
                $crossSelling = $element->getCrossSelling();
                $name = trim((string) ($crossSelling->getTranslation('name') ?? $crossSelling->getName()));
                $products = self::idPairs($element->getProducts());

                // „Oft zusammen gekauft" speist „Dazu passt"; jede andere Gruppe eines Bausatzes wird zur Erinnerung,
                // solange aus ihr nichts im Warenkorb liegt.
                if (mb_strtolower($name) === self::ALSO_BOUGHT) {
                    $bought[] = $products;
                } elseif ($isKit && $name !== '' && $products !== [] && !self::anyOwned($products, $owned)) {
                    $checklist[] = ['name' => $name, 'productId' => $mainId, 'productName' => (string) ($mains[$mainId]->getTranslation('name') ?? $mains[$mainId]->getName())];
                }
            }
        }

        $suggestionIds = self::mix($bought, $owned, self::SUGGESTION_LIMIT);
        $suggestions = [];
        if ($suggestionIds !== []) {
            $criteria = new Criteria($suggestionIds);
            $criteria->addAssociation('cover.media');
            $found = $this->productRepository->search($criteria, $context)->getEntities();
            // Die Suche liefert in eigener Reihenfolge; die abwechselnde Reihenfolge aus mix() bleibt maßgeblich.
            foreach ($suggestionIds as $id) {
                $product = $found->get($id);
                if ($product instanceof SalesChannelProductEntity) {
                    $suggestions[] = $product;
                }
            }
        }

        $checklist = \array_slice($checklist, 0, self::CHECKLIST_LIMIT);

        return $suggestions === [] && $checklist === [] ? null : ['suggestions' => $suggestions, 'checklist' => $checklist];
    }

    /**
     * Liegt der Artikel in einer der Bausatz-Kategorien oder darunter?
     *
     * @param list<string> $categoryTree
     * @param list<string> $kitCategories
     */
    public static function isKit(array $categoryTree, array $kitCategories): bool
    {
        return $kitCategories !== [] && array_intersect(array_map('strtolower', $categoryTree), array_map('strtolower', $kitCategories)) !== [];
    }

    /**
     * Abwechselnd aus jeder Liste der nächste, der weder im Warenkorb liegt noch schon gewählt ist. Varianten
     * zählen über ihren Hauptartikel, damit nicht zwei Längen desselben Rohrs nebeneinanderstehen.
     *
     * @param list<list<array{id: string, parentId: ?string}>> $lists
     * @param array<string, true>                             $owned
     *
     * @return list<string>
     */
    public static function mix(array $lists, array $owned, int $limit): array
    {
        $chosen = [];
        $seen = $owned;
        for ($round = 0; \count($chosen) < $limit; ++$round) {
            $any = false;
            foreach ($lists as $list) {
                if (!isset($list[$round])) {
                    continue;
                }
                $any = true;
                $pair = $list[$round];
                $main = $pair['parentId'] ?? $pair['id'];
                if (isset($seen[$pair['id']]) || isset($seen[$main])) {
                    continue;
                }
                $seen[$pair['id']] = $seen[$main] = true;
                $chosen[] = $pair['id'];
                if (\count($chosen) === $limit) {
                    break;
                }
            }
            if (!$any) {
                break;
            }
        }

        return $chosen;
    }

    /**
     * @param list<array{id: string, parentId: ?string}> $products
     * @param array<string, true>                         $owned
     */
    private static function anyOwned(array $products, array $owned): bool
    {
        foreach ($products as $pair) {
            if (isset($owned[$pair['id']]) || ($pair['parentId'] !== null && isset($owned[$pair['parentId']]))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{id: string, parentId: ?string}>
     */
    private static function idPairs(ProductCollection $products): array
    {
        $pairs = [];
        foreach ($products as $product) {
            $pairs[] = ['id' => $product->getId(), 'parentId' => $product->getParentId()];
        }

        return $pairs;
    }
}
