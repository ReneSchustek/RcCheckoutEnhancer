<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\PhoneNumberInput;

/**
 * Was als Telefonnummer durchgeht — und was nicht.
 *
 * Der teurere Fehler ist hier die zu strenge Prüfung. Wer eine gültige Nummer ablehnt, hält
 * einen Kunden auf, der gerade bestellen wollte, und bekommt trotzdem keine bessere Nummer. Die
 * Grenze verläuft deshalb nicht an der Schreibweise, sondern daran, ob überhaupt eine Rufnummer
 * dasteht: genug Ziffern, und nichts darin, was in einer Nummer nichts zu suchen hat.
 */
final class PhoneNumberInputTest extends TestCase
{
    /**
     * Was: Die Schreibweisen, die Menschen tatsächlich eintippen.
     * Warum: Klammern, Schrägstriche und Landesvorwahlen sind der Normalfall, nicht die
     *        Ausnahme — jede abgelehnte davon ist eine verlorene Bestellung.
     */
    #[DataProvider('validNumbers')]
    public function testItAcceptsHowPeopleActuallyWriteNumbers(string $eingabe, string $erwartet): void
    {
        self::assertSame($erwartet, (new PhoneNumberInput())->normalize($eingabe));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validNumbers(): array
    {
        return [
            'schlicht' => ['09568 8039770', '09568 8039770'],
            'mit Landesvorwahl' => ['+49 9568 8039770', '+49 9568 8039770'],
            'mit Klammern' => ['(09568) 8039770', '(09568) 8039770'],
            'mit Schrägstrich' => ['09568/8039770', '09568/8039770'],
            'mit Durchwahl' => ['09568 8039770-12', '09568 8039770-12'],
            'ohne Trennung' => ['095688039770', '095688039770'],
            'Schweizer Punkte' => ['+41 44.123.45.67', '+41 44.123.45.67'],
            'mit Rand-Leerzeichen' => ['  09568 8039770  ', '09568 8039770'],
            'mit doppelten Leerzeichen' => ['09568   8039770', '09568 8039770'],
            'mit Zeilenumbruch aus der Zwischenablage' => ["09568\n8039770", '09568 8039770'],
        ];
    }

    /**
     * Was: Alles, was keine Rufnummer ist.
     * Warum: Der Sinn der Prüfung. Steht später „bitte per Mail" im Nummernfeld, glaubt der
     *        Sachbearbeiter, er könne anrufen — und die Sendung bleibt liegen.
     */
    #[DataProvider('invalidInputs')]
    public function testItRefusesWhatIsNotANumber(?string $eingabe): void
    {
        self::assertNull((new PhoneNumberInput())->normalize($eingabe));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function invalidInputs(): array
    {
        return [
            'nichts geschickt' => [null],
            'leer' => [''],
            'nur Leerzeichen' => ['   '],
            'zu wenige Ziffern' => ['123'],
            'ein Satz' => ['bitte per Mail melden'],
            'Buchstaben in der Nummer' => ['09568 80397 Zentrale'],
            'eine Mailadresse' => ['kunde@example.test'],
            'länger als die Spalte des Kerns' => [str_repeat('9', 41)],
        ];
    }

    /**
     * Was: Genau die Grenze zwischen zu kurz und lang genug.
     * Warum: Eine Grenze, die nur ungefähr geprüft ist, verschiebt sich beim nächsten Umbau,
     *        ohne dass es jemand merkt.
     */
    public function testItDrawsTheLineAtFiveDigits(): void
    {
        $input = new PhoneNumberInput();

        self::assertNull($input->normalize('1234'));
        self::assertSame('12345', $input->normalize('12345'));
    }
}
