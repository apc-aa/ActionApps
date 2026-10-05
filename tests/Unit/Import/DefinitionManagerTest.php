<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\Definition;
use AA\Import\Registry;
use AA\Import\Run;
use AA\Import\RunCursor;
use AA\IO\DB\DB_AA;
use AA\Managed_Object;
use AA_Aliasgenerator_Object;
use AA_Content;
use AA_Field;
use AA_Form;
use AA_Item;
use AA_Value;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Later\Fixtures\BatchRunTables;
use Tests\Unit\Legacy\Fixtures\FakeFields;
use Tests\Unit\Legacy\Fixtures\FakeSlice;

require_once __DIR__ . '/../Later/Fixtures/BatchRunTables.php';

/**
 * The Manager of a slice's imports lists the name, source and "enabled" of each definition with the
 * state of its newest run, its last run and its next planned start - never the settings, the mapping
 * or the expressions. Each test in its own process: FakeSlice loads include/locsess.php, whose getDB()
 * collides with the stub tests/Unit/IO/DB loads when that directory runs first.
 */
#[RunTestsInSeparateProcesses]
final class DefinitionManagerTest extends TestCase
{
    use BatchRunTables {
        setUp as private tablesSetUp;
        tearDown as private tablesTearDown;
    }

    private const SLICE  = '5b5b5b5b5b5b5b5b5b5b5b5b5b5b5b5b';
    private const OTHER  = '6c6c6c6c6c6c6c6c6c6c6c6c6c6c6c6c';
    private const DEF    = '0123456789abcdef0123456789abcdef';
    private const MAPPED = 'custom:definitionmapped';
    private const PLAIN  = 'custom:definitionplain';
    /** in the hidden properties - the list must never show it */
    private const SECRET_MARK = 'HIDDEN-SETTING-7781';

    protected function setUp(): void
    {
        $this->tablesSetUp();
        session_id('test-session');   // FormToken keys the tokens of the row buttons with the session
        $sources = __DIR__ . '/fixtures/definition';
        Registry::setCustomRootForTests(dirname($sources), $sources);
        Registry::sources();
        foreach ([self::SLICE, self::OTHER] as $slice_id) {
            $slice = new FakeSlice($slice_id);
            $slice->setFields(new FakeFields($slice_id, [new AA_Field([
                'id' => 'headline........', 'slice_id' => $slice_id, 'text_stored' => 1, 'in_item_tbl' => '', 'input_show_func' => 'fld',
                'input_insert_func' => 'qte', 'input_validate' => '', 'content_edit' => 0, 'html_default' => 0, 'multiple' => 0,
            ])]));
        }
        $GLOBALS['permission_uid'] = AuthGetUid();
        $GLOBALS['permission_to']  = ['aa' => [AA_ID => ''], 'slice' => [self::SLICE => PS_FEEDING, self::OTHER => PS_FEEDING]];
        \AA::$module_id = self::SLICE;
    }

    protected function tearDown(): void
    {
        session_id('');
        \AA::$module_id = null;
        $this->tablesTearDown();
        Registry::setCustomRootForTests(null);
        unset($GLOBALS['permission_uid'], $GLOBALS['permission_to']);
    }

    // ------------------------------------------------------------ status

    /** @return iterable<string, array{?array<string, string>, string}> the run's stored properties (null = no run), the word */
    public static function runStates(): iterable
    {
        $now = 1_900_000_000;
        yield 'no run'                   => [null, 'Never ran'];
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
        yield 'ended'                    => [['state' => Run::ENDED], 'Ended'];
    }

    /** @param ?array<string, string> $props */
    #[DataProvider('runStates')]
    public function test_status_wordsForEachRunState(?array $props, string $word): void
    {
        $run = ($props === null) ? null : new Run($props);

        self::assertSame($word, Definition::statusWord($run, 1_900_000_000));
    }

    // ------------------------------------------------------------ the row

    public function test_rowHtml_hasDetailLink(): void
    {
        $id  = self::definition('prices');
        $row = self::rowFormat();

        self::assertStringContainsString('admin/se_dataimport.php?', $row);
        self::assertMatchesRegularExpression('~se_dataimport\.php\?[^"]*change_id=_#AA_OWNER~', $row);
        self::assertMatchesRegularExpression('~se_dataimport\.php\?[^"]*id=_#AA_ID___~', $row);

        $html = self::rendered($id);
        self::assertMatchesRegularExpression('~href="[^"]*se_dataimport\.php\?[^"]*change_id=' . self::SLICE . '[^"]*"~', $html);
        self::assertMatchesRegularExpression('~href="[^"]*se_dataimport\.php\?[^"]*\bid=' . $id . '~', $html);
        // Edit, Detail, Run, Delete - Run and Delete are POST buttons, the others links that only read
        self::assertMatchesRegularExpression('~>Edit<.*>Detail<.*data-aa-akce="Run".*data-aa-akce="DeleteObject"~s', $html);
    }

    public function test_rowHtml_escapesDefinitionName(): void
    {
        $id = self::definition('<b>prices</b>');

        $html = self::rendered($id);

        self::assertStringContainsString('&lt;b&gt;prices&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>prices', $html);
    }

    public function test_rowHtml_showsNoSettingsNorMapping(): void
    {
        $id = self::definition('prices');
        foreach (['settings', 'mapping', 'key_fields', 'validate', 'after_stream'] as $property) {
            self::storedSet($id, $property, '{"x":"' . self::SECRET_MARK . '"}');
        }

        preg_match_all('/_#[A-Z0-9_]{8}/', self::rowFormat(), $aliases);
        self::assertSame([], array_values(array_diff(array_unique($aliases[0]), ['_#AA_ID___', '_#AA_OWNER', '_#AA_OW_NM', '_#AA_NAME_'])), 'only the id, the owner and the name');
        self::assertStringNotContainsString(self::SECRET_MARK, self::rendered($id));
    }

    public function test_rowHtml_showsSourceEnabledStatusAndLastRun(): void
    {
        $id  = self::definition('prices');
        $run = self::startRun($id, ['mode' => 'check']);

        $html = self::rendered($id);

        self::assertStringContainsString('<td>Mapped</td>', $html, 'the name of the source');
        self::assertStringContainsString('title="' . self::SLICE . '"', $html, 'the slice - the overview lists the imports of all');
        self::assertStringContainsString('>Yes<', $html);
        self::assertStringContainsString('Queued', $html, 'the run\'s first step waits in the queue');
        self::assertStringContainsString(date('Y-m-d H:i', (int) self::stored($run)['started']), $html);
        self::assertStringContainsString('check', $html);
        self::assertStringContainsString('by hand', $html);
    }

    public function test_rowHtml_neverRan(): void
    {
        $html = self::rendered(self::definition('prices'));

        self::assertStringContainsString('Never ran', $html);
        self::assertStringContainsString('Not planned', $html);
    }

    /** the newest run decides, by its start */
    public function test_status_ofNewestRun(): void
    {
        $id  = self::definition('prices');
        $old = self::startRun($id);
        self::storedSet($old, 'state', Run::ERROR);
        $new = self::startRun($id);
        self::storedSet($new, 'started', (string) ((int) self::stored($old)['started'] + 10));
        self::storedSet($new, 'state', Run::DONE);

        self::assertStringContainsString('Finished', self::rendered($id));
        self::assertStringNotContainsString('Stopped by an error', self::rendered($id));
    }

    // ------------------------------------------------------------ next plan

    public function test_nextPlan_queuedTimeOfTaskCallingByIdOrName(): void
    {
        $id = self::definition('prices');
        self::task('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::SLICE, '{import:Prices:changed}', '03:00');
        self::task('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', self::SLICE, '{import:' . $id . '}', '04:00');
        self::task('cccccccccccccccccccccccccccccccc', self::OTHER, '{import:prices}', '05:00');   // another slice's import of the name
        self::task('dddddddddddddddddddddddddddddddd', self::SLICE, '{now}', '01:00');            // no import
        self::queued('Plannedtask_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::NOW + 7200);
        self::queued('Plannedtask_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', self::NOW + 3600);
        self::queued('Plannedtask_cccccccccccccccccccccccccccccccc', self::NOW + 60);
        self::queued('Plannedtask_dddddddddddddddddddddddddddddddd', self::NOW + 30);

        self::assertSame(self::NOW + 3600, Definition::fresh($id)?->nextPlanned(self::NOW));
        self::assertStringContainsString(date('Y-m-d H:i', self::NOW + 3600), self::rendered($id));
    }

    /** command names and import names compare without case, as {import} finds them */
    public function test_nextPlan_callMatchesWithoutCase(): void
    {
        $id = self::definition('prices');
        self::task('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::SLICE, '{IMPORT:PRICES }', '03:00');
        self::queued('Plannedtask_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::NOW + 60);

        self::assertSame(self::NOW + 60, Definition::fresh($id)?->nextPlanned(self::NOW));
    }

    /** the earliest of the rows queued for a task - a waiting "Run now" too */
    public function test_nextPlan_earliestQueuedRowAndRunNow(): void
    {
        $id = self::definition('prices');
        self::task('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::SLICE, '{import:prices}', '03:00');
        self::queued('Plannedtask_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::NOW + 600);
        self::queued('Plannedtask_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::NOW + 900);
        self::assertSame(self::NOW + 600, Definition::fresh($id)?->nextPlanned(self::NOW), 'the smaller of two rows');

        $other = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        self::task($other, self::SLICE, '{import:prices}', '03:00');
        self::queued("Plannedtask_$other:now", self::NOW + 5);
        \AA::Contentcache()->clear_soft();
        self::assertSame(self::NOW + 5, Definition::fresh($id)?->nextPlanned(self::NOW), 'a waiting Run now');
    }

    /** a time of day already past today comes tomorrow - the scheduler queues no time in the past */
    public function test_nextPlan_taskNotQueuedStartsAtItsNextTime(): void
    {
        $id  = self::definition('prices');
        $now = mktime(10, 0, 0, 10, 4, 2026);
        self::task('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::SLICE, '{import:prices}', '03:00');
        self::queued('Plannedtask_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $now - 60, 0);   // a row of priority 0 never runs

        self::assertSame(mktime(3, 0, 0, 10, 5, 2026), Definition::fresh($id)?->nextPlanned($now));

        self::task('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', self::SLICE, '{import:prices}', '23:00');
        \AA::Contentcache()->clear_soft();
        self::assertSame(mktime(23, 0, 0, 10, 4, 2026), Definition::fresh($id)?->nextPlanned($now), 'a time still ahead today');
    }

    /** the planned tasks of the installation are read once a request, whatever number of slices the list shows */
    public function test_nextPlan_tasksReadOnceForAllSlices(): void
    {
        $own   = self::definition('prices');
        $other = self::definition('rents', self::OTHER);
        self::task('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::SLICE, '{import:prices}', '03:00');
        self::task('cccccccccccccccccccccccccccccccc', self::OTHER, '{import:rents}', '05:00');
        self::queued('Plannedtask_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::NOW + 60);
        self::queued('Plannedtask_cccccccccccccccccccccccccccccccc', self::NOW + 90);
        \AA::Contentcache()->clear_soft();
        TableDb::$log = [];

        self::assertSame(self::NOW + 60, Definition::fresh($own)?->nextPlanned(self::NOW));
        self::assertSame(self::NOW + 90, Definition::fresh($other)?->nextPlanned(self::NOW));

        $reads = array_filter(TableDb::$log, static fn(string $sql): bool => str_contains($sql, 'FROM `toexecute`'));
        self::assertCount(1, $reads, 'one read of the queue for both slices');
    }

    /** a bare {import} names no import - not even one without a name */
    public function test_nextPlan_bareImportCallsNone(): void
    {
        $id = self::definition('prices');
        self::storedSet($id, 'aa_name', '');
        self::task('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::SLICE, '{import}', '03:00');
        self::queued('Plannedtask_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::NOW + 60);

        self::assertNull(Definition::fresh($id)?->nextPlanned(self::NOW));
    }

    public function test_nextPlan_disabledShowsOff(): void
    {
        $id = self::definition('prices');
        self::storedSet($id, 'enabled', '');
        self::task('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::SLICE, '{import:prices}', '03:00');

        $html = self::rendered($id);

        self::assertStringContainsString('>No<', $html);
        self::assertStringContainsString('Off', $html);
        self::assertStringNotContainsString('Not planned', $html);
    }

    // ------------------------------------------------------------ the cells

    public function test_cells_onlyForTheIdOfADefinition(): void
    {
        $content = new AA_Content();
        $content->setAaValue('aa_id', new AA_Value('not an id'));
        $content->setAaValue('source', new AA_Value(self::MAPPED));

        self::assertSame('', Definition::internal_expand($content, 'source'));
        self::assertSame('', Definition::internal_expand(null, 'status'));
    }

    public function test_cells_sourceOutOfReachShowsItsType(): void
    {
        $id = self::definition('prices');
        self::storedSet($id, 'source', 'custom:<b>gone</b>');

        self::assertStringContainsString('custom:&lt;b&gt;gone&lt;/b&gt;', self::rendered($id));
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function lastRuns(): iterable
    {
        yield 'planned'      => [['trigger' => 'plan:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'], 'planned'];
        yield 'on an event'  => [['trigger' => 'event:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'], 'on an event'];
        yield 'dry run'      => [['dry' => '1'], 'dry run, by hand'];
        yield 'changed only' => [['mode' => 'changed'], 'changed only, by hand'];
    }

    /** @param array<string, string> $props */
    #[DataProvider('lastRuns')]
    public function test_cells_lastRunSaysHowItRan(array $props, string $about): void
    {
        $id = self::definition('prices');
        self::startRun($id, $props);

        self::assertStringContainsString("<small>$about</small>", self::rendered($id));
    }

    // ------------------------------------------------------------ searchbar, form

    public function test_searchbar_onlyNameSourceEnabled(): void
    {
        $fields = Definition::getManagerConf()->getSettings()['searchbar']['fields'];

        self::assertSame(['aa_name', 'source', 'enabled'], array_keys(iterator_to_array($fields)));
    }

    public function test_form_existingOffersOnlyItsSource(): void
    {
        $id = self::definition('prices');

        $edit = AA_Form::factoryForm(Definition::class, $id, self::SLICE)->getRowsHtml(self::content($id));
        $new  = AA_Form::factoryForm(Definition::class, '', self::SLICE)->getRowsHtml((new AA_Content())->setOwnerId(self::SLICE));

        self::assertStringContainsString('value="' . self::MAPPED . '"', $edit);
        self::assertStringNotContainsString('value="' . self::PLAIN . '"', $edit, 'the source changes in the import detail');
        self::assertStringContainsString('value="' . self::PLAIN . '"', $new, 'a new import chooses any source');
    }

    /** the Manager opens the form of a definition of its own slice only */
    public function test_form_definitionOfOtherSliceRefused(): void
    {
        $own   = self::definition('prices');
        $other = self::definition('rents', self::OTHER);

        self::assertNull((new Managed_Object(Definition::class))->formRefusal($own, self::SLICE));
        self::assertIsString((new Managed_Object(Definition::class))->formRefusal($other, self::SLICE));
    }

    // ------------------------------------------------------------ helpers

    /** a definition of $slice called $name, stored as the form stores it; its id */
    private static function definition(string $name, string $slice = self::SLICE): string
    {
        $def = new Definition();
        $def->setNew(($slice === self::SLICE) ? self::DEF : 'fedcba9876543210fedcba9876543210', $slice, $name);
        $def->setProperty('source', self::MAPPED)->setProperty('enabled', '1');
        $def->applyMapping((new \DefinitionMappedSource())->defaultMapping());
        self::assertIsString($def->save());
        return (string) $def->getId();
    }

    /** @param array<string, string> $props  @return string a run of $defId, as Service starts it */
    private static function startRun(string $defId, array $props = []): string
    {
        $def = Definition::fresh($defId) ?? throw new \LogicException("No definition $defId");
        return (string) DB_AA::transaction(static fn() => Run::start($props + [
            'subject'     => $defId,
            'user'        => self::USER,
            'position'    => RunCursor::START,
            'mode'        => 'run',
            'snapshot'    => json_encode($def->snapshot(), JSON_THROW_ON_ERROR),
            'stream_keys' => '["s1"]',
            'trigger'     => 'manual',
        ]))->getId();
    }

    /** a planned task of $slice */
    private static function task(string $id, string $slice, string $task, string $time): void
    {
        foreach (['aa_type' => 'AA_Plannedtask', 'aa_owner' => $slice, 'aa_name' => "task $id", 'task' => $task, 'time' => $time, 'shift' => '0'] as $property => $value) {
            TableDb::$tables['object_text'][] = ['id' => 800000 + count(TableDb::rows('object_text')), 'object_id' => $id, 'property' => $property, 'priority' => 0, 'value' => $value, 'flag' => null];
        }
    }

    private static function queued(string $selector, int $at, int $priority = 100): void
    {
        TableDb::$tables['toexecute'][] = ['id' => 700000 + count(TableDb::rows('toexecute')), 'created' => self::NOW, 'execute_after' => $at, 'aa_user' => '', 'priority' => $priority, 'selector' => $selector, 'object' => '', 'params' => ''];
    }

    private static function rowFormat(): string
    {
        return (string) Definition::getManagerConf()->getSettings()['itemview']['format']['odd_row_format'];
    }

    /** the row of the Manager for definition $id, as the list shows it */
    private static function rendered(string $id): string
    {
        $item = new AA_Item(self::content($id), AA_Aliasgenerator_Object::getAliasesFromParams(['object_type' => Definition::class]));
        return $item->unalias(self::rowFormat());
    }

    /** the content AA_Object::getContent() gives the Manager - every stored property; its loading
     *  JOIN is more than TableDb reads */
    private static function content(string $id): AA_Content
    {
        $content = new AA_Content();
        foreach (self::stored($id) as $property => $value) {
            $content->setAaValue($property, new AA_Value($value));
        }
        $content->setAaValue('aa_id', new AA_Value($id));
        return $content;
    }
}
