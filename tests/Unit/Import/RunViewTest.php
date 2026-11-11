<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\Run;
use AA\Import\RunView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Later\Fixtures\BatchRunTables;

require_once __DIR__ . '/../Later/Fixtures/BatchRunTables.php';

/**
 * The words and marks of the runs of an import and the verdict on it - one wording for the list of
 * imports and the detail of one. Each test in its own process, as the other tests of the runs: the
 * fixtures load include/locsess.php.
 */
#[RunTestsInSeparateProcesses]
final class RunViewTest extends TestCase
{
    use BatchRunTables;

    /** @return iterable<string, array{array<string, string>, string}> the run's stored properties, the word */
    public static function runStates(): iterable
    {
        $now = 1_900_000_000;
        yield 'queued'                   => [['state' => Run::RUNNING, 'started' => (string) ($now - 5), 'next_at' => (string) $now], 'Queued'];
        yield 'queued after a lost step' => [['state' => Run::RUNNING, 'started' => (string) ($now - 900), 'lease' => (string) ($now - 1), 'lease_tok' => 't'], 'Queued'];
        yield 'queued after a dead step' => [['state' => Run::RUNNING, 'started' => (string) ($now - 900), 'lease' => (string) ($now - Run::DEAD_GRACE), 'lease_tok' => 't'], 'Queued'];
        yield 'stuck'                    => [['state' => Run::RUNNING, 'started' => (string) ($now - 86400), 'next_at' => (string) ($now - 86000)], 'Stuck — see State Check'];
        yield 'stuck with a dead step'   => [['state' => Run::RUNNING, 'started' => (string) ($now - 86400), 'lease' => (string) ($now - 86000), 'lease_tok' => 't'], 'Stuck — see State Check'];
        yield 'running'                  => [['state' => Run::RUNNING, 'started' => (string) ($now - 5), 'lease' => (string) ($now + 20), 'lease_tok' => 't'], 'Running'];
        yield 'running past its retry'   => [['state' => Run::RUNNING, 'started' => (string) ($now - 900), 'next_at' => (string) ($now + 60), 'lease' => (string) ($now + 20), 'lease_tok' => 't'], 'Running'];
        yield 'waiting to retry'         => [['state' => Run::RUNNING, 'started' => (string) ($now - 900), 'next_at' => (string) ($now + 60), 'failures' => '1'], 'Waiting to retry'];
        yield 'finished'                 => [['state' => Run::DONE, 'counts' => '{"read":3,"new":3,"errors":0}'], 'Finished'];
        yield 'finished with errors'     => [['state' => Run::DONE, 'counts' => '{"read":3,"new":1,"errors":2}'], 'Finished with item errors'];
        yield 'error'                    => [['state' => Run::ERROR, 'error_code' => 'AUTH'], 'Stopped by an error'];
        yield 'ended'                    => [['state' => Run::ENDED], 'Ended by hand'];
        yield 'ended, settings changed'  => [['state' => Run::ENDED, 'progress' => '{"note":"SETTINGS_CHANGED"}'], 'Ended - the settings changed'];
        yield 'ended after an error'     => [['state' => Run::ENDED, 'error_code' => 'AUTH', 'progress' => '{"note":"SCHEDULED_START"}'], 'Stopped by an error, then ended - a planned run started'];
    }

    /** @param array<string, string> $props */
    #[DataProvider('runStates')]
    public function test_outcome_wordsForEachRunState(array $props, string $word): void
    {
        self::assertSame($word, RunView::outcomeText(new Run($props), 1_900_000_000));
    }

    /** a run ended after an error failed: it reads as a failure, wherever it is shown */
    public function test_outcome_endedAfterAnErrorIsAFailure(): void
    {
        $outcome = RunView::outcome(new Run(['state' => Run::ENDED, 'error_code' => 'COLUMNS']), 1_900_000_000);
        self::assertSame(['error', 'Stopped by an error', 'then ended by hand'], array_values($outcome));
        self::assertSame('Ended before reading anything', RunView::nothingReadText(new Run(['state' => Run::ENDED, 'mode' => 'run'])));
        self::assertSame('', RunView::nothingReadText(new Run(['state' => Run::ENDED, 'counts' => '{"read":1}'])));
        self::assertSame('', RunView::nothingReadText(new Run(['state' => Run::DONE])), 'a finished run that read nothing says so by its counts');
    }

    /** @return iterable<string, array{bool, bool, list<array<string, string>>, string, string}> source, mapping, runs newest first, kind, a part of the reason */
    public static function healths(): iterable
    {
        $now  = 1_900_000_000;
        $done = static fn(string $counts, array $more = []): array => $more + ['state' => Run::DONE, 'counts' => $counts, 'mode' => 'run'];
        yield 'no run'                    => [true, true, [], 'idle', 'Run starts the first import'];
        yield 'source gone'               => [false, true, [$done('{"read":3}')], 'bad', 'not available'];
        yield 'mapping refused'           => [true, false, [$done('{"read":3}')], 'bad', 'mapping does not fit'];
        yield 'import done'               => [true, true, [$done('{"read":3,"new":3}')], 'ok', '3 read'];
        yield 'changed run, nothing new'  => [true, true, [$done('{"read":0}', ['mode' => 'changed'])], 'ok', 'nothing had changed'];
        yield 'full run read nothing'     => [true, true, [$done('{"read":0}')], 'warn', 'read nothing'];
        yield 'item errors'               => [true, true, [$done('{"read":3,"new":1,"errors":2}')], 'warn', '2 items could not be stored'];
        yield 'one item error'            => [true, true, [$done('{"read":3,"new":2,"errors":1}')], 'warn', 'but 1 item could not be stored'];
        yield 'import ended by hand'      => [true, true, [['state' => Run::ENDED, 'mode' => 'run']], 'warn', 'ended before it finished'];
        yield 'import ended after error'  => [true, true, [['state' => Run::ENDED, 'mode' => 'run', 'error_code' => 'AUTH']], 'warn', 'stopped by an error and was then ended'];
        yield 'import stopped'            => [true, true, [['state' => Run::ERROR, 'mode' => 'run']], 'bad', 'last import stopped by an error'];
        yield 'check stopped'             => [true, true, [['state' => Run::ERROR, 'mode' => 'check'], $done('{"read":3}')], 'bad', 'source check stopped'];
        yield 'dry run stopped'           => [true, true, [['state' => Run::ERROR, 'mode' => 'run', 'dry' => '1'], $done('{"read":3}')], 'bad', 'last dry run stopped'];
        yield 'baseline stopped'          => [true, true, [['state' => Run::ERROR, 'mode' => 'baseline']], 'bad', 'Recording the state of the source stopped'];
        yield 'only checks'               => [true, true, [$done('{}', ['mode' => 'check'])], 'idle', 'nothing was imported'];
        yield 'import behind a check'     => [true, true, [$done('{}', ['mode' => 'check']), $done('{"read":4}')], 'ok', '4 read'];
        yield 'import behind a dry run'   => [true, true, [$done('{"read":9}', ['dry' => '1']), $done('{"read":4,"errors":3}')], 'warn', '3 items'];
        yield 'running'                   => [true, true, [['state' => Run::RUNNING, 'started' => (string) ($now - 5), 'lease' => (string) ($now + 20), 'lease_tok' => 't']], 'run', 'Running'];
        yield 'waiting to retry'          => [true, true, [['state' => Run::RUNNING, 'started' => (string) ($now - 900), 'next_at' => (string) ($now + 60), 'failures' => '1']], 'warn', 'waits to try again'];
        yield 'stuck'                     => [true, true, [['state' => Run::RUNNING, 'started' => (string) ($now - 86400), 'next_at' => (string) ($now - 86000)]], 'bad', 'no progress'];
    }

    /** @param list<array<string, string>> $props */
    #[DataProvider('healths')]
    public function test_health_verdictAndItsReason(bool $source, bool $mapping, array $props, string $kind, string $reason): void
    {
        $runs    = array_map(static fn(array $p): Run => new Run($p), $props);
        $import  = array_values(array_filter($runs, static fn(Run $r): bool => $r->storesItems()))[0] ?? null;
        $verdict = RunView::health($source, null, $mapping, $runs[0] ?? null, $import, 1_900_000_000);

        self::assertSame($kind, $verdict['kind']);
        self::assertStringContainsString($reason, $verdict['reason']);
        self::assertNotSame('', $verdict['word']);
    }

    /** whether a planned task starts the import is another fact than whether it is in order */
    public function test_health_disabledAndHealthy_staysHealthy(): void
    {
        $import  = new Run(['state' => Run::DONE, 'mode' => 'run', 'counts' => '{"read":3}']);
        $verdict = RunView::health(true, null, true, $import, $import, 1_900_000_000);

        self::assertSame(['ok', 'Healthy'], [$verdict['kind'], $verdict['word']]);
        self::assertStringNotContainsString('by hand', $verdict['reason']);
        $off = RunView::statusHtml($verdict, false);
        self::assertStringContainsString('data-kind="ok"', $off);
        self::assertStringContainsString('Runs only by hand.', $off);
        self::assertStringNotContainsString('Disabled', $off);
        self::assertStringNotContainsString('Runs only by hand.', RunView::statusHtml($verdict, true));
    }

    /** settings the source needs and no run yet: a new import, not a failing one - its mapping comes from them */
    public function test_health_newImportWithoutSettings_isNotSetUp(): void
    {
        $now     = 1_900_000_000;
        $verdict = RunView::health(true, ['URL of the file', 'Columns'], false, null, null, $now);

        self::assertSame(['idle', 'Not set up'], [$verdict['kind'], $verdict['word']]);
        self::assertSame('Fill in these settings of the source: URL of the file, Columns.', $verdict['reason']);

        $run = new Run(['state' => Run::ERROR, 'mode' => 'run']);
        self::assertSame('bad', RunView::health(true, ['Columns'], true, $run, null, $now)['kind'], 'once it ran, its runs tell');
        self::assertSame('bad', RunView::health(false, ['Columns'], true, null, null, $now)['kind'], 'a source that is gone first');
        self::assertSame('The source refuses its settings.', RunView::health(true, [], true, null, null, $now)['reason'], 'refused, yet no blank setting to name');
        $refused = RunView::health(true, [], true, null, null, $now, 'The key column <b>Code</b> must be one of the columns');
        self::assertSame('The source refuses its settings: The key column <b>Code</b> must be one of the columns', $refused['reason'], 'its words say what to fix');
        self::assertStringContainsString('&lt;b&gt;Code', RunView::statusHtml($refused, true));
        self::assertSame('Fill in these settings of the source: Columns.', RunView::health(true, ['Columns'], true, null, null, $now, 'Columns are missing')['reason'], 'a blank setting says it plainer');
        self::assertSame('Never ran', RunView::health(true, null, true, null, null, $now)['word'], 'settings the source takes');
    }

    public function test_age_roughlyAndNothingWhenTooOld(): void
    {
        $now = 1_900_000_000;

        self::assertSame('just now', RunView::ageText($now - 10, $now));
        self::assertSame('12 min ago', RunView::ageText($now - 720, $now));
        self::assertSame('3 h ago', RunView::ageText($now - 3 * 3600 - 5, $now));
        self::assertSame('1 day ago', RunView::ageText($now - 86400 - 5, $now));
        self::assertSame('2 days ago', RunView::ageText($now - 2 * 86400, $now));
        self::assertSame('', RunView::ageText($now - 90 * 86400, $now));
        self::assertSame('', RunView::ageText($now + 5, $now), 'a clock that went back');
        self::assertSame('--', RunView::whenText(0, $now));
        self::assertSame(date('Y-m-d H:i', $now - 3600) . ' · 1 h ago', RunView::whenText($now - 3600, $now));
    }

    public function test_result_saysWhenARunStoredNothing(): void
    {
        $now = 1_900_000_000;

        self::assertSame('Source reachable - nothing imported', RunView::resultText(new Run(['state' => Run::DONE, 'mode' => 'check']), $now));
        self::assertSame('Source check failed - nothing imported', RunView::resultText(new Run(['state' => Run::ERROR, 'mode' => 'check']), $now));
        self::assertSame('Nothing read (nothing stored)', RunView::resultText(new Run(['state' => Run::DONE, 'dry' => '1']), $now));
        self::assertSame('Nothing had changed', RunView::resultText(new Run(['state' => Run::DONE, 'mode' => 'changed']), $now));
        self::assertSame('9 read, 5 new, 2 changed', RunView::resultText(new Run(['state' => Run::DONE, 'counts' => '{"read":9,"new":5,"updated":2}']), $now));
    }

    /** a baseline that failed says so, not how many streams it recorded before */
    public function test_result_baselineByItsOutcome(): void
    {
        $now = 1_900_000_000;

        self::assertSame('Recorded the state of 1 stream - nothing imported', RunView::resultText(new Run(['state' => Run::DONE, 'mode' => 'baseline', 'counts' => '{"streams":1}']), $now));
        self::assertSame('Recorded the state of 3 streams - nothing imported. 1 without a version', RunView::resultText(new Run(['state' => Run::DONE, 'mode' => 'baseline', 'counts' => '{"streams":3,"unversioned":1}']), $now));
        self::assertSame('Stopped by an error', RunView::resultText(new Run(['state' => Run::ERROR, 'mode' => 'baseline', 'counts' => '{"streams":2}']), $now));
    }

    public function test_lastRun_itemErrorsCounted(): void
    {
        $now = 1_900_000_000;
        $run = static fn(int $errors): Run => new Run(['state' => Run::DONE, 'mode' => 'run', 'started' => (string) ($now - 60), 'counts' => '{"read":3,"errors":' . $errors . '}']);

        self::assertStringContainsString('>1 item error<', RunView::lastRunHtml($now, $run(1), null));
        self::assertStringContainsString('>2 item errors<', RunView::lastRunHtml($now, $run(2), null));
        self::assertStringNotContainsString('item error', RunView::lastRunHtml($now, $run(0), null));
    }
}
