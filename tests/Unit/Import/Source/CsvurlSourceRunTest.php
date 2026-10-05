<?php

declare(strict_types=1);

namespace Tests\Unit\Import\Source;

use AA\Import\Definition;
use AA\Import\RunCursor;
use AA\Import\Source\CsvurlSource;
use AA\Import\StreamState;
use AA\IO\DB\DB_AA;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Unit\Import\Fixtures\RunSteps;
use Tests\Unit\Import\Fixtures\TestRun;

require_once __DIR__ . '/../fixtures/RunSteps.php';

/**
 * CsvurlSource through the whole run - Run, ParamsGrabber, Mapping and the Saver - with savers that
 * record what they would store. Each test in its own process: FakeSlice loads include/locsess.php.
 */
#[RunTestsInSeparateProcesses]
final class CsvurlSourceRunTest extends TestCase
{
    use RunSteps;

    private const URL = 'https://1.1.1.1/data.csv';

    private const SETTINGS = ['url' => self::URL, 'columns' => '{"Name": "headline........", "Code": "relation........", "Note": "text...........1"}', 'key_column' => 'Code'];

    /** a definition of the source's default mapping and a run of it, started as Service starts one */
    private static function startCsv(string $csv, string $mode = 'run', bool $dry = false): TestRun
    {
        TestRun::$http   = static fn(): MockResponse => new MockResponse($csv);
        TestRun::$source = $source = CsvurlSource::fromSettings(self::SETTINGS);
        $mapping         = $source->defaultMapping();
        $def             = new Definition();
        $def->setNew(self::DEF, self::SLICE, 'csv');
        foreach (['source' => 'core:csvurl', 'settings' => json_encode(self::SETTINGS, JSON_THROW_ON_ERROR), 'mapping' => $mapping->toJson(), 'key_fields' => json_encode($mapping->keyFields, JSON_THROW_ON_ERROR), 'store_mode' => 'update/insert', 'enabled' => '1'] as $property => $value) {
            $def->setProperty($property, $value);
        }
        self::assertSame(self::DEF, $def->save());

        return DB_AA::transaction(static fn() => TestRun::start([
            'subject' => self::DEF, 'user' => self::USER, 'position' => RunCursor::START, 'mode' => $mode, 'dry' => $dry ? '1' : '', 'limit' => '',
            'snapshot' => json_encode($def->snapshot(), JSON_THROW_ON_ERROR), 'stream_keys' => '["csv"]', 'trigger' => 'manual',
        ]));
    }

    public function test_run_overSeveralSteps(): void
    {
        $csv = "Name,Code,Note\nA,K1,a\nB,K2,b\nC,K3,c\nD,K4,d\nE,K5,e\n";
        TestRun::$itemSeconds = 10.0;
        $run = self::startCsv($csv);

        $steps = self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertGreaterThan(1, count($steps));
        self::assertSame(['A', 'B', 'C', 'D', 'E'], TestRun::storedValues('headline........'));
        self::assertSame(['K1', 'K2', 'K3', 'K4', 'K5'], TestRun::storedValues('relation........'));
        self::assertSame(sha1($csv), StreamState::forDefinition(self::DEF)?->versionOf('csv'));
    }

    // Excel's BOM, blank lines and markup in a cell: every row stored, as plain text
    public function test_run_excelFileStoredPlain(): void
    {
        $csv = "\xEF\xBB\xBFName,Code,Note\nA,K1,<b>x</b> _#HEADLINE {sql:1}\n\nB,K2,y\n\n";
        $run = self::startCsv($csv);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['K1', 'K2'], TestRun::storedValues('relation........'));
        self::assertSame(['<b>x</b> _#HEADLINE {sql:1}', 'y'], TestRun::storedValues('text...........1'));
        $flag = (int) (TestRun::$savers[0]->stored[0]['content']->getContent()['text...........1'][0]['flag'] ?? 0);
        self::assertSame(0, $flag & FLAG_HTML, 'plain text');
        self::assertSame(sha1($csv), StreamState::forDefinition(self::DEF)?->versionOf('csv'), 'no row failed, so the version is stored');
    }

    public function test_run_fileOverMaxBytesRefused(): void
    {
        $run = self::startCsv("Name,Code,Note\n" . str_repeat("A,K1,a\n", intdiv(CsvurlSource::MAX_BYTES, 7) + 1), dry: true);

        self::runAll($run->getId());

        self::assertSame(['error', 'SOURCE'], self::errorOf($run->getId()));
        self::assertSame([], TestRun::stored());
    }

    public function test_changed_unchangedFileNotFetchedAgain(): void
    {
        $csv = "Name,Code,Note\nA,K1,a\n";
        self::runAll(self::startCsv($csv, 'changed')->getId());
        $requests = count(TestRun::$requests);
        TestRun::$savers = [];

        $run = self::startCsv($csv, 'changed');
        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(2, count(TestRun::$requests) - $requests, 'check() and probe() only');
        self::assertSame([], TestRun::stored());
    }
}
