<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCheckoutEnhancer\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Jede Klasse, jedes Interface, jedes Enum und jeder Trait trägt einen Docblock-Kopf, der sagt,
 * wofür es den Typ gibt.
 *
 * Geprüft wird über die Token des PHP-Parsers und nicht über Reflection: So muss keine Datei
 * geladen werden, und ein `//`-Kommentar zwischen Docblock und Typ fällt auf. Reflection sähe
 * den Docblock dann trotzdem, ein Leser aber hält ihn für den Kopf des Kommentars darunter.
 */
#[CoversNothing]
final class TypeHeadCommentTest extends TestCase
{
    /**
     * Was: Alle Typen unter `src/` und `tests/` tragen einen Kopf.
     * Warum: Ein Typ ohne Kopf zwingt den nächsten Leser, die Entscheidung dahinter aus dem Code
     *        zu erraten. Ohne Wächter kommt er beim nächsten neuen Typ unbemerkt zurück.
     * Erwartet: Keine Fundstelle; die Meldung nennt Datei und Typ.
     */
    #[Test]
    public function everyTypeCarriesAHeadComment(): void
    {
        $root = \dirname(__DIR__, 3);
        $missing = [];

        foreach (['src', 'tests'] as $directory) {
            foreach ($this->phpFiles($root . '/' . $directory) as $path) {
                foreach (self::typesWithoutHead((string) file_get_contents($path)) as $type) {
                    $missing[] = substr($path, \strlen($root) + 1) . ': ' . $type;
                }
            }
        }

        static::assertSame([], $missing, "Typen ohne Docblock-Kopf:\n" . implode("\n", $missing));
    }

    /**
     * Was: Gegenprobe an Quelltexten im Speicher, je einer mit und ohne gültigen Kopf.
     * Warum: Ein Wächter, der nichts findet, kann auch blind sein. Nur wenn er eine Datei ohne
     *        Kopf erkennt, bedeutet sein Grün im Bestand etwas.
     * Erwartet: Genau die Typen ohne Kopf werden gemeldet.
     *
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('sources')]
    public function checkerRecognisesMissingHeads(string $source, array $expected): void
    {
        static::assertSame($expected, self::typesWithoutHead($source));
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function sources(): iterable
    {
        yield 'Klasse ohne Kopf' => [
            "<?php\nfinal class Bare\n{\n}\n",
            ['Bare'],
        ];

        yield 'Klasse mit Kopf' => [
            "<?php\n/**\n * Kopf.\n */\nfinal class Headed\n{\n}\n",
            [],
        ];

        yield 'Kopf über Attributen und Modifikatoren' => [
            "<?php\n/** Kopf. */\n#[Attr(['a' => 1])]\n#[Other]\nabstract readonly class WithAttributes\n{\n}\n",
            [],
        ];

        yield 'Zeilenkommentar statt Kopf' => [
            "<?php\n// Nur eine Randnotiz.\nclass LineCommentOnly\n{\n}\n",
            ['LineCommentOnly'],
        ];

        yield 'Zeilenkommentar zwischen Kopf und Klasse' => [
            "<?php\n/** Kopf. */\n// Randnotiz.\nclass Separated\n{\n}\n",
            ['Separated'],
        ];

        yield 'Interface, Enum und Trait ohne Kopf' => [
            "<?php\ninterface Contract\n{\n}\nenum State\n{\n    case On;\n}\ntrait Shared\n{\n}\n",
            ['Contract', 'State', 'Shared'],
        ];

        yield '::class und anonyme Klasse zählen nicht' => [
            "<?php\n/** Kopf. */\nfinal class Owner\n{\n    public function f(): object\n    {\n        \$name = self::class;\n\n        return new class {};\n    }\n}\n",
            [],
        ];
    }

    /**
     * Die Namen aller Typen in der Quelle, vor denen kein Docblock steht. Zwischen Docblock und
     * Typ dürfen nur Leerraum, Attribute und Modifikatoren stehen.
     *
     * @return list<string>
     */
    private static function typesWithoutHead(string $source): array
    {
        $tokens = array_values(\PhpToken::tokenize($source));
        $missing = [];

        foreach ($tokens as $index => $token) {
            if (!$token->is([\T_CLASS, \T_INTERFACE, \T_ENUM, \T_TRAIT])) {
                continue;
            }

            $previous = self::previousSignificant($tokens, $index - 1);
            if ($previous !== null && $tokens[$previous]->is([\T_DOUBLE_COLON, \T_NEW])) {
                continue;
            }

            $name = self::nextName($tokens, $index + 1);
            // `enum` ist ein weiches Schlüsselwort; ohne folgenden Namen ist es keine Deklaration.
            if ($name === null) {
                continue;
            }

            if (!self::headPrecedes($tokens, $index - 1)) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private static function headPrecedes(array $tokens, int $index): bool
    {
        while ($index >= 0) {
            $token = $tokens[$index];

            if ($token->is([\T_WHITESPACE, \T_FINAL, \T_ABSTRACT, \T_READONLY])) {
                --$index;

                continue;
            }

            if ($token->text === ']') {
                $index = self::attributeStart($tokens, $index) - 1;

                continue;
            }

            return $token->is(\T_DOC_COMMENT);
        }

        return false;
    }

    /**
     * Läuft von der schließenden Klammer eines Attributs zurück bis zu seinem `#[`. Eckige
     * Klammern im Attribut selbst, etwa ein Array-Argument, werden mitgezählt.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function attributeStart(array $tokens, int $index): int
    {
        $depth = 0;

        for (; $index >= 0; --$index) {
            $token = $tokens[$index];

            if ($token->text === ']') {
                ++$depth;
            } elseif ($token->text === '[' || $token->is(\T_ATTRIBUTE)) {
                --$depth;
            }

            if ($depth === 0) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private static function previousSignificant(array $tokens, int $index): ?int
    {
        for (; $index >= 0; --$index) {
            if (!$tokens[$index]->isIgnorable()) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private static function nextName(array $tokens, int $index): ?string
    {
        $count = \count($tokens);

        for (; $index < $count; ++$index) {
            if ($tokens[$index]->is(\T_WHITESPACE)) {
                continue;
            }

            return $tokens[$index]->is(\T_STRING) ? $tokens[$index]->text : null;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
