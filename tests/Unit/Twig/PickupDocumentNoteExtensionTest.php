<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\PickupDocumentNote;
use Ruhrcoder\RcCheckoutEnhancer\Twig\PickupDocumentNoteExtension;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;

/**
 * Die Twig-Funktion, über die Rechnung, Lieferschein, Storno und Gutschrift den Abhol-Hinweis
 * erfragen. Entschieden wird im Dienst; die Funktion reicht nur durch.
 */
final class PickupDocumentNoteExtensionTest extends TestCase
{
    public function testTheFunctionIsRegistered(): void
    {
        $names = array_map(static fn ($function): string => $function->getName(), $this->extension()->getFunctions());

        self::assertSame(['rc_pickup_document_note'], $names);
    }

    /**
     * Was: Ein Beleg ohne Bestellung, etwa die Vorschau im Verwaltungsbereich.
     */
    public function testWithoutAnOrderThereIsNoNote(): void
    {
        self::assertNull($this->extension()->forOrder(null));
    }

    public function testAPickupOrderGetsTheNote(): void
    {
        self::assertSame(['confirmedAt' => null], $this->extension()->forOrder($this->order('sm-abholung')));
    }

    public function testADeliveredOrderGetsNoNote(): void
    {
        self::assertNull($this->extension()->forOrder($this->order('sm-paket')));
    }

    private function extension(): PickupDocumentNoteExtension
    {
        $configService = $this->createMock(ConfigService::class);
        $configService->method('getNonDeliveryMethodIds')->willReturn(['sm-abholung']);

        return new PickupDocumentNoteExtension(new PickupDocumentNote($configService));
    }

    private function order(string $shippingMethodId): OrderEntity
    {
        $delivery = new OrderDeliveryEntity();
        $delivery->setId('lieferung');
        $delivery->setUniqueIdentifier('lieferung');
        $delivery->setShippingMethodId($shippingMethodId);

        $order = new OrderEntity();
        $order->setId('bestellung');
        $order->setSalesChannelId('sc-id');
        $order->setDeliveries(new OrderDeliveryCollection([$delivery]));

        return $order;
    }
}
