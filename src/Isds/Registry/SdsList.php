<?php

declare(strict_types=1);

namespace AA\Isds\Registry;

use AA\Http\Client;
use AA\Http\ClientInterface;
use AA\Http\ResponseTooLargeException;
use AA\Http\TransportException;
use AA\Util\Libxml;
use AA\Util\SafeFile;
use XMLReader;

/**
 * The public list of data boxes of public authorities (open data of the ISDS operator), read as a
 * stream: as a DOM the 17 MB file takes 180 MB, allocated by libxml outside memory_limit.
 *
 * Exception messages quote outside text - the HTTP answer, a redirect target, names and libxml
 * messages from the file - escape them (safe()) when shown.
 */
final class SdsList
{
    public const URL = 'https://www.datovka.gov.cz/sds/datafile.do?format=xml&service=seznam_ds_ovm';

    /** decoded bytes; the file had 17 MB in 10/2026, and Client holds the whole body in memory */
    public const MAX_BYTES = 40 * 1024 * 1024;

    private const NS = 'http://seznam.gov.cz/ovm/datafile/seznam_ds/v1';

    /** paths inside <box> that a Box is made of, each with its empty value */
    private const FIELDS = [
        'id' => '', 'type' => '', 'subtype' => '', 'ico' => '', 'idOVM' => '', 'pdz' => '',
        'name/tradeName' => '', 'name/person/firstName' => '', 'name/person/lastName' => '',
        'hierarchy/isMaster' => '', 'address/city' => '',
    ];

    private const TEXT = [XMLReader::TEXT, XMLReader::CDATA, XMLReader::WHITESPACE, XMLReader::SIGNIFICANT_WHITESPACE];

    /** bytes of outside text in a message */
    private const QUOTE = 200;

    /** for this one fixed address, so the size limit stays next to MAX_BYTES */
    public static function client(): Client
    {
        return new Client(timeout: 120, idle: 30, agent: Client::agent('ISDS list'), maxBytes: self::MAX_BYTES);
    }

    /**
     * Downloads the list into $to. The file is replaced only by a complete list: the whole answer is
     * parsed first (0.5 s for 17 MB), so a failed, cut-off or refused download leaves the previous one
     * in place.
     *
     * @param string $url      fixed in code - an address a request or a setting can choose needs a
     *                         Client::publicOnly() client, or it reads the server's internal network
     * @param int    $maxBytes for tests and callers with a smaller budget
     * @throws TransportException        no answer at all
     * @throws ResponseTooLargeException over $maxBytes
     * @throws \RuntimeException          an answer other than 200, or the file cannot be written
     * @throws \UnexpectedValueException  the answer is not a whole list with a box (an error page, cut-off XML)
     * @return array{sha256: string, size: int} of the bytes written - hashed here, as the file may be
     *                                          replaced as soon as it is in place
     */
    public static function download(ClientInterface $http, string $to, string $url = self::URL, int $maxBytes = self::MAX_BYTES): array
    {
        $answer = $http->request('GET', $url);
        if ($answer->status !== 200) {
            $where = ($answer->redirect === '') ? '' : ' to ' . $answer->redirect;
            throw new \RuntimeException(rtrim("The list of data boxes did not download: HTTP {$answer->status}$where " . $answer->excerpt()));
        }
        // the client may have no limit of its own
        if (strlen($answer->body) > $maxBytes) {
            throw new ResponseTooLargeException("The list of data boxes is over $maxBytes bytes");
        }
        SafeFile::replace($to, static function () use ($answer): string {
            if (iterator_count(self::fromString($answer->body)) === 0) {
                throw new \UnexpectedValueException('The downloaded list of data boxes has no box');
            }
            return $answer->body;
        });
        return ['sha256' => hash('sha256', $answer->body), 'size' => strlen($answer->body)];
    }

    /**
     * The boxes of the file in $path, in file order - see fromString(). The file is read whole, once.
     * @throws \RuntimeException the file cannot be read, or is no regular file of at most MAX_BYTES
     * @return \Generator<int, Box>
     */
    public static function fromXml(string $path): \Generator
    {
        return self::fromString(SafeFile::read($path, self::MAX_BYTES));
    }

    /**
     * The boxes of the file in $path when its bytes hash to $sha256, parsed from those very bytes: a
     * file put in its place after the read is never parsed.
     * @return ?\Generator<int, Box> null: the file is another one; see fromString()
     * @throws \RuntimeException the file cannot be read, or is no regular file of at most MAX_BYTES
     */
    public static function fromVerifiedFile(string $path, string $sha256): ?\Generator
    {
        $xml = SafeFile::read($path, self::MAX_BYTES);
        return hash_equals($sha256, hash('sha256', $xml)) ? self::fromString($xml) : null;
    }

    /**
     * The boxes of the list in $xml, in file order. Another document (an HTML error page) throws at the
     * call; damage deeper in the file only when the iteration reaches it, after some boxes were already
     * returned - so collect them all before changing anything.
     *
     * @throws \UnexpectedValueException not the list, not UTF-8, malformed XML, a DOCTYPE, comment,
     *                                   CDATA or processing instruction, a box with an id of another
     *                                   shape, with a field twice or an element inside a field
     * @return \Generator<int, Box>
     */
    public static function fromString(string $xml): \Generator
    {
        return self::boxes(self::reader($xml));
    }

    /**
     * Libxml::run() only while one box is read, so the caller's own XML code between two boxes
     * runs with its own libxml settings - but libxml errors it left pending are cleared.
     * @return \Generator<int, Box>
     */
    private static function boxes(XMLReader $reader): \Generator
    {
        try {
            while (($values = Libxml::run(static fn(): ?array => self::nextValues($reader))) !== null) {
                yield self::box($values);
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * A reader standing on the root element, which is checked - only the start of the list is parsed.
     * @throws \UnexpectedValueException
     */
    private static function reader(string $xml): XMLReader
    {
        self::scan($xml);
        return Libxml::run(static function () use ($xml): XMLReader {
            // LIBXML_NONET and none of the flags Libxml warns of: they keep entities unloaded and
            // unexpanded; the DOCTYPE nodes refused below are only a further line behind scan()
            $reader = XMLReader::XML($xml, null, LIBXML_NONET);
            if (!($reader instanceof XMLReader)) {
                throw new \UnexpectedValueException('The list of data boxes cannot be parsed');
            }
            try {
                self::root($reader);
            } catch (\Throwable $e) {
                $reader->close();
                throw $e;
            }
            return $reader;
        });
    }

    /** @throws \UnexpectedValueException */
    private static function root(XMLReader $reader): void
    {
        while ($reader->read()) {
            if (in_array($reader->nodeType, Libxml::FORBIDDEN, true)) {
                throw self::forbidden($reader);
            }
            if ($reader->nodeType === XMLReader::ELEMENT) {
                if (($reader->localName === 'list') && ($reader->namespaceURI === self::NS)) {
                    // libxml has parsed ahead into the first boxes; its errors would be cleared on return
                    self::throwErrors();
                    return;
                }
                throw new \UnexpectedValueException('The file is not the list of data boxes, it starts with <' . Libxml::cut($reader->name, self::QUOTE) . '>');
            }
        }
        self::throwErrors();
        throw new \UnexpectedValueException('The file is not the list of data boxes, it has no element');
    }

    /**
     * The texts of the next <box>, keyed as FIELDS.
     * @return ?array<string, string> null at the end of the file
     * @throws \UnexpectedValueException
     */
    private static function nextValues(XMLReader $reader): ?array
    {
        $in_box = false;
        $values = self::FIELDS;
        $path   = [];
        $field  = null;     // the key in $values the text belongs to
        $seen   = [];       // fields met in this box: the texts are joined, so a repeated one would merge
        while ($reader->read()) {
            $type = $reader->nodeType;
            if ($type === XMLReader::ELEMENT) {
                if ($in_box) {
                    if ($field !== null) {
                        throw new \UnexpectedValueException("A box in the list of data boxes has an element inside <$field>");
                    }
                    $segment = ($reader->namespaceURI === self::NS) ? $reader->localName : '{' . $reader->namespaceURI . '}' . $reader->localName;
                    $key     = self::field([...$path, $segment]);
                    if ($key !== null) {
                        if (isset($seen[$key])) {
                            throw new \UnexpectedValueException("A box in the list of data boxes has <$key> twice");
                        }
                        $seen[$key] = true;
                    }
                    if (!$reader->isEmptyElement) {     // no end tag follows, and no text
                        $path[] = $segment;
                        $field  = $key;
                    }
                } elseif (($reader->depth === 1) && ($reader->localName === 'box') && ($reader->namespaceURI === self::NS)) {
                    if ($reader->isEmptyElement) {
                        self::throwErrors();
                        return $values;     // no id - box() refuses it
                    }
                    $in_box = true;
                }
            } elseif ($type === XMLReader::END_ELEMENT) {
                if (!$in_box) {
                    continue;
                }
                if ($reader->depth > 1) {
                    array_pop($path);
                    $field = self::field($path);
                    continue;
                }
                // libxml parses ahead, so this also catches damage a little after the box
                self::throwErrors();
                return $values;
            } elseif (($field !== null) && in_array($type, self::TEXT, true)) {
                $values[$field] .= $reader->value;
            } elseif (in_array($type, Libxml::FORBIDDEN, true)) {
                throw self::forbidden($reader);
            }
        }
        self::throwErrors();
        return null;
    }

    /** @param list<string> $path */
    private static function field(array $path): ?string
    {
        $key = implode('/', $path);
        return array_key_exists($key, self::FIELDS) ? $key : null;
    }

    /** @param array<string, string> $values @throws \UnexpectedValueException an id of another shape */
    private static function box(array $values): Box
    {
        $v = array_map(trim(...), $values);
        // checked before Box would refuse it too: here another shape means the format changed
        if (preg_match(Box::ID, $v['id']) !== 1) {
            throw new \UnexpectedValueException('The list of data boxes has a box with the id "' . Libxml::cut($v['id'], self::QUOTE) . '", not 7 small letters or digits');
        }
        $name = ($v['name/tradeName'] !== '') ? $v['name/tradeName'] : trim($v['name/person/firstName'] . ' ' . $v['name/person/lastName']);
        return new Box(
            $v['id'],
            $v['ico'],
            $v['idOVM'],
            $name,
            $v['type'],
            ctype_digit($v['subtype']) ? (int) $v['subtype'] : 0,
            self::bool($v['hierarchy/isMaster']),
            self::bool($v['pdz']),
            $v['address/city'],
        );
    }

    /** xs:boolean */
    private static function bool(string $value): bool
    {
        return ($value === 'true') || ($value === '1');
    }

    /** @throws \UnexpectedValueException when libxml found the XML malformed */
    private static function throwErrors(): void
    {
        $errors = Libxml::describe(3, self::QUOTE);
        if ($errors !== '') {
            throw new \UnexpectedValueException('The list of data boxes is not well-formed XML: ' . $errors);
        }
    }

    private static function forbidden(XMLReader $reader): \UnexpectedValueException
    {
        return new \UnexpectedValueException(($reader->nodeType === XMLReader::DOC_TYPE) ? 'The file has a DOCTYPE ' . Libxml::cut($reader->name, self::QUOTE) . ', the list of data boxes has none' : 'The list of data boxes must not use entities');
    }

    /**
     * Checks the bytes before libxml sees them. The list is UTF-8: libxml would decode UTF-16 or a
     * declared UTF-7 and find markup this scan did not see. And it has no <! or <? markup other than
     * the leading XML declaration; the list never has any. libxml builds every comment, CDATA or
     * processing instruction up to the next tag in one go, before XMLReader returns the first of
     * them (a million empty comments in one box took 290 MB outside memory_limit), and parses a DTD
     * before it returns the DOCTYPE. Runs of attributes are left to libxml's own lookup limit
     * (10 MB of one tag, about 300 MB at worst while that tag is parsed).
     * @throws \UnexpectedValueException
     */
    private static function scan(string $xml): void
    {
        // XMLReader::XML() throws a ValueError on an empty string
        if ($xml === '') {
            throw new \UnexpectedValueException('The file is not the list of data boxes, it has no element');
        }
        // UTF-16 and UTF-32 have a NUL in every ASCII character
        if (str_contains($xml, "\0")) {
            throw new \UnexpectedValueException('The list of data boxes is not UTF-8 text');
        }
        $from = self::prolog($xml);
        foreach (['<!', '<?'] as $markup) {
            $at = strpos($xml, $markup, $from);
            if ($at !== false) {
                throw new \UnexpectedValueException('The list of data boxes has "' . mb_strcut($xml, $at, 16, 'UTF-8') . '" - <! or <? markup, which it never has');
            }
        }
    }

    /**
     * Where the text after the XML declaration starts; the start of the file must be UTF-8.
     * @throws \UnexpectedValueException
     */
    private static function prolog(string $text): int
    {
        $bom = str_starts_with($text, "\xEF\xBB\xBF") ? 3 : 0;
        if (($text[$bom] ?? '<') !== '<') {
            throw new \UnexpectedValueException('The list of data boxes must start with "<" in UTF-8, after a BOM at most');
        }
        $encoding = Libxml::declaredEncoding($text);
        if (!Libxml::isUtf8($encoding)) {
            throw new \UnexpectedValueException('The list of data boxes declares encoding "' . Libxml::cut($encoding, self::QUOTE) . '" - it is UTF-8');
        }
        return preg_match('/\G<\?xml\s[^>]*\?>/', $text, $declaration, 0, $bom) ? $bom + strlen($declaration[0]) : 0;
    }
}
