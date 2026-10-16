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
