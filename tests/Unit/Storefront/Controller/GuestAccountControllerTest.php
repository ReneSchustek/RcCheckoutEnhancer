<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Storefront\Controller;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\GuestAccountOffer;
use Ruhrcoder\RcCheckoutEnhancer\Storefront\Controller\GuestAccountController;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractConvertGuestRoute;
use Shopware\Core\Checkout\Customer\Validation\Constraint\CustomerEmailUnique;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Der Endpunkt, der aus dem Gastkonto ein Kundenkonto macht. Er darf das nur für den Gast, den die
 * Sitzung vorgemerkt hat, und nie für eine Kennung aus der Anfrage.
 */
final class GuestAccountControllerTest extends TestCase
{
    private RequestStack $stack;

    protected function setUp(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->stack = new RequestStack();
        $this->stack->push($request);
    }

    public function testTheRememberedGuestBecomesACustomer(): void
    {
        $this->offer()->remember('kunde-1', 'gast@example.test', new DateTimeImmutable());

        $route = $this->createMock(AbstractConvertGuestRoute::class);
        $route->expects(self::once())->method('convertGuest')->with(
            self::callback(static fn (RequestDataBag $data): bool => $data->get('password') === 'Kennwort-2026'),
            self::anything(),
            self::callback(static fn (CustomerEntity $customer): bool => $customer->getId() === 'kunde-1'),
        );

        $response = $this->controller($route)->create(new RequestDataBag(['password' => 'Kennwort-2026']), $this->context());

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/frontend.account.login.page', $response->getTargetUrl());
        self::assertNull($this->offer()->pending(new DateTimeImmutable()), 'einmal verwendbar');
        self::assertSame(['rcCheckout.guestAccount.created'], $this->flashes('success'));
    }

    /**
     * Was: Die Anfrage nennt eine fremde Kundenkennung.
     * Warum: Sonst ließe sich ein beliebiges Gastkonto mit einem Kennwort übernehmen.
     */
    public function testACustomerIdInTheRequestIsIgnored(): void
    {
        $this->offer()->remember('kunde-1', 'gast@example.test', new DateTimeImmutable());

        $route = $this->createMock(AbstractConvertGuestRoute::class);
        $route->expects(self::once())->method('convertGuest')->with(
            self::callback(static fn (RequestDataBag $data): bool => !$data->has('customerId')),
            self::anything(),
            self::callback(static fn (CustomerEntity $customer): bool => $customer->getId() === 'kunde-1'),
        );

        $this->controller($route)->create(new RequestDataBag(['password' => 'Kennwort-2026', 'customerId' => 'fremd']), $this->context());
    }

    public function testWithoutAnOfferNothingIsConverted(): void
    {
        $route = $this->createMock(AbstractConvertGuestRoute::class);
        $route->expects(self::never())->method('convertGuest');

        $response = $this->controller($route)->create(new RequestDataBag(['password' => 'Kennwort-2026']), $this->context());

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(['rcCheckout.guestAccount.expired'], $this->flashes('info'));
    }

    /**
     * Was: Der Gast hat inzwischen ein Konto, etwa über einen zweiten Reiter.
     */
    public function testAGuestWhoIsNoLongerAGuestIsLeftAlone(): void
    {
        $this->offer()->remember('kunde-1', 'gast@example.test', new DateTimeImmutable());

        $route = $this->createMock(AbstractConvertGuestRoute::class);
        $route->expects(self::never())->method('convertGuest');

        $this->controller($route, guest: false)->create(new RequestDataBag(['password' => 'Kennwort-2026']), $this->context());

        self::assertNull($this->offer()->pending(new DateTimeImmutable()));
    }

    /**
     * Was: Zu kurzes Kennwort.
     * Warum: Zurück zum Formular, das Angebot bleibt; der Kunde soll es noch einmal versuchen können.
     */
    public function testARejectedPasswordLeadsBackToTheForm(): void
    {
        $this->offer()->remember('kunde-1', 'gast@example.test', new DateTimeImmutable());

        $route = $this->createMock(AbstractConvertGuestRoute::class);
        $route->method('convertGuest')->willThrowException(new ConstraintViolationException(new ConstraintViolationList([
            new ConstraintViolation('zu kurz', '', [], '', '/password', ''),
        ]), []));

        $response = $this->controller($route)->create(new RequestDataBag(['password' => 'kurz']), $this->context());

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/frontend.rc-checkout.guest-account.page', $response->getTargetUrl());
        self::assertSame(['rcCheckout.guestAccount.invalidPassword'], $this->flashes('danger'));
        self::assertNotNull($this->offer()->pending(new DateTimeImmutable()));
    }

    public function testATakenEmailIsNamed(): void
    {
        $this->offer()->remember('kunde-1', 'gast@example.test', new DateTimeImmutable());

        $route = $this->createMock(AbstractConvertGuestRoute::class);
        $route->method('convertGuest')->willThrowException(new ConstraintViolationException(new ConstraintViolationList([
            new ConstraintViolation('vergeben', '', [], '', '/email', '', null, CustomerEmailUnique::CUSTOMER_EMAIL_NOT_UNIQUE),
        ]), []));

        $this->controller($route)->create(new RequestDataBag(['password' => 'Kennwort-2026']), $this->context());

        self::assertSame(['rcCheckout.guestAccount.emailTaken'], $this->flashes('danger'));
    }

    public function testWhenSwitchedOffTheRouteDoesNotExist(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller($this->createMock(AbstractConvertGuestRoute::class), enabled: false)->page($this->context());
    }

    public function testThePageWithoutAnOfferLeadsToTheLogin(): void
    {
        $response = $this->controller($this->createMock(AbstractConvertGuestRoute::class))->page($this->context());

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/frontend.account.login.page', $response->getTargetUrl());
    }

    private function controller(AbstractConvertGuestRoute $route, bool $enabled = true, bool $guest = true): GuestAccountController
    {
        $configService = $this->createMock(ConfigService::class);
        $configService->method('isGuestAccountOfferEnabled')->willReturn($enabled);

        $customer = new CustomerEntity();
        $customer->setId('kunde-1');
        $customer->setUniqueIdentifier('kunde-1');
        $customer->setGuest($guest);
        $customer->setEmail('gast@example.test');

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn(new EntitySearchResult(
            'customer',
            1,
            new CustomerCollection([$customer]),
            null,
            new Criteria(),
            Context::createDefaultContext(),
        ));

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getBool')->willReturn(false);
        $systemConfig->method('getInt')->willReturn(8);

        $controller = new GuestAccountController($configService, $this->offer(), $route, $repository, $systemConfig);
        $controller->setContainer($this->container());

        return $controller;
    }

    private function offer(): GuestAccountOffer
    {
        return new GuestAccountOffer($this->stack);
    }

    /**
     * @return list<string>
     */
    private function flashes(string $type): array
    {
        $session = $this->stack->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);

        return array_values(array_map('strval', $session->getFlashBag()->peek($type)));
    }

    private function container(): Container
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name): string => '/' . $name);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $container = new Container();
        $container->set('router', $router);
        $container->set('translator', $translator);
        $container->set('request_stack', $this->stack);
        $container->set('event_dispatcher', new EventDispatcher());

        return $container;
    }

    private function context(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-id');
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }
}
