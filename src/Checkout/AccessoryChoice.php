<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Checkout;

use Shopware\Core\Content\Product\ProductCollection;

/**
 * Die Auswahl für „Dazu passt" und „Haben Sie an alles gedacht?". Reine Rechnung, kein Dienst.
 */
final class AccessoryChoice
{
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
    public static function anyOwned(array $products, array $owned): bool
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
    public static function idPairs(ProductCollection $products): array
    {
        $pairs = [];
        foreach ($products as $product) {
            $pairs[] = ['id' => $product->getId(), 'parentId' => $product->getParentId()];
        }

        return $pairs;
    }
}
