<?php

declare(strict_types=1);

namespace AA\Lang;

/**
 * Reads and writes include/lang/ translation files.
 *
 * Generated format (since 2026): header side-effects ($mgettext_lang, setlocale, …)
 * followed by a single `return [...]` array literal, which OPcache stores as one
 * immutable shared array — loading it per request is nearly free. The legacy
 * format (one `$_m[...] = ...;` assignment per message) is still readable.
 *
 * One-byte-charset variants (cz, sk, …) are GENERATED from their xx-utf8 masters
 * by charset conversion — translators edit only the utf8 files.
 */
class LangFile
{
    /**
     * Per-language header lines emitted into generated files (after $mgettext_lang).
     * Replicates the hand-written headers of the existing files; DB_COLLATION is
     * guarded so that loading a second language in one request does not warn.
     */
    private const HEADERS = [
        'cz-utf8' => [
            "defined('DB_COLLATION') || define('DB_COLLATION', 'utf8mb4_czech_ci');",
            "setlocale(LC_ALL, 'cs_CZ.utf8', 'cs_CZ');  // sort, date, uppercase, ..",
            "setlocale(LC_NUMERIC, 'en_US');            // keep float format with dot (see AA_Stringexpand_If)",
        ],
        'en-utf8' => [
            "defined('DB_COLLATION') || define('DB_COLLATION', 'utf8mb4_unicode_ci');  // *_unicode_* is better for most cases than *_general_*",
            "setlocale(LC_ALL, 'en_US.utf8', 'en_US');  // sort, date, uppercase, ..",
        ],
        'es-utf8' => [
            "defined('DB_COLLATION') || define('DB_COLLATION', 'utf8mb4_spanish_ci');",
            "setlocale(LC_ALL, 'es_ES.utf8', 'es_ES');  // sort, date, uppercase, ..",
            "setlocale(LC_NUMERIC, 'en_US');            // keep float format with dot (see AA_Stringexpand_If)",
        ],
        'sk-utf8' => [
            "defined('DB_COLLATION') || define('DB_COLLATION', 'utf8mb4_slovak_ci');",
            "setlocale(LC_ALL, 'sk_SK.utf8', 'sk_SK');  // sort, date, uppercase, ..",
            "setlocale(LC_NUMERIC, 'en_US');            // keep float format with dot (see AA_Stringexpand_If)",
        ],
        'de-utf8' => [
            "defined('DB_COLLATION') || define('DB_COLLATION', 'utf8mb4_german2_ci');",
            "setlocale(LC_ALL, 'de_DE.utf8', 'de_DE', 'de', 'ge');  // sort, date, uppercase, ..",
            "setlocale(LC_NUMERIC, 'en_US');            // keep float format with dot (see AA_Stringexpand_If)",
        ],
        'bg-utf8' => [
            "defined('DB_COLLATION') || define('DB_COLLATION', 'utf8mb4_unicode_ci');",
            "setlocale(LC_ALL, 'bg_BG.utf8', 'bg_BG');  // sort, date, uppercase, ..",
            "setlocale(LC_NUMERIC, 'en_US');            // keep float format with dot (see AA_Stringexpand_If)",
        ],
        'fr-utf8' => [
            "defined('DB_COLLATION') || define('DB_COLLATION', 'utf8mb4_unicode_ci');",
            "setlocale(LC_ALL, 'fr_FR.utf8', 'fr_FR');  // sort, date, uppercase, ..",
            "setlocale(LC_NUMERIC, 'en_US');            // keep float format with dot (see AA_Stringexpand_If)",
        ],
        'hr-utf8' => [
            "defined('DB_COLLATION') || define('DB_COLLATION', 'utf8mb4_croatian_ci');",
            "setlocale(LC_ALL, 'hr_HR.utf8', 'hr_HR');  // sort, date, uppercase, ..",
            "setlocale(LC_NUMERIC, 'en_US');            // keep float format with dot (see AA_Stringexpand_If)",
        ],
        'hu-utf8' => [
            "defined('DB_COLLATION') || define('DB_COLLATION', 'utf8mb4_hungarian_ci');",
            "setlocale(LC_ALL, 'hu_HU.utf8', 'hu_HU');  // sort, date, uppercase, ..",
            "setlocale(LC_NUMERIC, 'en_US');            // keep float format with dot (see AA_Stringexpand_If)",
        ],
        'ja-utf8' => [
            "defined('DB_COLLATION') || define('DB_COLLATION', 'utf8mb4_unicode_ci');",
            "setlocale(LC_ALL, 'ja_JP.utf8', 'ja_JP');  // sort, date, uppercase, ..",
            "setlocale(LC_NUMERIC, 'en_US');            // keep float format with dot (see AA_Stringexpand_If)",
        ],
        'ro-utf8' => [
            "defined('DB_COLLATION') || define('DB_COLLATION', 'utf8mb4_romanian_ci');",
            "setlocale(LC_ALL, 'ro_RO.utf8', 'ro_RO');  // sort, date, uppercase, ..",
            "setlocale(LC_NUMERIC, 'en_US');            // keep float format with dot (see AA_Stringexpand_If)",
        ],
        'ru-utf8' => [
            "defined('DB_COLLATION') || define('DB_COLLATION', 'utf8mb4_unicode_ci');",
            "setlocale(LC_ALL, 'ru_RU.utf8', 'ru_RU');  // sort, date, uppercase, ..",
            "setlocale(LC_NUMERIC, 'en_US');            // keep float format with dot (see AA_Stringexpand_If)",
        ],
        'vn' => [
            "defined('DB_COLLATION') || define('DB_COLLATION', 'utf8mb4_vietnamese_ci');",
            "setlocale(LC_ALL, 'vi_VN.utf8', 'vi_VN');  // sort, date, uppercase, ..",
            "setlocale(LC_NUMERIC, 'en_US');            // keep float format with dot (see AA_Stringexpand_If)",
        ],
        'cz' => [
            "setlocale(LC_ALL, 'cs_CZ');      // sort, date, uppercase, ..",
            "setlocale(LC_NUMERIC, 'en_US');  // keep float format with dot (see AA_Stringexpand_If)",
        ],
    ];

    /**
     * Loads message => translation map from a lang file (both new `return [...]`
     * and legacy `$_m[...] = ...` formats).
     *
     * @return array<string,string>
     */
    public function read(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        // legacy files setlocale() and define() unconditionally — silence
        // "already defined" warnings when reading many files in one process
        set_error_handler(static fn(): bool => true, E_WARNING | E_NOTICE | E_DEPRECATED);
        try {
            $loader = static function (string $__path): array {
                $_m = null;   // legacy-format files fill $_m[...], new-format files `return [...]`
                $ret = include $__path;
                if (is_array($ret)) {
                    return $ret;
                }
                // @phpstan-ignore function.impossibleType (the include above fills $_m as its side effect)
                return is_array($_m) ? $_m : [];
            };
            return $loader($path);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Writes a lang file in the generated `return [...]` format.
     *
     * @param string $lang_code     e.g. 'cz-utf8'
     * @param array  $entries       list of ['message' => string, 'translation' => string, 'comments' => string[]]
     * @param array  $unused        message => translation kept from previous file but no longer in sources
     * @param string $derived_from  master lang code when this is a generated 1B variant
     */
    public function write(string $path, string $lang_code, array $entries, array $unused = [], string $derived_from = ''): void
    {
        $out = "<?php\n";
        $out .= '// $' . "Id\$\n";   // svn keyword
        $out .= '// Language: ' . strtoupper($lang_code) . "\n";
        $out .= "// This file is GENERATED by misc/mgettext/cli.php — do not edit its structure.\n";
        if ($derived_from !== '') {
            $out .= "// DERIVED from the {$derived_from} files by charset conversion — DO NOT EDIT,\n";
            $out .= "// translate in the {$derived_from} files and re-run the generator.\n";
        } else {
            $out .= "// Type translations on the right side of \"=>\" only.\n";
            $out .= "// Before each message there are links to the program code where it is used.\n";
        }
        $out .= "\n\$mgettext_lang = \"$lang_code\";\n";
        foreach (self::HEADERS[$lang_code] ?? [] as $line) {
            $out .= $line . "\n";
        }
        $out .= "\nreturn [\n";

        if ($unused) {
            $out .= "// Unused messages\n";
            foreach ($unused as $message => $translation) {
                $out .= self::pair((string) $message, (string) $translation);
            }
            $out .= "// End of unused messages\n";
        }
        foreach ($entries as $entry) {
            $out .= "\n";
            foreach ($entry['comments'] ?? [] as $comment) {
                // a newline inside a comment would escape the // and inject code into the included file
                $out .= '// ' . str_replace(["\r", "\n"], ' ', (string) $comment) . "\n";
            }
            $out .= self::pair((string) $entry['message'], (string) $entry['translation']);
        }
        $out .= "];\n";

        if (file_put_contents($path, $out) === false) {
            throw new \RuntimeException("cannot write $path");
        }
        @chmod($path, 0664);
    }

    /**
     * Converts translation values to a one-byte charset; unconvertible values
     * are transliterated and reported in $lossy (message => converted value).
     *
     * @param array<string,string> $translations message => utf8 translation
     * @return array<string,string> message => converted translation (keys stay utf8!)
     */
    public static function convertValues(array $translations, string $charset, array &$lossy = []): array
    {
        $converted = [];
        foreach ($translations as $message => $value) {
            $c = @iconv('UTF-8', $charset, $value);
            if ($c === false) {
                $c = @iconv('UTF-8', $charset . '//TRANSLIT', $value);
                if ($c === false) {
                    $c = @iconv('UTF-8', $charset . '//IGNORE', $value) ?: '';
                }
                $lossy[$message] = $c;
            }
            $converted[$message] = $c;
        }
        return $converted;
    }

    /**
     * Converts translation values from a one-byte charset to UTF-8 (used to seed
     * a new xx-utf8 master from an existing legacy one-byte file).
     *
     * @param array<string,string> $translations message => value in $charset
     * @return array<string,string> message => UTF-8 value
     */
    public static function toUtf8(array $translations, string $charset): array
    {
        if (strcasecmp($charset, 'utf-8') === 0) {
            return $translations;
        }
        return array_map(
            static fn(string $value): string => (string) @iconv($charset, 'UTF-8//IGNORE', $value),
            $translations,
        );
    }

    /** one "message" => "translation", pair (multiline values use constant concatenation) */
    private static function pair(string $message, string $translation): string
    {
        $key = self::phpStringLiteral($message);
        $val = self::phpStringLiteral($translation);
        if (str_contains($message, "\n") || str_contains($translation, "\n")) {
            return "$key\n => $val,\n";
        }
        return "$key => $val,\n";
    }

    /**
     * Double-quoted PHP string literal; embedded newlines become "\n" with the
     * remainder continued via constant "." concatenation (compile-time folded,
     * so the surrounding array stays an OPcache-shared constant).
     */
    public static function phpStringLiteral(string $str): string
    {
        $str = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $str);
        $parts = explode("\n", $str);
        $last = array_pop($parts);
        $lit = '';
        foreach ($parts as $part) {
            $lit .= '"' . $part . '\n"' . "\n   .";
        }
        return $lit . '"' . $last . '"';
    }
}
