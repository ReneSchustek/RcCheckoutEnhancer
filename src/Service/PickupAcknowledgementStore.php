<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Hält die Bestätigung des Abhol-Hinweises für die Dauer der Sitzung.
 *
 * In der Sitzung und nicht am Warenkorb, weil die Bestätigung eine Aussage des Menschen vor dem
 * Bildschirm ist und keine Eigenschaft des Warenkorbs. Sie überlebt kein Gerät und keine zweite
 * Sitzung; wer neu anfängt, bestätigt neu.
 *
 * Gespeichert wird der Wortlaut und kein Häkchen. Der Hinweistext steht in der Konfiguration und
 * kann sich ändern, und ein gespeichertes „bestätigt" sagte danach nicht mehr, was bestätigt
 * wurde. Eine Bestätigung gilt deshalb nur für genau diesen Wortlaut; ändert der Betreiber den
 * Text, während ein Kunde im Bestellvorgang steht, ist erneut zu bestätigen.
 */
final class PickupAcknowledgementStore
{
    public const SESSION_KEY = 'rc_checkout_enhancer.pickup_acknowledgement';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    /**
     * Hält die Bestätigung fest. `false`, wenn es keine Sitzung gibt.
     *
     * Ohne Sitzung ließe sich die Bestätigung später weder prüfen noch an die Bestellung
     * hängen. Der Aufrufer behandelt den Fehlschlag, statt dem Kunden einen Erfolg zu melden,
     * den es nicht gibt.
     */
    public function acknowledge(string $hint, \DateTimeImmutable $at): bool
    {
        $session = $this->session();
        if ($session === null) {
            return false;
        }

        $session->set(self::SESSION_KEY, [
            'hint' => $hint,
            'at' => $at->format(\DateTimeInterface::ATOM),
        ]);

        return true;
    }

    /**
     * Ist genau dieser Wortlaut bestätigt? Ein anderer, früher bestätigter zählt nicht.
     */
    public function isAcknowledged(string $hint): bool
    {
        $stored = $this->stored();

        return $stored !== null && $stored['hint'] === $hint;
    }

    /**
     * Die festgehaltene Bestätigung, wie sie an die Bestellung wandert.
     *
     * @return array{hint: string, at: string}|null
     */
    public function stored(): ?array
    {
        $session = $this->session();
        if ($session === null) {
            return null;
        }

        $raw = $session->get(self::SESSION_KEY);
        if (!\is_array($raw) || !\is_string($raw['hint'] ?? null) || !\is_string($raw['at'] ?? null)) {
            return null;
        }

        return ['hint' => $raw['hint'], 'at' => $raw['at']];
    }

    public function clear(): void
    {
        $this->session()?->remove(self::SESSION_KEY);
    }

    private function session(): ?SessionInterface
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }

        return $request->getSession();
    }
}
