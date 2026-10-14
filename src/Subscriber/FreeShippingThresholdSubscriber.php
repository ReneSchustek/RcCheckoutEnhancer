<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreeShippingReachability;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreeShippingService;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreeShippingSwitchGate;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingEstimateService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hängt den Versandkostenfrei-Hinweis an Warenkorbseite und Leiste, aber nur, wenn die Zusage
 * für diesen Lieferort und diesen Warenkorb auch stimmt.
 *
 * Die Prüfungen laufen von billig nach teuer: Einstellungen, leerer Warenkorb, A/B-Test,
 * Reichweite der Regel, Lieferbarkeit. Jede kann den Hinweis verschweigen; eine falsche Zusage
 * neben dem Versandkostenrechner wiegt schwerer als ein fehlender Werbesatz.
 */
class FreeShippingThresholdSubscriber implements EventSubscriberInterface
{
    /**
     * Unterhalb eines halben Cents gilt ein Betrag als null.
     *
     * Ein Vergleich `> 0.0` auf einem Fließkommawert wäre eine Wette darauf, dass eine
     * Summe aus Rundungen exakt null trifft. Trifft sie es um ein Zehntausendstel nicht,
     * verschwände der Hinweis in Shops, in denen er völlig richtig wäre.
     */
    private const CENT_TOLERANCE = 0.005;

    public function __construct(
        private readonly ConfigService $configService,
        private readonly FreeShippingService $freeShippingService,
        private readonly FreeShippingReachability $reachability,
        private readonly ShippingEstimateService $estimateService,
        private readonly ?FreeShippingSwitchGate $switchGate = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutCartPageLoadedEvent::class => 'onCartPageLoaded',
            OffcanvasCartPageLoadedEvent::class => 'onCartPageLoaded',
        ];
    }

    public function onCartPageLoaded(CheckoutCartPageLoadedEvent|OffcanvasCartPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        $salesChannelId = $context->getSalesChannel()->getId();
        $cart = $event->getPage()->getCart();

        if (!$this->isWanted($cart, $salesChannelId)) {
            return;
        }

        $configuredThreshold = $this->configService->getFreeShippingThreshold($salesChannelId);

        // Gilt Versandkostenfreiheit für diesen Lieferort nicht, schweigt der Hinweis. Sonst
        // stünde er auch vor dem Gast in Österreich, und der Versandkostenrechner darunter
        // nennte für dasselbe Land eine Zahl größer null.
        $reach = $this->reachability->reachableFrom($this->configService->getFreeShippingMethodIds($salesChannelId), $context);
        if (!$reach->applies) {
            return;
        }

        // Und lässt sich dieser Warenkorb überhaupt ausliefern? Die Prüfung darüber sieht nur
        // die Verfügbarkeits-Regeln an; die Gewichtsgrenze steht aber in den Preisbändern, und
        // oberhalb des obersten Bands ist eine Versandart weiterhin verfügbar und scheitert erst
        // am fehlenden Preis. Ohne diese zweite Frage verspräche der Hinweis kostenlosen Versand
        // für Warenkörbe über dem obersten Gewichtsband, die der Shop gar nicht ausliefert.
        if (!$this->estimateService->canShipToContextLocation($cart, $context)) {
            return;
        }

        // Der Betrag aus der Regel schlägt die Einstellung; zwei Stellen für dieselbe Zahl
        // laufen früher oder später auseinander.
        // Ohne Betrag schweigt der Hinweis, wie die Vertrauensleiste. Ein angenommener Betrag
        // stünde neben einer Leiste, die keine Schwelle nennt.
        $threshold = $reach->threshold ?? $configuredThreshold;
        if ($threshold === null || $threshold <= 0.0) {
            return;
        }

        $status = $this->freeShippingService->calculate($cart, $context, $threshold);

        // Erreichte Schwelle und Versandkosten über null passen nicht zusammen. Der Hinweis
        // rechnet nur Warenwert gegen Schwelle; ist die versandkostenfreie Versandart etwa für
        // dieses Gewicht gesperrt, liefert ein Paketdienst zum Normaltarif, und die
        // Zusammenfassung daneben nennt einen Betrag. Dann wird geschwiegen, auch kein
        // „noch X € fehlen", denn die Schwelle ist ja überschritten. Wer selbst einen
        // kostenpflichtigen Versand gewählt hat, zahlt Versand und bekommt ebenfalls keine Zusage.
        if ($status->achieved && $cart->getShippingCosts()->getTotalPrice() > self::CENT_TOLERANCE) {
            return;
        }

        $event->getPage()->addExtension('rcFreeShipping', $status);

        // Steht das Lieferland noch nicht fest, bleibt der Hinweis sichtbar, sagt aber
        // dazu, wofür er gilt. Ohne den Zusatz wäre er eine Zusage ohne Bedingung, ein
        // weggelassener kostete die Werbewirkung, für die es ihn gibt.
        $event->getPage()->addExtension('rcFreeShippingReach', new ArrayStruct([
            // Die Bedingung wird nur Gästen genannt. Wer angemeldet ist, hat eine
            // Adresse; für ihn ist die Frage beantwortet, und ein Zusatz wäre Lärm.
            'qualify' => $context->getCustomer() === null && $reach->countryIds !== [],
            'countryNames' => $reach->countryNames,
        ]));
    }

    /**
     * Die billigen Vorprüfungen, bevor Lieferort und Versandkosten gerechnet werden.
     */
    private function isWanted(Cart $cart, string $salesChannelId): bool
    {
        // Zuerst die zwischengespeicherten Einstellungen; nur wenn der Hinweis überhaupt an ist,
        // lohnen die teureren Prüfungen.
        if (!$this->configService->isFreeShippingIndicatorEnabled($salesChannelId)) {
            return false;
        }

        // Eine eingestellte Null schaltet den Hinweis ab. Fehlt der Wert, entscheidet später die
        // Regel; ohne sie gibt es keine Schwelle.
        $configuredThreshold = $this->configService->getFreeShippingThreshold($salesChannelId);
        if ($configuredThreshold !== null && $configuredThreshold <= 0.0) {
            return false;
        }

        if ($cart->getLineItems()->count() === 0) {
            return false;
        }

        // Ist der Besucher im optionalen A/B-Test der Variante „Hinweis aus" zugeordnet, bleibt der
        // Indikator weg.
        return $this->switchGate?->isIndicatorSuppressed() !== true;
    }
}
