<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Subscriber\CheckoutSubscriber;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPage;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPage;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Register\CheckoutRegisterPage;
use Shopware\Storefront\Page\Checkout\Register\CheckoutRegisterPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(CheckoutSubscriber::class)]
final class CheckoutSubscriberTest extends TestCase
{
    private ConfigService&MockObject $configService;
    private CheckoutSubscriber $subscriber;
    private SalesChannelContext&MockObject $salesChannelContext;

    private const SALES_CHANNEL_ID = 'test-sales-channel-id';

    protected function setUp(): void
    {
        $this->configService = $this->createMock(ConfigService::class);
        $this->subscriber = new CheckoutSubscriber($this->configService);

        $salesChannel = $this->createMock(SalesChannelEntity::class);
        $salesChannel->method('getId')->willReturn(self::SALES_CHANNEL_ID);

        $this->salesChannelContext = $this->createMock(SalesChannelContext::class);
        $this->salesChannelContext->method('getSalesChannel')->willReturn($salesChannel);
    }

    #[Test]
    public function subscribedEventsRegistersAllCheckoutEvents(): void
    {
        $events = CheckoutSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(CheckoutCartPageLoadedEvent::class, $events);
        self::assertArrayHasKey(CheckoutRegisterPageLoadedEvent::class, $events);
        self::assertArrayHasKey(CheckoutConfirmPageLoadedEvent::class, $events);
        self::assertArrayHasKey(CheckoutFinishPageLoadedEvent::class, $events);
        self::assertCount(4, $events);
    }

    #[Test]
    public function cartPageSetsStepOne(): void
    {
        $this->configureDefaultMocks();
        $page = new CheckoutCartPage();
        $event = new CheckoutCartPageLoadedEvent($page, $this->salesChannelContext, new Request());

        $this->subscriber->onCheckoutPage($event);

        $extension = $page->getExtension('rcCheckoutEnhancer');
        self::assertInstanceOf(ArrayEntity::class, $extension);
        self::assertSame(1, $extension->get('currentStep'));
    }

    #[Test]
    public function registerPageSetsStepTwo(): void
    {
        $this->configureDefaultMocks();
        $page = new CheckoutRegisterPage();
        $event = new CheckoutRegisterPageLoadedEvent($page, $this->salesChannelContext, new Request());

        $this->subscriber->onCheckoutPage($event);

        $extension = $page->getExtension('rcCheckoutEnhancer');
        self::assertInstanceOf(ArrayEntity::class, $extension);
        self::assertSame(2, $extension->get('currentStep'));
    }

    #[Test]
    public function confirmPageSetsStepThree(): void
    {
        $this->configureDefaultMocks();
        $page = new CheckoutConfirmPage();
        $event = new CheckoutConfirmPageLoadedEvent($page, $this->salesChannelContext, new Request());

        $this->subscriber->onCheckoutPage($event);

        $extension = $page->getExtension('rcCheckoutEnhancer');
        self::assertInstanceOf(ArrayEntity::class, $extension);
        self::assertSame(3, $extension->get('currentStep'));
    }

    #[Test]
    public function finishPageSetsStepFour(): void
    {
        $this->configureDefaultMocks();
        $page = new CheckoutFinishPage();
        $event = new CheckoutFinishPageLoadedEvent($page, $this->salesChannelContext, new Request());

        $this->subscriber->onCheckoutPage($event);

        $extension = $page->getExtension('rcCheckoutEnhancer');
        self::assertInstanceOf(ArrayEntity::class, $extension);
        self::assertSame(4, $extension->get('currentStep'));
    }

    #[Test]
    public function extensionContainsAllConfigValues(): void
    {
        $expectedBadges = [['icon' => 'lock', 'text' => 'Sicher']];
        $expectedLabels = ['step1' => 'W', 'step2' => 'A', 'step3' => 'P', 'step4' => 'F'];

        $this->configService->method('isProgressBarEnabled')->willReturn(true);
        $this->configService->method('isTrustBadgesEnabled')->willReturn(false);
        $this->configService->method('getTrustBadges')->willReturn($expectedBadges);
        $this->configService->method('isMiniCartEnabled')->willReturn(true);
        $this->configService->method('isOrderSummaryEnabled')->willReturn(false);
        $this->configService->method('isDeliveryTimeEnabled')->willReturn(true);
        $this->configService->method('getEstimatedDeliveryTime')->willReturn('3-5 Werktage');
        $this->configService->method('getProgressStepLabels')->willReturn($expectedLabels);

        $page = new CheckoutCartPage();
        $event = new CheckoutCartPageLoadedEvent($page, $this->salesChannelContext, new Request());

        $this->subscriber->onCheckoutPage($event);

        $extension = $page->getExtension('rcCheckoutEnhancer');
        self::assertInstanceOf(ArrayEntity::class, $extension);
        self::assertSame(4, $extension->get('totalSteps'));
        self::assertTrue($extension->get('progressBarEnabled'));
        self::assertFalse($extension->get('trustBadgesEnabled'));
        self::assertSame($expectedBadges, $extension->get('trustBadges'));
        self::assertTrue($extension->get('miniCartEnabled'));
        self::assertFalse($extension->get('orderSummaryEnabled'));
        self::assertTrue($extension->get('deliveryTimeEnabled'));
        self::assertSame('3-5 Werktage', $extension->get('estimatedDeliveryTime'));
        self::assertSame($expectedLabels, $extension->get('stepLabels'));
    }

    #[Test]
    public function salesChannelIdIsPassedToConfigService(): void
    {
        $this->configService->expects(self::once())
            ->method('isProgressBarEnabled')
            ->with(self::SALES_CHANNEL_ID)
            ->willReturn(true);

        $this->configService->method('isTrustBadgesEnabled')->willReturn(true);
        $this->configService->method('getTrustBadges')->willReturn([]);
        $this->configService->method('isMiniCartEnabled')->willReturn(true);
        $this->configService->method('isOrderSummaryEnabled')->willReturn(true);
        $this->configService->method('isDeliveryTimeEnabled')->willReturn(false);
        $this->configService->method('getEstimatedDeliveryTime')->willReturn('');
        $this->configService->method('getProgressStepLabels')->willReturn([
            'step1' => '', 'step2' => '', 'step3' => '', 'step4' => '',
        ]);

        $page = new CheckoutCartPage();
        $event = new CheckoutCartPageLoadedEvent($page, $this->salesChannelContext, new Request());

        $this->subscriber->onCheckoutPage($event);
    }

    private function configureDefaultMocks(): void
    {
        $this->configService->method('isProgressBarEnabled')->willReturn(true);
        $this->configService->method('isTrustBadgesEnabled')->willReturn(true);
        $this->configService->method('getTrustBadges')->willReturn([]);
        $this->configService->method('isMiniCartEnabled')->willReturn(true);
        $this->configService->method('isOrderSummaryEnabled')->willReturn(true);
        $this->configService->method('isDeliveryTimeEnabled')->willReturn(false);
        $this->configService->method('getEstimatedDeliveryTime')->willReturn('');
        $this->configService->method('getProgressStepLabels')->willReturn([
            'step1' => '', 'step2' => '', 'step3' => '', 'step4' => '',
        ]);
    }
}
