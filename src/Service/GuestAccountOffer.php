<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Merkt sich nach der Bestellung eines Gasts, wem das Angebot „Kundenkonto anlegen" gilt.
 *
 * Shopware meldet den Gast auf der Abschlussseite ab, wenn `core.cart.logoutGuestAfterCheckout`
 * eingeschaltet ist; danach kennt der Kontext ihn nicht mehr, und das Angebot des Kerns erscheint
 * nicht. Die Abmeldung bleibt bewusst bestehen: An einem geteilten Rechner sähe sonst der Nächste
 * Bestellung und Adresse. Statt des angemeldeten Gasts trägt deshalb die Sitzung, für wen das
 * Kennwort gilt.
 *
 * Die Kennung des Kunden kommt aus dieser Sitzung, nie aus der Anfrage; sonst ließe sich über den
 * Endpunkt ein fremdes Gastkonto übernehmen. Das Angebot gilt 30 Minuten und einmal: lang genug für
 * ein Kennwort, kurz genug, dass ein später an denselben Rechner Kommender nichts mehr vorfindet.
 */
class GuestAccountOffer
{
    public const SESSION_KEY = 'rc_checkout_enhancer.guest_account_offer';

    public const LIFETIME_SECONDS = 1800;

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function remember(string $customerId, string $email, DateTimeImmutable $now): void
    {
        $this->requestStack->getSession()->set(self::SESSION_KEY, [
            'customerId' => $customerId,
            'email' => $email,
            'expiresAt' => $now->getTimestamp() + self::LIFETIME_SECONDS,
        ]);
    }

    /**
     * Das gültige Angebot, oder `null`. Ein abgelaufenes wird dabei verworfen.
     *
     * @return array{customerId: string, email: string}|null
     */
    public function pending(DateTimeImmutable $now): ?array
    {
        $offer = $this->requestStack->getSession()->get(self::SESSION_KEY);
        if (!\is_array($offer)
            || !\is_string($offer['customerId'] ?? null)
            || !\is_string($offer['email'] ?? null)
            || !\is_int($offer['expiresAt'] ?? null)) {
            return null;
        }

        if ($offer['expiresAt'] < $now->getTimestamp()) {
            $this->forget();

            return null;
        }

        return ['customerId' => $offer['customerId'], 'email' => $offer['email']];
    }

    public function forget(): void
    {
        $this->requestStack->getSession()->remove(self::SESSION_KEY);
    }
}
