<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\PhoneNumberInput;
use Ruhrcoder\RcCheckoutEnhancer\Service\PhoneNumberRequirement;
use Ruhrcoder\RcCheckoutEnhancer\Storefront\Controller\PhoneNumberController;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressCollection;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Der Weg der nachgereichten Nummer in die gespeicherte Adresse.
 *
 * Die Nummer muss in die *gespeicherte* Adresse. Landete sie nur an der Bestellung, stünde
 * derselbe Kunde bei der nächsten Bestellung wieder vor der Sperre, denn geprüft wird die
 * Rechnungsadresse im Konto.
 */
final class PhoneNumberControllerTest extends TestCase
{
    /**
     * Was: Eine gültige Nummer wird nachgereicht.
     * Warum: Der Hauptfall. Geprüft wird die geschriebene Kennung mit: Sie muss die des
     *        Servers sein, nicht irgendeine.
     */
    public function testItWritesTheNumberIntoTheStoredAddress(): void
    {
        $address = $this->address();
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('update')
            ->with([['id' => $address->getId(), 'phoneNumber' => '09568 8039770']]);

        $antwort = $this->controller($address, $repository)
            ->save($this->request('09568 8039770'), $this->context());

        self::assertInstanceOf(RedirectResponse::class, $antwort);
        self::assertSame('/checkout/confirm', $antwort->getTargetUrl());
    }

    /**
     * Was: Die geladene Adresse nach dem Speichern.
     * Warum: Ohne das Nachziehen stünde die Sperre auf der Seite, auf die gerade umgeleitet
     *        wird, noch einmal da — der Kunde hätte die Nummer eingegeben und bekäme dieselbe
     *        Aufforderung zurück.
     */
    public function testItKeepsTheLoadedAddressInStep(): void
    {
        $address = $this->address();

        $this->controller($address)->save($this->request('09568 8039770'), $this->context());

        self::assertSame('09568 8039770', $address->getPhoneNumber());
    }

    /**
     * Was: Etwas, das keine Nummer ist.
     * Warum: Es darf nichts gespeichert werden — sonst steht später eine Zeile im Nummernfeld,
     *        unter der niemand erreichbar ist.
     */
    public function testItSavesNothingForAnInvalidEntry(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('update');

        $antwort = $this->controller($this->address(), $repository)
            ->save($this->request('bitte per Mail'), $this->context());

        self::assertInstanceOf(RedirectResponse::class, $antwort);
    }

    /**
     * Was: Es fehlt gar keine Nummer — etwa weil das Formular zweimal abgeschickt wurde.
     * Warum: Kein Fehler, aber auch kein Schreibzugriff. Käme die Kennung aus der Anfrage,
     *        ließe sich hier eine gepflegte Nummer von außen überschreiben.
     */
    public function testItSavesNothingWhenNoNumberIsMissing(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('update');

        $antwort = $this->controller(null, $repository)
            ->save($this->request('09568 8039770'), $this->context());

        self::assertInstanceOf(RedirectResponse::class, $antwort);
    }

    /**
     * Was: Schalter aus, der Kunde hat keine Nummer, jemand schickt das Formular von Hand ab.
     * Warum: Ohne Pflicht gibt es nichts nachzureichen; der Endpunkt schreibt dann nicht.
     */
    public function testItSavesNothingWhenTheSwitchIsOff(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('update');

        $antwort = $this->controller($this->address(), $repository, switchOn: false)
            ->save($this->request('09568 8039770'), $this->context());

        self::assertInstanceOf(RedirectResponse::class, $antwort);
    }

    /**
     * @param EntityRepository<CustomerAddressCollection>|null $repository
     */
    private function controller(
        ?CustomerAddressEntity $address,
        ?EntityRepository $repository = null,
        bool $switchOn = true,
    ): PhoneNumberController {
        $requirement = $this->createMock(PhoneNumberRequirement::class);
        $requirement->method('addressWithoutNumber')->willReturn($address);
        $requirement->method('isMissing')->willReturn($address !== null && $switchOn);

        $controller = new PhoneNumberController(
            $requirement,
            new PhoneNumberInput(),
            $repository ?? $this->createMock(EntityRepository::class),
        );

        $controller->setContainer($this->container());

        return $controller;
    }

    private function container(): Container
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/checkout/confirm');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);

        $container = new Container();
        $container->set('router', $router);
        $container->set('translator', $translator);
        // Der StorefrontController legt seine Meldungen in die Sitzung der laufenden Anfrage.
        $container->set('request_stack', $stack);
        // Jede Weiterleitung wird als Ereignis gemeldet; ohne Verteiler bricht sie ab, bevor
        // die Antwort entsteht.
        $container->set('event_dispatcher', new EventDispatcher());

        return $container;
    }

    private function address(): CustomerAddressEntity
    {
        $address = new CustomerAddressEntity();
        $address->setId(Uuid::randomHex());

        return $address;
    }

    private function request(string $number): Request
    {
        return new Request([], ['phoneNumber' => $number]);
    }

    private function context(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sc-id');
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }
}
