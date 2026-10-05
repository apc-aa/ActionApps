<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\Definition;
use AA\Import\DetailPage;
use AA\Import\Registry;
use AA_Plannedtask;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Import\Fixtures\RunSteps;

require_once __DIR__ . '/fixtures/RunSteps.php';

/**
 * The overview of an import lists the planned tasks of its slice that call it, by its id or its name,
 * with the mode, the next start and whether the start is queued; "Schedule" opens the form of a new
 * planned task filled in for this import - only for a user who may manage the planned tasks.
 * Each test in its own process, as DetailPageTest: FakeSlice loads include/locsess.php.
 */
#[RunTestsInSeparateProcesses]
final class DetailScheduleTest extends TestCase
{
    use RunSteps {
        setUp as private stepsSetUp;
        tearDown as private stepsTearDown;
    }

    private const SOURCE = 'custom:service';
    private const TASK_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const TASK_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const TASK_C = 'cccccccccccccccccccccccccccccccc';
    private const TASK_D = 'dddddddddddddddddddddddddddddddd';
    private const TASK_E = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    protected function setUp(): void
    {
        $this->stepsSetUp();
        session_id('test-session');
        $dir = __DIR__ . '/fixtures/service';
        Registry::setCustomRootForTests(dirname($dir), $dir);
        Registry::sources();
        \ServiceSource::$streamKeys     = ['s1'];
        \ServiceSource::$refuse         = null;
        \ServiceSource::$failStreams    = null;
        \ServiceSource::$created        = [];
        \ServiceSource::$mapping        = null;
        \ServiceSource::$failProperties = null;
        \ServiceSource::$failMapping    = null;
        TableDb::$tables['module'] = [['id' => self::SLICE, 'type' => 'S'], ['id' => self::OTHER, 'type' => 'S']];
        \AA::$module_id = self::SLICE;
        self::perms(PS_FEEDING . PS_FORMS, PS_FEEDING . PS_FORMS);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        \AA::$module_id = null;
        session_id('');
        Registry::setCustomRootForTests(null);
        $this->stepsTearDown();
    }

    public function test_linkedTasks_byIdAndName(): void
    {
        self::definition('Prices');
        self::task(self::TASK_A, self::SLICE, 'Nightly prices', '{import:PRICES :changed}', '03:00');
        self::task(self::TASK_B, self::SLICE, 'Prices by id', 'x{import:' . self::DEF . '}', '04:00');
        self::queued('Plannedtask_' . self::TASK_B, self::NOW + 3600);

        $html = self::overview();

        $nextA = (new AA_Plannedtask(['time' => '03:00']))->nextStart(self::NOW);
        $this->assertMatchesRegularExpression('~<a href="/aa/admin/se_tasks\.php\?id=' . self::TASK_A . '&amp;module_id=' . self::SLICE . '"[^>]*>Nightly prices</a>[^<]*Run changed only[^<]*' . date('Y-m-d H:i', $nextA) . '[^<]*not queued~', $html);
        $this->assertMatchesRegularExpression('~<a href="/aa/admin/se_tasks\.php\?id=' . self::TASK_B . '&amp;module_id=' . self::SLICE . '"[^>]*>Prices by id</a>: Run, next ' . date('Y-m-d H:i', self::NOW + 3600) . ', queued</li>~', $html);
        $this->assertStringNotContainsString('No planned task', $html);
    }

    /** another import of the slice, an import of the name in another slice, a task with no {import} */
    public function test_linkedTasks_otherImportsNotListed(): void
    {
        self::definition('Prices');
        self::task(self::TASK_C, self::OTHER, 'Prices of the other slice', '{import:prices}', '05:00');
        self::task(self::TASK_D, self::SLICE, 'Rents', '{import:rents} {import}', '01:00');
        self::task(self::TASK_E, self::SLICE, 'Clock', '{now}', '02:00');

        $html = self::overview();

        foreach (['Prices of the other slice', 'Rents', 'Clock'] as $name) {
            $this->assertStringNotContainsString($name, $html);
        }
        $this->assertStringContainsString('No planned task of the slice calls this import.', $html);
    }

    /** the link fills in exactly what the form of a new task takes from a link - a name stripped of
     *  what a task name may not hold, and never an expression other than this import's own call */
    public function test_scheduleLink_prefill(): void
    {
        self::definition('Ceny <b>"A&B"</b> {user:x}');

        $html = self::overview();

        $this->assertSame(1, preg_match('~<a href="([^"]*se_tasks\.php[^"]*)"[^>]*>Schedule</a>~', $html, $m), 'a link, not a form');
        $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        $this->assertStringStartsWith('/aa/admin/se_tasks.php?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame([
            'module_id' => self::SLICE,
            'add'       => '1',
            'prefill'   => ['aa_name' => 'Ceny b A B /b user:x', 'task' => '{import:' . self::DEF . ':changed}', 'time' => '03:00'],
        ], $query);
        $this->assertSame($query['prefill'], AA_Plannedtask::formPrefill($query['prefill']), 'the form takes every value');
    }

    public function test_scheduleDisabledWithoutForms(): void
    {
        self::definition('Prices');
        self::task(self::TASK_A, self::SLICE, 'Nightly prices', '{import:prices}', '03:00');
        self::perms(PS_FEEDING, PS_FEEDING);
        self::task(self::TASK_B, self::SLICE, 'Morning prices', '{import:prices:changed}', '02:00');

        $html = self::overview();

        $this->assertStringNotContainsString('se_tasks.php', $html, 'neither the form nor a task the user cannot open');
        foreach (['Nightly prices', 'Morning prices', 'Run changed only'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html, 'the tasks are the business of who manages them');
        }
        $next = (new AA_Plannedtask(['time' => '02:00']))->nextStart(self::NOW);
        $this->assertStringContainsString('Planned tasks: 2, next ' . date('Y-m-d H:i', $next), $html);
        $this->assertMatchesRegularExpression('~<button type="button" disabled="disabled" title="Planned tasks need the Forms permission">Schedule</button>~', $html);
        $this->assertStringContainsString('<span class="aa-import-why">Planned tasks need the Forms permission</span>', $html);
    }

    public function test_taskNameEscaped(): void
    {
        self::definition('Prices');
        self::task(self::TASK_A, self::SLICE, '<script>alert(1)</script> {user:password}', '{import:prices:check}', '03:00');

        $html = self::overview();

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; {user:password}', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('Check source', $html);
    }

    /** a task calling two imports shows the mode it calls this one with */
    public function test_linkedTasks_onlyThisImportsMode(): void
    {
        self::definition('Prices');
        self::task(self::TASK_A, self::SLICE, 'Both', '{import:rents:changed}{import:prices:check}', '03:00');

        $this->assertMatchesRegularExpression('~>Both</a>: Check source, next ~', self::overview());
    }

    /** each mode once, in the order the task calls them, whatever its case */
    public function test_linkedTasks_modesDeduplicatedAndLowercased(): void
    {
        self::definition('Prices');
        self::task(self::TASK_A, self::SLICE, 'Twice', '{import:prices:CHANGED}{import:Prices:changed}{import:prices}{import:' . self::DEF . ':run}', '03:00');

        $this->assertMatchesRegularExpression('~>Twice</a>: Run changed only, Run, next ~', self::overview());
    }

    /** soonest first, a task with no time last */
    public function test_linkedTasks_soonestFirst(): void
    {
        self::definition('Prices');
        self::task(self::TASK_A, self::SLICE, 'Late', '{import:prices}', '05:00');
        self::task(self::TASK_B, self::SLICE, 'Untimed', '{import:prices}', '');
        self::task(self::TASK_C, self::SLICE, 'Early', '{import:prices}', '04:00');

        $html = self::overview();

        $this->assertMatchesRegularExpression('~>Early</a>.*>Late</a>.*>Untimed</a>: Run, no time set, not queued~s', $html);
    }

    /** {import} trims its parameters - so does the list */
    public function test_linkedTasks_referenceTrimmed(): void
    {
        self::definition('Prices');
        self::task(self::TASK_A, self::SLICE, 'Spaced', '{import: prices :changed}', '03:00');

        $this->assertMatchesRegularExpression('~>Spaced</a>: Run changed only, next ~', self::overview());
    }

    /** {import(prices)} does not reach the import named prices when it runs */
    public function test_linkedTasks_parenthesisFormNotListed(): void
    {
        self::definition('Prices');
        self::task(self::TASK_A, self::SLICE, 'Parenthesis', '{import(prices)}', '03:00');

        $this->assertStringNotContainsString('Parenthesis', self::overview());
        $this->assertTrue((new AA_Plannedtask(['task' => '{import(prices)}']))->usesImport(), 'saving such a task still asks for feeding');
    }

    public function test_linkedTasks_modeByExpression(): void
    {
        self::definition('Prices');
        self::task(self::TASK_A, self::SLICE, 'Computed', '{import:prices:{var:m}}', '03:00');

        $html = self::overview();

        $this->assertMatchesRegularExpression('~>Computed</a>: mode set by an expression, next ~', $html);
        $this->assertStringNotContainsString('{var', $html);
    }

    /** "0" is a mode {import} refuses, not the default */
    public function test_linkedTasks_modeZeroIsUnknown(): void
    {
        self::definition('Prices');
        self::task(self::TASK_A, self::SLICE, 'Zero', '{import:prices:0}', '03:00');

        $this->assertMatchesRegularExpression('~>Zero</a>: unknown mode 0, next ~', self::overview());
    }

    /** an import called inside the mode of another call is listed too */
    public function test_linkedTasks_nestedCall(): void
    {
        self::definition('Prices');
        self::task(self::TASK_A, self::SLICE, 'Nested', '{import:rents:x{import:prices:check}}', '03:00');

        $this->assertMatchesRegularExpression('~>Nested</a>: Check source, next ~', self::overview());
    }

    /** a planned start of a disabled import does nothing - the list must not suggest otherwise */
    public function test_linkedTasks_importOff(): void
    {
        self::definition('Prices');
        self::storedSet(self::DEF, 'enabled', '');
        self::task(self::TASK_A, self::SLICE, 'Nightly prices', '{import:prices}', '03:00');

        $this->assertMatchesRegularExpression('~>Nightly prices</a>: Run, next [^<]*, not queued \(import off\)</li>~', self::overview());
    }

    /** the id goes into the link to the task - an object that is not a task is no task */
    public function test_linkedTasks_taskIdNotAnIdSkipped(): void
    {
        self::definition('Prices');
        self::task('x"><b>1', self::SLICE, 'Odd', '{import:prices}', '03:00');

        $html = self::overview();

        $this->assertStringNotContainsString('Odd', $html);
        $this->assertStringNotContainsString('<b>1', $html);
    }

    public function test_taskWithoutNameShowsId(): void
    {
        self::definition('Prices');
        self::task(self::TASK_A, self::SLICE, '', '{import:prices}', '03:00');

        $this->assertMatchesRegularExpression('~>' . self::TASK_A . '</a>: Run, next ~', self::overview());
    }

    /** the form takes a name of up to 100 characters; a name with nothing left gives the id */
    public function test_scheduleLink_nameCutOrMadeUp(): void
    {
        self::definition(str_repeat('ab ', 60));
        $this->assertSame(str_repeat('ab ', 33) . 'a', self::prefill()['aa_name']);
        $this->assertSame(100, mb_strlen(self::prefill()['aa_name']));

        self::storedSet(self::DEF, 'aa_name', '<{}>');
        \AA::Contentcache()->clear_soft();
        $this->assertSame('Import ' . self::DEF, self::prefill()['aa_name']);
    }

    // ------------------------------------------------------------ helpers

    private static function overview(): string
    {
        $page = DetailPage::forRequest(self::SLICE, self::DEF, AuthGetUid()) ?? throw new \LogicException('No page');
        return $page->overviewHtml(self::NOW);
    }

    /** @return array<string, string> the prefill of the Schedule link */
    private static function prefill(): array
    {
        preg_match('~<a href="([^"]*se_tasks\.php[^"]*)"[^>]*>Schedule</a>~', self::overview(), $m);
        parse_str((string) parse_url(html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5), PHP_URL_QUERY), $query);
        return $query['prefill'] ?? [];
    }

    private static function definition(string $name): void
    {
        $def = new Definition();
        $def->setNew(self::DEF, self::SLICE, $name);
        foreach (['source' => self::SOURCE, 'mapping' => self::NO_PARAM_MAPPING, 'key_fields' => '["relation........"]', 'store_mode' => 'update/insert', 'enabled' => '1'] as $property => $value) {
            $def->setProperty($property, $value);
        }
        self::assertSame(self::DEF, $def->save());
    }

    /** a planned task of $slice, stored as its form stores it */
    private static function task(string $id, string $slice, string $name, string $task, string $time): void
    {
        foreach (['aa_type' => 'AA_Plannedtask', 'aa_owner' => $slice, 'aa_name' => $name, 'task' => $task, 'time' => $time, 'shift' => '0'] as $property => $value) {
            TableDb::$tables['object_text'][] = ['id' => 800000 + count(TableDb::rows('object_text')), 'object_id' => $id, 'property' => $property, 'priority' => 0, 'value' => $value, 'flag' => null];
        }
    }

    private static function queued(string $selector, int $at): void
    {
        TableDb::$tables['toexecute'][] = ['id' => 700000 + count(TableDb::rows('toexecute')), 'created' => self::NOW, 'execute_after' => $at, 'aa_user' => '', 'priority' => 100, 'selector' => $selector, 'object' => '', 'params' => ''];
    }
}
