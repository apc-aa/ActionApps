<?php

declare(strict_types=1);

namespace AA\Util;

use LibXMLError;
use XMLReader;

/**
 * libxml for XML from outside: errors collected instead of PHP warnings, nothing loadable from
 * outside the document. Never parse such XML with the libxml flags NOENT, DTDLOAD, DTDATTR,
 * DTDVALID or XINCLUDE (they load or expand what the document points to), nor PARSEHUGE (it lifts
 * libxml's own limits, the entity amplification limit among them).
 */
final class Libxml
{
    /** nodes to refuse from an XMLReader: a DTD may declare entities (billion laughs, external
     *  files), an entity reference needs one */
    public const FORBIDDEN = [XMLReader::DOC_TYPE, XMLReader::ENTITY, XMLReader::ENTITY_REF, XMLReader::NOTATION];

    /**
     * Runs $fn with libxml errors collected and no external entity loadable, and restores both.
     * Errors pending in libxml are cleared before and after - read $fn's own inside $fn (errors()).
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function run(callable $fn): mixed
    {
        $internal = libxml_use_internal_errors(true);
        $loader   = libxml_get_external_entity_loader();
        libxml_set_external_entity_loader(static fn() => null);
        libxml_clear_errors();
        try {
            return $fn();
        } finally {
            libxml_clear_errors();
            libxml_set_external_entity_loader($loader);
            libxml_use_internal_errors($internal);
        }
    }

    /**
     * A DOM of XML from outside, refused unless it is plain UTF-8 without a DTD. The bytes are checked
     * before libxml sees them: it parses a DTD before anything can look at it, and in an encoding it
     * detects by itself (UTF-16, EBCDIC) `<!` has other bytes than the check looks for - so only UTF-8
     * passes, without a NUL (valid UTF-8, but in XML only from UTF-16 of ASCII). Nothing is loaded
     * from outside (run()).
     * @param string $what the subject of the messages, like 'The package'
     * @throws \UnexpectedValueException messages quote the document - escape them when shown
     */
    public static function load(string $xml, string $what = 'The XML'): \DOMDocument
    {
        if (trim($xml) === '') {
            throw new \UnexpectedValueException("$what is empty");
        }
        if (!mb_check_encoding($xml, 'UTF-8') || str_contains($xml, "\0")) {
            throw new \UnexpectedValueException("$what is not UTF-8 text");
        }
        if (self::declaresInProlog($xml)) {
            throw new \UnexpectedValueException("$what must not have a DOCTYPE or other markup declarations (DTD, entities)");
        }
        $encoding = self::declaredEncoding($xml);
        if (!self::isUtf8($encoding)) {
            throw new \UnexpectedValueException("$what declares encoding \"" . self::cut($encoding, 64) . '" - only UTF-8 is read');
        }
        return self::parse($xml, $what);
    }

    /**
     * `<!` other than a comment before the root element. A DTD can only be there; after the root a
     * comment or CDATA may hold `<!` as text, and any other `<!` is not well-formed. Comments and
     * processing instructions end where libxml ends them, at the first `-->` and `?>`.
     */
    private static function declaresInProlog(string $xml): bool
    {
        $pos = str_starts_with($xml, "\xEF\xBB\xBF") ? 3 : 0;
        while (true) {
            $pos += strspn($xml, " \t\r\n", $pos);
            [$open, $close] = match (true) {
                substr($xml, $pos, 4) === '<!--' => ['<!--', '-->'],
                substr($xml, $pos, 2) === '<?'   => ['<?', '?>'],
                default                          => ['', ''],
            };
            if ($open === '') {
                return substr($xml, $pos, 2) === '<!';
            }
            $end = strpos($xml, $close, $pos + strlen($open));
            if ($end === false) {
                return false;   // unterminated: not well-formed, libxml refuses it
            }
            $pos = $end + strlen($close);
        }
    }

    /** The DOM step of load(): its checks look at what libxml read, which the byte checks only predict */
    private static function parse(string $xml, string $what): \DOMDocument
    {
        return self::run(static function () use ($xml, $what): \DOMDocument {
            $dom = new \DOMDocument();
            if (!$dom->loadXML($xml, LIBXML_NONET) || self::errors()) {
                $errors = self::describe(5, 256);
                throw new \UnexpectedValueException("$what is not well-formed XML" . (($errors === '') ? '' : ": $errors"));
            }
            if (!is_null($dom->doctype)) {
                throw new \UnexpectedValueException("$what must not have a DOCTYPE (DTD, entities)");
            }
            if (!self::isUtf8($dom->xmlEncoding)) {
                throw new \UnexpectedValueException("$what is in encoding \"" . self::cut($dom->xmlEncoding, 64) . '" - only UTF-8 is read');
            }
            return $dom;
        });
    }

    /** @return list<LibXMLError> errors, not warnings */
    public static function errors(): array
    {
        return array_values(array_filter(libxml_get_errors(), static fn(LibXMLError $e): bool => $e->level >= LIBXML_ERR_ERROR));
    }

    /**
     * "line N: message; ..." of the first $count errors, '' when there are none. libxml quotes the
     * document in its messages, so each one is cut to $bytes.
     */
    public static function describe(int $count, int $bytes): string
    {
        $lines = array_map(static fn(LibXMLError $e): string => "line $e->line: " . self::cut(trim($e->message), $bytes), array_slice(self::errors(), 0, $count));
        return implode('; ', $lines);
    }

    /**
     * encoding="…" of the XML declaration, null = none. $xml may be only the start of a document.
     * The declaration may be megabytes long (whitespace): no regex over the whole input.
     * @throws \UnexpectedValueException the declaration cannot be read
     */
    public static function declaredEncoding(string $xml): ?string
    {
        $start = str_starts_with($xml, "\xEF\xBB\xBF") ? 3 : 0;
        if (!preg_match('/\G<\?xml\s/', $xml, $m, 0, $start)) {
            return null;
        }
        $end   = strpos($xml, '?>', $start);
        $found = preg_match('/\sencoding\s*=\s*(["\'])([^"\']*)\1/', substr($xml, $start, $end === false ? null : $end - $start), $m);
        return match ($found) {
            1       => $m[2],
            0       => null,
            default => throw new \UnexpectedValueException('The XML declaration cannot be read'),
        };
    }

    /**
     * A declared encoding makes libxml decode the bytes as such - in UTF-7 `+ADw-` is `<`, which a
     * check of the UTF-8 bytes does not see. null = none declared, which is UTF-8.
     * @phpstan-assert-if-false !null $encoding
     */
    public static function isUtf8(?string $encoding): bool
    {
        return ($encoding === null) || (strcasecmp($encoding, 'UTF-8') === 0);
    }

    /** text of a document for a message: at most $bytes, not splitting a character */
    public static function cut(string $text, int $bytes): string
    {
        return strlen($text) > $bytes ? mb_strcut($text, 0, $bytes, 'UTF-8') . '…' : $text;
    }
}
