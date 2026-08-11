<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Subscriber;

use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\Country\SalesChannel\AbstractCountryRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Hängt die Auswahlliste für den Versandkostenrechner an die Warenkorb-Seite.
 *
 * Bewusst ein eigener Subscriber neben dem Versandkostenfrei-Indikator: Die beiden
 * beantworten dieselbe Kundenfrage, haben aber getrennte Schalter und getrennte
 * Daten. In einem Subscriber vereint müsste jeder Aufruf beide Konfigurationen
 * lesen, auch wenn nur eines von beiden aktiv ist.
 */
class ShippingEstimateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly AbstractCountryRoute $countryRoute,
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

        // Nur für Gäste (Entscheidung zu RCHK05). Für angemeldete Kunden gilt die
        // Adresse des Kontos; eine zweite Zahl daneben wäre irreführend.
        if ($context->getCustomer() !== null) {
            return;
        }

        // Ein leerer Warenkorb hat keine Versandkosten, über die sich reden ließe.
        if ($event->getPage()->getCart()->getLineItems()->count() === 0) {
            return;
        }

        $event->getPage()->addExtension('rcShippingEstimate', new ArrayStruct([
            'countries' => $this->shippingCountries($context),
            'currentCountryId' => $context->getShippingLocation()->getCountry()->getId(),
        ]));
    }

    /**
     * Die Länder, in die der Kanal überhaupt liefert — dieselbe Liste, die auch
     * der Checkout anbietet. Ein Land, das dort fehlt, gehört hier auch nicht hin:
     * Es würde eine Lieferung in Aussicht stellen, die niemand bestellen kann.
     */
    private function shippingCountries(SalesChannelContext $context): \Shopware\Core\System\Country\CountryCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('shippingAvailable', true));
        $criteria->addSorting(new FieldSorting('position'));
        $criteria->addSorting(new FieldSorting('name'));
        $criteria->setLimit(300);

        return $this->countryRoute->load(new Request(), $criteria, $context)->getCountries();
    }
}
