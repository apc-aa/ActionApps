<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Http\Response;
use AA\Isds\AddressBlockedException;
use AA\Isds\AuthException;
use AA\Isds\IsdsException;
use AA\Isds\Soap;
use AA\Isds\TransportException;
use AA\Isds\UnavailableException;
use AA\Util\Libxml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SoapTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
    }

    /** an answer of ISDS with $inner in the SOAP body */
    private static function soap(string $inner): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"><SOAP-ENV:Body>'
            . $inner . '</SOAP-ENV:Body></SOAP-ENV:Envelope>';
    }

    /** CreateMessageResponse with a status and, unless null, a dmID */
    private static function created(string $code, ?string $id = null, string $text = 'Chyba'): string
    {
        return self::soap('<p:CreateMessageResponse xmlns:p="http://isds.czechpoint.cz/v20">' . (is_null($id) ? '' : "<p:dmID>$id</p:dmID>")
            . "<p:dmStatus><p:dmStatusCode>$code</p:dmStatusCode><p:dmStatusMessage>$text</p:dmStatusMessage></p:dmStatus></p:CreateMessageResponse>");
    }

    /** @return array<string, string> child name => text, 'nil' for xsi:nil */
    private static function children(string $xml): array
    {
        $xpath = new \DOMXPath(Libxml::load($xml));
        $xpath->registerNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        $out = [];
        foreach ($xpath->query('/soap:Envelope/soap:Body/*/*') ?: [] as $child) {
            if ($child instanceof \DOMElement) {
                $out[$child->localName] = ($child->getAttributeNS(Soap::XSI, 'nil') === 'true') ? 'nil' : $child->textContent;
            }
        }
        return $out;
    }

    public function test_request_valuesAndNil(): void
    {
        $xml = Soap::envelope(Soap::request('GetListOfSentMessages', ['dmFromTime' => 'a', 'dmToTime' => null, 'dmStatusFilter' => -1, 'dmOffset' => true, 'dmLimit' => false]));

        self::assertSame(['dmFromTime' => 'a', 'dmToTime' => 'nil', 'dmStatusFilter' => '-1', 'dmOffset' => 'true', 'dmLimit' => 'false'], self::children($xml));
        self::assertSame(1, substr_count($xml, '<?xml'), 'one document');
    }

    public function test_request_escapesText(): void
    {
        $xml = Soap::envelope(Soap::request('CheckDataBox', ['dbID' => '<a&b>']));

        self::assertStringContainsString('<isds:dbID>&lt;a&amp;b&gt;</isds:dbID>', $xml);
    }

    #[DataProvider('notAllowed')]
    public function test_envelope_refusesOperationNotAllowed(string $op): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Soap::envelope(Soap::request($op));
    }

    /** every operation over received messages: ClientTest::test_operations_noneOverReceivedMessages() */
    public static function notAllowed(): array
    {
        return ['unknown' => ['Foo'], 'case matters' => ['createmessage']];
    }

    /** @param \Closure(): \DOMElement $operation */
    #[DataProvider('notRequests')]
    public function test_envelope_refusesElementOutsideARequest(\Closure $operation): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Soap::envelope($operation());
    }

    public static function notRequests(): array
    {
        return [
            'detached'            => [static fn(): \DOMElement => (new \DOMDocument())->createElementNS(Soap::NS, 'isds:CheckDataBox')],
            'no envelope'         => [static function (): \DOMElement {
                $doc = new \DOMDocument();
                $op  = $doc->createElementNS(Soap::NS, 'isds:CheckDataBox');
                $doc->appendChild($op);
                return $op;
            }],
            'inside another one'  => [static fn(): \DOMElement => Soap::add(Soap::request('CreateMessage'), 'CheckDataBox', [])],
            'a second one beside' => [static function (): \DOMElement {
                $op = Soap::request('CheckDataBox');
                $op->parentNode?->appendChild(new \DOMElement('isds:MessageDownload', null, Soap::NS));
                return $op;
            }],
            'another namespace'   => [static function (): \DOMElement {
                $op    = Soap::request('CheckDataBox');
                $other = new \DOMElement('CheckDataBox', null, 'http://isds.czechpoint.cz/v30');
                $op->parentNode?->replaceChild($other, $op);
                return $other;
            }],
        ];
    }

    // "refused, nothing done" only on a sign from ISDS; everything else may have done the work
    #[DataProvider('httpAnswers')]
    public function test_answer_byHttpStatusAndBody(int $status, string $body, string $class, string $code, bool $sent): void
    {
        try {
            Soap::answer(new Response($status, $body), 'CreateMessage');
            self::fail("$class expected");
        } catch (IsdsException $e) {
            self::assertSame([$class, $code, $sent], [$e::class, $e->isdsCode(), $e->mayHaveBeenSent()]);
        }
    }

    public static function httpAnswers(): array
    {
        $html     = '<html><body><h1>Internal Server Error</h1></body></html>';
        $client   = self::fixture('fault.xml');
        $server   = str_replace('SOAP-ENV:Client', 'SOAP-ENV:Server', $client);
        $sender   = self::fixture('fault12.xml');
        $receiver = str_replace('SOAP-ENV:Sender', 'SOAP-ENV:Receiver', $sender);
        $ok       = self::fixture('create-message-ok.xml');
        $refused  = self::fixture('create-message-1233.xml');
        return [
            '401 login'                    => [401, self::fixture('unauthorized.html'), AuthException::class, '', false],
            '503 outage'                   => [503, '', UnavailableException::class, '', true],
            '429 too many'                 => [429, $html, UnavailableException::class, '', true],
            '408 request too slow'         => [408, '', UnavailableException::class, '', true],
            '400 Client fault'             => [400, $client, IsdsException::class, 'Client', false],
            '200 Client fault'             => [200, $client, IsdsException::class, 'Client', false],
            '400 Client subcode fault'     => [400, str_replace('SOAP-ENV:Client', 'Client.Authentication', $client), IsdsException::class, 'Client.Authentication', false],
            '400 Sender fault, SOAP 1.2'   => [400, $sender, IsdsException::class, 'Sender', false],
            '500 Client fault'             => [500, $client, TransportException::class, 'Client', true],
            '502 Client fault'             => [502, $client, TransportException::class, 'Client', true],
            '504 Sender fault'             => [504, $sender, TransportException::class, 'Sender', true],
            '500 Server fault'             => [500, $server, TransportException::class, 'Server', true],
            '200 Server fault'             => [200, $server, TransportException::class, 'Server', true],
            '400 Receiver fault'           => [400, $receiver, TransportException::class, 'Receiver', true],
            '400 ClientError is no Client' => [400, str_replace('SOAP-ENV:Client', 'ClientError', $client), TransportException::class, 'ClientError', true],
            '500 html'                     => [500, $html, TransportException::class, '', true],
            '504 empty'                    => [504, '', TransportException::class, '', true],
            '502 with a success'           => [502, $ok, TransportException::class, '', true],
            '404 html'                     => [404, $html, TransportException::class, '', true],
            '302 empty'                    => [302, '', TransportException::class, '', true],
            '400 with a refusal'           => [400, $refused, TransportException::class, '', true],
            '200 not xml'                  => [200, 'OK', TransportException::class, '', true],
            '200 empty'                    => [200, '', TransportException::class, '', true],
            '204 empty'                    => [204, '', TransportException::class, '', true],
            '200 doctype'                  => [200, "<!DOCTYPE html>\n<html/>", TransportException::class, '', true],
            '200 another operation'        => [200, self::fixture('check-data-box.xml'), TransportException::class, '', true],
            '200 without a status'         => [200, self::soap('<p:CreateMessageResponse xmlns:p="http://isds.czechpoint.cz/v20"><p:dmID>1</p:dmID></p:CreateMessageResponse>'), TransportException::class, '', true],
            '200 refused'                  => [200, $refused, IsdsException::class, '1233', false],
            '200 refused, empty dmID'      => [200, self::created('1233', ''), IsdsException::class, '1233', false],
            '200 refused with a dmID'      => [200, self::created('1233', '1835603'), TransportException::class, '1233', true],
            '200 internal error'           => [200, self::created('9200'), TransportException::class, '9200', true],
            '200 too many parallel calls'  => [200, self::created('3008'), UnavailableException::class, '3008', true],
            '200 another call running'     => [200, self::created('3009'), UnavailableException::class, '3009', true],
            '200 status not 00, not 1-8'   => [200, self::created('0100'), TransportException::class, '0100', true],
            '200 status no number'         => [200, self::created('abcd'), TransportException::class, 'abcd', true],
        ];
    }

    public function test_answer_401QuotesTheBlockNotTheMarkup(): void
    {
        try {
            Soap::answer(new Response(401, self::fixture('unauthorized.html')), 'CreateMessage');
            self::fail('AuthException expected');
        } catch (AuthException $e) {
            self::assertStringContainsString('Login blocked until: 13:04:39', $e->getMessage());
            self::assertStringNotContainsString('<', $e->getMessage());
        }
    }

    /** @return array<string, array{string, class-string}> */
    public static function pages401(): array
    {
        $page = static fn(string $said): string => '<html><body><h1>Authentication required!</h1><p>This server could not verify that you are authorized to access the URL "/DS/df".</p>'
            . "<p>$said</p><p>In case you are allowed to request the document, please check your user-id and password and try again.</p></body></html>";
        return [
            'as documented'       => [self::fixture('address-blocked.html'), AddressBlockedException::class],
            'in capitals'         => [$page('BEZPECNOSTNI MECHANISMUS ISDS NEUMOZNIL PRIHLASENI.'), AddressBlockedException::class],
            'over lines'          => [$page("Bezpecnostni\n\t mechanismus ISDS\r\nneumoznil prihlaseni."), AddressBlockedException::class],
            'with diacritics'     => [$page('Bezpečnostní mechanismus ISDS neumožnil přihlášení.'), AddressBlockedException::class],
            'in CP1250'           => [$page("Bezpe\xE8nostn\xED mechanismus ISDS neumo\x9Enil p\xF8ihl\xE1\x9Aen\xED."), AddressBlockedException::class],
            'in entities'         => [$page('Bezpe&#269;nostn&iacute; mechanismus ISDS neumo&#382;nil p&#345;ihl&aacute;&scaron;en&iacute;.'), AddressBlockedException::class],
            'by &nbsp;'           => [$page('Bezpecnostni&nbsp;mechanismus&nbsp;ISDS&nbsp;neumoznil&nbsp;prihlaseni.'), AddressBlockedException::class],
            'by a no-break space' => [$page("Bezpecnostni\u{00A0}mechanismus ISDS neumoznil prihlaseni."), AddressBlockedException::class],
            'with a tag inside'   => [$page('Bezpecnostni mechanismus <b>ISDS</b> neumoznil prihlaseni.'), AddressBlockedException::class],
            // UTF-8 still, though the cut splits the two bytes of a ž
            'cut in a letter'     => [str_pad('<p>Bezpečnostní mechanismus ISDS neumožnil přihlášení.</p><p>', 4095, 'x') . str_repeat('ž', 100), AddressBlockedException::class],
            'the login blocked'   => [self::fixture('unauthorized.html'), AuthException::class],
            'past the first 4 KB' => [$page(str_repeat('<i></i>', 600) . 'Bezpecnostni mechanismus ISDS neumoznil prihlaseni.'), AuthException::class],
        ];
    }

    // the address of the server, not the login: ISDS may write it in other letters, a proxy re-encode it
    #[DataProvider('pages401')]
    public function test_answer_401BlockedAddressInAnyLetters(string $body, string $class): void
    {
        try {
            Soap::answer(new Response(401, $body), 'GetOwnerInfoFromLogin2');
            self::fail('AuthException expected');
        } catch (AuthException $e) {
            self::assertSame($class, $e::class);
        }
    }

    // the start of the body only, as text: no style or script, entities decoded
    public function test_answer_excerptOfHtml(): void
    {
        $html = '<html><head><style>body { color: red }</style><script>var x = 1;</script></head><body><p>Login &amp; password</p>'
            . str_repeat('<p>more</p>', 100000) . '</body></html>';
        try {
            Soap::answer(new Response(500, $html), 'CreateMessage');
            self::fail('TransportException expected');
        } catch (TransportException $e) {
            self::assertStringContainsString('Login & password more', $e->getMessage());
            self::assertStringNotContainsString('color', $e->getMessage());
            self::assertStringNotContainsString('var x', $e->getMessage());
            self::assertLessThan(400, strlen($e->getMessage()));
        }
    }

    public function test_answer_chainsWhyItIsNoSoapAnswer(): void
    {
        try {
            Soap::answer(new Response(200, "<!DOCTYPE html>\n<html/>"), 'CreateMessage');
            self::fail('TransportException expected');
        } catch (TransportException $e) {
            self::assertInstanceOf(\UnexpectedValueException::class, $e->getPrevious());
            self::assertStringContainsString('DOCTYPE', $e->getPrevious()->getMessage());
        }
    }

    public function test_answer_refusalCarriesIsdsText(): void
    {
        try {
            Soap::answer(new Response(200, self::fixture('create-message-1233.xml')), 'CreateMessage');
            self::fail('IsdsException expected');
        } catch (IsdsException $e) {
            self::assertSame('Odesilatel ani příjemce datové zprávy není OVM a zpráva je označena jako nekomerční.', $e->getMessage());
        }
    }

    // without a text of ISDS, only a refusal says "refused"
    #[DataProvider('statusesWithoutText')]
    public function test_answer_statusWithoutText(string $code, string $message): void
    {
        try {
            Soap::answer(new Response(200, self::created($code, null, '')), 'CreateMessage');
            self::fail('IsdsException expected');
        } catch (IsdsException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    public static function statusesWithoutText(): array
    {
        return [
            'refused'        => ['1233', 'ISDS refused CreateMessage (status 1233)'],
            'too many calls' => ['3008', 'ISDS did not complete CreateMessage (status 3008)'],
            'internal error' => ['9200', 'ISDS did not complete CreateMessage (status 9200)'],
            'no status'      => ['', 'ISDS answered CreateMessage without a status'],
        ];
    }

    public function test_answer_faultTextOnOneLine(): void
    {
        $long = "Validation\n\terror:\u{85}\x7F " . str_repeat('x', 1000);
        try {
            Soap::answer(new Response(400, str_replace('Validation error:', $long, self::fixture('fault.xml'))), 'CreateMessage');
            self::fail('IsdsException expected');
        } catch (IsdsException $e) {
            self::assertStringStartsWith('ISDS refused the request: Validation error: xxx', $e->getMessage());
            self::assertStringEndsWith('…', $e->getMessage());
        }
    }

    #[DataProvider('successCodes')]
    public function test_answer_success(string $code): void
    {
        $xml    = self::soap('<p:CheckDataBoxResponse xmlns:p="http://isds.czechpoint.cz/v20"><p:dbState>1</p:dbState><p:dbStatus><p:dbStatusCode>' . $code . '</p:dbStatusCode><p:dbStatusMessage>ok</p:dbStatusMessage></p:dbStatus></p:CheckDataBoxResponse>');
        $answer = Soap::answer(new Response(200, $xml), 'CheckDataBox');

        self::assertSame($code, $answer->code);
        self::assertSame('1', $answer->value('isds:dbState'));
    }

    // "other states starting with 00 may be taken as success" (WS_manipulace_s_datovymi_zpravami.pdf 1.3)
    public static function successCodes(): array
    {
        return ['0000' => ['0000'], 'nothing found' => ['0002']];
    }

    public function test_quote_oneLineCut(): void
    {
        self::assertSame('a b c d', Soap::quote(" a\n\tb\x01\x7Fc\u{85}d\r\n"));
        self::assertSame('Žl…', Soap::quote('Žluťoučký', 3));
        self::assertTrue(mb_check_encoding(Soap::quote("a\xE8b"), 'UTF-8'));
    }

    #[DataProvider('times')]
    public function test_timestamp_readsZones(string $text, int $expected): void
    {
        self::assertSame($expected, Soap::timestamp($text));
    }

    public static function times(): array
    {
        return [
            'summer, fraction'         => ['2010-10-11T11:18:17.308+02:00', 1286788697],
            'winter'                   => ['2010-03-24T16:07:26.117+01:00', 1269443246],
            'utc'                      => ['2010-10-11T09:18:17Z', 1286788697],
            'no zone is Czech, winter' => ['2013-03-25T10:00:00', 1364202000],
            'no zone is Czech, summer' => ['2013-07-01T10:00:00', 1372665600],
            'long fraction'            => ['2010-10-11T11:18:17.308123+02:00', 1286788697],
            'farthest offset'          => ['2010-10-11T11:18:17-14:00', 1286846297],
        ];
    }

    #[DataProvider('notTimes')]
    public function test_timestamp_refuses(string $text): void
    {
        $this->expectException(TransportException::class);
        Soap::timestamp($text);
    }

    public static function notTimes(): array
    {
        return [
            'empty'              => [''],
            'words'              => ['now'],
            'relative'           => ['+1 day'],
            'date only'          => ['2010-10-11'],
            'month 13'           => ['2010-13-11T11:18:17Z'],
            '30 February'        => ['2010-02-30T10:00:00Z'],
            '24:00'              => ['2010-01-01T24:00:00Z'],
            'second 60'          => ['2010-10-11T11:18:60Z'],
            'hour of summertime' => ['2013-03-31T02:30:00'],
            'offset over 14:00'  => ['2010-10-11T11:18:17+14:01'],
            'minute 60'          => ['2010-10-11T11:18:17+01:60'],
        ];
    }

    public function test_dateTime_utcWithZone(): void
    {
        self::assertSame('2010-10-11T09:18:17Z', Soap::dateTime(1286788697));
    }
}
