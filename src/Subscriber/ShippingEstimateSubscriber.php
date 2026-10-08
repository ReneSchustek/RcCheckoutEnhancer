<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\EstimateFormData;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hängt die Auswahlliste für den Versandkostenrechner an die Warenkorb-Seite.
 *
 * Ein eigener Subscriber neben dem Versandkostenfrei-Indikator: Die beiden beantworten
 * dieselbe Kundenfrage, haben aber getrennte Schalter und getrennte Daten. In einem
 * Subscriber vereint müsste jeder Aufruf beide Konfigurationen lesen, auch wenn nur eines
 * von beiden aktiv ist.
 */
class ShippingEstimateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly EstimateFormData $formData,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutCartPageLoadedEvent::class => 'onCartPageLoaded',
        ];
    }

    public function onCartPageLoaded(CheckoutCartPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();

        if (!$this->configService->isShippingEstimatorEnabled($context->getSalesChannelId())) {
            return;
        }

        // Ein leerer Warenkorb hat keine Versandkosten, über die sich reden ließe.
        if ($event->getPage()->getCart()->getLineItems()->count() === 0) {
            return;
        }

        // Auch Angemeldete sehen den Rechner: Er ist die einzige Stelle im Warenkorb, an der
        // jemand erfährt, dass es für seine Sendung gar keine Versandart gibt, und das trifft
        // gerade den Stammkunden, der eine halbe Tonne bestellt. Eine zweite, abweichende Zahl
        // neben dem Checkout entsteht nicht, weil der Rechner dieselbe Kern-Berechnung benutzt.
        $event->getPage()->addExtension('rcShippingEstimate', new ArrayStruct(
            $this->formData->forContext($context)
        ));
    }
}
