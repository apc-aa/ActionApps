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

    /** The charset of the module's lang_file; null when lang_file is not on the allowlist of its type
     *  (the import would reject it and AA would fall back to the installation default at run time).
     *  @param array<string, mixed> $module module row (type, lang_file) */
    public static function ofModule(array $module): ?string
    {
        $file = (string) ($module['lang_file'] ?? '');
        $type = self::LANG_TYPES[$module['type'] ?? ''] ?? null;
        if (($type === null) || !isset(Langs::getFiles($type)[$file])) {
            return null;
        }
        return Langs::getCharset(substr($file, 0, (int) strpos($file, '_')));
    }

    /** The value in UTF-8. A value the charset cannot hold (a byte it does not define, broken UTF-8,
     *  a charset iconv does not know) stays as it is, with a finding - also when the raw bytes happen
     *  to be valid UTF-8, which no later check would notice. Writer carries invalid UTF-8 as base64.
     *  @return array{string, ?LintFinding} */
    public static function toUtf8(string $value, string $charset): array
    {
        if (($charset === 'utf-8') ? mb_check_encoding($value, 'UTF-8') : mb_check_encoding($value, 'ASCII')) {
            return [$value, null];
        }
        if ($charset !== 'utf-8') {
            $utf = @iconv($charset, 'UTF-8', $value);     // no //IGNORE, no //TRANSLIT: false on any loss; the notice is the finding
            if ($utf !== false) {
                return [$utf, null];
            }
            return [$value, new LintFinding(LintFinding::WARNING, ValueCodec::INVALID_BYTES, substr($value, 0, 16), 0)];
        }
        return [$value, ValueCodec::encode($value)['finding']];
    }

    /** false = this iconv converts windows-1250 wrongly (a broken or stub iconv): the export must stop */
    public static function works(): bool
    {
        static $works = null;
        return $works ??= (@iconv('windows-1250', 'UTF-8', "\x8A") === 'Š');
    }
}
