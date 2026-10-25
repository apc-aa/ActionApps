<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA\Security\RequestCharset;
use ConvertCharset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../include/convert_charset.class.php';

/**
 * A request may name the charset of a view, poll or form (convertfrom, convertto); AA takes only
 * charsets in which ASCII stays ASCII (AA\Security\RequestCharset), both in ConvertCharset (views,
 * polls) and in iconv (filler.php), and in which ConvertCharset makes no markup character of other
 * text. iconv may (//TRANSLIT turns “ into "), but it converts only form input, whose sender can
 * write those characters himself.
 */
final class ConvertCharsetAsciiTest extends TestCase
{
    private static function ascii(): string
    {
        return implode('', array_map('chr', range(0, 127)));
    }

    #[DataProvider('asciiCharsets')]
    public function test_Convert_keepsAscii(string $charset): void
    {
        $encoder = new ConvertCharset();

        self::assertSame(self::ascii(), $encoder->Convert(self::ascii(), $charset, 'utf-8'));
        self::assertSame(self::ascii(), $encoder->Convert(self::ascii(), 'utf-8', $charset));
        self::assertSame(self::ascii(), $encoder->Convert(self::ascii(), strtoupper($charset), 'UTF-8'), 'the name in any case');
    }

    #[DataProvider('asciiCharsets')]
    public function test_iconv_keepsAscii(string $charset): void
    {
        self::assertSame(self::ascii(), iconv($charset, 'UTF-8//TRANSLIT', self::ascii()));
        self::assertSame(self::ascii(), iconv('UTF-8', $charset . '//TRANSLIT', self::ascii()));
    }

    /** text without ASCII, rich in look-alikes of the markup characters */
    private const LOOK_ALIKES = 'Příliš žluťoučký kůň ‹a› «b» “c” „d“ ‘e’ ‚f‘ ＜g＞ ＂h＂ ＇i＇ ＆j ′k″ ˂l˃ ❮n❯ 〈o〉 ⟨p⟩ ﹤q﹥ ˮs ´t` € — … ŠĐČĆŽ АБВ αβγ אבג ابت ไทย';

    #[DataProvider('asciiCharsets')]
    public function test_Convert_makesNoMarkupCharacter(string $charset): void
    {
        $high = implode('', array_map('chr', range(128, 255)));   // every byte of the charset outside ASCII
        ob_start();   // a character missing in the table is reported on the output
        try {
            $to_charset = (new ConvertCharset())->Convert(self::LOOK_ALIKES, 'utf-8', $charset);
            $to_utf     = (new ConvertCharset())->Convert($high, $charset, 'utf-8');
        } finally {
            ob_end_clean();
        }

        self::assertDoesNotMatchRegularExpression('/[<>"\'&]/', $to_charset);
        self::assertDoesNotMatchRegularExpression('/[<>"\'&]/', $to_utf);
    }

    /** @return array<string, array{string}> */
    public static function asciiCharsets(): array
    {
        return array_combine(RequestCharset::ALLOWED, array_map(static fn(string $c): array => [$c], RequestCharset::ALLOWED));
    }

    /** an EBCDIC table gives other bytes the meaning of ASCII ones - why such charsets are left out */
    public function test_Convert_ebcdicChangesAscii(): void
    {
        // @: the cp037 table has a line without a second column
        self::assertSame('<', @(new ConvertCharset())->Convert("\x4C", 'cp037', 'utf-8'));
    }
}
