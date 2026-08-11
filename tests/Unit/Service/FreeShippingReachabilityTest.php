<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\FreeShippingReachability;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Content\Rule\Aggregate\RuleCondition\RuleConditionCollection;
use Shopware\Core\Content\Rule\Aggregate\RuleCondition\RuleConditionEntity;
use Shopware\Core\Content\Rule\RuleEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Die Frage, um die es hier geht: Gilt Versandkostenfreiheit für diesen Lieferort
 * überhaupt?
 *
 * Gelesen wird die echte Verfügbarkeitsregel der eingestellten Versandarten. Eine eigene
 * Nachbildung („wenn Land = DE") wäre eine zweite Wahrheit neben dem Admin und liefe beim
 * ersten geänderten Regelsatz still auseinander.
 */
final class FreeShippingReachabilityTest extends TestCase
{
    private const DE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const AT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * Was: Die Regel erlaubt Deutschland, der Besucher liefert nach Deutschland.
     * Warum: Der Normalfall — hier soll der Hinweis stehen.
     */
    public function testTheHintAppliesInAnAllowedCountry(): void
    {
        $reach = $this->reachability(allowedCountryIds: [self::DE])
            ->reachableFrom(['sm-1'], $this->context(self::DE));

        self::assertTrue($reach->applies);
        self::assertTrue($reach->certain);
        self::assertSame(['Deutschland'], $reach->countryNames);
    }

    /**
     * Was: Dieselbe Regel, aber der Besucher liefert nach Österreich.
     * Warum: **Der Befund.** Bis 1.3.0 stand der Hinweis auch ihm vor der Nase — eine
     *        Zusage, die für ihn nie eintritt. Verschärft dadurch, dass seitdem der
     *        Versandkostenrechner direkt darunter für dasselbe Land eine Zahl größer null
     *        nennt: zwei Aussagen an derselben Stelle, die einander widersprechen.
     * Erwartet: Der Hinweis gilt nicht.
     */
    public function testTheHintDoesNotApplyInACountryTheRuleExcludes(): void
    {
        $reach = $this->reachability(allowedCountryIds: [self::DE])
            ->reachableFrom(['sm-1'], $this->context(self::AT));

        self::assertFalse($reach->applies);
        self::assertTrue($reach->certain);
    }

    /**
     * Was: Es ist keine Versandart eingestellt.
     * Warum: Im Zweifel wird geworben, nicht geschwiegen — aber die Unsicherheit wird
     *        mitgeführt, damit die Vorlage die Bedingung in den Text nehmen kann.
     * Erwartet: gilt, aber nicht sicher.
     */
    public function testWithoutConfiguredMethodsTheAnswerIsYesButUncertain(): void
    {
        $reach = $this->reachability(allowedCountryIds: [self::DE])
            ->reachableFrom([], $this->context(self::AT));

        self::assertTrue($reach->applies);
        self::assertFalse($reach->certain);
    }

    /**
     * Was: Die Versandart trägt gar keine Verfügbarkeitsregel.
     * Warum: Dann ist sie überall verfügbar — die Frage nach dem Land ist damit
     *        beantwortet, und zwar mit „überall".
     */
    public function testAMethodWithoutARuleAppliesEverywhere(): void
    {
        $reach = $this->reachability(allowedCountryIds: null)
            ->reachableFrom(['sm-1'], $this->context(self::AT));

        self::assertTrue($reach->applies);
        self::assertFalse($reach->certain);
    }

    /**
     * Was: Die Regel nennt einen Betrag.
     * Warum: **Der Kern.** Bis 1.4.0 stand derselbe Betrag an drei Stellen —
     *        Regel, Einstellung, Freitext der Vertrauensleiste — und alle drei waren
     *        verschieden. Gelesen wird jetzt die Regel; die Einstellung ist nur noch
     *        Rückfall.
     */
    public function testTheThresholdIsReadFromTheRule(): void
    {
        $reach = $this->reachability(allowedCountryIds: [self::DE], amounts: [357.0])
            ->reachableFrom(['sm-1'], $this->context(self::DE));

        self::assertSame(357.0, $reach->threshold);
    }

    /**
     * Was: Zwei Versandarten mit verschiedenen Beträgen.
     * Warum: Ab dem niedrigsten ist Versandkostenfreiheit überhaupt erreichbar — und genau
     *        das sagt der Hinweis zu.
     */
    public function testTheLowestAmountWins(): void
    {
        $reach = $this->reachability(allowedCountryIds: [self::DE], amounts: [500.0, 357.0])
            ->reachableFrom(['sm-1'], $this->context(self::DE));

        self::assertSame(357.0, $reach->threshold);
    }

    /**
     * Was: Eine Regel ohne Betrags-Bedingung.
     * Warum: Dann bleibt die Einstellung im Admin maßgeblich — der Dienst rät nicht.
     */
    public function testWithoutAnAmountConditionTheThresholdStaysUnknown(): void
    {
        $reach = $this->reachability(allowedCountryIds: [self::DE])
            ->reachableFrom(['sm-1'], $this->context(self::DE));

        self::assertNull($reach->threshold);
    }

    /**
     * @param list<string>|null $allowedCountryIds null = Versandart ohne Regel
     */
    /**
     * @param list<string>|null $allowedCountryIds null = Versandart ohne Regel
     * @param list<float>       $amounts           je ein Betrag = je eine Versandart
     */
    private function reachability(?array $allowedCountryIds, array $amounts = []): FreeShippingReachability
    {
        $methods = [];
        foreach ($amounts === [] ? [null] : $amounts as $index => $amount) {
            $methods[] = $this->method('sm-' . $index, $allowedCountryIds, $amount);
        }

        $shippingMethods = $this->createMock(EntityRepository::class);
        $shippingMethods->method('search')->willReturn($this->searchResult(new ShippingMethodCollection($methods)));

        $country = new CountryEntity();
        $country->setId(self::DE);
        $country->setUniqueIdentifier(self::DE);
        $country->setName('Deutschland');

        $countries = $this->createMock(EntityRepository::class);
        $countries->method('search')->willReturn($this->searchResult(new CountryCollection([$country])));

        return new FreeShippingReachability($shippingMethods, $countries);
    }

    /**
     * @param list<string>|null $allowedCountryIds
     */
    private function method(string $id, ?array $allowedCountryIds, ?float $amount): ShippingMethodEntity
    {
        $method = new ShippingMethodEntity();
        $method->setId($id);
        $method->setUniqueIdentifier($id);

        if ($allowedCountryIds === null) {
            return $method;
        }

        $conditions = [$this->condition('customerShippingCountry', [
            'operator' => '=',
            'countryIds' => $allowedCountryIds,
        ])];

        if ($amount !== null) {
            $conditions[] = $this->condition('cartGoodsPrice', ['operator' => '>', 'amount' => $amount]);
        }

        $rule = new RuleEntity();
        $rule->setId('rule-' . $id);
        $rule->setUniqueIdentifier('rule-' . $id);
        $rule->setConditions(new RuleConditionCollection($conditions));

        $method->setAvailabilityRule($rule);

        return $method;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function condition(string $type, array $value): RuleConditionEntity
    {
        $condition = new RuleConditionEntity();
        $condition->setUniqueIdentifier(Uuid::randomHex());
        $condition->setType($type);
        $condition->setValue($value);

        return $condition;
    }

    /**
     * @param EntityCollection<covariant Entity> $entities
     *
     * @return EntitySearchResult<EntityCollection<covariant Entity>>
     */
    private function searchResult(EntityCollection $entities): EntitySearchResult
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getEntities')->willReturn($entities);

        return $result;
    }

    private function context(string $countryId): SalesChannelContext
    {
        $country = new CountryEntity();
        $country->setId($countryId);
        $country->setUniqueIdentifier($countryId);

        $location = $this->createMock(\Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation::class);
        $location->method('getCountry')->willReturn($country);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getShippingLocation')->willReturn($location);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }
}
