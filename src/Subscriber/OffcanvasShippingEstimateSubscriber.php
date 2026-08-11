<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Service\CartFingerprint;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\LastShippingEstimateStore;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hängt die zuletzt abgefragte Versandkosten-Auskunft an die Warenkorb-Seitenleiste.
 *
 * Bewusst **kein** zweiter Rechner an dieser Stelle. Drei Gründe, jeder für sich
 * ausreichend: Der Rechner ist kein Einzeiler und schöbe den „Zur Kasse"-Knopf nach
 * unten, also genau den Weg, den die Leiste abkürzen soll. Er läuft nur für Gäste,
 * damit hätte ein Teil der Besucher ein Feld und der andere nicht. Und zwei Formulare
 * mit demselben Ziel auf einer Seite driften mit der Zeit auseinander.
 *
 * Stattdessen: die Auskunft, wenn es eine gibt — und sonst der Weg dorthin.
 */
class OffcanvasShippingEstimateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly LastShippingEstimateStore $lastEstimateStore,
        private readonly CartFingerprint $cartFingerprint,
    ) {
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            OffcanvasCartPageLoadedEvent::class => 'onOffcanvasLoaded',
        ];
    }

    public function onOffcanvasLoaded(OffcanvasCartPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();

        if (!$this->configService->isShippingEstimatorEnabled($context->getSalesChannelId())) {
            return;
        }

        $cart = $event->getPage()->getCart();

        // Ein leerer Warenkorb hat keine Versandkosten, über die sich reden ließe.
        if ($cart->getLineItems()->count() === 0) {
            return;
        }

        $lastEstimate = $this->lastEstimateStore->get();

        // Ohne vorherige Berechnung nur der Verweis auf die Warenkorb-Seite, kein
        // leerer Kasten: Wer nie gerechnet hat, soll erfahren, dass er es kann.
        if ($lastEstimate === null) {
            $event->getPage()->addExtension('rcOffcanvasShipping', new ArrayStruct([
                'state' => 'none',
            ]));

            return;
        }

        // Der Fingerabdruck entscheidet, ob der gespeicherte Preis noch gilt. Stimmt
        // er nicht, wird hier **nicht** neu gerechnet — eine Berechnung kostet so viele
        // Warenkorb-Durchläufe, wie es Versandarten gibt, und die Leiste geht oft auf.
        // Gesagt wird stattdessen, dass neu zu rechnen ist.
        $stillValid = $lastEstimate->cartFingerprint === $this->cartFingerprint->of($cart);

        $event->getPage()->addExtension('rcOffcanvasShipping', new ArrayStruct([
            'state' => $stillValid ? 'valid' : 'stale',
            'estimate' => $lastEstimate,
        ]));
    }
}
