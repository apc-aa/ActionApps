<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\Catalog;
use AA\Import\CatalogEntry;
use AA\Import\CatalogPage;
use AA\Import\CheckResult;
use AA\Import\Context;
use AA\Import\ParamsGrabber;
use AA\Import\Probe;
use AA\Import\Run;
use AA\Import\RunCursor;
use AA\Import\Stream;
use AA\Import\StreamState;
use AA\IO\DB\DB_AA;
use AA\Http\TransportException;
use AA\IO\SourceRefused;
use AA\IO\SourceUnavailable;
use AA_Content;
use AA_Value;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Import\Fixtures\CatalogSource;
use Tests\Unit\Import\Fixtures\CheckedSource;
use Tests\Unit\Import\Fixtures\FieldMaxSource;
use Tests\Unit\Import\Fixtures\ListedSource;
use Tests\Unit\Import\Fixtures\RunSteps;
use Tests\Unit\Import\Fixtures\ScriptedGrabber;
use Tests\Unit\Import\Fixtures\ScriptedSource;
use Tests\Unit\Import\Fixtures\TestRun;

require_once __DIR__ . '/fixtures/RunSteps.php';
require_once __DIR__ . '/fixtures/FieldMaxSource.php';

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

    /** a stream versioned by its field and with no version is read whole whatever probe() says: not
     *  asked, and it spends nothing of the step - yet checked, so the detail lists it */
    public function test_check_versionFieldStreamWithoutVersion_notProbed(): void
    {
        $source              = self::cataloged(streams: 4);
        $source->checkSeconds = 25.0;
        $source->streams[0]   = new Stream('s1', 'Stream 1', ['fld' => 'text...........1'], '', 'year');
        $source->streams[1]   = new Stream('s2', 'Stream 2', ['fld' => 'text...........1'], '', 'year');
        $source->streams[2]   = new Stream('s3', 'Stream 3', ['fld' => 'text...........1'], '', 'year');
        self::streamVersions(['s2' => '']);
        $run = self::startRun($source, ['mode' => 'check']);

        self::runOne();
        self::assertSame(['check'], $source->calls, 'no time left after check()');
        self::assertSame(3, TestRun::get($run->getId())?->position()['i'], "s1-s3 free all the same, '' known as none");
        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['check', 'probe:s4', 'catalog:'], $source->calls);
        $streams = StreamState::forDefinition(self::DEF)?->streams() ?? [];
        self::assertEqualsCanonicalizing(['s1', 's2', 's3', 's4'], array_keys($streams), 'listed as checked');
        self::assertSame(self::NOW, $streams['s1']['probed']);
        self::assertSame(['probed' => 4, 'unchanged' => 0], json_decode(self::stored($run->getId())['report'], true)['probe']);
    }

    /** the changed mode reads such a stream without a request - one that fails cannot stop the run; a
     *  stream with a version, or without a versionField, is probed as before */
    public function test_changed_versionFieldStreamWithoutVersion_readWithoutProbe(): void
    {
        $source                  = self::cataloged(streams: 3, jobs: 1, items: 1);
        $source->streams[0]      = new Stream('s1', 'Stream 1', ['fld' => 'text...........1'], '', 'year');
        $source->streams[1]      = new Stream('s2', 'Stream 2', ['fld' => 'text...........1'], '', 'year');
        $source->failProbe['s1'] = new SourceUnavailable('TRANSPORT', 'No answer');
        $source->probes['s2']    = new Probe(Probe::UNCHANGED);
        self::streamVersions(['s2' => '2021']);
        $run = self::startRun($source, ['mode' => 'changed']);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['s2' => '2021', 's3' => null], $source->known, 'no probe of s1');
        self::assertSame([['stream' => 's1', 'known' => null], ['stream' => 's3', 'known' => null]], $source->jobCalls);
        self::assertSame(1, TestRun::get($run->getId())?->counts()['unchanged']);
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

    /** the catalog keeps the streams the source had when the check listed it, so the page compares
     *  them without asking the source; the next check lists them anew */
    public function test_check_catalogKeepsTheStreamsItWasListedWith(): void
    {
        $source          = self::cataloged(streams: 0);
        $source->streams = [new Stream('s1', 'Prices', [], 'e1'), new Stream('s2', 'Wages', [], 'e2'), new Stream('s3', 'Own', [])];
        $source->pages   = [
            ''   => new CatalogPage([new CatalogEntry('e1', 'Prices')], 'p2'),
            'p2' => new CatalogPage([new CatalogEntry('e2', 'Wages, renamed')]),
        ];
        $source->callSeconds = 15.0;
        $run                 = self::startRun($source, ['mode' => 'check']);

        $steps = self::runAll($run->getId());

        self::assertGreaterThan(2, count($steps), 'the pages came in more steps');
        self::assertSame(['done', ''], self::errorOf($run->getId()));
        $catalog = Catalog::forDefinition(self::DEF);
        $streams = $catalog?->streams() ?? [];
        self::assertSame([['s1', 'Prices', 'e1'], ['s2', 'Wages', 'e2']], array_map(static fn(Stream $s): array => [$s->key, $s->label, $s->catalogKey], $streams));
        self::assertSame(['new' => [], 'missing' => [], 'renamed' => ['s2']], $catalog?->diff($streams));

        array_shift($source->streams);
        $source->calls = [];
        $again         = self::startRun($source, ['mode' => 'check']);
        self::runAll($again->getId());

        self::assertSame(['s2'], array_map(static fn(Stream $s): string => $s->key, Catalog::forDefinition(self::DEF)?->streams() ?? []));
    }

    /** a source that only lists its catalog has its streams kept with it too */
    public function test_check_catalogOfASourceWithoutProbes(): void
    {
        require_once __DIR__ . '/fixtures/ListedSource.php';
        $source          = new ListedSource();
        $source->streams = [new Stream('s1', 'Prices', [], 'e1')];
        $source->pages   = ['' => new CatalogPage([new CatalogEntry('e1', 'Prices')])];
        TestRun::$source = $source;
        $run             = self::startRun($source, ['mode' => 'check']);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['s1'], array_map(static fn(Stream $s): string => $s->key, Catalog::forDefinition(self::DEF)?->streams() ?? []));
    }

    /** the catalog is shown in the import detail: the source's keys are masked in its texts - in its
     *  keys and the streams' catalog keys alike, so they still match */
    public function test_check_catalogTextsRedacted(): void
    {
        define('AA_APIKEY_RUNCAT', 'sekret88');
        $source               = self::cataloged(streams: 0);
        $source::$credentials = [['vendor' => 'RUNCAT', 'context' => '']];
        $source->streams      = [new Stream('s1', 'Label sekret88', [], 'k-sekret88')];
        $source->pages        = ['' => new CatalogPage([new CatalogEntry('k-sekret88', 'Label sekret88', ['sekret88' => 'u sekret88', 'n' => 88], 'at sekret88')])];
        $run                  = self::startRun($source, ['mode' => 'check']);

        self::runAll($run->getId());

        $catalog = Catalog::forDefinition(self::DEF);
        self::assertEquals([new CatalogEntry('k-***', 'Label ***', ['***' => 'u ***', 'n' => '88'], 'at ***')], $catalog?->entries());
        self::assertSame([['s1', 'Label ***', 'k-***']], array_map(static fn(Stream $s): array => [$s->key, $s->label, $s->catalogKey], $catalog->streams()));
        self::assertSame(['new' => [], 'missing' => [], 'renamed' => []], $catalog->diff($catalog->streams()));
        foreach (TableDb::rows('object_text') as $row) {
            self::assertStringNotContainsString('sekret88', (string) $row['value']);
        }
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
        $source::$credentials = [['vendor' => 'RUNCHECK', 'context' => '']];
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

    // ------------------------------------------------------------ check() at the start of a run

    public function test_run_checkAtStartRefusedErrors(): void
    {
        $source        = self::cataloged(streams: 1, jobs: 1, items: 1);
        $source->check = new CheckResult(false, 'AUTH', '401 from the source');
        $run           = self::startRun($source);

        $steps = self::runAll($run->getId());

        self::assertSame(['error', 'AUTH'], self::errorOf($run->getId()));
        self::assertSame('check', self::stored($run->getId())['error_path']);
        self::assertSame(['step 1', 'error AUTH'], $steps, 'stored in one step, the error in the next - at once');
        self::assertSame(['time' => self::NOW, 'ok' => false, 'code' => 'AUTH', 'msg' => '401 from the source'], StreamState::forDefinition(self::DEF)?->lastCheck());
        self::assertSame(['check'], $source->calls);
        self::assertSame([], $source->jobCalls, 'nothing imported');
    }

    public function test_run_checkAtStartRefusedBySource_errorsWithItsReason(): void
    {
        $source        = self::checkedSource(streams: 1);
        $source->check = new SourceRefused('NO_CREDENTIAL', 'AA_APIKEY_X missing');
        $run           = self::startRun($source);

        $steps = self::runAll($run->getId());

        self::assertSame(['error', 'NO_CREDENTIAL'], self::errorOf($run->getId()));
        self::assertSame(['step 1', 'error NO_CREDENTIAL'], $steps);
        self::assertSame([], $source->jobCalls);
        $failed = ['ok' => false, 'code' => 'NO_CREDENTIAL', 'msg' => ''];
        self::assertSame(['time' => self::NOW] + $failed, StreamState::forDefinition(self::DEF)?->lastCheck(), 'recorded as a failed check()');
        self::assertSame($failed, json_decode(self::stored($run->getId())['check'], true));
    }

    // the refusal ends the run in the next step; its detail goes along, through the cursor
    public function test_run_checkAtStartRefused_keepsItsDetail(): void
    {
        $source        = self::checkedSource(streams: 1);
        $source->check = new SourceRefused('SOURCE', 'HTTP 410 from https://1.1.1.1/x?token=tok3n4242', 'http:410');
        $run           = self::startRun($source);

        self::runAll($run->getId());

        $stored = self::stored($run->getId());
        self::assertSame(['error', 'SOURCE', 'http:410', 'check'], [$stored['state'], $stored['error_code'], $stored['error_detail'], $stored['error_path']]);
        self::assertStringNotContainsString('tok3n4242', json_encode($stored, JSON_THROW_ON_ERROR));
    }

    public function test_run_checkResultFailed_keepsItsDetail(): void
    {
        $source        = self::cataloged(streams: 1, jobs: 1, items: 1);
        $source->check = new CheckResult(false, 'AUTH', '401 from the source', 'http:401');
        $run           = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['AUTH', 'http:401'], [self::stored($run->getId())['error_code'], self::stored($run->getId())['error_detail']]);
    }

    public function test_check_catalogRefused_keepsItsDetail(): void
    {
        $source        = self::cataloged(streams: 1);
        $source->pages = ['' => new SourceRefused('SOURCE', 'HTTP 404 from 1.1.1.1', 'http:404')];
        $run           = self::startRun($source, ['mode' => 'check']);

        self::runAll($run->getId());

        self::assertSame(['error', 'SOURCE'], self::errorOf($run->getId()));
        self::assertSame('http:404', self::stored($run->getId())['error_detail']);
    }

    public function test_check_refusedCheck_recordsTheCodeNotTheText(): void
    {
        $source        = self::checkedSource(streams: 1);
        // a token of the session, not a declared credential: redact() does not know it
        $source->check = new SourceRefused('AUTH', '401 from https://example.cz/api?session=tok3n7171');
        $run           = self::startRun($source, ['mode' => 'check']);

        self::assertSame(['step 1', 'error AUTH'], self::runAll($run->getId()));

        self::assertSame(['time' => self::NOW, 'ok' => false, 'code' => 'AUTH', 'msg' => ''], StreamState::forDefinition(self::DEF)?->lastCheck());
        self::assertStringNotContainsString('tok3n7171', json_encode(self::stored($run->getId()), JSON_THROW_ON_ERROR));
    }

    /** @return array<string, array{string}> */
    public static function checkingModes(): array
    {
        return ['run' => ['run'], 'check' => ['check']];
    }

    #[DataProvider('checkingModes')]
    public function test_resume_afterAFailedCheck_asksItAgain(string $mode): void
    {
        $source        = self::cataloged(streams: 1, jobs: 1, items: 1);
        $source->check = new CheckResult(false, 'AUTH', 'token expired');
        $run           = self::startRun($source, ['mode' => $mode]);
        self::runAll($run->getId());
        self::assertSame(['error', 'AUTH'], self::errorOf($run->getId()));

        $source->check = new CheckResult(true, '', 'a new token');
        DB_AA::transaction(static fn() => TestRun::lockForUpdate($run->getId())?->resume(AuthGetUid()));
        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()), 'Continue after the user fixed the credential');
        self::assertCount(2, array_filter($source->calls, static fn(string $c): bool => $c === 'check'));
        self::assertTrue(StreamState::forDefinition(self::DEF)?->lastCheck()['ok']);
        self::assertSame(($mode === 'run') ? ['k-s1:a-1'] : [], TestRun::storedValues('relation........'));
    }

    public function test_check_passedCheckThenProbeDown_isProgress(): void
    {
        $source                  = self::cataloged(streams: 1);
        $source->failProbe['s1'] = new SourceUnavailable('TRANSPORT', 'No answer');
        $run                     = self::startRun($source, ['mode' => 'check']);

        self::runOne();
        self::assertSame(['0', 1], [self::stored($run->getId())['failures'], TestRun::get($run->getId())?->position()['checked']], 'a check keeps its check()');
        unset($source->failProbe['s1']);
        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['check', 'probe:s1', 'probe:s1', 'catalog:'], $source->calls);
    }

    public function test_run_checkAtStartTakingTheBudget_isProgress(): void
    {
        $source               = self::cataloged(streams: 1, jobs: 1, items: 1);
        $source->checkSeconds = 20.0;
        $run                  = self::startRun($source, ['mode' => 'changed']);

        self::runOne();
        self::assertSame(['check'], $source->calls, '7 s left - the probe waits for the next step');
        self::assertSame(self::sorted(self::pastCheck()), self::sorted(TestRun::get($run->getId())?->position()), 'kept, not asked again');
        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['check', 'probe:s1'], $source->calls);
    }

    public function test_run_remarkOfCheckAtStart_keptByTheRun(): void
    {
        $source          = self::source(streams: 1, jobs: 1, items: 1, source: new CheckedSource());
        $source->check   = new CheckResult(true, '', 'fine');
        $source->onCheck = static fn(CheckedSource $s, Context $ctx) => $ctx->remark('DEFINITION_LEFT_OUT', 'Indicator X: no geo level');
        $run             = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame([['code' => 'DEFINITION_LEFT_OUT', 'text' => 'Indicator X: no geo level']], TestRun::get($run->getId())?->remarks());
        self::assertSame([Run::REMARK => ['DEFINITION_LEFT_OUT' => 1]], json_decode(self::stored($run->getId())['warnings'], true));
    }

    public function test_check_remarkOfCheck_inTheReport(): void
    {
        $source          = self::cataloged(streams: 1);
        $source->onCheck = static fn(Context $ctx) => $ctx->remark('DUPLICATE_ICO', 'IČO 123 twice');
        $run             = self::startRun($source, ['mode' => 'check']);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame([['code' => 'DUPLICATE_ICO', 'text' => 'IČO 123 twice']], json_decode(self::stored($run->getId())['report'], true)['remarks']);
    }

    public function test_resume_afterAnErrorPastTheCheck_doesNotAskAgain(): void
    {
        $source                         = self::source(streams: 1, jobs: 2, items: 1, source: new CheckedSource());
        $source->jobs['s1'][1]['error'] = new SourceUnavailable('TRANSPORT', 'No answer');
        $source->onCheck                = static function (CheckedSource $s): void {
            // the hook's check() finds the source down: the run ends in error
            if (count($s->checkCalls) === 1) {
                $s->check = new SourceUnavailable('TRANSPORT', 'No answer');
            }
        };
        $run = self::startRun($source);
        self::runAll($run->getId());
        self::assertSame(['error', 'TRANSPORT'], self::errorOf($run->getId()));
        self::assertSame([1, ''], [TestRun::get($run->getId())?->position()['checked'], TestRun::get($run->getId())?->position()['ccode']]);

        unset($source->jobs['s1'][1]['error']);
        $source->check = new CheckResult(true, '', '');
        DB_AA::transaction(static fn() => TestRun::lockForUpdate($run->getId())?->resume(AuthGetUid()));
        self::assertSame(1, TestRun::get($run->getId())?->position()['checked'], 'Continue keeps a check() that passed');
        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertCount(2, $source->checkCalls, 'at the start and by the hook - not again after Continue');
        self::assertSame(['k-s1:a-1', 'k-s1:b-1'], TestRun::storedValues('relation........'));
    }

    public function test_run_checkAtStartPassed_isNoProgressOfAFailingStep(): void
    {
        $source             = self::checkedSource(streams: 2);
        $source->jobs['s1'] = static fn(): array => throw new SourceUnavailable('TRANSPORT', 'No answer');
        $run                = self::startRun($source);

        self::runOne();
        self::assertSame(['1', RunCursor::START], [self::stored($run->getId())['failures'], TestRun::get($run->getId())?->position()], 'the next step asks again');
        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertCount(3, array_filter($source->jobCalls, static fn(array $c): bool => $c['stream'] === 's1'), 'three tries, as without the check');
        self::assertSame(['k-s2:a-1'], TestRun::storedValues('relation........'), 'the hook skipped s1: its check() passed');
        self::assertSame(['JOB_FAILED' => 1], self::warnings($run->getId()));
    }

    /** @return array<string, array{string}> */
    public static function outages(): array
    {
        return ['source unavailable' => ['unavailable'], 'transport' => ['transport']];
    }

    #[DataProvider('outages')]
    public function test_run_checkAtStartUnavailableRetries(string $outage): void
    {
        $source        = self::checkedSource(streams: 2);
        $source->check = ($outage === 'transport') ? new TransportException('no route to host') : new SourceUnavailable('TRANSPORT', 'No answer', 'timeout');
        $run           = self::startRun($source);

        self::runOne();
        $after = TestRun::get($run->getId());
        self::assertSame(['running', self::NOW + 1800], [$after?->state(), (int) self::stored($run->getId())['next_at']], 'retried as a transport failure');
        self::runAll($run->getId());

        self::assertSame(['error', 'TRANSPORT'], self::errorOf($run->getId()));
        self::assertSame(($outage === 'transport') ? '' : 'timeout', self::stored($run->getId())['error_detail']);
        self::assertCount(3, $source->checkCalls, 'three steps; the hook has no job to skip and does not ask again');
        self::assertSame([], $source->jobCalls);
        self::assertSame([], self::warnings($run->getId()));
        self::assertNull(StreamState::forDefinition(self::DEF), 'an outage is no answer of check()');
    }

    public function test_run_checkAtStartUpAgain_importsEverything(): void
    {
        $source          = self::checkedSource(streams: 2);
        $source->check   = new SourceUnavailable('TRANSPORT', 'No answer');
        $source->onCheck = static function (CheckedSource $s): void {
            if (count($s->checkCalls) === 1) {
                $s->check = new CheckResult(true, '', 'fine now');
            }
        };
        $run = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(self::keys(2, 1, 1), TestRun::storedValues('relation........'));
        self::assertCount(2, $source->checkCalls);
        self::assertSame([], self::warnings($run->getId()), 'no stream skipped');
        self::assertTrue(StreamState::forDefinition(self::DEF)?->lastCheck()['ok']);
    }

    public function test_run_checkAtStart_stepsDyingThere_skipTheFirstStream(): void
    {
        $source = self::checkedSource(streams: 2);
        $run    = self::startRun($source);
        // two steps died before their end, each maybe past the check() and in the first job
        self::storedSet($run->getId(), 'lease', (string) (self::NOW - 1));
        self::storedSet($run->getId(), 'lease_tok', 'dead-step');
        self::storedSet($run->getId(), 'failures', '2');

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['k-s2:a-1'], TestRun::storedValues('relation........'), 's1 skipped as before the check at the start');
        self::assertSame(['JOB_FAILED' => 1], self::warnings($run->getId()));
        self::assertCount(2, $source->checkCalls, 'by the hook, then at the start of the next step');
    }

    public function test_run_checkAtStartOnceAcrossSteps(): void
    {
        $source = self::cataloged(streams: 3, jobs: 1, items: 1, seconds: 15.0);
        $run    = self::startRun($source);

        $steps = self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertGreaterThan(2, count($steps));
        self::assertSame(['check'], array_values(array_filter($source->calls, static fn(string $c): bool => $c === 'check')));
        self::assertSame('check', $source->calls[0], 'before any job');
        self::assertSame(['time' => self::NOW, 'ok' => true, 'code' => '', 'msg' => ''], StreamState::forDefinition(self::DEF)?->lastCheck());
    }

    /** @return array<string, array{string, string, int}> */
    public static function startModes(): array
    {
        return [
            'run'         => ['run', '', 1],
            'changed'     => ['changed', '', 1],
            'dry run'     => ['run', '1', 0],
            'dry changed' => ['changed', '1', 0],
        ];
    }

    #[DataProvider('startModes')]
    public function test_run_checkAtStartOfALiveRunOnly(string $mode, string $dry, int $checks): void
    {
        $source = self::cataloged(streams: 1, jobs: 1, items: 1);
        $run    = self::startRun($source, ['mode' => $mode, 'dry' => $dry]);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertCount($checks, array_filter($source->calls, static fn(string $c): bool => $c === 'check'));
    }

    public function test_run_checkAtStartTakesTimeFromTheFirstJob(): void
    {
        $source               = self::cataloged(streams: 1, jobs: 1, items: 1);
        $source->checkSeconds = 20.0;
        $run                  = self::startRun($source);

        self::runOne();

        self::assertSame([], TestRun::$savers, '7 s left - the job waits for the next step');
        self::assertSame(1, TestRun::get($run->getId())?->position()['checked']);
        self::runAll($run->getId());
        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['k-s1:a-1'], TestRun::storedValues('relation........'));
    }

    public function test_run_uncheckableSourceImportsAsBefore(): void
    {
        $source = self::source(streams: 1, jobs: 1, items: 1);
        $run    = self::startRun($source);

        self::assertSame(['done'], self::runAll($run->getId()));
        self::assertSame(['k-s1:a-1'], TestRun::storedValues('relation........'));
        self::assertNull(StreamState::forDefinition(self::DEF)?->lastCheck());
    }

    // ------------------------------------------------------------ key collisions

    public function test_report_keyCollisionsWithinJob(): void
    {
        $source                         = self::source(streams: 1, jobs: 2, items: 0);
        $source->jobs['s1'][0]['items'] = [['key' => 'k1', 'val' => '01'], ['key' => 'k1', 'val' => '02'], ['key' => 'k2', 'val' => 'x'], ['key' => 'k1', 'val' => '03']];
        $source->jobs['s1'][1]['items'] = [['key' => 'k1', 'val' => 'the same key in another job']];
        $run                            = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()), 'no error');
        $report = json_decode(self::stored($run->getId())['report'], true);
        self::assertSame(['jobs' => [['stream' => 's1', 'job' => 's1:a', 'count' => 2]], 'more' => 0], $report['collisions']);
        self::assertSame(0, TestRun::get($run->getId())?->counts()['errors']);
        self::assertCount(5, TestRun::stored(), 'each one stored - the last one wins');
    }

    // ------------------------------------------------------------ empty keys

    public function test_emptyKey_skippedRowsKeepTheStreamVersion(): void
    {
        $source                         = self::source(streams: 1, jobs: 1, items: 0, probed: true);
        $source->jobs['s1'][0]['items'] = [['key' => 'k1', 'val' => '1'], ['key' => ' ', 'val' => 'a territory the slice lacks'], ['key' => 'k2', 'val' => '2']];
        $run                            = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        $counts = TestRun::get($run->getId())?->counts();
        self::assertSame([2, 1, 0], [$counts['new'] + $counts['updated'], $counts['skipped'], $counts['errors']]);
        self::assertSame('v-s1', StreamState::forDefinition(self::DEF)?->versionOf('s1'), 'a skipped row is no failed one');
        $expected = ['count' => 1, 'samples' => [['stream' => 's1', 'job' => 's1:a', 'values' => ['key' => ' ']]]];
        self::assertSame($expected, TestRun::get($run->getId())?->emptyKeys());
        self::assertSame($expected, json_decode(self::stored($run->getId())['report'], true)['empty_keys']);
    }

    public function test_emptyKey_jobKeyedInAnEarlierStep_restStaysSkipped(): void
    {
        $source                         = self::source(streams: 1, jobs: 1, items: 0, seconds: 10.0, probed: true);
        $keyed                          = array_map(static fn(string $k): array => ['key' => $k, 'val' => 'v'], ['k1', 'k2', 'k3']);
        $source->jobs['s1'][0]['items'] = [...$keyed, ...array_fill(0, 3, ['key' => '', 'val' => 'x'])];
        $run                            = self::startRun($source);

        $steps = self::runAll($run->getId());

        self::assertCount(2, $steps, 'three items fit a step - the second has no key at all');
        self::assertSame([3, 0], [TestRun::get($run->getId())?->counts()['skipped'], TestRun::get($run->getId())?->counts()['errors']]);
        self::assertSame('v-s1', StreamState::forDefinition(self::DEF)?->versionOf('s1'));
        self::assertSame(3, TestRun::get($run->getId())?->emptyKeys()['count']);
    }

    public function test_emptyKey_allRowsIsError(): void
    {
        $source                         = self::source(streams: 2, jobs: 1, items: 1, probed: true);
        $source->jobs['s1'][0]['items'] = [['key' => '', 'val' => '1'], ['key' => ' ', 'val' => '2']];
        $run                            = self::startRun($source);

        self::runAll($run->getId());

        self::assertSame([2, 1], [TestRun::get($run->getId())?->counts()['skipped'], TestRun::get($run->getId())?->counts()['errors']], 'one error for the job, its items stay skipped');
        $errors = TestRun::get($run->getId())?->errors() ?? [];
        self::assertSame([['stream' => 's1', 'job' => 's1:a', 'code' => 'EMPTY_KEY', 'msg' => 'No item of the job had its key (2 without it) - the key of the mapping is broken']], $errors);
        self::assertNull(StreamState::forDefinition(self::DEF)?->versionOf('s1'));
        self::assertSame('v-s2', StreamState::forDefinition(self::DEF)?->versionOf('s2'));
        self::assertSame(2, json_decode(self::stored($run->getId())['report'], true)['empty_keys']['count'], 'the samples show what broke');
    }

    /** @return iterable<string, array{string}> */
    public static function dryOrLive(): iterable
    {
        yield 'dry run' => ['1'];
        yield 'live run' => [''];
    }

    #[DataProvider('dryOrLive')]
    public function test_emptyKey_allRowsUpToTheLimitIsError(string $dry): void
    {
        $source                         = self::source(streams: 1, jobs: 1, items: 0, probed: true);
        $source->jobs['s1'][0]['items'] = [...array_fill(0, 3, ['key' => '', 'val' => 'x']), ['key' => 'k4', 'val' => 'v']];
        $run                            = self::startRun($source, ['dry' => $dry, 'limit' => '3']);

        self::runAll($run->getId());

        self::assertSame('None of the 3 items read had its key - the key of the mapping is broken', TestRun::get($run->getId())?->errors()[0]['msg'] ?? '');
        self::assertSame(1, TestRun::get($run->getId())?->counts()['errors']);
        self::assertNull(StreamState::forDefinition(self::DEF)?->versionOf('s1'));
    }

    public function test_emptyKey_limitCutsAJobAfterKeyedOnes_noError(): void
    {
        $source                         = self::source(streams: 1, jobs: 2, items: 3, probed: true);
        $source->jobs['s1'][1]['items'] = array_fill(0, 3, ['key' => '', 'val' => 'a territory the slice lacks']);
        $run                            = self::startRun($source, ['dry' => '1', 'limit' => '4']);

        self::runAll($run->getId());

        $counts = TestRun::get($run->getId())?->counts();
        self::assertSame([4, 1, 0], [$counts['read'], $counts['skipped'], $counts['errors']], 'one keyless item of job b says nothing of the mapping');
    }

    public function test_emptyKey_keyedJobThenKeylessJob_errorForTheSecond(): void
    {
        $source                         = self::source(streams: 1, jobs: 2, items: 2, probed: true);
        $source->jobs['s1'][1]['items'] = array_fill(0, 2, ['key' => '', 'val' => 'x']);
        $run                            = self::startRun($source);

        self::runAll($run->getId());

        $errors = TestRun::get($run->getId())?->errors() ?? [];
        self::assertSame([['s1:b', 'EMPTY_KEY']], array_map(static fn(array $e): array => [$e['job'], $e['code']], $errors), 'each job decides on its own items');
        self::assertNull(StreamState::forDefinition(self::DEF)?->versionOf('s1'));
    }

    public function test_emptyKey_lastStepReadsNothing_stillError(): void
    {
        $items                          = array_fill(0, 3, ['key' => '', 'val' => 'x']);
        $source                         = self::source(streams: 1, jobs: 1, items: 0, probed: true);
        $source->jobs['s1'][0]['items'] = $items;
        // a step before read all three items, and its time ran out before it asked for a fourth
        $read = new ParamsGrabber(new ScriptedGrabber(array_map(static function (array $fields): AA_Content {
            $item = new AA_Content();
            foreach ($fields as $field => $value) {
                $item->setAaValue($field, new AA_Value($value));
            }
            return $item;
        }, $items)), ['fld' => 'text...........1']);
        $read->prepare();
        $run = self::startRun($source, ['position' => ['offset' => 3, 'fp' => $read->fingerprint(), 'nokey' => 3] + self::onJob('s1:a')]);

        self::runAll($run->getId());

        self::assertSame(0, TestRun::get($run->getId())?->counts()['read'], 'this step read nothing');
        self::assertSame(['EMPTY_KEY'], array_column(TestRun::get($run->getId())?->errors() ?? [], 'code'));
        self::assertNull(StreamState::forDefinition(self::DEF)?->versionOf('s1'));
    }

    public function test_emptyKey_jobReadAgain_countsItsKeysAnew(): void
    {
        $source = self::source(streams: 1, jobs: 1, items: 5, seconds: 10.0, probed: true);
        $run    = self::startRun($source);
        self::runOne();
        self::assertSame(1, TestRun::$committed[0]->position['keyed']);

        // the source changed under the offset: the job starts over, and now no item has a key
        $source->jobs['s1'][0]['items'] = array_fill(0, 5, ['key' => '', 'val' => 'x']);
        self::runAll($run->getId());

        self::assertSame(['EMPTY_KEY'], array_column(TestRun::get($run->getId())?->errors() ?? [], 'code'));
        self::assertStringContainsString('(5 without it)', TestRun::get($run->getId())?->errors()[0]['msg'] ?? '');
    }

    public function test_report_namesTheRunsAPlannedStartEnded(): void
    {
        $old = str_repeat('ab', 16);
        $run = self::startRun(self::source(streams: 1, jobs: 1, items: 1), ['replaced' => json_encode([$old])]);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame([$old], json_decode(self::stored($run->getId())['report'], true)['replaced']);
    }

    public function test_report_noCollisions_noEntry(): void
    {
        $run = self::startRun(self::source(streams: 1, jobs: 2, items: 2), ['dry' => '1']);

        self::runAll($run->getId());

        self::assertArrayNotHasKey('collisions', json_decode(self::stored($run->getId())['report'], true));
        self::assertArrayNotHasKey('replaced', json_decode(self::stored($run->getId())['report'], true), 'nor runs a planned start ended');
    }

    public function test_report_keyCollisionsOfADryRun(): void
    {
        $source                         = self::source(streams: 1, jobs: 1, items: 0);
        $source->jobs['s1'][0]['items'] = [['key' => 'k1', 'val' => '01'], ['key' => 'k1', 'val' => '02']];
        $run                            = self::startRun($source, ['dry' => '1']);

        self::runAll($run->getId());

        $report = json_decode(self::stored($run->getId())['report'], true);
        self::assertSame(['jobs' => [['stream' => 's1', 'job' => 's1:a', 'count' => 1]], 'more' => 0], $report['collisions']);
    }

    public function test_report_keyCollisionsOfAJobAcrossSteps_addUp(): void
    {
        $source                         = self::source(streams: 1, jobs: 1, items: 0, seconds: 10.0);
        $source->jobs['s1'][0]['items'] = array_map(static fn(string $k): array => ['key' => $k, 'val' => 'v'], ['k1', 'k1', 'k1', 'k2', 'k2']);
        $run                            = self::startRun($source);

        $steps = self::runAll($run->getId());

        self::assertCount(2, $steps, 'three items fit a step');
        $report = json_decode(self::stored($run->getId())['report'], true);
        self::assertSame(['jobs' => [['stream' => 's1', 'job' => 's1:a', 'count' => 3]], 'more' => 0], $report['collisions'], 'one entry of the job');
    }

    public function test_report_keyCollisions_keptForTwentyJobs(): void
    {
        $source = self::source(streams: 1, jobs: 25, items: 0);
        foreach ($source->jobs['s1'] as $j => $job) {
            $source->jobs['s1'][$j]['items'] = [['key' => "k$j", 'val' => '1'], ['key' => "k$j", 'val' => '2']];
        }
        $source->jobs['s1'][24]['items'][] = ['key' => 'k24', 'val' => '3'];
        $run = self::startRun($source);

        self::runAll($run->getId());

        $collisions = json_decode(self::stored($run->getId())['report'], true)['collisions'];
        self::assertCount(20, $collisions['jobs']);
        self::assertSame(['stream' => 's1', 'job' => 's1:t', 'count' => 1], $collisions['jobs'][19]);
        self::assertSame(6, $collisions['more'], 'the five jobs not listed, the last one twice');
    }

    public function test_report_keyCollisions_jobKeyRedacted(): void
    {
        define('AA_APIKEY_RUNCOLL', 'sekret5151');
        $source              = self::source(streams: 1, jobs: 1, items: 0);
        $source::$credentials = [['vendor' => 'RUNCOLL', 'context' => '']];
        $source->jobs['s1']  = [['key' => 's1:x.sekret5151', 'items' => [['key' => 'k1', 'val' => '1'], ['key' => 'k1', 'val' => '2']]]];
        $run                 = self::startRun($source);

        self::runAll($run->getId());

        $collisions = json_decode(self::stored($run->getId())['report'], true)['collisions'];
        self::assertSame('s1:x.***', $collisions['jobs'][0]['job']);
        self::assertStringNotContainsString('sekret5151', (string) self::stored($run->getId())['collisions']);
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

    public function test_version_fromProbeInRunModeAtStreamStart(): void
    {
        $source               = self::cataloged(streams: 1, jobs: 1, items: 1);
        $source->probes['s1'] = new Probe(Probe::CHANGED, 'v-7');
        $run                  = self::startRun($source, ['position' => self::pastCheck()]);

        self::runAll($run->getId());

        self::assertSame(['probe:s1'], $source->calls, 'asked once, before its jobs');
        self::assertEquals(['v' => 'v-7', 'imported' => self::NOW, 'run' => $run->getId(), 'probed' => self::NOW], StreamState::forDefinition(self::DEF)?->streams()['s1']);
    }

    public function test_version_changedModeKeepsTheProbeOfItsStart(): void
    {
        $source               = self::cataloged(streams: 1, jobs: 2, items: 1, seconds: 5.0);
        $source->minJob       = 25;
        $source->probes['s1'] = new Probe(Probe::CHANGED, 'v-start');
        $run                  = self::startRun($source, ['mode' => 'changed', 'position' => self::pastCheck()]);

        self::runOne();
        $source->probes['s1'] = new Probe(Probe::CHANGED, 'v-later');
        self::runAll($run->getId());

        self::assertSame(['probe:s1'], $source->calls);
        self::assertSame('v-start', StreamState::forDefinition(self::DEF)?->versionOf('s1'), 'the version the import started from');
        self::assertSame(self::NOW, StreamState::forDefinition(self::DEF)?->streams()['s1']['probed']);
    }

    // the file changing during the run: the version stored is that of the data imported, so changed sees the newer one
    public function test_version_runModeKeepsTheProbeOfItsStart(): void
    {
        $source               = self::cataloged(streams: 1, jobs: 2, items: 1, seconds: 5.0);
        $source->minJob       = 25;
        $source->probes['s1'] = new Probe(Probe::CHANGED, 'v-start');
        $run                  = self::startRun($source, ['position' => self::pastCheck()]);

        self::runOne();
        $source->probes['s1'] = new Probe(Probe::CHANGED, 'v-later');
        self::runAll($run->getId());

        self::assertSame(['probe:s1'], $source->calls);
        self::assertSame('v-start', StreamState::forDefinition(self::DEF)?->versionOf('s1'));
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

    /** a step that lost the run to another one runs no expression of its stream: that step does it */
    public function test_afterStream_notOnceTheRunIsAnotherStepsOwn(): void
    {
        $source            = self::source(streams: 1, jobs: 1, items: 1);
        TestRun::$evaluate = static function (): void {};
        $run               = self::startRun($source, [], ['after_stream' => '{x}']);
        $id                = $run->getId();
        $source->jobs['s1'][0]['onPrepare'] = static function () use ($id): void {
            self::storedSet($id, 'lease_tok', 'retried');
        };

        self::runOne();

        self::assertSame(['k-s1:a-1'], TestRun::storedValues('relation........'));
        self::assertSame([], TestRun::$evaluated);
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
        $source::$credentials = [['vendor' => 'RUNCUT', 'context' => '']];
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
        self::assertSame(['check', 'probe:s1', 'check'], $source->calls, 'checked at the start, probed once; then the hook asked check()');
        self::assertSame(['JOB_FAILED' => 1], self::warnings($run->getId()));
        self::assertLessThanOrEqual(5, count($steps));
    }

    public function test_version_fromTheSource_redactedCleanAndCut(): void
    {
        define('AA_APIKEY_RUNVER', 'sekret99');
        $source               = self::cataloged(streams: 1, jobs: 1, items: 1);
        $source::$credentials  = [['vendor' => 'RUNVER', 'context' => '']];
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
        $source::$credentials  = [['vendor' => 'RUNPV', 'context' => '']];
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
        $source::$credentials = [['vendor' => 'RUNCUR', 'context' => '']];
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
        $source              = self::cataloged(streams: 2, jobs: 1, items: 1, seconds: 20.0);
        $run                 = self::startRun($source, ['position' => self::pastCheck()]);

        self::runOne();

        self::assertSame(['probe:s1'], $source->calls, '7 s left - the probe of s2 waits for the next step');
        self::assertSame(['i' => 1, 'probed' => 0], array_intersect_key((array) TestRun::get($run->getId())?->position(), ['i' => 0, 'probed' => 0]));
        self::runAll($run->getId());
        self::assertSame(['probe:s1', 'probe:s2'], $source->calls);
        self::assertCount(2, $source->grabbers);
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
        $source::$credentials = [['vendor' => 'RUNVMAX', 'context' => '']];
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
        $run = self::startRun($source, ['position' => ['i' => 1, 'after' => 's1'] + self::pastCheck()], ['after_stream' => '{x}']);

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
        $run               = self::startRun($source, ['position' => self::pastCheck()], ['after_stream' => '{x}']);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertEquals(['JOB_FAILED' => 1, 'STREAM_GONE' => 1], self::warnings($run->getId()));
        self::assertSame(1, TestRun::get($run->getId())?->counts()['streams'], 'only s2 counts');
        self::assertCount(1, TestRun::$evaluated, 'no expression for a stream gone');
    }

    // ------------------------------------------------------------ baseline: the state of the source recorded

    public function test_baseline_versionsWithoutImport(): void
    {
        $source               = self::cataloged(streams: 3, jobs: 1, items: 1);
        $source->callSeconds  = 10.0;
        $source->probes['s2'] = new Probe(Probe::UNCHANGED, 'v-2');
        self::streamVersions(['s3' => 'v-old']);
        $run = self::startRun($source, ['mode' => 'baseline']);

        self::runOne();
        self::assertSame(['check', 'probe:s1', 'probe:s2'], $source->calls, 'in queue steps, as a check');
        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['check', 'probe:s1', 'probe:s2', 'probe:s3'], $source->calls, 'no catalog');
        self::assertSame(['s1' => null, 's2' => null, 's3' => null], $source->known, 'what the source has now, whatever was imported before');
        self::assertSame([], $source->jobCalls, 'nothing fetched');
        self::assertSame([], TestRun::$savers, 'nothing stored');
        self::assertSame([], self::$invalidated);
        $state = StreamState::forDefinition(self::DEF);
        self::assertEquals(['v' => 'v-s1', 'probed' => self::NOW, 'baseline' => self::NOW, 'run' => $run->getId()], $state?->streams()['s1'], 'a baseline, not an import');
        self::assertSame(['v-s1', 'v-2', 'v-s3'], [$state->versionOf('s1'), $state->versionOf('s2'), $state->versionOf('s3')]);
        self::assertTrue($state->lastCheck()['ok']);
        $report = json_decode(self::stored($run->getId())['report'], true);
        self::assertSame(['streams' => 3, 'unversioned' => 0], $report['baseline']);
        self::assertSame(['ok' => true, 'code' => '', 'msg' => ''], $report['check']);
        self::assertArrayNotHasKey('probe', $report);

        $source->calls  = [];
        $source->known  = [];
        $source->probes = ['s1' => new Probe(Probe::UNCHANGED, 'v-s1'), 's2' => new Probe(Probe::UNCHANGED, 'v-2'), 's3' => new Probe(Probe::CHANGED, 'v-s3b')];
        $changed        = self::startRun($source, ['mode' => 'changed']);
        self::runAll($changed->getId());

        self::assertSame(['done', ''], self::errorOf($changed->getId()));
        self::assertSame(['s1' => 'v-s1', 's2' => 'v-2', 's3' => 'v-s3'], $source->known, 'the next changed run starts from the recorded versions');
        self::assertSame([['stream' => 's3', 'known' => 'v-s3']], $source->jobCalls, 'only what changed since');
    }

    /** nothing vouches for the old version: it goes, and the next changed run imports the stream */
    public function test_baseline_noVersionLeavesNoneKnown(): void
    {
        $source               = self::cataloged(streams: 2);
        $source->probes['s1'] = new Probe(Probe::UNKNOWN);
        self::streamVersions(['s1' => 'v-old']);
        $run = self::startRun($source, ['mode' => 'baseline']);

        self::runAll($run->getId());

        $state = StreamState::forDefinition(self::DEF);
        self::assertSame([null, 'v-s2'], [$state?->versionOf('s1'), $state?->versionOf('s2')]);
        self::assertArrayNotHasKey('v', $state->streams()['s1'], "not '' - that would say the source has no versions");
        self::assertSame([2, 1], [TestRun::get($run->getId())?->counts()['streams'], TestRun::get($run->getId())?->counts()['unversioned']]);
        self::assertSame(['streams' => 2, 'unversioned' => 1], json_decode(self::stored($run->getId())['report'], true)['baseline']);
    }

    /** the maximum of a versionField needs the rows; a version probe() gives may be on another scale
     *  unless the source vouches for it (FieldMaxProbeable) */
    public function test_baseline_versionFieldStreamGetsNoVersion(): void
    {
        $source             = self::cataloged(streams: 2);
        $source->streams[0] = new Stream('s1', 'Stream 1', ['fld' => 'text...........1'], '', 'year');
        self::streamVersions(['s1' => '2021']);
        $run = self::startRun($source, ['mode' => 'baseline']);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['check', 'probe:s2'], $source->calls, 'not asked for s1');
        $state = StreamState::forDefinition(self::DEF);
        self::assertSame([null, 'v-s2'], [$state?->versionOf('s1'), $state?->versionOf('s2')]);
        self::assertEquals(['baseline' => self::NOW, 'run' => $run->getId()], $state->streams()['s1']);
        self::assertSame(1, TestRun::get($run->getId())?->counts()['unversioned']);
    }

    /** a source that vouches for the maximum of the field: the baseline records it, and the next changed
     *  run compares on that scale - an unchanged stream skipped, a later one read from it on */
    public function test_baseline_fieldMaxProbeRecordsVersionField(): void
    {
        $source             = self::cataloged(streams: 2, source: new FieldMaxSource());
        $source->streams[0] = new Stream('s1', 'Stream 1', ['fld' => 'text...........1'], '', 'year');
        $source->streams[1] = new Stream('s2', 'Stream 2', ['fld' => 'text...........1'], '', 'year');
        $source->probes     = ['s1' => new Probe(Probe::CHANGED, '2023'), 's2' => new Probe(Probe::CHANGED, '2022')];
        self::streamVersions(['s1' => '2019']);
        $run = self::startRun($source, ['mode' => 'baseline']);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['check', 'probe:s1', 'probe:s2'], $source->calls);
        self::assertSame(['s1' => null, 's2' => null], $source->known, 'what the source has now');
        self::assertSame([], $source->jobCalls, 'nothing fetched');
        $state = StreamState::forDefinition(self::DEF);
        self::assertSame(['2023', '2022'], [$state?->versionOf('s1'), $state?->versionOf('s2')]);
        self::assertEquals(['v' => '2023', 'probed' => self::NOW, 'baseline' => self::NOW, 'run' => $run->getId()], $state?->streams()['s1']);
        self::assertSame(0, TestRun::get($run->getId())?->counts()['unversioned']);

        $source->calls  = [];
        $source->known  = [];
        $source->probes = ['s1' => new Probe(Probe::UNCHANGED), 's2' => new Probe(Probe::CHANGED)];
        $source->jobs['s2'][0]['items'] = [['key' => 'k1', 'val' => 'v', 'year' => '2024']];
        $changed = self::startRun($source, ['mode' => 'changed']);
        self::runAll($changed->getId());

        self::assertSame(['done', ''], self::errorOf($changed->getId()));
        self::assertSame(['s1' => '2023', 's2' => '2022'], $source->known, 'probed from the recorded versions');
        self::assertSame([['stream' => 's2', 'known' => '2022']], $source->jobCalls, 'only the stream with a later year');
        self::assertSame(1, TestRun::get($changed->getId())?->counts()['unchanged']);
        self::assertSame(['2023', '2024'], [StreamState::forDefinition(self::DEF)?->versionOf('s1'), StreamState::forDefinition(self::DEF)?->versionOf('s2')]);
    }

    /** a maximum the source cannot tell leaves the stream unversioned, as any baseline without a version */
    public function test_baseline_fieldMaxProbeWithoutVersionIsUnversioned(): void
    {
        $source             = self::cataloged(streams: 1, source: new FieldMaxSource());
        $source->streams[0] = new Stream('s1', 'Stream 1', ['fld' => 'text...........1'], '', 'year');
        $source->probes     = ['s1' => new Probe(Probe::CHANGED)];
        self::streamVersions(['s1' => '2019']);
        $run = self::startRun($source, ['mode' => 'baseline']);

        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['check', 'probe:s1'], $source->calls);
        self::assertNull(StreamState::forDefinition(self::DEF)?->versionOf('s1'), 'the old version goes');
        self::assertSame(1, TestRun::get($run->getId())?->counts()['unversioned']);
        self::assertSame(['streams' => 1, 'unversioned' => 1], json_decode(self::stored($run->getId())['report'], true)['baseline']);
    }

    public function test_baseline_versionRedacted(): void
    {
        define('AA_APIKEY_RUNBASE', 'sekret66');
        $source               = self::cataloged(streams: 1);
        $source::$credentials = [['vendor' => 'RUNBASE', 'context' => '']];
        $source->probes['s1'] = new Probe(Probe::CHANGED, 'https://x.cz/?k=sekret66');
        $run                  = self::startRun($source, ['mode' => 'baseline']);

        self::runAll($run->getId());

        self::assertSame('https://x.cz/?k=***', StreamState::forDefinition(self::DEF)?->versionOf('s1'));
        foreach (TableDb::rows('object_text') as $row) {
            self::assertStringNotContainsString('sekret66', (string) $row['value']);
        }
    }

    /** a check() passed is the progress of the step, as in a check: the probe has its own tries */
    public function test_baseline_probeDownRightAfterCheck_isProgress(): void
    {
        $source                  = self::cataloged(streams: 1);
        $source->failProbe['s1'] = new SourceUnavailable('TRANSPORT', 'No answer');
        $run                     = self::startRun($source, ['mode' => 'baseline']);

        self::runOne();
        self::assertSame(['0', 1], [self::stored($run->getId())['failures'], TestRun::get($run->getId())?->position()['checked']]);
        unset($source->failProbe['s1']);
        self::runAll($run->getId());

        self::assertSame(['done', ''], self::errorOf($run->getId()));
        self::assertSame(['check', 'probe:s1', 'probe:s1'], $source->calls);
        self::assertSame('v-s1', StreamState::forDefinition(self::DEF)?->versionOf('s1'));
    }

    /** a source that does not answer ends the baseline as it ends a check, with what it recorded kept */
    public function test_baseline_probeDownErrorsAsCheck(): void
    {
        $source                  = self::cataloged(streams: 2);
        $source->failProbe['s2'] = new SourceUnavailable('TRANSPORT', 'No answer');
        $run                     = self::startRun($source, ['mode' => 'baseline']);

        self::runAll($run->getId());

        self::assertSame(['error', 'TRANSPORT'], self::errorOf($run->getId()));
        $state = StreamState::forDefinition(self::DEF);
        self::assertSame(['v-s1', null], [$state?->versionOf('s1'), $state?->versionOf('s2')]);
        self::assertSame([], $source->jobCalls);
    }

    public function test_baseline_dryIsRefused(): void
    {
        $source = self::cataloged(streams: 1);
        $run    = self::startRun($source, ['mode' => 'baseline', 'dry' => '1']);

        self::runAll($run->getId());

        self::assertSame(['error', 'DEFINITION'], self::errorOf($run->getId()), 'dry it would not record what it is for');
        self::assertSame([], $source->calls);
        self::assertNull(StreamState::forDefinition(self::DEF));
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

    /** source() of one-item jobs over a CheckedSource whose check() passes */
    private static function checkedSource(int $streams): CheckedSource
    {
        $source = new CheckedSource();
        self::source($streams, 1, 1, source: $source);
        return $source;
    }

    /** source() over a CatalogSource that checks, probes and has an empty catalog */
    private static function cataloged(int $streams, int $jobs = 1, int $items = 0, float $seconds = 0.0, ?CatalogSource $source = null): CatalogSource
    {
        $source       ??= new CatalogSource();
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
