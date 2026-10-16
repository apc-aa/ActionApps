<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Http\Response;
use AA\Util\Libxml;

/**
 * SOAP 1.1 for ISDS by hand - ext-soap is not installed on the servers nor in CI. Builds the
 * request of an allowed operation and turns the HTTP answer into an Answer, or into an exception
 * that says whether ISDS may have done the work.
 */
final class Soap
{
    public const NS  = 'http://isds.czechpoint.cz/v20';
    public const XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    private const ENV = 'http://schemas.xmlsoap.org/soap/envelope/';
    /** the only Fault the documentation shows is SOAP 1.2 (WS_manipulace_s_datovymi_zpravami.pdf 1.3.3) */
    private const ENV12 = 'http://www.w3.org/2003/05/soap-envelope';

    /** other codes than 0000 starting 00 "may be taken as success too, with regard to the circumstances" (1.3) */
    private const OK = '/^00\d\d\z/';
    /** a status ISDS refuses a request with; 9xxx are its internal errors (9200, 9204) */
    private const REFUSED = '/^[1-8]\d\d\d\z/';
    /** too many parallel or repeated calls - come back later (1.9.1, 1.9.2) */
    private const BUSY = ['3008', '3009'];

    /** bytes of an ISDS text quoted in an exception message */
    private const QUOTE = 300;

    /**
     * A new request soap:Envelope/soap:Body/isds:$op, all in one DOM
     * @param array<string, mixed> $children see add()
     * @return \DOMElement the operation element, for more children
     */
    public static function request(string $op, array $children = []): \DOMElement
    {
        $doc      = new \DOMDocument('1.0', 'UTF-8');
        $envelope = $doc->createElementNS(self::ENV, 'soap:Envelope');
        $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:isds', self::NS);
        $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::XSI);
        $doc->appendChild($envelope);
        $body = $envelope->appendChild($doc->createElementNS(self::ENV, 'soap:Body'));
        $operation = $doc->createElementNS(self::NS, 'isds:' . $op);
        $body->appendChild($operation);
        foreach ($children as $name => $value) {
            self::add($operation, (string) $name, $value);
        }
        return $operation;
    }

    /**
     * Appends <isds:$name> to $parent. ISDS takes an empty element or nil for an empty value (1.3.1),
     * but only nil is valid for every type of the XSD (an empty xs:integer is not), so null is nil.
     * @param string|int|bool|array<string, mixed>|null $value an array makes child elements, in its order
     */
    public static function add(\DOMElement $parent, string $name, string|int|bool|array|null $value): \DOMElement
    {
        $doc     = $parent->ownerDocument ?? throw new \LogicException('The element is not in a document');
        $element = $doc->createElementNS(self::NS, 'isds:' . $name);
        $parent->appendChild($element);
        if (is_null($value)) {
            $element->setAttributeNS(self::XSI, 'xsi:nil', 'true');
        } elseif (is_array($value)) {
            foreach ($value as $child => $child_value) {
                self::add($element, (string) $child, $child_value);
            }
        } else {
            $element->appendChild($doc->createTextNode(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value));
        }
        return $element;
    }

    /**
     * The request() as XML
     * @throws \InvalidArgumentException not the only child of a request's Body, or not an operation the
     *                                   client calls
     */
    public static function envelope(\DOMElement $operation): string
    {
        $doc  = $operation->ownerDocument;
        $body = $operation->parentNode;
        if (is_null($doc) || !($body instanceof \DOMElement) || ($body->parentNode !== $doc->documentElement)
            || ($body->namespaceURI !== self::ENV) || ($body->localName !== 'Body') || ($body->childElementCount !== 1)
            || ($operation->namespaceURI !== self::NS) || !array_key_exists((string) $operation->localName, Client::OPERATIONS)) {
            throw new \InvalidArgumentException('ISDS operation ' . $operation->localName . ' is not one this client calls');
        }
        return (string) $doc->saveXML();
    }

    /**
     * The HTTP answer to $op as an Answer with a success status. "Refused, nothing done" only where
     * ISDS says so: HTTP 401, a fault of the request below HTTP 500, a status 1xxx-8xxx in an answer
     * without a message id. Anything else may have done the work.
     * @throws AuthException        HTTP 401
     * @throws UnavailableException HTTP 408, 429, 503, status 3008, 3009
     * @throws IsdsException        refused, see above
     * @throws TransportException   no success, and maybe done
     */
    public static function answer(Response $response, string $op): Answer
    {
        $status = $response->status;
        if ($status === 401) {
            throw new AuthException('ISDS refused the login (HTTP 401): ' . self::excerpt($response->body));
        }
        if (in_array($status, [408, 429, 503], true)) {
            throw new UnavailableException("ISDS asks to come back later (HTTP $status)");
        }
        try {
            $xpath = self::parse($response->body);
        } catch (\UnexpectedValueException $e) {
            throw TransportException::uncertain("ISDS answered HTTP $status without a SOAP answer: " . self::excerpt($response->body), '', $e);
        }
        $fault = self::fault($xpath, $status);
        if (!is_null($fault)) {
            throw $fault;
        }
        // something in front of ISDS may pass on an answer it did not mean as the result
        if (!$response->ok()) {
            throw TransportException::uncertain("ISDS answered HTTP $status: " . self::excerpt($response->body));
        }
        $element = self::first($xpath, "/soap:Envelope/soap:Body/isds:{$op}Response");
        if (is_null($element)) {
            throw TransportException::uncertain("ISDS answered without {$op}Response");
        }
        $answer = new Answer($xpath, $element);
        if (preg_match(self::OK, $answer->code) === 1) {
            return $answer;
        }
        if ($answer->code === '') {
            throw TransportException::uncertain("ISDS answered $op without a status");
        }
        $code = self::quote($answer->code, 16);
        $said = self::quote($answer->text);
        $text = ($said === '') ? "ISDS did not complete $op (status $code)" : $said;
        if (in_array($answer->code, self::BUSY, true)) {
            throw new UnavailableException($text, $code);
        }
        // an internal error, another odd status, or a refusal with the id of a created message
        if ((preg_match(self::REFUSED, $answer->code) !== 1) || !in_array($answer->value('isds:dmID'), [null, ''], true)) {
            throw TransportException::uncertain($text, $code);
        }
        throw new IsdsException(($said === '') ? "ISDS refused $op (status $code)" : $said, $code);
    }

    /** xs:dateTime in UTC - with a zone, ISDS does not guess between CET and CEST */
    public static function dateTime(int $time): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $time);
    }

    /**
     * Unix seconds of an xs:dateTime of ISDS ('2010-10-11T11:18:17.308+02:00'); without a zone it is
     * Czech time, as ISDS reads its input (1.3.2). A time that does not exist (30 February, 24:00, the
     * hour skipped for summer time) is refused - PHP would quietly move it on.
     * @throws TransportException
     */
    public static function timestamp(string $text): int
    {
        if (preg_match('/^(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d)(?:\.\d{1,9})?(?:Z|[+-](\d\d):([0-5]\d))?\z/', $text, $m) === 1) {
            $offset = (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0);
            try {
                $time = new \DateTimeImmutable($text, new \DateTimeZone('Europe/Prague'));
                if (($offset <= 14 * 60) && ($time->format('Y-m-d\TH:i:s') === $m[1])) {
                    return $time->getTimestamp();
                }
            } catch (\Exception) {
                // refused below
            }
        }
        throw TransportException::uncertain('ISDS answered a time that is not xs:dateTime: ' . self::quote($text, 64));
    }

    /** a text of ISDS for an exception message: one line, no control characters, cut */
    public static function quote(string $text, int $bytes = self::QUOTE): string
    {
        $line = (string) preg_replace('/[\s\x{0}-\x{1F}\x{7F}-\x{9F}]+/u', ' ', mb_scrub($text, 'UTF-8'));
        return Libxml::cut(trim($line), $bytes);
    }

    /** the first element at $path, null when there is none */
    public static function first(\DOMXPath $xpath, string $path, ?\DOMNode $context = null): ?\DOMElement
    {
        $nodes = $xpath->query($path, $context);
        $node  = ($nodes === false) ? null : $nodes->item(0);
        return ($node instanceof \DOMElement) ? $node : null;
    }

    /** @throws \UnexpectedValueException not plain UTF-8 XML (Libxml::load()) */
    private static function parse(string $xml): \DOMXPath
    {
        $xpath = new \DOMXPath(Libxml::load($xml, 'The answer of ISDS'));
        $xpath->registerNamespace('soap', self::ENV);
        $xpath->registerNamespace('soap12', self::ENV12);
        $xpath->registerNamespace('isds', self::NS);
        return $xpath;
    }

    /** A SOAP Fault as the exception to throw, null when there is none */
    private static function fault(\DOMXPath $xpath, int $status): ?IsdsException
    {
        $fault = self::first($xpath, '/soap:Envelope/soap:Body/soap:Fault | /soap12:Envelope/soap12:Body/soap12:Fault');
        if (is_null($fault)) {
            return null;
        }
        // faultcode and faultstring are unqualified in SOAP 1.1, Code/Value and Reason/Text qualified in 1.2
        $code   = (string) $xpath->evaluate('normalize-space(*[local-name()="faultcode"] | soap12:Code/soap12:Value)', $fault);
        $reason = self::quote((string) $xpath->evaluate('normalize-space(*[local-name()="faultstring"] | soap12:Reason/soap12:Text)', $fault));
        $code   = self::quote(substr((string) strrchr(':' . $code, ':'), 1), 64);
        // SOAP 1.1 sends every fault with HTTP 500, as a failing gateway does - only below 500 is a
        // fault of the request sure to come from ISDS refusing it
        if ((preg_match('/^(?:Client|Sender)(?:\.|\z)/', $code) === 1) && ($status < 500)) {
            return new IsdsException("ISDS refused the request: $reason", $code);
        }
        return TransportException::uncertain("ISDS failed: $reason", $code);
    }

    /** the start of an HTML or XML body as text, for a message */
    private static function excerpt(string $body): string
    {
        // the body may be megabytes, its start tells what it is
        $head = mb_scrub(substr($body, 0, 4096), 'UTF-8');
        $text = (string) preg_replace('~<(script|style)\b.*?(?:</\1\s*>|\z)~is', ' ', $head);
        // a space for every tag keeps the words of <p>a</p><p>b</p> apart
        return self::quote(html_entity_decode(strip_tags(str_replace('<', ' <', $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
