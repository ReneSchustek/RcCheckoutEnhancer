<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Storefront\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Ruhrcoder\RcCheckoutEnhancer\Service\ConfigService;
use Ruhrcoder\RcCheckoutEnhancer\Service\GuestAccountOffer;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractConvertGuestRoute;
use Shopware\Core\Checkout\Customer\Validation\Constraint\CustomerEmailUnique;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\RateLimiter\Exception\RateLimitExceededException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\EqualTo;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Legt aus dem Gastkonto der eben abgeschlossenen Bestellung ein Kundenkonto an.
 *
 * Für wen, steht in der Sitzung ({@see GuestAccountOffer}), nie in der Anfrage. Umgewandelt wird
 * über dieselbe Route wie im Kern; sie prüft Kennwort und Eindeutigkeit der Mailadresse und bremst
 * wiederholte Versuche. Danach geht es zur Anmeldung: Angemeldet war der Gast schon nicht mehr,
 * und eine Anmeldung ohne Eingabe der Zugangsdaten gäbe einem Fremden am selben Rechner das Konto.
 *
 * Kein Captcha: Ohne Vormerkung aus einer Bestellung in derselben Sitzung bewirkt der Endpunkt
 * nichts, ein Programm kann ihn also nicht nutzen. Ein Rätsel träfe nur den Kunden.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class GuestAccountController extends StorefrontController
{
    public const PAGE_TEMPLATE = '@RcCheckoutEnhancer/storefront/page/rc-checkout/guest-account.html.twig';

    /**
     * @param EntityRepository<CustomerCollection> $customerRepository
     */
    public function __construct(
        private readonly ConfigService $configService,
        private readonly GuestAccountOffer $offer,
        private readonly AbstractConvertGuestRoute $convertGuestRoute,
        private readonly EntityRepository $customerRepository,
        private readonly SystemConfigService $systemConfig,
    ) {
    }

    /**
     * Das Formular als eigene Seite, für den Weg zurück nach einer abgelehnten Eingabe. Die
     * Abschlussseite selbst verlangt nach der Abmeldung eine Anmeldung.
     */
    #[Route(
        path: '/rc-checkout/guest-account',
        name: 'frontend.rc-checkout.guest-account.page',
        methods: ['GET'],
    )]
    public function page(SalesChannelContext $context): Response
    {
        $this->ensureEnabled($context);

        $offer = $this->offer->pending($this->now());
        if ($offer === null) {
            return $this->expired();
        }

        return $this->renderStorefront(self::PAGE_TEMPLATE, ['rcGuestAccount' => ['email' => $offer['email']]]);
    }

    #[Route(
        path: '/rc-checkout/guest-account',
        name: 'frontend.rc-checkout.guest-account',
        methods: ['POST'],
    )]
    public function create(RequestDataBag $data, SalesChannelContext $context): Response
    {
        $this->ensureEnabled($context);

        $customer = $this->pendingGuest($context);
        if ($customer === null) {
            return $this->expired();
        }

        $password = $data->get('password');
        $input = new RequestDataBag(['password' => \is_string($password) ? $password : '']);

        // Wie im Kern: Die Wiederholung des Kennworts gehört nur dazu, wenn der Shop sie verlangt.
        $definition = new DataValidationDefinition('rc_guest_account');
        if ($this->systemConfig->getBool('core.loginRegistration.requirePasswordConfirmation', $context->getSalesChannelId())) {
            $input->set('passwordConfirmation', $data->get('passwordConfirmation'));
            $definition->add('passwordConfirmation', new NotBlank(), new EqualTo(value: $input->get('password')));
        }

        try {
            $this->convertGuestRoute->convertGuest($input, $context, $customer, $definition);
        } catch (RateLimitExceededException $exception) {
            $this->addFlash(self::INFO, $this->trans('error.rateLimitExceeded', ['%seconds%' => $exception->getWaitTime()]));

            return $this->redirectToRoute('frontend.rc-checkout.guest-account.page');
        } catch (ConstraintViolationException $exception) {
            return $this->rejected($exception, $context);
        }

        $this->offer->forget();
        $this->addFlash(self::SUCCESS, $this->trans('rcCheckout.guestAccount.created', ['%email%' => $customer->getEmail()]));

        return $this->redirectToRoute('frontend.account.login.page');
    }

    /**
     * Zurück zum Formular mit dem Grund. Das Angebot bleibt, der Kunde kann es noch einmal versuchen.
     */
    private function rejected(ConstraintViolationException $exception, SalesChannelContext $context): Response
    {
        // Der Code, den der Prüfer des Kerns setzt. `CUSTOMER_EMAIL_NOT_UNIQUE_CODE` gibt es erst
        // ab 6.7.13; dieses Plugin läuft ab 6.7.0.
        $emailTaken = $exception->getViolations()->findByCodes(CustomerEmailUnique::CUSTOMER_EMAIL_NOT_UNIQUE)->count() > 0;

        $this->addFlash(self::DANGER, $this->trans(
            $emailTaken ? 'rcCheckout.guestAccount.emailTaken' : 'rcCheckout.guestAccount.invalidPassword',
            ['%minLength%' => $this->systemConfig->getInt('core.loginRegistration.passwordMinLength', $context->getSalesChannelId())],
        ));

        return $this->redirectToRoute('frontend.rc-checkout.guest-account.page');
    }

    private function ensureEnabled(SalesChannelContext $context): void
    {
        if (!$this->configService->isGuestAccountOfferEnabled($context->getSalesChannelId())) {
            throw new NotFoundHttpException();
        }
    }

    /**
     * Der vorgemerkte Gast, solange er noch Gast ist. Wer inzwischen ein Konto hat, etwa über einen
     * zweiten Reiter, wird nicht noch einmal umgewandelt.
     */
    private function pendingGuest(SalesChannelContext $context): ?CustomerEntity
    {
        $offer = $this->offer->pending($this->now());
        if ($offer === null) {
            return null;
        }

        $customer = $this->customerRepository
            ->search(new Criteria([$offer['customerId']]), $context->getContext())
            ->getEntities()
            ->first();

        return $customer instanceof CustomerEntity && $customer->getGuest() ? $customer : null;
    }

    private function expired(): Response
    {
        $this->offer->forget();
        $this->addFlash(self::INFO, $this->trans('rcCheckout.guestAccount.expired'));

        return $this->redirectToRoute('frontend.account.login.page');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
