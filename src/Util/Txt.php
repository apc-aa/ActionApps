<?php

namespace AA\Util;

class Txt
{
    public static function decoratedName(string $name, bool $deleted = false): string
    {
        return $deleted ? (Langs::isUTF() ? "××× $name ×××" : "xxx $name xxx") : $name;
    }

    /** shorten with possible ellipsis character */
    public static function shorten(string $str, int $len = 20, string $ellipsis = "…"): string
    {
        return (self::strlen($str) > $len) ? self::substr($str, 0, $len - strlen($ellipsis)) . $ellipsis : $str;
    }

    /** Shorten to $len by eliding the MIDDLE, keeping head and tail.
     *
     *  Use instead of {@see shorten()} when the end of the string is as
     *  identifying as its start - a numbered series, an author, a filename
     *  extension - so that cutting the end would collapse distinct values.
     *
     *  Counts graphemes, not bytes, and never returns more than $len of them -
     *  a string already within $len comes back untouched.
     *
     *  The odd grapheme goes to the TAIL (length 21 gives a 9 + marker + 11
     *  split), matching what {shorten:<text>:<len>:3} and the option labels at
     *  formutil.php:2738 have always rendered.
     *
     *  $marker replaces the elided middle. Callers that need the result to stay
     *  within a restricted alphabet pass their own - {@see \Files::escape()} uses
     *  a digest, because a filename may hold no "…".
     */
    public static function shortenMiddle(string $str, int $len = 20, string $marker = "…"): string
    {
        if (self::strlen($str) <= $len) {
            return $str;
        }
        $budget = $len - self::strlen($marker);
        if ($budget < 2) {              // no room for a head and a tail around the marker
            return self::substr($marker, 0, max($len, 0));
        }
        $head_len = intdiv($budget - 1, 2);
        return self::substr($str, 0, $head_len) . $marker . self::substr($str, $head_len - $budget);
    }

    /** Valid UTF-8 of at most $bytes, for a column of that size: invalid bytes become
     *  mb_substitute_character, and the cut never splits a character. */
    public static function utf8Cut(string $s, int $bytes): string
    {
        return mb_strcut(mb_scrub($s, 'UTF-8'), 0, max(0, $bytes), 'UTF-8');
    }

    // --- UTF-aware string primitives (canonical home; the global aa_*() in
    //     include/util.php now delegate here). grapheme_/mb_ per tonsky.me/blog/unicode. ---

    /** UTF-aware strlen (grapheme count). grapheme_strlen() returns null for
     *  invalid UTF-8 (e.g. raw bytes in a debug print_r() dump); fall back to
     *  byte length so length-dependent callers like shorten() stay robust. */
    public static function strlen($text): int|false
    {
        $text = (string) $text;
        return grapheme_strlen($text) ?? strlen($text);
    }

    /** UTF-aware substr. */
    public static function substr($text, $start, ?int $len = null): string
    {
        return mb_substr((string) $text, (int) $start, $len);
    }

    /** UTF-aware strpos. */
    public static function strpos($haystack, $needle, $offset = 0): int|false
    {
        return mb_strpos((string) $haystack, (string) $needle, (int) $offset);
    }

    /** UTF-aware strrpos. */
    public static function strrpos($haystack, $needle, $offset = 0): int|false
    {
        return mb_strrpos((string) $haystack, (string) $needle, (int) $offset);
    }

    /** HTML-escape for output. ENT_QUOTES|ENT_HTML5; legacy charset ISO-8859-1. */
    public static function safe($var = ''): string
    {
        return htmlspecialchars((string) ($var ?? ''), ENT_QUOTES | ENT_HTML5, 'ISO-8859-1');
    }

    /** Escape for XML attribute/content contexts (UTF-8). */
    public static function safexml($var = ''): string
    {
        return htmlspecialchars((string) ($var ?? ''), ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }

    /** small key -> value table for logs, ... (styled by .aa-log-entry in admin-ecn.css) */
    public static function keyValTable(array $arr, bool $html = false): string
    {
        $ret = '';
        foreach ($arr as $name => $val) {
            $name = $html ? $name : safe($name);
            $val  = $html ? $val : safe(trim($val));
            if ($val !== '') {
                $ret .= "<div class=aa-log-entry><label>$name</label><span>$val</span></div>";
            }
        }
        return '<div class=aa-log-entry>' . $ret . '</div>';
    }

    /** enhance value - item_id, date */
    public static function richValue(mixed $value): string
    {
        if (is_long_id($value)) {
            return Adminlink::itemLink($value);
        }
        if ((int) $value >= 883609200 && (int) $value <= 2208985200) {   // 1998 - 2040
            return $value . self::additionalInfo([date("Y-m-d H:i", (int) $value)]);
        }
        return safe($value);
    }

    /** Prints additional info box */
    public static function additionalInfo(array $texts): string
    {
        return '<div class="tabhlp">' . implode(' | ', $texts) . "</div>";
    }

    /** Prefixes all  */
    public static function prefixArrayKeys(array $arr, string $prefix): array
    {
        $ret = [];
        foreach ($arr as $k => $v) {
            $ret[$prefix . $k] = $v;
        }
        return $ret;
    }

    /** Base64url encode (RFC 4648 §5, no padding). */
    public static function bin2url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** Base64url decode. Tolerant: accepts missing padding and mixed +/-_ alphabets.
     *  Returns false on undecodable input (matches base64_decode semantics).
     */
    public static function url2bin(string $data): false|string
    {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '='));
    }
}
