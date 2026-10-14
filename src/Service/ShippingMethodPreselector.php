<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;

/**
 * Entscheidet, welche Versandart vorausgewählt wird, wenn die eingestellte nicht verfügbar ist.
 *
 * Greift die Standard-Versandart des Kanals für den Warenkorb nicht, etwa ein Paketdienst bis
 * 5 kg und 1200 mm bei Handläufen und Pfosten, wählt Shopware nichts vor. Der Kunde steht vor
 * einer Liste ohne Anhakung; wer sich auskennt, klickt die kostenlose Selbstabholung an, wer
 * nicht, ruft an.
 *
 * Eigene Klasse, weil die Entscheidung reine Rechenarbeit auf einer Liste ist und weder mit dem
 * Kontext noch mit dem Warenkorb zu tun hat. So lässt sie sich ohne Shopware prüfen.
 */
final class ShippingMethodPreselector
{
    /**
     * Die Versandart, auf die umgeschaltet werden soll, oder `null`, wenn nichts zu tun ist.
     *
     * `null` heißt zweierlei: Die eingestellte Versandart ist verfügbar und bleibt, oder es
     * bleibt keine übrig, auf die umgeschaltet werden darf, und der Anfrageweg greift.
     *
     * Die Standard-Versandart des Kanals geht vor. Sie ist die Angabe des Betreibers, was der
     * Regelfall sein soll, typischerweise der Paketversand. Erst wenn sie für diesen Warenkorb
     * nicht verfügbar ist, entscheidet die Position; Shopware hält es im Kern genauso.
     *
     * Eine Nicht-Lieferart bleibt nur stehen, wenn der Kunde sie selbst gewählt hat. Die
     * Abholung ist oft die einzige Versandart ohne Gewichts- und Längengrenze, und Shopware
     * landet von selbst auf ihr, sobald der Paketdienst nicht mehr greift. Sie ist dann
     * verfügbar; wer nur darauf sieht, lässt sie angehakt stehen, obwohl der Kunde sie nie
     * angeklickt hat.
     *
     * Der Aufrufer weiß über den `ShippingChoiceStore`, ob ein Klick vorlag. Die Vorgabe `true`
     * lässt eine Nicht-Lieferart stehen; wer den Wert nicht kennt, bekommt kein stilles
     * Umschalten.
     *
     * Der Platzhalter ist eine Versandart, die nur ohne Lieferadresse verfügbar ist. Er ist nie
     * Kandidat und wird verlassen, sobald eine Lieferart verfügbar ist, auch wenn der Kunde ihn
     * angeklickt hat: Bestellen lässt er sich ohnehin nicht. Bleibt keine Lieferart, tritt er an
     * die Stelle dessen, was sonst stehen bliebe. Ein Platzhalter, der bereits im Kontext steht,
     * bleibt dann stehen (`null`).
     *
     * @param list<string> $nonDeliveryMethodIds Versandarten, die keine Lieferung sind
     */
    public function preselect(
        ShippingMethodCollection $available,
        string $currentShippingMethodId,
        array $nonDeliveryMethodIds,
        ?string $defaultShippingMethodId = null,
        bool $currentWasChosenByCustomer = true,
        ?string $placeholderMethodId = null,
    ): ?ShippingMethodEntity {
        $currentIsPlaceholder = $currentShippingMethodId === $placeholderMethodId;
        $mustLeaveCurrent = $currentIsPlaceholder || (!$currentWasChosenByCustomer
            && \in_array($currentShippingMethodId, $nonDeliveryMethodIds, true));

        if (!$mustLeaveCurrent && $available->has($currentShippingMethodId)) {
            return null;
        }

        $excluded = $placeholderMethodId === null
            ? $nonDeliveryMethodIds
            : [...$nonDeliveryMethodIds, $placeholderMethodId];
        $candidates = $this->deliveryMethodsByPosition($available, $excluded);

        foreach ($candidates as $candidate) {
            if ($candidate->getId() === $defaultShippingMethodId) {
                return $candidate;
            }
        }

        if ($candidates !== []) {
            return $candidates[0];
        }

        if ($currentIsPlaceholder || $placeholderMethodId === null) {
            return null;
        }

        // Ein gesperrter Platzhalter fehlt in der Liste und gibt deshalb `null`. Mit Adresse ist
        // das der Normalfall, und auf ihn umzuschalten machte die Bestellung unmöglich.
        return $available->get($placeholderMethodId);
    }

    /**
     * Die lieferfähigen Versandarten, aufsteigend nach Position.
     *
     * Die Position ist der Maßstab und nicht der Preis: Sie ist die Reihenfolge, in der die
     * Storefront die Liste ohnehin zeigt und die der Betreiber im Verwaltungsbereich selbst
     * festlegt. Die Selbstabholung steht typischerweise ganz hinten und wird zusätzlich über die
     * Liste „keine Lieferung" ausgeschlossen, damit die Automatik sie auch dann nicht greift,
     * wenn jemand die Positionen umstellt. Bei gleicher Position entscheidet der Name, damit die
     * Wahl nicht von der Ladereihenfolge abhängt.
     *
     * @param list<string> $nonDeliveryMethodIds
     *
     * @return list<ShippingMethodEntity>
     */
    private function deliveryMethodsByPosition(
        ShippingMethodCollection $available,
        array $nonDeliveryMethodIds,
    ): array {
        $candidates = [];

        foreach ($available as $method) {
            if (\in_array($method->getId(), $nonDeliveryMethodIds, true)) {
                continue;
            }

            $candidates[] = $method;
        }

        usort(
            $candidates,
            static fn (ShippingMethodEntity $left, ShippingMethodEntity $right): int
                => [$left->getPosition(), $left->getName() ?? ''] <=> [$right->getPosition(), $right->getName() ?? ''],
        );

        return $candidates;
    }
}
