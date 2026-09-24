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

    /** @return array<string, array{string, string, ?string}> type, lang_file, charset (null = not allowed) */
    public static function langFiles(): array
    {
        return [
            'utf-8 slice'           => ['S', 'cz-utf8_news_lang.php', 'utf-8'],
            'windows-1250 slice'    => ['S', 'cz_news_lang.php', 'windows-1250'],
            'site'                  => ['W', 'en-utf8_site_lang.php', 'utf-8'],
            'alerts'                => ['Alerts', 'sk_alerts_lang.php', 'windows-1250'],
            'unknown language'      => ['S', 'xx_news_lang.php', null],
            'other module type'     => ['S', 'cz-utf8_site_lang.php', null],
            'not a module of v1'    => ['P', 'en_polls_lang.php', null],
            'empty'                 => ['S', '', null],
        ];
    }

    #[DataProvider('langFiles')]
    public function test_ofModule_takes_only_the_lang_files_of_the_type(string $type, string $lang_file, ?string $charset): void
    {
        $this->assertSame($charset, ModuleCharset::ofModule(['type' => $type, 'lang_file' => $lang_file]));
    }
}
