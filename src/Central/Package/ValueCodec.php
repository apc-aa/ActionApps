<?php

declare(strict_types=1);

namespace AA\Central\Package;

use InvalidArgumentException;
use RuntimeException;

/** How one value travels in the element text of a package (design balicek-v1.md § 2.2) - both ways,
 *  the writer encodes, the reader decodes. Pure string functions, no DOM.
 *
 *  encode():
 *  - invalid UTF-8, a character XML 1.0 cannot carry (C0 control except TAB LF CR, U+FFFE, U+FFFF),
 *    a lone CR or mixed CRLF/LF line ends => base64 of the bytes as they are; invalid UTF-8 is also a
 *    finding (INVALID_BYTES, W) - the collector converted the module charset to UTF-8 (§ 2.4), so
 *    these bytes were broken already in the source;
 *  - only CRLF line ends => LF + crlf (XML parsers turn CRLF into LF, even in CDATA);
 *  - `<`, `&` or a line end => CDATA (DOM splits `]]>` itself), else a text node.
 *  A column with the empty value of its type is left out of the row (isEmpty(), § 2.3). */
final class ValueCodec
{
    /** LintFinding code: the value is not valid UTF-8 - carried as base64 of the raw bytes (§ 2.4) */
    public const INVALID_BYTES = 'invalid-bytes';

    public const TEXT   = 'text';
    public const BASE64 = 'base64';

    /** characters XML 1.0 cannot carry, in valid UTF-8 */
    private const NOT_XML = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u';

    /** LintFinding::$ref of INVALID_BYTES: this many bytes from the first invalid one */
    public const REF_BYTES = 16;

    /** @param bool $raw bytes of a module charset that could not be converted (Model\RawBytes): base64
     *                  with no finding - the collector reported them
     *  @return array{text: string, enc: string, crlf: bool, cdata: bool, finding: ?LintFinding} */
    public static function encode(string $value, bool $raw = false): array
    {
        $finding = null;
        $crlf    = 0;
        if ($raw) {
            $base64 = true;
        } elseif (!mb_check_encoding($value, 'UTF-8')) {
            $offset  = self::firstInvalidByte($value);
            $finding = new LintFinding(LintFinding::WARNING, self::INVALID_BYTES, substr($value, $offset, self::REF_BYTES), $offset);
            $base64  = true;
        } else {
            $crlf   = substr_count($value, "\r\n");
            $base64 = self::hasNonXml($value) || substr_count($value, "\r") !== $crlf || ($crlf && substr_count($value, "\n") !== $crlf);
        }
        if ($base64) {
            return ['text' => base64_encode($value), 'enc' => self::BASE64, 'crlf' => false, 'cdata' => false, 'finding' => $finding];
        }
        $text = $crlf ? str_replace("\r\n", "\n", $value) : $value;
        return ['text' => $text, 'enc' => self::TEXT, 'crlf' => $crlf > 0, 'cdata' => strpbrk($text, "<&\n") !== false, 'finding' => null];
    }

    /** The value of an element: its textContent and attributes enc, crlf, null.
     *  @throws InvalidArgumentException null with text, broken base64, base64 with crlf */
    public static function decode(string $text, string $enc = self::TEXT, bool $crlf = false, bool $null = false): ?string
    {
        if ($null) {
            return $text === '' ? null : throw new InvalidArgumentException('null="true" with a text');
        }
        if ($enc === self::BASE64) {
            if ($crlf) {
                throw new InvalidArgumentException('enc="base64" with crlf="true"');
            }
            $bytes = base64_decode($text, true);
            return $bytes === false ? throw new InvalidArgumentException('Broken base64') : $bytes;
        }
        if ($enc !== self::TEXT) {
            throw new InvalidArgumentException("Unknown enc=\"$enc\"");
        }
        return $crlf ? str_replace("\n", "\r\n", $text) : $text;
    }

    /** the value of a column the row leaves out (§ 2.3) @param string $type Schema::rowColumns() type */
    public static function emptyValue(string $type): string
    {
        return ($type === 'intValue' || $type === 'floatValue') ? '0' : '';
    }

    /** a column with this value is left out of the row: '' or a numeric zero of a number column */
    public static function isEmpty(string $type, string $value): bool
    {
        return $value === '' || (self::emptyValue($type) === '0' && is_numeric($value) && (float) $value === 0.0);
    }

    /** true when the element text can hold the value as it is (no enc, no crlf) - attributes, meta */
    public static function isPlain(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8') && !self::hasNonXml($value);
    }

    /** @param string $value valid UTF-8 */
    private static function hasNonXml(string $value): bool
    {
        $found = preg_match(self::NOT_XML, $value);
        if ($found === false) {
            throw new RuntimeException('Value check failed: ' . preg_last_error_msg());
        }
        return $found === 1;
    }

    /** byte offset of the first invalid UTF-8 sequence - a scan, no regex (no backtracking limit) */
    private static function firstInvalidByte(string $value): int
    {
        static $ascii = null;
        $ascii ??= implode('', array_map('chr', range(0, 0x7F)));
        $length   = strlen($value);
        for ($i = strspn($value, $ascii); $i < $length; $i += strspn($value, $ascii, $i)) {
            $lead  = ord($value[$i]);
            $bytes = $lead >= 0xF0 ? 4 : ($lead >= 0xE0 ? 3 : 2);
            if ($lead < 0xC2 || $lead > 0xF4 || !mb_check_encoding(substr($value, $i, $bytes), 'UTF-8')) {
                return $i;
            }
            $i += $bytes;
        }
        return $length;
    }
}
