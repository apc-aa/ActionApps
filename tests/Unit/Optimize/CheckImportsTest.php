<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Import\Catalog;
use AA\Import\CatalogEntry;
use AA\Import\CatalogPage;
use AA\Import\CheckResult;
use AA\Import\Definition;
use AA\Import\Registry;
use AA\Import\Run;
use AA\Import\RunCursor;
use AA\Import\StreamState;
use AA\IO\DB\DB_AA;
use AA\Later\Toexecute;
use AA\Optimize\CheckImports;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Later\Fixtures\BatchRunTables;

require_once __DIR__ . '/../Later/Fixtures/BatchRunTables.php';

/**
 * CheckImports: State Check of the imports - runs stopped or hanging, definitions that cannot run,
 * records left without their definition, failures only the log knows of. Read through the
 * (property, value) and object_id indexes of object_text only, never a whole record of a definition;
 * repair() deletes what a deleted definition left, as deleting the definition does.
 */
final class CheckImportsTest extends TestCase
{
    use BatchRunTables {
        setUpBeforeClass as private tablesBeforeClass;
        setUp as private tablesSetUp;
        tearDown as private tablesTearDown;
    }

    private const SLICE  = '5b5b5b5b5b5b5b5b5b5b5b5b5b5b5b5b';
    private const SLICE2 = '6c6c6c6c6c6c6c6c6c6c6c6c6c6c6c6c';
    private const DEF    = '0123456789abcdef0123456789abcdef';
    private const DEF2   = 'fedcba9876543210fedcba9876543210';
    private const DEF3   = 'abababababababababababababababab';
    /** a definition deleted long ago - only its records are left */
    private const GONE   = '0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f';
    private const SOURCE = 'custom:checkimportsplain';
    private const DAY    = 86400;

    /** holds the installation directory of the test source */
    private static string $root = '';

    public static function setUpBeforeClass(): void
    {
        self::tablesBeforeClass();
        self::$root = sys_get_temp_dir() . '/aa-checkimports-' . bin2hex(random_bytes(6));
        mkdir(self::$root . '/import', 0777, true);
        file_put_contents(self::$root . '/import/CheckImportsPlainSource.php', <<<'PHP'
            <?php
            final class CheckImportsPlainSource extends \AA\Import\AbstractSource
            {
                public static function name(): string { return 'Plain'; }
                public static function description(): string { return ''; }
                public static function getClassProperties(): array { return []; }
                public static function fromSettings(array $settings): static { throw new \LogicException('State Check runs no code of a source'); }
                public function streams(): iterable { return []; }
                public function jobs(\AA\Import\Stream $stream, \AA\Import\Context $ctx, ?string $known): iterable { return []; }
            }
            PHP);
        // left out by the Registry - it declares no class
        file_put_contents(self::$root . '/import/CheckImportsEmptySource.php', "<?php\n");
    }

    public static function tearDownAfterClass(): void
    {
        array_map('unlink', glob(self::$root . '/import/*') ?: []);
        rmdir(self::$root . '/import');
        rmdir(self::$root);
    }

    protected function setUp(): void
    {
        $this->tablesSetUp();
        Registry::setCustomRootForTests(self::$root, self::$root . '/import');
        TableDb::$answer = self::logAnswer(...);
        // the planned tasks are read once a request - here once a test
        \AA::Contentcache()->clear_soft();
    }

    protected function tearDown(): void
    {
        Registry::setCustomRootForTests(null);
        $this->tablesTearDown();
    }

    // ------------------------------------------------------------ runs

    public function test_errorRun_error(): void
    {
        self::definition(self::DEF, 'prices');
        $run = self::importRun(self::DEF, ['state' => Run::ERROR, 'error_code' => 'AUTH', 'error_path' => 's1 job?token=s3cr3t']);

        $finding = self::only(self::findings(time()), "error:$run");

        $this->assertSame(Finding::ERROR, $finding->severity);
        $this->assertContains('AUTH', array_values($finding->evidence[0]));
        // the place is the source's stream and job key - it stays on the import detail
        $this->assertStringNotContainsString('s3cr3t', self::text($finding));
    }

    public function test_stuckRun_warning(): void
    {
        self::definition(self::DEF, 'prices');
        $run = self::importRun(self::DEF);

        $this->assertNull(self::find(self::findings(time() + 3600), "stuck:$run"), 'a run queued an hour ago waits for its step');
        $finding = self::only(self::findings(time() + 3 * 3600), "stuck:$run");

        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('cron', $finding->advice);
    }

    /** a queue whose rounds are all shorter than a step needs: the run waits, unharmed, until the State Check says why */
    public function test_stuckRun_roundsTooShort(): void
    {
        self::definition(self::DEF, 'prices');
        $run   = self::importRun(self::DEF);
        $round = new \ReflectionProperty(Toexecute::class, 'round');
        $round->setValue(null, [microtime(true), 5.0]);
        try {
            $this->assertSame('round too short', self::runQueued());
        } finally {
            $round->setValue(null, null);
        }
        $this->assertSame([Run::RUNNING, '', '0'], [self::stored($run)['state'], self::stored($run)['error_code'], self::stored($run)['failures']]);
        $this->assertCount(1, self::queue(), 'its step waits for the next round');

        $finding = self::only(self::findings(time() + 3 * 3600), "stuck:$run");

        $this->assertStringContainsString('TOEXECUTE_ALLOWED_TIME', $finding->advice);
    }

    /** the hours count from where isStuck() counts: a step due later than the start */
    public function test_stuckRun_hoursSinceTheStepWasDue(): void
    {
        self::definition(self::DEF, 'prices');
        $due = time() + 5 * 3600;
        $run = self::importRun(self::DEF, ['next_at' => (string) $due]);

        $finding = self::only(self::findings($due + 3 * 3600 + 60), "stuck:$run");

        $this->assertStringContainsString(' 3 hours', $finding->title);
        $this->assertSame($due, $finding->since);
    }

    public function test_waitingForRetry_notStuck(): void
    {
        self::definition(self::DEF, 'prices');
        $now = time() + 3 * 3600;
        // the third try failed; the next one is due in 30 minutes
        $run = self::importRun(self::DEF, ['failures' => '2', 'next_at' => (string) ($now + 1800)]);

        $this->assertNull(self::find(self::findings($now), "stuck:$run"));
    }

    // ------------------------------------------------------------ definitions

    public function test_objectTextNotInnodb_error(): void
    {
        self::definition(self::DEF, 'prices');
        TableDb::$engines['object_text'] = 'MyISAM';

        $finding = self::only(self::findings(time()), 'engine');

        $this->assertSame(Finding::ERROR, $finding->severity);
        $this->assertStringContainsString('object_text', self::text($finding));
    }

    public function test_objectTextNotInnodb_noDefinitionNoFinding(): void
    {
        TableDb::$engines['object_text'] = 'MyISAM';

        $this->assertSame([Finding::OK], self::severities(self::findings(time())));
    }

    public function test_unknownOrRejectedSource_error(): void
    {
        self::definition(self::DEF, 'prices', ['source' => 'custom:checkimportsempty']);
        self::definition(self::DEF2, 'rates', ['source' => 'core:nosuch']);

        $finding = self::only(self::findings(time()), 'source');

        $this->assertSame([Finding::ERROR, 2], [$finding->severity, $finding->count]);
        $text = self::text($finding);
        $this->assertStringContainsString('core:nosuch', $text);
        $this->assertStringContainsString('CheckImportsEmptySource.php', $text, 'why the Registry left the file out');
    }

    public function test_lastCheckFailedWithin7Days_warning(): void
    {
        self::definition(self::DEF, 'prices');
        self::definition(self::DEF2, 'rates');
        $checked = time() - 6 * self::DAY;
        self::checked(self::DEF, new CheckResult(false, 'AUTH', '401 from host with key s3cr3t'), $checked);
        // a refusal the source threw is stored with its code alone
        self::checked(self::DEF2, new CheckResult(false, 'NO_KEY', ''), time() - 3600);

        $findings = self::findings(time());

        foreach ([self::DEF => 'AUTH', self::DEF2 => 'NO_KEY'] as $def => $code) {
            $finding = self::only($findings, "check:$def");
            $this->assertSame(Finding::WARNING, $finding->severity);
            $this->assertStringContainsString($code, self::text($finding));
            $this->assertStringNotContainsString('s3cr3t', self::text($finding), 'the message is the source\'s');
        }
        $this->assertSame([$checked, $checked], [self::only($findings, 'check:' . self::DEF)->since, self::only($findings, 'check:' . self::DEF)->last]);
    }

    public function test_lastCheckFailedLongAgo_orPassed_noFinding(): void
    {
        self::definition(self::DEF, 'prices');
        self::definition(self::DEF2, 'rates');
        self::checked(self::DEF, new CheckResult(false, 'AUTH', ''), time() - 8 * self::DAY);
        self::checked(self::DEF2, new CheckResult(true, '', 'fine'), time());

        $this->assertSame([Finding::OK], self::severities(self::findings(time())));
    }

    /** a source may pass and still say what to fix (bad definitions) - by its code, the message is the source's */
    public function test_lastCheckPassedWithCode_info(): void
    {
        self::definition(self::DEF, 'prices');
        self::definition(self::DEF2, 'rates');
        $checked = time() - 3600;
        self::checked(self::DEF, new CheckResult(true, 'BAD_DEFINITIONS', 'item s3cr3t'), $checked);
        self::checked(self::DEF2, new CheckResult(true, 'OLD_NEWS', ''), time() - 8 * self::DAY);

        $findings = self::findings(time());

        $finding = self::only($findings, 'checknote:' . self::DEF);
        $this->assertSame(Finding::INFO, $finding->severity);
        $this->assertStringContainsString('BAD_DEFINITIONS', self::text($finding));
        $this->assertStringContainsString('prices', self::text($finding));
        $this->assertStringNotContainsString('s3cr3t', self::text($finding));
        $this->assertSame($checked, $finding->since);
        $this->assertNull(self::find($findings, 'checknote:' . self::DEF2));
        $this->assertNull(self::find($findings, 'check:' . self::DEF));
    }

    public function test_damagedCheckOfStreamState_warning(): void
    {
        self::definition(self::DEF, 'prices');
        self::definition(self::DEF2, 'rates');
        self::checked(self::DEF, new CheckResult(true, '', ''), time());
        self::storedSet(StreamState::idOf(self::DEF), 'check', '{"time": 1');
        // the streams are read only by the extended check, CheckImportStores
        self::checked(self::DEF2, new CheckResult(true, '', ''), time());
        self::storedSet(StreamState::idOf(self::DEF2), 'streams', '{"s1": {"v": "2026"');

        $findings = self::findings(time());

        $this->assertSame(Finding::WARNING, self::only($findings, 'checkdata:' . self::DEF)->severity);
        $this->assertNull(self::find($findings, 'checkdata:' . self::DEF2));
    }

    public function test_lastRunItemErrors_info(): void
    {
        self::definition(self::DEF, 'prices');
        self::definition(self::DEF2, 'rates');
        self::importRun(self::DEF, ['state' => Run::DONE, 'started' => '100', 'finished' => '200', 'counts' => '{"read":10,"errors":3}']);
        // neither a check, a baseline of the source's state nor a dry run says what the last import left: they store no items
        self::importRun(self::DEF, ['state' => Run::DONE, 'started' => '500', 'finished' => '600', 'mode' => 'check', 'counts' => '{}']);
        self::importRun(self::DEF, ['state' => Run::DONE, 'started' => '600', 'finished' => '650', 'mode' => 'baseline', 'counts' => '{"streams":2}']);
        self::importRun(self::DEF, ['state' => Run::DONE, 'started' => '700', 'finished' => '800', 'dry' => '1', 'counts' => '{"read":5}']);
        // nor a run that is not finished - whatever it says
        self::importRun(self::DEF, ['state' => Run::ERROR, 'started' => '900', 'finished' => '1000', 'counts' => '{"read":1}']);
        // the errors of an older run were fixed by the last one
        self::importRun(self::DEF2, ['state' => Run::DONE, 'started' => '100', 'finished' => '200', 'counts' => '{"errors":3}']);
        self::importRun(self::DEF2, ['state' => Run::ENDED, 'started' => '300', 'finished' => '400', 'counts' => '{"errors":0}']);

        $findings = self::findings(time());

        $finding = self::only($findings, 'items:' . self::DEF);
        $this->assertSame([Finding::INFO, 3, 200, 200], [$finding->severity, $finding->count, $finding->since, $finding->last]);
        $this->assertNull(self::find($findings, 'items:' . self::DEF2));
    }

    public function test_lastRunSkippedStreamsForCycle_warning(): void
    {
        $now = time();
        self::definition(self::DEF, '<b>prices</b>');
        self::definition(self::DEF2, 'rates');
        self::definition(self::DEF3, 'old');
        self::importRun(self::DEF, ['state' => Run::DONE, 'started' => (string) ($now - 200), 'finished' => (string) ($now - 100), 'warnings' => '{"SOURCE_CYCLE":2,"REMARK":{"SOURCE_CYCLE":2}}']);
        // a later run that went through fixed it
        self::importRun(self::DEF2, ['state' => Run::DONE, 'started' => (string) ($now - 900), 'finished' => (string) ($now - 800), 'warnings' => '{"SOURCE_CYCLE":1}']);
        self::importRun(self::DEF2, ['state' => Run::DONE, 'started' => (string) ($now - 300), 'finished' => (string) ($now - 200), 'warnings' => '{"JOB_FAILED":1}']);
        // too long ago to say anything of the source now
        self::importRun(self::DEF3, ['state' => Run::DONE, 'started' => '100', 'finished' => '200', 'warnings' => '{"SOURCE_CYCLE":1}']);

        $findings = self::findings($now);

        $finding = self::only($findings, 'cycle:' . self::DEF);
        $this->assertSame([Finding::WARNING, 2, $now - 100], [$finding->severity, $finding->count, $finding->last]);
        $this->assertStringContainsString('&lt;b&gt;prices&lt;/b&gt;', self::text($finding));
        $this->assertStringContainsString('check the source', self::text($finding));
        $this->assertNull(self::find($findings, 'cycle:' . self::DEF2));
        $this->assertNull(self::find($findings, 'cycle:' . self::DEF3));
    }

    public function test_lastRunSkippedOneStreamForCycle_singular(): void
    {
        self::definition(self::DEF, 'prices');
        self::importRun(self::DEF, ['state' => Run::DONE, 'started' => (string) (time() - 20), 'finished' => (string) (time() - 10), 'warnings' => '{"SOURCE_CYCLE":1}']);

        $this->assertSame('The last run of an import skipped 1 stream: the source went round', self::only(self::findings(time()), 'cycle:' . self::DEF)->title);
    }

    public function test_lastRunDamagedWarnings_noCycleFinding(): void
    {
        self::definition(self::DEF, 'prices');
        self::importRun(self::DEF, ['state' => Run::DONE, 'started' => (string) (time() - 20), 'finished' => (string) (time() - 10), 'warnings' => '{broken']);

        $this->assertNull(self::find(self::findings(time()), 'cycle:' . self::DEF));
    }

    /** runs finished at the same second: the one started later is the last */
    public function test_lastRunItemErrors_tieByStart(): void
    {
        self::definition(self::DEF, 'prices');
        self::definition(self::DEF2, 'rates');
        self::importRun(self::DEF, ['state' => Run::DONE, 'started' => '100', 'finished' => '400', 'counts' => '{"errors":0}']);
        self::importRun(self::DEF, ['state' => Run::DONE, 'started' => '300', 'finished' => '400', 'counts' => '{"errors":2}']);
        self::importRun(self::DEF2, ['state' => Run::DONE, 'started' => '100', 'finished' => '400', 'counts' => '{"errors":2}']);
        self::importRun(self::DEF2, ['state' => Run::DONE, 'started' => '300', 'finished' => '400', 'counts' => '{"errors":0}']);

        $findings = self::findings(time());

        $this->assertSame(2, self::only($findings, 'items:' . self::DEF)->count);
        $this->assertNull(self::find($findings, 'items:' . self::DEF2));
    }

    public function test_enabledWithoutPlannedTask_info(): void
    {
        self::definition(self::DEF, 'prices', ['enabled' => '1']);
        self::definition(self::DEF2, 'rates', ['enabled' => '1']);
        self::definition(self::DEF3, 'fees', ['enabled' => '1']);
        self::plannedTask('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1', self::SLICE, '{IMPORT: Rates } {import:prices2}');
        // a task of another slice does not start the import of this one
        self::plannedTask('b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2', self::SLICE2, '{import:prices}');
        self::plannedTask('c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3', self::SLICE, '{import:' . self::DEF3 . ':changed}');

        $findings = self::findings(time());

        $this->assertSame(Finding::INFO, self::only($findings, 'plan:' . self::DEF)->severity, 'prices2 is another name');
        $this->assertNull(self::find($findings, 'plan:' . self::DEF2), 'names match as {import} finds them');
        $this->assertNull(self::find($findings, 'plan:' . self::DEF3), 'by its id');
        $this->assertNotContains('condition', self::plannedTaskProperties(), 'the condition never starts an import');
    }

    /** a task counts as the import detail lists it: {import(x)} does not reach the import x when it
     *  runs, {import} trims its parameters */
    public function test_plannedAsTheDetailListsIt(): void
    {
        self::definition(self::DEF, 'prices', ['enabled' => '1']);
        self::definition(self::DEF2, 'rates', ['enabled' => '1']);
        self::plannedTask('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1', self::SLICE, '{import(prices)} {import:  rates :changed}');

        $findings = self::findings(time());

        $this->assertNotNull(self::find($findings, 'plan:' . self::DEF), 'not planned by {import(prices)}');
        $this->assertNull(self::find($findings, 'plan:' . self::DEF2), 'planned by {import:  rates :changed}');
        $this->assertSame([], Definition::fresh(self::DEF)?->plannedTasks(time()), 'the detail lists no task');
        $this->assertCount(1, Definition::fresh(self::DEF2)?->plannedTasks(time()) ?? []);
    }

    /** the tasks and the queue are read once, whatever number of imports */
    public function test_plannedTasksReadOnce(): void
    {
        foreach ([self::DEF, self::DEF2, self::DEF3] as $i => $id) {
            self::definition($id, "import $i", ['enabled' => '1']);
        }
        self::plannedTask('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1', self::SLICE, '{import:import 1}');
        TableDb::$log = [];

        self::findings(time());

        $this->assertCount(1, array_filter(TableDb::$log, static fn(string $sql): bool => str_contains($sql, 'FROM `toexecute`') && str_contains($sql, 'Plannedtask_')));
        $this->assertCount(1, array_filter(TableDb::$log, static fn(string $sql): bool => str_contains($sql, 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1') && str_contains($sql, "'task'")));
    }

    public function test_duplicateNames_warning(): void
    {
        self::definition(self::DEF, 'Prices');
        self::definition(self::DEF2, 'prices ');
        // the same name in another slice is no clash
        self::definition(self::DEF3, 'rates');
        self::definition(self::GONE, 'rates', slice: self::SLICE2);

        $findings = array_values(array_filter(self::findings(time()), static fn(Finding $f): bool => str_starts_with($f->key, 'names:')));

        $this->assertCount(1, $findings);
        $this->assertSame([Finding::WARNING, 2], [$findings[0]->severity, $findings[0]->count]);
    }

    public function test_incompleteCatalog_infoOnlyWhenNoCheckRuns(): void
    {
        self::definition(self::DEF, 'prices');
        $catalog = Catalog::create(self::DEF);
        $catalog->addPage(new CatalogPage([new CatalogEntry('a', 'Alpha')], 'page2'), time());
        $catalog->store();
        $run = self::importRun(self::DEF, ['mode' => 'check']);

        $this->assertNull(self::find(self::findings(time()), 'catalog:' . self::DEF), 'the check fills it now');

        self::storedSet($run, 'state', Run::ENDED);
        $this->assertSame(Finding::INFO, self::only(self::findings(time()), 'catalog:' . self::DEF)->severity);
    }

    public function test_foreignRecordAtOwnedId_error(): void
    {
        self::definition(self::DEF, 'prices');
        self::definition(self::DEF2, 'rates');
        self::definition(self::DEF3, 'fees');
        // another class at the place of the catalog; the stream state of another definition
        self::storedSet(Catalog::idOf(self::DEF), 'aa_type', 'AA_Plannedtask');
        self::storedSet(Catalog::idOf(self::DEF), 'aa_owner', self::DEF);
        self::storedSet(StreamState::idOf(self::DEF2), 'aa_type', StreamState::class);
        self::storedSet(StreamState::idOf(self::DEF2), 'aa_owner', self::DEF);
        self::storedSet(StreamState::idOf(self::DEF2), 'check', '{"time": 1');
        // half written - the next store saves it whole
        self::storedSet(StreamState::idOf(self::DEF3), 'aa_type', StreamState::class);

        $findings = self::findings(time());

        $this->assertSame(Finding::ERROR, self::only($findings, 'foreign:' . self::DEF)->severity);
        $this->assertSame(Finding::ERROR, self::only($findings, 'foreign:' . self::DEF2)->severity);
        $this->assertNull(self::find($findings, 'foreign:' . self::DEF3));
        $this->assertNull(self::find($findings, 'checkdata:' . self::DEF2), 'what a foreign record holds is not the import\'s');
    }

    /** found among the constants by name - no source is asked which keys it needs */
    public function test_damagedKey_errorByConstantName(): void
    {
        $constants = ['AA_APIKEY_NZIS' => "abc\r\nX-Injected: s3cr3t", 'AA_APIKEY_CSU' => 'fine', 'DB_PASSWORD' => "a\nb"];

        $finding = self::only(self::findings(time(), $constants), 'key:AA_APIKEY_NZIS');

        $this->assertSame(Finding::ERROR, $finding->severity);
        $this->assertStringContainsString('AA_APIKEY_NZIS', self::text($finding));
        $this->assertStringNotContainsString('s3cr3t', self::text($finding));
        $this->assertCount(1, self::findings(time(), $constants));
    }

    // ------------------------------------------------------------ log, orphans, all good

    public function test_loggedImportFailures_warning(): void
    {
        self::definition(self::DEF, 'prices');
        $now = time();
        self::logged($now - 120, Definition::ERROR_EVENT, 'Import_' . self::GONE, '#' . self::GONE . ' RuntimeException <b>x</b>:1');
        self::logged($now - 60, Toexecute::FAILED_EVENT, 'Import_' . self::DEF, '#' . str_repeat('a', 32) . ' RuntimeException src/Import/Run.php:12');
        // not imports: a task of the queue, a failure older than a week
        self::logged($now - 60, Toexecute::FAILED_EVENT, 'AA_Plannedtask', '#12 RuntimeException x.php:1');
        self::logged($now - 8 * self::DAY, Definition::ERROR_EVENT, 'Import_' . self::DEF, '#' . self::DEF . ' AUTH');

        $finding = self::only(self::findings($now), 'logged');

        $this->assertSame([Finding::WARNING, 2, $now - 120, $now - 60], [$finding->severity, $finding->count, $finding->since, $finding->last]);
        $this->assertStringContainsString('&lt;b&gt;', self::text($finding), 'log rows are escaped');
        $this->assertSame('prices', $finding->evidence[0][_m('Import')], 'the newest first, the definition by its name');
    }

    public function test_loggedImportFailures_fiveNewestShown(): void
    {
        $now = time();
        foreach (range(7, 1) as $ago) {
            self::logged($now - $ago, Definition::ERROR_EVENT, 'Import_' . self::GONE, "#$ago");
        }

        $finding = self::only(self::findings($now), 'logged');

        $this->assertSame(7, $finding->count);
        $this->assertSame(['#1', '#2', '#3', '#4', '#5'], array_column($finding->evidence, _m('Error')));
    }

    public function test_orphans_infoAndRepair(): void
    {
        self::definition(self::GONE, 'deleted');
        $done    = self::importRun(self::GONE, ['state' => Run::DONE, 'finished' => (string) (time() - 60)]);
        $running = self::importRun(self::GONE);
        self::checked(self::GONE, new CheckResult(true, '', ''), time());
        $catalog = Catalog::create(self::GONE);
        $catalog->addPage(new CatalogPage([new CatalogEntry('a', 'Alpha')]), time());
        $catalog->store();
        self::deleteObject(self::GONE);
        // a live import keeps what it has
        self::definition(self::DEF, 'prices');
        $kept = self::importRun(self::DEF, ['state' => Run::DONE, 'finished' => (string) (time() - 60)]);
        $queued = self::importRun(self::DEF);
        self::checked(self::DEF, new CheckResult(true, '', ''), time());

        $finding = self::only(self::findings(time()), 'orphans');
        $this->assertSame([Finding::INFO, 4, true], [$finding->severity, $finding->count, $finding->repairable]);

        $check = new CheckImports();
        $this->assertTrue($check->repair());

        foreach ([$done, $running, StreamState::idOf(self::GONE), Catalog::idOf(self::GONE)] as $id) {
            $this->assertSame([], self::stored($id));
        }
        $this->assertSame([], array_values(array_filter(self::queue(), static fn(array $r): bool => $r['selector'] === 'Import_' . self::GONE)), 'nor its queued step');
        $this->assertStringContainsString('2 runs', $check->report());
        $this->assertStringContainsString('2 stream states and catalogs', $check->report());
        $this->assertSame(Run::DONE, self::stored($kept)['state'] ?? '');
        $this->assertSame(Run::RUNNING, self::stored($queued)['state'] ?? '');
        $this->assertNotSame([], self::stored(StreamState::idOf(self::DEF)));
        $this->assertNull(self::find(self::findings(time()), 'orphans'));
    }

    /** a step of a deleted import still working may write items - a warning until its lease ends */
    public function test_orphans_warningWhileAStepWorks(): void
    {
        self::definition(self::GONE, 'deleted');
        $run = self::importRun(self::GONE, ['lease' => (string) (time() + 60), 'lease_tok' => 'working']);
        self::deleteObject(self::GONE);

        $this->assertSame(Finding::WARNING, self::only(self::findings(time()), 'orphans')->severity);
        $this->assertSame(Finding::INFO, self::only(self::findings(time() + 120), 'orphans')->severity);

        (new CheckImports())->repair();
        $this->assertSame([], self::stored($run), 'deleted as deleting the definition deletes it - the step writes nothing back');
    }

    /** a definition stored after the check read the objects keeps what it owns */
    public function test_repair_checksDefinitionAgain(): void
    {
        self::definition(self::DEF, 'prices');
        $run = self::importRun(self::DEF, ['state' => Run::DONE, 'finished' => (string) (time() - 60)]);
        self::checked(self::DEF, new CheckResult(true, '', ''), time());
        $tables          = TableDb::$answer;
        TableDb::$answer = static fn(string $sql): ?array => str_contains($sql, "value IN ('AA\\\\Import\\\\Definition'")
            ? array_map(static fn(array $r): array => ['object_id' => $r['object_id'], 'value' => $r['value']], array_values(array_filter(TableDb::rows('object_text'), static fn(array $r): bool => ($r['property'] === 'aa_type') && in_array($r['value'], [Run::class, StreamState::class], true))))
            : $tables($sql);
        $this->assertNotNull(self::find(self::findings(time()), 'orphans'), 'the definition is missed');

        (new CheckImports())->repair();

        $this->assertSame(Run::DONE, self::stored($run)['state'] ?? '');
        $this->assertNotSame([], self::stored(StreamState::idOf(self::DEF)));
    }

    public function test_allGood_ok(): void
    {
        self::definition(self::DEF, 'prices', ['enabled' => '1']);
        self::plannedTask('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1', self::SLICE, '{import:' . self::DEF . '}');
        self::importRun(self::DEF, ['state' => Run::DONE, 'finished' => (string) time(), 'counts' => '{"read":5,"new":5}']);
        self::checked(self::DEF, new CheckResult(true, '', ''), time());
        $catalog = Catalog::create(self::DEF);
        $catalog->addPage(new CatalogPage([new CatalogEntry('a', 'Alpha')]), time());
        $catalog->store();

        $this->assertSame([Finding::OK], self::severities(self::findings(time())));
    }

    public function test_noImports_ok(): void
    {
        $this->assertSame([Finding::OK], self::severities(self::findings(time())));
    }

    public function test_findings_carryProgressLinkAndAdvice(): void
    {
        self::definition(self::DEF, '<b>prices</b>');
        $run = self::importRun(self::DEF, ['state' => Run::ERROR, 'error_code' => 'NO_PROGRESS']);

        $finding = self::only(self::findings(time()), "error:$run");

        $this->assertStringContainsString('Continue', $finding->advice);
        $this->assertStringEndsWith('admin/se_dataimport.php?change_id=' . self::SLICE . '&id=' . self::DEF, array_values($finding->links)[0]);
        $this->assertContains(safe('<b>prices</b>'), array_values($finding->evidence[0]), 'stored names are escaped');
    }

    /**
     * CHECK_BASIC runs on every load of State Check: object_text only through its (property, value)
     * and object_id indexes and only the listed small properties - never the streams or entries of a
     * definition - the log through (type, time). As many reads for three imports as for one.
     */
    public function test_basic_readsOnlyThroughIndexes(): void
    {
        $selects = [];
        foreach ([self::DEF, self::DEF2, self::DEF3] as $def) {
            self::definition($def, "import $def", ['enabled' => '1']);
            self::importRun($def, ['state' => Run::DONE, 'counts' => '{"errors":1}']);
            self::importRun($def, ['state' => Run::ERROR]);
            self::checked($def, new CheckResult(false, 'AUTH', ''), time());
            $catalog = Catalog::create($def);
            $catalog->addPage(new CatalogPage([new CatalogEntry('a', 'Alpha')]), time());
            $catalog->store();
            \AA::Contentcache()->clear_soft();
            TableDb::$log = [];

            self::findings(time());

            $selects[] = array_values(array_filter(TableDb::$log, static fn(string $sql): bool => str_starts_with($sql, 'SELECT')));
        }
        $this->assertSame(count($selects[0]), count($selects[2]));
        foreach ($selects[2] as $sql) {
            $this->assertMatchesRegularExpression('/ FROM (`object_text` WHERE (property = \'\w+\' AND value |object_id IN \(.*\) AND property IN \()|`log` WHERE type IN |information_schema\.tables WHERE )/', $sql);
        }
        $this->assertSame([], TableDb::writes(), 'a check writes nothing');
    }

    // ------------------------------------------------------------ helpers

    /** @param array<string, mixed> $constants the defined constants the check looks at */
    private static function findings(int $now, array $constants = []): array
    {
        return CheckImports::findingsAt($now, $constants);
    }

    /** @param array<string, string> $props */
    private static function definition(string $id, string $name, array $props = [], string $slice = self::SLICE): void
    {
        $def = new Definition();
        $def->setNew($id, $slice, $name);
        foreach ($props + ['source' => self::SOURCE, 'settings' => '{}', 'enabled' => ''] as $property => $value) {
            $def->setProperty($property, $value);
        }
        self::assertSame($id, $def->save());
    }

    /** a run of $def as Service starts it, then $set behind its back
     *  @param array<string, string> $set */
    private static function importRun(string $def, array $set = []): string
    {
        $id = (string) DB_AA::transaction(static fn() => Run::start([
            'subject'     => $def,
            'user'        => self::USER,
            'position'    => RunCursor::START,
            'mode'        => $set['mode'] ?? 'run',
            'snapshot'    => '{}',
            'stream_keys' => '["s1"]',
            'trigger'     => 'manual',
        ]))->getId();
        foreach ($set as $property => $value) {
            self::storedSet($id, $property, $value);
        }
        return $id;
    }

    private static function checked(string $def, CheckResult $r, int $time): void
    {
        $state = StreamState::forDefinition($def) ?? StreamState::create($def);
        $state->recordCheck($r, $time);
        $state->store();
    }

    private static function plannedTask(string $id, string $slice, string $task): void
    {
        self::storedSet($id, 'aa_type', 'AA_Plannedtask');
        self::storedSet($id, 'aa_owner', $slice);
        self::storedSet($id, 'task', $task);
        self::storedSet($id, 'condition', '{import:prices}');
    }

    /** @return list<string> the properties CheckImports read of the planned tasks */
    private static function plannedTaskProperties(): array
    {
        \AA::Contentcache()->clear_soft();
        TableDb::$log = [];
        self::findings(time());
        $read = [];
        foreach (TableDb::$log as $sql) {
            if (str_contains($sql, 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1') && preg_match("/property IN \\(([^)]*)\\)/", $sql, $m)) {
                $read = array_map(static fn(string $p): string => trim($p, " '"), explode(',', $m[1]));
            }
        }
        return ['aa_type', ...$read];
    }

    private static function deleteObject(string $id): void
    {
        TableDb::$tables['object_text'] = array_values(array_filter(TableDb::rows('object_text'), static fn(array $r): bool => $r['object_id'] !== $id));
    }

    private static function logged(int $time, string $type, string $selector, string $params): void
    {
        TableDb::$tables['log'][] = ['id' => count(TableDb::rows('log')) + 1, 'time' => $time, 'user' => '', 'type' => $type, 'selector' => $selector, 'params' => $params];
    }

    /**
     * The log as MySQL answers CheckImports - TableDb reads no >= nor LIKE: the rows of the types in
     * `type IN (...)`, `time >= n` and a selector beginning with Import_; in the order of the table
     * unless the statement asks for the newest first, and as many as its LIMIT.
     * @return list<array<string, mixed>>|null null for any other statement
     */
    private static function logAnswer(string $sql): ?array
    {
        if (!str_contains($sql, 'FROM `log`')) {
            return null;
        }
        self::assertMatchesRegularExpression("/WHERE type IN \\('[A-Z_]+','[A-Z_]+'\\) AND time >= \\d+ AND selector LIKE 'Import\\\\_%'/", $sql);
        preg_match("/type IN \\('([A-Z_]+)','([A-Z_]+)'\\) AND time >= (\\d+)/", $sql, $m);
        $rows = array_values(array_filter(TableDb::rows('log'), static fn(array $r): bool => in_array($r['type'], [$m[1], $m[2]], true) && ($r['time'] >= (int) $m[3]) && str_starts_with((string) $r['selector'], 'Import_')));
        if (str_contains($sql, 'COUNT(*)')) {
            $times = array_column($rows, 'time');
            return [['cnt' => count($rows), 'first' => $times ? min($times) : null, 'last' => $times ? max($times) : null]];
        }
        if (str_contains($sql, 'ORDER BY time DESC')) {
            usort($rows, static fn(array $a, array $b): int => $b['time'] <=> $a['time']);
        }
        return preg_match('/LIMIT 0,(\d+)/', $sql, $limit) ? array_slice($rows, 0, (int) $limit[1]) : $rows;
    }

    /** @param Finding[] $findings */
    private static function find(array $findings, string $key): ?Finding
    {
        foreach ($findings as $finding) {
            if ($finding->key === $key) {
                return $finding;
            }
        }
        return null;
    }

    /** @param Finding[] $findings */
    private static function only(array $findings, string $key): Finding
    {
        $found = array_values(array_filter($findings, static fn(Finding $f): bool => $f->key === $key));
        self::assertCount(1, $found, "one finding $key among: " . implode(', ', array_map(static fn(Finding $f): string => $f->key, $findings)));
        return $found[0];
    }

    /** @param Finding[] $findings  @return list<string> */
    private static function severities(array $findings): array
    {
        return array_map(static fn(Finding $f): string => $f->severity, $findings);
    }

    /** everything the finding shows */
    private static function text(Finding $f): string
    {
        return implode("\n", [$f->title, $f->advice, ...array_map(static fn(array $row): string => implode("\n", array_keys($row)) . "\n" . implode("\n", $row), $f->evidence), ...array_keys($f->links), ...array_values($f->links)]);
    }
}
