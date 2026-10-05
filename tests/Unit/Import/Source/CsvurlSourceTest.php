<?php

declare(strict_types=1);

namespace Tests\Unit\Import\Source;

use AA\Import\Context;
use AA\Import\Probe;
use AA\Import\Registry;
use AA\Import\Source\CsvColumnsSetting;
use AA\Import\Source\CsvurlSource;
use AA\Import\Stream;
use AA\IO\Grabber\CSV;
use AA\IO\SourceRefused;
use AA\IO\SourceUnavailable;
use AA\Later\BatchBudget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Addresses are public IP literals: the guard resolves host names, so a name would hit real DNS.
 * Each test in its own process: the CSV grabber loads include/locsess.php, whose getDB() collides
 * with the stub tests/Unit/IO/DB loads when that directory runs first.
 */
#[RunTestsInSeparateProcesses]
final class CsvurlSourceTest extends TestCase
{
    private const URL = 'https://1.1.1.1/data.csv';

    private const CSV = "Name,Code,Note\nPraha,CZ010,a\nBrno,CZ064,b\n";

    private const COLUMNS = '{"Name": "headline........", "Code": "text...........1"}';

    /** @var list<string> requested URLs */
    private array $requests = [];

    protected function setUp(): void
    {
        // what include/config.php gives; not in setUpBeforeClass(), which runs in the parent process too
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
            require_once dirname(__DIR__, 4) . '/include/util.php';
        } finally {
            restore_error_handler();
        }
    }

    /** @param array<string, string> $settings */
    private static function source(array $settings = []): CsvurlSource
    {
        return CsvurlSource::fromSettings($settings + ['url' => self::URL, 'columns' => self::COLUMNS, 'key_column' => 'Code']);
    }

    /** @param list<MockResponse> $responses */
    private function context(array $responses, int $maxBytes = Context::DEFAULT_MAX_BYTES): Context
    {
        $mock = new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->requests[] = "$method $url";
            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });
        return new Context([], new BatchBudget(microtime(true) + 30), $maxBytes, $mock, resolver: fn(string $host): array => throw new \LogicException("DNS asked for $host"));
    }

    private static function stream(CsvurlSource $source): Stream
    {
        $streams = [...$source->streams()];
        self::assertCount(1, $streams);
        return $streams[0];
    }

    private static function assertRefused(callable $call, string $reason = 'SETTINGS'): void
    {
        try {
            $call();
            self::fail('SourceRefused expected');
        } catch (SourceRefused $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    public function test_registry_findsCoreCsvurl(): void
    {
        self::assertSame(CsvurlSource::class, Registry::sources()['core:csvurl'] ?? null);
    }

    public function test_jobs_oneCsvJob(): void
    {
        $source = self::source(['delimiter' => ';']);
        $stream = self::stream($source);
        $ctx    = $this->context([new MockResponse("Name;Code\nPraha;CZ010\n")]);

        $jobs = [...$source->jobs($stream, $ctx, null)];

        self::assertCount(1, $jobs);
        self::assertInstanceOf(CSV::class, $jobs[0]->grabber);
        self::assertSame([], $jobs[0]->params($stream), 'the job belongs to its stream');
        $jobs[0]->grabber->prepare();
        $item = $jobs[0]->grabber->getItem();
        self::assertSame(['Praha', 'CZ010'], [$item?->getValue('Name'), $item?->getValue('Code')]);
        self::assertSame(['GET ' . self::URL], $this->requests, 'fetched through Context::http()');
    }

    public function test_jobs_tabDelimiter(): void
    {
        $source = self::source(['delimiter' => '\t']);
        $jobs   = [...$source->jobs(self::stream($source), $this->context([new MockResponse("Name\tCode\nPraha\tCZ010\n")]), null)];

        $jobs[0]->grabber->prepare();
        self::assertSame('CZ010', $jobs[0]->grabber->getItem()?->getValue('Code'));
    }

    // the run builds the Context of the source with it
    public function test_maxBytes_overItRefused(): void
    {
        $source = self::source();
        self::assertSame(CsvurlSource::MAX_BYTES, $source->maxBytes());

        self::assertRefused(fn() => $source->check($this->context([new MockResponse(str_repeat('x', CsvurlSource::MAX_BYTES + 1))], $source->maxBytes())), 'SOURCE');
    }

    public function test_check_privateUrlRefused(): void
    {
        $source = self::source(['url' => 'http://10.0.0.1/data.csv']);

        self::assertRefused(fn() => $source->check($this->context([new MockResponse(self::CSV)])), 'SOURCE');
        self::assertSame([], $this->requests);
    }

    public function test_check_okWhenMappedColumnsPresent(): void
    {
        $check = self::source()->check($this->context([new MockResponse(self::CSV)]));

        self::assertTrue($check->ok);
        self::assertSame(['GET ' . self::URL], $this->requests);
    }

    /** @param string $delimiter the setting, as the form posts it */
    #[DataProvider('delimited')]
    public function test_check_readsTheHeaderAsTheJobDoes(string $delimiter, string $csv): void
    {
        self::assertTrue(self::source(['delimiter' => $delimiter])->check($this->context([new MockResponse($csv)]))->ok);
    }

    public static function delimited(): array
    {
        return [
            'semicolon'           => [';', "Name;Code\nPraha;CZ010\n"],
            'tab'                 => ['\t', "Name\tCode\nPraha\tCZ010\n"],
            'BOM and blank first' => [',', "\xEF\xBB\xBF\nName,Code\n"],
        ];
    }

    public function test_check_missingColumnFails(): void
    {
        $check = self::source()->check($this->context([new MockResponse("Name,Kod\nPraha,CZ010\n")]));

        self::assertFalse($check->ok);
        self::assertSame('COLUMNS', $check->code);
        self::assertStringContainsString('Code', $check->message);
    }

    public function test_check_emptyFileFails(): void
    {
        self::assertSame('COLUMNS', self::source()->check($this->context([new MockResponse('')]))->code);
    }

    public function test_check_httpErrorsByTheCommonRule(): void
    {
        $source = self::source();
        self::assertRefused(fn() => $source->check($this->context([new MockResponse('', ['http_code' => 404])])), 'SOURCE');

        $this->expectException(SourceUnavailable::class);
        $source->check($this->context([new MockResponse('', ['http_code' => 503])]));
    }

    public function test_check_withoutUrlRefused(): void
    {
        self::assertRefused(fn() => CsvurlSource::fromSettings([])->check($this->context([])));
    }

    public function test_defaultMapping_fromColumns(): void
    {
        $mapping = self::source()->defaultMapping();

        self::assertSame([
            ['to' => 'headline........', 't' => 'copyfield', 'p' => ['field2copy' => 'Name']],
            ['to' => 'text...........1', 't' => 'copyfield', 'p' => ['field2copy' => 'Code']],
        ], $mapping->rows);
        self::assertSame(['text...........1'], $mapping->keyFields);
    }

    // copyfield expands nothing, so a column name needs no quoting
    public function test_defaultMapping_columnNamesAsTheyAre(): void
    {
        $mapping = self::source(['columns' => '{"2024": "number.........1", "Cena (Kč): {x} _#A": "text...........2"}', 'key_column' => '2024'])->defaultMapping();

        self::assertSame(['2024', 'Cena (Kč): {x} _#A'], array_map(static fn(array $row): string => $row['p']['field2copy'], $mapping->rows));
        self::assertSame(['number.........1'], $mapping->keyFields);
    }

    // a new definition asks for the mapping before it has any settings
    public function test_defaultMapping_withoutSettingsEmpty(): void
    {
        self::assertSame([], CsvurlSource::fromSettings([])->defaultMapping()->rows);
    }

    #[DataProvider('badColumns')]
    public function test_settings_badColumnsRefused(string $columns): void
    {
        $value = $columns;
        self::assertFalse(CsvurlSource::getClassProperties()['columns']->getValidator()->validate($value), 'refused already by the form');

        self::assertRefused(fn() => self::source(['columns' => $columns, 'key_column' => '']));
    }

    public static function badColumns(): array
    {
        $many = [];
        for ($i = 0; $i <= CsvColumnsSetting::MAX_COLUMNS; $i++) {
            $many["c$i"] = 'text...........1';
        }
        return [
            'not JSON'          => ['{Name: headline........}'],
            'a list'            => ['["headline........"]'],
            'empty object'      => ['{}'],
            'not a text'        => ['{"Name": 1}'],
            'nested'            => ['{"Name": {"a": "headline........"}}'],
            'not a field id'    => ['{"Name": "headline"}'],
            'status field'      => ['{"Name": "status_code....."}'],
            'author field'      => ['{"Name": "posted_by......."}'],
            'item id field'     => ['{"Name": "id.............."}'],
            'edge space'        => ['{" Name": "headline........"}'],
            'edge nbsp'         => ['{"Name ": "headline........"}'],
            'control character' => ['{"Na\u001eme": "headline........"}'],
            'format character'  => ['{"Na​me": "headline........"}'],
            'empty column'      => ['{"": "headline........"}'],
            'too long column'   => ['{"' . str_repeat('a', CsvColumnsSetting::MAX_NAME + 1) . '": "headline........"}'],
            'too many columns'  => [json_encode($many, JSON_THROW_ON_ERROR)],
        ];
    }

    public function test_settings_longestColumnNameAccepted(): void
    {
        $name = str_repeat('á', CsvColumnsSetting::MAX_NAME);

        self::assertSame([$name => 'headline........'], CsvColumnsSetting::parse(json_encode([$name => 'headline........'], JSON_THROW_ON_ERROR)));
    }

    public function test_settings_keyColumnMustBeMapped(): void
    {
        self::assertRefused(fn() => self::source(['key_column' => 'Note']));
    }

    #[DataProvider('badUrls')]
    public function test_settings_urlMustBeHttp(string $url): void
    {
        $value = $url;
        self::assertFalse(CsvurlSource::getClassProperties()['url']->getValidator()->validate($value), 'refused already by the form');

        self::assertRefused(fn() => self::source(['url' => $url]));
    }

    public static function badUrls(): array
    {
        return [
            'ftp'       => ['ftp://1.1.1.1/data.csv'],
            'file'      => ['file:///etc/passwd'],
            'php'       => ['php://filter/resource=/etc/passwd'],
            'user'      => ['https://user@1.1.1.1/data.csv'],
            'password'  => ['https://user:secret@1.1.1.1/data.csv'],
            'no host'   => ['https:///data.csv'],
            'newline'   => ["https://1.1.1.1/data.csv\n"],
        ];
    }

    // the browser reads the pattern as ^(?:pattern)$
    public function test_settings_urlPatternWorksInTheForm(): void
    {
        $pattern = CsvurlSource::getClassProperties()['url']->getValidator()->getHtmlInputAttr()['pattern'];

        self::assertSame(1, preg_match('~^(?:' . $pattern . ')$~', self::URL));
        self::assertSame(0, preg_match('~^(?:' . $pattern . ')$~', 'https://user@1.1.1.1/data.csv'));
    }

    public function test_settings_atInPathAccepted(): void
    {
        $source = self::source(['url' => 'https://1.1.1.1/a@b/data.csv?x=@']);

        self::assertSame('https://1.1.1.1/a@b/data.csv?x=@', [...$source->jobs(self::stream($source), $this->context([]), null)][0]->url);
    }

    public function test_settings_badDelimiterRefused(): void
    {
        self::assertRefused(fn() => self::source(['delimiter' => ',;']));
    }

    public function test_probe_sameFileUnchanged(): void
    {
        $source = self::source();
        $stream = self::stream($source);

        $first  = $source->probe($stream, $this->context([new MockResponse(self::CSV)]), null);
        $second = $source->probe($stream, $this->context([new MockResponse(self::CSV)]), $first->version);

        self::assertSame([Probe::CHANGED, sha1(self::CSV)], [$first->state, $first->version]);
        self::assertSame([Probe::UNCHANGED, $first->version], [$second->state, $second->version]);
    }

    public function test_probe_changedFileChanged(): void
    {
        $source = self::source();

        $probe = $source->probe(self::stream($source), $this->context([new MockResponse(self::CSV . "Ostrava,CZ080,c\n")]), sha1(self::CSV));

        self::assertSame(Probe::CHANGED, $probe->state);
        self::assertNotSame(sha1(self::CSV), $probe->version);
    }
}
