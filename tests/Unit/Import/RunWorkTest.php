<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\Context;
use AA\Import\Definition;
use AA\Import\ParamsGrabber;
use AA\Import\Run;
use AA\Import\Probe;
use AA\Import\RunCursor;
use AA\Import\Stream;
use AA\Import\StreamState;
use AA\IO\Grabber\CSV;
use AA\IO\SourceRefused;
use AA\IO\SourceUnavailable;
use AA\IO\DB\DB_AA;
use AA\Later\BatchBudgetSpent;
use AA\Later\Toexecute;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Import\Fixtures\RunSteps;
use Tests\Unit\Import\Fixtures\TestRun;

require_once __DIR__ . '/fixtures/RunSteps.php';

/**
 * Run::work() step by step through the queue: streams, jobs and items of a ScriptedSource into
 * savers that record what they would store. Each step's budget is the core's - 27 s outside a
 * queue round - and each item costs TestRun::$itemSeconds of the run's clock.
 *
 * Each test in its own process: FakeSlice loads include/locsess.php, whose getDB() collides with
 * the stub tests/Unit/IO/DB loads when that directory runs first.
 */
#[RunTestsInSeparateProcesses]
final class RunWorkTest extends TestCase
{
    use RunSteps;

    // ------------------------------------------------------------ walking streams and jobs

    public function test_work_walksStreamsAndJobsAcrossSteps(): void
    {
        $source = self::source(streams: 3, jobs: 2, items: 2, seconds: 10.0);
        $run    = self::startRun($source);

        $steps = self::runAll($run->getId());

        $after = TestRun::get($run->getId());
        self::assertSame('done', $after?->state(), implode(' | ', $steps));
        self::assertCount(6, array_filter($steps, static fn(string $s): bool => str_starts_with($s, 'step') || ($s === 'done')), 'a step per job: the next job does not fit after the first');
        self::assertSame(self::keys(3, 2, 2), TestRun::storedValues('relation........'));
        $counts = $after->counts();
        self::assertSame([12, 12, 0, 0, 0, 0, 6, 3, 0, 0, 0], array_values($counts));
        self::assertSame(['read', 'new', 'updated', 'skipped', 'filtered', 'errors', 'jobs', 'streams', 'unchanged', 'skipped_jobs', 'unversioned'], array_keys($counts));
    }

    public function test_work_storesTheParameterTargetOfEachStream(): void
    {
        $source = self::source(streams: 2, jobs: 1, items: 1);
        $source->streams[1] = new Stream('s2', 'Stream 2', ['fld' => 'text...........2']);
        $run    = self::startRun($source);

        self::runAll($run->getId());

        $stored = TestRun::stored();
        self::assertSame(['v-s1:a-1'], $stored[0]['text...........1']);
        self::assertSame(['v-s2:a-1'], $stored[1]['text...........2']);
        self::assertArrayNotHasKey('fld', $stored[0], 'parameters serve the mapping, they are not stored');
    }

    public function test_work_continuesLongJobFromOffset(): void
    {
        $source = self::source(streams: 1, jobs: 1, items: 5, seconds: 10.0);
        $run    = self::startRun($source);

        self::runOne();
        $position = TestRun::get($run->getId())?->position();
        self::assertSame('s1:a', $position['job']);
        self::assertSame(3, $position['offset'], 'three items fit in 27 s');
        self::assertNotSame('', $position['fp']);
        self::assertSame(['k-s1:a-1', 'k-s1:a-2', 'k-s1:a-3'], TestRun::storedValues('relation........'));

        self::runAll($run->getId());

        self::assertSame('done', TestRun::get($run->getId())?->state());
        self::assertSame(self::keys(1, 1, 5), TestRun::storedValues('relation........'), 'each item once - the second step skipped the stored ones');
        self::assertSame(5, TestRun::get($run->getId())?->counts()['read']);
    }

    /** each step adds its own time; the waits between steps do not count */
    public function test_work_countsWorkingTimeOfTheSteps(): void
    {
        $source = self::source(streams: 1, jobs: 1, items: 5, seconds: 10.0);
        $run    = self::startRun($source);
        self::assertNull(TestRun::get($run->getId())?->workedSeconds(), 'no step yet');

        self::runOne();
        self::assertSame(30, TestRun::get($run->getId())?->workedSeconds(), 'three items of 10 s');
        TestRun::$now = (int) TestRun::$now + 600;
        self::runAll($run->getId());

        self::assertSame('done', TestRun::get($run->getId())?->state());
        self::assertSame(50, TestRun::get($run->getId())?->workedSeconds());
    }

    public function test_work_restartsJobWhenFingerprintChanged(): void
    {
        $source = self::source(streams: 1, jobs: 1, items: 5, seconds: 10.0);
        $run    = self::startRun($source);
        self::runOne();

        // the source changed under the offset: the skipped items would not be the stored ones
        $source->jobs['s1'][0]['items'][0] = ['key' => 'k-new', 'val' => 'v-new'];
        TestRun::$savers = [];
        self::runOne();

        $after = TestRun::get($run->getId());
        self::assertSame('running', $after?->state());
        self::assertSame(['k-new', 'k-s1:a-2', 'k-s1:a-3'], TestRun::storedValues('relation........'), 'the job started over');
        self::assertSame(1, $after->position()['restarts']);
        self::assertSame(3, $after->position()['offset']);
        self::assertSame('0', self::stored($run->getId())['failures'], 'the first restart is progress');
        self::assertSame(6, $after->counts()['read'], 'the restarted job read its items from the first one');
    }

    public function test_work_respectsMinJobSeconds(): void
    {
        $source         = self::source(streams: 1, jobs: 2, items: 1, seconds: 5.0);
        $source->minJob = 25;
        $run            = self::startRun($source);

        self::runOne();

        self::assertSame(['k-s1:a-1'], TestRun::storedValues('relation........'), '22 s left, the next job needs 25');
        $position = TestRun::get($run->getId())?->position();
        self::assertSame(['s1:b', 0, '', 2], [$position['job'], $position['offset'], $position['fp'], $position['pages']]);

        $source->minJob = 20;
        self::runOne();
        self::assertSame('done', TestRun::get($run->getId())?->state(), 'the first job of a step always starts');
    }

    public function test_work_nextJobStartsWhenTimeIsLeft(): void
    {
        $source         = self::source(streams: 2, jobs: 2, items: 1, seconds: 5.0);
        $source->minJob = 5;
        $run            = self::startRun($source);

        self::runOne();

        self::assertSame(self::keys(2, 2, 1), TestRun::storedValues('relation........'));
        self::assertSame('done', TestRun::get($run->getId())?->state());
    }

    /** Continue gave the run to a new step: this one, past its lease, starts no further job beside it */
    public function test_work_stopsBetweenJobsOnceTheRunIsNoLongerItsOwn(): void
    {
        $source         = self::source(streams: 1, jobs: 2, items: 1, seconds: 5.0);
        $source->minJob = 5;
        $run            = self::startRun($source);
        $id             = $run->getId();
        $source->jobs['s1'][0]['onPrepare'] = static function () use ($id): void {
            self::storedSet($id, 'lease_tok', 'retried');
        };

        self::runOne();

        self::assertSame(['k-s1:a-1'], TestRun::storedValues('relation........'), 'the second job did not start');
        self::assertSame([], TestRun::$committed, 'the end of the step is not its own');
    }

    /** past its lease the step ends at the next job: a Continue may give the run to another step any moment */
    public function test_work_stopsBetweenJobsOnceTheLeaseRanOut(): void
    {
        $source         = self::source(streams: 1, jobs: 2, items: 1, seconds: 5.0);
        $source->minJob = 5;
        $run            = self::startRun($source);
        $id             = $run->getId();
        $source->jobs['s1'][0]['onPrepare'] = static function () use ($id): void {
            self::storedSet($id, 'lease', (string) (self::NOW - 1));
        };

        self::runOne();

        self::assertSame(['k-s1:a-1'], TestRun::storedValues('relation........'));
        self::assertSame('s1:b', TestRun::get($id)?->position()['job'], 'the step ended at the job boundary');
        self::assertSame('running', self::stored($id)['state']);
    }

    public function test_work_budgetFromTimeLeft(): void
    {
        $source = self::source(streams: 1, jobs: 1, items: 1);
        $run    = self::startRun($source);

        // a round of 40 s with 12 s left: the step gets those less the core's margin of 3 s
        $round = new \ReflectionProperty(Toexecute::class, 'round');
        $round->setValue(null, [microtime(true) - 28.0, 40.0]);
        try {
            self::runOne();
        } finally {
            $round->setValue(null, null);
        }

        self::assertCount(1, TestRun::$budgets);
        self::assertEqualsWithDelta(9.0, TestRun::$budgets[0]->deadline - self::NOW, 0.5);
        self::assertSame('done', TestRun::get($run->getId())?->state());
    }

    // ------------------------------------------------------------ modes run and changed

    public function test_work_changedSkipsUnchangedStreams(): void
    {
        $source         = self::source(streams: 2, jobs: 1, items: 1, probed: true);
        $source->probes = ['s1' => new Probe(Probe::UNCHANGED, 'v1')];
        $run            = self::startRun($source, ['mode' => 'changed']);

        self::runAll($run->getId());

        self::assertSame(['k-s2:a-1'], TestRun::storedValues('relation........'));
        self::assertSame(['s1', 's2'], array_column($source->probeCalls, 'stream'));
        self::assertSame(['s2'], array_column($source->jobCalls, 'stream'), 'no job of an unchanged stream');
        $counts = TestRun::get($run->getId())?->counts();
        self::assertSame([1, 1], [$counts['unchanged'], $counts['streams']]);
    }

    public function test_work_changedImportsUnknownProbe(): void
    {
        $source         = self::source(streams: 1, jobs: 1, items: 1, probed: true);
        $source->probes = ['s1' => new Probe(Probe::UNKNOWN)];
        $run            = self::startRun($source, ['mode' => 'changed']);

        self::runAll($run->getId());

        self::assertSame(['k-s1:a-1'], TestRun::storedValues('relation........'));
    }

    public function test_work_changedPassesKnownVersion(): void
    {
        self::streamVersions(['s1' => '2023']);
        $source = self::source(streams: 2, jobs: 1, items: 1, probed: true);
        $run    = self::startRun($source, ['mode' => 'changed']);

        self::runAll($run->getId());

        self::assertSame([['stream' => 's1', 'known' => '2023'], ['stream' => 's2', 'known' => null]], $source->jobCalls);
        self::assertSame([['stream' => 's1', 'known' => '2023'], ['stream' => 's2', 'known' => null]], $source->probeCalls);
    }

    public function test_work_runPassesNullKnown(): void
    {
        self::streamVersions(['s1' => '2023']);
        $source = self::source(streams: 1, jobs: 1, items: 1, probed: true);
        $run    = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame([['stream' => 's1', 'known' => null]], $source->jobCalls);
        self::assertSame([['stream' => 's1', 'known' => null]], $source->probeCalls, 'run imports every stream - its version is asked at its start');
    }

    public function test_work_singleStream(): void
    {
        $source = self::source(streams: 3, jobs: 1, items: 1);
        $run    = self::startRun($source, ['stream_keys' => '["s2"]']);

        self::runAll($run->getId());

        self::assertSame(['k-s2:a-1'], TestRun::storedValues('relation........'));
        self::assertSame(['s2'], array_column($source->jobCalls, 'stream'));
    }

    public function test_work_dryRunWritesNothingAndStopsAtLimit(): void
    {
        $source = self::source(streams: 2, jobs: 1, items: 5);
        $run    = self::startRun($source, ['dry' => '1', 'limit' => '3']);

        self::runAll($run->getId());

        $after = TestRun::get($run->getId());
        self::assertSame('done', $after?->state());
        self::assertSame([], TestRun::stored(), 'a dry run stores nothing');
        self::assertSame(3, $after->counts()['read']);
        self::assertSame(3, $after->counts()['new'], 'what it would store is counted');
        self::assertSame([], self::$invalidated, 'nothing written, no page to invalidate');
    }

    // ------------------------------------------------------------ what a step refuses

    public function test_work_refusesGrabberWithoutClient(): void
    {
        // no parameters and no direct fill: only the way it fetches is wrong
        $source                       = self::source(streams: 1, jobs: 1, items: 1);
        $source->streams[0]           = new Stream('s1', 'Stream 1', []);
        $source->jobs['s1'][0]['via'] = false;
        $run                          = self::startRun($source, [], ['mapping' => self::NO_PARAM_MAPPING]);

        self::runAll($run->getId());

        self::assertSame(['error', 'DEFINITION'], self::errorOf($run->getId()));
        self::assertSame([], TestRun::$savers, 'nothing was fetched');
    }

    public function test_work_fileUrlIsSource(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'aa-run');
        file_put_contents($file, "key,val\nk1,v1\n");
        try {
            $source                           = self::source(streams: 1, jobs: 1, items: 0);
            $source->jobs['s1'][0]['grabber'] = new CSV('file://' . $file);
            $run                              = self::startRun($source);

            self::runAll($run->getId());
        } finally {
            unlink($file);
        }

        self::assertSame(['error', 'SOURCE'], self::errorOf($run->getId()));
        self::assertSame([], TestRun::stored(), 'the file was not read');
    }

    public function test_work_directFillWithParametersRefused(): void
    {
        $source                          = self::source(streams: 1, jobs: 1, items: 1);
        $source->jobs['s1'][0]['direct'] = true;
        $run                             = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['error', 'DEFINITION'], self::errorOf($run->getId()));
        self::assertSame([], TestRun::stored());
    }

    public function test_work_mappingProblemIsDefinition(): void
    {
        $source = self::source(streams: 1, jobs: 1, items: 1);
        $run    = self::startRun($source, [], ['key_fields' => '["headline........"]']);

        self::runAll($run->getId());

        self::assertSame(['error', 'DEFINITION'], self::errorOf($run->getId()));
        self::assertSame('KEY_NOT_TARGET', self::stored($run->getId())['error_path']);
        self::assertSame([], TestRun::$savers);
    }

    public function test_work_prepareFailureNeverStoredAsText(): void
    {
        $source                         = self::source(streams: 1, jobs: 1, items: 1);
        $source->jobs['s1'][0]['error'] = new \RuntimeException('token=abc123 failed');
        $run                            = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['error', 'SOURCE'], self::errorOf($run->getId()));
        self::assertStringNotContainsString('abc123', json_encode(TableDb::rows('object_text'), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('abc123', json_encode(TableDb::rows('log'), JSON_THROW_ON_ERROR));
    }

    public function test_work_sourceRefusedKeepsItsReason(): void
    {
        $source                         = self::source(streams: 1, jobs: 1, items: 1);
        $source->jobs['s1'][0]['error'] = new SourceRefused('AUTH', 'HTTP 401 from example.org');
        $run                            = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['error', 'AUTH'], self::errorOf($run->getId()));
    }

    public function test_work_unavailableSourceRetriesFromLastSavedJob(): void
    {
        $source                         = self::source(streams: 1, jobs: 2, items: 1);
        $source->minJob                 = 1;
        $source->jobs['s1'][1]['error'] = new SourceUnavailable('TRANSPORT', 'No answer');
        $run                            = self::startRun($source);

        self::runOne();

        $stored = self::stored($run->getId());
        self::assertSame('running', $stored['state']);
        self::assertSame('0', $stored['failures'], 'the first job got stored - progress');
        self::assertSame('s1:b', TestRun::get($run->getId())?->position()['job']);
        self::assertSame((string) (self::NOW + 1800), $stored['next_at'], 'the source gets time to recover');
        self::assertSame(['k-s1:a-1'], TestRun::storedValues('relation........'));
    }

    // ------------------------------------------------------------ errors, page cache

    public function test_work_itemErrorsNumberedWithinTheJob(): void
    {
        $source = self::source(streams: 1, jobs: 1, items: 5, seconds: 10.0);
        // the fifth item fails the validation
        $source->jobs['s1'][0]['items'][4] = ['key' => 'k5', 'val' => 'bad'];
        $run = self::startRun($source, [], ['validate' => '["{ifeq:{index:text...........1}:bad:no:1}"]']);

        self::runAll($run->getId());

        $errors = array_merge(...array_map(static fn($r): array => $r->extra['errors'] ?? [], TestRun::$committed));
        self::assertCount(1, $errors);
        self::assertSame(['s1', 's1:a', 'VALIDATE'], [$errors[0]['stream'], $errors[0]['job'], $errors[0]['code']]);
        self::assertStringStartsWith('Item 5 (', $errors[0]['msg'], 'the number in the job, not in the second step');
        self::assertSame(1, TestRun::get($run->getId())?->counts()['errors']);
    }

    public function test_work_pagecacheInvalidatedEachStep(): void
    {
        $source = self::source(streams: 2, jobs: 1, items: 1, seconds: 10.0);
        $source->minJob = 20;
        $run    = self::startRun($source);

        self::runOne();
        self::assertSame([[[self::SLICE], 'M']], self::$invalidated, 'once at the end of the step');

        self::runAll($run->getId());
        self::assertSame([[[self::SLICE], 'M']], array_unique(self::$invalidated, SORT_REGULAR));
        self::assertGreaterThanOrEqual(2, count(self::$invalidated));
    }

    public function test_work_pagecacheInvalidatedWhenTheStepFails(): void
    {
        $source                         = self::source(streams: 1, jobs: 2, items: 1);
        $source->minJob                 = 1;
        $source->jobs['s1'][1]['error'] = new SourceRefused('SOURCE', 'bad answer');
        $run                            = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['error', 'SOURCE'], self::errorOf($run->getId()));
        self::assertSame([[[self::SLICE], 'M']], self::$invalidated, 'the first job stored an item');
    }

    public function test_work_eachGrabberGetsTheGuardedClient(): void
    {
        $source = self::source(streams: 1, jobs: 2, items: 1);
        $source->minJob = 1;
        $run    = self::startRun($source);

        self::runAll($run->getId());

        self::assertCount(2, $source->grabbers);
        foreach ($source->grabbers as $grabber) {
            self::assertNotNull($grabber->client);
        }
    }

    // ------------------------------------------------------------ control

    public function test_mayControl_feedingOnTargetSlice(): void
    {
        $run = self::startRun(self::source(streams: 1, jobs: 1, items: 1));
        $uid = (string) $GLOBALS['permission_uid'];

        self::assertTrue($run->mayControl($uid));

        self::perms(PS_EDIT_ALL_ITEMS, PS_FEEDING);   // feeding of another slice does not count
        self::assertFalse(TestRun::get($run->getId())?->mayControl($uid));
    }

    public function test_stillValid_falseWhenDefinitionDeleted(): void
    {
        $run = self::startRun(self::source(streams: 1, jobs: 1, items: 1));
        DB_AA::delete('object_text', [['object_id', self::DEF]]);

        self::runOne();

        self::assertSame('ended', TestRun::get($run->getId())?->state());
        self::assertSame([], TestRun::stored());
    }

    public function test_run_selectorAndAccessors(): void
    {
        $run = self::startRun(self::source(streams: 1, jobs: 1, items: 1), ['mode' => 'changed', 'dry' => '1']);

        self::assertSame('Import_' . self::DEF, $run->selector());
        self::assertSame(self::DEF, $run->definitionId());
        self::assertSame('changed', $run->mode());
        self::assertTrue($run->isDry());
        self::assertSame('Import_' . self::DEF, self::queue()[0]['selector']);
    }

    // ------------------------------------------------------------ a source the run cannot follow

    public function test_work_firstItemChangingEveryStep_endsInError(): void
    {
        $source = self::source(streams: 1, jobs: 1, items: 25, seconds: 10.0);
        $items  = $source->jobs['s1'][0]['items'];
        $call   = 0;
        $source->jobs['s1'] = static function () use ($items, &$call): array {
            $items[0] = ['key' => 'k-changed-' . ++$call, 'val' => 'v'];
            return [['key' => 's1:a', 'items' => $items]];
        };
        $run = self::startRun($source);

        $steps = self::runAll($run->getId());

        self::assertSame(['error', 'SOURCE_UNSTABLE'], self::errorOf($run->getId()));
        self::assertLessThanOrEqual(5, count($steps), implode(' | ', $steps));
        self::assertCount(6, TestRun::stored(), 'three items in the first step, three after the one restart - none once it may not restart');
        self::assertSame(6, TestRun::get($run->getId())?->counts()['read']);
    }

    public function test_work_secondRestartIsUnstableEvenAtTheStepsEnd(): void
    {
        $source                             = self::source(streams: 1, jobs: 1, items: 5);
        $source->jobs['s1'][0]['onPrepare'] = static function (): void {
            TestRun::$microNow = self::NOW + 26.5;   // a refused restart is no request cut short by the step's end
        };
        $run = self::startRun($source, ['position' => ['offset' => 3, 'fp' => 'old', 'restarts' => 1] + self::onJob('s1:a')]);

        self::runOne();

        self::assertSame('SOURCE_UNSTABLE', TestRun::$committed[0]->retry);
        self::assertSame([], TestRun::stored());
        self::assertSame('1', self::stored($run->getId())['failures']);
    }

    public function test_work_jobKeyChangingEveryStep_endsInError(): void
    {
        $source = self::source(streams: 1, jobs: 1, items: 25, seconds: 10.0);
        $items  = $source->jobs['s1'][0]['items'];
        $call   = 0;
        $source->jobs['s1'] = static function () use ($items, &$call): array {
            return [['key' => 's1:a' . ++$call, 'items' => $items]];
        };
        $run = self::startRun($source);

        $steps = self::runAll($run->getId());

        self::assertSame(['error', 'SOURCE_UNSTABLE'], self::errorOf($run->getId()));
        self::assertLessThanOrEqual(5, count($steps), implode(' | ', $steps));
        self::assertSame(1, json_decode(self::stored($run->getId())['warnings'], true)['JOB_GONE'], 'the first job gone restarts the stream');
    }

    public function test_work_morePagesThanMaxIsCycle(): void
    {
        $source         = self::source(streams: 1, jobs: 3, items: 1);
        $source->minJob = 0;
        $from           = ['job' => 's1:a', 'pages' => RunCursor::MAX_PAGES - 1, 'cur' => sha1('s1:a')] + RunCursor::START;
        $run            = self::startRun($source, ['position' => $from]);

        self::runOne();

        self::assertSame(2, TestRun::get($run->getId())?->counts()['read'], 'the 1000th job may start, the 1001st not - what got stored before counts');
        self::assertSame(['', 1], [TestRun::$committed[0]->retry, TestRun::get($run->getId())?->counts()['skipped_jobs']], 'the rest of the stream goes at once');
        self::assertSame(1, TestRun::get($run->getId())?->position()['i']);
        self::assertSame(1, json_decode((string) TestRun::get($run->getId())?->getProperty('warnings'), true)[Run::SOURCE_CYCLE] ?? 0);
        self::assertSame([['code' => Run::SOURCE_CYCLE, 'text' => 'Stream Stream 1 (s1): the source handed out more than ' . RunCursor::MAX_PAGES . ' jobs; the rest of the stream was skipped']], TestRun::get($run->getId())?->remarks());
    }

    /** a source declaring more jobs a stream (one per municipality) is no cycle at MAX_PAGES - only at its own limit */
    public function test_work_sourceLimitAboveMaxPages_noCycle(): void
    {
        $source          = self::source(streams: 1, jobs: 3, items: 1);
        $source->minJob  = 0;
        $source->maxJobs = RunCursor::MAX_PAGES + 2;
        $from            = ['job' => 's1:a', 'pages' => RunCursor::MAX_PAGES - 1, 'cur' => sha1('s1:a')] + RunCursor::START;
        $run             = self::startRun($source, ['position' => $from]);

        self::runOne();

        self::assertSame(3, TestRun::get($run->getId())?->counts()['read']);
        self::assertSame(0, TestRun::get($run->getId())?->counts()['skipped_jobs'] ?? 0);

        $source          = self::source(streams: 1, jobs: 3, items: 1);
        $source->minJob  = 0;
        $source->maxJobs = 5;
        $run             = self::startRun($source, ['position' => ['job' => 's1:a', 'pages' => 4, 'cur' => sha1('s1:a')] + RunCursor::START]);

        self::runOne();

        self::assertSame([2, 1], [TestRun::get($run->getId())?->counts()['read'], TestRun::get($run->getId())?->counts()['skipped_jobs']], 'its own limit is the cycle guard');
    }

    /** a step goes on from a job past the first MAX_PAGES of its stream when the source declares that many */
    public function test_work_resumePastMaxPages_findsTheJob(): void
    {
        $source             = self::source(streams: 1, jobs: 0, items: 0);
        $source->minJob     = 0;
        $source->maxJobs    = 2000;
        $source->jobs['s1'] = static function (): \Generator {
            for ($n = 1; $n <= 1500; $n++) {
                $key = sprintf('s1:j%04d', $n);
                yield ['key' => $key, 'items' => [['key' => "k-$key", 'val' => "v-$key"]]];
            }
        };
        $run = self::startRun($source, ['position' => ['job' => 's1:j1200', 'pages' => 1200, 'cur' => sha1('s1:j1200')] + RunCursor::START]);

        self::runOne();

        self::assertSame([], (array) json_decode(self::stored($run->getId())['warnings'] ?? '[]', true), 'no JOB_GONE, no cycle');
        self::assertSame(301, TestRun::get($run->getId())?->counts()['read'], 'jobs 1200 to 1500');
        self::assertSame(1, TestRun::get($run->getId())?->position()['i']);
    }

    // ------------------------------------------------------------ where the cursor goes when the step stops

    public function test_work_sourceFailingMidJob_keepsWhatWasStored(): void
    {
        $source = self::source(streams: 1, jobs: 1, items: 25);
        $source->jobs['s1'][0]['failAt'] = [23, new SourceUnavailable('TRANSPORT', 'No answer')];
        $run = self::startRun($source);

        self::runOne();

        self::assertCount(22, TestRun::stored());
        $after = TestRun::get($run->getId());
        self::assertSame(22, $after?->position()['offset']);
        self::assertNotSame('', $after->position()['fp']);
        self::assertSame([22, 22], [$after->counts()['read'], $after->counts()['new']]);
        $stored = self::stored($run->getId());
        self::assertSame(['running', '0', (string) (self::NOW + 1800)], [$stored['state'], $stored['failures'], $stored['next_at']]);
    }

    public function test_work_limitHoldsAcrossAFailedStep(): void
    {
        $source = self::source(streams: 1, jobs: 1, items: 30);
        $source->jobs['s1'][0]['failAt'] = [23, new SourceUnavailable('TRANSPORT', 'No answer')];
        $run = self::startRun($source, ['limit' => '24']);

        self::runOne();
        self::assertSame(22, TestRun::get($run->getId())?->counts()['read'], 'the failed step counted what it read');

        $source->jobs['s1'][0]['failAt'] = [0, null];
        self::runAll($run->getId());

        self::assertSame('done', TestRun::get($run->getId())?->state());
        self::assertSame(24, TestRun::get($run->getId())?->counts()['read']);
        self::assertCount(24, TestRun::stored(), 'the next step read only up to the limit');
    }

    public function test_work_budgetSpentAfterAJob_goesOnAtOnce(): void
    {
        $source                         = self::source(streams: 1, jobs: 2, items: 1);
        $source->minJob                 = 1;
        $source->jobs['s1'][1]['error'] = new BatchBudgetSpent('spent');
        $run                            = self::startRun($source);

        self::runOne();

        $stored = self::stored($run->getId());
        self::assertSame(['running', '0', (string) self::NOW], [$stored['state'], $stored['failures'], $stored['next_at']], 'no wait for the source');
        self::assertSame('s1:b', TestRun::get($run->getId())?->position()['job']);
        self::assertSame('', TestRun::$committed[0]->retry);
    }

    public function test_work_budgetSpentWithoutProgress_isNoProgress(): void
    {
        $source                         = self::source(streams: 1, jobs: 1, items: 1);
        $source->jobs['s1'][0]['error'] = new BatchBudgetSpent('spent');
        $run                            = self::startRun($source, ['position' => self::onJob('s1:a')]);

        self::runOne();

        self::assertSame('NO_PROGRESS', TestRun::$committed[0]->retry);
        self::assertSame(['1', (string) self::NOW], [self::stored($run->getId())['failures'], self::stored($run->getId())['next_at']]);
    }

    public function test_work_timeoutAtOwnDeadline_isNoProgressNotTransport(): void
    {
        $source                             = self::source(streams: 1, jobs: 1, items: 1);
        $source->jobs['s1'][0]['onPrepare'] = static function (): void {
            TestRun::$microNow = self::NOW + 26.5;   // half a second of the 27 s left
        };
        $source->jobs['s1'][0]['error'] = new SourceUnavailable('TRANSPORT', 'No answer in time');
        $run                            = self::startRun($source, ['position' => self::onJob('s1:a')]);

        self::runOne();

        self::assertSame('NO_PROGRESS', TestRun::$committed[0]->retry);
        self::assertSame((string) self::NOW, self::stored($run->getId())['next_at'], 'the next step at once');
    }

    public function test_work_budgetSpentBeforeTheJobWasFetched_keepsTheCursor(): void
    {
        $source              = self::source(streams: 1, jobs: 1, items: 5);
        $source->jobsSeconds = 30.0;
        $from                = ['offset' => 3, 'fp' => 'fingerprint-of-3'] + self::onJob('s1:a');
        $run                 = self::startRun($source, ['position' => $from]);

        self::runOne();

        self::assertSame(self::sorted($from), self::sorted(TestRun::get($run->getId())?->position()));
        self::assertSame('NO_PROGRESS', TestRun::$committed[0]->retry);
    }

    public function test_work_jobGone_restartsTheStream(): void
    {
        $source         = self::source(streams: 1, jobs: 2, items: 1);
        $source->minJob = 1;
        $run            = self::startRun($source, ['position' => ['offset' => 3, 'fp' => 'old'] + self::onJob('s1:x')]);

        self::runOne();

        self::assertSame(['k-s1:a-1', 'k-s1:b-1'], TestRun::storedValues('relation........'));
        self::assertSame('done', TestRun::get($run->getId())?->state());
        self::assertSame(['JOB_GONE' => 1], json_decode(self::stored($run->getId())['warnings'], true));
    }

    public function test_work_streamGone_skipped(): void
    {
        $source = self::source(streams: 2, jobs: 1, items: 1);
        $run    = self::startRun($source, ['stream_keys' => '["s1","gone","s2"]']);

        self::runAll($run->getId());

        self::assertSame(['k-s1:a-1', 'k-s2:a-1'], TestRun::storedValues('relation........'));
        self::assertSame(['STREAM_GONE' => 1], json_decode(self::stored($run->getId())['warnings'], true));
        self::assertSame(2, TestRun::get($run->getId())?->counts()['streams']);
    }

    public function test_work_limitStopsInTheJobItReaches(): void
    {
        $source         = self::source(streams: 1, jobs: 2, items: 2);
        $source->minJob = 1;
        $run            = self::startRun($source, ['dry' => '1', 'limit' => '2']);

        self::runAll($run->getId());

        self::assertCount(1, TestRun::$savers, 'the second job does not start');
        self::assertSame(2, TestRun::get($run->getId())?->counts()['read']);
        self::assertSame(0, TestRun::get($run->getId())?->position()['wrote'], 'a dry run writes nothing');
    }

    public function test_work_versionMaxCarriedInTheCursor(): void
    {
        $source             = self::source(streams: 1, jobs: 2, items: 0, seconds: 5.0);
        $source->minJob     = 25;
        $source->streams[0] = new Stream('s1', 'Stream 1', ['fld' => 'text...........1'], '', 'year');
        $source->jobs['s1'][0]['items'] = [['key' => 'k1', 'val' => '1', 'year' => '2019'], ['key' => 'k2', 'val' => '2', 'year' => '2021'], ['key' => 'k3', 'val' => '3', 'year' => '2020']];
        $run = self::startRun($source);

        self::runOne();

        self::assertSame(['s1:b', '2021'], [TestRun::get($run->getId())?->position()['job'], TestRun::get($run->getId())?->position()['vmax']]);
    }

    public function test_filter_skippedRowsKeepStreamVersion(): void
    {
        $source             = self::source(streams: 1, jobs: 1, items: 0);
        $source->streams[0] = new Stream('s1', 'Stream 1', ['fld' => 'text...........1'], '', 'year');
        $source->jobs['s1'][0]['items'] = [['key' => 'k1', 'val' => '1', 'year' => '2019'], ['key' => 'k2', 'val' => '', 'year' => '2023'], ['key' => 'k3', 'val' => '0', 'year' => '2020']];
        $run = self::startRun($source, [], ['filter' => '{index:val}']);

        self::runAll($run->getId());

        $done = TestRun::get($run->getId());
        self::assertSame('done', $done?->state());
        self::assertSame(['read' => 3, 'skipped' => 0, 'filtered' => 2, 'errors' => 0], array_intersect_key((array) $done?->counts(), ['read' => 1, 'skipped' => 1, 'filtered' => 1, 'errors' => 1]));
        self::assertSame(['k1'], TestRun::storedValues('relation........'));
        self::assertSame('2023', StreamState::forDefinition(self::DEF)?->versionOf('s1'), 'left out is no failure: the stream gets its version');
    }

    public function test_filter_budgetSpentAmongLeftOutRows_nextStepGoesOnExactly(): void
    {
        $source  = self::source(streams: 1, jobs: 1, items: 0);
        $charged = 0;
        // reading a row past the fingerprint costs 4 s, once: the next step reads the skipped rows again for free
        $source->jobs['s1'][0]['onGet'] = static function (int $n) use (&$charged): void {
            if (($n > ParamsGrabber::FP_ITEMS) && ($n > $charged)) {
                $charged            = $n;
                TestRun::$microNow += 4.0;
            }
        };
        $source->jobs['s1'][0]['items'] = array_map(static fn(int $n): array => ['key' => "k$n", 'val' => (($n <= 3) || ($n === 40)) ? '1' : ''], range(1, 40));
        $run = self::startRun($source, [], ['filter' => '{index:val}']);

        self::runOne();

        $step = TestRun::get($run->getId());
        $pos  = (array) $step?->position();
        self::assertSame('running', $step?->state(), 'stopped among the rows left out, to go on');
        self::assertGreaterThan(20, $pos['offset']);
        self::assertLessThan(40, $pos['offset']);
        self::assertSame([$pos['offset'], $pos['offset'] - 3, 0], [$step?->counts()['read'], $step?->counts()['filtered'], $step?->counts()['skipped']]);

        self::runAll($run->getId());

        $done = TestRun::get($run->getId());
        self::assertSame(['k1', 'k2', 'k3', 'k40'], TestRun::storedValues('relation........'), 'each kept row once, none lost');
        self::assertSame([40, 36, 0], [$done?->counts()['read'], $done?->counts()['filtered'], $done?->counts()['errors']]);
        self::assertSame([], json_decode((string) $done?->getProperty('warnings'), true) ?? [], 'the filter kept some');
    }

    public function test_filter_keptNoneOfAJobAcrossSteps_notedOnceItEnds(): void
    {
        $source  = self::source(streams: 1, jobs: 2, items: 0);
        $charged = 0;
        // as above: rows past the fingerprint cost 4 s once, so the first job spans steps
        $source->jobs['s1'][0]['onGet'] = static function (int $n) use (&$charged): void {
            if (($n > ParamsGrabber::FP_ITEMS) && ($n > $charged)) {
                $charged            = $n;
                TestRun::$microNow += 4.0;
            }
        };
        $prepared = 0;
        // the second step spends its time going back to where the first stopped, and stops before reading on
        $source->jobs['s1'][0]['onPrepare'] = static function () use (&$prepared): void {
            TestRun::$microNow += (++$prepared === 2) ? 30.0 : 0.0;
        };
        $source->jobs['s1'][0]['items'] = array_map(static fn(int $n): array => ['key' => "k$n", 'val' => ''], range(1, 40));
        $source->jobs['s1'][1]['items'] = [['key' => 'x1', 'val' => ''], ['key' => 'x2', 'val' => '']];
        $run = self::startRun($source, [], ['filter' => '{index:val}']);

        self::runOne();

        $step = TestRun::get($run->getId());
        $pos  = (array) $step?->position();
        self::assertSame(['s1:a', $pos['offset']], [$pos['job'], $pos['fout']], 'every row so far left out');
        self::assertLessThan(40, $pos['offset']);

        self::runOne();

        $step = TestRun::get($run->getId());
        self::assertSame([$pos['offset'], 's1:a'], [$step?->position()['offset'], $step?->position()['job']], 'the job is not done');
        self::assertSame([], json_decode((string) $step?->getProperty('warnings'), true) ?? [], 'not before the job ends');

        self::runAll($run->getId());

        $done = TestRun::get($run->getId());
        self::assertSame('done', $done?->state());
        self::assertSame(42, $done?->counts()['filtered']);
        self::assertSame([Run::FILTER_KEPT_NONE => 2], json_decode((string) $done?->getProperty('warnings'), true), 'the rows left out add up over steps, and the second job counts its own');
    }

    public function test_filter_keptNoneOfAJob_aRemarkNotAnError(): void
    {
        $source = self::source(streams: 1, jobs: 2, items: 2);
        $source->jobs['s1'][1]['items'] = [['key' => 'x1', 'val' => 'v1', 'drop' => '1'], ['key' => 'x2', 'val' => 'v2', 'drop' => '1']];
        $run    = self::startRun($source, [], ['filter' => '{ifset:{index:drop}:0:1}']);

        self::runAll($run->getId());

        $done = TestRun::get($run->getId());
        self::assertSame('done', $done?->state());
        self::assertSame([2, 2, 0], [$done?->counts()['new'], $done?->counts()['filtered'], $done?->counts()['errors']]);
        self::assertSame([Run::FILTER_KEPT_NONE => 1], json_decode((string) $done?->getProperty('warnings'), true));
    }

    // ------------------------------------------------------------ remarks of the source

    public function test_remark_fromJobsAndAGrabber_keptByTheRunAcrossSteps(): void
    {
        $source = self::source(streams: 1, jobs: 2, items: 2, seconds: 10.0);
        $ctx    = null;
        $source->onJobs = static function (Stream $stream, Context $c) use (&$ctx): void {
            $ctx = $c;
            $c->remark('INCOMPLETE_YEAR', "Stream {$stream->key}: 2026 left out, 7 of 12 months");
        };
        $source->jobs['s1'][1]['onGet'] = static function (int $n) use (&$ctx): void {
            $ctx?->remark('DUPLICATE_ICO', "Row $n: <b>IČO</b> 123 twice");
        };
        $run = self::startRun($source);

        self::runOne();

        $step = TestRun::get($run->getId());
        self::assertSame('running', $step?->state());
        self::assertSame([['code' => 'INCOMPLETE_YEAR', 'text' => 'Stream s1: 2026 left out, 7 of 12 months']], $step?->remarks());
        self::assertSame([Run::REMARK => ['INCOMPLETE_YEAR' => 1]], json_decode((string) $step?->getProperty('warnings'), true));

        self::runAll($run->getId());

        $done = TestRun::get($run->getId());
        self::assertSame('done', $done?->state());
        self::assertCount(2, $source->jobCalls, 'jobs() asked again in the second step');
        self::assertSame([
            ['code' => 'INCOMPLETE_YEAR', 'text' => 'Stream s1: 2026 left out, 7 of 12 months'],
            ['code' => 'DUPLICATE_ICO', 'text' => 'Row 1: <b>IČO</b> 123 twice'],
            ['code' => 'DUPLICATE_ICO', 'text' => 'Row 2: <b>IČO</b> 123 twice'],
        ], $done?->remarks(), 'the same remark of the next step is kept once');
        self::assertSame([Run::REMARK => ['INCOMPLETE_YEAR' => 1, 'DUPLICATE_ICO' => 2]], json_decode((string) $done?->getProperty('warnings'), true), 'and counted once');
        self::assertSame($done?->remarks(), json_decode((string) $done?->getProperty('report'), true)['remarks']);
        self::assertSame([0, 4], [$done?->counts()['errors'], $done?->counts()['new']], 'no error, the run goes on');
    }

    public function test_remark_listedUpToTheCapAndCountedOnce(): void
    {
        $source = self::source(streams: 2, jobs: 1, items: 2, seconds: 10.0);
        $source->onJobs = static function (Stream $stream, Context $c): void {
            for ($i = 1; $i <= 15; $i++) {
                $c->remark('DUPLICATE_ICO', "{$stream->key}: IČO $i twice");
            }
        };
        $run = self::startRun($source);

        $steps = self::runAll($run->getId());

        $done = TestRun::get($run->getId());
        self::assertSame('done', $done?->state(), implode(' | ', $steps));
        self::assertGreaterThan(2, count($source->jobCalls), 'a stream asked again in a later step');
        self::assertCount(20, $done?->remarks());
        self::assertSame('s1: IČO 1 twice', $done?->remarks()[0]['text'], 'the first ones');
        self::assertSame([Run::REMARK => ['DUPLICATE_ICO' => 30]], json_decode((string) $done?->getProperty('warnings'), true), 'also those not listed, each once');
    }

    public function test_remark_countedUpToWhatTheRunTellsApart(): void
    {
        $source = self::source(streams: 2, jobs: 1, items: 2, seconds: 10.0);
        $source->onJobs = static function (Stream $stream, Context $c): void {
            for ($i = 1; $i <= 600; $i++) {
                $c->remark('DUPLICATE_ICO', "{$stream->key}: IČO $i twice");
            }
        };
        $run = self::startRun($source);

        self::runAll($run->getId());

        $done = TestRun::get($run->getId());
        self::assertSame([Run::REMARK => ['DUPLICATE_ICO' => Context::REMARKS_COUNTED]], json_decode((string) $done?->getProperty('warnings'), true));
        self::assertLessThan(32 * 1024, strlen((string) $done?->getProperty('remarks')), 'what the run stores stays small');
    }

    public function test_remark_secretMasked(): void
    {
        define('AA_APIKEY_RUNTEST', 'sekret42');
        $source               = self::source(streams: 1, jobs: 1, items: 1);
        $source::$credentials = [['vendor' => 'RUNTEST', 'context' => '']];
        $source->onJobs       = static fn(Stream $stream, Context $c) => $c->remark('BAD_DEFINITION', 'asked with key=sekret42');
        $run                  = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame([['code' => 'BAD_DEFINITION', 'text' => 'asked with key=***']], TestRun::get($run->getId())?->remarks());
    }

    /** a source cannot report a cycle of the run: no SOURCE_CYCLE warning, its remark kept as a bad code */
    public function test_remark_runCodeOfTheSource_noRunWarning(): void
    {
        self::assertContains(Run::SOURCE_CYCLE, Context::RUN_REMARK_CODES);
        $source         = self::source(streams: 1, jobs: 1, items: 1);
        $source->onJobs = static fn(Stream $stream, Context $c) => $c->remark(Run::SOURCE_CYCLE, 'Stream s1: fake');
        $run            = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame([['code' => Context::BAD_REMARK_CODE, 'text' => 'Bad code SOURCE_CYCLE: Stream s1: fake']], TestRun::get($run->getId())?->remarks());
        self::assertSame([Run::REMARK => [Context::BAD_REMARK_CODE => 1]], json_decode((string) TestRun::get($run->getId())?->getProperty('warnings'), true));
    }

    public function test_remark_badCode_keptAndTheRunGoesOn(): void
    {
        $source         = self::source(streams: 1, jobs: 1, items: 1);
        $source->onJobs = static fn(Stream $stream, Context $c) => $c->remark('<b>bad</b>', 'text');
        $run            = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame([['code' => Context::BAD_REMARK_CODE, 'text' => 'Bad code <b>bad</b>: text']], TestRun::get($run->getId())?->remarks());
        self::assertSame([Run::REMARK => [Context::BAD_REMARK_CODE => 1]], json_decode((string) TestRun::get($run->getId())?->getProperty('warnings'), true));
    }

    public function test_remark_storedListReadBackUpToTwenty(): void
    {
        $list = array_map(static fn(int $i): array => ['code' => 'DUPLICATE_ICO', 'text' => "IČO $i"], range(1, 25));
        $run  = self::startRun(self::source(streams: 1, jobs: 1, items: 1), ['remarks' => json_encode(['list' => $list], JSON_THROW_ON_ERROR)]);

        self::assertSame(array_slice($list, 0, 20), TestRun::get($run->getId())?->remarks());
    }

    public function test_remark_storedTextReadBackCleaned(): void
    {
        $run = self::startRun(self::source(streams: 1, jobs: 1, items: 1), ['remarks' => json_encode(['list' => [
            ['code' => 'OK_CODE', 'text' => str_repeat('x', Context::REMARK_CHARS + 9)],
            ['code' => '<script>', 'text' => 'dropped'],
            ['code' => 'ARRAY', 'text' => ['x']],
            'not a remark',
        ]], JSON_THROW_ON_ERROR)]);

        self::assertSame([
            ['code' => 'OK_CODE', 'text' => str_repeat('x', Context::REMARK_CHARS)],
            ['code' => 'ARRAY', 'text' => ''],
        ], TestRun::get($run->getId())?->remarks());
    }

    // ------------------------------------------------------------ probe

    public function test_work_minJobSecondsBeforeProbe(): void
    {
        $source         = self::source(streams: 2, jobs: 1, items: 1, seconds: 15.0, probed: true);
        $source->minJob = 15;
        $run            = self::startRun($source, ['mode' => 'changed']);

        self::runOne();

        self::assertSame(['s1'], array_column($source->probeCalls, 'stream'), '12 s left: the next stream is not probed');
        self::assertSame(['i' => 1, 'job' => ''], array_intersect_key((array) TestRun::get($run->getId())?->position(), ['i' => 0, 'job' => 0]));
    }

    public function test_work_probeOnlyAtStreamStart(): void
    {
        $source = self::source(streams: 1, jobs: 2, items: 1, probed: true);
        $run    = self::startRun($source, ['mode' => 'changed', 'position' => ['pages' => 2] + self::onJob('s1:b')]);

        self::runOne();

        self::assertSame([], $source->probeCalls);
        self::assertSame(['k-s1:b-1'], TestRun::storedValues('relation........'));
    }

    // ------------------------------------------------------------ what a step refuses (more)

    public function test_work_directFillWithoutParametersRefused(): void
    {
        $source                          = self::source(streams: 1, jobs: 1, items: 1);
        $source->streams[0]              = new Stream('s1', 'Stream 1', []);
        $source->jobs['s1'][0]['direct'] = true;
        $run                             = self::startRun($source, [], ['mapping' => self::NO_PARAM_MAPPING]);

        self::runAll($run->getId());

        self::assertSame(['error', 'DEFINITION'], self::errorOf($run->getId()));
        self::assertSame([], TestRun::$savers);
    }

    public function test_work_grabberWithLoadFrequencyRefused(): void
    {
        $source                        = self::source(streams: 1, jobs: 1, items: 1);
        $source->jobs['s1'][0]['wait'] = 500.0;
        $run                           = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['error', 'DEFINITION'], self::errorOf($run->getId()));
        self::assertSame([], TestRun::$savers, 'it would sleep past the budget');
    }

    public function test_work_parameterOnAFixedTarget_isDefinition(): void
    {
        $source             = self::source(streams: 1, jobs: 1, items: 1);
        $source->streams[0] = new Stream('s1', 'Stream 1', ['fld' => 'relation........']);
        $run                = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['error', 'DEFINITION'], self::errorOf($run->getId()));
    }

    public function test_work_unknownModeIsDefinition(): void
    {
        $run = self::startRun(self::source(streams: 1, jobs: 1, items: 1), ['mode' => 'every']);

        self::runAll($run->getId());

        self::assertSame(['error', 'DEFINITION'], self::errorOf($run->getId()));
        self::assertSame('run', self::stored($run->getId())['error_path']);
    }

    public function test_work_unexpectedFailureIsLoggedByClassOnly(): void
    {
        $source                         = self::source(streams: 1, jobs: 1, items: 1);
        $source->jobs['s1'][0]['error'] = new \RuntimeException('token=abc123 failed');
        $run                            = self::startRun($source);

        self::runAll($run->getId());

        $log = array_values(array_filter(TableDb::rows('log'), static fn(array $r): bool => $r['type'] === Toexecute::FAILED_EVENT));
        self::assertCount(1, $log);
        self::assertSame('Import_' . self::DEF, $log[0]['selector']);
        self::assertStringContainsString('RuntimeException', (string) $log[0]['params']);
    }

    // ------------------------------------------------------------ validation, item errors, secrets

    public function test_work_validationRuns(): void
    {
        $source = self::source(streams: 1, jobs: 1, items: 2);
        $run    = self::startRun($source, [], ['validate' => '["no"]']);

        self::runAll($run->getId());

        self::assertSame([], TestRun::stored());
        self::assertSame(2, TestRun::get($run->getId())?->counts()['errors']);
    }

    public function test_work_validationSeesTheJobParameters(): void
    {
        $source             = self::source(streams: 1, jobs: 1, items: 2);
        $source->streams[0] = new Stream('s1', 'Stream 1', ['fld' => 'text...........1', 'ok' => 'no']);
        $run                = self::startRun($source, [], ['validate' => '["{index:ok}"]']);

        self::runAll($run->getId());

        self::assertSame([], TestRun::stored(), '"no" fails the validation; without the parameter it would pass empty');
    }

    public function test_work_itemErrorsCappedPerStep(): void
    {
        $source = self::source(streams: 1, jobs: 2, items: 15);
        $source->minJob = 1;
        $run = self::startRun($source, [], ['validate' => '["no"]']);

        self::runAll($run->getId());

        self::assertCount(20, TestRun::$committed[0]->extra['errors']);
        self::assertSame(30, TestRun::get($run->getId())?->counts()['errors']);
        self::assertSame([], self::$invalidated, 'nothing stored, no page to invalidate');
    }

    public function test_work_secretsMaskedInErrors(): void
    {
        define('AA_APIKEY_RUNTEST', 'sekret42');
        $source              = self::source(streams: 1, jobs: 1, items: 0);
        $source::$credentials = [['vendor' => 'RUNTEST', 'context' => '']];
        $source->jobs['s1']  = [['key' => 's1:sekret42', 'items' => [['key' => 'k-sekret42', 'val' => 'v']]]];
        $run                 = self::startRun($source, [], ['validate' => '["no"]']);

        self::runAll($run->getId());

        $errors = TestRun::$committed[0]->extra['errors'];
        self::assertSame('s1:***', $errors[0]['job']);
        self::assertStringContainsString('k-***', $errors[0]['msg']);
        self::assertStringNotContainsString('sekret42', json_encode($errors, JSON_THROW_ON_ERROR));
    }

    public function test_work_secretMaskedInTheErrorPlace(): void
    {
        define('AA_APIKEY_RUNTEST', 'sekret42');
        $source              = self::source(streams: 1, jobs: 1, items: 1);
        $source::$credentials = [['vendor' => 'RUNTEST', 'context' => '']];
        $source->jobs['s1'][0]['key'] = 's1:sekret42';
        $source->jobs['s1'][0]['via'] = false;
        $run                          = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['error', 'DEFINITION'], self::errorOf($run->getId()));
        self::assertStringNotContainsString('sekret42', self::stored($run->getId())['error_path']);
        self::assertStringStartsWith('s1:', self::stored($run->getId())['error_path'], 'the stream key is not repeated');
    }

    // ------------------------------------------------------------ page cache at the end and after a dead step

    public function test_work_pagecacheAfterADeadStep(): void
    {
        $run = self::startRun(self::source(streams: 1, jobs: 1, items: 0));
        self::storedSet($run->getId(), 'lease', (string) (self::NOW - 1));
        self::storedSet($run->getId(), 'lease_tok', 'dead-step');

        self::runOne();

        self::assertSame([[[self::SLICE], 'M']], self::$invalidated, 'the dead step may have stored items');
    }

    /** Continue tried the dead step at once: the step after it still cleans up after the dead one */
    public function test_work_pagecacheAfterADeadStepContinued(): void
    {
        $run = self::startRun(self::source(streams: 1, jobs: 1, items: 0));
        self::storedSet($run->getId(), 'lease', (string) (self::NOW - TestRun::DEAD_GRACE));
        self::storedSet($run->getId(), 'lease_tok', 'dead-step');
        $uid = (string) $GLOBALS['permission_uid'];
        DB_AA::transaction(static fn() => TestRun::lockForUpdate($run->getId())?->resume($uid));

        self::runOne();

        self::assertSame([[[self::SLICE], 'M']], self::$invalidated, 'the dead step may have stored items');
    }

    public function test_work_pagecacheNotAgainWhenDone(): void
    {
        $run = self::startRun(self::source(streams: 1, jobs: 1, items: 1));

        self::runAll($run->getId());

        self::assertSame('done', TestRun::get($run->getId())?->state());
        self::assertCount(1, self::$invalidated, 'the step that stored did it');
    }

    public function test_work_pagecacheWhenEnded(): void
    {
        $run = self::startRun(self::source(streams: 1, jobs: 1, items: 1));
        $uid = (string) $GLOBALS['permission_uid'];
        DB_AA::transaction(static fn() => TestRun::lockForUpdate($run->getId())?->end($uid));
        TableDb::$tables['toexecute'] = array_values(array_filter(self::queue(), static fn(array $r): bool => str_starts_with((string) $r['selector'], 'BatchClose_')));

        self::runOne();

        self::assertSame('ended', TestRun::get($run->getId())?->state());
        self::assertSame([[[self::SLICE], 'M']], self::$invalidated);
    }

    public function test_mayImportInto_onlyALongId(): void
    {
        $uid = (string) $GLOBALS['permission_uid'];
        $GLOBALS['permission_to']['slice']['not-an-id'] = PS_FEEDING;

        self::assertTrue(Definition::mayImportInto($uid, self::SLICE));
        self::assertFalse(Definition::mayImportInto($uid, 'not-an-id'));
        self::assertFalse(Definition::mayImportInto($uid, self::OTHER), 'no feeding there');
    }
}
