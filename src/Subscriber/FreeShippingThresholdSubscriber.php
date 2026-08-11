<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreeShippingReachability;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreeShippingService;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreeShippingSwitchGate;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingEstimateService;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class FreeShippingThresholdSubscriber implements EventSubscriberInterface
{
    private const DEFAULT_THRESHOLD = 50.00;

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

        // Zuerst die billigen, gecachten Config-Checks — nur wenn das Feature überhaupt aktiv ist,
        // lohnt der teurere A/B-Gate-Aufruf weiter unten.
        if (!$this->configService->isFreeShippingIndicatorEnabled($salesChannelId)) {
            return;
        }

        $threshold = $this->configService->getFreeShippingThreshold($salesChannelId) ?? self::DEFAULT_THRESHOLD;
        if ($threshold <= 0.0) {
            return;
        }

        $cart = $event->getPage()->getCart();
        if ($cart->getLineItems()->count() === 0) {
            return;
        }

        // Optionaler A/B-Test zuletzt (teuerster Check): ist der Besucher der Variante mit
        // „Hinweis aus" zugeordnet, wird der Indikator nicht angehängt (siehe RCHK02).
        if ($this->switchGate?->isIndicatorSuppressed() === true) {
            return;
        }

        // Gilt Versandkostenfreiheit für diesen Lieferort überhaupt? Bis 1.3.0 wurde
        // das nie gefragt: Der Hinweis war ein reiner Rechenausdruck und stand auch dem
        // Gast in Österreich vor der Nase, für den er nie eintritt. Seit der
        // Versandkostenrechner direkt darunter steht und für dasselbe Land eine Zahl
        // größer null nennt, widersprachen sich zwei Aussagen an derselben Stelle.
        $reach = $this->reachability->reachableFrom($this->configService->getFreeShippingMethodIds($salesChannelId), $context);
        if (!$reach->applies) {
            return;
        }

        // Und lässt sich dieser Warenkorb überhaupt ausliefern? Die Prüfung darüber sieht nur
        // die Verfügbarkeits-Regeln an; die Gewichtsgrenze steht aber in den Preisbändern, und
        // oberhalb des obersten Bands ist eine Versandart weiterhin verfügbar und scheitert erst
        // am fehlenden Preis. Ohne diese zweite Frage versprach der Hinweis kostenlosen Versand
        // für Warenkörbe, die der Shop gar nicht ausliefert — am 2026-08-10 mit 530 kg gemessen.
        if (!$this->estimateService->canShipToContextLocation($cart, $context)) {
            return;
        }

        // Der Betrag aus der Regel schlägt die Einstellung. Bis 1.4.0 stand er an drei
        // Stellen — Regel, Einstellung, Freitext der Vertrauensleiste — und am 2026-08-04
        // waren alle drei verschieden. Drei Stellen für dieselbe Zahl laufen wieder
        // auseinander; es ist keine Frage, ob, sondern wann.
        $threshold = $reach->threshold ?? $threshold;

        $status = $this->freeShippingService->calculate($cart, $context, $threshold);

        $event->getPage()->addExtension('rcFreeShipping', $status);

        // Steht das Lieferland noch nicht fest, bleibt der Hinweis sichtbar, sagt aber
        // dazu, wofür er gilt (Entscheidung Rene, 2026-08-04). Ein stiller Hinweis wäre
        // eine Zusage ohne Bedingung, ein weggelassener kostete genau die Werbewirkung,
        // für die es ihn gibt.
        $event->getPage()->addExtension('rcFreeShippingReach', new ArrayStruct([
            // Die Bedingung wird nur Gästen genannt. Wer angemeldet ist, hat eine
            // Adresse — für den ist die Frage beantwortet, und ein Zusatz wäre Lärm.
            'qualify' => $context->getCustomer() === null && $reach->countryIds !== [],
            'countryNames' => $reach->countryNames,
        ]));
    }
}
