<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\Catalog;
use AA\Import\CatalogEntry;
use AA\Import\CatalogPage;
use AA\Import\CheckResult;
use AA\Import\Probe;
use AA\Import\RunCursor;
use AA\Import\Stream;
use AA\Import\StreamState;
use AA\IO\DB\DB_AA;
use AA\IO\SourceUnavailable;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Import\Fixtures\CatalogSource;
use Tests\Unit\Import\Fixtures\CheckedSource;
use Tests\Unit\Import\Fixtures\RunSteps;
use Tests\Unit\Import\Fixtures\ScriptedSource;
use Tests\Unit\Import\Fixtures\TestRun;

require_once __DIR__ . '/fixtures/RunSteps.php';

/**
 * What a run of Run leaves besides the items: the check mode (check(), probe(), the catalog), the
 * versions of the streams, the expression after a stream, and what the end of a step writes - the
 * stream state, the catalog, the item errors and the samples of a dry run.
 *
 * Each test in its own process: FakeSlice loads include/locsess.php, whose getDB() collides with
 * the stub tests/Unit/IO/DB loads when that directory runs first.
 */
#[RunTestsInSeparateProcesses]
final class RunCheckTest extends TestCase
{
    use RunSteps;

    // ------------------------------------------------------------ check mode

    public function test_check_runsCheckProbeCatalogWithCursor(): void
    {
        $source               = self::cataloged(streams: 3);
        $source->callSeconds  = 10.0;
        $source->probes['s2'] = new Probe(Probe::UNCHANGED, 'v-s2');
        $source->pages        = [
            ''   => new CatalogPage([new CatalogEntry('e1', 'Entry 1')], 'c2'),
            'c2' => new CatalogPage([new CatalogEntry('e2', 'Entry 2')], 'c3'),
            'c3' => new CatalogPage([new CatalogEntry('e3', 'Entry 3')]),
        ];
        self::streamVersions(['s2' => 'v-s2']);
        $run = self::startRun($source, ['mode' => 'check']);

        self::runOne();
        self::assertSame(['check', 'probe:s1', 'probe:s2'], $source->calls, '7 s left - no third probe');
        self::assertSame(['s1' => null, 's2' => 'v-s2'], $source->known, 'probe() knows what was imported');
        self::runOne();
        self::assertSame(['probe:s3', 'catalog:'], array_slice($source->calls, 3), 'check() not again');
        self::assertNull(Catalog::forDefinition(self::DEF)?->filledAt(), 'a page stored, the catalog still filling');
        self::assertSame('c2', TestRun::get($run->getId())?->position()['cat']);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['catalog:c2', 'catalog:c3'], array_slice($source->calls, 5));
        $catalog = Catalog::forDefinition(self::DEF);
        self::assertSame(['e1', 'e2', 'e3'], array_map(static fn(CatalogEntry $e): string => $e->key, $catalog?->entries() ?? []), 'the pages of three steps');
        self::assertNotNull($catalog?->filledAt());
        $state = StreamState::forDefinition(self::DEF);
        self::assertTrue($state?->lastCheck()['ok']);
        self::assertSame(['s2', 's1', 's3'], array_keys($state->streams()), 'probed');
        self::assertNull($state->versionOf('s1'), 'a check imports nothing - no version');
        $report = json_decode(self::stored($run->getId())['report'], true);
        self::assertTrue($report['check']['ok']);
        self::assertSame(['probed' => 3, 'unchanged' => 1], $report['probe']);
    }

    public function test_check_dryIsRefused(): void
    {
        $source = self::cataloged(streams: 1);
        $run    = self::startRun($source, ['mode' => 'check', 'dry' => '1']);

        self::runAll($run->getId());

        self::assertSame(['error', 'DEFINITION'], self::errorOf($run->getId()), 'a check is for what it records');
        self::assertSame([], $source->calls);
        self::assertNull(StreamState::forDefinition(self::DEF));
    }

    public function test_check_reportShowsItsOwnCheck(): void
    {
        self::storedCheck(new CheckResult(false, 'AUTH', 'an old failure'));
        $source        = self::cataloged(streams: 1);
        $source->check = new CheckResult(true, '', 'fine now');
        $run           = self::startRun($source, ['mode' => 'check']);

        self::runAll($run->getId());

        self::assertSame(['ok' => true, 'code' => '', 'msg' => 'fine now'], json_decode(self::stored($run->getId())['report'], true)['check']);
    }

    public function test_check_writesNothingToSlice(): void
    {
        $source        = self::cataloged(streams: 2);
        $source->pages = ['' => new CatalogPage([new CatalogEntry('e1', 'Entry 1')])];
        $run           = self::startRun($source, ['mode' => 'check']);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame([], TestRun::$savers);
        self::assertSame([], $source->jobCalls);
        self::assertSame([], self::$invalidated);
    }

    public function test_check_catalogCursorCycle_errorSourceCycle(): void
    {
        $source        = self::cataloged(streams: 1);
        $source->pages = [
            ''  => new CatalogPage([new CatalogEntry('e1', 'Entry 1')], 'a'),
            'a' => new CatalogPage([new CatalogEntry('e2', 'Entry 2')], 'a'),
        ];
        $run = self::startRun($source, ['mode' => 'check']);

        $steps = self::runAll($run->getId());

        self::assertSame(['error', 'SOURCE_CYCLE'], self::errorOf($run->getId()));
        self::assertSame(['check', 'probe:s1', 'catalog:', 'catalog:a'], $source->calls, 'the repeated cursor, not MAX_PAGES');
        self::assertCount(2, $steps);
        self::assertSame(['e1', 'e2'], array_map(static fn(CatalogEntry $e): string => $e->key, Catalog::forDefinition(self::DEF)?->entries() ?? []), 'the pages before the cycle are kept');
    }

    public function test_check_catalogTooBig_errors(): void
    {
        $source        = self::cataloged(streams: 1);
        $source->pages = ['' => new CatalogPage(array_map(static fn(int $n): CatalogEntry => new CatalogEntry("e$n", ''), range(0, Catalog::MAX_ENTRIES)))];
        $run           = self::startRun($source, ['mode' => 'check']);

        self::runOne();
        self::assertSame('running', TestRun::get($run->getId())?->state(), 'the step first stores what it found');
        $state = StreamState::forDefinition(self::DEF);
        self::assertTrue($state?->lastCheck()['ok']);
        self::assertSame(['s1'], array_keys($state->streams()), 'probed');
        self::runOne();

        self::assertSame(['error', 'CATALOG_TOO_BIG'], self::errorOf($run->getId()), 'in the next step');
        self::assertSame(['check', 'probe:s1', 'catalog:'], $source->calls);
    }

    public function test_check_keyNotInMessage(): void
    {
        define('AA_APIKEY_RUNCHECK', 'sekret77');
        $source              = self::cataloged(streams: 1);
        $source->credentials = [['vendor' => 'RUNCHECK', 'context' => '']];
        $source->check       = new CheckResult(true, '', 'answered to /api?key=sekret77');
        $source->pages       = ['' => new CatalogPage([])];
        $run                 = self::startRun($source, ['mode' => 'check']);

        self::runAll($run->getId());

        $check = StreamState::forDefinition(self::DEF)?->lastCheck();
        self::assertSame('answered to /api?key=***', $check['msg'] ?? null);
        self::assertStringNotContainsString('sekret77', (string) self::stored($run->getId())['report']);
    }

    public function test_check_failedCheck_recordedAndErrors(): void
    {
        $source        = self::cataloged(streams: 1);
        $source->check = new CheckResult(false, 'AUTH', '401 from the source');
        $run           = self::startRun($source, ['mode' => 'check']);

        $steps = self::runAll($run->getId());

        self::assertSame(['error', 'AUTH'], self::errorOf($run->getId()));
        self::assertSame(['step 1', 'error AUTH'], $steps, 'stored in one step, the error in the next - at once');
        self::assertSame(['time' => self::NOW, 'ok' => false, 'code' => 'AUTH', 'msg' => '401 from the source'], StreamState::forDefinition(self::DEF)?->lastCheck());
        self::assertSame(['check'], $source->calls);
    }

    // ------------------------------------------------------------ versions of the streams

    public function test_version_fromVersionFieldMaxAcrossSteps(): void
    {
        $source             = self::source(streams: 1, jobs: 2, items: 0, seconds: 5.0);
        $source->minJob     = 25;
        $source->streams[0] = new Stream('s1', 'Stream 1', ['fld' => 'text...........1'], '', 'year');
        $source->jobs['s1'][0]['items'] = [['key' => 'k1', 'val' => '1', 'year' => '2019'], ['key' => 'k2', 'val' => '2', 'year' => '2021']];
        $source->jobs['s1'][1]['items'] = [['key' => 'k3', 'val' => '3', 'year' => '2020']];
        $run = self::startRun($source);

        self::runOne();
        self::assertNull(StreamState::forDefinition(self::DEF), 'the stream is not done yet');
        self::runAll($run->getId());

        $state = StreamState::forDefinition(self::DEF);
        self::assertSame('2021', $state?->versionOf('s1'));
        self::assertEquals(['v' => '2021', 'imported' => self::NOW, 'run' => $run->getId()], $state->streams()['s1']);
    }

    public function test_version_fromProbeInRunModeAtStreamEnd(): void
    {
        $source               = self::cataloged(streams: 1, jobs: 1, items: 1);
        $source->probes['s1'] = new Probe(Probe::CHANGED, 'v-7');
        $run                  = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['probe:s1'], $source->calls, 'asked once, after its jobs');
        self::assertEquals(['v' => 'v-7', 'imported' => self::NOW, 'run' => $run->getId(), 'probed' => self::NOW], StreamState::forDefinition(self::DEF)?->streams()['s1']);
    }

    public function test_version_changedModeKeepsTheProbeOfItsStart(): void
    {
        $source               = self::cataloged(streams: 1, jobs: 2, items: 1, seconds: 5.0);
        $source->minJob       = 25;
        $source->probes['s1'] = new Probe(Probe::CHANGED, 'v-start');
        $run                  = self::startRun($source, ['mode' => 'changed']);

        self::runOne();
        $source->probes['s1'] = new Probe(Probe::CHANGED, 'v-later');
        self::runAll($run->getId());

        self::assertSame(['probe:s1'], $source->calls);
        self::assertSame('v-start', StreamState::forDefinition(self::DEF)?->versionOf('s1'), 'the version the import started from');
        self::assertSame(self::NOW, StreamState::forDefinition(self::DEF)?->streams()['s1']['probed']);
    }

    public function test_version_notWrittenWhenStreamHadFailedJob(): void
    {
        $source = self::cataloged(streams: 2, jobs: 1, items: 1);
        $run    = self::startRun($source, ['position' => ['failed' => 1] + self::onJob('s1:a')]);

        self::runAll($run->getId());

        $state = StreamState::forDefinition(self::DEF);
        self::assertNull($state?->versionOf('s1'), 'the next changed run imports it again');
        self::assertSame('v-s2', $state?->versionOf('s2'));
    }

    public function test_version_notWrittenWhenItemsHadErrors(): void
    {
        $source             = self::cataloged(streams: 2, jobs: 1, items: 1);
        $source->streams[0] = new Stream('s1', 'Stream 1', ['fld' => 'text...........1', 'ok' => 'no']);
        $run                = self::startRun($source, [], ['validate' => '["{index:ok}"]']);

        self::runAll($run->getId());

        self::assertSame(1, TestRun::get($run->getId())?->counts()['errors']);
        $state = StreamState::forDefinition(self::DEF);
        self::assertNull($state?->versionOf('s1'), 'its rows failed - changed must not skip them');
        self::assertSame('v-s2', $state?->versionOf('s2'));
    }

    public function test_version_notWrittenInDryRun(): void
    {
        $source = self::cataloged(streams: 1, jobs: 1, items: 1);
        $run    = self::startRun($source, ['dry' => '1']);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertNull(StreamState::forDefinition(self::DEF));
        self::assertSame([], $source->calls, 'no version to ask for');
    }

    // ------------------------------------------------------------ the expression after a stream

    public function test_afterStream_onlyWhenWroteAndNotDry(): void
    {
        $source                         = self::source(streams: 2, jobs: 1, items: 1);
        $source->streams[1]             = new Stream('s2', 'Stream 2', ['fld' => 'text...........1', 'region' => 'CZ010']);
        $source->jobs['s1'][0]['items'] = [];
        TestRun::$evaluate              = static function (): void {};
        $run                            = self::startRun($source, [], ['after_stream' => '{recompute:{index:region}}']);

        self::runAll($run->getId());

        self::assertSame([['{recompute:{index:region}}', ['fld' => ['text...........1'], 'region' => ['CZ010']], self::SLICE]], TestRun::$evaluated, 'not after s1, which stored nothing');

        TestRun::$evaluated = [];
        $dry                = self::startDry($source, ['after_stream' => '{recompute:{index:region}}']);
        self::runAll($dry->getId());
        self::assertSame([], TestRun::$evaluated, 'a dry run');
    }

    public function test_afterStream_exceptionToErrors(): void
    {
        $source            = self::source(streams: 2, jobs: 1, items: 1);
        TestRun::$evaluate = static function (string $expression): void {
            throw new \RuntimeException('text with sekret');
        };
        $run = self::startRun($source, [], ['after_stream' => '{x}']);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()), 'the stream is done all the same');
        $errors = json_decode(self::stored($run->getId())['errors'], true);
        self::assertSame([['stream' => 's1', 'job' => '', 'code' => 'AFTER_STREAM', 'msg' => ''], ['stream' => 's2', 'job' => '', 'code' => 'AFTER_STREAM', 'msg' => '']], $errors);
        self::assertSame(2, TestRun::get($run->getId())?->counts()['errors']);
        self::assertSame(2, TestRun::get($run->getId())?->counts()['streams']);
    }

    public function test_afterStream_timeCountsToBudget(): void
    {
        $source            = self::source(streams: 2, jobs: 1, items: 1);
        TestRun::$evaluate = static function (): void {
            TestRun::$microNow = (TestRun::$microNow ?? 0.0) + 20.0;
        };
        $run = self::startRun($source, [], ['after_stream' => '{x}']);

        self::runOne();

        self::assertSame(['k-s1:a-1'], TestRun::storedValues('relation........'), 'the next job does not start in the 7 s left');
        self::assertSame(1, TestRun::get($run->getId())?->position()['i']);
    }

    public function test_afterStream_streamClosedByTheRetriesHook(): void
    {
        $checked                         = new CheckedSource();
        $checked->minJob                 = 1;
        $source                          = self::source(streams: 2, jobs: 2, items: 1, source: $checked);
        $source->jobs['s1'][1]['error']  = new SourceUnavailable('TRANSPORT', 'No answer');
        $source->jobs['s1'][1]['onPrepare'] = static function (): void {
            \AA::$slice_id = self::OTHER;   // as a request may leave it
        };
        TestRun::$evaluate               = static function (): void {};
        $run                             = self::startRun($source, [], ['after_stream' => '{x}']);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertCount(2, TestRun::$evaluated, 's1 stored before its last job failed - closed, it gets its expression too');
        self::assertSame(self::SLICE, TestRun::$evaluated[0][2], 'in the target slice');
        self::assertSame(2, TestRun::get($run->getId())?->counts()['streams']);
        self::assertNull(StreamState::forDefinition(self::DEF)?->versionOf('s1'));
    }

    // ------------------------------------------------------------ the end of a step

    public function test_commitStep_ignoredWhenRunEnded(): void
    {
        $source                             = self::cataloged(streams: 2, jobs: 1, items: 1);
        $id                                 = '';
        $source->jobs['s2'][0]['onPrepare'] = static function () use (&$id): void {
            $uid = (string) $GLOBALS['permission_uid'];
            DB_AA::transaction(static fn() => TestRun::lockForUpdate($id)?->end($uid));
        };
        $run = self::startRun($source);
        $id  = $run->getId();

        self::runOne();

        self::assertSame('ended', TestRun::get($run->getId())?->state());
        self::assertNull(StreamState::forDefinition(self::DEF), 's1 was done in the step - its version is not written');
    }

    public function test_pagecache_invalidatedOncePerStep(): void
    {
        $source         = self::source(streams: 3, jobs: 1, items: 1, seconds: 8.0);
        $source->minJob = 20;
        $source->jobs['s1'][0]['items'] = [];
        $run            = self::startRun($source);

        self::runOne();
        self::assertSame([[[self::SLICE], 'M']], self::$invalidated, 's1 and s2 in one step - one invalidation');

        self::runAll($run->getId());
        self::assertSame([[[self::SLICE], 'M'], [[self::SLICE], 'M']], self::$invalidated, 'the step of s3 - and none for the final phase');
        self::assertSame(['done', ''], self::errorOf($run->getId()));
    }

    public function test_dryRun_samplesInReport(): void
    {
        $source         = self::source(streams: 2, jobs: 1, items: 4, seconds: 5.0);
        $source->minJob = 20;
        $run            = self::startRun($source, ['dry' => '1']);

        self::runOne();
        $samples = json_decode(self::stored($run->getId())['samples'], true);
        self::assertSame(['k-s1:a-1', 'k-s1:a-2', 'k-s1:a-3', 'k-s1:a-4'], array_map(static fn(array $s): string => $s['relation........'][0], $samples), 'the run keeps them between steps');
        self::runAll($run->getId());

        $report = json_decode(self::stored($run->getId())['report'], true);
        self::assertCount(5, $report['samples']);
        self::assertSame(['k-s1:a-1'], $report['samples'][0]['relation........']);
        self::assertSame(['k-s2:a-1'], $report['samples'][4]['relation........'], 'the second step adds to the first');
    }

    public function test_errors_keptInTheRunAcrossSteps(): void
    {
        $source = self::source(streams: 2, jobs: 1, items: 15, seconds: 1.0);
        $source->minJob = 20;
        $run    = self::startRun($source, [], ['validate' => '["no"]']);

        self::runAll($run->getId());

        $errors = json_decode(self::stored($run->getId())['errors'], true);
        self::assertCount(20, $errors);
        self::assertSame('s2', $errors[19]['stream'], 'the second step adds to the first');
        self::assertSame(30, TestRun::get($run->getId())?->counts()['errors']);
    }

    public function test_errors_redactedBeforeTheKeyIsCut(): void
    {
        define('AA_APIKEY_RUNCUT', 'sekret4242424242');
        $source              = self::source(streams: 1, jobs: 1, items: 0);
        $source->credentials = [['vendor' => 'RUNCUT', 'context' => '']];
        // 47 characters: cut at 40, the key would leave "sekret424" that redact() no longer knows
        $source->jobs['s1']  = [['key' => 's1:a', 'items' => [['key' => 'https://example.cz/data?apikey=sekret4242424242', 'val' => 'v']]]];
        $run                 = self::startRun($source, [], ['validate' => '["no"]']);

        self::runAll($run->getId());

        $stored = (string) self::stored($run->getId())['errors'];
        self::assertStringContainsString('apikey=***', $stored);
        self::assertStringNotContainsString('sekret4', $stored);
    }

    // ------------------------------------------------------------ after the review

    public function test_version_unknownProbeWithoutNewRows_keepsTheStoredOne(): void
    {
        $source             = self::cataloged(streams: 1, jobs: 1, items: 0);
        $source->streams[0] = new Stream('s1', 'Stream 1', ['fld' => 'text...........1'], '', 'year');
        $source->probes['s1'] = new Probe(Probe::UNKNOWN);
        self::streamVersions(['s1' => '2021']);
        $run = self::startRun($source, ['mode' => 'changed']);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame('2021', StreamState::forDefinition(self::DEF)?->versionOf('s1'));
    }

    public function test_version_emptyNeverOverwrites(): void
    {
        $source               = self::cataloged(streams: 1, jobs: 1, items: 1);
        $source->probes['s1'] = new Probe(Probe::CHANGED, '');
        self::streamVersions(['s1' => 'v-old']);
        $run = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame('v-old', StreamState::forDefinition(self::DEF)?->versionOf('s1'));
        self::assertSame($run->getId(), StreamState::forDefinition(self::DEF)?->streams()['s1']['run']);
    }

    public function test_probe_onceEvenWhenTheJobsKeepFailing(): void
    {
        $source               = self::cataloged(streams: 1, jobs: 1, items: 1);
        $source->jobs['s1']   = static fn(): array => throw new SourceUnavailable('TRANSPORT', 'No answer');
        $n                    = 0;
        $source->probes['s1'] = static function () use (&$n): Probe {
            return new Probe(Probe::CHANGED, 'v-' . ++$n);   // a new answer would be a new cursor
        };
        $run = self::startRun($source, ['mode' => 'changed']);

        $steps = self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()), 'the hook closed the stream - the run did not go round');
        self::assertSame(['probe:s1', 'check'], $source->calls, 'probed once; then the hook asked check()');
        self::assertSame(['JOB_FAILED' => 1], self::warnings($run->getId()));
        self::assertLessThanOrEqual(5, count($steps));
    }

    public function test_version_fromTheSource_redactedCleanAndCut(): void
    {
        define('AA_APIKEY_RUNVER', 'sekret99');
        $source               = self::cataloged(streams: 1, jobs: 1, items: 1);
        $source->credentials  = [['vendor' => 'RUNVER', 'context' => '']];
        $source->probes['s1'] = new Probe(Probe::CHANGED, "https://x.cz/?k=sekret99&v=\xff" . str_repeat('9', 400));
        $run                  = self::startRun($source);

        self::runAll($run->getId());

        $version = (string) StreamState::forDefinition(self::DEF)?->versionOf('s1');
        self::assertStringStartsWith('https://x.cz/?k=***&v=', $version);
        self::assertTrue(mb_check_encoding($version, 'UTF-8'));
        self::assertLessThanOrEqual(255, strlen($version));
    }

    public function test_version_inTheChangedCursor_redactedCleanAndCut(): void
    {
        define('AA_APIKEY_RUNPV', 'sekret98');
        $source               = self::cataloged(streams: 1, jobs: 2, items: 1, seconds: 5.0);
        $source->minJob       = 25;
        $source->credentials  = [['vendor' => 'RUNPV', 'context' => '']];
        $source->probes['s1'] = new Probe(Probe::CHANGED, "v\xff-sekret98");
        $run                  = self::startRun($source, ['mode' => 'changed']);

        self::runOne();

        $pv = TestRun::get($run->getId())?->position()['pv'];
        self::assertStringNotContainsString('sekret98', (string) $pv);
        self::assertTrue(mb_check_encoding((string) $pv, 'UTF-8'));
        self::runAll($run->getId());
        self::assertSame($pv, StreamState::forDefinition(self::DEF)?->versionOf('s1'), 'the cursor and the state keep the same form');
    }

    public function test_check_catalogCursorOfTheSourceRefused(): void
    {
        define('AA_APIKEY_RUNCUR', 'sekret97');
        $source              = self::cataloged(streams: 1);
        $source->credentials = [['vendor' => 'RUNCUR', 'context' => '']];
        $next                = 'https://x.cz/next?key=sekret97';
        $source->pages       = ['' => static fn(): CatalogPage => new CatalogPage([new CatalogEntry('e1', 'Entry 1')], $next), $next => new CatalogPage([])];
        $run                 = self::startRun($source, ['mode' => 'check']);

        self::runAll($run->getId());

        self::assertSame(['error', 'SOURCE'], self::errorOf($run->getId()));
        self::assertStringNotContainsString('sekret97', json_encode(TestRun::get($run->getId())?->position(), JSON_THROW_ON_ERROR));
    }

    public function test_check_invalidCursorBytesRefused(): void
    {
        $source        = self::cataloged(streams: 1);
        $source->pages = ['' => static fn(): CatalogPage => new CatalogPage([], "p\xff2"), "p\xff2" => new CatalogPage([])];
        $run           = self::startRun($source, ['mode' => 'check']);

        self::runAll($run->getId());

        self::assertSame(['error', 'SOURCE'], self::errorOf($run->getId()));
    }

    public function test_versionProbeFailing_doesNotDownloadTheJobAgain(): void
    {
        $source               = self::cataloged(streams: 2, jobs: 1, items: 1);
        $source->probes['s1'] = new Probe(Probe::CHANGED, 'v');
        $source->failProbe    = ['s1' => new SourceUnavailable('TRANSPORT', 'No answer')];
        $run                  = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertCount(2, $source->grabbers, 'one per job: s1:a was not fetched again');
        self::assertSame(['VERSION_UNKNOWN' => 1], self::warnings($run->getId()), 'no job failed - s1 closed without its version');
        self::assertNull(StreamState::forDefinition(self::DEF)?->versionOf('s1'));
        self::assertSame('v-s2', StreamState::forDefinition(self::DEF)?->versionOf('s2'));
    }

    public function test_versionProbe_keepsToTheBudget(): void
    {
        $source              = self::cataloged(streams: 1, jobs: 1, items: 1, seconds: 20.0);
        $run                 = self::startRun($source);

        self::runOne();

        self::assertSame([], $source->calls, '7 s left - the probe waits for the next step');
        self::assertSame(1, TestRun::get($run->getId())?->position()['end']);
        self::runAll($run->getId());
        self::assertSame(['probe:s1'], $source->calls);
        self::assertCount(1, $source->grabbers);
    }

    public function test_afterStream_notForAStreamClosedWithoutWriting(): void
    {
        $checked                        = new CheckedSource();
        $checked->minJob                = 1;
        $source                         = self::source(streams: 2, jobs: 1, items: 1, source: $checked);
        $source->jobs['s1'][0]['error'] = new SourceUnavailable('TRANSPORT', 'No answer');
        TestRun::$evaluate              = static function (): void {};
        $run                            = self::startRun($source, [], ['after_stream' => '{x}']);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertCount(1, TestRun::$evaluated, 'only s2');
    }

    public function test_afterStream_failedStreamThatWroteGetsIt(): void
    {
        $source            = self::source(streams: 1, jobs: 1, items: 1);
        TestRun::$evaluate = static function (): void {};
        $run               = self::startRun($source, ['position' => ['failed' => 1] + self::onJob('s1:a')], ['after_stream' => '{x}']);

        self::runAll($run->getId());

        self::assertCount(1, TestRun::$evaluated);
        self::assertNull(StreamState::forDefinition(self::DEF)?->versionOf('s1'));
    }

    public function test_afterStream_restoresTheSliceOfTheRequest(): void
    {
        $source            = self::source(streams: 1, jobs: 1, items: 1);
        TestRun::$evaluate = static function (): void {};
        $run               = self::startRun($source, [], ['after_stream' => '{x}']);
        \AA::$slice_id     = 'outer';

        self::runAll($run->getId());

        self::assertSame(self::SLICE, TestRun::$evaluated[0][2]);
        self::assertSame('outer', \AA::$slice_id);
    }

    public function test_afterStream_ofTheLastStreamRunsAtTheEnd(): void
    {
        $checked                        = new CheckedSource();
        $checked->minJob                = 1;
        $source                         = self::source(streams: 1, jobs: 2, items: 1, source: $checked);
        $source->jobs['s1'][1]['error'] = new SourceUnavailable('TRANSPORT', 'No answer');
        TestRun::$evaluate              = static function (): void {};
        $run                            = self::startRun($source, [], ['after_stream' => '{x}']);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertCount(1, TestRun::$evaluated, 'the hook closed the last stream - the step after it runs the expression');
    }

    public function test_checkResult_belongsToItsStep(): void
    {
        $source = self::cataloged(streams: 1);
        $run    = TestRun::get(self::startRun($source, ['mode' => 'check'])->getId());

        $first  = $run?->workOnce(RunCursor::START);
        $second = $run?->workOnce(['checked' => 1] + RunCursor::START);

        self::assertInstanceOf(CheckResult::class, $first?->extra['check']);
        self::assertNull($second?->extra['check'], 'check() was not asked in that step');
    }

    public function test_version_changedMode_olderRowsDoNotLowerIt(): void
    {
        $source               = self::cataloged(streams: 1, jobs: 1, items: 0);
        $source->streams[0]   = new Stream('s1', 'Stream 1', ['fld' => 'text...........1'], '', 'year');
        $source->jobs['s1'][0]['items'] = [['key' => 'k1', 'val' => 'v', 'year' => '2019']];
        self::streamVersions(['s1' => '2021']);
        $run = self::startRun($source, ['mode' => 'changed']);

        self::runAll($run->getId());

        self::assertSame('2021', StreamState::forDefinition(self::DEF)?->versionOf('s1'), 'a revised old row');
    }

    public function test_version_ofTheVersionField_cleanInTheCursor(): void
    {
        define('AA_APIKEY_RUNVMAX', 'sekret96');
        $source              = self::cataloged(streams: 1, jobs: 2, items: 0, seconds: 5.0);
        $source->minJob      = 25;
        $source->credentials = [['vendor' => 'RUNVMAX', 'context' => '']];
        $source->streams[0]  = new Stream('s1', 'Stream 1', ['fld' => 'text...........1'], '', 'year');
        $source->jobs['s1'][0]['items'] = [['key' => 'k1', 'val' => 'v', 'year' => "2021-sekret96\xff"]];
        $source->jobs['s1'][1]['items'] = [['key' => 'k2', 'val' => 'v', 'year' => '2020']];
        $run = self::startRun($source);

        self::runOne();

        $vmax = (string) TestRun::get($run->getId())?->position()['vmax'];
        self::assertStringStartsWith('2021-***', $vmax);
        self::assertTrue(mb_check_encoding($vmax, 'UTF-8'));
    }

    public function test_afterStream_dueAtTheStepStart_countsToItsBudget(): void
    {
        $source            = self::source(streams: 2, jobs: 1, items: 1);
        TestRun::$evaluate = static function (): void {
            TestRun::$microNow = (TestRun::$microNow ?? 0.0) + 20.0;
        };
        $run = self::startRun($source, ['position' => ['i' => 1, 'after' => 's1'] + RunCursor::START], ['after_stream' => '{x}']);

        self::runOne();

        self::assertCount(1, TestRun::$evaluated, 'the due expression is the first call of the step');
        self::assertSame([], TestRun::stored(), '7 s left: the job of s2 waits');
    }

    public function test_check_takesTimeFromTheProbes(): void
    {
        $source               = self::cataloged(streams: 1);
        $source->checkSeconds = 20.0;
        $run                  = self::startRun($source, ['mode' => 'check']);

        self::runOne();

        self::assertSame(['check'], $source->calls, '7 s left after check()');
        self::runAll($run->getId());
        self::assertSame(['done', ''], self::errorOf($run->getId()));
    }

    public function test_retriesHook_keepsTheExpressionStillDue(): void
    {
        $checked             = new CheckedSource();
        $checked->minJob     = 1;
        $source              = self::source(streams: 3, jobs: 1, items: 1, source: $checked);
        $source->failStreams = 3;   // every step fails, the hook gets the streams
        TestRun::$evaluate   = static function (): void {};
        $run = self::startRun($source, ['position' => ['i' => 1, 'after' => 's1'] + RunCursor::START], ['after_stream' => '{x}']);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['k-s3:a-1'], TestRun::storedValues('relation........'), 's2 skipped');
        self::assertCount(2, TestRun::$evaluated, 'the expression of s1 survives s2 skipped, s3 gets its own');
    }

    public function test_retriesHook_streamsNotListed_failsTheRun(): void
    {
        $checked             = new CheckedSource();
        $checked->minJob     = 1;
        $source              = self::source(streams: 2, jobs: 2, items: 1, source: $checked);
        $source->failStreams = 4;
        $run = self::startRun($source, ['position' => self::onJob('s1:a')]);

        self::runAll($run->getId());

        self::assertSame(['error', 'TRANSPORT'], self::errorOf($run->getId()), 'the whole source is down, not s1');
        self::assertSame([], self::warnings($run->getId()), 'no job closed as failed');
        self::assertSame([], TestRun::stored());
    }

    public function test_afterStream_dueWithinTheStep_waitsForTheBudget(): void
    {
        $source            = self::source(streams: 2, jobs: 1, items: 1, seconds: 15.0);
        TestRun::$evaluate = static function (): void {};
        $run               = self::startRun($source, [], ['after_stream' => '{x}']);

        self::runOne();

        self::assertSame([], TestRun::$evaluated, '12 s left after s1 - its expression waits for the next step');
        self::assertSame('s1', TestRun::get($run->getId())?->position()['after']);
        self::runAll($run->getId());
        self::assertCount(2, TestRun::$evaluated);
    }

    public function test_check_runKeepsTheMessageAsTheStateDoes(): void
    {
        $source        = self::cataloged(streams: 1);
        $source->check = new CheckResult(true, '', "bad\x07\x1b[31m\u{202E}key" . str_repeat('é', 800));
        $run           = self::startRun($source, ['mode' => 'check']);

        self::runAll($run->getId());

        $kept   = StreamState::forDefinition(self::DEF)?->lastCheck();
        $report = json_decode(self::stored($run->getId())['report'], true)['check'];
        self::assertSame(['ok' => true, 'code' => '', 'msg' => $kept['msg'] ?? null], $report);
        self::assertStringStartsWith('bad  [31mkey', $report['msg']);
        self::assertLessThanOrEqual(1000, strlen($report['msg']));
        self::assertTrue(mb_check_encoding($report['msg'], 'UTF-8'));
    }

    public function test_retriesHook_streamGoneMeanwhile(): void
    {
        $checked                        = new CheckedSource();
        $checked->minJob                = 1;
        $source                         = self::source(streams: 2, jobs: 2, items: 1, source: $checked);
        $source->jobs['s1'][1]['error'] = new SourceUnavailable('TRANSPORT', 'No answer');
        $checked->onCheck               = static function (CheckedSource $s): void {
            $s->streams = array_values(array_filter($s->streams, static fn(Stream $st): bool => $st->key !== 's1'));
        };
        TestRun::$evaluate = static function (): void {};
        $run               = self::startRun($source, [], ['after_stream' => '{x}']);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertEquals(['JOB_FAILED' => 1, 'STREAM_GONE' => 1], self::warnings($run->getId()));
        self::assertSame(1, TestRun::get($run->getId())?->counts()['streams'], 'only s2 counts');
        self::assertCount(1, TestRun::$evaluated, 'no expression for a stream gone');
    }

    // ------------------------------------------------------------ helpers

    private static function storedCheck(CheckResult $check): void
    {
        $state = StreamState::create(self::DEF);
        $state->recordCheck($check, self::NOW - 86400);
        $state->store();
    }

    /** @return array<string, int> */
    private static function warnings(string $id): array
    {
        return (array) json_decode(self::stored($id)['warnings'] ?? '[]', true);
    }

    /** source() over a CatalogSource that checks, probes and has an empty catalog */
    private static function cataloged(int $streams, int $jobs = 1, int $items = 0, float $seconds = 0.0): CatalogSource
    {
        $source         = new CatalogSource();
        $source->pages  = ['' => new CatalogPage([])];
        self::source($streams, $jobs, $items, $seconds, source: $source);
        return $source;
    }

    /** a dry run of the same definition, as another start would make it once the first one is done
     *  @param array<string, string> $definition */
    private static function startDry(ScriptedSource $source, array $definition): TestRun
    {
        return self::startRun($source, ['dry' => '1'], $definition);
    }
}
