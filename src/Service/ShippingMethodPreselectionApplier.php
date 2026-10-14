<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Ruhrcoder\RcCheckoutEnhancer\Checkout\ShippingMethodAvailability;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Payment\Cart\Error\PaymentMethodBlockedError;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Schaltet auf die vorauszuwählende Versandart um, wenn es eine gibt, und rechnet den Warenkorb
 * neu.
 *
 * Welche das ist, entscheidet der {@see ShippingMethodPreselector}; hier geschieht das Umschalten.
 * Warenkorbseite, Leiste, Bestätigungsseite und der einseitige Checkout rufen dieselbe Stelle,
 * damit überall dieselbe Versandart angehakt ist. Ob der Kunde eine Abholung selbst angeklickt
 * hat, weiß der {@see ShippingChoiceStore}; nur dann bleibt sie stehen.
 *
 * Es wird nicht umgeleitet, nur der Kontext umgeschaltet. Eine Weiterleitung aus dem Seitenaufbau
 * heraus endet in der Fortschrittsanzeige in einer Schleife.
 */
final class ShippingMethodPreselectionApplier
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly ShippingMethodPreselector $preselector,
        private readonly AbstractContextSwitchRoute $contextSwitchRoute,
        private readonly CartService $cartService,
        private readonly ShippingChoiceStore $choiceStore,
        private readonly AbstractPaymentMethodRoute $paymentMethodRoute,
    ) {
    }

    /**
     * Der neu gerechnete Warenkorb, oder `null`, wenn nichts umzuschalten war.
     *
     * `$taxed` wie beim Seitenlader, dessen Warenkorb ersetzt wird: Die Bestätigungsseite lässt
     * externe Steueranbieter rechnen, Warenkorb und Leiste nicht.
     */
    public function apply(ShippingMethodCollection $listed, SalesChannelContext $context, bool $taxed = false): ?Cart
    {
        $salesChannelId = $context->getSalesChannelId();

        if (!$this->configService->isShippingPreselectEnabled($salesChannelId)) {
            return null;
        }

        $previous = $context->getShippingMethod();

        $selected = $this->preselector->preselect(
            ShippingMethodAvailability::availableOnly($listed, $context),
            $previous->getId(),
            $this->configService->getNonDeliveryMethodIds($salesChannelId),
            $context->getSalesChannel()->getShippingMethodId(),
            $this->choiceStore->wasChosen($previous->getId()),
            $this->configService->getShippingPlaceholderMethodId($salesChannelId),
        );

        if ($selected === null) {
            return null;
        }

        $this->contextSwitchRoute->switchContext(
            new RequestDataBag([SalesChannelContextService::SHIPPING_METHOD_ID => $selected->getId()]),
            $context,
        );

        // Der Kontext dieses Aufrufs trägt die alte Versandart noch. Die Vorlage hakt die Auswahl
        // über `context.shippingMethod.id` an; ohne diese Zeile bliebe die alte angehakt, obwohl
        // der Kontext für den nächsten Aufruf schon umgeschaltet ist.
        $context->assign(['shippingMethod' => $selected]);

        // Ohne Zwischenspeicher rechnen: Der gespeicherte Warenkorb trägt die Versandkosten der
        // alten Versandart, und neben der neuen stünde ein Betrag, der nicht zu ihr gehört.
        $cart = $this->cartService->getCart($context->getToken(), $context, false, $taxed);

        return $this->switchBlockedPaymentMethod($cart, $context, $taxed);
    }

    /**
     * Zieht die Zahlart nach, wenn sie mit der neuen Versandart gesperrt ist.
     *
     * Hängt eine Zahlart an der Versandart, etwa Barzahlung nur bei Abholung, steht nach dem
     * Umschalten eine gesperrte Zahlart im Kontext; der Kunde sähe eine Sperre ohne passende
     * Auswahl. Der Kern löst das beim eigenen Umschalten genauso, mit einer internen Klasse, die
     * Plugins nicht nutzen dürfen: Standard-Zahlart des Kanals, sonst die erste verfügbare.
     */
    private function switchBlockedPaymentMethod(Cart $cart, SalesChannelContext $context, bool $taxed): Cart
    {
        if ($cart->getErrors()->filterInstance(PaymentMethodBlockedError::class)->count() === 0) {
            return $cart;
        }

        $available = $this->paymentMethodRoute
            ->load(new Request(['onlyAvailable' => true]), $context, new Criteria())
            ->getPaymentMethods();

        $replacement = $available->get($context->getSalesChannel()->getPaymentMethodId()) ?? $available->first();
        if ($replacement === null || $replacement->getId() === $context->getPaymentMethod()->getId()) {
            return $cart;
        }

        $this->contextSwitchRoute->switchContext(
            new RequestDataBag([SalesChannelContextService::PAYMENT_METHOD_ID => $replacement->getId()]),
            $context,
        );
        $context->assign(['paymentMethod' => $replacement]);

        return $this->cartService->getCart($context->getToken(), $context, false, $taxed);
    }
}
