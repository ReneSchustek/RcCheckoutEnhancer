<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Ruhrcoder\RcCheckoutEnhancer\Checkout\Amount;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Entscheidet, ob der Versandkostenfrei-Hinweis für diesen Warenkorb und diesen Lieferort erscheint, und mit welcher
 * Bedingung.
 *
 * Die Prüfungen laufen von billig nach teuer: Einstellungen, leerer Warenkorb, A/B-Test, Reichweite der Regel,
 * Lieferbarkeit. Jede kann den Hinweis verschweigen; eine falsche Zusage neben dem Versandkostenrechner wiegt schwerer
 * als ein fehlender Werbesatz.
 */
final class FreeShippingHint
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly FreeShippingService $freeShippingService,
        private readonly FreeShippingReachability $reachability,
        private readonly ShippingEstimateService $estimateService,
        private readonly ?FreeShippingSwitchGate $switchGate = null,
    ) {
    }

    /**
     * Stand und Bedingung des Hinweises; `null`, wenn er schweigt. `qualify` heißt: Der Hinweis nennt, für welche
     * Länder er gilt.
     *
     * @return array{status: FreeShippingStatus, qualify: bool, countryNames: list<string>}|null
     */
    public function for(Cart $cart, SalesChannelContext $context): ?array
    {
        $salesChannelId = $context->getSalesChannel()->getId();
        if (!$this->isWanted($cart, $salesChannelId)) {
            return null;
        }

        // Gilt Versandkostenfreiheit für diesen Lieferort nicht, schweigt der Hinweis. Sonst stünde er auch vor dem Gast
        // in Österreich, und der Versandkostenrechner darunter nennte für dasselbe Land eine Zahl größer null.
        $reach = $this->reachability->reachableFrom($this->configService->getFreeShippingMethodIds($salesChannelId), $context);
        if (!$reach->applies) {
            return null;
        }

        // Und lässt sich dieser Warenkorb überhaupt ausliefern? Die Reichweite sieht nur die Verfügbarkeits-Regeln an;
        // die Gewichtsgrenze steht aber in den Preisbändern, und oberhalb des obersten Bands ist eine Versandart weiter
        // verfügbar und scheitert erst am fehlenden Preis.
        if (!$this->estimateService->canShipToContextLocation($cart, $context)) {
            return null;
        }

        // Der Betrag aus der Regel schlägt die Einstellung; zwei Stellen für dieselbe Zahl laufen früher oder später
        // auseinander. Ohne Betrag schweigt der Hinweis, wie die Vertrauensleiste.
        $threshold = $reach->threshold ?? $this->configService->getFreeShippingThreshold($salesChannelId);
        if ($threshold === null || $threshold <= 0.0) {
            return null;
        }

        $status = $this->freeShippingService->calculate($cart, $context, $threshold);

        // Erreichte Schwelle und Versandkosten über null passen nicht zusammen: Ist die versandkostenfreie Versandart
        // etwa für dieses Gewicht gesperrt, liefert ein Paketdienst zum Normaltarif, und die Zusammenfassung daneben
        // nennt einen Betrag. Dann wird geschwiegen, auch kein „noch X € fehlen", denn die Schwelle ist überschritten.
        if ($status->achieved && Amount::isPositive($cart->getShippingCosts()->getTotalPrice())) {
            return null;
        }

        return [
            'status' => $status,
            // Die Bedingung wird nur Gästen genannt. Wer angemeldet ist, hat eine Adresse; für ihn ist die Frage
            // beantwortet, und ein Zusatz wäre Lärm.
            'qualify' => $context->getCustomer() === null && $reach->countryIds !== [],
            'countryNames' => $reach->countryNames,
        ];
    }

    /**
     * Die billigen Vorprüfungen, bevor Lieferort und Versandkosten gerechnet werden.
     */
    private function isWanted(Cart $cart, string $salesChannelId): bool
    {
        if (!$this->configService->isFreeShippingIndicatorEnabled($salesChannelId)) {
            return false;
        }

        // Eine eingestellte Null schaltet den Hinweis ab. Fehlt der Wert, entscheidet später die Regel.
        $configuredThreshold = $this->configService->getFreeShippingThreshold($salesChannelId);
        if ($configuredThreshold !== null && $configuredThreshold <= 0.0) {
            return false;
        }

        if ($cart->getLineItems()->count() === 0) {
            return false;
        }

        // Ist der Besucher im optionalen A/B-Test der Variante „Hinweis aus" zugeordnet, bleibt der Indikator weg.
        return $this->switchGate?->isIndicatorSuppressed() !== true;
    }
}
