<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcCheckoutEnhancer\Service\EstimateContextFactory;
use Ruhrcoder\RcCheckoutEnhancer\Service\ShippingEstimateService;
use Ruhrcoder\RcCheckoutEnhancer\Struct\ShippingEstimateResult;
use Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\SalesChannelContextBuilder;
use RuntimeException;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartRuleLoader;
use Shopware\Core\Checkout\Cart\Delivery\Struct\Delivery;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryDate;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryPositionCollection;
use Shopware\Core\Checkout\Cart\Error\GenericCartError;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\RuleLoaderResult;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Shipping\SalesChannel\AbstractShippingMethodRoute;
use Shopware\Core\Checkout\Shipping\SalesChannel\ShippingMethodRouteResponse;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\DeliveryTime\DeliveryTimeEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Stringable;
use Symfony\Component\HttpFoundation\Request;

final class ShippingEstimateServiceTest extends TestCase
{
    public function testEmptyCartYieldsNoShippingMethod(): void
    {
        $service = $this->service([$this->shippingMethod('Standard')], $this->findCountry('DE'));

        $result = $service->estimate(new Cart('token'), SalesChannelContextBuilder::build(), 'DE', '44787');

        self::assertSame(ShippingEstimateResult::STATE_NO_SHIPPING, $result->state);
        self::assertSame([], $result->estimates);
    }

    /**
     * Was: Ein Warenkorb, der einen Hinweis trägt — ein ausverkaufter Artikel, eine
     *      abgelaufene Aktion, eine Pflichtangabe aus einem anderen Plugin.
     * Warum: **Der Befund vom 2026-08-04.** Die Berechnung kopierte den Warenkorb mit
     *        einem schlichten `clone`. `Struct` klont tief, und ein Warenkorb-Hinweis ist
     *        eine Exception — die verbietet PHP zu klonen. Der Rechner scheiterte damit
     *        bei jedem Warenkorb mit Hinweis stillschweigend mit „Berechnung nicht
     *        möglich", und Hinweise sind der Normalfall, nicht die Ausnahme.
     * Erwartet: Die Auskunft kommt trotzdem — und der Hinweis hängt hinterher
     *           unverändert am Original.
     */
    public function testACartCarryingAnErrorCanStillBeEstimated(): void
    {
        $service = $this->service([$this->shippingMethod('Standard')], $this->findCountry('DE'), shippingCosts: 4.9);

        $cart = $this->cart();
        $cart->addErrors(new GenericCartError(
            'irgendein-hinweis',
            'Ein Hinweis am Warenkorb',
            [],
            GenericCartError::LEVEL_NOTICE,
            false,
            false,
            false,
        ));

        $result = $service->estimate($cart, SalesChannelContextBuilder::build(), 'DE', '44787');

        self::assertSame(ShippingEstimateResult::STATE_OK, $result->state);
        self::assertCount(1, $result->estimates);
        self::assertCount(1, $cart->getErrors(), 'Der Hinweis muss nach der Abfrage am Original hängen.');
    }

    public function testUnknownCountryYieldsNoShippingMethod(): void
    {
        $service = $this->service([$this->shippingMethod('Standard')], null);

        $result = $service->estimate($this->cart(), SalesChannelContextBuilder::build(), 'XX', '1010');

        self::assertSame(ShippingEstimateResult::STATE_NO_SHIPPING, $result->state);
    }

    public function testEveryAvailableShippingMethodComesWithAPrice(): void
    {
        $service = $this->service(
            [$this->shippingMethod('Standard'), $this->shippingMethod('Express')],
            $this->findCountry('DE'),
            shippingCosts: 4.9,
        );

        $result = $service->estimate($this->cart(), SalesChannelContextBuilder::build(), 'DE', '44787');

        self::assertSame(ShippingEstimateResult::STATE_OK, $result->state);
        self::assertCount(2, $result->estimates);
        self::assertSame(['Standard', 'Express'], array_map(static fn ($e) => $e->name, $result->estimates));
        self::assertSame(4.9, $result->estimates[0]->price);
        self::assertSame('EUR', $result->estimates[0]->currencyIsoCode);
    }

    /**
     * Der Reihenfolge-Nachweis: Die Route entscheidet die Verfügbarkeit anhand der
     * Regeln, die auf dem Kontext stehen — und die setzt erst die Berechnung. Wird
     * die Route mit dem Kontext des Besuchers statt dem des Ziellandes gefragt,
     * antwortet sie für das falsche Land. Genau dieser Fehler hat auf einem Shop
     * mit rund 250 Versandarten ganz Österreich verschluckt.
     */
    public function testRouteIsAskedWithTheTargetCountryContext(): void
    {
        $askedWith = [];
        $country = $this->findCountry('AT');

        $service = new ShippingEstimateService(
            $this->shippingMethodRoute([$this->shippingMethod('Standard')], $askedWith),
            $this->countryRepository($country),
            new EstimateContextFactory(),
            $this->cartRuleLoader(),
            new NullLogger(),
        );

        $service->estimate($this->cart(), SalesChannelContextBuilder::build(), 'AT', '1010');

        self::assertCount(1, $askedWith);
        self::assertSame($country->getId(), $askedWith[0]->getShippingLocation()->getCountry()->getId());
        self::assertSame('1010', $askedWith[0]->getShippingLocation()->getAddress()?->getZipcode());
    }

    public function testNoAvailableShippingMethodYieldsNoShipping(): void
    {
        $service = $this->service([], $this->findCountry('AT'));

        $result = $service->estimate($this->cart(), SalesChannelContextBuilder::build(), 'AT', '1010');

        self::assertSame(ShippingEstimateResult::STATE_NO_SHIPPING, $result->state);
    }

    public function testCalculationFailureYieldsErrorStateInsteadOfException(): void
    {
        $cartRuleLoader = $this->createMock(CartRuleLoader::class);
        $cartRuleLoader->method('loadByCart')->willThrowException(new RuntimeException('Berechnung kaputt'));

        $service = new ShippingEstimateService(
            $this->shippingMethodRoute([$this->shippingMethod('Standard')]),
            $this->countryRepository($this->findCountry('DE')),
            new EstimateContextFactory(),
            $cartRuleLoader,
            new NullLogger(),
        );

        $result = $service->estimate($this->cart(), SalesChannelContextBuilder::build(), 'DE', '44787');

        self::assertSame(ShippingEstimateResult::STATE_ERROR, $result->state);
        self::assertSame([], $result->estimates);
    }

    /**
     * Der eigentliche Sicherungsnachweis: Die Auskunft darf den Warenkorb des Kunden
     * nicht anfassen. Ginge das Original in die Berechnung, stünde der Kunde nach
     * einer Preisabfrage mit fremden Versandkosten und fremdem Land da.
     */
    public function testCustomerCartStaysUntouched(): void
    {
        $customerCart = $this->cart();
        $customerCart->setDeliveries(new DeliveryCollection());
        $visitorContext = SalesChannelContextBuilder::build();
        $tokenBefore = $visitorContext->getToken();

        $received = [];
        $cartRuleLoader = $this->createMock(CartRuleLoader::class);
        $cartRuleLoader->method('loadByCart')->willReturnCallback(
            function (SalesChannelContext $context, Cart $cart) use (&$received): RuleLoaderResult {
                $received[] = $cart;
                // Was die Berechnung normalerweise am Warenkorb verändert.
                $cart->setDeliveries(new DeliveryCollection());
                $cart->addExtension('berechnet', new \Shopware\Core\Framework\Struct\ArrayStruct(['x' => 1]));

                return new RuleLoaderResult($cart, new RuleCollection());
            }
        );

        $service = new ShippingEstimateService(
            $this->shippingMethodRoute([$this->shippingMethod('Standard')]),
            $this->countryRepository($this->findCountry('DE')),
            new EstimateContextFactory(),
            $cartRuleLoader,
            new NullLogger(),
        );

        $service->estimate($customerCart, $visitorContext, 'DE', '44787');

        // Zwei Durchläufe: einer für die Verfügbarkeit, einer für den Preis.
        self::assertCount(2, $received);
        foreach ($received as $index => $handedOver) {
            self::assertNotSame($customerCart, $handedOver, "Berechnung {$index} bekam das Original statt eines Klons.");
        }
        self::assertFalse($customerCart->hasExtension('berechnet'));
        self::assertSame($tokenBefore, $visitorContext->getToken());
        self::assertNull($visitorContext->getCustomer());
        self::assertSame('DE', $visitorContext->getShippingLocation()->getCountry()->getIso());
    }

    /**
     * Greift die Kontext-Ableitung nicht, gibt es nichts zu berechnen. Die Fabrik
     * meldet das mit `null`, weil `Struct::assign()` Zuweisungsfehler wortlos
     * verschluckt — ohne diesen Zweig liefe die Berechnung gegen das Standardland
     * des Kanals und lieferte plausible, falsche Preise.
     */
    public function testFailedContextDerivationYieldsNoShipping(): void
    {
        $factory = $this->createMock(EstimateContextFactory::class);
        $factory->method('create')->willReturn(null);

        $service = new ShippingEstimateService(
            $this->shippingMethodRoute([$this->shippingMethod('Standard')]),
            $this->countryRepository($this->findCountry('DE')),
            $factory,
            $this->cartRuleLoader(),
            new NullLogger(),
        );

        $result = $service->estimate($this->cart(), SalesChannelContextBuilder::build(), 'DE', '44787');

        self::assertSame(ShippingEstimateResult::STATE_NO_SHIPPING, $result->state);
    }

    /**
     * Scheitert die Ableitung erst beim Preis einer einzelnen Versandart, fällt nur
     * diese heraus — die übrigen bleiben stehen. Eine einzelne stumme Versandart
     * darf die ganze Auskunft nicht kippen.
     */
    public function testFailedDerivationForOneMethodDropsOnlyThatMethod(): void
    {
        $available = $this->shippingMethod('Standard');
        $mute = $this->shippingMethod('Express');

        $realFactory = new EstimateContextFactory();
        $factory = $this->createMock(EstimateContextFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn ($context, $country, $zip, $method) => $method->getId() === $mute->getId()
                ? null
                : $realFactory->create($context, $country, $zip, $method)
        );

        $service = new ShippingEstimateService(
            $this->shippingMethodRoute([$available, $mute]),
            $this->countryRepository($this->findCountry('DE')),
            $factory,
            $this->cartRuleLoader(4.9),
            new NullLogger(),
        );

        $result = $service->estimate($this->cart(), SalesChannelContextBuilder::build(), 'DE', '44787');

        self::assertSame(ShippingEstimateResult::STATE_OK, $result->state);
        self::assertCount(1, $result->estimates);
        self::assertSame('Standard', $result->estimates[0]->name);
    }

    /**
     * Jede verfügbare Versandart kostet eine eigene Warenkorb-Berechnung. Oberhalb
     * der Grenze wird gekürzt — und das muss im Log stehen. Eine stille Kürzung
     * liest sich wie eine vollständige Liste.
     */
    public function testTooManyMethodsAreCappedAndLogged(): void
    {
        $many = [];
        for ($i = 0; $i < 30; ++$i) {
            $many[] = $this->shippingMethod('Versandart ' . $i);
        }

        $logger = new class () extends NullLogger {
            /** @var list<string> */
            public array $warnings = [];

            /**
             * @param array<string, mixed> $context
             */
            public function warning(string|Stringable $message, array $context = []): void
            {
                $this->warnings[] = (string) $message;
            }
        };

        $service = new ShippingEstimateService(
            $this->shippingMethodRoute($many),
            $this->countryRepository($this->findCountry('DE')),
            new EstimateContextFactory(),
            $this->cartRuleLoader(4.9),
            $logger,
        );

        $result = $service->estimate($this->cart(), SalesChannelContextBuilder::build(), 'DE', '44787');

        self::assertCount(25, $result->estimates);
        self::assertCount(1, $logger->warnings);
        self::assertStringContainsString('gekürzt', $logger->warnings[0]);
    }

    /**
     * Der Name kommt aus der Übersetzung, fällt auf den Entitätsnamen zurück und
     * zuletzt auf die leere Zeichenkette. Ohne den letzten Schritt stünde dort
     * `null` und der Aufruf bräche ab.
     */
    public function testNameFallsBackWhenNoTranslationExists(): void
    {
        $withoutName = new ShippingMethodEntity();
        $withoutName->setId(Uuid::randomHex());

        $service = $this->service([$withoutName], $this->findCountry('DE'), shippingCosts: 4.9);

        $result = $service->estimate($this->cart(), SalesChannelContextBuilder::build(), 'DE', '44787');

        self::assertSame(ShippingEstimateResult::STATE_OK, $result->state);
        self::assertSame('', $result->estimates[0]->name);
        self::assertNull($result->estimates[0]->deliveryTimeName);
    }

    /**
     * Die Lieferzeit ist optional. Ist eine hinterlegt, gehört sie in die Auskunft.
     */
    public function testDeliveryTimeIsPassedThroughWhenPresent(): void
    {
        $withDeliveryTime = $this->shippingMethod('Standard');
        $deliveryTime = new DeliveryTimeEntity();
        $deliveryTime->setId(Uuid::randomHex());
        $deliveryTime->setName('3-5 Werktage');
        // getTranslation() liest aus 'translated', nicht aus dem Feld selbst.
        $deliveryTime->setTranslated(['name' => '3-5 Werktage']);
        $withDeliveryTime->setDeliveryTime($deliveryTime);

        $service = $this->service([$withDeliveryTime], $this->findCountry('DE'), shippingCosts: 4.9);

        $result = $service->estimate($this->cart(), SalesChannelContextBuilder::build(), 'DE', '44787');

        self::assertSame('3-5 Werktage', $result->estimates[0]->deliveryTimeName);
    }

    /**
     * @param list<ShippingMethodEntity> $shippingMethods
     */
    private function service(
        array $shippingMethods,
        ?CountryEntity $country,
        float $shippingCosts = 0.0,
    ): ShippingEstimateService {
        return new ShippingEstimateService(
            $this->shippingMethodRoute($shippingMethods),
            $this->countryRepository($country),
            new EstimateContextFactory(),
            $this->cartRuleLoader($shippingCosts),
            new NullLogger(),
        );
    }

    private function cartRuleLoader(float $shippingCosts = 0.0): CartRuleLoader
    {
        $cartRuleLoader = $this->createMock(CartRuleLoader::class);
        $cartRuleLoader->method('loadByCart')->willReturnCallback(
            function (SalesChannelContext $context, Cart $cart) use ($shippingCosts): RuleLoaderResult {
                // `Cart::getShippingCosts()` summiert die Lieferungen — die Kosten
                // müssen also über eine Lieferung hineinkommen, nicht als Zahl.
                $cart->setDeliveries(new DeliveryCollection([
                    new Delivery(
                        new DeliveryPositionCollection(),
                        new DeliveryDate(new DateTimeImmutable(), new DateTimeImmutable()),
                        $context->getShippingMethod(),
                        $context->getShippingLocation(),
                        new CalculatedPrice(
                            $shippingCosts,
                            $shippingCosts,
                            new CalculatedTaxCollection(),
                            new TaxRuleCollection(),
                        ),
                    ),
                ]));

                return new RuleLoaderResult($cart, new RuleCollection());
            }
        );

        return $cartRuleLoader;
    }

    /**
     * Die Versandarten-Route als Double: Sie gibt zurück, was Shopware als
     * verfügbar meldet. Über $askedWith lässt sich prüfen, mit welchem Kontext
     * sie gefragt wurde — daran hängt der Nachweis der Reihenfolge.
     *
     * @param list<ShippingMethodEntity>      $shippingMethods
     * @param array<int, SalesChannelContext> $askedWith
     */
    private function shippingMethodRoute(array $shippingMethods, array &$askedWith = []): AbstractShippingMethodRoute
    {
        $route = $this->createMock(AbstractShippingMethodRoute::class);
        $route->method('load')->willReturnCallback(
            function (Request $request, SalesChannelContext $context, Criteria $criteria) use ($shippingMethods, &$askedWith): ShippingMethodRouteResponse {
                $askedWith[] = $context;

                $collection = new ShippingMethodCollection($shippingMethods);

                return new ShippingMethodRouteResponse(new EntitySearchResult(
                    'shipping_method',
                    $collection->count(),
                    $collection,
                    null,
                    $criteria,
                    $context->getContext(),
                ));
            }
        );

        return $route;
    }

    /**
     * @return EntityRepository<CountryCollection>
     */
    private function countryRepository(?CountryEntity $country): EntityRepository
    {
        return $this->repository('country', new CountryCollection($country === null ? [] : [$country]));
    }

    /**
     * @template TCollection of EntityCollection<covariant \Shopware\Core\Framework\DataAbstractionLayer\Entity>
     *
     * @param TCollection $collection
     *
     * @return EntityRepository<TCollection>
     */
    private function repository(string $entity, EntityCollection $collection): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, \Shopware\Core\Framework\Context $context): EntitySearchResult
                => new EntitySearchResult(
                    $entity,
                    $collection->count(),
                    $collection,
                    null,
                    $criteria,
                    $context,
                )
        );

        return $repository;
    }

    private function cart(): Cart
    {
        $cart = new Cart('kunden-token');
        $cart->add(new LineItem(Uuid::randomHex(), LineItem::PRODUCT_LINE_ITEM_TYPE));

        return $cart;
    }

    private function shippingMethod(string $name): ShippingMethodEntity
    {
        $shippingMethod = new ShippingMethodEntity();
        $shippingMethod->setId(Uuid::randomHex());
        $shippingMethod->setName($name);

        return $shippingMethod;
    }

    private function findCountry(string $iso): CountryEntity
    {
        $country = new CountryEntity();
        $country->setId(Uuid::randomHex());
        $country->setIso($iso);

        return $country;
    }
}
