<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Trägt die Warenkorb-Zusammenfassung von der Bestätigungsseite zum Kontaktformular.
 *
 * **Über die Sitzung und nicht über die Adresszeile.** Zwei Gründe, jeder für sich
 * ausreichend: Was jemand kaufen will, gehört nicht in eine URL, die in Verläufen,
 * Zugriffsprotokollen und Verweis-Kopfzeilen landet. Und ein Warenkorb mit zwanzig
 * Positionen sprengt jede Adresslänge.
 *
 * Kein zusätzlicher Einwilligungsfall nach § 25 TDDDG: Shopware führt für den Warenkorb
 * ohnehin eine Sitzung — dieselbe Linie wie beim Speicher der Versandauskunft.
 *
 * Der Eintrag wird beim Lesen entfernt. Er gilt für genau einen Weg vom Bestellvorgang
 * zum Formular; bliebe er stehen, fände ihn der Kunde beim nächsten Besuch der
 * Kontaktseite wieder vor — mit einem Warenkorb, den es womöglich nicht mehr gibt.
 */
// Bewusst nicht `final`: Subscriber und Controller werden gegen Test-Doubles geprüft.
class ShippingEnquiryStore
{
    private const SESSION_KEY = 'rcCheckoutShippingEnquiry';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function remember(string $summary): void
    {
        $this->requestStack->getSession()->set(self::SESSION_KEY, $summary);
    }

    /**
     * Gibt die Zusammenfassung zurück und vergisst sie im selben Zug.
     */
    public function take(): ?string
    {
        $session = $this->requestStack->getSession();

        $summary = $session->get(self::SESSION_KEY);
        $session->remove(self::SESSION_KEY);

        return \is_string($summary) && $summary !== '' ? $summary : null;
    }
}
