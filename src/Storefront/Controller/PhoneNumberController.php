<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Storefront\Controller;

use Ruhrcoder\RcCheckoutEnhancer\Service\PhoneNumberInput;
use Ruhrcoder\RcCheckoutEnhancer\Service\PhoneNumberRequirement;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Nimmt die nachgereichte Telefonnummer entgegen und schreibt sie in die gespeicherte Adresse.
 *
 * In die Kundenadresse und nicht nur an die Bestellung, weil die Bestelladresse eine Kopie ist,
 * die mit der Bestellung endet. Bliebe die gespeicherte Adresse ohne Nummer, stünde der Kunde
 * bei jeder Folgebestellung wieder vor derselben Sperre.
 *
 * Die Kennung der Adresse kommt vom Server, aus dem angemeldeten Kontext
 * ({@see PhoneNumberRequirement}). Nähme der Endpunkt eine mitgeschickte Kennung entgegen, ließe
 * sich damit die Adresse eines fremden Kunden beschreiben.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class PhoneNumberController extends StorefrontController
{
    /**
     * @param EntityRepository<CustomerAddressCollection> $customerAddressRepository
     */
    public function __construct(
        private readonly PhoneNumberRequirement $requirement,
        private readonly PhoneNumberInput $input,
        private readonly EntityRepository $customerAddressRepository,
    ) {
    }

    #[Route(
        path: '/rc-checkout/phone-number',
        name: 'frontend.rc-checkout.phone-number',
        methods: ['POST'],
    )]
    public function save(Request $request, SalesChannelContext $context): Response
    {
        $address = $this->requirement->isMissing($context)
            ? $this->requirement->addressWithoutNumber($context)
            : null;

        // Nichts zu tun: Entweder ist der Schalter aus, oder die Nummer steht längst da, etwa
        // weil der Kunde das Formular zweimal abgeschickt hat. Beides ist kein Fehler, und eine
        // rote Meldung dafür verwirrte nur.
        if ($address === null) {
            return $this->redirectToRoute('frontend.checkout.confirm.page');
        }

        // Ein Feld lässt sich von Hand als Liste absenden; nur eine Zeichenkette wird überhaupt
        // geprüft.
        $raw = $request->request->get('phoneNumber');
        $number = $this->input->normalize(\is_string($raw) ? $raw : null);

        if ($number === null) {
            $this->addFlash(self::DANGER, $this->trans('rcCheckout.phoneNumberInvalid'));

            return $this->redirectToRoute('frontend.checkout.confirm.page');
        }

        $this->customerAddressRepository->update(
            [['id' => $address->getId(), 'phoneNumber' => $number]],
            $context->getContext(),
        );

        // Die geladene Adresse im Kontext trägt die Nummer noch nicht. Ohne dieses Nachziehen
        // stünde die Sperre auf der Seite, auf die gerade umgeleitet wird, noch einmal da, und
        // der Kunde bekäme nach der Eingabe dieselbe Aufforderung zurück.
        $address->setPhoneNumber($number);

        $this->addFlash(self::SUCCESS, $this->trans('rcCheckout.phoneNumberSaved'));

        return $this->redirectToRoute('frontend.checkout.confirm.page');
    }
}
