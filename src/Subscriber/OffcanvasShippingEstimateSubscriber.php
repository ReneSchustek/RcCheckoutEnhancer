<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Service\CartFingerprint;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\EstimateFormData;
use Ruhrcoder\RcCheckoutEnhancer\Service\LastShippingEstimateStore;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Offcanvas\OffcanvasCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hängt die zuletzt abgefragte Versandkosten-Auskunft und den Rechner an die
 * Warenkorb-Seitenleiste.
 *
 * Der Rechner ist derselbe Baustein wie auf der Warenkorbseite, mit derselben Berechnung.
 * Neu gerechnet wird nur auf Klick; beim Öffnen der Leiste zeigt sie die gespeicherte
 * Auskunft, solange sie zum Warenkorb passt.
 */
class OffcanvasShippingEstimateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly LastShippingEstimateStore $lastEstimateStore,
        private readonly CartFingerprint $cartFingerprint,
        private readonly EstimateFormData $formData,
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

        // Wer etwas hineinlegt, fragt in diesem Moment nach den Versandkosten; deshalb steht der
        // Rechner auch in der Leiste.
        $event->getPage()->addExtension('rcShippingEstimate', new ArrayStruct(
            $this->formData->forContext($context)
        ));

        $lastEstimate = $this->lastEstimateStore->get();

        // Ohne frühere Auskunft steht nur der Rechner da, kein leerer Kasten.
        if ($lastEstimate === null) {
            $event->getPage()->addExtension('rcOffcanvasShipping', new ArrayStruct([
                'state' => 'none',
            ]));

            return;
        }

        // Der Fingerabdruck entscheidet, ob der gespeicherte Preis noch gilt. Stimmt
        // er nicht, wird hier nicht neu gerechnet, denn eine Berechnung kostet je verfügbarer
        // Versandart einen Warenkorb-Durchlauf, und die Leiste geht oft auf. Gesagt wird
        // stattdessen, dass neu zu rechnen ist.
        $stillValid = $lastEstimate->cartFingerprint === $this->cartFingerprint->of($cart);

        $event->getPage()->addExtension('rcOffcanvasShipping', new ArrayStruct([
            'state' => $stillValid ? 'valid' : 'stale',
            'estimate' => $lastEstimate,
        ]));
    }
}
