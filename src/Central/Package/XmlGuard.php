<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Util\Libxml;
use DOMDocument;
use XMLReader;

/** Parses an untrusted package safely. All limits hold BEFORE a DOM is built - a DOM of a small file can
 *  be huge (8 MB of input: 900 000 empty attributes = 690 MB; libxml allocates outside memory_limit):
 *  1. bytes, without libxml: size, UTF-8, the declared encoding; `<!` markup other than a comment or CDATA
 *     is refused (libxml parses a DTD before it returns the node); nodes are counted (XMLReader parses
 *     ahead: `a<!---->` x 1M = 330 MB before its 400 000th node) and so are the attributes of each start
 *     tag (libxml allocates them all before it returns the tag);
 *  2. a streaming XMLReader pass: prolog, DOCTYPE/entities, nodes, elements, attributes, depth;
 *  3. the DOM by Libxml::load(), with nothing loadable from outside. Never use the libxml flags NOENT,
 *     DTDLOAD, DTDATTR, DTDVALID, XINCLUDE, PARSEHUGE, NOBLANKS (a value of only spaces, e.g.
 *     view.row_delimiter, would vanish).
 *  Messages quote package text - escape them when shown. */
final class XmlGuard
{
    public const MAX_BYTES      = 8 * 1024 * 1024;
    public const MAX_ELEMENTS   = 100_000;
    /** every node XMLReader returns but an end tag. Writer output has ~2.9 nodes per element;
     *  400 000 nodes = 100 MB of DOM */
    public const MAX_NODES      = 400_000;
    public const MAX_ATTRIBUTES = 200_000;
    /** the format needs 6 (source: 5 + a namespace declaration) */
    public const MAX_TAG_ATTRIBUTES = 64;
    /** levels of nested elements, the root is level 1 (the format needs 5: aa-app/slice-module/object/object/p) */
    public const MAX_DEPTH = 32;

    public const MAX_QUOTE = 256;

    /** nodes that may precede the root element - a comment may come before a DOCTYPE, so every node counts */
    private const PROLOG = [XMLReader::COMMENT, XMLReader::PI, XMLReader::WHITESPACE, XMLReader::SIGNIFICANT_WHITESPACE];

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
        Libxml::run(function () use ($xml): void {
            $this->prePass($xml);
        });
        try {
            return Libxml::load($xml, 'The package');
        } catch (\UnexpectedValueException $e) {
            throw new PackageRejected($e->getMessage(), 0, $e);
        }
    }

    /** @param string $xsd the schema itself - schemaValidate(path) would need the entity loader
     *  @throws PackageRejected */
    public function validate(DOMDocument $dom, string $xsd): void
    {
        Libxml::run(static function () use ($dom, $xsd): void {
            if (!$dom->schemaValidateSource($xsd) || Libxml::errors()) {
                throw self::rejected('The package does not match the aa-app schema');
            }
        });
    }

    /** validate() of XML this AA wrote (Writer): parsed inside the same guard, without the limits of load()
     *  @throws PackageRejected */
    public function validateXml(string $xml, string $xsd): void
    {
        Libxml::run(function () use ($xml, $xsd): void {
            $dom = new DOMDocument();
            if (!$dom->loadXML($xml, LIBXML_NONET) || Libxml::errors()) {
                throw self::rejected('The package is not well-formed XML');
            }
            $this->validate($dom, $xsd);
        });
    }

    /** package text for a message */
    public static function quote(string $text, int $bytes = 64): string
    {
        return Libxml::cut($text, $bytes);
    }

    /** @throws PackageRejected */
    private static function declaredEncoding(string $xml): ?string
    {
        try {
            return Libxml::declaredEncoding($xml);
        } catch (\UnexpectedValueException $e) {
            throw new PackageRejected($e->getMessage(), 0, $e);
        }
    }

    /** @throws PackageRejected */
    private static function checkEncoding(?string $encoding): void
    {
        if (!Libxml::isUtf8($encoding)) {
            throw new PackageRejected('The package declares encoding "' . self::quote($encoding) . '" - a package is UTF-8 only');
        }
    }

    /** Step 1: linear, no regex. Malformed input may be misread here - libxml refuses it anyway.
     *  @throws PackageRejected */
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
                if (in_array($type, Libxml::FORBIDDEN, true)) {
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
            if (Libxml::errors()) {
                throw self::rejected('The package is not well-formed XML');
            }
        } finally {
            $reader->close();
        }
    }

    /** libxml quotes package text (values, names) in its messages - each one is cut */
    private static function rejected(string $what): PackageRejected
    {
        $errors = Libxml::describe(5, self::MAX_QUOTE);
        return new PackageRejected($what . (($errors === '') ? '' : ': ' . $errors));
    }
}
