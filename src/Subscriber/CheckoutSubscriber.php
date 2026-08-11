<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreeShippingThresholdProvider;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\Currency\CurrencyFormatter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
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

    /**
     * Der Platzhalter, den der Betreiber in ein Vertrauenssignal schreiben kann, statt eine
     * Zahl zu pflegen: `truck;Kostenloser Versand ab %freeShippingThreshold%`.
     */
    private const THRESHOLD_PLACEHOLDER = '%freeShippingThreshold%';

    public function __construct(
        private readonly ConfigService $configService,
        private readonly FreeShippingThresholdProvider $freeShippingThreshold,
        private readonly CurrencyFormatter $currencyFormatter,
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
            'trustBadges' => $this->fillThreshold(
                $this->configService->getTrustBadges($salesChannelId),
                $event->getSalesChannelContext(),
            ),
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

    /**
     * Ersetzt den Platzhalter für den Versandkostenfrei-Betrag in den Vertrauenssignalen.
     *
     * Bis 1.5.0 stand die Zahl dort als Freitext, während die Verfügbarkeitsregel des
     * Shops eine andere verlangte. Wer lieber eine feste Zahl hinschreibt, kann das
     * weiterhin tun — der Platzhalter ist ein Angebot, kein Zwang.
     *
     * @param list<array{icon: string, text: string}> $badges
     *
     * @return list<array{icon: string, text: string}>
     */
    private function fillThreshold(array $badges, SalesChannelContext $context): array
    {
        $hasPlaceholder = false;
        foreach ($badges as $badge) {
            if (str_contains($badge['text'], self::THRESHOLD_PLACEHOLDER)) {
                $hasPlaceholder = true;

                break;
            }
        }

        if (!$hasPlaceholder) {
            return $badges;
        }

        $threshold = $this->freeShippingThreshold->thresholdFor($context);
        if ($threshold === null) {
            // Kein Betrag ermittelbar — dann darf die Zeile nicht stehen bleiben. Bis 1.6.1
            // ging sie unverändert an die Vorlage, und der Kunde las im Bestellvorgang
            // wörtlich „Kostenloser Versand ab %freeShippingThreshold%". Ein Vertrauenssignal,
            // das sich nicht füllen lässt, ist schlechter als keines: Es wirbt mit einer
            // Zusage und zeigt an ihrer Stelle einen Platzhalter.
            //
            // Betroffen ist der Fall, dass weder die Verfügbarkeitsregel einer eingestellten
            // Versandart noch die Einstellung einen brauchbaren Betrag hergibt.
            return array_values(array_filter(
                $badges,
                static fn (array $badge): bool => !str_contains($badge['text'], self::THRESHOLD_PLACEHOLDER),
            ));
        }

        $formatted = $this->currencyFormatter->formatCurrencyByLanguage(
            $threshold,
            $context->getCurrency()->getIsoCode(),
            $context->getLanguageId(),
            $context->getContext(),
        );

        foreach ($badges as $index => $badge) {
            $badges[$index]['text'] = str_replace(self::THRESHOLD_PLACEHOLDER, $formatted, $badge['text']);
        }

        return $badges;
    }
}
