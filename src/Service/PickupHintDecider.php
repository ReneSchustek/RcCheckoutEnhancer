<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Ruhrcoder\RcCheckoutEnhancer\Struct\CartMeasurements;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Entscheidet, ob der Abhol-Hinweis für diesen Warenkorb fällig ist — und mit welchem Wortlaut.
 *
 * Die Bedingung wird an zwei Stellen gebraucht: Der
 * {@see \Ruhrcoder\RcCheckoutEnhancer\Subscriber\PickupHintSubscriber} zeigt den Hinweis, der
 * {@see \Ruhrcoder\RcCheckoutEnhancer\Checkout\Cart\PickupAcknowledgementValidator} sperrt die
 * Bestellung, solange er nicht bestätigt ist. Mit zwei Kopien griffe irgendwann die eine und die
 * andere nicht, und der Dialog erschiene ohne Sperre oder die Sperre ohne Dialog.
 *
 * Der Wortlaut wird hier aufgelöst und nicht in der Vorlage, weil die Bestätigung an der
 * Bestellung festgehalten wird, und zwar der Satz, den der Kunde gelesen hat. Die Einstellung
 * ist nur eine Überschreibung des Textbausteins; bliebe die Auflösung der Vorlage überlassen,
 * stünde am Nachweis eine leere Zeichenkette, sobald der Betreiber die Vorgabe nie überschrieben
 * hat.
 */
final class PickupHintDecider
{
    public const FALLBACK_SNIPPET = 'rcCheckout.pickupHint';

    /**
     * Fehlt eines der beiden Maße, wird es nicht genannt.
     *
     * Ein Warenkorb, in dem kein Produkt eine Länge trägt, ergäbe sonst „max. Länge von
     * 0,00 m". Der Hinweis soll dem Kunden helfen zu entscheiden, ob die Ware ins Auto passt;
     * eine erfundene Null hilft dabei nicht.
     */
    private const SNIPPET_WEIGHT_ONLY = 'rcCheckout.pickupHintWeightOnly';

    private const SNIPPET_LENGTH_ONLY = 'rcCheckout.pickupHintLengthOnly';

    /**
     * Beide Schreibweisen gelten, damit der Betreiber im eigenen Text dieselben Platzhalter
     * benutzen kann wie die Vorgabe, ohne sich zu merken, welche davon die „richtige" ist.
     */
    private const WEIGHT_TOKENS = ['{Gewicht}', '{weight}'];

    private const LENGTH_TOKENS = ['{Länge}', '{length}'];

    public function __construct(
        private readonly ConfigService $configService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Der zu bestätigende Wortlaut — oder `null`, wenn kein Hinweis fällig ist.
     *
     * Fällig ist er, wenn alle drei Bedingungen zusammenkommen: eine Schwelle ist überhaupt
     * eingestellt, die gewählte Versandart ist eine, die keine Lieferung ist (also die
     * Abholung), und der Warenkorb liegt über Gewicht oder Länge.
     */
    public function hintFor(Cart $cart, SalesChannelContext $context): ?string
    {
        $salesChannelId = $context->getSalesChannelId();

        $weightThreshold = $this->configService->getPickupHintWeightThreshold($salesChannelId);
        $lengthThreshold = $this->configService->getPickupHintLengthThreshold($salesChannelId);
        if ($weightThreshold === null && $lengthThreshold === null) {
            return null;
        }

        $nonDeliveryIds = $this->configService->getNonDeliveryMethodIds($salesChannelId);
        if (!\in_array($context->getShippingMethod()->getId(), $nonDeliveryIds, true)) {
            return null;
        }

        $measurements = CartMeasurements::fromCart($cart);
        if (!$measurements->exceeds($weightThreshold, $lengthThreshold)) {
            return null;
        }

        return $this->wording($salesChannelId, $measurements);
    }

    /**
     * Die Einstellung, sonst der Textbaustein — dasselbe Muster wie beim Anfrageweg.
     *
     * Der eigene Text des Betreibers durchläuft denselben Ersetzungsschritt wie die Vorgabe;
     * sonst wäre der Platzhalter eine Falle, die nur bei der Vorgabe funktioniert.
     *
     * Weil der Wortlaut die Zahlen trägt und der {@see PickupAcknowledgementStore} die
     * Bestätigung zum Wortlaut speichert, verfällt sie, sobald sich der Warenkorb ändert.
     * Zugestimmt wurde dem Transport dieser Ware, nicht irgendeiner.
     */
    private function wording(?string $salesChannelId, CartMeasurements $measurements): string
    {
        $configured = $this->configService->getPickupHintText($salesChannelId);

        $text = $configured !== '' ? $configured : $this->translator->trans($this->snippetFor($measurements));

        return strtr($text, $this->replacements($measurements));
    }

    /**
     * Welcher Textbaustein passt zu dem, was der Warenkorb überhaupt hergibt?
     */
    private function snippetFor(CartMeasurements $measurements): string
    {
        if ($measurements->longestLength <= 0.0) {
            return self::SNIPPET_WEIGHT_ONLY;
        }

        if ($measurements->totalWeight <= 0.0) {
            return self::SNIPPET_LENGTH_ONLY;
        }

        return self::FALLBACK_SNIPPET;
    }

    /**
     * @return array<string, string>
     */
    private function replacements(CartMeasurements $measurements): array
    {
        $weight = $this->number($measurements->totalWeight, 1) . ' kg';
        $length = $this->number($measurements->longestLength / 1000, 2) . ' m';

        $map = [];
        foreach (self::WEIGHT_TOKENS as $token) {
            $map[$token] = $weight;
        }
        foreach (self::LENGTH_TOKENS as $token) {
            $map[$token] = $length;
        }

        return $map;
    }

    /**
     * Zahl in der Schreibweise der Oberfläche.
     *
     * Ohne `intl`, weil es nur um Komma oder Punkt geht. Eine Erweiterung vorauszusetzen, die
     * auf manchen Servern fehlt, riskierte einen Ausfall für einen Satz Text. Eine englische
     * Oberfläche bekommt die getauschten Trennzeichen, jede andere die deutschen.
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
