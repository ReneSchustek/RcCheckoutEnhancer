<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCheckoutEnhancer\Service\EstimateInputValidator;

final class EstimateInputValidatorTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: ?string}>
     */
    public static function eingaben(): array
    {
        return [
            // Land, Postleitzahl, erwarteter Snippet-Schlüssel (null = brauchbar)
            'deutsche Postleitzahl' => ['DE', '44787', null],
            'dreistelliges Länderkürzel' => ['DEU', '44787', null],
            'niederländische Form mit Leerzeichen' => ['NL', '1234 AB', null],
            'britische Form' => ['GB', 'SW1A 1AA', null],
            'kanadische Form' => ['CA', 'K1A 0B1', null],
            'Bindestrich' => ['US', '12345-678', null],
            'genau die Längengrenze' => ['DE', '123456789012', null],

            'leeres Land' => ['', '44787', 'rc-checkout.shippingEstimate.errorCountry'],
            'Land einstellig' => ['D', '44787', 'rc-checkout.shippingEstimate.errorCountry'],
            'Land vierstellig' => ['DEUT', '44787', 'rc-checkout.shippingEstimate.errorCountry'],
            'Land kleingeschrieben' => ['de', '44787', 'rc-checkout.shippingEstimate.errorCountry'],
            'Land mit Ziffer' => ['D1', '44787', 'rc-checkout.shippingEstimate.errorCountry'],

            'leere Postleitzahl' => ['DE', '', 'rc-checkout.shippingEstimate.errorZipRequired'],

            'eine Stelle über der Grenze' => ['DE', '1234567890123', 'rc-checkout.shippingEstimate.errorZipInvalid'],
            'Sonderzeichen' => ['DE', '4478/7', 'rc-checkout.shippingEstimate.errorZipInvalid'],
            'spitze Klammer' => ['DE', '<script>', 'rc-checkout.shippingEstimate.errorZipInvalid'],
            'Umlaut' => ['DE', '44ö87', 'rc-checkout.shippingEstimate.errorZipInvalid'],
            'Zeilenumbruch' => ['DE', "447\n87", 'rc-checkout.shippingEstimate.errorZipInvalid'],
        ];
    }

    #[DataProvider('eingaben')]
    public function testValidate(string $countryIso, string $zipCode, ?string $expected): void
    {
        self::assertSame($expected, (new EstimateInputValidator())->validate($countryIso, $zipCode));
    }

    /**
     * Die Längengrenze zählt Zeichen, nicht Bytes. Ohne `mb_strlen` käme eine
     * Zeichenkette aus Mehrbyte-Zeichen früher durch die Grenze, als sie sollte —
     * die Prüfung wäre dann von der Kodierung abhängig statt von der Eingabe.
     */
    public function testLengthLimitCountsCharactersNotBytes(): void
    {
        $validator = new EstimateInputValidator();

        // 13 Mehrbyte-Zeichen: über der Grenze von 12, aber deutlich mehr Bytes.
        $tooLong = str_repeat('ä', 13);

        self::assertSame(
            'rc-checkout.shippingEstimate.errorZipInvalid',
            $validator->validate('DE', $tooLong),
        );
        self::assertGreaterThan(12, mb_strlen($tooLong));
    }
}
