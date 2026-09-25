<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Module;
use AA\Util\Langs;

/** The charset of a module and the conversion of its texts to UTF-8 (design balicek-v1.md § 2.4).
 *
 *  Strict iconv, not ConvertCharset: that one has no table for EUC-JP (then exit()s) and drops undefined
 *  bytes silently (design § 15, C4.3(3)). */
final class ModuleCharset
{
    /** module.type => the type of Langs::getFiles() - the list the import checks lang_file against */
    private const LANG_TYPES = ['S' => 'news', 'W' => 'site', 'Alerts' => 'alerts'];

    /** an old `.php3` lang_file read as `.php` (as modules/alerts/modedit.php:168) */
    public static function langFile(string $file): string
    {
        return str_ends_with($file, '.php3') ? substr($file, 0, -1) : $file;
    }

    /** true: the lang_file (`.php3` read as `.php`) is one a module of the type may have (§ 2.4) */
    public static function accepts(string $type, string $file): bool
    {
        return isset(self::langFiles($type)[self::langFile($file)]);
    }

    /** the lang files a module of the type may have (module.type S/W/Alerts or the package wrapper) -
     *  Langs::getFiles(<type>); [] for another type @return array<string, string> file => name */
    public static function langFiles(string $type): array
    {
        $type = self::langType($type);
        return ($type === null) ? [] : Langs::getFiles($type);
    }

    /** The lang_file a package carries for the module and its charset - the one AA uses at run time,
     *  Langs::getCharset() of the language code before the first `_` (AA_Module::getCharset()).
     *  An old `.php3` name loses its `3` (as modules/alerts/modedit.php:168); a known language with a
     *  file off the list of its type (Langs::getFiles(<type>), what the import accepts) maps to the
     *  language's file of the type. Null = an unknown language: the charset is unknown.
     *  @param array<string, mixed> $module module row (type, lang_file)
     *  @return array{string, string}|null [lang_file, charset] */
    public static function ofModule(array $module): ?array
    {
        $file = self::langFile((string) ($module['lang_file'] ?? ''));
        $type = self::langType((string) ($module['type'] ?? ''));
        $lang = substr($file, 0, (int) strpos($file . '_', '_'));
        if (($type === null) || !isset(Langs::getNames()[$lang])) {
            return null;
        }
        return [isset(self::langFiles($module['type'])[$file]) ? $file : "{$lang}_{$type}_lang.php", Langs::getCharset($lang)];
    }

    /** the Langs type of a module.type or a package wrapper */
    private static function langType(string $type): ?string
    {
        return self::LANG_TYPES[array_search($type, Module::TYPES, true) ?: $type] ?? null;
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

    /** charsets fromUtf8() converts to: stateless ASCII supersets (an ASCII byte is always that
     *  character, never part of another) - the rewrite of references on UTF-8 before the conversion and
     *  on RawBytes relies on it. Langs has exactly these; a new one (Shift_JIS, GBK, Big5, ISO-2022-JP:
     *  ASCII bytes inside characters or shift states) fails closed until it is checked and added. */
    public const STATELESS = ['utf-8', 'windows-1250', 'windows-1251', 'iso-8859-1', 'iso-8859-2', 'euc-jp'];

    /** The UTF-8 value in the charset (§ 2.4, import): strict iconv (no //IGNORE, no //TRANSLIT) and
     *  a round trip back to the same UTF-8. Null = the charset cannot hold the value, the value is no
     *  UTF-8, or the charset is not in STATELESS. ASCII is the same in every STATELESS charset and stays
     *  as it is; a text that converts still converts after ASCII runs in it were replaced by other ASCII
     *  (the reference rewrite - TargetCharset). */
    public static function fromUtf8(string $value, string $charset): ?string
    {
        $charset = strtolower($charset);
        if (!in_array($charset, self::STATELESS, true) || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        if (($charset === 'utf-8') || mb_check_encoding($value, 'ASCII')) {
            return $value;
        }
        $bytes = @iconv('UTF-8', $charset, $value);     // false on any loss; the notice is the finding
        return (($bytes !== false) && (@iconv($charset, 'UTF-8', $bytes) === $value)) ? $bytes : null;
    }

    /** byte offset of the first character of the UTF-8 value the charset cannot hold (fromUtf8()) - of
     *  the first invalid byte when the value is no UTF-8; 0 for a charset off STATELESS; null = it
     *  converts. A binary search on prefixes (a prefix that fails fails longer, the charsets being
     *  stateless): O(n log n), no array of characters. */
    public static function unconvertible(string $value, string $charset): ?int
    {
        if (self::fromUtf8($value, $charset) !== null) {
            return null;
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            return ValueCodec::encode($value)['finding']->offset ?? 0;
        }
        if (!in_array(strtolower($charset), self::STATELESS, true)) {
            return 0;
        }
        [$lo, $hi] = [0, strlen($value)];      // the prefix of $lo bytes converts, of $hi bytes not
        while (($next = $lo + self::charLength($value[$lo])) < $hi) {
            $mid = max($next, strlen(mb_strcut($value, 0, intdiv($lo + $hi, 2), 'UTF-8')));
            if (self::fromUtf8(substr($value, 0, $mid), $charset) !== null) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    /** bytes of the UTF-8 character with this lead byte */
    private static function charLength(string $lead): int
    {
        $byte = ord($lead);
        return ($byte < 0xC0) ? 1 : (($byte < 0xE0) ? 2 : (($byte < 0xF0) ? 3 : 4));
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
