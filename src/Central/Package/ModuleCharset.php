<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Util\Langs;

/** The charset of a module and the conversion of its texts to UTF-8 (design balicek-v1.md § 2.4).
 *
 *  Deviation from § 2.4 ("ConvertCharset as .aaxml"): strict iconv. ConvertCharset has no table for
 *  EUC-JP (MakeConvertTable() then calls exit), drops undefined bytes silently and is ~250x slower;
 *  iconv gives the same characters for windows-1250/1251 and iso-8859-1/2 (checked by the review). */
final class ModuleCharset
{
    /** module.type => the type of Langs::getFiles() - the list the import checks lang_file against */
    private const LANG_TYPES = ['S' => 'news', 'W' => 'site', 'Alerts' => 'alerts'];

    /** The lang_file a package carries for the module and its charset - the one AA uses at run time,
     *  Langs::getCharset() of the language code before the first `_` (AA_Module::getCharset()).
     *  An old `.php3` name loses its `3` (as modules/alerts/modedit.php:168); a known language with a
     *  file off the list of its type (Langs::getFiles(<type>), what the import accepts) maps to the
     *  language's file of the type. Null = an unknown language: the charset is unknown.
     *  @param array<string, mixed> $module module row (type, lang_file)
     *  @return array{string, string}|null [lang_file, charset] */
    public static function ofModule(array $module): ?array
    {
        $file = (string) ($module['lang_file'] ?? '');
        $file = str_ends_with($file, '.php3') ? substr($file, 0, -1) : $file;
        $type = self::LANG_TYPES[$module['type'] ?? ''] ?? null;
        $lang = substr($file, 0, (int) strpos($file . '_', '_'));
        if (($type === null) || !isset(Langs::getNames()[$lang])) {
            return null;
        }
        $files = Langs::getFiles($type);
        return [isset($files[$file]) ? $file : "{$lang}_{$type}_lang.php", Langs::getCharset($lang)];
    }

    /** The value in UTF-8. A value the charset cannot hold (a byte it does not define, broken UTF-8,
     *  a charset iconv does not know) stays as it is, with a finding at the byte that failed - also when
     *  the raw bytes happen to be valid UTF-8, which no later check would notice (Collector wraps such
     *  a value in Model\RawBytes, so it travels as base64).
     *  @return array{string, ?LintFinding} */
    public static function toUtf8(string $value, string $charset): array
    {
        if (($charset === 'utf-8') ? mb_check_encoding($value, 'UTF-8') : mb_check_encoding($value, 'ASCII')) {
            return [$value, null];
        }
        if ($charset === 'utf-8') {
            return [$value, ValueCodec::encode($value)['finding']];
        }
        $utf = @iconv($charset, 'UTF-8', $value);     // no //IGNORE, no //TRANSLIT: false on any loss; the notice is the finding
        if ($utf !== false) {
            return [$utf, null];
        }
        $offset = strcspn($value, self::undefinedBytes($charset));
        return [$value, new LintFinding(LintFinding::WARNING, ValueCodec::INVALID_BYTES, substr($value, $offset, ValueCodec::REF_BYTES), $offset)];
    }

    /** The bytes the charset does not define on their own - exact for the single-byte charsets of
     *  Langs; for EUC-JP every lead byte, so the offset is the first non-ASCII character there. */
    private static function undefinedBytes(string $charset): string
    {
        static $undefined = [];
        return $undefined[$charset] ??= implode('', array_filter(array_map('chr', range(0, 255)), static fn(string $c): bool => @iconv($charset, 'UTF-8', $c) === false));
    }

    /** false = this iconv converts windows-1250 wrongly (a broken or stub iconv): the export must stop */
    public static function works(): bool
    {
        static $works = null;
        return $works ??= (@iconv('windows-1250', 'UTF-8', "\x8A") === 'Š');
    }
}
