<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Credential\Secret;
use AA\Http\ClientCertificate;
use AA\Http\ClientInterface;
use AA\Http\Response;
use AA\Http\ResponseTooLargeException;
use AA\Http\TransportException as HttpTransportException;
use AA\Isds\Account;
use AA\Isds\AddressBlockedException;
use AA\Isds\AuthException;
use AA\Isds\Client;
use AA\Isds\ConfigException;
use AA\Isds\File;
use AA\Isds\IsdsException;
use AA\Isds\LoginLatch;
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
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\Unit\Fixtures\Certificates;
use Tests\Unit\Fixtures\FakeHttp;
use Tests\Unit\Isds\Fixtures\MemoryLatch;

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

    private const PASSPHRASE = 'Pass-phrase-never-shown';

    /** the certificates of this run, made in setUpBeforeClass() */
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';     // the constants Secret::key() derives from
        require_once __DIR__ . '/../fixtures/FakeHttp.php';
        require_once __DIR__ . '/../fixtures/Certificates.php';
        require_once __DIR__ . '/fixtures/MemoryLatch.php';
        self::$dir           = Certificates::directory();
        [$certificate, $key] = Certificates::make();
        Certificates::file(self::$dir, 'cert.pem', $certificate);
        Certificates::file(self::$dir, 'both.pem', $certificate . $key);
        Certificates::file(self::$dir, 'encrypted.key', Certificates::encrypted($key, self::PASSPHRASE));
        Certificates::file(self::$dir, 'other.key', Certificates::make('Another', 43)[1]);
        Certificates::file(self::$dir, 'garbage.pem', 'not a certificate');
        Certificates::file(self::$dir, 'locked.pem', $certificate . $key, 0o000);
    }

    public static function tearDownAfterClass(): void
    {
        Certificates::remove(self::$dir);
    }

    /** an account of the certificate and the key in files of this run */
    private static function certificateAccount(string $certificate, string $key = '', string $passphrase = '', string $environment = Account::PRODUCTION, string $box = 'kv62bqf'): Account
    {
        $file = static fn(string $name): string => ($name === '') ? '' : self::$dir . "/$name";

        return Account::withCertificate($environment, new ClientCertificate($file($certificate), $file($key), new Secret($passphrase)), $box);
    }

    /**
     * A transport answering every request with $xml; the HTTP client works on a copy of it, so the
     * requests are counted in $seen, not by getRequestsCount()
     * @param ?list<array<string, mixed>> $seen the URL and the options of every request
     */
    private static function transport(string $xml, int $status = 200, ?array &$seen = null): MockHttpClient
    {
        $seen = [];

        return new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen, $xml, $status): MockResponse {
            $seen[] = ['url' => $url] + $options;
            return new MockResponse($xml, ['http_code' => $status]);
        });
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
    }

    private static function answering(string $xml, int $status = 200): FakeHttp
    {
        return new FakeHttp([new Response($status, $xml)]);
    }

    /** a latch of its own unless one is given: a refusal must not latch another test */
    private static function client(?ClientInterface $http = null, string $environment = Account::TEST, ?LoginLatch $latch = null, ?HttpClientInterface $transport = null, string $password = self::PASSWORD, string $login = self::LOGIN): Client
    {
        return new Client(new Account($environment, $login, new Secret($password)), $http, $transport, $latch ?? new MemoryLatch());
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

    // 0 or none is an error of ISDS (WS_vyhledavani_datovych_schranek.pdf 2.3): an answer about the box, which it could not give
    #[DataProvider('badBoxStates')]
    public function test_checkDataBox_noStateWhenIsdsDoesNotSay(string $replacement): void
    {
        $http = self::answering(self::changed('check-data-box.xml', '~<p:dbState>1</p:dbState>~', $replacement));

        self::assertSame(Client::NO_STATE, self::client($http)->checkDataBox('kv62bqf'));
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

    // left out or nil (dbTypes.xsd): not known - "no rights" would be a wrong answer
    public function test_userInfo_noPrivilegesAreUnknown(): void
    {
        foreach (['', '<p:userPrivils xsi:nil="true"/>'] as $replacement) {
            $xml = self::changed('user-info.xml', '~<p:userPrivils>28</p:userPrivils>~', $replacement);

            self::assertEquals(new UserInfo(null, 'ENTRUSTED_USER'), self::client(self::answering($xml))->userInfo());
        }
    }

    // the right each operation needs, from WS_manipulace_s_datovymi_zpravami.pdf 1.4; a change has to change this test
    public function test_rights_ofEveryOperation(): void
    {
        self::assertSame(array_keys(Client::OPERATIONS), array_keys(Client::RIGHTS));
        self::assertSame([4, 8, 8, 16, 0, 0, 0], array_values(Client::RIGHTS));
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

    // a reused connection could send a message twice; a list of 1000 sent messages fits the answer limit
    public function test_call_freshConnectionsAndSizeLimit(): void
    {
        $limit  = 8 * 1024 * 1024;
        $answer = self::fixture('owner-info.xml');
        $answer .= '<!--' . str_repeat('x', $limit - strlen($answer) - 7) . '-->';
        $seen   = [];
        $client = self::client(transport: new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen, $answer): MockResponse {
            $seen[] = $options['extra']['curl'] ?? [];
            return new MockResponse($answer . str_repeat(' ', count($seen) - 1));     // the limit, then one byte over
        }));

        self::assertSame('kv62bqf', $client->ownerBox());
        try {
            $client->ownerBox();
            self::fail('TransportException expected');
        } catch (TransportException $e) {
            self::assertStringContainsString('too large', $e->getMessage());
        }
        self::assertSame([\CURLOPT_FRESH_CONNECT => true, \CURLOPT_FORBID_REUSE => true], $seen[0]);
    }

    // a 20 MB upload is silent until its end; nothing else may hold a run for minutes
    public function test_call_timeLimitByOperation(): void
    {
        $seen      = [];
        $transport = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = [basename($url), $options['max_duration'], $options['timeout']];
            return new MockResponse(self::fixture(str_ends_with($url, '/dz') ? 'create-message-ok.xml' : 'owner-info.xml'));
        });
        $client = self::client(transport: $transport);

        $client->ownerBox();
        $client->createMessage(self::message());
        $client->ownerBox();

        self::assertSame([['DsManage', 30.0, 30.0], ['dz', 300.0, 300.0], ['DsManage', 30.0, 30.0]], $seen);
    }

    // the certificate reaches the connection; Basic auth only where the kind of login asks for it
    #[DataProvider('certificateLogins')]
    public function test_call_certificateLogin(string $environment, string $box): void
    {
        $account = self::certificateAccount('cert.pem', 'encrypted.key', self::PASSPHRASE, $environment, $box);
        $client  = new Client($account, transport: self::transport(self::fixture('owner-info.xml'), seen: $seen), latch: new MemoryLatch());

        self::assertSame('kv62bqf', $client->ownerBox());

        $authorization = array_map(static fn(string $value): string => "Authorization: $value", array_values($account->headers()));
        self::assertSame(
            [$account->url('DsManage'), self::$dir . '/cert.pem', self::$dir . '/encrypted.key', self::PASSPHRASE, $authorization],
            [$seen[0]['url'], $seen[0]['local_cert'] ?? null, $seen[0]['local_pk'] ?? null, $seen[0]['passphrase'] ?? null, array_values(preg_grep('/^Authorization:/i', $seen[0]['headers'] ?? []))],
        );
        self::assertSame($box !== '', $authorization !== [], 'a hosted filing service names the box');
    }

    public static function certificateLogins(): array
    {
        return [
            'system, test'       => [Account::TEST, ''],
            'system, production' => [Account::PRODUCTION, ''],
            'hosted, test'       => [Account::TEST, 'kv62bqf'],
            'hosted, production' => [Account::PRODUCTION, 'kv62bqf'],
        ];
    }

    // inside the TLS handshake it would fail like the network: "the message may have left"
    #[DataProvider('unusableCertificates')]
    public function test_call_unusableCertificateSendsNothing(string $certificate, string $key, string $passphrase, string $expected): void
    {
        if (($certificate === 'locked.pem') && function_exists('posix_geteuid') && (posix_geteuid() === 0)) {
            self::markTestSkipped('root reads any file');
        }
        $transport = self::transport(self::fixture('create-message-ok.xml'), seen: $seen);
        $latch     = new MemoryLatch();
        $client    = new Client(self::certificateAccount($certificate, $key, $passphrase), transport: $transport, latch: $latch);

        try {
            $client->createMessage(self::message());
            self::fail('ConfigException expected');
        } catch (ConfigException $e) {
            self::assertStringStartsWith($expected, $e->getMessage());
            self::assertStringContainsString('nothing was sent', $e->getMessage());
            self::assertStringNotContainsString(self::$dir, $e->getMessage(), 'a template may show it');
        }
        self::assertSame([[], []], [$seen, $latch->rows]);
    }

    public static function unusableCertificates(): array
    {
        return [
            'no certificate'     => ['none.pem', 'encrypted.key', self::PASSPHRASE, 'PHP cannot read the certificate'],
            'unreadable'         => ['locked.pem', '', '', 'PHP cannot read the certificate'],
            'no key'             => ['cert.pem', 'none.key', '', 'PHP cannot read the private key'],
            'not a certificate'  => ['garbage.pem', 'encrypted.key', self::PASSPHRASE, 'The certificate file of the ISDS account holds no certificate'],
            'another passphrase' => ['cert.pem', 'encrypted.key', 'Another-pass-phrase', 'The private key of the ISDS account does not open'],
            'no passphrase'      => ['cert.pem', 'encrypted.key', '', 'The private key of the ISDS account does not open'],
            'another key'        => ['cert.pem', 'other.key', '', 'The private key of the ISDS account does not belong to its certificate'],
        ];
    }

    // a certificate the TLS library still refuses fails before the handshake; its error names the file
    #[DataProvider('certificatesNotLoaded')]
    public function test_call_certificateNotLoadedSendsNothing(string $error): void
    {
        $latch  = new MemoryLatch();
        $client = new Client(self::certificateAccount('both.pem'), transport: new MockHttpClient(new MockResponse('', ['error' => $error])), latch: $latch);

        try {
            $client->createMessage(self::message());
            self::fail('ConfigException expected');
        } catch (ConfigException $e) {
            self::assertSame('This server could not use the certificate of the ISDS account for the connection - nothing was sent; the State Check tells more', $e->getMessage());
        }
        self::assertSame([], $latch->rows);
    }

    public static function certificatesNotLoaded(): array
    {
        return [
            'curl, a weak key'         => ['Could not load PEM client certificate from /etc/aa-isds/nszm.pem, OpenSSL error error:0A00018F:SSL routines::ee key too small, (no key found, wrong passphrase, or wrong file format?)'],
            'curl, the key file'       => ["unable to set private key file: '/etc/aa-isds/nszm.key' type PEM"],
            'php streams, the chain'   => ["fopen(): Unable to set local cert chain file `/etc/aa-isds/nszm.pem'; Check that your cafile/capath settings include details of your certificate and its issuer"],
            'php streams, the key'     => ["fopen(): Unable to set private key file `/etc/aa-isds/nszm.key'"],
        ];
    }

    // a person asks again, but the certificate cannot work: nothing to try, the refusal stays
    public function test_forgetRefusal_unusableCertificateKeepsTheRefusal(): void
    {
        $latch = new MemoryLatch();
        try {
            (new Client(self::certificateAccount('cert.pem', 'encrypted.key', self::PASSPHRASE), transport: self::transport(self::fixture('unauthorized.html'), 401), latch: $latch))->ownerBox();
            self::fail('AuthException expected');
        } catch (AuthException) {
        }
        $rows   = $latch->rows;
        $client = new Client(self::certificateAccount('cert.pem', 'encrypted.key', 'Another-pass-phrase'), transport: self::transport(self::fixture('owner-info.xml')), latch: $latch);

        try {
            $client->forgetRefusal();
            self::fail('ConfigException expected');
        } catch (ConfigException) {
        }
        self::assertSame($rows, $latch->rows);
        self::assertNotNull($latch->refusedAt(self::certificateAccount('cert.pem', 'encrypted.key', self::PASSPHRASE)));
    }

    // the HTTP client of AA is what sends the certificate - a test client would skip it unnoticed
    public function test_construct_refusesAClientForACertificate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client(self::certificateAccount('both.pem'), self::answering(self::fixture('owner-info.xml')));
    }

    // a refused certificate is not tried again, whatever else changes in its files; a new one is
    public function test_call_refusedCertificateUntilANewOne(): void
    {
        $dir   = Certificates::directory();
        $latch = new MemoryLatch();
        [$certificate, $key] = Certificates::make();
        Certificates::file($dir, 'live.pem', $certificate);
        Certificates::file($dir, 'live.key', Certificates::encrypted($key, self::PASSPHRASE));
        // every run reads the files anew
        $run = static function (string $passphrase, MockHttpClient $transport, MemoryLatch $latch) use ($dir): string {
            $account = Account::withCertificate(Account::PRODUCTION, new ClientCertificate("$dir/live.pem", "$dir/live.key", new Secret($passphrase)), 'kv62bqf');
            return (new Client($account, transport: $transport, latch: $latch))->ownerBox();
        };
        try {
            try {
                $run(self::PASSPHRASE, self::transport(self::fixture('unauthorized.html'), 401), $latch);
                self::fail('AuthException expected');
            } catch (AuthException) {
            }
            $changes = [
                'a new passphrase' => static fn(): string => Certificates::file($dir, 'live.key', Certificates::encrypted($key, 'Another-pass-phrase')),
                'a chain after it, other line ends' => static fn(): string => Certificates::file($dir, 'live.pem', str_replace("\n", "\r\n", $certificate . Certificates::make('Another', 43)[0])),
            ];
            foreach ($changes as $change => $apply) {
                $apply();
                $transport = self::transport(self::fixture('owner-info.xml'), seen: $seen);
                try {
                    $run('Another-pass-phrase', $transport, $latch = $latch->nextRun());
                    self::fail("AuthException expected after $change");
                } catch (AuthException $e) {
                    self::assertStringContainsString('AA does not try it again until the certificate changes', $e->getMessage());
                }
                self::assertSame([], $seen, $change);
            }

            [$certificate, $key] = Certificates::make('AA test', 44);
            Certificates::file($dir, 'live.pem', $certificate);
            Certificates::file($dir, 'live.key', $key);
            $transport = self::transport(self::fixture('owner-info.xml'), seen: $seen);

            self::assertSame('kv62bqf', $run('', $transport, $latch->nextRun()));
            self::assertCount(1, $seen);
        } finally {
            Certificates::remove($dir);
        }
    }

    // ISDS blocks a login after failures, and the address of the server on suspicion
    public function test_call_refusedLoginNotTriedAgain(): void
    {
        $latch = new MemoryLatch();
        $http  = self::answering(self::fixture('unauthorized.html'), 401);

        try {
            self::client($http, latch: $latch)->ownerBox();
            self::fail('AuthException expected');
        } catch (AuthException $e) {
            self::assertStringContainsString('Login blocked until', $e->getMessage());
        }
        foreach ([$latch, $latch->nextRun()] as $run) {
            try {
                self::client($http, latch: $run)->userInfo();
                self::fail('AuthException expected');
            } catch (AuthException $e) {
                self::assertSame('ISDS refused this login at ' . date('Y-m-d H:i', $latch->now) . ' - AA does not try it again until the login or the password changes, or an administrator checks it again on the State Check page', $e->getMessage());
                self::assertFalse($e->mayHaveBeenSent());
            }
        }
        self::assertCount(1, $http->calls, 'no request after the refusal');
        self::assertSame([LoginLatch::REFUSED], array_column($latch->rows, 'params'));
    }

    public function test_call_newPasswordTriedAgain(): void
    {
        $latch = new MemoryLatch();
        $http  = self::answering(self::fixture('unauthorized.html'), 401);
        try {
            self::client($http, latch: $latch)->ownerBox();
        } catch (AuthException) {
        }
        $http = self::answering(self::fixture('owner-info.xml'));

        self::assertSame('kv62bqf', self::client($http, latch: $latch->nextRun(), password: 'Another-Pa55')->ownerBox());
        self::assertCount(1, $http->calls);
    }

    // a person asks once more; a second account of the same login in that run does not
    public function test_forgetRefusal_onceByAPerson(): void
    {
        $earlier = new MemoryLatch();
        try {
            self::client(self::answering(self::fixture('unauthorized.html'), 401), latch: $earlier)->ownerBox();
        } catch (AuthException) {
        }
        $run    = $earlier->nextRun();
        $http   = self::answering(self::fixture('unauthorized.html'), 401);
        $client = self::client($http, latch: $run);

        self::assertTrue($client->forgetRefusal());
        try {
            $client->ownerBox();
            self::fail('AuthException expected');
        } catch (AuthException $e) {
            self::assertStringContainsString('HTTP 401', $e->getMessage(), 'asked ISDS');
        }
        $other = self::client($http, latch: $run);
        self::assertFalse($other->forgetRefusal(), 'refused in this run');
        try {
            $other->ownerBox();
        } catch (AuthException) {
        }
        self::assertCount(1, $http->calls);
        self::assertSame([LoginLatch::REFUSED, LoginLatch::REFUSED], array_column($run->rows, 'params'));
        self::assertFalse(self::client($http, latch: new MemoryLatch())->forgetRefusal(), 'nothing to forget');
    }

    /** @return array<string, array{\Closure(): ClientInterface, bool}> */
    public static function answersToARetry(): array
    {
        $refused = static fn(): string => strtr(self::fixture('owner-info.xml'), ['0000' => '2011']);
        return [
            'the box'             => [static fn() => self::answering(self::fixture('owner-info.xml')), true],
            'refused by a status' => [static fn() => self::answering($refused()), true],
            'no answer'           => [static fn() => self::failing(static fn() => new HttpTransportException('No answer', 0, new TimeoutException('Idle timeout reached'))), false],
            'come back later'     => [static fn() => self::answering('Service Unavailable', 503), false],
            'unreadable'          => [static fn() => self::answering('<html>a gateway</html>'), false],
        ];
    }

    /**
     * A retry the daily run may build on only when ISDS took the login - a person's try that got no
     * answer must leave the latch for the next run
     * @param \Closure(): ClientInterface $http
     */
    #[DataProvider('answersToARetry')]
    public function test_forgetRefusal_writtenOnceISDSTookTheLogin(\Closure $http, bool $took): void
    {
        $earlier = new MemoryLatch();
        try {
            self::client(self::answering(self::fixture('unauthorized.html'), 401), latch: $earlier)->ownerBox();
        } catch (AuthException) {
        }
        $run    = $earlier->nextRun();
        $client = self::client($http(), latch: $run);
        self::assertTrue($client->forgetRefusal());

        try {
            $client->ownerBox();
        } catch (IsdsException) {
        }

        self::assertSame($took ? [LoginLatch::REFUSED, LoginLatch::FORGOTTEN] : [LoginLatch::REFUSED], array_column($run->rows, 'params'));
        self::assertSame(!$took, !is_null($run->nextRun()->refusedAt(new Account(Account::TEST, self::LOGIN, new Secret(self::PASSWORD)))));
    }

    // the address of the server: every account of the environment waits, the login is not latched
    public function test_call_blockedAddressLatchesTheEnvironment(): void
    {
        $latch = new MemoryLatch();
        $http  = self::answering(self::fixture('address-blocked.html'), 401);

        try {
            self::client($http, latch: $latch)->ownerBox();
            self::fail('AddressBlockedException expected');
        } catch (AddressBlockedException $e) {
            self::assertStringStartsWith('ISDS blocked logins from the address of this server (HTTP 401): ', $e->getMessage());
        }
        self::assertSame([['selector' => 'blocked:test', 'params' => LoginLatch::BLOCKED, 'time' => $latch->now]], $latch->rows);

        foreach ([$latch, $latch->nextRun(LoginLatch::BLOCK_HOURS * 3600 - 1)] as $run) {
            try {
                self::client($http, latch: $run, login: 'other01')->userInfo();
                self::fail('AddressBlockedException expected');
            } catch (AddressBlockedException $e) {
                self::assertSame('ISDS blocked logins from the address of this server at ' . date('Y-m-d H:i', $latch->now) . ' - AA tries again ' . LoginLatch::BLOCK_HOURS . ' hours later, or when an administrator checks it again on the State Check page', $e->getMessage());
            }
        }
        self::assertCount(1, $http->calls, 'no request while it stands');

        $later = $latch->nextRun(LoginLatch::BLOCK_HOURS * 3600);
        foreach (['one try', 'blocked again'] as $case) {
            try {
                self::client($http, latch: $later)->ownerBox();
            } catch (AddressBlockedException) {
            }
        }
        self::assertCount(2, $http->calls);
        self::assertSame([LoginLatch::BLOCKED, LoginLatch::BLOCKED], array_column($later->rows, 'params'));
        self::assertNull($later->refusedAt(new Account(Account::TEST, self::LOGIN, new Secret(self::PASSWORD))));
    }

    public function test_forgetRefusal_theBlockedAddress(): void
    {
        $earlier = new MemoryLatch();
        try {
            self::client(self::answering(self::fixture('address-blocked.html'), 401), latch: $earlier)->ownerBox();
        } catch (AddressBlockedException) {
        }
        $run    = $earlier->nextRun();
        $client = self::client(self::answering(self::fixture('owner-info.xml')), latch: $run, login: 'other01');

        self::assertTrue($client->forgetRefusal());
        self::assertSame('kv62bqf', $client->ownerBox());
        self::assertSame([LoginLatch::BLOCKED, LoginLatch::FORGOTTEN], array_column($run->rows, 'params'));
    }

    // a cut through the login would leave its start, an entity spells it in other letters
    public function test_call_errorTextsWithoutTheSecrets(): void
    {
        $login = 'qx7Login';
        // ISDS texts are cut at 300 bytes: this one cuts the login after its fifth letter
        $pair  = base64_encode("$login:" . self::PASSWORD);
        $fault = str_replace('Validation error:', str_repeat('x', 295) . strtoupper($login) . ' ' . self::PASSWORD . " $pair", self::fixture('fault.xml'));
        $html  = str_replace('Login blocked until', 'User ' . implode('', array_map(static fn(string $c): string => '&#' . ord($c) . ';', str_split($login))) . ' blocked until', self::fixture('unauthorized.html'));
        $owner = str_replace('<p:dbID>kv62bqf</p:dbID>', "<p:dbID>$login</p:dbID>", self::fixture('owner-info.xml'));

        foreach (['cut through the login' => [$fault, 500], 'in entities' => [$html, 401], 'as a value' => [$owner, 200]] as $case => [$body, $status]) {
            try {
                self::client(self::answering($body, $status), login: $login)->ownerBox();
                self::fail("IsdsException expected: $case");
            } catch (IsdsException $e) {
                self::assertStringContainsString('***', $e->getMessage(), $case);
                foreach ([substr($login, 0, 3), substr(self::PASSWORD, 0, 4), substr($pair, 0, 6)] as $start) {
                    self::assertStringNotContainsStringIgnoringCase($start, $e->getMessage(), $case);
                }
            }
        }
    }

    // quote() makes one space of two, and the excerpt of a long page cuts it: neither may come before redaction
    public function test_call_secretsSpacedOrSplitByTheExcerpt(): void
    {
        $password = 'Qz8  spaced-Pass';
        $fault    = str_replace('Validation error:', "Bad credentials $password:", self::fixture('fault.xml'));
        $entities = implode('', array_map(static fn(string $c): string => '&#' . ord($c) . ';', str_split('Qz8Wk4Lm3Pv8')));
        // the excerpt reads 4096 bytes; the cut falls in the entities, after the fifth letter
        $head = '<html><body>' . str_repeat('<i></i>', 500);
        $page = $head . str_repeat(' ', 4096 - strlen($head) - 6 * 5) . $entities . '</body></html>';

        foreach (['spaced, in a fault' => [$fault, 500, $password], 'split by the cut' => [$page, 401, 'Qz8Wk4Lm3Pv8']] as $case => [$body, $status, $secret]) {
            try {
                self::client(self::answering($body, $status), password: $secret)->ownerBox();
                self::fail("IsdsException expected: $case");
            } catch (IsdsException $e) {
                self::assertStringNotContainsString('Qz8', $e->getMessage(), $case);
            }
        }
    }

    /** @return array<string, array{string, string, string}> a page, the login, the password */
    public static function cutSecrets(): array
    {
        $password = 'Qz8Wx7Lm3Pv8Rt5Yu2Io1PasW0rd';
        $entities = static fn(string $text): string => implode('', array_map(static fn(string $c): string => '&#' . ord($c) . ';', str_split($text)));
        // 20 letters, then the 21st zero-padded: the excerpt's cut falls 300 bytes into its padding
        $padded = $entities(substr($password, 0, 20)) . '&#' . str_repeat('0', 400) . ord($password[20]) . ';' . $entities(substr($password, 21));
        $before = strlen($entities(substr($password, 0, 20))) + 300;
        // longer than the tail any login of a dozen letters needs
        $login = str_repeat('Qz8', 100);
        // a login shorter than *** makes the redacted body the longer one: 3616 bytes, 4216 redacted
        $short = '<p><script>' . str_repeat('Q7 ', 600) . '</script>' . str_repeat(' ', 1646) . $entities($password);

        return [
            'a zero-padded entity'     => [self::page($padded, $before), self::LOGIN, $password],
            'a long login'             => [self::page($login, 290), $login, self::PASSWORD],
            'a login shorter than ***' => [$short, 'Q7', $password],
        ];
    }

    // the redacted body decides whether the excerpt was cut: here only the raw one is over it
    public function test_call_excerptOfABodyShorterOnceRedacted(): void
    {
        $page = '<p>' . self::PASSWORD . str_repeat(' ', 4097 - 3 - strlen(self::PASSWORD) - 9) . ' END-MARK';
        try {
            self::client(self::answering($page, 401))->ownerBox();
            self::fail('AuthException expected');
        } catch (AuthException $e) {
            self::assertStringContainsString('*** END-MARK', $e->getMessage());
        }
    }

    /** a 401 page longer than the excerpt, $secret starting $before bytes ahead of its cut */
    private static function page(string $secret, int $before): string
    {
        return '<p>x' . str_repeat(' ', 4096 - 4 - $before) . $secret . str_repeat(' tail', 50);
    }

    // decoded, a secret the cut split leaves its start at the end of the text - all of it is dropped
    #[DataProvider('cutSecrets')]
    public function test_call_secretSplitByTheExcerpt(string $page, string $login, string $password): void
    {
        try {
            self::client(self::answering($page, 401), password: $password, login: $login)->ownerBox();
            self::fail('AuthException expected');
        } catch (AuthException $e) {
            self::assertStringNotContainsString('Qz8', $e->getMessage());
        }
    }

    /** @return array<string, array{string, string}> a password, an error page that repeats it as it is */
    public static function markupPasswords(): array
    {
        return [
            'with a tag'       => ['Ab1<x>Secret99', '<p>Your password Ab1<x>Secret99 is wrong</p>'],
            'with an entity'   => ['Ab&lt;cdef12', '<p>Your password Ab&lt;cdef12 is wrong</p>'],
        ];
    }

    // stripped of tags or decoded, such a password is no longer the text redact() looks for
    #[DataProvider('markupPasswords')]
    public function test_call_passwordOfMarkupRedactedAsItCame(string $password, string $page): void
    {
        foreach ([401, 500] as $status) {
            try {
                self::client(self::answering($page, $status), password: $password)->ownerBox();
                self::fail("IsdsException expected: HTTP $status");
            } catch (IsdsException $e) {
                self::assertStringContainsString('Your password *** is wrong', $e->getMessage(), "HTTP $status");
            }
        }
    }

    // the code is the part after the last colon - of the redacted code, not of the password
    public function test_call_faultCodeWithoutAPasswordOfAColon(): void
    {
        $password = 'abc:defghXYZ12';
        $fault    = str_replace('SOAP-ENV:Client', "SOAP-ENV:$password", self::fixture('fault.xml'));
        foreach ([500, 400] as $status) {
            try {
                self::client(self::answering($fault, $status), password: $password)->ownerBox();
                self::fail("IsdsException expected: HTTP $status");
            } catch (IsdsException $e) {
                self::assertSame('***', $e->isdsCode(), "HTTP $status");
                self::assertStringNotContainsString('defgh', $e->getMessage(), "HTTP $status");
            }
        }
    }
}
