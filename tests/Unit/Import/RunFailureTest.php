<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\CheckResult;
use AA\Import\Run;
use AA\Import\RunCursor;
use AA\Import\Stream;
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

    // the cap stops the download itself - no step parses an answer over it
    public function test_source_answerOverItsMaxBytesRefused(): void
    {
        $source           = self::source(streams: 1, jobs: 1, items: 0);
        $source->maxBytes = 100;
        $source->jobs['s1'][0]['grabber'] = new \AA\IO\Grabber\CSV('https://1.1.1.1/data.csv');
        TestRun::$http    = static fn(): \Symfony\Component\HttpClient\Response\MockResponse => new \Symfony\Component\HttpClient\Response\MockResponse("key,val\n" . str_repeat("k,v\n", 50));
        $run              = self::startRun($source, ['dry' => '1']);

        self::runAll($run->getId());

        self::assertSame(['error', 'SOURCE'], self::errorOf($run->getId()));
        self::assertCount(1, TestRun::$requests);
    }
    /** a status the grabber gets is kept as a detail; the URL, with its token, is kept nowhere */
    #[DataProvider('statusDetails')]
    public function test_source_statusKeptAsDetail(int $status, string $code): void
    {
        $source = self::source(streams: 1, jobs: 1, items: 0);
        $source->jobs['s1'][0]['grabber'] = new \AA\IO\Grabber\CSV('https://1.1.1.1/data.csv?token=tok3n5151');
        TestRun::$http = static fn(): \Symfony\Component\HttpClient\Response\MockResponse => new \Symfony\Component\HttpClient\Response\MockResponse('gone at https://1.1.1.1/data.csv?token=tok3n5151', ['http_code' => $status]);
        $run = self::startRun($source);

        self::runAll($run->getId());

        $stored = self::stored($run->getId());
        self::assertSame(['error', $code, "http:$status"], [$stored['state'], $stored['error_code'], $stored['error_detail']]);
        self::assertStringNotContainsString('tok3n5151', json_encode($stored, JSON_THROW_ON_ERROR));
        self::assertSame(['code' => $code, 'where' => $stored['error_path'], 'detail' => "http:$status"], TestRun::get($run->getId())?->progress(AuthGetUid())['error']);
    }

    public static function statusDetails(): array
    {
        return ['404' => [404, 'SOURCE'], '410' => [410, 'SOURCE'], '429' => [429, 'TRANSPORT'], '503' => [503, 'TRANSPORT'], '500' => [500, 'TRANSPORT']];
    }

    // while it waits to try again the page learns why - not error: null
    #[DataProvider('outageDetails')]
    public function test_source_outageWhileWaitingShowsItsDetail(string $error, string $detail): void
    {
        $source = self::source(streams: 1, jobs: 1, items: 0);
        $source->jobs['s1'][0]['grabber'] = new \AA\IO\Grabber\CSV('https://1.1.1.1/data.csv?token=tok3n5252');
        TestRun::$http = static fn(): \Symfony\Component\HttpClient\Response\MockResponse => new \Symfony\Component\HttpClient\Response\MockResponse('', ['error' => $error . ' for "https://1.1.1.1/data.csv?token=tok3n5252"']);
        $run = self::startRun($source);

        self::runOne();

        $run = TestRun::get($run->getId()) ?? throw new \LogicException('No run');
        self::assertTrue($run->isWaiting(TestRun::$now));
        self::assertSame(['code' => 'TRANSPORT', 'where' => '', 'detail' => $detail], $run->progress(AuthGetUid())['error']);
        self::assertStringNotContainsString('tok3n5252', json_encode(self::stored($run->getId()), JSON_THROW_ON_ERROR));
    }

    public static function outageDetails(): array
    {
        return ['dns' => ['Could not resolve host: data.example', 'dns'], 'timeout' => ['Idle timeout reached', 'timeout'], 'tls' => ['SSL connect error', 'tls']];
    }

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

        self::assertSame('', TestRun::$committed[0]->retry, 'no retry: it would go round again');
        self::assertSame(['k1'], TestRun::storedValues('relation........'), 'the repeated job does not start, nor the rest of the stream');
        self::assertSame('0', self::stored($run->getId())['failures']);

        self::runAll($run->getId());
        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame([Run::SOURCE_CYCLE => 1, Run::REMARK => [Run::SOURCE_CYCLE => 1]], self::warnings($run->getId()), 'no job failed: the source went round');
        self::assertSame([['code' => Run::SOURCE_CYCLE, 'text' => 'Stream Stream 1 (s1): the source handed out the same job twice in a row; the rest of the stream was skipped']], TestRun::get($run->getId())?->remarks(), 'the operator sees which stream');
        self::assertSame([1, 1, 1], [TestRun::get($run->getId())?->counts()['jobs'], TestRun::get($run->getId())?->counts()['skipped_jobs'], TestRun::get($run->getId())?->counts()['streams']]);
    }

    /** a stream whose label is its key is named once */
    public function test_cycle_labelSameAsKey_namedByTheKey(): void
    {
        $source             = self::source(streams: 1, jobs: 0, items: 0);
        $source->minJob     = 0;
        $source->streams    = [new Stream('s1', 's1', ['fld' => 'text...........1'])];
        $source->jobs['s1'] = [
            ['key' => 's1:a', 'items' => [['key' => 'k1', 'val' => 'v1']]],
            ['key' => 's1:a', 'items' => [['key' => 'k2', 'val' => 'v2']]],
        ];
        $run = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame('Stream s1: the source handed out the same job twice in a row; the rest of the stream was skipped', TestRun::get($run->getId())?->remarks()[0]['text'] ?? '');
    }

    /** a stream skipped for its cycle after it stored something still gets its expression */
    public function test_cycleAfterStoring_keepsTheAfterStreamExpression(): void
    {
        $source             = self::source(streams: 2, jobs: 0, items: 0);
        $source->minJob     = 0;
        $source->jobs['s1'] = [
            ['key' => 's1:a', 'items' => [['key' => 'k1', 'val' => 'v1']]],
            ['key' => 's1:a', 'items' => [['key' => 'k2', 'val' => 'v2']]],
        ];
        TestRun::$evaluate  = static function (): void {};
        $run                = self::startRun($source, definition: ['after_stream' => '{x}']);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame([Run::SOURCE_CYCLE => 1, Run::REMARK => [Run::SOURCE_CYCLE => 1]], self::warnings($run->getId()));
        self::assertCount(1, TestRun::$evaluated, 's1, which stored k1 (s2 has no job)');
    }

    /**
     * A source that sees a repeated page only among the pages it fetched in one step: a step going on
     * from the cursor fetches one more page before it sees the cycle again. The rest of the stream goes
     * at the first cycle, so the run does not creep on by a page a step.
     */
    public function test_cycleSeenWithinAStep_skipsTheStreamAtOnce(): void
    {
        $source             = self::source(streams: 2, jobs: 1, items: 1);
        $source->minJob     = 5;
        $spent              = false;
        $source->jobs['s1'] = static function () use (&$spent): \Generator {
            $before = false;
            for ($n = 1; $n <= 50; $n++) {
                $fetched = false;
                // the source ignores the page: every page has the same rows
                $job = ['key' => "s1:p$n", 'items' => [['key' => 'k-p', 'val' => 'v']], 'onPrepare' => static function () use (&$fetched, &$spent): void {
                    $fetched = true;
                    if (!$spent) {
                        // the first step has no time for a second page
                        $spent             = true;
                        TestRun::$microNow = TestRun::$now + 25.5;
                    }
                }];
                yield $job;
                if ($fetched && $before) {
                    yield $job;
                    return;
                }
                $before = $fetched;
            }
        };
        $run = self::startRun($source);

        $steps = self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertCount(2, $steps, implode(' | ', $steps));
        self::assertSame('', TestRun::$committed[0]->retry, 'the first step only ran out of time');
        self::assertSame(['k-p', 'k-p', 'k-p', 'k-s2:a-1'], TestRun::storedValues('relation........'), 'p1; then p2 and p3, the same, and s2');
        self::assertSame([Run::SOURCE_CYCLE => 1, Run::REMARK => [Run::SOURCE_CYCLE => 1]], self::warnings($run->getId()), 'once, though two steps saw the stream');
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
        self::assertSame(1, self::warnings($run->getId())[Run::SOURCE_CYCLE] ?? 0);
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
        self::assertSame('', TestRun::$committed[0]->retry, 'the step goes on past the cycle');

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['k-s1:a-1', 'k-a', 'k-s3:a-1'], TestRun::storedValues('relation........'));
        self::assertSame([Run::SOURCE_CYCLE => 1, Run::REMARK => [Run::SOURCE_CYCLE => 1]], self::warnings($run->getId()));
        self::assertStringStartsWith('Stream Stream 2 (s2): ', TestRun::get($run->getId())?->remarks()[0]['text'] ?? '');
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
            self::sorted(['job' => 's1:c', 'pages' => 3, 'cur' => sha1('s1:c'), 'failed' => 1, 'wrote' => 1, 'skips' => 1, 'rkeyed' => 1] + self::pastCheck()),
            self::sorted($skip->position),
            'the next job, not started yet',
        );
    }

    public function test_retriesExhausted_thirdFailedJobInARowSkipsTheStream(): void
    {
        $source = self::checked(streams: 2, jobs: 5, items: 1);
        foreach ([1, 2, 3] as $j) {
            $source->jobs['s1'][$j]['error'] = new SourceUnavailable('TRANSPORT', 'No answer');
        }
        $run = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['k-s1:a-1', 'k-s2:a-1', 'k-s2:b-1', 'k-s2:c-1', 'k-s2:d-1', 'k-s2:e-1'], TestRun::storedValues('relation........'), 'job e of s1 is skipped with the stream');
        self::assertSame(['JOB_FAILED' => 3, 'JOBS_FAILING' => 1], self::warnings($run->getId()));
        self::assertSame(3, TestRun::get($run->getId())?->counts()['skipped_jobs']);
        $s1 = array_filter($source->jobCalls, static fn(array $call): bool => $call['stream'] === 's1');
        self::assertCount(9 + 2 + 1, $s1, 'three steps per failed job, the first two skips look for the next one, the third does not');
    }

    public function test_retriesExhausted_jobDoneResetsTheSkips(): void
    {
        $source = self::checked(streams: 1, jobs: 6, items: 1);
        foreach ([1, 2, 4] as $j) {
            $source->jobs['s1'][$j]['error'] = new SourceUnavailable('TRANSPORT', 'No answer');
        }
        $run = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['k-s1:a-1', 'k-s1:d-1', 'k-s1:f-1'], TestRun::storedValues('relation........'));
        self::assertSame(['JOB_FAILED' => 3], self::warnings($run->getId()), 'three failed jobs, never three in a row');
    }

    public function test_retriesExhausted_lastJobClosesStreamWithoutVersion(): void
    {
        $source                         = self::checked(streams: 2, jobs: 2, items: 1);
        $source->jobs['s1'][1]['error'] = new SourceUnavailable('TRANSPORT', 'No answer');
        self::streamVersions(['s1' => 'v-old']);
        $run = self::startRun($source, ['mode' => 'changed']);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(self::sorted(['i' => 1, 'rkeyed' => 1] + self::pastCheck()), self::sorted(TestRun::$committed[3]->position), 'the run had a keyed item');
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

        self::assertSame(['JOB_GONE' => 1, Run::SOURCE_CYCLE => 1, Run::REMARK => [Run::SOURCE_CYCLE => 1]], self::warnings($run->getId()), 'the cursor\'s job is not among the first MAX_PAGES; the restarted stream ends at the cap');
        self::assertSame('', TestRun::$committed[0]->retry);
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

    public function test_retriesExhausted_lostJob_seeksUpToTheSourceLimit(): void
    {
        $source             = self::checked(streams: 2, jobs: 1, items: 1);
        $source->maxJobs    = RunCursor::MAX_PAGES + 200;
        $source->jobs['s1'] = self::endless($yields);
        $run                = self::startRun($source, ['position' => self::onJob('s1:z')]);
        self::diedTwice($run->getId());

        self::runOne();

        self::assertSame(RunCursor::MAX_PAGES + 200, $yields);
    }

    /** a job failing past job 1000 of a source declaring more skips only that job, not the rest of the stream */
    public function test_retriesExhausted_failedJobPastMaxPages_skipsOnlyIt(): void
    {
        $source                         = self::checked(streams: 1, jobs: 3, items: 1);
        $source->maxJobs                = RunCursor::MAX_PAGES + 10;
        $source->jobs['s1'][1]['error'] = new SourceUnavailable('TRANSPORT', 'No answer');
        $run                            = self::startRun($source, ['position' => ['job' => 's1:b', 'pages' => RunCursor::MAX_PAGES, 'cur' => sha1('s1:b')] + self::pastCheck()]);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['k-s1:c-1'], TestRun::storedValues('relation........'), 'the job after the failed one');
        self::assertSame(['JOB_FAILED' => 1], self::warnings($run->getId()));
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
        $run  = self::startRun(self::checked(streams: 2, jobs: 1, items: 1));
        $last = new BatchStepResult(self::onJob('s1:a'), false, ['read' => 2, 'skipped_jobs' => 1], ['JOB_GONE' => 1, 'JOB_FAILED' => 2], retry: 'TRANSPORT', extra: ['errors' => [['stream' => 's1', 'job' => 's1:a', 'code' => 'VALIDATE', 'msg' => 'Item 1']]]);

        $next = TestRun::get($run->getId())?->exhausted($last);

        self::assertEquals(['skipped_jobs' => 2, 'read' => 2, 'streams' => 1], $next?->counts, 'skipping its last job closed s1');
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
        // the cap comes before the third skip in a row would end the stream
        $run = self::startRun($source, ['position' => ['job' => 's1:a', 'pages' => RunCursor::MAX_PAGES - 1, 'cur' => sha1('s1:a')] + self::pastCheck()]);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        $skips = array_values(array_filter(TestRun::$committed, static fn($r): bool => ($r->retry === '') && !$r->finished));
        self::assertSame(
            [['s1:b', RunCursor::MAX_PAGES, 0], ['', 0, 1]],
            array_map(static fn($r): array => [$r->position['job'], $r->position['pages'], $r->position['i']], $skips),
            'each skip starts a job - the cap ends the stream',
        );
        self::assertSame(['JOB_FAILED' => 2], self::warnings($run->getId()));
        self::assertCount(2, $source->checkCalls);
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
        return ['check fails' => ['fails', 'AUTH'], 'check cannot reach the source' => ['down', 'TRANSPORT'], 'check refused' => ['refused', 'AUTH']];
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

        self::assertSame(['error', $code], self::errorOf($run->getId()), 'a failed check() tells its own code, not the TRANSPORT of the job');
        if ($how === 'fails') {
            self::assertSame('check', self::stored($run->getId())['error_path']);
        }
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
