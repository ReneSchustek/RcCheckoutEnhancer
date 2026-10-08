<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Ruhrcoder\RcCheckoutEnhancer\Subscriber\PickupAcknowledgementOrderSubscriber;
use Shopware\Core\Checkout\Order\OrderEntity;

/**
 * Entscheidet, ob auf einen Beleg der Hinweis zur Selbstabholung gehört.
 *
 * Die Zusage, dass Verladen und Transport beim Käufer liegen, gehört auf den Beleg, weil er beim
 * Kunden bleibt und im Streitfall vorgelegt wird; im Zusatzfeld der Bestellung sieht sie dann
 * niemand.
 *
 * Es entscheidet die Versandart und nicht die Bestätigung. Abholbestellungen ohne Dialog gibt es,
 * etwa bei abgeschalteter Bestätigung, und deren Belege brauchen den Satz genauso; er beschreibt
 * die Lage und keine Handlung des Kunden.
 *
 * Das Datum steht getrennt davon, weil „bestätigt am …" eine Tatsachenbehauptung ist. Ohne
 * Bestätigung sähe ein Datum aus wie ein Nachweis und wäre keiner; deshalb darf der Wert fehlen.
 *
 * Ein fertiger Satz kommt hier nicht heraus. Der Wortlaut steht im Textbaustein und wird in der
 * Sprache des Belegs übersetzt.
 */
final class PickupDocumentNote
{
    /**
     * Die Zeitzone, in der der Beleg seinen Tag zählt.
     *
     * Fest und nicht die des Servers: Der Zeitpunkt wird mit Zeitzonen-Angabe abgelegt, welcher
     * Kalendertag daraus wird, hängt aber von der Zone ab, in der man ihn liest. Eine Bestätigung
     * um 00:30 deutscher Zeit fiele in UTC auf den Vortag, und auf dem Beleg stünde ein Datum,
     * das der Kunde nicht wiedererkennt. Die Serverzeit wäre dasselbe Problem mit einer
     * zusätzlichen Unbekannten.
     */
    private const TIMEZONE = 'Europe/Berlin';

    public function __construct(private readonly ConfigService $configService)
    {
    }

    /**
     * Gehört der Hinweis auf den Beleg, und trägt er ein Bestätigungsdatum?
     *
     * @return array{confirmedAt: string|null}|null `null`, wenn es keine Abholbestellung ist
     */
    public function forOrder(OrderEntity $order): ?array
    {
        $shippingMethodId = $order->getDeliveries()?->first()?->getShippingMethodId();
        if ($shippingMethodId === null) {
            return null;
        }

        $pickupMethodIds = $this->configService->getNonDeliveryMethodIds($order->getSalesChannelId());
        if (!\in_array($shippingMethodId, $pickupMethodIds, true)) {
            return null;
        }

        return ['confirmedAt' => $this->confirmedAt($order)];
    }

    /**
     * Der Tag der Bestätigung, deutsch geschrieben, oder `null`.
     *
     * Das Feld ist gewachsener Bestand: Eine alte Bestellung kann dort alles Mögliche tragen. Ein
     * unlesbarer Wert kostet deshalb nur das Datum und nicht die Rechnungserzeugung.
     */
    private function confirmedAt(OrderEntity $order): ?string
    {
        $acknowledgement = ($order->getCustomFields() ?? [])[PickupAcknowledgementOrderSubscriber::CUSTOM_FIELD] ?? null;
        if (!\is_array($acknowledgement) || !\is_string($acknowledgement['at'] ?? null)) {
            return null;
        }

        $zone = new \DateTimeZone(self::TIMEZONE);

        try {
            // Die Zone im Aufruf greift nur, wenn die Zeichenkette selbst keine trägt. Ein
            // abgelegter Zeitpunkt trägt sie; fehlt sie in einem alten Datensatz, gilt die Zeit
            // des Shops und nicht die des Servers.
            $moment = new \DateTimeImmutable($acknowledgement['at'], new \DateTimeZone(self::TIMEZONE));
        } catch (\Exception) {
            return null;
        }

        return $moment->setTimezone($zone)->format('d.m.Y');
    }
}
