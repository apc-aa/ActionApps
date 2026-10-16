<?php

declare(strict_types=1);

namespace AA\Isds\Registry;

use AA\Http\Client;
use AA\Http\ClientInterface;
use AA\Http\ResponseTooLargeException;
use AA\Http\TransportException;
use AA\Util\Libxml;
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

    /** every id in the 2026 file matches; the id becomes a recipient address, so another shape means the format changed */
    private const ID = '/^[a-z0-9]{7}$/';

    private const NS = 'http://seznam.gov.cz/ovm/datafile/seznam_ds/v1';

    /** paths inside <box> that a Box is made of, each with its empty value */
    private const FIELDS = [
        'id' => '', 'type' => '', 'subtype' => '', 'ico' => '', 'idOVM' => '', 'pdz' => '',
        'name/tradeName' => '', 'name/person/firstName' => '', 'name/person/lastName' => '',
        'hierarchy/isMaster' => '',
    ];

    private const TEXT = [XMLReader::TEXT, XMLReader::CDATA, XMLReader::WHITESPACE, XMLReader::SIGNIFICANT_WHITESPACE];

    /** bytes of outside text in a message */
    private const QUOTE = 200;

    /** bytes scan() reads at a time */
    private const SCAN_CHUNK = 1024 * 1024;

    /** for this one fixed address, so the size limit stays next to MAX_BYTES */
    public static function client(): Client
    {
        return new Client(timeout: 120, idle: 30, agent: Client::agent('ISDS list'), maxBytes: self::MAX_BYTES);
    }

    /**
     * Downloads the list into $to and returns $to. The file is replaced only by a complete list: the
     * whole answer is parsed first (0.5 s for 17 MB), so a failed, cut-off or refused download leaves
     * the previous one in place.
     *
     * @param string $url fixed in code - an address a request or a setting can choose needs a
     *                    Client::publicOnly() client, or it reads the server's internal network
     * @throws TransportException        no answer at all
     * @throws ResponseTooLargeException over MAX_BYTES
     * @throws \RuntimeException          an answer other than 200, or the file cannot be written
     * @throws \UnexpectedValueException  the answer is not a whole list with a box (an error page, cut-off XML)
     */
    public static function download(ClientInterface $http, string $to, string $url = self::URL): string
    {
        $answer = $http->request('GET', $url);
        if ($answer->status !== 200) {
            $where = ($answer->redirect === '') ? '' : ' to ' . $answer->redirect;
            throw new \RuntimeException(rtrim("The list of data boxes did not download: HTTP {$answer->status}$where " . $answer->excerpt()));
        }
        // the client may have no limit of its own
        if (strlen($answer->body) > self::MAX_BYTES) {
            throw new ResponseTooLargeException('The list of data boxes is over ' . self::MAX_BYTES . ' bytes');
        }
        // a name of its own, created exclusively next to $to (so the rename is atomic): another
        // download, or a link planted under a fixed name, cannot get between the write and the rename
        $dir = dirname($to);
        error_clear_last();
        $part = @tempnam($dir, basename($to) . '.');
        if ($part === false) {
            throw new \RuntimeException("Cannot create a file in $dir" . self::lastError());
        }
        if (dirname($part) !== realpath($dir)) {
            @unlink($part);     // tempnam() falls back to the system temp directory
            throw new \RuntimeException("Cannot create a file in $dir - it is missing or not writable");
        }
        try {
            error_clear_last();
            if (@file_put_contents($part, $answer->body) !== strlen($answer->body)) {
                throw new \RuntimeException("Cannot write $part" . self::lastError());
            }
            if (iterator_count(self::fromXml($part)) === 0) {
                throw new \UnexpectedValueException('The downloaded list of data boxes has no box');
            }
            // tempnam() makes it 0600 and rename() keeps that - a reader under another uid would fail
            @chmod($part, 0o666 & ~umask());
            error_clear_last();
            if (!@rename($part, $to)) {
                throw new \RuntimeException("Cannot replace $to" . self::lastError());
            }
        } catch (\Throwable $e) {
            @unlink($part);
            throw $e;
        }
        return $to;
    }

    /**
     * The boxes in file order. A missing file or another document (an HTML error page) throws
     * at the call; damage deeper in the file only when the iteration reaches it, after some
     * boxes were already returned - so collect them all before changing anything.
     *
     * @throws \RuntimeException         the file cannot be read
     * @throws \UnexpectedValueException not the list, not UTF-8, malformed XML, a DOCTYPE, comment,
     *                                   CDATA or processing instruction, a box with an id of another
     *                                   shape, with a field twice or an element inside a field
     * @return \Generator<int, Box>
     */
    public static function fromXml(string $path): \Generator
    {
        return self::boxes(self::reader($path));
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
     * A reader standing on the root element, which is checked - only the start of the file is read.
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    private static function reader(string $path): XMLReader
    {
        // XMLReader takes the path as a URL and decodes %41 to A - it would read another file
        if (str_contains($path, '%')) {
            throw new \RuntimeException("The path of the list of data boxes must not contain '%': $path");
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("Cannot read the list of data boxes $path");
        }
        self::scan($path);
        return Libxml::run(static function () use ($path): XMLReader {
            // LIBXML_NONET and none of the flags Libxml warns of: they keep entities unloaded and
            // unexpanded; the DOCTYPE nodes refused below are only a further line behind scan()
            $reader = XMLReader::open($path, null, LIBXML_NONET);
            if (!($reader instanceof XMLReader)) {
                throw new \RuntimeException("Cannot read the list of data boxes $path");
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
        if (preg_match(self::ID, $v['id']) !== 1) {
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
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    private static function scan(string $path): void
    {
        error_clear_last();
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new \RuntimeException("Cannot read the list of data boxes $path" . self::lastError());
        }
        try {
            $tail = '';
            for ($first = true; ($chunk = fread($file, self::SCAN_CHUNK)) !== false && ($chunk !== ''); $first = false) {
                // UTF-16 and UTF-32 have a NUL in every ASCII character
                if (str_contains($chunk, "\0")) {
                    throw new \UnexpectedValueException('The list of data boxes is not UTF-8 text');
                }
                $text = $tail . $chunk;
                $from = $first ? self::prolog($text) : 0;
                foreach (['<!', '<?'] as $markup) {
                    $at = strpos($text, $markup, $from);
                    if ($at !== false) {
                        throw new \UnexpectedValueException('The list of data boxes has "' . mb_strcut($text, $at, 16, 'UTF-8') . '" - <! or <? markup, which it never has');
                    }
                }
                $tail = substr($text, -1);  // a '<' at the end pairs with the first byte of the next chunk
            }
        } finally {
            fclose($file);
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

    /** what PHP said about the failed file operation, for the message */
    private static function lastError(): string
    {
        $error = error_get_last()['message'] ?? '';
        return ($error === '') ? '' : ' - ' . $error;
    }
}
