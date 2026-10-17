<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Credential\Secret;
use AA\Http\ClientInterface;
use AA\Http\Response;
use AA\Http\ResponseTooLargeException;
use AA\Http\TransportException as HttpTransportException;
use AA\Isds\Account;
use AA\Isds\Client;
use AA\Isds\File;
use AA\Isds\IsdsException;
use AA\Isds\Message;
use AA\Isds\SendResult;
use AA\Isds\SentMessage;
use AA\Isds\Soap;
use AA\Isds\StateChange;
use AA\Isds\TransportException;
use AA\Isds\UnavailableException;
use AA\Isds\UserInfo;
use AA\Util\Libxml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Unit\Fixtures\FakeHttp;

final class ClientTest extends TestCase
{
    private const LOGIN    = 'user01';
    private const PASSWORD = 'Pa55-word-never-shown';

    /** 2026-10-01 10:00:00 and 12:00:00 CEST */
    private const FROM = 1790841600;
    private const TO   = 1790848800;

    /**
     * Every operation over received messages, from the WSDLs (dm_operations, dm_info, dm_VoDZ; ConfirmDelivery
     * from older versions). Listing received messages delivers them; the others need them listed first.
     */
    private const RECEIVED = [
        'GetListOfReceivedMessages', 'MessageDownload', 'SignedMessageDownload', 'MessageEnvelopeDownload',
        'MarkMessageAsDownloaded', 'GetDeliveryInfo', 'GetSignedDeliveryInfo', 'GetMessageAuthor', 'GetMessageAuthor2',
        'GetListForNotifications', 'RegisterForNotifications', 'SuspMessageReport', 'EraseMessage',
        'GetListOfErasedMessages', 'PickUpAsyncResponse', 'BigMessageDownload', 'SignedBigMessageDownload',
        'DownloadAttachment', 'ConfirmDelivery',
    ];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/FakeHttp.php';
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
    }

    private static function answering(string $xml, int $status = 200): FakeHttp
    {
        return new FakeHttp([new Response($status, $xml)]);
    }

    private static function client(ClientInterface $http, string $environment = Account::TEST): Client
    {
        return new Client(new Account($environment, self::LOGIN, new Secret(self::PASSWORD)), $http);
    }

    /** @param \Closure(): \Throwable $failure made inside request(), so that its trace has the request's arguments */
    private static function failing(\Closure $failure): ClientInterface
    {
        return new class ($failure) implements ClientInterface {
            public function __construct(private readonly \Closure $failure) {}

            public function request(string $method, #[\SensitiveParameter] string $url, #[\SensitiveParameter] ?string $body = null, #[\SensitiveParameter] array $headers = []): Response
            {
                throw ($this->failure)();
            }
        };
    }

    /** the message create-message-request.xml was written for */
    private static function message(): Message
    {
        return new Message('kv62bqf', 'Pozvánka na setkání Zdravých měst', [
            new File('Dopis.html', 'text/html', File::MAIN, '<p>Dobrý den</p>'),
            new File('Příloha č. 1.pdf', 'application/pdf', File::ENCLOSURE, '%PDF-1.4 test'),
        ], 'a1b2-c3d4', 'starosta');
    }

    /** the operation element of a sent envelope */
    private static function operation(string $envelope): \DOMElement
    {
        $xpath = new \DOMXPath(Libxml::load($envelope));
        $xpath->registerNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        $nodes = $xpath->query('/soap:Envelope/soap:Body/*');
        self::assertNotFalse($nodes);
        self::assertSame(1, $nodes->length, 'one operation in the body');
        $element = $nodes->item(0);
        self::assertInstanceOf(\DOMElement::class, $element);
        return $element;
    }

    private static function assertValidRequest(string $envelope, string $op, string $xsd): void
    {
        $element = self::operation($envelope);
        self::assertSame([Soap::NS, $op], [$element->namespaceURI, $element->localName]);
        $request = new \DOMDocument();
        $request->appendChild($request->importNode($element, true));
        $schema = self::fixture('xsd/' . $xsd);
        [$valid, $errors] = Libxml::run(static fn(): array => [$request->schemaValidateSource($schema), Libxml::describe(5, 300)]);
        self::assertTrue($valid, "$op does not match $xsd: $errors");
    }

    /** @return array<string, string> child name => text, 'nil' for xsi:nil */
    private static function children(\DOMElement $parent): array
    {
        $out = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $out[$child->localName] = ($child->getAttributeNS(Soap::XSI, 'nil') === 'true') ? 'nil' : $child->textContent;
            }
        }
        return $out;
    }

    /** $xml with the first match of $pattern replaced */
    private static function changed(string $fixture, string $pattern, string $replacement): string
    {
        $xml = (string) preg_replace($pattern, $replacement, self::fixture($fixture), 1, $count);
        self::assertSame(1, $count, "$pattern is not in $fixture");
        return $xml;
    }

    /** @param \Closure(Client): mixed $call */
    private static function assertUncertain(string $xml, \Closure $call): void
    {
        try {
            $call(self::client(self::answering($xml)));
            self::fail('TransportException expected');
        } catch (TransportException $e) {
            self::assertTrue($e->mayHaveBeenSent());
        }
    }

    /**
     * The request every public method sends - several methods, so no single one in the name
     * @param \Closure(Client): mixed $call
     */
    #[DataProvider('requests')]
    public function test_request_matchesXsdAndEndpoint(string $op, string $xsd, string $answer, string $environment, string $url, \Closure $call): void
    {
        $http = self::answering(self::fixture($answer));

        $call(self::client($http, $environment));

        self::assertCount(1, $http->calls);
        self::assertSame('POST', $http->calls[0]['method']);
        self::assertSame($url, $http->calls[0]['url']);
        self::assertSame([
            'Content-Type'  => 'text/xml; charset=utf-8',
            'SOAPAction'    => '""',
            'Authorization' => 'Basic ' . base64_encode(self::LOGIN . ':' . self::PASSWORD),
        ], $http->calls[0]['headers']);
        self::assertValidRequest((string) $http->calls[0]['body'], $op, $xsd);
    }

    public static function requests(): array
    {
        $test = 'https://ws1.datovka-test.gov.cz/DS/';
        return [
            'send, test'       => ['CreateMessage', 'dmBaseTypes.xsd', 'create-message-ok.xml', Account::TEST, $test . 'dz', static fn(Client $c) => $c->createMessage(self::message())],
            'send, flags set'  => ['CreateMessage', 'dmBaseTypes.xsd', 'create-message-ok.xml', Account::TEST, $test . 'dz', static fn(Client $c) => $c->createMessage(new Message('kv62bqf', 'Věc', [new File('a.pdf', 'application/pdf', File::MAIN, 'x')], personalDelivery: true, allowSubstDelivery: false))],
            'state changes'    => ['GetMessageStateChanges', 'dmBaseTypes.xsd', 'state-changes.xml', Account::TEST, $test . 'dx', static fn(Client $c) => $c->stateChanges(self::FROM, self::TO)],
            'sent messages'    => ['GetListOfSentMessages', 'dmBaseTypes.xsd', 'sent-messages.xml', Account::TEST, $test . 'dx', static fn(Client $c) => $c->sentMessages(self::FROM, self::TO, 1001, 500)],
            'check box'        => ['CheckDataBox', 'dbTypes.xsd', 'check-data-box.xml', Account::TEST, $test . 'df', static fn(Client $c) => $c->checkDataBox('kv62bqf')],
            'user'             => ['GetUserInfoFromLogin2', 'dbTypes.xsd', 'user-info.xml', Account::TEST, $test . 'DsManage', static fn(Client $c) => $c->userInfo()],
            'password'         => ['GetPasswordInfo', 'dbTypes.xsd', 'password-info.xml', Account::TEST, $test . 'DsManage', static fn(Client $c) => $c->passwordExpiry()],
            'owner'            => ['GetOwnerInfoFromLogin2', 'dbTypes.xsd', 'owner-info.xml', Account::TEST, $test . 'DsManage', static fn(Client $c) => $c->ownerBox()],
        ];
    }

    // element order, nil for the empty ones, files in order with their attributes
    public function test_createMessage_matchesRequestFixture(): void
    {
        $http = self::answering(self::fixture('create-message-ok.xml'));
        self::client($http)->createMessage(self::message());

        $sent = new \DOMDocument();
        $sent->loadXML((string) $http->calls[0]['body']);
        $expected                     = new \DOMDocument();
        $expected->preserveWhiteSpace = false;
        $expected->loadXML(self::fixture('create-message-request.xml'));
        self::assertSame($expected->C14N(), $sent->C14N());
    }

    #[DataProvider('envelopeValues')]
    public function test_createMessage_envelopeValues(Message $message, array $expected): void
    {
        $http = self::answering(self::fixture('create-message-ok.xml'));
        self::client($http)->createMessage($message);

        $envelope = self::operation((string) $http->calls[0]['body'])->firstElementChild;
        self::assertInstanceOf(\DOMElement::class, $envelope);
        self::assertSame($expected, array_intersect_key(self::children($envelope), $expected));
    }

    public static function envelopeValues(): array
    {
        $file   = [new File('a.pdf', 'application/pdf', File::MAIN, 'x')];
        $fields = static fn(string $toHands, string $ref, string $personal, string $subst): array
            => ['dmToHands' => $toHands, 'dmSenderRefNumber' => $ref, 'dmPersonalDelivery' => $personal, 'dmAllowSubstDelivery' => $subst];
        return [
            'empty texts are nil, default flags' => [new Message('kv62bqf', 'Věc', $file), $fields('nil', 'nil', 'false', 'nil')],
            'personal, no substitute delivery'   => [new Message('kv62bqf', 'Věc', $file, 'r1', 'starosta', true, false), $fields('starosta', 'r1', 'true', 'false')],
            'substitute delivery allowed'        => [new Message('kv62bqf', 'Věc', $file, allowSubstDelivery: true), $fields('nil', 'nil', 'false', 'true')],
        ];
    }

    public function test_createMessage_result(): void
    {
        $result = self::client(self::answering(self::fixture('create-message-ok.xml')))->createMessage(self::message());

        self::assertEquals(new SendResult('1835603', '0000', 'Provedeno úspěšně.'), $result);
    }

    // a success without a usable id leaves the message's fate unknown
    #[DataProvider('badIds')]
    public function test_createMessage_successWithoutIdIsUncertain(string $replacement): void
    {
        self::assertUncertain(self::changed('create-message-ok.xml', '~<p:dmID>1835603</p:dmID>~', $replacement), static fn(Client $c) => $c->createMessage(self::message()));
    }

    public static function badIds(): array
    {
        return [
            'none'          => [''],
            'empty'         => ['<p:dmID></p:dmID>'],
            'nil'           => ['<p:dmID xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:nil="true"/>'],
            'not digits'    => ['<p:dmID>18356O3</p:dmID>'],
            'over 20 digits' => ['<p:dmID>' . str_repeat('1', 21) . '</p:dmID>'],
        ];
    }

    /** @param \Closure(): \Throwable $failure */
    #[DataProvider('httpFailures')]
    public function test_createMessage_noAnswer(\Closure $failure, bool $sent): void
    {
        try {
            self::client(self::failing($failure))->createMessage(self::message());
            self::fail('TransportException expected');
        } catch (TransportException $e) {
            self::assertSame($sent, $e->mayHaveBeenSent());
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    /** every kind of HTTP failure: TransportExceptionTest */
    public static function httpFailures(): array
    {
        $url = '"https://ws1.datovka-test.gov.cz/DS/dz"';
        return [
            'dns: never left'         => [static fn() => new HttpTransportException('No answer', 0, new \RuntimeException("Could not resolve host: ws1.datovka-test.gov.cz for $url.")), false],
            'timeout: may have left'  => [static fn() => new HttpTransportException('No answer', 0, new TimeoutException("Idle timeout reached for $url.")), true],
            'too large: it did leave' => [static fn() => new ResponseTooLargeException('The answer is over 8388608 bytes'), true],
        ];
    }

    #[DataProvider('boxStates')]
    public function test_checkDataBox_state(string $state, int $expected): void
    {
        $http = self::answering(self::changed('check-data-box.xml', '~<p:dbState>1</p:dbState>~', "<p:dbState>$state</p:dbState>"));

        self::assertSame($expected, self::client($http)->checkDataBox(" KV62BQF\n"));
        self::assertSame(['dbID' => 'kv62bqf'], self::children(self::operation((string) $http->calls[0]['body'])));
    }

    public static function boxStates(): array
    {
        return ['accessible' => ['1', 1], 'deleted' => ['5', 5]];
    }

    // 0 or none is an error of ISDS (WS_vyhledavani_datovych_schranek.pdf 2.3)
    #[DataProvider('badBoxStates')]
    public function test_checkDataBox_badStateIsUncertain(string $replacement): void
    {
        self::assertUncertain(self::changed('check-data-box.xml', '~<p:dbState>1</p:dbState>~', $replacement), static fn(Client $c) => $c->checkDataBox('kv62bqf'));
    }

    public static function badBoxStates(): array
    {
        return ['none' => [''], 'empty' => ['<p:dbState></p:dbState>'], 'error' => ['<p:dbState>0</p:dbState>'], 'unknown' => ['<p:dbState>6</p:dbState>']];
    }

    public function test_checkDataBox_noSuchBox(): void
    {
        $xml = self::changed('check-data-box.xml', '~<p:dbState>1</p:dbState>(.*)0000(.*)Provedeno úspěšně.~s', '${1}5001${2}Schránka nenalezena');

        self::assertSame(Client::NO_BOX, self::client(self::answering($xml))->checkDataBox('kv62bqf'));
    }

    #[DataProvider('otherBoxFailures')]
    public function test_checkDataBox_otherFailuresThrow(string $xml, int $status, string $class): void
    {
        try {
            self::client(self::answering($xml, $status))->checkDataBox('kv62bqf');
            self::fail("$class expected");
        } catch (IsdsException $e) {
            self::assertSame($class, $e::class);
        }
    }

    public static function otherBoxFailures(): array
    {
        $refused = self::changed('check-data-box.xml', '~<p:dbState>1</p:dbState>(.*)0000~s', '${1}1007');
        return [
            'another refusal'        => [$refused, 200, IsdsException::class],
            '5001 as a server fault' => [str_replace('SOAP-ENV:Client', '5001', self::fixture('fault.xml')), 500, TransportException::class],
        ];
    }

    public function test_checkDataBox_refusesNotABoxWithoutCalling(): void
    {
        $http = new FakeHttp();
        try {
            self::client($http)->checkDataBox('kv62bq');
            self::fail('InvalidArgumentException expected');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $http->calls);
        }
    }

    public function test_stateChanges_list(): void
    {
        $http    = self::answering(self::fixture('state-changes.xml'));
        $changes = self::client($http)->stateChanges(self::FROM, self::TO);

        self::assertEquals([new StateChange('169100', 4, 1286788697), new StateChange('169100', 6, 1286788703)], $changes);
        self::assertSame(['dmFromTime' => '2026-10-01T08:00:00Z', 'dmToTime' => '2026-10-01T10:00:00Z'], self::children(self::operation((string) $http->calls[0]['body'])));
    }

    // a record is never skipped: it could be the very message the caller waits for
    #[DataProvider('badChanges')]
    public function test_stateChanges_badRecordIsUncertain(string $pattern, string $replacement): void
    {
        self::assertUncertain(self::changed('state-changes.xml', $pattern, $replacement), static fn(Client $c) => $c->stateChanges(self::FROM, self::TO));
    }

    public static function badChanges(): array
    {
        return [
            'no id'           => ['~<q:dmID>169100</q:dmID>~', ''],
            'id not digits'   => ['~<q:dmID>169100</q:dmID>~', '<q:dmID>16910a</q:dmID>'],
            'no time'         => ['~<q:dmEventTime>[^<]*</q:dmEventTime>~', ''],
            'no state'        => ['~<q:dmMessageStatus>4</q:dmMessageStatus>~', ''],
            'state no number' => ['~<q:dmMessageStatus>4</q:dmMessageStatus>~', '<q:dmMessageStatus>x</q:dmMessageStatus>'],
        ];
    }

    public function test_stateChanges_refusesReversedWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::client(new FakeHttp())->stateChanges(self::TO, self::FROM);
    }

    public function test_sentMessages_list(): void
    {
        $http     = self::answering(self::fixture('sent-messages.xml'));
        $messages = self::client($http)->sentMessages(self::FROM, self::TO);

        self::assertEquals([
            new SentMessage('78021', 6, '', 'jyg3h5s', 1270028470, 1270456811),
            new SentMessage('77725', 5, 'banka_2011_09_15_123', 'qcssvxh', 1269443246, 1270303646),
            new SentMessage('77726', 4, '', 'qcssvxh', 1269443246, null),
        ], $messages);
        self::assertSame([
            'dmFromTime'         => '2026-10-01T08:00:00Z',
            'dmToTime'           => '2026-10-01T10:00:00Z',
            'dmSenderOrgUnitNum' => 'nil',
            'dmStatusFilter'     => '-1',
            'dmOffset'           => '1',
            'dmLimit'            => '1000',
        ], self::children(self::operation((string) $http->calls[0]['body'])));
    }

    #[DataProvider('badSentRecords')]
    public function test_sentMessages_badRecordIsUncertain(string $pattern, string $replacement): void
    {
        self::assertUncertain(self::changed('sent-messages.xml', $pattern, $replacement), static fn(Client $c) => $c->sentMessages(self::FROM, self::TO));
    }

    public static function badSentRecords(): array
    {
        return [
            'no id'              => ['~<q:dmID>78021</q:dmID>~', ''],
            'nil id'             => ['~<q:dmID>78021</q:dmID>~', '<q:dmID xsi:nil="true"/>'],
            'no state'           => ['~<q:dmMessageStatus>6</q:dmMessageStatus>~', ''],
            'no recipient'       => ['~<q:dbIDRecipient>jyg3h5s</q:dbIDRecipient>~', ''],
            'recipient no box'   => ['~<q:dbIDRecipient>jyg3h5s</q:dbIDRecipient>~', '<q:dbIDRecipient>jyg3h5</q:dbIDRecipient>'],
            'delivery not times' => ['~<q:dmDeliveryTime>[^<]*</q:dmDeliveryTime>~', '<q:dmDeliveryTime>yesterday</q:dmDeliveryTime>'],
        ];
    }

    // the list is one of the calls ISDS limits (WS_manipulace_s_datovymi_zpravami.pdf 1.9.1)
    public function test_sentMessages_tooManyCallsIsUnavailable(): void
    {
        $xml = self::changed('sent-messages.xml', '~<q:dmRecords>.*</q:dmRecords>(.*)0000(.*)Provedeno úspěšně.~s', '${1}3008${2}Příliš mnoho paralelních požadavků');
        try {
            self::client(self::answering($xml))->sentMessages(self::FROM, self::TO);
            self::fail('UnavailableException expected');
        } catch (UnavailableException $e) {
            self::assertSame('3008', $e->isdsCode());
        }
    }

    #[DataProvider('badPages')]
    public function test_sentMessages_refusesPage(int $offset, int $limit): void
    {
        $http = new FakeHttp();
        try {
            self::client($http)->sentMessages(self::FROM, self::TO, $offset, $limit);
            self::fail('InvalidArgumentException expected');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $http->calls);
        }
    }

    public static function badPages(): array
    {
        return ['offset 0' => [0, 10], 'limit 0' => [1, 0], 'over the limit' => [1, Client::MAX_LIST + 1]];
    }

    public function test_userInfo_privilegesAndType(): void
    {
        self::assertEquals(new UserInfo(28, 'ENTRUSTED_USER'), self::client(self::answering(self::fixture('user-info.xml')))->userInfo());
    }

    public function test_userInfo_noPrivilegesAreNone(): void
    {
        $xml = self::changed('user-info.xml', '~<p:userPrivils>28</p:userPrivils>~', '');

        self::assertEquals(new UserInfo(0, 'ENTRUSTED_USER'), self::client(self::answering($xml))->userInfo());
    }

    #[DataProvider('badUsers')]
    public function test_userInfo_badAnswerIsUncertain(string $pattern, string $replacement): void
    {
        self::assertUncertain(self::changed('user-info.xml', $pattern, $replacement), static fn(Client $c) => $c->userInfo());
    }

    public static function badUsers(): array
    {
        return [
            'no user'              => ['~<p:dbUserInfo>.*</p:dbUserInfo>~s', ''],
            'privileges no number' => ['~<p:userPrivils>28</p:userPrivils>~', '<p:userPrivils>all</p:userPrivils>'],
        ];
    }

    // nil (WS_souvisejici_s_pristupem_do_ISDS.pdf 1.8) or left out (dbTypes.xsd) means "never"
    public function test_passwordExpiry_dateOrNever(): void
    {
        $missing = self::changed('password-info.xml', '~<p:pswExpDate>[^<]*</p:pswExpDate>~', '');

        self::assertSame(1309952019, self::client(self::answering(self::fixture('password-info.xml')))->passwordExpiry());
        self::assertNull(self::client(self::answering(self::fixture('password-info-nil.xml')))->passwordExpiry());
        self::assertNull(self::client(self::answering($missing))->passwordExpiry());
    }

    #[DataProvider('badExpiries')]
    public function test_passwordExpiry_badDateIsUncertain(string $replacement): void
    {
        self::assertUncertain(self::changed('password-info.xml', '~<p:pswExpDate>[^<]*</p:pswExpDate>~', $replacement), static fn(Client $c) => $c->passwordExpiry());
    }

    public static function badExpiries(): array
    {
        return ['empty, not nil' => ['<p:pswExpDate/>'], 'blank' => ['<p:pswExpDate> </p:pswExpDate>'], 'no date' => ['<p:pswExpDate>never</p:pswExpDate>']];
    }

    public function test_ownerBox_id(): void
    {
        self::assertSame('kv62bqf', self::client(self::answering(self::fixture('owner-info.xml')))->ownerBox());
    }

    #[DataProvider('badOwners')]
    public function test_ownerBox_badIdIsUncertain(string $replacement): void
    {
        self::assertUncertain(self::changed('owner-info.xml', '~<p:dbID>kv62bqf</p:dbID>~', $replacement), static fn(Client $c) => $c->ownerBox());
    }

    public static function badOwners(): array
    {
        return ['none' => [''], 'empty' => ['<p:dbID></p:dbID>'], 'not a box' => ['<p:dbID>kv62bqf1</p:dbID>']];
    }

    // the constant OPERATIONS, no method; a change of the list has to change this test too - on purpose
    public function test_operations_onlyTheAllowedOnes(): void
    {
        self::assertSame([
            'CreateMessage'          => 'dz',
            'GetMessageStateChanges' => 'dx',
            'GetListOfSentMessages'  => 'dx',
            'CheckDataBox'           => 'df',
            'GetUserInfoFromLogin2'  => 'DsManage',
            'GetOwnerInfoFromLogin2' => 'DsManage',
            'GetPasswordInfo'        => 'DsManage',
        ], Client::OPERATIONS);
    }

    // the constant OPERATIONS and Soap::envelope(), which refuses what is not in it
    public function test_operations_noneOverReceivedMessages(): void
    {
        self::assertSame([], array_values(array_intersect(self::RECEIVED, array_keys(Client::OPERATIONS))));
        foreach (self::RECEIVED as $op) {
            try {
                Soap::envelope(Soap::request($op));
                self::fail("$op must be refused");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // the constant OPERATIONS: request element, endpoint and soapAction "" as the WSDLs say
    public function test_operations_matchWsdl(): void
    {
        $found = [];
        foreach (glob(__DIR__ . '/fixtures/wsdl/*.wsdl') ?: [] as $path) {
            $wsdl = new \DOMDocument();
            $wsdl->load($path);
            $xpath = new \DOMXPath($wsdl);
            $xpath->registerNamespace('w', 'http://schemas.xmlsoap.org/wsdl/');
            $xpath->registerNamespace('s', 'http://schemas.xmlsoap.org/wsdl/soap/');
            $service = basename((string) parse_url($xpath->evaluate('string(//w:service/w:port/s:address/@location)'), PHP_URL_PATH));
            foreach ($xpath->query('//w:portType/w:operation') ?: [] as $operation) {
                if (!$operation instanceof \DOMElement) {
                    continue;
                }
                $op      = $operation->getAttribute('name');
                $input   = substr($xpath->evaluate('string(w:input/@message)', $operation), 4);
                $element = $xpath->evaluate("string(//w:message[@name='$input']/w:part/@element)");
                $action  = $xpath->evaluate("string(//w:binding/w:operation[@name='$op']/s:operation/@soapAction)");
                $found[$op] = [$service, $element, $action];
            }
        }
        foreach (Client::OPERATIONS as $op => $service) {
            self::assertSame([$service, "tns:$op", ''], $found[$op] ?? null, $op);
        }
    }

    /**
     * Every frame of every exception in the chain, arguments included (PHP's default outside
     * production), up to this test - PHPUnit's own frames below hold the whole suite.
     * @param \Closure(): ClientInterface $http
     */
    #[DataProvider('failures')]
    public function test_createMessage_errorsNeverShowThePassword(\Closure $http): void
    {
        $ignore = (string) ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            self::client($http())->createMessage(self::message());
            self::fail('IsdsException expected');
        } catch (IsdsException $e) {
            $seen = '';
            for ($x = $e; !is_null($x); $x = $x->getPrevious()) {
                $seen .= $x->getMessage() . "\n";
                foreach ($x->getTrace() as $frame) {
                    $seen .= ($frame['class'] ?? '') . '::' . $frame['function'] . print_r($frame['args'] ?? [], true);
                    if ((($frame['class'] ?? '') === self::class) && ($frame['function'] === __FUNCTION__)) {
                        break;
                    }
                }
            }
            self::assertStringContainsString('Pozvánka na setkání', $seen, 'the trace keeps arguments');
            self::assertStringNotContainsString(self::PASSWORD, $seen);
            self::assertStringNotContainsString(base64_encode(self::LOGIN . ':' . self::PASSWORD), $seen);
        } finally {
            ini_set('zend.exception_ignore_args', $ignore);
        }
    }

    public static function failures(): array
    {
        // closures: data providers run before setUpBeforeClass() loads FakeHttp
        return [
            'login refused' => [static fn() => self::answering(self::fixture('unauthorized.html'), 401)],
            'fault'         => [static fn() => self::answering(self::fixture('fault.xml'), 500)],
            'status 1233'   => [static fn() => self::answering(self::fixture('create-message-1233.xml'))],
            'no answer'     => [static fn() => self::failing(static fn() => new HttpTransportException('No answer', 0, new TimeoutException('Idle timeout reached')))],
        ];
    }

    // a reused connection could send a message twice; a 20 MB upload is silent until its end
    public function test_http_freshConnectionsAndLimits(): void
    {
        $limit = 8 * 1024 * 1024;
        $seen  = [];
        $http  = Client::http(new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen, $limit): MockResponse {
            $seen[] = [$options['max_duration'], $options['timeout'], $options['extra']['curl'] ?? []];
            return new MockResponse(str_repeat('x', $limit + count($seen) - 1));     // the limit, then one byte over
        }));

        self::assertSame($limit, strlen($http->request('POST', 'https://ws1.datovka-test.gov.cz/DS/dz', 'x')->body));
        try {
            $http->request('POST', 'https://ws1.datovka-test.gov.cz/DS/dz', 'x');
            self::fail('ResponseTooLargeException expected');
        } catch (ResponseTooLargeException) {
            self::assertSame([300.0, 300.0, [\CURLOPT_FRESH_CONNECT => true, \CURLOPT_FORBID_REUSE => true]], $seen[0]);
        }
    }
}
