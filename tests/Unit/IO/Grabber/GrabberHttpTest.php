<?php

declare(strict_types=1);

namespace Tests\Unit\IO\Grabber;

use AA\Http\Client;
use AA\Http\ClientInterface;
use AA\Http\Response;
use AA\IO\Grabber\CSV;
use AA\IO\Grabber\JsonLD;
use AA\IO\Grabber\XML;
use AA\IO\SourceFailure;
use AA\IO\SourceRefused;
use AA\IO\SourceUnavailable;
use AA\IO\Grabber\AARSS;
use AA\IO\Grabber\Slice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * CSV, XML and JSON-LD grabbers fetch through loadData(). A client handed over by setHttp()
 * replaces file_get_contents() and turns a failed fetch into SourceUnavailable / SourceRefused;
 * without it they behave as they always did - feeds and the CSV import rely on that.
 *
 * Each test in its own process: util.php loads include/locsess.php, whose getDB() collides with
 * the stub tests/Unit/IO/DB loads when that directory runs first.
 */
#[RunTestsInSeparateProcesses]
final class GrabberHttpTest extends TestCase
{
    /**
     * A CSV grabber as serialize() wrote it before grabbers could have a client - feed grabbers
     * wait in the queue in this form, so it must still read back and be written the same way.
     * ~ stands for the NUL byte of protected property names.
     */
    private const CSV_SERIALIZED = 'O:17:"AA\IO\Grabber\CSV":10:{s:17:"~*~load_frequency";N;s:11:"~*~messages";a:0:{}'
        . 's:7:"~*~file";s:28:"https://example.org/data.csv";s:15:"~*~direct_input";s:0:"";s:12:"~*~delimiter";s:1:";";'
        . 's:12:"~*~enclosure";s:1:""";s:10:"~*~caption";b:1;s:18:"~*~source_encoding";s:12:"windows-1250";'
        . 's:12:"~*~_colnames";N;s:9:"~*~_items";N;}';

    private ?string $tmp = null;

    protected function setUp(): void
    {
        // safe() for the report of loadData(); util.php boots locsess.php, which wants the config.
        // Not in setUpBeforeClass(): PHPUnit runs that in the parent process too, where util.php
        // would meet the getDB() stub of tests/Unit/IO/DB
        foreach ([
            'AA_INSTAL_URL'   => 'http://localhost/aa/',
            'AA_INSTAL_PATH'  => '/aa/',
            'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
            'AA_BASE_PATH'    => dirname(__DIR__, 4) . '/',
            'PERM_LIB'        => 'sql',
            'DB_HOST'         => 'localhost',
            'DB_NAME'         => 'aa_test',
            'DB_USER'         => 'aa',
            'DB_PASSWORD'     => '',
        ] as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }
        // the warnings are locsess.php missing the web request and config of a real install
        set_error_handler(static fn() => true, E_WARNING | E_DEPRECATED);
        try {
            require_once __DIR__ . '/../../../../include/util.php';
        } finally {
            restore_error_handler();
        }
    }

    protected function tearDown(): void
    {
        if (!is_null($this->tmp) && is_file($this->tmp)) {
            unlink($this->tmp);
        }
    }

    public function test_loadData_directInputUnchanged(): void
    {
        $grabber = new CSV('https://example.invalid/never-fetched.csv', "a,b\n1,2\n");

        self::assertSame(["a,b\n1,2\n"], $grabber->getSources());
        self::assertSame([], $grabber->reportRows(9));
    }

    // PHP 8.4 deprecates fgetcsv() without its escape argument - one notice per row
    public function test_prepare_noDeprecation(): void
    {
        $deprecations = [];
        set_error_handler(static function (int $no, string $text) use (&$deprecations): bool {
            $deprecations[] = $text;
            return true;
        }, E_DEPRECATED);
        try {
            $items = (new CSV('', "a,b\n\"x\\\"\",2\n"))->getAllItems();
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $deprecations);
        self::assertSame('x\\"', $items[0]->getValue('a'), 'a backslash still escapes the enclosure');
    }

    // a blank line would become an item with no values - an import counts it as an item without a key
    public function test_prepare_blankLinesSkipped(): void
    {
        $items = (new CSV('', "\na,b\n1,2\n\n\r\n3,4\n\n"))->getAllItems();

        self::assertSame([['1', '2'], ['3', '4']], array_map(static fn($i): array => [$i->getValue('a'), $i->getValue('b')], $items));
    }

    // Excel's "CSV UTF-8" starts the file with one; it would stick to the first column name
    public function test_prepare_utf8BomStripped(): void
    {
        $items = (new CSV('', "\xEF\xBB\xBFname,age\nAda,36\n"))->getAllItems();

        self::assertSame('Ada', $items[0]->getValue('name'));
    }

    // a value copied as it is (copyfield) takes its flag along
    public function test_getItem_valuesArePlainText(): void
    {
        $items = (new CSV('', "a,b\n<b>1</b>,2\n"))->getAllItems();

        self::assertSame([['value' => '<b>1</b>', 'flag' => 0]], $items[0]->getContent()['a']);
    }

    public function test_firstRow_asPrepareReadsIt(): void
    {
        $grabber = new CSV('', "\xEF\xBB\xBF\nname\tage\nAda\t36\n", '\t');

        self::assertSame(['name', 'age'], $grabber->firstRow());
    }

    public function test_loadData_localFileWithoutClientUnchanged(): void
    {
        $this->tmp = tempnam(sys_get_temp_dir(), 'aa-grabber');
        file_put_contents($this->tmp, "name;age\nAda;36\n");
        $grabber = new CSV($this->tmp, '', ';');

        self::assertSame(["name;age\nAda;36\n"], $grabber->getSources());
        $items = $grabber->getAllItems();
        self::assertCount(1, $items);
        self::assertSame('Ada', $items[0]->getValue('name'));
    }

    public function test_serialize_grabberWithoutHttp(): void
    {
        $serialized = str_replace('~', "\0", self::CSV_SERIALIZED);
        $grabber    = new CSV('https://example.org/data.csv', '', ';', '"', true, 'windows-1250');

        self::assertSame($serialized, serialize($grabber));
        self::assertEquals($grabber, unserialize($serialized));
    }

    public function test_serialize_xmlRoundTrip(): void
    {
        $grabber = (new XML('https://example.org/a.xml', null, '', '//item'))->useFakeNamespaces();

        self::assertEquals($grabber, unserialize(serialize($grabber)));
    }

    public function test_setHttp_okBody(): void
    {
        $seen = [];
        $mock = new MockHttpClient(static function (string $method, string $url) use (&$seen): MockResponse {
            $seen[] = [$method, $url];
            return new MockResponse("a,b\n1,2\n");
        });
        // .invalid: file_get_contents() could not reach it, so the body proves the client was used
        $grabber = (new CSV('https://example.invalid/data.csv'))->setHttp(new Client(http: $mock));

        self::assertSame(["a,b\n1,2\n"], $grabber->getSources());
        self::assertSame([['GET', 'https://example.invalid/data.csv']], $seen);
    }

    public function test_setHttp_503IsUnavailable(): void
    {
        $this->assertFailure(SourceUnavailable::class, 'TRANSPORT', new MockResponse('down', ['http_code' => 503]));
    }

    public function test_setHttp_429IsUnavailable(): void
    {
        $this->assertFailure(SourceUnavailable::class, 'TRANSPORT', new MockResponse('slow down', ['http_code' => 429]));
    }

    #[DataProvider('authStatuses')]
    public function test_setHttp_401IsRefusedAuth(int $status): void
    {
        $this->assertFailure(SourceRefused::class, 'AUTH', new MockResponse('no', ['http_code' => $status]));
    }

    public static function authStatuses(): array
    {
        return ['401' => [401], '403' => [403]];
    }

    #[DataProvider('sourceStatuses')]
    public function test_setHttp_404IsRefusedSource(int $status): void
    {
        $this->assertFailure(SourceRefused::class, 'SOURCE', new MockResponse('?', ['http_code' => $status]));
    }

    public static function sourceStatuses(): array
    {
        return ['404' => [404], '400' => [400], 'unfollowed redirect' => [302]];
    }

    public function test_setHttp_timeoutIsUnavailable(): void
    {
        $this->assertFailure(SourceUnavailable::class, 'TRANSPORT', new MockResponse('', ['error' => 'Idle timeout reached']));
    }

    public function test_setHttp_tooLargeIsRefusedSource(): void
    {
        $grabber = (new CSV('https://example.invalid/data.csv?key=s3cr3tKey'))
            ->setHttp(new Client(maxBytes: 4, http: new MockHttpClient([new MockResponse('0123456789')])));

        $e = $this->failureOf(fn() => $grabber->getSources());
        self::assertInstanceOf(SourceRefused::class, $e);
        self::assertSame('SOURCE', $e->reason);
        self::assertSame('The answer from example.invalid is too large', $e->getMessage());
    }

    public function test_setHttp_malformedUrlIsRefusedSource(): void
    {
        $grabber = (new CSV('ftp://example.invalid/data.csv'))->setHttp(new Client(http: new MockHttpClient([new MockResponse('a')])));

        $e = $this->failureOf(fn() => $grabber->getSources());
        self::assertInstanceOf(SourceRefused::class, $e);
        self::assertSame('SOURCE', $e->reason);
    }

    public function test_setHttp_grabberNotViaLoadDataThrows(): void
    {
        $grabber = (new \ReflectionClass(AARSS::class))->newInstanceWithoutConstructor();
        $grabber->setHttp(null);

        $this->expectException(\LogicException::class);
        $grabber->setHttp(new Client(http: new MockHttpClient([])));
    }

    public function test_setHttp_failureMessageWithoutQuery(): void
    {
        $grabber = (new CSV('https://example.invalid/data.csv?key=s3cr3tKey'))
            ->setHttp(new Client(http: new MockHttpClient([new MockResponse('no', ['http_code' => 404])])));

        $e = $this->failureOf(fn() => $grabber->getSources());
        self::assertStringContainsString('example.invalid', $e->getMessage());
        self::assertStringNotContainsString('s3cr3tKey', $e->getMessage());
    }

    public function test_setHttp_sourceFailureOfTheClientPassesThrough(): void
    {
        // the import's client already sorts its failures and masks keys in the message
        $refused = new SourceRefused('SOURCE', 'Address in a local or private network: http://***/');
        $client  = new class ($refused) implements ClientInterface {
            public function __construct(private readonly SourceRefused $refused) {}

            public function request(string $method, string $url, ?string $body = null, array $headers = []): Response
            {
                throw $this->refused;
            }
        };
        $grabber = (new CSV('http://10.0.0.1/data.csv'))->setHttp($client);

        self::assertSame($refused, $this->failureOf(fn() => $grabber->getSources()));
    }

    public function test_setHttp_postBodySent(): void
    {
        $seen = [];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $options['body'], $options['normalized_headers']['content-type'][0] ?? ''];
            return new MockResponse('<r><item>1</item></r>');
        });
        $grabber = (new XML('https://example.invalid/soap', '<q/>', '', '//item'))->setHttp(new Client(http: $mock));

        self::assertCount(1, $grabber->getAllItems());
        self::assertSame(['POST', '<q/>', 'Content-Type: text/xml'], $seen);
    }

    public function test_setHttp_directInputFetchesNothing(): void
    {
        $calls   = 0;
        $mock    = new MockHttpClient(static function () use (&$calls): MockResponse {
            $calls++;
            return new MockResponse('fetched');
        });
        $grabber = (new CSV('https://example.invalid/data.csv', "a\n1\n"))->setHttp(new Client(http: $mock));

        self::assertSame(["a\n1\n"], $grabber->getSources());
        self::assertSame(0, $calls);
    }

    public function test_jsonld_bearerHeaderSent(): void
    {
        $headers = [];
        $mock    = new MockHttpClient(static function (string $method, string $url, array $options) use (&$headers): MockResponse {
            $headers = $options['normalized_headers'];
            return new MockResponse('[{"a":1}]');
        });
        $grabber = (new JsonLD('https://example.invalid/api'))->setToken('test-token')->setHttp(new Client(http: $mock));

        self::assertCount(1, $grabber->getAllItems());
        self::assertSame(['Authorization: Bearer test-token'], $headers['authorization']);
        self::assertSame(['Accept: application/json'], $headers['accept']);
        self::assertSame(['Content-Type: application/json'], $headers['content-type']);
    }

    public function test_jsonld_tokenFetchedThroughClient(): void
    {
        $seen = [];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = [$method, $url, $options['body'] ?? '', $options['normalized_headers']['authorization'][0] ?? ''];
            return new MockResponse($url === 'https://example.invalid/token' ? '{"token":"fresh-token"}' : '[]');
        });
        $grabber = (new JsonLD('https://example.invalid/api'))
            ->setAuth(['username' => 'u', 'password' => 'p', 'tokenurl' => 'https://example.invalid/token'])
            ->setHttp(new Client(http: $mock));

        $grabber->getSources();
        self::assertSame([
            ['POST', 'https://example.invalid/token', '{"username":"u","password":"p"}', ''],
            ['GET', 'https://example.invalid/api', '', 'Authorization: Bearer fresh-token'],
        ], $seen);
    }

    public function test_jsonld_tokenRefusedIsAuth(): void
    {
        $grabber = (new JsonLD('https://example.invalid/api'))
            ->setAuth(['username' => 'u', 'password' => 'wrong', 'tokenurl' => 'https://example.invalid/token'])
            ->setHttp(new Client(http: new MockHttpClient([new MockResponse('{"token":""}')])));

        $e = $this->failureOf(fn() => $grabber->getSources());
        self::assertInstanceOf(SourceRefused::class, $e);
        self::assertSame('AUTH', $e->reason);
    }

    public function test_jsonld_setNextFuncNoneFetchesOnePage(): void
    {
        $page = '{"hydra:member":[],"hydra:view":{"hydra:next":"/api?page=2"}}';
        // Client works on a copy of the mock, so its request counter stays at 0 - count here
        $calls = 0;
        $mock  = new MockHttpClient(static function () use (&$calls, $page): MockResponse {
            $calls++;
            return new MockResponse($page);
        });
        $grabber = (new JsonLD('https://example.invalid/api?page=1'))->setToken('test-token')->setNextFunc('None')->setHttp(new Client(http: $mock));

        self::assertCount(1, $grabber->getSources());
        self::assertSame(1, $calls);
    }

    public function test_jsonld_nextPageOnOtherHostRefused(): void
    {
        $seen = [];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = [$url, $options['normalized_headers']['authorization'][0] ?? ''];
            return new MockResponse('{"hydra:member":[],"hydra:view":{"hydra:next":"http://attacker.example/api?page=2"}}');
        });
        $grabber = (new JsonLD('https://example.invalid/api?page=1'))->setToken('test-token')->setHttp(new Client(http: $mock));

        $e = $this->failureOf(fn() => $grabber->getSources());
        self::assertInstanceOf(SourceRefused::class, $e);
        self::assertSame('SOURCE', $e->reason);
        self::assertSame('The next page points away from example.invalid', $e->getMessage());
        self::assertSame([['https://example.invalid/api?page=1', 'Authorization: Bearer test-token']], $seen);
    }

    // the same host as for the token: scheme and port do not make another place
    public function test_jsonld_nextPageOnOwnHostOtherPortIsOwnPaging(): void
    {
        $grabber = (new JsonLD('https://example.invalid/api?page=1'))->setToken('test-token')
            ->setHttp(new Client(http: new MockHttpClient([new MockResponse('{"hydra:member":[],"hydra:view":{"hydra:next":"http://EXAMPLE.invalid:8080/api?page=2"}}')])));

        $e = $this->failureOf(fn() => $grabber->getSources());
        self::assertSame('SOURCE', $e->reason);
        self::assertStringContainsString('paging of the grabber must be off', $e->getMessage());
    }

    public function test_jsonld_ownPagingRefusedWithClient(): void
    {
        $calls = 0;
        $mock  = new MockHttpClient(static function () use (&$calls): MockResponse {
            $calls++;
            return new MockResponse('{"hydra:member":[],"hydra:view":{"hydra:next":"/api?page=2"}}');
        });
        $grabber = (new JsonLD('https://example.invalid/api?page=1'))->setToken('test-token')->setHttp(new Client(http: $mock));

        $e = $this->failureOf(fn() => $grabber->getSources());
        self::assertSame('SOURCE', $e->reason);
        self::assertStringContainsString('paging of the grabber must be off', $e->getMessage());
        self::assertSame(1, $calls);
    }

    public function test_jsonld_lastPageWithoutHydraViewNoWarning(): void
    {
        $grabber = (new JsonLD('https://example.invalid/api'))->setToken('test-token')
            ->setHttp(new Client(http: new MockHttpClient([new MockResponse('[{"a":1},{"a":2}]')])));

        $warnings = $this->warningsOf(fn() => self::assertCount(2, $grabber->getAllItems()));
        self::assertSame([], $warnings);
    }

    public function test_jsonld_bearerOnlyToOwnHosts(): void
    {
        $seen = [];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[$url] = $options['normalized_headers']['authorization'][0] ?? '';
            return new MockResponse('[]');
        });
        $grabber = new class ('https://example.invalid/api') extends JsonLD {
            public function get(string $url): string
            {
                return $this->loadUrl($url);
            }
        };
        $grabber->setAuth(['username' => 'u', 'password' => 'p', 'tokenurl' => 'https://login.example.invalid/token'])
            ->setToken('test-token')->setHttp(new Client(http: $mock));

        $grabber->get('https://example.invalid/other');
        $grabber->get('https://login.example.invalid/refresh');
        $grabber->get('https://elsewhere.example/api');
        self::assertSame([
            'https://example.invalid/other'         => 'Authorization: Bearer test-token',
            'https://login.example.invalid/refresh' => 'Authorization: Bearer test-token',
            'https://elsewhere.example/api'         => '',
        ], $seen);
    }

    // over plain http the token would cross the network readable
    public function test_jsonld_bearerOnlyOverHttps(): void
    {
        $seen = [];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[$url] = $options['normalized_headers']['authorization'][0] ?? '';
            return new MockResponse('[]');
        });
        $grabber = new class ('https://example.invalid/api') extends JsonLD {
            public function get(string $url): string
            {
                return $this->loadUrl($url);
            }
        };
        $grabber->setToken('test-token')->setHttp(new Client(http: $mock));

        $grabber->get('HTTPS://example.invalid/a');
        $grabber->get('http://example.invalid/b');
        // the library writes the scheme in lower case
        self::assertSame(['https://example.invalid/a' => 'Authorization: Bearer test-token', 'http://example.invalid/b' => ''], $seen);
    }

    #[DataProvider('hostPairs')]
    public function test_sameHost_ignoresSchemeAndPort(string $url, string $other, bool $expected): void
    {
        $grabber = new class ('https://example.invalid/data.csv') extends CSV {
            public static function same(string $url, string $other): bool
            {
                return self::sameHost($url, $other);
            }
        };

        self::assertSame($expected, $grabber::same($url, $other));
    }

    public static function hostPairs(): array
    {
        return [
            'other scheme, port and case' => ['https://Example.invalid/a', 'http://example.invalid:8080/b', true],
            'other host'                  => ['https://example.invalid/a', 'https://attacker.example/a', false],
            'both without a host'         => ['/api?page=2', '/api?page=2', false],
            'both empty'                  => ['', '', false],
        ];
    }

    #[DataProvider('tokenAnswers')]
    public function test_jsonld_tokenAnswerWithoutTokenIsAuth(string $answer): void
    {
        $grabber = (new JsonLD('https://example.invalid/api'))
            ->setAuth(['username' => 'u', 'password' => 'p', 'tokenurl' => 'https://example.invalid/token'])
            ->setHttp(new Client(http: new MockHttpClient([new MockResponse($answer)])));

        $e = $this->failureOf(fn() => $grabber->getSources());
        self::assertInstanceOf(SourceRefused::class, $e);
        self::assertSame('AUTH', $e->reason);
    }

    public static function tokenAnswers(): array
    {
        return [
            'no token'     => ['{"error":"bad password"}'],
            'not an array' => ['"token"'],
            'list'         => ['["token"]'],
            'number'       => ['{"token":5}'],
            'not JSON'     => ['<html>login</html>'],
        ];
    }

    public function test_jsonld_invalidBodyIsRefusedSource(): void
    {
        $grabber = (new JsonLD('https://example.invalid/api'))->setToken('test-token')
            ->setHttp(new Client(http: new MockHttpClient([new MockResponse('<html>Maintenance</html>')])));

        $e = $this->failureOf(fn() => $grabber->prepare());
        self::assertInstanceOf(SourceRefused::class, $e);
        self::assertSame('SOURCE', $e->reason);
    }

    public function test_xml_invalidBodyIsRefusedSource(): void
    {
        // a proxy or a login page answering 200 instead of the service
        $html    = '<!DOCTYPE html><html><body><p>Please log in<br></body></html>';
        $grabber = (new XML('https://example.invalid/a.xml'))
            ->setHttp(new Client(http: new MockHttpClient([new MockResponse($html)])));

        $e        = null;
        $warnings = $this->warningsOf(function () use ($grabber, &$e): void {
            $e = $this->failureOf(fn() => $grabber->prepare());
        });
        self::assertInstanceOf(SourceRefused::class, $e);
        self::assertSame('SOURCE', $e->reason);
        self::assertStringNotContainsString('Please log in', $e->getMessage());
        self::assertSame([], $warnings);
    }

    public function test_xml_fakeNamespacesSecondParseRefused(): void
    {
        // valid as it comes, but renaming xmlns:a to xmlns_a repeats an attribute
        $body    = '<a:r xmlns:a="urn:x" xmlns_a="secret-data"><a:item>1</a:item></a:r>';
        $grabber = (new XML('https://example.invalid/a.xml'))->useFakeNamespaces()
            ->setHttp(new Client(http: new MockHttpClient([new MockResponse($body)])));

        $e        = null;
        $warnings = $this->warningsOf(function () use ($grabber, &$e): void {
            $e = $this->failureOf(fn() => $grabber->prepare());
        });
        self::assertInstanceOf(SourceRefused::class, $e);
        self::assertSame('SOURCE', $e->reason);
        self::assertStringNotContainsString('secret-data', $e->getMessage());
        self::assertSame([], $warnings);
    }

    #[DataProvider('grabbers')]
    public function test_fetchesViaLoadData_onlyCsvXmlJsonld(string $class, bool $expected): void
    {
        $grabber = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        self::assertSame($expected, $grabber->fetchesViaLoadData());
    }

    /** every grabber in src/IO/Grabber, so a new one is checked too */
    public static function grabbers(): array
    {
        $via   = [CSV::class => true, XML::class => true, JsonLD::class => true, 'AA\IO\Grabber\SOAP' => true];
        $cases = [];
        foreach (glob(dirname(__DIR__, 4) . '/src/IO/Grabber/*.php') ?: [] as $file) {
            $class = 'AA\IO\Grabber\\' . basename($file, '.php');
            // PohodaOrdersResult names its parent relative to its own namespace and cannot be loaded
            if (($class === 'AA\IO\Grabber\PohodaOrdersResult') || ((new \ReflectionClass($class))->isAbstract())) {
                continue;
            }
            $cases[basename($file, '.php')] = [$class, $via[$class] ?? false];
        }
        return $cases;
    }

    public function test_loadData_dataExcerptOnlyAtVerbosity2(): void
    {
        $body    = str_repeat('x', 1500);
        $grabber = (new CSV('https://example.invalid/data.csv'))
            ->setHttp(new Client(http: new MockHttpClient([new MockResponse($body)])));
        $grabber->getSources();

        $text = static fn(int $verbosity): string => implode("\n", array_map(fn(array $row): string => (string) $row['txt'], $grabber->reportRows($verbosity)));
        self::assertStringContainsString('1500', $text(1));
        self::assertStringNotContainsString('xxxx', $text(1));
        self::assertStringContainsString(str_repeat('x', 1000), $text(2));
        self::assertStringNotContainsString(str_repeat('x', 1001), $text(2));
    }

    public function test_setHttp_notSerialized(): void
    {
        $plain  = new CSV('https://example.org/data.csv', '', ';', '"', true, 'windows-1250');
        $client = new Client(http: new MockHttpClient([]));
        $with   = (clone $plain)->setHttp($client);

        $serialized = serialize($with);
        self::assertSame(serialize($plain), $serialized);
        self::assertSame(str_replace('~', "\0", self::CSV_SERIALIZED), $serialized);
        self::assertEquals($plain, unserialize($serialized));
    }

    public function test_serialize_privatePropertyOfSubclassKept(): void
    {
        $grabber = (new \ReflectionClass(Slice::class))->newInstanceWithoutConstructor();
        $cache   = new \ReflectionProperty(Slice::class, '_content_cache');
        $cache->setValue($grabber, ['k' => 'v']);

        $serialized = serialize($grabber);
        self::assertStringContainsString("\0AA\\IO\\Grabber\\Slice\0_content_cache", $serialized);
        self::assertSame(['k' => 'v'], $cache->getValue(unserialize($serialized)));
    }

    /** @return list<string> warnings and notices $run raised */
    private function warningsOf(\Closure $run): array
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            $run();
        } finally {
            restore_error_handler();
        }
        return $warnings;
    }

    /** @param class-string<SourceFailure> $class */
    private function assertFailure(string $class, string $reason, MockResponse $answer): void
    {
        $grabber = (new CSV('https://example.invalid/data.csv'))->setHttp(new Client(http: new MockHttpClient([$answer])));

        $e = $this->failureOf(fn() => $grabber->getSources());
        self::assertInstanceOf($class, $e);
        self::assertSame($reason, $e->reason);
    }

    private function failureOf(\Closure $fetch): SourceFailure
    {
        try {
            $fetch();
        } catch (SourceFailure $e) {
            return $e;
        }
        self::fail('No SourceFailure thrown');
    }
}
