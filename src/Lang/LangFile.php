<?php

declare(strict_types=1);

namespace AA\Lang;

use AA\Util\Langs;

/**
 * Reads and writes include/lang/ translation files.
 *
 * Generated format (since 2026): header side-effects ($mgettext_lang, setlocale, …)
 * followed by a single `return [...]` array literal, which OPcache stores as one
 * immutable shared array — loading it per request is nearly free. The legacy
 * format (one `$_m[...] = ...;` assignment per message) is still readable.
 *
 * One-byte-charset variants (cz, sk, …) are GENERATED from their xx-utf8 masters
 * by charset conversion — translators edit only the utf8 files. Their non-ASCII
 * bytes are written as \xNN escapes, so the file itself is pure ASCII and cannot
 * be corrupted by an editor resaving it in a wrong encoding (which is how the
 * cz/sk files got broken in SVN r4602).
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
            // commented-out unused pairs are invisible to include() but must survive
            // regenerations (the generator revives them when their message returns)
            return $loader($path) + self::readCommentedUnused($path);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Parses the commented-out "Unused messages" block of a generated file back
     * into message => translation pairs (active entries win on conflict).
     *
     * @return array<string,string>
     */
    private static function readCommentedUnused(string $path): array
    {
        return self::parsePairs(self::unusedBlock((string) file_get_contents($path)));
    }

    /** the "Unused messages" block with the leading `// ` stripped (or '' if absent) */
    private static function unusedBlock(string $code): string
    {
        if (!preg_match('~^// Unused messages.*?$(.*?)^// End of unused messages~ms', $code, $m)) {
            return '';
        }
        return (string) preg_replace('~^// ?~m', '', $m[1]);
    }

    /**
     * Reads per-message AI-translation markers — the trailing `// ai …` comments
     * emitted by write()'s $marks parameter — from active, runtime and
     * commented-unused pairs alike. The marker text is kept verbatim (so a date
     * like "ai 2026-06" survives regenerations); deleting the comment in the
     * file removes the mark permanently.
     *
     * @return array<string,string> message => marker text (e.g. 'ai 2026-06')
     */
    public static function readMarks(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $code = (string) file_get_contents($path);
        $marks = [];
        self::parsePairs($code, $marks);                       // active + runtime pairs
        self::parsePairs(self::unusedBlock($code), $marks);    // commented-out pairs
        return $marks;
    }

    /**
     * Tokenizes `"message" => "translation",` pairs (values may be "." concatenations).
     * Lines that are not such a pair are ignored.
     * With $marks, a `// ai …` comment trailing a pair's comma is recorded
     * there as message => marker text (the AI-translation marker of write()).
     *
     * @param array<string,string>|null $marks
     * @return array<string,string>
     */
    private static function parsePairs(string $body, ?array &$marks = null): array
    {
        $pairs = [];
        $key = null;
        $cur = [];
        $last_key = null;
        $last_comma_line = -1;
        foreach (\PhpToken::tokenize("<?php\n" . $body) as $t) {
            if ($marks !== null && $last_key !== null && $t->line === $last_comma_line
                && $t->is(T_COMMENT) && preg_match('~^//\s*(ai\b.*?)\s*$~', $t->text, $m)) {
                $marks[$last_key] = $m[1];
                continue;
            }
            if ($t->isIgnorable() || $t->is(T_OPEN_TAG)) {
                continue;
            }
            if ($t->is(T_CONSTANT_ENCAPSED_STRING)) {
                $cur[] = MsgExtractor::decodeStringLiteral($t->text);
            } elseif ($t->text === '.') {
                continue;
            } elseif ($t->is(T_DOUBLE_ARROW)) {
                $key = implode('', $cur);
                $cur = [];
            } elseif ($t->text === ',') {
                if ($key !== null && $cur) {
                    $pairs[$key] = implode('', $cur);
                    $last_key = $key;
                    $last_comma_line = $t->line;
                }
                $key = null;
                $cur = [];
            } else {
                $key = null;   // explanation text or other non-pair content — skip
                $cur = [];
            }
        }
        return $pairs;
    }

    /**
     * Writes a lang file in the generated `return [...]` format.
     *
     * @param string $lang_code     e.g. 'cz-utf8'
     * @param array  $entries       list of ['message' => string, 'translation' => string, 'comments' => string[]]
     * @param array  $unused        message => translation kept from previous file but no longer in sources
     *                              (written commented-out; revived when the message returns)
     * @param string $derived_from  master lang code when this is a generated 1B variant
     * @param array  $runtime       message => translation for runtime-translated messages — _mdelayed($var)
     *                              values listed in misc/mgettext/cli.php $RUNTIME_MESSAGES (written active)
     * @param array  $marks         message => marker text for AI-produced translations (e.g. 'ai 2026-06');
     *                              emitted as a trailing `// ai …` comment, read back by readMarks()
     */
    public function write(string $path, string $lang_code, array $entries, array $unused = [], string $derived_from = '', array $runtime = [], array $marks = []): void
    {
        $charset = array_key_exists($lang_code, Langs::getNames()) ? strtolower(Langs::getCharset($lang_code)) : 'utf-8';
        // one-byte files are written as pure ASCII (\xNN escapes) — immune to editors
        // resaving them in a wrong encoding, which is what corrupted them in r4602
        $ascii = ($charset !== 'utf-8');

        $out = $ascii ? "<?php\n" : "<?php // -*- coding: utf-8 -*-\n";
        $out .= '// $' . "Id\$\n";   // svn keyword
        $out .= '// Language: ' . strtoupper($lang_code) . "\n";
        $out .= $ascii
            ? "// Encoding: pure ASCII in this file; values decode to $charset bytes (\\xNN escapes).\n"
            : "// Encoding: utf-8 — never resave this file in another encoding.\n";
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

        if ($runtime) {
            $out .= "// Runtime-translated messages — used via _mdelayed(\$variable), so they never appear\n";
            $out .= "// in _m('...') literals; their domains are listed in misc/mgettext/cli.php \$RUNTIME_MESSAGES.\n";
            foreach ($runtime as $message => $translation) {
                $out .= self::pair((string) $message, (string) $translation, $ascii, $marks[$message] ?? null);
            }
            $out .= "// End of runtime-translated messages\n\n";
        }
        if ($unused) {
            // commented out: not loaded at runtime, but read() parses the block back,
            // so the generator revives a translation when its message returns
            $out .= "// Unused messages — no longer found in the sources; kept commented for reference.\n";
            foreach ($unused as $message => $translation) {
                $pair = rtrim(self::pair((string) $message, (string) $translation, $ascii, $marks[$message] ?? null), "\n");
                $out .= '// ' . str_replace("\n", "\n// ", $pair) . "\n";
            }
            $out .= "// End of unused messages\n";
        }
        foreach ($entries as $entry) {
            $out .= "\n";
            foreach ($entry['comments'] ?? [] as $comment) {
                // a newline inside a comment would escape the // and inject code into the included file
                $out .= '// ' . str_replace(["\r", "\n"], ' ', (string) $comment) . "\n";
            }
            $out .= self::pair((string) $entry['message'], (string) $entry['translation'], $ascii, $marks[$entry['message']] ?? null);
        }
        $out .= "];\n";

        if ($ascii) {
            $out = str_replace('—', '-', $out);   // em dashes in the fixed comment texts
            if (preg_match('/[\x80-\xFF]/', $out)) {
                throw new \RuntimeException("$path: non-ASCII byte would leak into a one-byte lang file");
            }
        }

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

    /** one "message" => "translation", pair (multiline values use constant concatenation),
     *  optionally tagged with a trailing AI-translation marker comment */
    private static function pair(string $message, string $translation, bool $ascii = false, ?string $mark = null): string
    {
        $key = self::phpStringLiteral($message, $ascii);
        $val = self::phpStringLiteral($translation, $ascii);
        // a newline in the marker would escape the trailing comment and inject code
        $end = $mark === null ? ",\n" : ', // ' . str_replace(["\r", "\n"], ' ', $mark) . "\n";
        if (str_contains($message, "\n") || str_contains($translation, "\n")) {
            return "$key\n => $val$end";
        }
        return "$key => $val$end";
    }

    /**
     * Double-quoted PHP string literal; embedded newlines become "\n" with the
     * remainder continued via constant "." concatenation (compile-time folded,
     * so the surrounding array stays an OPcache-shared constant).
     * With $ascii, bytes >0x7F become \xNN escapes (encoding-proof one-byte files).
     */
    public static function phpStringLiteral(string $str, bool $ascii = false): string
    {
        $str = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $str);
        if ($ascii) {
            $str = preg_replace_callback(
                '/[\x80-\xFF]/',
                static fn(array $m): string => sprintf('\x%02X', ord($m[0])),
                $str,
            );
        }
        $parts = explode("\n", $str);
        $last = array_pop($parts);
        $lit = '';
        foreach ($parts as $part) {
            $lit .= '"' . $part . '\n"' . "\n   .";
        }
        return $lit . '"' . $last . '"';
    }
}
