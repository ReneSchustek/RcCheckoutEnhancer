<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Benchmarks\Support;

use Composer\Autoload\ClassLoader;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartRuleLoader;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\DbalKernelPluginLoader;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Kernel;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Der Messbestand: ein gestarteter Kern, der Kontext eines Gasts mit Anschrift im Liefergebiet und
 * ein berechneter Warenkorb mit einem Speditionsartikel.
 *
 * Jede Messung prüft vorher, dass sie über echte Daten läuft. Ohne Postleitzahl etwa antwortet
 * die Lieferbarkeits-Prüfung sofort mit „ja" und die Messung meldete eine hervorragende Zahl für
 * nichts; dann bricht der Lauf mit Erklärung ab.
 */
final class ShopwareFixture
{
    /** Handlauf mit Spedition 50 kg, im Bestand von live-clone. */
    public const PRODUCT_ID = '0da2af7608904acabf787c434e7e8408';

    /**
     * Die Versandart, die die Seite für diesen Artikel in Coburg vorauswählt; sie hat dort einen
     * Preis. Der doppelte Leerschritt steht so im Bestand.
     */
    public const FREIGHT_METHOD = 'Spedition 50 kg Deutschland  Zone 1';

    /** Die Vorgabe des Kanals; für den 24-kg-Artikel gesperrt, weil kein Preisband passt. */
    public const PARCEL_METHOD = 'Paketdienst Deutschland';

    private static ?KernelInterface $kernel = null;

    /**
     * Ein Dienst aus dem Container des Testmodus, der auch private Dienste herausgibt.
     *
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    public static function service(string $type, ?string $id = null): object
    {
        self::$kernel ??= self::boot();

        /** @var ContainerInterface $container */
        $container = self::$kernel->getContainer()->get('test.service_container');
        $service = $container->get($id ?? $type);

        if (!$service instanceof $type) {
            throw new RuntimeException(\sprintf('Dienst %s ist kein %s.', $id ?? $type, $type));
        }

        return $service;
    }

    /**
     * Der Kontext des jüngsten Messgasts. Das Seitenskript legt ihn bei jedem Lauf an, mit
     * Anschrift in 96450 Coburg; Kundendaten des Shops werden nicht angefasst.
     *
     * @param string|null $shippingMethodName ohne Angabe die Vorgabe des Kanals
     */
    public static function guestContext(?string $shippingMethodName = null): SalesChannelContext
    {
        $connection = self::service(Connection::class);

        $customerId = $connection->fetchOne(
            "SELECT LOWER(HEX(id)) FROM customer WHERE email LIKE 'messung-%@example.test' ORDER BY created_at DESC LIMIT 1",
        );
        if (!\is_string($customerId)) {
            throw new RuntimeException('Kein Messgast im Bestand. Zuerst benchmarks/http/checkout-pages.sh laufen lassen, es legt ihn an.');
        }

        $salesChannelId = $connection->fetchOne(
            'SELECT LOWER(HEX(sales_channel_id)) FROM sales_channel_domain WHERE url = :url LIMIT 1',
            ['url' => (string) ($_SERVER['APP_URL'] ?? '')],
        );
        if (!\is_string($salesChannelId)) {
            throw new RuntimeException('Kein Verkaufskanal zur Adresse APP_URL gefunden.');
        }

        $options = [SalesChannelContextService::CUSTOMER_ID => $customerId];
        if ($shippingMethodName !== null) {
            $options[SalesChannelContextService::SHIPPING_METHOD_ID] = self::shippingMethodId($connection, $shippingMethodName);
        }

        $factory = self::service(AbstractSalesChannelContextFactory::class, 'Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory');
        $context = $factory->create(Uuid::randomHex(), $salesChannelId, $options);

        if (($context->getShippingLocation()->getAddress()?->getZipcode() ?? '') === '') {
            throw new RuntimeException('Der Messgast hat keine Postleitzahl; die Prüfungen liefen ins Leere.');
        }

        return $context;
    }

    private static function shippingMethodId(Connection $connection, string $name): string
    {
        $id = $connection->fetchOne(
            'SELECT LOWER(HEX(shipping_method_id)) FROM shipping_method_translation WHERE name = :name LIMIT 1',
            ['name' => $name],
        );
        if (!\is_string($id)) {
            throw new RuntimeException(\sprintf('Versandart „%s" fehlt im Bestand.', $name));
        }

        return $id;
    }

    public static function calculatedCart(SalesChannelContext $context, int $quantity = 1): Cart
    {
        $lineItem = self::service(LineItemFactoryRegistry::class)->create([
            'type' => LineItem::PRODUCT_LINE_ITEM_TYPE,
            'referencedId' => self::PRODUCT_ID,
            'quantity' => $quantity,
        ], $context);

        $cart = new Cart($context->getToken());
        $cart->add($lineItem);

        $cart = self::service(CartRuleLoader::class)
            ->loadByCart($context, $cart, new CartBehavior($context->getPermissions()), true)
            ->getCart();

        if ($cart->getLineItems()->count() === 0 || $cart->getDeliveries()->count() === 0) {
            throw new RuntimeException('Der Messartikel fehlt im Bestand oder ist nicht lieferbar; gemessen würde ein leerer Warenkorb.');
        }

        return $cart;
    }

    /**
     * Testmodus wegen des Zugriffs auf private Dienste, ohne Debug, damit die Sammler des
     * Debug-Modus nicht mitgemessen werden.
     */
    private static function boot(): KernelInterface
    {
        /** @var ClassLoader $classLoader */
        $classLoader = $GLOBALS['rcBenchmarkClassLoader'];

        $kernel = KernelFactory::create(
            environment: 'test',
            debug: false,
            classLoader: $classLoader,
            pluginLoader: new DbalKernelPluginLoader($classLoader, null, Kernel::getConnection()),
        );
        if (!$kernel instanceof KernelInterface) {
            throw new RuntimeException('Die Kern-Fabrik lieferte keinen startbaren Kern.');
        }

        $kernel->boot();

        return $kernel;
    }
}
