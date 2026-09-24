<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package;

use AA\Central\Package\LintFinding;
use AA\Central\Package\ModuleCharset;
use AA\Central\Package\ValueCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleCharsetTest extends TestCase
{
    /** @return array<string, array{string, string, string}> value, charset, UTF-8 */
    public static function conversions(): array
    {
        return [
            'windows-1250' => ["P\xF8\xEDli\x9A \x8Ae", 'windows-1250', 'Příliš Še'],
            'windows-1251' => ["\xCF\xF0\xE8", 'windows-1251', 'При'],
            'iso-8859-2'   => ["\xA9koda", 'iso-8859-2', 'Škoda'],
            'EUC-JP'       => ["\xA4\xA2", 'EUC-JP', 'あ'],
            'utf-8'        => ['Příliš', 'utf-8', 'Příliš'],
            'ascii'        => ["a\r\nb\x07", 'windows-1250', "a\r\nb\x07"],
        ];
    }

    #[DataProvider('conversions')]
    public function test_toUtf8_converts_from_the_module_charset(string $value, string $charset, string $expected): void
    {
        $this->assertSame([$expected, null], ModuleCharset::toUtf8($value, $charset));
    }

    /** @return array<string, array{string, string}> value, charset - kept as it is, with a finding */
    public static function lossy(): array
    {
        return [
            'byte undefined in windows-1250'         => ["a\x81b", 'windows-1250'],
            'undefined, yet the raw value is UTF-8'  => ["\xC3\x81", 'windows-1250'],     // "Á" in UTF-8 - no reader would notice
            'broken utf-8'                           => ["a\xC3", 'utf-8'],
            'charset iconv does not know'            => ["\xE1", 'x-no-such-charset'],
        ];
    }

    #[DataProvider('lossy')]
    public function test_toUtf8_keeps_the_raw_bytes_of_a_value_it_cannot_convert(string $value, string $charset): void
    {
        [$utf, $finding] = ModuleCharset::toUtf8($value, $charset);

        $this->assertSame($value, $utf);
        $this->assertInstanceOf(LintFinding::class, $finding);
        $this->assertSame([LintFinding::WARNING, ValueCodec::INVALID_BYTES], [$finding->level, $finding->code]);
    }

    public function test_iconv_passes_the_self_test(): void
    {
        $this->assertTrue(ModuleCharset::works());
    }

    /** @return array<string, array{string, string, ?array{string, string}}> type, lang_file, [lang_file as carried, charset] (null = unknown) */
    public static function langFiles(): array
    {
        return [
            'utf-8 slice'              => ['S', 'cz-utf8_news_lang.php', ['cz-utf8_news_lang.php', 'utf-8']],
            'windows-1250 slice'       => ['S', 'cz_news_lang.php', ['cz_news_lang.php', 'windows-1250']],
            'site'                     => ['W', 'en-utf8_site_lang.php', ['en-utf8_site_lang.php', 'utf-8']],
            'alerts'                   => ['Alerts', 'sk_alerts_lang.php', ['sk_alerts_lang.php', 'windows-1250']],
            // most of production aadb.module: the old .php3 names (modules/alerts/modedit.php:168 trims them)
            'php3 slice'               => ['S', 'cz-utf8_news_lang.php3', ['cz-utf8_news_lang.php', 'utf-8']],
            'php3 site'                => ['W', 'cz_site_lang.php3', ['cz_site_lang.php', 'windows-1250']],
            'php3 iso-8859-2'          => ['S', 'hu_news_lang.php3', ['hu_news_lang.php', 'iso-8859-2']],
            // a known language, the file of another type: AA takes the charset from the language code
            'language of another type' => ['S', 'cz-utf8_site_lang.php', ['cz-utf8_news_lang.php', 'utf-8']],
            'odd name'                 => ['Alerts', 'en_polls_lang.inc', ['en_alerts_lang.php', 'iso-8859-1']],
            'unknown language'         => ['S', 'xx_news_lang.php', null],
            'not a module of v1'       => ['P', 'en_polls_lang.php', null],
            'empty'                    => ['S', '', null],
        ];
    }

    #[DataProvider('langFiles')]
    public function test_ofModule_normalises_the_lang_file_of_the_type(string $type, string $lang_file, ?array $expected): void
    {
        $this->assertSame($expected, ModuleCharset::ofModule(['type' => $type, 'lang_file' => $lang_file]));
    }

    public function test_accepts_the_lang_files_of_the_type(): void
    {
        $this->assertTrue(ModuleCharset::accepts('slice-module', 'cz-utf8_news_lang.php'));
        $this->assertTrue(ModuleCharset::accepts('S', 'cz-utf8_news_lang.php3'), '.php3 read as .php');
        $this->assertTrue(ModuleCharset::accepts('alerts-module', 'en_alerts_lang.php'));
        $this->assertFalse(ModuleCharset::accepts('slice-module', 'cz-utf8_site_lang.php'), 'another type');
        $this->assertFalse(ModuleCharset::accepts('site-module', ''));
        $this->assertFalse(ModuleCharset::accepts('P', 'cz_news_lang.php'), 'no type of v1');
    }

    /** @return array<string, array{string, string, int}> value, charset, offset of the byte that failed */
    public static function failingBytes(): array
    {
        return [
            'windows-1250' => ["ab\x81cd\x81", 'windows-1250', 2],
            'first byte'   => ["\x98x", 'windows-1250', 0],
            'after a good one' => ["\x8A\x81", 'windows-1250', 1],
            'utf-8'        => ["žž\xC3", 'utf-8', 4],
        ];
    }

    #[DataProvider('failingBytes')]
    public function test_the_finding_points_at_the_byte_that_failed(string $value, string $charset, int $offset): void
    {
        $finding = ModuleCharset::toUtf8($value, $charset)[1];

        $this->assertSame([$offset, substr($value, $offset, 16)], [$finding?->offset, $finding?->ref]);
    }

    /** @return array<string, array{string, string, ?string}> UTF-8 value, charset, the bytes (null = cannot hold) */
    public static function fromUtf8Cases(): array
    {
        return [
            'windows-1250'            => ['Příliš Še', 'windows-1250', "P\xF8\xEDli\x9A \x8Ae"],
            'iso-8859-2'              => ['Škoda', 'iso-8859-2', "\xA9koda"],
            'EUC-JP'                  => ['あ', 'EUC-JP', "\xA4\xA2"],
            'utf-8 is a no-op'        => ['€ 漢', 'utf-8', '€ 漢'],
            'ascii in any charset'    => ["a\r\nb\x07", 'windows-1251', "a\r\nb\x07"],
            'euro sign in iso-8859-2' => ['cena 5 €', 'iso-8859-2', null],
            'CJK in windows-1250'     => ['漢字', 'windows-1250', null],
            'cyrillic in iso-8859-1'  => ['При', 'iso-8859-1', null],
            'no UTF-8'                => ["a\xE1", 'windows-1250', null],
            'broken UTF-8 into utf-8' => ["a\xC3", 'utf-8', null],
            'unknown charset'         => ['á', 'x-no-such-charset', null],
            'upper-case charset name' => ['Škoda', 'ISO-8859-2', "\xA9koda"],
            // not stateless ASCII supersets: ASCII bytes inside characters or shift states - fail closed
            'Shift_JIS'               => ['abc', 'Shift_JIS', null],
            'GBK'                     => ['abc', 'GBK', null],
            'Big5'                    => ['abc', 'Big5', null],
            'ISO-2022-JP'             => ['あ', 'ISO-2022-JP', null],
        ];
    }

    #[DataProvider('fromUtf8Cases')]
    public function test_fromUtf8_converts_strictly_with_a_round_trip(string $value, string $charset, ?string $expected): void
    {
        $this->assertSame($expected, ModuleCharset::fromUtf8($value, $charset));
    }

    public function test_unconvertible_points_at_the_first_character_the_charset_lacks(): void
    {
        $this->assertSame(7, ModuleCharset::unconvertible('Škoda €5 漢', 'iso-8859-2'));
        $this->assertNull(ModuleCharset::unconvertible('Škoda', 'iso-8859-2'));
        $this->assertSame(1, ModuleCharset::unconvertible("a\xE1", 'windows-1250'), 'no UTF-8: the first bad byte');
        $this->assertSame(0, ModuleCharset::unconvertible('€', 'iso-8859-2'));
        $this->assertSame(0, ModuleCharset::unconvertible('abc', 'Shift_JIS'), 'a charset off the list');
        $this->assertSame(12, ModuleCharset::unconvertible('ščřžýá€€', 'iso-8859-2'), 'after six two-byte characters');
    }

    public function test_unconvertible_finds_the_character_in_a_long_value(): void
    {
        $value = str_repeat('Příliš ', 100000) . '漢' . str_repeat('x', 1000);
        $this->assertSame(strlen(str_repeat('Příliš ', 100000)), ModuleCharset::unconvertible($value, 'windows-1250'));
        $this->assertSame(1, ModuleCharset::unconvertible('a€', 'iso-8859-1'));
    }
}
