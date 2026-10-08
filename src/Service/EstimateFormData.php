<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\Country\SalesChannel\AbstractCountryRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Was der Versandkostenrechner braucht, um überhaupt dazustehen: Länder und Vorbelegung.
 *
 * Den Rechner gibt es auf der Warenkorbseite und in der Leiste, die beim Hineinlegen aufgeht.
 * Stünde die Länderabfrage in beiden Zuhörern, böte irgendwann die eine Stelle ein Land an, das
 * die andere nicht kennt.
 */
final class EstimateFormData
{
    public function __construct(private readonly AbstractCountryRoute $countryRoute)
    {
    }

    /**
     * @return array{countries: CountryCollection, currentCountryId: string, currentZipCode: string}
     */
    public function forContext(SalesChannelContext $context): array
    {
        return [
            'countries' => $this->shippingCountries($context),
            'currentCountryId' => $context->getShippingLocation()->getCountry()->getId(),
            // Vorbelegung für Angemeldete: Was der Shop längst weiß, soll niemand abtippen.
            'currentZipCode' => $context->getShippingLocation()->getAddress()?->getZipcode() ?? '',
        ];
    }

    /**
     * Die Länder, in die der Kanal überhaupt liefert, also dieselbe Liste, die der Bestellvorgang
     * anbietet. Ein Land, das dort fehlt, stellte hier eine Lieferung in Aussicht, die niemand
     * bestellen kann.
     *
     * Die Grenze von 300 liegt über der Zahl aller Länder der Welt; sie schneidet nie etwas ab und
     * verhindert nur eine unbegrenzte Abfrage.
     */
    private function shippingCountries(SalesChannelContext $context): CountryCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('shippingAvailable', true));
        $criteria->addSorting(new FieldSorting('position'));
        $criteria->addSorting(new FieldSorting('name'));
        $criteria->setLimit(300);

        return $this->countryRoute->load(new Request(), $criteria, $context)->getCountries();
    }
}
