<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function test_check_trimsAndCountsCharacters(): void
    {
        self::assertSame('Žluťoučký kůň', Text::check('The subject', " \tŽluťoučký kůň\r\n", 13));
        self::assertSame('a1-B2 /.', Text::check('The reference', ' a1-B2 /. ', 8, ascii: true));
    }

    #[DataProvider('refused')]
    public function test_check_refuses(string $value, bool $ascii, string $message): void
    {
        try {
            Text::check('The subject', $value, 10, $ascii);
            self::fail('InvalidArgumentException expected');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("The subject $message", $e->getMessage());
        }
    }

    public static function refused(): array
    {
        return [
            'not utf-8'   => ["a\xE8", false, 'is not plain UTF-8 text'],
            'nul'         => ["a\0b", false, 'is not plain UTF-8 text'],
            'inner tab'   => ["a\tb", false, 'is not plain UTF-8 text'],
            'escape'      => ["a\x1Bb", false, 'is not plain UTF-8 text'],
            'del'         => ["a\x7Fb", false, 'is not plain UTF-8 text'],
            'c1'          => ["a\u{9F}b", false, 'is not plain UTF-8 text'],
            'fffe'        => ["a\u{FFFE}", false, 'is not plain UTF-8 text'],
            'ffff'        => ["a\u{FFFF}", false, 'is not plain UTF-8 text'],
            'not ascii'   => ['č.j. 1', true, 'may only have letters, digits and signs of ASCII'],
            'too long'    => [str_repeat('ř', 11), false, 'is longer than 10 characters'],
        ];
    }

    // a lookup compares the subject sent with the one listed: ISDS replaced or dropped these on the way (1.3.5)
    #[DataProvider('readings')]
    public function test_isdsKey_sameForWhatIsdsMadeOfIt(string $sent, string $listed): void
    {
        self::assertSame(Text::isdsKey($listed), Text::isdsKey($sent));
    }

    public static function readings(): array
    {
        return [
            'no-break spaces made spaces' => ["Zastupitelstvo\u{00A0}obce\u{202F}2026", 'Zastupitelstvo obce 2026'],
            // 1.3.5 lists U+202F both as made a space and as dropped
            'U+202F dropped'              => ["obce\u{202F}2026", 'obce2026'],
            'line separators'             => ["a\u{2028}b\u{2029}c", 'a b c'],
            'soft hyphen'                 => ["po\u{00AD}zvánka", 'pozvánka'],
            'invisible'                   => ["a\u{200B}\u{200F}b\u{202A}\u{202E}c\u{2061}\u{206F}", 'abc'],
            'spaces around'               => ["\u{00A0}Pozvánka ", 'Pozvánka'],
        ];
    }

    public function test_isdsKey_keepsWhatIsdsKeeps(): void
    {
        self::assertSame('Žluťoučkýkůň–„úpěl“', Text::isdsKey("Žluťoučký kůň\t –  „úpěl“"));
        self::assertSame("a\u{2060}b", Text::isdsKey("a\u{2060}b"), 'U+2060 is not among the characters ISDS drops');
        self::assertNotSame(Text::isdsKey('Pozvánka'), Text::isdsKey('Pozvánka.'));
    }
}
