<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Ruhrcoder\RcCheckoutEnhancer\Struct\CartMeasurements;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Schreibt den Warenkorb als Text, den der Vertrieb ohne Rückfrage rechnen kann.
 *
 * Ein Kasten „bitte rufen Sie an" bringt wenig: Der Kunde ruft an, und der Vertrieb nimmt
 * Artikelnummern, Mengen, Maße und Gewichte per Telefon neu auf. Die Anfrage bringt all das
 * deshalb gleich mit.
 *
 * Gelesen werden die Nutzdaten der Position, kein gerendertes Markup. Die Kundeneingaben
 * anderer Plugins, etwa die RAL-Farbe von RcColorPicker oder die Zuschnittlänge von
 * TmmsProductCustomerInputs, hängen im Payload der Position. Die fertige Positionszeile wäre
 * HTML und verlöre bei jeder Theme-Änderung Angaben.
 *
 * Fremde Angaben werden nicht aufgezählt, sondern übrig gelassen. Eine Liste bekannter
 * Plugin-Schlüssel veraltete mit jedem neuen Plugin; bekannt ist stattdessen, was Shopware
 * selbst in den Payload schreibt, und alles andere stammt von einem Plugin.
 *
 * Nicht `final`, weil die Tests des Anfrage-Controllers ihn als Test-Double ersetzen.
 */
class ShippingEnquirySummary
{
    /**
     * Was Shopware selbst in den Payload einer Produktposition legt.
     *
     * Abgeschrieben aus `ProductCartProcessor` des Kerns. Alles, was hier nicht steht, hat
     * ein Plugin angehängt, und das ist die Kundeneingabe, um die es geht.
     *
     * `productNumber` und `options` stehen in der Liste, weil sie an anderer Stelle
     * ausdrücklich ausgegeben werden; ein zweites Mal als „Kundeneingabe" wären sie Lärm.
     *
     * Nimmt der Kern einen Schlüssel dazu, taucht er als vermeintliche Kundeneingabe in der
     * Anfrage auf. `productType` setzt der Kern einzeln (`LineItem::PAYLOAD_PRODUCT_TYPE`)
     * und nicht im selben Block wie die übrigen; der Test hält ihn fest. Wer hier etwas
     * ergänzt, ergänzt es dort mit.
     */
    private const CORE_PAYLOAD_KEYS = [
        'productType',
        'isCloseout',
        'customFields',
        'createdAt',
        'releaseDate',
        'isNew',
        'markAsTopseller',
        'purchasePrices',
        'productNumber',
        'manufacturerId',
        'taxId',
        'tagIds',
        'categoryIds',
        'propertyIds',
        'optionIds',
        'options',
        'streamIds',
        'parentId',
        'stock',
    ];

    /**
     * Grenze für die Länge eines einzelnen Werts.
     *
     * Ein Freitextfeld kann ein ganzes Formular aufnehmen; in eine Anfrage gehört davon der
     * Anfang. Ohne Grenze schöbe eine einzige Position den Rest der Anfrage aus dem Blick.
     * 200 Zeichen fassen jede Farb- oder Maßangabe und bleiben im Formular wenige Zeilen.
     */
    private const MAX_VALUE_LENGTH = 200;

    public function __construct(private readonly CartExposedCustomFields $exposedCustomFields)
    {
    }

    public function forCart(Cart $cart, SalesChannelContext $context): string
    {
        $lines = [];
        $currency = $context->getCurrency()->getSymbol();

        foreach ($cart->getLineItems()->filterGoodsFlat() as $lineItem) {
            $lines[] = $this->lineFor($lineItem, $currency);

            foreach ($this->detailsOf($lineItem) as $detail) {
                $lines[] = '    ' . $detail;
            }
        }

        if ($lines === []) {
            return '';
        }

        return implode("\n", [...$lines, '', ...$this->totalsOf($cart, $context, $currency)]);
    }

    private function lineFor(LineItem $lineItem, string $currency): string
    {
        $number = $lineItem->getPayloadValue('productNumber');
        $label = $lineItem->getLabel() ?? '';

        $head = \is_string($number) && $number !== ''
            ? \sprintf('%d × %s — %s', $lineItem->getQuantity(), $number, $label)
            : \sprintf('%d × %s', $lineItem->getQuantity(), $label);

        $price = $lineItem->getPrice();

        return $price === null
            ? $head
            : \sprintf('%s (%s)', $head, $this->money($price->getTotalPrice(), $currency));
    }

    /**
     * Die Angaben unter einer Position: Varianten, Kundeneingaben, Maße.
     *
     * @return list<string>
     */
    private function detailsOf(LineItem $lineItem): array
    {
        $details = [];

        foreach ($this->variantOptionsOf($lineItem) as $option) {
            $details[] = $option;
        }

        foreach ($this->customerInputOf($lineItem) as $label => $value) {
            $details[] = \sprintf('%s: %s', $label, $value);
        }

        $measurements = $this->measurementsOf($lineItem);
        if ($measurements !== '') {
            $details[] = $measurements;
        }

        return $details;
    }

    /**
     * @return list<string>
     */
    private function variantOptionsOf(LineItem $lineItem): array
    {
        $options = $lineItem->getPayloadValue('options');
        if (!\is_array($options)) {
            return [];
        }

        $result = [];
        foreach ($options as $option) {
            if (!\is_array($option)) {
                continue;
            }

            $group = $this->asText($option['group'] ?? null);
            $value = $this->asText($option['option'] ?? null);

            if ($group !== null && $value !== null) {
                $result[] = \sprintf('%s: %s', $group, $value);
            }
        }

        return $result;
    }

    /**
     * Alles, was ein Plugin an die Position gehängt hat.
     *
     * @return array<string, string>
     */
    private function customerInputOf(LineItem $lineItem): array
    {
        $input = [];

        foreach ($lineItem->getPayload() as $key => $value) {
            if (\in_array($key, self::CORE_PAYLOAD_KEYS, true)) {
                continue;
            }

            $text = $this->asText($value);
            if ($text !== null) {
                $input[$key] = $text;
            }
        }

        // Die Felder des Produkts stehen ebenfalls im Payload, eine Ebene tiefer. Sie
        // tragen die Eingaben von Plugins, die mit Zusatzfeldern statt mit eigenen
        // Payload-Schlüsseln arbeiten. Nur die für den Warenkorb freigegebenen; die übrigen sind
        // interne Felder des Produkts ({@see CartExposedCustomFields}).
        $customFields = $lineItem->getPayloadValue('customFields');
        if (\is_array($customFields)) {
            $exposed = $this->exposedCustomFields->names();
            foreach ($customFields as $key => $value) {
                if (!\in_array((string) $key, $exposed, true)) {
                    continue;
                }

                $text = $this->asText($value);
                if ($text !== null) {
                    $input[(string) $key] = $text;
                }
            }
        }

        return $input;
    }

    private function measurementsOf(LineItem $lineItem): string
    {
        $delivery = $lineItem->getDeliveryInformation();
        if ($delivery === null) {
            return '';
        }

        $parts = [];

        $weight = $delivery->getWeight();
        if ($weight !== null && $weight > 0.0) {
            $parts[] = \sprintf('%s kg/Stück', $this->number($weight));
        }

        $length = $delivery->getLength();
        $width = $delivery->getWidth();
        $height = $delivery->getHeight();
        if ($length !== null && $width !== null && $height !== null && $length > 0.0) {
            $parts[] = \sprintf(
                '%s × %s × %s mm',
                $this->number($length),
                $this->number($width),
                $this->number($height),
            );
        }

        return $parts === [] ? '' : implode(', ', $parts);
    }

    /**
     * Die Zahlen, die der Vertrieb für ein Frachtangebot braucht.
     *
     * @return list<string>
     */
    private function totalsOf(Cart $cart, SalesChannelContext $context, string $currency): array
    {
        $totals = [\sprintf('Warenwert: %s', $this->money($cart->getPrice()->getPositionPrice(), $currency))];

        $measurements = CartMeasurements::fromCart($cart);

        if ($measurements->totalWeight > 0.0) {
            $totals[] = \sprintf('Gesamtgewicht: %s kg', $this->number($measurements->totalWeight));
        }

        if ($measurements->longestLength > 0.0) {
            $totals[] = \sprintf('Längste Position: %s mm', $this->number($measurements->longestLength));
        }

        return [...$totals, ...$this->destinationOf($context)];
    }

    /**
     * Wohin geliefert werden soll, so vollständig, wie es der Kontext hergibt.
     *
     * Die ganze Anschrift, weil ein Frachtpreis an der Abladestelle hängt. Mit „Deutschland
     * 44787" müsste der Vertrieb nachfragen, bevor er rechnen kann, und diesen Anruf soll die
     * Anfrage ersparen. Die Firma steht mit dabei, weil sie bei einer Spedition darüber
     * entscheidet, ob eine Rampe da ist oder eine Hebebühne gebraucht wird.
     *
     * Steht die Anschrift noch nicht fest, bleibt es bei der einen Zeile mit dem Land.
     * Geraten wird nichts.
     *
     * @return list<string>
     */
    private function destinationOf(SalesChannelContext $context): array
    {
        $location = $context->getShippingLocation();
        $country = $location->getCountry()->getTranslation('name') ?? $location->getCountry()->getName();
        $country = \is_string($country) ? trim($country) : '';

        $address = $location->getAddress();
        if ($address === null) {
            return $country === '' ? [] : [\sprintf('Lieferung nach: %s', $country)];
        }

        $city = trim(\sprintf('%s %s', $address->getZipcode() ?? '', $address->getCity()));

        $lines = array_values(array_filter([
            $address->getCompany(),
            $address->getStreet(),
            $city === '' ? null : $city,
            $country === '' ? null : $country,
        ], static fn (?string $line): bool => $line !== null && trim($line) !== ''));

        if ($lines === []) {
            return [];
        }

        return ['Lieferung nach:', ...array_map(static fn (string $line): string => '    ' . trim($line), $lines)];
    }

    /**
     * Ein Payload-Wert als Text, oder `null`, wenn er keiner ist.
     *
     * Wahrheitswerte bleiben draußen: Ein `rcColorPickerActive: true` sagt dem Vertrieb
     * nichts, es ist ein Schalter für die Anzeige. Verschachtelte Felder ebenso; was sich
     * nicht in eine Zeile schreiben lässt, gehört nicht in eine Anfrage.
     */
    private function asText(mixed $value): ?string
    {
        if (\is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : mb_substr($value, 0, self::MAX_VALUE_LENGTH);
        }

        return \is_int($value) || \is_float($value) ? $this->number((float) $value) : null;
    }

    /**
     * Die Beträge stehen schon in der Währung des Warenkorbs; dazu gehört deren Zeichen, nicht ein
     * festes Euro-Zeichen.
     */
    private function money(float $amount, string $currency): string
    {
        return number_format($amount, 2, ',', '.') . ' ' . $currency;
    }

    /**
     * Zahlen ohne überflüssige Nullen: 53 statt 53,000, aber 1,5 bleibt 1,5.
     */
    private function number(float $value): string
    {
        $rounded = round($value, 2);

        return $rounded == (int) $rounded
            ? (string) (int) $rounded
            : rtrim(number_format($rounded, 2, ',', ''), '0');
    }
}
