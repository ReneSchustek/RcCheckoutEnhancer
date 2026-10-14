<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\SystemCheck;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\SystemCheck\ConfigurationCheck;
use Shopware\Core\Framework\SystemCheck\Check\Status;
use Shopware\Core\Framework\SystemCheck\Check\SystemCheckExecutionContext;

/**
 * Der Eintrag im Systemstatus, der eine mangels Einstellung ruhende Funktion meldet.
 */
final class ConfigurationCheckTest extends TestCase
{
    private const CHANNEL = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testEverythingConfiguredIsOk(): void
    {
        $result = $this->check($this->config(category: 'cat', nonDelivery: ['pickup'], freeShipping: ['free']))->run();

        self::assertSame(Status::OK, $result->status);
        self::assertTrue($result->healthy);
        self::assertSame([], $result->extra['warnings']);
        self::assertSame([], $result->extra['notices']);
    }

    public function testAMissingContactPageIsAWarningNamingTheChannel(): void
    {
        $result = $this->check($this->config(category: null, nonDelivery: ['pickup'], freeShipping: ['free']))->run();

        self::assertSame(Status::WARNING, $result->status);
        self::assertStringContainsString('Storefront: Anfrageweg ruht', $result->message);
        // Eine ruhende Funktion ist kein Ausfall; die Überwachung soll nicht Alarm schlagen.
        self::assertTrue($result->healthy);
    }

    public function testAMissingNonDeliveryListIsAWarning(): void
    {
        $result = $this->check($this->config(category: 'cat', nonDelivery: [], freeShipping: ['free']))->run();

        self::assertSame(Status::WARNING, $result->status);
        self::assertStringContainsString('keine Lieferung', $result->message);
    }

    public function testASwitchedOffEnquiryNeedsNoTarget(): void
    {
        $result = $this->check($this->config(category: null, nonDelivery: [], freeShipping: ['free'], enquiry: false))->run();

        self::assertSame(Status::OK, $result->status);
    }

    public function testMissingFreeShippingMethodsAreOnlyANotice(): void
    {
        $result = $this->check($this->config(category: 'cat', nonDelivery: ['pickup'], freeShipping: []))->run();

        self::assertSame(Status::OK, $result->status);
        self::assertStringStartsWith('Eingerichtet, mit Hinweisen', $result->message);
        self::assertCount(1, $result->extra['notices']);
    }

    public function testAnInactiveFreeShippingIndicatorNeedsNoMethods(): void
    {
        $result = $this->check($this->config(category: 'cat', nonDelivery: ['pickup'], freeShipping: [], indicator: false))->run();

        self::assertSame([], $result->extra['notices']);
    }

    public function testItRunsInEveryContextIncludingBeforeARollout(): void
    {
        $check = $this->check($this->config(category: 'cat', nonDelivery: ['pickup'], freeShipping: ['free']));

        foreach (SystemCheckExecutionContext::cases() as $context) {
            self::assertTrue($check->allowedToRunIn($context), $context->value);
        }
    }

    private function check(ConfigService $config): ConfigurationCheck
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllKeyValue')->willReturn([self::CHANNEL => 'Storefront']);

        return new ConfigurationCheck($config, $connection);
    }

    /**
     * @param list<string> $nonDelivery
     * @param list<string> $freeShipping
     */
    private function config(?string $category, array $nonDelivery, array $freeShipping, bool $enquiry = true, bool $indicator = true): ConfigService&MockObject
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('isShippingEnquiryEnabled')->with(self::CHANNEL)->willReturn($enquiry);
        $config->method('getShippingEnquiryCategoryId')->willReturn($category);
        $config->method('getNonDeliveryMethodIds')->willReturn($nonDelivery);
        $config->method('isFreeShippingIndicatorEnabled')->willReturn($indicator);
        $config->method('getFreeShippingMethodIds')->willReturn($freeShipping);

        return $config;
    }
}
