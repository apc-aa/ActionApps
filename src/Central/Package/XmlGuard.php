<?php

declare(strict_types=1);

namespace AA\Central\Package;

use DOMDocument;
use LibXMLError;
use XMLReader;

/** Parses an untrusted package safely (design balicek-v1.md § 3.1 steps 1-5 and 8). All limits hold
 *  BEFORE a DOM is built - a DOM of a small file can be huge (measured, 8 MB of input: 900 000 empty
 *  attributes = 690 MB, `a<!---->` x 1M = 356 MB; libxml allocates outside PHP's memory_limit):
 *  1. bytes: size, UTF-8, the declared encoding; a scan without libxml refuses `<!` markup other than a
 *     comment or CDATA (DOCTYPE - libxml parses a DTD before it returns the node), counts the nodes
 *     (XMLReader keeps comments and PIs of an open element and parses ahead: `a<!---->` x 1M = 330 MB
 *     before its 400 000th node) and the attributes of each start tag (libxml allocates them all
 *     before it returns the tag: 937 000 in one tag = 320 MB);
 *  2. a streaming XMLReader pass: every node before the root, DOCTYPE/entities anywhere, nodes,
 *     elements, attributes, depth;
 *  3. the DOM. No DTD, no entity, nothing is loaded from outside - the external entity loader returns
 *     null while libxml works. Forbidden libxml flags: NOENT, DTDLOAD, DTDATTR, DTDVALID, XINCLUDE,
 *     PARSEHUGE, NOBLANKS (a value of only spaces, e.g. view.row_delimiter, would vanish).
 *  Messages quote at most MAX_QUOTE bytes of package text per quote - escape them when shown. */
final class XmlGuard
{
    public const MAX_BYTES      = 8 * 1024 * 1024;
    public const MAX_ELEMENTS   = 100_000;
    /** every node XMLReader returns but an end tag: elements, text, whitespace, CDATA, comments, PIs.
     *  Writer output has ~2.9 nodes per element (the design example); 400 000 nodes = 100 MB of DOM */
    public const MAX_NODES      = 400_000;
    public const MAX_ATTRIBUTES = 200_000;
    /** the format needs 6 (source: 5 + a namespace declaration) */
    public const MAX_TAG_ATTRIBUTES = 64;
    /** levels of nested elements, the root is level 1 (the format needs 5: aa-app/slice-module/object/object/p) */
    public const MAX_DEPTH = 32;

    public const MAX_QUOTE = 256;

    /** nodes that may precede the root element - a comment may come before a DOCTYPE, so every node counts */
    private const PROLOG = [XMLReader::COMMENT, XMLReader::PI, XMLReader::WHITESPACE, XMLReader::SIGNIFICANT_WHITESPACE];

    /** a DTD may declare entities (billion laughs, external files); an entity reference needs one */
    private const FORBIDDEN = [XMLReader::DOC_TYPE, XMLReader::ENTITY, XMLReader::ENTITY_REF, XMLReader::NOTATION];

    public function __construct(
        private readonly int $maxBytes = self::MAX_BYTES,
        private readonly int $maxElements = self::MAX_ELEMENTS,
        private readonly int $maxNodes = self::MAX_NODES,
        private readonly int $maxAttributes = self::MAX_ATTRIBUTES,
        private readonly int $maxTagAttributes = self::MAX_TAG_ATTRIBUTES,
        private readonly int $maxDepth = self::MAX_DEPTH,
    ) {}

    /** @throws PackageRejected */
    public function load(string $xml): DOMDocument
    {
        if ($xml === '') {
            throw new PackageRejected('The package is empty');
        }
        if (strlen($xml) > $this->maxBytes) {
            throw new PackageRejected("The package is larger than $this->maxBytes bytes");
        }
        // NUL never occurs in XML 1.0 text, but in UTF-16/UCS-4 of ASCII - libxml would detect and read those
        if (!mb_check_encoding($xml, 'UTF-8') || str_contains($xml, "\0")) {
            throw new PackageRejected('The package is not UTF-8 text');
        }
        self::checkEncoding(self::declaredEncoding($xml));
        $this->scan($xml);
        return self::libxml(function () use ($xml): DOMDocument {
            $this->prePass($xml);
            $dom = new DOMDocument();
            if (!$dom->loadXML($xml, LIBXML_NONET) || self::errors()) {
                throw self::rejected('The package is not well-formed XML');
            }
            // both would have been refused before; without the checks textContent would expand entities
            // and another encoding (UTF-7: `+ADw-` = `<`) would make markup the pre-pass did not see
            if ($dom->doctype !== null) {
                throw new PackageRejected('A package must not have a DOCTYPE');
            }
            self::checkEncoding($dom->xmlEncoding);
            return $dom;
        });
    }

    /** @param string $xsd the schema itself - schemaValidate(path) would need the entity loader
     *  @throws PackageRejected */
    public function validate(DOMDocument $dom, string $xsd): void
    {
        self::libxml(static function () use ($dom, $xsd): void {
            if (!$dom->schemaValidateSource($xsd) || self::errors()) {
                throw self::rejected('The package does not match the aa-app schema');
            }
        });
    }

    /** package text for a message */
    public static function quote(string $text, int $bytes = 64): string
    {
        return strlen($text) > $bytes ? mb_strcut($text, 0, $bytes, 'UTF-8') . '…' : $text;
    }

    /** encoding="…" of the XML declaration, null = none. The declaration may be megabytes long
     *  (whitespace): no regex over the whole input. @throws PackageRejected */
    private static function declaredEncoding(string $xml): ?string
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
            default => throw new PackageRejected('The XML declaration cannot be read'),
        };
    }

    /** @throws PackageRejected */
    private static function checkEncoding(?string $encoding): void
    {
        if (($encoding !== null) && (strcasecmp($encoding, 'UTF-8') !== 0)) {
            throw new PackageRejected('The package declares encoding "' . self::quote($encoding) . '" - a package is UTF-8 only');
        }
    }

    /** Step 1 on the bytes (see the class): strpos/strcspn jumps, linear, no regex. A well-formed
     *  document has `<` only in markup, comments, CDATA and PIs; malformed input may be misread here,
     *  libxml refuses it anyway. @throws PackageRejected */
    private function scan(string $xml): void
    {
        $nodes = 0;
        $after = 0;     // offset after the previous markup - text in between is a node
        for ($pos = strpos($xml, '<'); $pos !== false; $pos = strpos($xml, '<', $after)) {
            $next = $xml[$pos + 1] ?? '';
            [$end, $close] = match (true) {
                substr_compare($xml, '<!--', $pos, 4) === 0      => [strpos($xml, '-->', $pos + 4), 3],
                substr_compare($xml, '<![CDATA[', $pos, 9) === 0 => [strpos($xml, ']]>', $pos + 9), 3],
                $next === '!'                                    => throw new PackageRejected('A package must not have a DOCTYPE or other markup declarations (DTD, entities)'),
                $next === '?'                                    => [strpos($xml, '?>', $pos + 2), 2],
                $next === '/'                                    => [strpos($xml, '>', $pos), 1],
                default                                          => [$this->startTag($xml, $pos + 1), 1],
            };
            $nodes += ($pos > $after ? 1 : 0) + ($next === '/' ? 0 : 1);
            if ($nodes > $this->maxNodes) {
                throw new PackageRejected("The package has more than $this->maxNodes nodes");
            }
            if ($end === false) {
                return;     // unterminated - not well-formed
            }
            $after = $end + $close;
        }
    }

    /** @return int|false the offset of the tag's `>` @throws PackageRejected more than maxTagAttributes */
    private function startTag(string $xml, int $i): int|false
    {
        $attributes = 0;
        $length     = strlen($xml);
        while ($i < $length) {
            $stop        = $i + strcspn($xml, "\"'>", $i);     // `=` outside quotes = an attribute
            $attributes += substr_count($xml, '=', $i, $stop - $i);
            if ($attributes > $this->maxTagAttributes) {
                throw new PackageRejected("The package has more than $this->maxTagAttributes attributes in one tag");
            }
            if ($stop >= $length) {
                return false;
            }
            if ($xml[$stop] === '>') {
                return $stop;
            }
            $close = strpos($xml, $xml[$stop], $stop + 1);
            if ($close === false) {
                return false;
            }
            $i = $close + 1;
        }
        return false;
    }

    /** Step 2: streaming, no tree @throws PackageRejected */
    private function prePass(string $xml): void
    {
        $reader = new XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET)) {
            throw self::rejected('The package is not well-formed XML');
        }
        $root  = false;
        $count = ['nodes' => 0, 'elements' => 0, 'attributes' => 0];
        $limit = ['nodes' => $this->maxNodes, 'elements' => $this->maxElements, 'attributes' => $this->maxAttributes];
        try {
            while ($reader->read()) {
                $type = $reader->nodeType;
                if (in_array($type, self::FORBIDDEN, true)) {
                    throw new PackageRejected($type === XMLReader::DOC_TYPE ? 'A package must not have a DOCTYPE (DTD, entities)' : 'A package must not use entities');
                }
                if ($type === XMLReader::END_ELEMENT) {
                    continue;
                }
                $count['nodes']++;
                if ($type === XMLReader::ELEMENT) {
                    $root = true;
                    $count['elements']++;
                    $count['attributes'] += $reader->attributeCount;
                    if ($reader->depth >= $this->maxDepth) {
                        throw new PackageRejected("The package has elements nested deeper than $this->maxDepth levels");
                    }
                } elseif (!$root && !in_array($type, self::PROLOG, true)) {
                    throw new PackageRejected('Only comments and processing instructions may precede the root element');
                }
                foreach ($count as $what => $n) {
                    if ($n > $limit[$what]) {
                        throw new PackageRejected("The package has more than $limit[$what] $what");
                    }
                }
            }
            if (self::errors()) {
                throw self::rejected('The package is not well-formed XML');
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Runs $fn with libxml errors collected and no external entity loadable; restores both.
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private static function libxml(callable $fn): mixed
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
    private static function errors(): array
    {
        return array_values(array_filter(libxml_get_errors(), static fn(LibXMLError $e): bool => $e->level >= LIBXML_ERR_ERROR));
    }

    /** libxml quotes package text (values, names) in its messages - each one is cut */
    private static function rejected(string $what): PackageRejected
    {
        $lines = array_map(static fn(LibXMLError $e): string => "line $e->line: " . self::quote(trim($e->message), self::MAX_QUOTE), array_slice(self::errors(), 0, 5));
        return new PackageRejected($what . ($lines ? ': ' . implode('; ', $lines) : ''));
    }
}
