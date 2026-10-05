<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\CheckResult;
use AA\Import\RunCursor;
use AA\Import\StreamState;
use AA\IO\SourceRefused;
use AA\IO\SourceUnavailable;
use AA\Later\BatchStepResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Import\Fixtures\CheckedSource;
use Tests\Unit\Import\Fixtures\RunSteps;
use Tests\Unit\Import\Fixtures\ScriptedSource;
use Tests\Unit\Import\Fixtures\TestRun;

require_once __DIR__ . '/fixtures/RunSteps.php';

/**
 * How a run of Run gets past a source that fails: a cycle of jobs, a job that fails three steps in
 * a row (onRetriesExhausted()), a timeout at the step's own end, and the item errors it keeps.
 *
 * Each test in its own process: FakeSlice loads include/locsess.php, whose getDB() collides with
 * the stub tests/Unit/IO/DB loads when that directory runs first.
 */
#[RunTestsInSeparateProcesses]
final class RunFailureTest extends TestCase
{
    use RunSteps;

    // ------------------------------------------------------------ the cursor of the source

    /** settings broken in the snapshot end the run as a fault of the definition, not of a stream */
    public function test_brokenSettingsInSnapshot_definitionError(): void
    {
        self::source(streams: 1, jobs: 1, items: 1);
        $run = self::startRun(new ScriptedSource(), definition: ['settings' => '{broken']);
        TestRun::$source = null;   // the source of the snapshot, from its settings

        self::runAll($run->getId());

        self::assertSame(['error', 'DEFINITION'], self::errorOf($run->getId()));
        self::assertSame('settings', self::stored($run->getId())['error_path']);
    }

    public function test_repeatedJobKey_sourceCycle(): void
    {
        $source             = self::source(streams: 1, jobs: 0, items: 0);
        $source->minJob     = 0;
        $source->jobs['s1'] = [
            ['key' => 's1:a', 'items' => [['key' => 'k1', 'val' => 'v1']]],
            ['key' => 's1:a', 'items' => [['key' => 'k2', 'val' => 'v2']]],
            ['key' => 's1:c', 'items' => [['key' => 'k3', 'val' => 'v3']]],
        ];
        $run = self::startRun($source);

        self::runOne();

        self::assertSame('SOURCE_CYCLE', TestRun::$committed[0]->retry);
        self::assertSame(['k1'], TestRun::storedValues('relation........'), 'the repeated job does not start');
        $position = TestRun::get($run->getId())?->position();
        self::assertSame(['s1:a', 1, sha1('s1:a'), 1], [$position['job'], $position['pages'], $position['cur'], $position['wrote']], 'what got stored before counts');
        self::assertSame(['0', (string) (self::NOW + 1800)], [self::stored($run->getId())['failures'], self::stored($run->getId())['next_at']]);

        self::runOne();

        self::assertSame('SOURCE_CYCLE', TestRun::$committed[1]->retry);
        self::assertSame('1', self::stored($run->getId())['failures'], 'from the repeated key on, no progress');

        self::runAll($run->getId());
        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(1, TestRun::get($run->getId())?->counts()['jobs'], 'the steps that went over s1:a again read nothing new');
    }

    public function test_cycleABA_endsAfterMaxPagesSkippingRestOfStream(): void
    {
        $source             = self::source(streams: 2, jobs: 1, items: 1);
        $source->minJob     = 0;
        $source->jobs['s1'] = static function (): \Generator {
            while (true) {
                yield ['key' => 's1:a', 'items' => [['key' => 'k-a', 'val' => 'v']]];
                yield ['key' => 's1:b', 'items' => [['key' => 'k-b', 'val' => 'v']]];
            }
        };
        $run = self::startRun($source, ['position' => ['job' => 's1:a', 'pages' => RunCursor::MAX_PAGES - 3, 'cur' => sha1('s1:a')] + RunCursor::START]);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['k-a', 'k-b', 'k-a', 'k-b', 'k-s2:a-1'], TestRun::storedValues('relation........'), 'A, B, A is no repeated key - the cap stops it');
        self::assertSame(1, self::warnings($run->getId())['JOB_FAILED'] ?? 0);
        self::assertSame(1, TestRun::get($run->getId())?->counts()['skipped_jobs']);
    }

    public function test_cycleInALaterStream_skipsThatStream(): void
    {
        $source             = self::source(streams: 3, jobs: 1, items: 1);
        $source->minJob     = 0;
        $source->jobs['s2'] = [
            ['key' => 's2:a', 'items' => [['key' => 'k-a', 'val' => 'v']]],
            ['key' => 's2:a', 'items' => [['key' => 'k-a2', 'val' => 'v']]],
        ];
        $run = self::startRun($source);

        self::runOne();
        self::assertSame(1, TestRun::get($run->getId())?->position()['i'], 'the step stops at the cycle, not where it started');

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['k-s1:a-1', 'k-a', 'k-s3:a-1'], TestRun::storedValues('relation........'));
        self::assertSame(['JOB_FAILED' => 1], self::warnings($run->getId()));
    }

    public function test_continuingSameJobFromOffset_isNotRepeatedKey(): void
    {
        $run = self::startRun(self::source(streams: 1, jobs: 1, items: 5, seconds: 10.0));

        self::runOne();
        $position = TestRun::get($run->getId())?->position();
        self::assertSame([3, sha1('s1:a')], [$position['offset'], $position['cur']]);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(self::keys(1, 1, 5), TestRun::storedValues('relation........'));
        self::assertSame(['', ''], array_map(static fn($r): string => $r->retry, array_slice(TestRun::$committed, 0, 2)));
    }

    public function test_restart_isNotRepeatedKey(): void
    {
        $source = self::source(streams: 1, jobs: 1, items: 2);
        $run    = self::startRun($source, ['position' => ['offset' => 3, 'fp' => 'old'] + self::onJob('s1:a')]);

        self::runOne();

        self::assertSame('', TestRun::$committed[0]->retry);
        self::assertSame(self::keys(1, 1, 2), TestRun::storedValues('relation........'), 'the job started over under the key of the cursor');
        self::assertSame('done', TestRun::get($run->getId())?->state());
    }

    // ------------------------------------------------------------ three steps without progress

    public function test_retriesExhausted_skipsJobWithFailedFlag(): void
    {
        $source                         = self::checked(streams: 1, jobs: 3, items: 1);
        $source->jobs['s1'][1]['error'] = new SourceUnavailable('TRANSPORT', 'No answer');
        $run                            = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['k-s1:a-1', 'k-s1:c-1'], TestRun::storedValues('relation........'));
        self::assertSame(['JOB_FAILED' => 1], self::warnings($run->getId()));
        self::assertSame(1, TestRun::get($run->getId())?->counts()['skipped_jobs']);
        self::assertSame([false, false], array_column($source->checkCalls, 'tx'), 'at the start and by the hook, outside any transaction');
        $skip = TestRun::$committed[3];
        self::assertSame('', $skip->retry);
        self::assertSame(
            self::sorted(['job' => 's1:c', 'pages' => 3, 'cur' => sha1('s1:c'), 'failed' => 1, 'wrote' => 1] + self::pastCheck()),
            self::sorted($skip->position),
            'the next job, not started yet',
        );
    }

    public function test_retriesExhausted_lastJobClosesStreamWithoutVersion(): void
    {
        $source                         = self::checked(streams: 2, jobs: 2, items: 1);
        $source->jobs['s1'][1]['error'] = new SourceUnavailable('TRANSPORT', 'No answer');
        self::streamVersions(['s1' => 'v-old']);
        $run = self::startRun($source, ['mode' => 'changed']);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(self::sorted(['i' => 1] + self::pastCheck()), self::sorted(TestRun::$committed[3]->position));
        self::assertSame(['k-s1:a-1', 'k-s2:a-1', 'k-s2:b-1'], TestRun::storedValues('relation........'));
        self::assertSame(['JOB_FAILED' => 1], self::warnings($run->getId()));
        self::assertSame('v-old', StreamState::forDefinition(self::DEF)?->versionOf('s1'), 'the next changed run imports the stream again');
        $s1 = array_filter($source->jobCalls, static fn(array $call): bool => $call['stream'] === 's1');
        self::assertSame(['v-old'], array_values(array_unique(array_column($s1, 'known'))), 'the skip asks for the jobs as the steps do');
    }

    public function test_retriesExhausted_streamFailingBeforeItsFirstJob_skipsTheStream(): void
    {
        $source             = self::checked(streams: 2, jobs: 1, items: 1);
        $source->jobs['s1'] = static fn(): array => throw new SourceUnavailable('TRANSPORT', 'No answer');
        $run                = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(self::sorted(['i' => 1] + RunCursor::START), self::sorted(TestRun::$committed[2]->position), 'the third step without progress skips; failed belongs to the stream skipped');
        self::assertSame(['k-s2:a-1'], TestRun::storedValues('relation........'));
        self::assertSame(['JOB_FAILED' => 1], self::warnings($run->getId()));
        $s1 = array_filter($source->jobCalls, static fn(array $call): bool => $call['stream'] === 's1');
        self::assertCount(3, $s1, 'the three steps asked for its jobs, the skip did not');
    }

    public function test_retriesExhausted_jobsFailingAfterTheCheck_closesTheStream(): void
    {
        $source             = self::checked(streams: 2, jobs: 1, items: 1);
        $dead               = new SourceUnavailable('TRANSPORT', 'No answer');
        $source->jobs['s1'] = static function () use ($dead): \Generator {
            yield ['key' => 's1:a', 'items' => [['key' => 'k-a', 'val' => 'v']], 'error' => $dead];
            throw $dead;   // the next page cannot be had either
        };
        $run = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()), 'one broken page does not end the run');
        self::assertSame(['k-s2:a-1'], TestRun::storedValues('relation........'));
        self::assertSame(['JOB_FAILED' => 1], self::warnings($run->getId()));
        self::assertSame(1, TestRun::get($run->getId())?->counts()['skipped_jobs']);
    }

    public function test_retriesExhausted_jobsRefusedAfterTheCheck_errorsWithItsReason(): void
    {
        $source             = self::checked(streams: 2, jobs: 1, items: 1);
        $source->jobs['s1'] = static function (): \Generator {
            yield ['key' => 's1:a', 'items' => [['key' => 'k-a', 'val' => 'v']], 'error' => new SourceUnavailable('TRANSPORT', 'No answer')];
            throw new SourceRefused('AUTH', '401 for the next page');
        };
        $run = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['error', 'AUTH'], self::errorOf($run->getId()), 'a refusal ends the run, as in a step');
        self::assertSame([], TestRun::stored());
    }

    public function test_retriesExhausted_jobsCutShortByTheHooksDeadline_errors(): void
    {
        $source             = self::checked(streams: 2, jobs: 1, items: 1);
        $dead               = new SourceUnavailable('TRANSPORT', 'No answer');
        $source->jobs['s1'] = static function () use ($dead): \Generator {
            yield ['key' => 's1:a', 'items' => [['key' => 'k-a', 'val' => 'v']], 'error' => $dead];
            TestRun::$microNow = (TestRun::$microNow ?? 0.0) + 14.5;   // half a second of the hook's 15 s left
            throw $dead;
        };
        $run = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['error', 'TRANSPORT'], self::errorOf($run->getId()), 'a request out of time says nothing about the page');
        self::assertSame([], TestRun::stored());
    }

    public function test_seek_lostJobInAnEndlessGenerator_isBounded(): void
    {
        $source             = self::source(streams: 1, jobs: 0, items: 0);
        $source->minJob     = 0;
        $source->jobs['s1'] = self::endless($yields);
        $run                = self::startRun($source, ['position' => self::onJob('s1:z')]);

        self::runOne();

        self::assertSame(['JOB_GONE' => 1], self::warnings($run->getId()), 'the cursor\'s job is not among the first MAX_PAGES');
        self::assertSame('SOURCE_CYCLE', TestRun::$committed[0]->retry, 'the restarted stream ends at the cap');
        self::assertLessThan(3 * RunCursor::MAX_PAGES, $yields);
    }

    public function test_retriesExhausted_lostJobInAnEndlessGenerator_isBounded(): void
    {
        $source             = self::checked(streams: 2, jobs: 1, items: 1);
        $source->jobs['s1'] = self::endless($yields);
        $run                = self::startRun($source, ['position' => self::onJob('s1:z')]);
        self::diedTwice($run->getId());

        self::runOne();

        self::assertSame(self::sorted(['i' => 1] + self::pastCheck()), self::sorted(TestRun::get($run->getId())?->position()));
        self::assertSame(RunCursor::MAX_PAGES, $yields);
    }

    public function test_retriesExhausted_lookingForTheNextJob_keepsToItsBudget(): void
    {
        $source             = self::checked(streams: 1, jobs: 0, items: 0);
        $source->jobs['s1'] = static function (): \Generator {
            for ($n = 1; $n <= 100; $n++) {
                TestRun::$microNow = (TestRun::$microNow ?? 0.0) + 1.0;   // a page discovered from a response
                yield ['key' => "s1:p$n", 'items' => []];
            }
        };
        $run = self::startRun($source, ['position' => self::onJob('s1:p50')]);
        self::diedTwice($run->getId());

        self::runOne();

        self::assertSame(['error', 'STEP_DIED'], self::errorOf($run->getId()), 'p51 lies past the 15 s it has');
    }

    public function test_retriesExhausted_skipKeepsTheLastStepsData(): void
    {
        $run  = self::startRun(self::source(streams: 2, jobs: 1, items: 1));
        $last = new BatchStepResult(self::onJob('s1:a'), false, ['read' => 2, 'skipped_jobs' => 1], ['JOB_GONE' => 1, 'JOB_FAILED' => 2], retry: 'SOURCE_CYCLE', extra: ['errors' => [['stream' => 's1', 'job' => 's1:a', 'code' => 'VALIDATE', 'msg' => 'Item 1']]]);

        $next = TestRun::get($run->getId())?->exhausted($last);

        self::assertEquals(['skipped_jobs' => 2, 'read' => 2, 'streams' => 1], $next?->counts, 'the cycle closed s1');
        self::assertEquals(['JOB_FAILED' => 3, 'JOB_GONE' => 1], $next->warnings);
        self::assertSame($last->extra, $next->extra);
        self::assertSame(['', false, 's2', 1, 2], [$next->retry, $next->finished, $next->label, $next->done, $next->total]);
    }

    /** @return array<string, array{?int, float}> overrun of the lease => seconds the hook has */
    public static function hookBudgets(): array
    {
        return ['its own 15 s' => [null, 15.0], 'up to 30 s before the lease ends' => [31, 2.5]];
    }

    #[DataProvider('hookBudgets')]
    public function test_retriesExhausted_hookHasABudgetOfItsOwn(?int $overrun, float $left): void
    {
        TestRun::$overrun                     = $overrun;
        $source                               = self::checked(streams: 1, jobs: 2, items: 1);
        $source->jobs['s1'][0]['onPrepare']   = static function (): void {
            TestRun::$microNow = TestRun::$now + 25.5;   // 1.5 s of the step's 27 s left
        };
        $source->jobs['s1'][0]['error'] = new SourceUnavailable('TRANSPORT', 'No answer');
        $run                            = self::startRun($source, ['position' => self::onJob('s1:a')]);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame([$left], array_column($source->checkCalls, 'left'));
    }

    public function test_retriesExhausted_cyclicGeneratorWithDeadSource_endsAtCap(): void
    {
        $source             = self::checked(streams: 1, jobs: 0, items: 0);
        $dead               = new SourceUnavailable('TRANSPORT', 'No answer');
        $source->jobs['s1'] = static function () use ($dead): \Generator {
            while (true) {
                yield ['key' => 's1:a', 'items' => [['key' => 'k-a', 'val' => 'v']], 'error' => $dead];
                yield ['key' => 's1:b', 'items' => [['key' => 'k-b', 'val' => 'v']], 'error' => $dead];
            }
        };
        $run = self::startRun($source, ['position' => ['job' => 's1:a', 'pages' => RunCursor::MAX_PAGES - 2, 'cur' => sha1('s1:a')] + self::pastCheck()]);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        $skips = array_values(array_filter(TestRun::$committed, static fn($r): bool => ($r->retry === '') && !$r->finished));
        self::assertSame(
            [['s1:b', RunCursor::MAX_PAGES - 1, 0], ['s1:a', RunCursor::MAX_PAGES, 0], ['', 0, 1]],
            array_map(static fn($r): array => [$r->position['job'], $r->position['pages'], $r->position['i']], $skips),
            'each skip starts a job - the cap ends the stream',
        );
        self::assertSame(['JOB_FAILED' => 3], self::warnings($run->getId()));
        self::assertCount(3, $source->checkCalls);
        self::assertSame([], TestRun::stored());
    }

    public function test_retriesExhausted_unstableJobSkippedWhenCheckOk(): void
    {
        $source = self::checked(streams: 1, jobs: 1, items: 25, seconds: 10.0);
        $items  = $source->jobs['s1'][0]['items'];
        $call   = 0;
        $source->jobs['s1'] = static function () use ($items, &$call): array {
            $items[0] = ['key' => 'k-changed-' . ++$call, 'val' => 'v'];
            return [['key' => 's1:a', 'items' => $items]];
        };
        $run = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['JOB_FAILED' => 1], self::warnings($run->getId()));
    }

    public function test_retriesExhausted_noProgressErrors(): void
    {
        $source              = self::checked(streams: 1, jobs: 1, items: 5);
        $source->jobsSeconds = 30.0;
        $run                 = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['error', 'NO_PROGRESS'], self::errorOf($run->getId()), 'skipping what does not fit a round would lose data quietly');
        self::assertCount(1, $source->checkCalls, 'at the start only - the hook asks nothing');
        self::assertSame(['hint' => 'TOEXECUTE_ALLOWED_TIME'], TestRun::get($run->getId())?->progress((string) $GLOBALS['permission_uid'])['extra']);
    }

    /** @return array<string, array{string, string}> */
    public static function deadChecks(): array
    {
        return ['check fails' => ['fails', 'TRANSPORT'], 'check cannot reach the source' => ['down', 'TRANSPORT'], 'check refused' => ['refused', 'AUTH']];
    }

    #[DataProvider('deadChecks')]
    public function test_retriesExhausted_checkFailsErrors(string $how, string $code): void
    {
        $source                         = self::checked(streams: 1, jobs: 3, items: 1);
        $source->jobs['s1'][1]['error'] = new SourceUnavailable('TRANSPORT', 'No answer');
        $source->check                  = match ($how) {
            'fails'   => new CheckResult(false, 'AUTH', '401 from the source'),
            'down'    => new SourceUnavailable('TRANSPORT', 'No answer'),
            'refused' => new SourceRefused('AUTH', '401 from the source'),
        };
        $run = self::startRun($source, ['position' => self::pastCheck()]);

        self::runAll($run->getId());

        self::assertSame(['error', $code], self::errorOf($run->getId()));
        self::assertCount(1, $source->checkCalls);
        self::assertSame(['k-s1:a-1'], TestRun::storedValues('relation........'));
        self::assertSame([], self::warnings($run->getId()));
        self::assertSame([], TestRun::get($run->getId())?->progress((string) $GLOBALS['permission_uid'])['extra']);
    }

    public function test_retriesExhausted_uncheckableSourceErrors(): void
    {
        $source                         = self::source(streams: 1, jobs: 3, items: 1);
        $source->jobs['s1'][1]['error'] = new SourceUnavailable('TRANSPORT', 'No answer');
        $run                            = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['error', 'TRANSPORT'], self::errorOf($run->getId()), 'nothing says the source is up');
        self::assertSame(['k-s1:a-1'], TestRun::storedValues('relation........'));
    }

    public function test_retriesExhausted_deadStepSkippedWhenCheckOk(): void
    {
        $source = self::checked(streams: 1, jobs: 2, items: 1);
        $run    = self::startRun($source, ['position' => self::onJob('s1:a')]);
        self::diedTwice($run->getId());

        self::runOne();

        self::assertSame([[[self::SLICE], 'M']], self::$invalidated, 'the dead step may have stored items');
        self::assertSame(['s1:b', 1], [TestRun::get($run->getId())?->position()['job'], TestRun::get($run->getId())?->position()['failed']]);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['k-s1:b-1'], TestRun::storedValues('relation........'));
        self::assertSame(['JOB_FAILED' => 1], self::warnings($run->getId()));
    }

    public function test_retriesExhausted_deadStepErrorInvalidatesOnce(): void
    {
        $run = self::startRun(self::source(streams: 1, jobs: 2, items: 1), ['position' => self::onJob('s1:a')]);
        self::diedTwice($run->getId());

        self::runOne();

        self::assertSame(['error', 'STEP_DIED'], self::errorOf($run->getId()));
        self::assertSame([[[self::SLICE], 'M']], self::$invalidated, 'once, by afterDeadStep()');
    }

    public function test_retriesExhausted_errorAfterStoredItems_eachStepInvalidatedItsOwn(): void
    {
        // past the 20 items ParamsGrabber reads ahead for the fingerprint
        $source                          = self::source(streams: 1, jobs: 1, items: 25);
        $source->jobs['s1'][0]['failAt'] = [23, new SourceUnavailable('TRANSPORT', 'No answer')];
        $run                             = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['error', 'TRANSPORT'], self::errorOf($run->getId()));
        self::assertCount(22, TestRun::stored());
        self::assertSame([[[self::SLICE], 'M']], self::$invalidated, 'the step that stored them - the error adds nothing');
    }

    // ------------------------------------------------------------ the step's own end, item errors

    public function test_timeoutAtOwnDeadline_afterSavedPage_isProgress(): void
    {
        // 22 items of 1.19 s: 0.82 s of the 27 s left when the 23rd fails
        $source                          = self::source(streams: 1, jobs: 1, items: 25, seconds: 1.19);
        $source->jobs['s1'][0]['failAt'] = [23, new SourceUnavailable('TRANSPORT', 'No answer in time')];
        $run                             = self::startRun($source, ['position' => self::onJob('s1:a')]);

        self::runOne();

        self::assertSame('', TestRun::$committed[0]->retry, 'cut short by the step\'s own end');
        self::assertSame(22, TestRun::get($run->getId())?->position()['offset']);
        $stored = self::stored($run->getId());
        self::assertSame(['0', (string) self::NOW], [$stored['failures'], $stored['next_at']], 'the next step at once');
    }

    public function test_errors_cappedAt20AndRedacted(): void
    {
        define('AA_APIKEY_RUNFAIL', 'sekret42');
        $source              = self::source(streams: 1, jobs: 1, items: 0);
        $source::$credentials = [['vendor' => 'RUNFAIL', 'context' => '']];
        $source->jobs['s1']  = [['key' => 's1:a', 'items' => array_map(static fn(int $n): array => ['key' => "https://x.cz/?k=sekret42&n=$n", 'val' => 'v'], range(1, 25))]];
        $run                 = self::startRun($source, [], ['validate' => '["no"]']);

        self::runAll($run->getId());

        $errors = TestRun::$committed[0]->extra['errors'];
        self::assertCount(20, $errors);
        self::assertSame(25, TestRun::get($run->getId())?->counts()['errors']);
        self::assertStringContainsString('k=***', $errors[19]['msg']);
        self::assertStringNotContainsString('sekret42', json_encode($errors, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, array{int, float, array<string, mixed>}> items, seconds each, position */
    public static function jobsCountedOnce(): array
    {
        return [
            'continued over steps'         => [5, 10.0, []],
            'started over in the step'     => [2, 0.0, ['offset' => 3, 'fp' => 'old', 'job' => 's1:a', 'pages' => 1, 'cur' => sha1('s1:a')]],
            'empty, moved onto before'     => [0, 0.0, ['job' => 's1:a', 'pages' => 1, 'cur' => sha1('s1:a')]],
        ];
    }

    #[DataProvider('jobsCountedOnce')]
    public function test_counts_eachJobOnce(int $items, float $seconds, array $position): void
    {
        $run = self::startRun(self::source(streams: 1, jobs: 1, items: $items, seconds: $seconds), ['position' => $position + RunCursor::START]);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(1, TestRun::get($run->getId())?->counts()['jobs']);
    }

    // ------------------------------------------------------------ helpers

    /** source() over a CheckedSource whose check() passes */
    private static function checked(int $streams, int $jobs, int $items, float $seconds = 0.0): CheckedSource
    {
        $source         = new CheckedSource();
        $source->minJob = 1;
        self::source($streams, $jobs, $items, $seconds, source: $source);
        return $source;
    }

    /** @return array<string, int> */
    private static function warnings(string $id): array
    {
        return (array) json_decode(self::stored($id)['warnings'] ?? '[]', true);
    }

    /**
     * Jobs A, B, A, B, .. for ever - fails the test instead of hanging once a seek runs past 5 000.
     * @param-out int $yields the jobs it handed out
     */
    private static function endless(?int &$yields): \Closure
    {
        $yields = 0;
        return static function () use (&$yields): \Generator {
            while (true) {
                if (++$yields > 5000) {
                    throw new \LogicException('A seek without a bound');
                }
                yield ['key' => ($yields % 2) ? 's1:a' : 's1:b', 'items' => []];
            }
        };
    }

    /** the run's step died holding its lease, after two steps without progress */
    private static function diedTwice(string $id): void
    {
        self::storedSet($id, 'lease', (string) (self::NOW - 1));
        self::storedSet($id, 'lease_tok', 'dead-step');
        self::storedSet($id, 'failures', '2');
    }
}
