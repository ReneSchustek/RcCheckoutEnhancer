<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcCheckoutEnhancer\Struct\FreightHint;
use Ruhrcoder\RcCheckoutEnhancer\Struct\ShippingEstimateResult;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Der Speditionshinweis auf der Produktseite.
 *
 * Bei langer und schwerer Ware ist die Spedition der Normalfall, und sie kostet schnell gut
 * hundert Euro. Ohne den Hinweis stünde auf der Produktseite nur „zzgl. Versandkosten", und der
 * Kunde erführe den Preis erst im Warenkorb.
 *
 * Nachgebaut wird nichts. Der Artikel kommt in einen Wegwerf-Warenkorb, und den rechnet derselbe
 * {@see ShippingEstimateService} durch, der auch den Rechner im Warenkorb bedient, mit Shopwares
 * eigener Berechnung und denselben Regeln wie im Checkout. Speditionsregeln hängen an Gewicht,
 * Länge, Schlagworten, Eigenschaften und Postleitzahl-Zonen; eine Nachbildung läge beim ersten
 * geänderten Band still daneben.
 *
 * Die Zonen kommen als Postleitzahlen aus der Einstellung, je Zone eine. Der Hinweis gilt
 * deshalb nur im Heimatland des Verkaufskanals; für ein anderes Land wären es die falschen
 * Postleitzahlen.
 */
class FreightHintService
{
    /**
     * Sechs Stunden. Je Postleitzahl kostet die Spanne eine Handvoll Warenkorb-Berechnungen, und
     * die soll nicht jeder Aufruf neu anstoßen. Das liegt in der Größenordnung des Takts der
     * Feed-Vorberechnung: Eine geänderte Versandart zeigt sich spätestens dann, und
     * `cache:clear` räumt sofort ab.
     */
    private const CACHE_SECONDS = 21600;

    /**
     * @param SalesChannelRepository<ProductCollection> $productRepository
     */
    public function __construct(
        private readonly ConfigService $configService,
        private readonly ShippingEstimateService $estimateService,
        private readonly SalesChannelRepository $productRepository,
        private readonly LineItemFactoryRegistry $lineItemFactory,
        private readonly CacheInterface $cache,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Der Hinweis für diesen Artikel, oder `null`, wenn er nicht per Spedition geht, der
     * Hinweis aus ist oder sich nichts Belastbares sagen lässt.
     */
    public function forProduct(string $productId, SalesChannelContext $context): ?FreightHint
    {
        $salesChannelId = $context->getSalesChannelId();
        if (!$this->configService->isFreightHintEnabled($salesChannelId)) {
            return null;
        }

        $freightMethodIds = $this->configService->getFreightShippingMethodIds($salesChannelId);
        $zipCodes = $this->configService->getFreightHintZipCodes($salesChannelId);
        if ($freightMethodIds === [] || $zipCodes === [] || !$this->isHomeCountry($context)) {
            return null;
        }

        $product = $this->product($productId, $context);
        if ($product === null) {
            return null;
        }

        $quantity = max(1, $product->getMinPurchase() ?? 1);
        $range = $this->cachedRange($product, $quantity, $context, $freightMethodIds, $zipCodes);
        if ($range === null) {
            return null;
        }

        $countryName = (string) ($context->getShippingLocation()->getCountry()->getTranslation('name') ?? '');

        return new FreightHint($range['min'], $range['max'], $range['currency'], $countryName, $quantity, ...$this->reason($product, $quantity, $salesChannelId));
    }

    /**
     * Die Auskunft für eine Postleitzahl, die der Kunde selbst eingibt — je Versandart, wie im
     * Warenkorb. Nicht zwischengespeichert: Die Eingabe ist frei, der Endpunkt ist begrenzt.
     */
    public function estimateForZip(string $productId, SalesChannelContext $context, string $zipCode): ShippingEstimateResult
    {
        $countryIso = (string) $context->getShippingLocation()->getCountry()->getIso();

        $product = $this->product($productId, $context);
        $cart = $product === null ? null : $this->cartFor($product, max(1, $product->getMinPurchase() ?? 1), $context);
        if ($cart === null) {
            return ShippingEstimateResult::withoutShippingMethod($countryIso, $zipCode);
        }

        return $this->estimateService->estimate($cart, $context, $countryIso, $zipCode);
    }

    /**
     * @param list<string> $freightMethodIds
     * @param list<string> $zipCodes
     *
     * @return array{min: float, max: float, currency: string}|null
     */
    private function cachedRange(
        SalesChannelProductEntity $product,
        int $quantity,
        SalesChannelContext $context,
        array $freightMethodIds,
        array $zipCodes,
    ): ?array {
        $key = $this->cacheKey($product, $quantity, $context, $freightMethodIds, $zipCodes);

        try {
            /** @var array{range: array{min: float, max: float, currency: string}|null} $entry */
            $entry = $this->cache->get($key, function (ItemInterface $item) use ($product, $quantity, $context, $freightMethodIds, $zipCodes): array {
                $item->expiresAfter(self::CACHE_SECONDS);

                return ['range' => $this->range($product, $quantity, $context, $freightMethodIds, $zipCodes)];
            });

            return $entry['range'];
        } catch (Throwable $e) {
            // Ein Fehler beim Rechnen darf die Produktseite nicht stören. Dort bleibt dann
            // „zzgl. Versandkosten" stehen, und der Fehler steht im Log.
            $this->logger->error('Speditionshinweis konnte nicht berechnet werden', [
                'productId' => $product->getId(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Alles, wovon die Spanne abhängt.
     *
     * Ohne die Regelkennungen des Kontexts: Sie hängen am Warenkorb und wechselten mit jedem
     * Artikel, der Speicher zerfiele in Einzelstücke. Das ist nur dann falsch, wenn Preise von
     * Kundenregeln abhängen; im Shop gibt es weder regelabhängige Produkt- noch Versandpreise.
     * Kommen welche dazu, gehört `getRuleIds()` hier mit hinein.
     *
     * @param list<string> $freightMethodIds
     * @param list<string> $zipCodes
     */
    private function cacheKey(
        SalesChannelProductEntity $product,
        int $quantity,
        SalesChannelContext $context,
        array $freightMethodIds,
        array $zipCodes,
    ): string {
        return 'rc_freight_hint_' . hash('xxh128', (string) json_encode([
            $product->getId(),
            $quantity,
            $context->getSalesChannelId(),
            $context->getShippingLocation()->getCountry()->getId(),
            $context->getCurrencyId(),
            $context->getTaxState(),
            $context->getCurrentCustomerGroup()->getId(),
            $freightMethodIds,
            $this->configService->getNonDeliveryMethodIds($context->getSalesChannelId()),
            $zipCodes,
        ]));
    }

    /**
     * @param list<string> $freightMethodIds
     * @param list<string> $zipCodes
     *
     * @return array{min: float, max: float, currency: string}|null
     */
    private function range(
        SalesChannelProductEntity $product,
        int $quantity,
        SalesChannelContext $context,
        array $freightMethodIds,
        array $zipCodes,
    ): ?array {
        $cart = $this->cartFor($product, $quantity, $context);
        if ($cart === null) {
            return null;
        }

        $countryIso = (string) $context->getShippingLocation()->getCountry()->getIso();

        $results = [];
        foreach ($zipCodes as $zipCode) {
            $results[$zipCode] = $this->estimateService->estimate($cart, $context, $countryIso, $zipCode);
        }

        return FreightRange::of(
            $results,
            $freightMethodIds,
            $this->configService->getNonDeliveryMethodIds($context->getSalesChannelId()),
        );
    }

    /**
     * Ein Warenkorb mit genau diesem Artikel, unter einem Wegwerf-Token. Er wird nie
     * gespeichert; die Berechnung klont ihn ohnehin, bevor sie rechnet.
     */
    private function cartFor(SalesChannelProductEntity $product, int $quantity, SalesChannelContext $context): ?Cart
    {
        try {
            $lineItem = $this->lineItemFactory->create([
                'type' => LineItem::PRODUCT_LINE_ITEM_TYPE,
                'referencedId' => $product->getId(),
                'quantity' => $quantity,
            ], $context);
        } catch (Throwable $e) {
            // Ein Elternartikel mit Varianten oder ein nicht käuflicher Artikel lässt sich
            // nicht in einen Warenkorb legen. Ohne Warenkorb keine Lieferung, also kein Hinweis.
            $this->logger->info('Artikel lässt sich für den Speditionshinweis nicht in einen Warenkorb legen', [
                'productId' => $product->getId(),
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $cart = new Cart(Uuid::randomHex());
        $cart->add($lineItem);

        return $cart;
    }

    /**
     * Nur Artikel, die dieser Verkaufskanal zeigt. Der Endpunkt ist öffentlich und darf über
     * eine geratene Kennung nichts über versteckte Ware verraten.
     */
    private function product(string $productId, SalesChannelContext $context): ?SalesChannelProductEntity
    {
        if (!Uuid::isValid($productId)) {
            return null;
        }

        $product = $this->productRepository->search(new Criteria([$productId]), $context)->getEntities()->first();

        return $product instanceof SalesChannelProductEntity ? $product : null;
    }

    /**
     * Die Postleitzahlen der Einstellung gehören zum Heimatland des Verkaufskanals. Ein
     * angemeldeter Kunde mit Anschrift in Belgien bekäme sonst eine Spanne für deutsche Zonen.
     */
    private function isHomeCountry(SalesChannelContext $context): bool
    {
        return $context->getShippingLocation()->getCountry()->getId() === $context->getSalesChannel()->getCountryId();
    }

    /**
     * Warum Spedition, abgeleitet aus den Maßen des Artikels und denselben Schwellen, die auch der
     * Abhol-Hinweis benutzt. Die Länge geht vor: Sechs Meter passen auch leicht nicht ins Auto.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function reason(SalesChannelProductEntity $product, int $quantity, string $salesChannelId): array
    {
        $lengthThreshold = $this->configService->getPickupHintLengthThreshold($salesChannelId);
        $length = (float) ($product->getLength() ?? 0.0);
        if ($lengthThreshold !== null && $length > $lengthThreshold) {
            return [FreightHint::REASON_LENGTH, $this->number($length / 1000, 2) . ' m'];
        }

        $weightThreshold = $this->configService->getPickupHintWeightThreshold($salesChannelId);
        $weight = (float) ($product->getWeight() ?? 0.0) * $quantity;
        if ($weightThreshold !== null && $weight > $weightThreshold) {
            return [FreightHint::REASON_WEIGHT, $this->number($weight, 1) . ' kg'];
        }

        return [null, null];
    }

    /**
     * Dieselbe Schreibweise wie beim Abhol-Hinweis, ohne `intl`; siehe {@see PickupHintDecider}.
     */
    private function number(float $value, int $decimals): string
    {
        $german = !($this->translator instanceof LocaleAwareInterface)
            || !str_starts_with($this->translator->getLocale(), 'en');

        return $german
            ? number_format($value, $decimals, ',', '.')
            : number_format($value, $decimals, '.', ',');
    }
}
