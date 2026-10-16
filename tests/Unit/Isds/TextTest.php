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
}
