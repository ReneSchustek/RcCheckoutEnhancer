<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Register\CheckoutRegisterPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class CheckoutSubscriber implements EventSubscriberInterface
{
    /**
     * Die Twig-Erweiterung von RcAbTesting — als **Zeichenkette**, nicht als Klassenverweis.
     *
     * Zwei Gründe: Ein PHP-Typ aus einem anderen Plugin ist für die statische Analyse nicht
     * auffindbar (jedes Plugin wird im Gate für sich geprüft, mit eigenem `vendor`), und er
     * würde Fremd-Plugins ausschließen, die ihn gar nicht kennen können. `class_exists()` nimmt
     * eine Zeichenkette; für die Analyse ist das unsichtbar.
     *
     * Geprüft wird das, weil die Vorlage sonst `ab_variant()` aufriefe — eine Funktion, die es
     * ohne RcAbTesting nicht gibt. Twig bricht bei einer unbekannten Funktion schon beim
     * Übersetzen ab, und dann steht der ganze Checkout.
     */
    private const AB_TWIG_EXTENSION = 'Ruhrcoder\\RcAbTesting\\Twig\\Extension\\RcAbTwigExtension';

    public function __construct(
        private readonly ConfigService $configService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutCartPageLoadedEvent::class => 'onCheckoutPage',
            CheckoutRegisterPageLoadedEvent::class => 'onCheckoutPage',
            CheckoutConfirmPageLoadedEvent::class => 'onCheckoutPage',
            CheckoutFinishPageLoadedEvent::class => 'onCheckoutPage',
        ];
    }

    public function onCheckoutPage(CheckoutCartPageLoadedEvent|CheckoutRegisterPageLoadedEvent|CheckoutConfirmPageLoadedEvent|CheckoutFinishPageLoadedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannel()->getId();

        $experimentKey = $this->configService->getAbExperimentKey($salesChannelId);

        $step = match (true) {
            $event instanceof CheckoutCartPageLoadedEvent => 1,
            $event instanceof CheckoutRegisterPageLoadedEvent => 2,
            $event instanceof CheckoutConfirmPageLoadedEvent => 3,
            $event instanceof CheckoutFinishPageLoadedEvent => 4,
        };

        $labels = $this->configService->getProgressStepLabels($salesChannelId);

        $event->getPage()->addExtension('rcCheckoutEnhancer', new ArrayEntity([
            'currentStep' => $step,
            'totalSteps' => 4,
            'stepLabels' => $labels,
            'progressBarEnabled' => $this->configService->isProgressBarEnabled($salesChannelId),
            'trustBadgesEnabled' => $this->configService->isTrustBadgesEnabled($salesChannelId),
            'trustBadges' => $this->configService->getTrustBadges($salesChannelId),
            'miniCartEnabled' => $this->configService->isMiniCartEnabled($salesChannelId),
            'deliveryTimeEnabled' => $this->configService->isDeliveryTimeEnabled($salesChannelId),
            'estimatedDeliveryTime' => $this->configService->getEstimatedDeliveryTime($salesChannelId),
            // Für den A/B-Test: Die Vorlage entscheidet, ob sie sich zurückhält — hier stehen
            // nur die Angaben dafür. `abActive` ist die Erlaubnis, `ab_variant()` überhaupt
            // aufzurufen.
            'abExperimentKey' => $experimentKey,
            'abSuppressVariant' => $this->configService->getAbSuppressVariant($salesChannelId),
            'abActive' => $experimentKey !== '' && class_exists(self::AB_TWIG_EXTENSION),
        ]));
    }
}
