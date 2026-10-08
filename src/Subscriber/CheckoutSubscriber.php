<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Checkout\CheckoutLayout;
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

/**
 * Hängt an jede Seite des Bestellvorgangs, was Fortschrittsanzeige, Vertrauenssignale,
 * Mini-Warenkorb und Lieferzeit brauchen.
 *
 * Ob sich das Plugin für die Vergleichsgruppe eines A/B-Tests zurückhält, entscheidet die
 * Vorlage über `ab_variant()`; hier stehen nur die Angaben und die Erlaubnis, die Funktion
 * aufzurufen. So braucht der Subscriber keine Abhängigkeit zu RcAbTesting.
 */
final class CheckoutSubscriber implements EventSubscriberInterface
{
    /**
     * Die Twig-Erweiterung von RcAbTesting, als Zeichenkette und nicht als Klassenverweis.
     *
     * Ein PHP-Typ aus einem anderen Plugin ist für die statische Analyse nicht auffindbar,
     * weil jedes Plugin im Gate für sich mit eigenem `vendor` geprüft wird. `class_exists()`
     * nimmt eine Zeichenkette, und die bleibt für die Analyse unsichtbar.
     *
     * Geprüft wird, weil die Vorlage sonst `ab_variant()` aufriefe, eine Funktion, die es ohne
     * RcAbTesting nicht gibt. Twig bricht bei einer unbekannten Funktion schon beim Übersetzen
     * ab, und dann steht der ganze Checkout.
     */
    private const AB_TWIG_EXTENSION = CheckoutLayout::AB_TWIG_EXTENSION;

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

        $event->getPage()->addExtension('rcCheckoutEnhancer', new ArrayEntity([
            'currentStep' => match (true) {
                $event instanceof CheckoutCartPageLoadedEvent => 1,
                $event instanceof CheckoutRegisterPageLoadedEvent => 2,
                $event instanceof CheckoutConfirmPageLoadedEvent => 3,
                $event instanceof CheckoutFinishPageLoadedEvent => 4,
            },
            // Warenkorb, Anmeldung oder Adresse, Bestätigung, Abschluss.
            'totalSteps' => 4,
            'stepLabels' => $this->configService->getProgressStepLabels($salesChannelId),
            'progressBarEnabled' => $this->configService->isProgressBarEnabled($salesChannelId),
            'trustBadgesEnabled' => $this->configService->isTrustBadgesEnabled($salesChannelId),
            'trustBadges' => $this->fillThreshold(
                $this->configService->getTrustBadges($salesChannelId),
                $event->getSalesChannelContext(),
            ),
            'miniCartEnabled' => $this->configService->isMiniCartEnabled($salesChannelId),
            'deliveryTimeEnabled' => $this->configService->isDeliveryTimeEnabled($salesChannelId),
            'estimatedDeliveryTime' => $this->configService->getEstimatedDeliveryTime($salesChannelId),
            ...$this->abTestSettings($salesChannelId),
        ]));
    }

    /**
     * Die Angaben für den A/B-Test. Die Vorlage entscheidet, ob sie sich zurückhält; hier stehen
     * nur die Angaben dafür.
     *
     * @return array<string, mixed>
     */
    private function abTestSettings(string $salesChannelId): array
    {
        $experimentKey = $this->configService->getAbExperimentKey($salesChannelId);
        $checkoutLayout = $this->configService->getCheckoutLayout($salesChannelId);

        return [
            'abExperimentKey' => $experimentKey,
            'abSuppressVariant' => $this->configService->getAbSuppressVariant($salesChannelId),
            // Die Erlaubnis, `ab_variant()` überhaupt aufzurufen.
            'abActive' => $experimentKey !== '' && class_exists(self::AB_TWIG_EXTENSION),
            'checkoutLayout' => $checkoutLayout,
            // Wie bei `abActive`: die Erlaubnis, `ab_switch()` aufzurufen. Nur beim A/B-Test und nur,
            // wenn es die Funktion gibt; sonst gilt in der Vorlage die geführte Darstellung.
            'abSwitchAvailable' => $checkoutLayout === CheckoutLayout::AB_TEST && class_exists(self::AB_TWIG_EXTENSION),
        ];
    }

    /**
     * Ersetzt den Platzhalter für den Versandkostenfrei-Betrag in den Vertrauenssignalen.
     *
     * Der Platzhalter nimmt den Betrag aus der Regel, damit keine zweite Zahl im Text
     * auseinanderläuft. Eine feste Zahl im Text bleibt möglich.
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
            // Weder Regel noch Einstellung geben einen Betrag her. Ohne die Zeile zu entfernen,
            // läse der Kunde wörtlich „Kostenloser Versand ab %freeShippingThreshold%".
            return array_values(array_filter(
                $badges,
                static fn (array $badge): bool => !str_contains($badge['text'], self::THRESHOLD_PLACEHOLDER),
            ));
        }

        // Die Schwelle steht in der Standardwährung. Umgerechnet wie im Versandkostenfrei-Hinweis,
        // sonst nennten Leiste und Hinweis in Franken zwei verschiedene Beträge.
        $formatted = $this->currencyFormatter->formatCurrencyByLanguage(
            round($threshold * $context->getCurrency()->getFactor(), 2),
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
