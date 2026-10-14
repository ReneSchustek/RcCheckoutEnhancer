<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Checkout\ShippingMethodAvailability;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\PickupAcknowledgementStore;
use Ruhrcoder\RcCheckoutEnhancer\Service\PickupHintDecider;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingMethodPreselector;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Warnt, wer schwere oder lange Ware selbst abholen will.
 *
 * Die Selbstabholung ist oft die einzige Versandart ohne Gewichtsgrenze. Sie bleibt übrig,
 * weil sie als Letztes durchfällt, nicht weil jemand sie für eine halbe Tonne vorgesehen hätte;
 * wer sie wählt, führe sonst mit dem Kombi vor, um sechs Meter Handlauf und 300 kg mitzunehmen.
 *
 * Maßgeblich ist die gewählte Versandart und nicht die Liste der verfügbaren. Darin
 * unterscheidet sich der Hinweis vom Anfrageweg im {@see ShippingEnquirySubscriber}, der fragt,
 * ob überhaupt eine Lieferung übrig bleibt. Hier geht es um eine Entscheidung des Kunden, und
 * wer eine Spedition gewählt hat, soll nicht behelligt werden.
 *
 * Ohne JavaScript, weil Shopware die Bestätigungsseite bei jedem Wechsel der Versandart neu
 * lädt. Die Auswahl steht damit im Kontext, bevor die Seite gerendert wird; ein Skript, das auf
 * den Klick lauert, wäre eine zweite Wahrheit daneben.
 */
final class PickupHintSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PickupHintDecider $decider,
        private readonly PickupAcknowledgementStore $store,
        private readonly ShippingMethodPreselector $preselector,
        private readonly ConfigService $configService,
    ) {
    }

    /**
     * Rang −100: Der Hinweis entscheidet nach der Vorauswahl, die auf Rang 100 läuft.
     *
     * Er liest die Versandart aus dem Kontext. Solange die Vorauswahl noch nicht gelaufen ist,
     * steht dort die Abholung, auf der Shopware zurückgeblieben ist, und der Dialog erschiene
     * zu einer Wahl, die es auf der fertigen Seite nicht mehr gibt.
     *
     * @return array<class-string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [CheckoutConfirmPageLoadedEvent::class => ['onConfirmPage', -100]];
    }

    public function onConfirmPage(CheckoutConfirmPageLoadedEvent $event): void
    {
        // Die Bedingung steht im PickupHintDecider, derselben Stelle, die auch die Bestellung
        // sperrt, solange nicht bestätigt ist.
        $hint = $this->decider->hintFor($event->getPage()->getCart(), $event->getSalesChannelContext());
        if ($hint === null) {
            return;
        }

        // `acknowledged` entscheidet in der Vorlage, ob der Dialog noch aufgeht. Ohne dieses
        // Feld erschiene er nach dem Bestätigen bei jedem weiteren Seitenaufbau erneut, denn die
        // Bedingung trifft weiterhin zu, nur die Sperre ist weg.
        // Schließt der Kunde den Dialog, ohne zu bestätigen, wird auf die Versandart gestellt,
        // die auch die Vorauswahl nähme, und nicht auf die Standard-Versandart des Kanals. Die
        // ist bei schwerer Ware oft gar nicht verfügbar, und zwei Logiken ergäben zwei
        // Ergebnisse.
        $context = $event->getSalesChannelContext();
        $revert = $this->preselector->preselect(
            ShippingMethodAvailability::availableOnly($event->getPage()->getShippingMethods(), $context),
            '',
            $this->configService->getNonDeliveryMethodIds($context->getSalesChannelId()),
            $context->getSalesChannel()->getShippingMethodId(),
            placeholderMethodId: $this->configService->getShippingPlaceholderMethodId($context->getSalesChannelId()),
        );

        // Bleibt außer der Abholung nichts übrig (`null`: keine Lieferart, auf die sich
        // zurückstellen ließe), gehört die Seite dem Anfrageweg. Mit Dialog bekäme der Kunde
        // zwei Botschaften auf einmal, „bestätige den Transport" und „für diese Ware ist keine
        // Lieferung möglich". Die Bestellung bleibt ohne Bestätigung gesperrt; für solche Ware
        // führt der Weg über die Anfrage und nicht durch die Kasse.
        if ($revert === null) {
            return;
        }

        $event->getPage()->addExtension('rcPickupHint', new ArrayStruct([
            'hint' => $hint,
            'acknowledged' => $this->store->isAcknowledged($hint),
            'revertToId' => $revert->getId(),
        ]));
    }
}
