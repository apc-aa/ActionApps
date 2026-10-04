<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\Definition;
use AA\Import\Probe;
use AA\Import\Run;
use AA\Import\Stream;
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
        self::assertSame([12, 12, 0, 0, 0, 6, 3, 0, 0], array_values($counts));
        self::assertSame(['read', 'new', 'updated', 'skipped', 'errors', 'jobs', 'streams', 'unchanged', 'skipped_jobs'], array_keys($counts));
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
        self::assertSame([], $source->probeCalls, 'run imports every stream');
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
        // the fifth item has no key
        $source->jobs['s1'][0]['items'][4] = ['val' => 'no key'];
        $run = self::startRun($source);

        self::runAll($run->getId());

        $errors = array_merge(...array_map(static fn($r): array => $r->extra['errors'] ?? [], TestRun::$committed));
        self::assertCount(1, $errors);
        self::assertSame(['s1', 's1:a', 'EMPTY_KEY'], [$errors[0]['stream'], $errors[0]['job'], $errors[0]['code']]);
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
        $from           = ['job' => 's1:a', 'pages' => Run::MAX_PAGES - 1, 'cur' => sha1('s1:a')] + Run::START;
        $run            = self::startRun($source, ['position' => $from]);

        self::runOne();

        $position = TestRun::get($run->getId())?->position();
        self::assertSame(['s1:b', Run::MAX_PAGES], [$position['job'], $position['pages']], 'the 1000th job may start, the 1001st not');
        self::assertSame('SOURCE_CYCLE', TestRun::$committed[0]->retry);
        self::assertSame(2, TestRun::get($run->getId())?->counts()['read'], 'what got stored before counts');

        self::runOne();
        self::assertSame('1', self::stored($run->getId())['failures'], 'from the cap on, no progress');
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
        $run = self::startRun(self::source(streams: 1, jobs: 1, items: 1), ['mode' => 'check']);

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
        foreach ([0, 1] as $j) {
            $source->jobs['s1'][$j]['items'] = array_fill(0, 15, ['val' => 'no key']);
        }
        $run = self::startRun($source);

        self::runAll($run->getId());

        self::assertCount(20, TestRun::$committed[0]->extra['errors']);
        self::assertSame(30, TestRun::get($run->getId())?->counts()['errors']);
        self::assertSame([], self::$invalidated, 'nothing stored, no page to invalidate');
    }

    public function test_work_secretsMaskedInErrors(): void
    {
        define('AA_APIKEY_RUNTEST', 'sekret42');
        $source              = self::source(streams: 1, jobs: 1, items: 0);
        $source->credentials = [['vendor' => 'RUNTEST', 'context' => '']];
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
        $source->credentials = [['vendor' => 'RUNTEST', 'context' => '']];
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
