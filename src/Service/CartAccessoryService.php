<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Ruhrcoder\RcCheckoutEnhancer\Checkout\AccessoryChoice;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Content\Product\SalesChannel\CrossSelling\AbstractProductCrossSellingRoute;
use Shopware\Core\Content\Product\SalesChannel\Detail\ProductDetailRoute;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Cache\EntityCacheKeyGenerator;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

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
 * Regeln für Sichtbarkeit und Verfügbarkeit, gepflegte Listen wie dynamische Produktgruppen. Zwischengespeichert werden
 * nur Gruppen und Produktkennungen; die Vorschläge selbst werden je Aufruf frisch geladen.
 */
final class CartAccessoryService
{
    // Eine Kachelreihe auf der Warenkorbseite (vier Spalten), am Telefon zwei Zeilen zu zwei.
    private const SUGGESTION_LIMIT = 4;
    // Mehr als vier Erinnerungen liest unter den Positionen niemand mehr.
    private const CHECKLIST_LIMIT = 4;
    // Jeder Hauptartikel kostet ohne Zwischenspeicher eine Abfrage der Querverkaufs-Route; bei großen Warenkörben
    // reichen die ersten zehn.
    private const PRODUCT_LIMIT = 10;
    // Die Leiste geht nach jedem Hinzufügen auf. Ändert jemand den Querverkauf, löscht der Kern den Eintrag über den
    // Tag; die Stunde begrenzt nur, wie lange eine dynamische Produktgruppe auf dem alten Stand bleibt.
    private const CACHE_SECONDS = 3600;

    /**
     * @param SalesChannelRepository<SalesChannelProductCollection> $productRepository
     */
    public function __construct(
        private readonly AbstractProductCrossSellingRoute $crossSellingRoute,
        private readonly SalesChannelRepository $productRepository,
        private readonly ConfigService $configService,
        private readonly TagAwareCacheInterface $cache,
    ) {
    }

    /**
     * @return array{
     *     suggestions: list<SalesChannelProductEntity>,
     *     checklist: list<array{name: string, productId: string, productName: string}>
     * }|null
     */
    public function load(Cart $cart, SalesChannelContext $context): ?array
    {
        $ids = self::productIds($cart);
        if ($ids === []) {
            return null;
        }

        [$owned, $mains] = self::ownedAndMains($this->productRepository->search(new Criteria($ids), $context)->getEntities());
        [$bought, $checklist] = $this->collect($mains, $owned, $context);

        $suggestions = $this->suggestions(AccessoryChoice::mix($bought, $owned, self::SUGGESTION_LIMIT), $context);
        $checklist = \array_slice($checklist, 0, self::CHECKLIST_LIMIT);

        return $suggestions === [] && $checklist === [] ? null : ['suggestions' => $suggestions, 'checklist' => $checklist];
    }

    /**
     * @return list<string>
     */
    private static function productIds(Cart $cart): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (LineItem $item): ?string => $item->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE ? $item->getReferencedId() : null,
            $cart->getLineItems()->getFlat(),
        ))));
    }

    /**
     * Eine Variante im Warenkorb gilt auch für ihren Hauptartikel als vorhanden. Querverkauf und Kategorien hängen am
     * Hauptartikel; die erste Variante je Hauptartikel steht für ihn.
     *
     * @return array{0: array<string, true>, 1: array<string, SalesChannelProductEntity>}
     */
    private static function ownedAndMains(SalesChannelProductCollection $inCart): array
    {
        $owned = [];
        $mains = [];
        foreach ($inCart as $product) {
            $owned[$product->getId()] = true;
            $main = $product->getParentId() ?? $product->getId();
            $owned[$main] = true;
            $mains[$main] ??= $product;
        }

        return [$owned, $mains];
    }

    /**
     * „Oft zusammen gekauft" speist „Dazu passt"; jede andere Gruppe eines Bausatzes wird zur Erinnerung, solange aus
     * ihr nichts im Warenkorb liegt.
     *
     * @param array<string, SalesChannelProductEntity> $mains
     * @param array<string, true>                      $owned
     *
     * @return array{0: list<list<array{id: string, parentId: ?string}>>, 1: list<array{name: string, productId: string, productName: string}>}
     */
    private function collect(array $mains, array $owned, SalesChannelContext $context): array
    {
        $kitCategories = $this->configService->getCartAccessoryKitCategoryIds($context->getSalesChannelId());
        // Verglichen wird kleingeschrieben mit dem übersetzten Namen der Gruppe; die Namen je Sprache kommen aus den
        // Einstellungen, sonst fiele die Gruppe in einem englischen Kanal unter „Haben Sie an alles gedacht?".
        $alsoBought = $this->configService->getCartAccessoryGroupNames($context->getSalesChannelId());

        $bought = [];
        $checklist = [];
        foreach (\array_slice(array_keys($mains), 0, self::PRODUCT_LIMIT) as $mainId) {
            $isKit = AccessoryChoice::isKit(array_values($mains[$mainId]->getCategoryTree() ?? []), $kitCategories);

            foreach ($this->groups($mainId, $context) as $group) {
                if (\in_array(mb_strtolower($group['name']), $alsoBought, true)) {
                    $bought[] = $group['products'];
                } elseif ($isKit && $group['name'] !== '' && $group['products'] !== [] && !AccessoryChoice::anyOwned($group['products'], $owned)) {
                    $checklist[] = [
                        'name' => $group['name'],
                        'productId' => $mainId,
                        'productName' => (string) ($mains[$mainId]->getTranslation('name') ?? $mains[$mainId]->getName()),
                    ];
                }
            }
        }

        return [$bought, $checklist];
    }

    /**
     * Die Querverkaufsgruppen eines Hauptartikels mit den Kennungen ihrer Produkte, zwischengespeichert je Kanal,
     * Sprache, Währung und Regeln. Die Regeln gehören dazu, weil die Route Produkte nach Sichtbarkeit und Preisregeln
     * auswählt.
     *
     * @return list<array{name: string, products: list<array{id: string, parentId: ?string}>}>
     */
    private function groups(string $mainId, SalesChannelContext $context): array
    {
        $ruleIds = $context->getRuleIds();
        sort($ruleIds);
        $key = 'rc-checkout-accessory-' . md5(implode('|', [
            $mainId,
            $context->getSalesChannelId(),
            $context->getLanguageId(),
            $context->getCurrencyId(),
            implode(',', $ruleIds),
        ]));

        /** @var list<array{name: string, products: list<array{id: string, parentId: ?string}>}> $groups */
        $groups = $this->cache->get($key, function (ItemInterface $item) use ($mainId, $context): array {
            $item->expiresAfter(self::CACHE_SECONDS);
            // Der Kern löscht diese Tags, wenn Querverkauf oder Produkt sich ändern.
            $item->tag([ProductDetailRoute::buildName($mainId), EntityCacheKeyGenerator::buildProductTag($mainId)]);

            $groups = [];
            foreach ($this->crossSellingRoute->load($mainId, new Request(), $context, new Criteria())->getResult() as $element) {
                $crossSelling = $element->getCrossSelling();
                $groups[] = [
                    'name' => trim((string) ($crossSelling->getTranslation('name') ?? $crossSelling->getName())),
                    'products' => AccessoryChoice::idPairs($element->getProducts()),
                ];
            }

            return $groups;
        });

        return $groups;
    }

    /**
     * Die gewählten Vorschläge mit Bild, in der abwechselnden Reihenfolge aus {@see AccessoryChoice::mix()}; die Suche liefert in eigener.
     *
     * @param list<string> $ids
     *
     * @return list<SalesChannelProductEntity>
     */
    private function suggestions(array $ids, SalesChannelContext $context): array
    {
        if ($ids === []) {
            return [];
        }

        $criteria = new Criteria($ids);
        $criteria->addAssociation('cover.media');
        $found = $this->productRepository->search($criteria, $context)->getEntities();

        $suggestions = [];
        foreach ($ids as $id) {
            $product = $found->get($id);
            if ($product instanceof SalesChannelProductEntity) {
                $suggestions[] = $product;
            }
        }

        return $suggestions;
    }
}
