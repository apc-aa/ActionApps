<?php

declare(strict_types=1);

namespace Tests\Unit\Manageraction;

use AA;
use AA\Managed_Object;
use AA\Manager;
use AA\Manageraction\Manageractions;
use AA\Manageraction\Plannedtask_Runnow;
use AA\Util\FormToken;
use AA\Util\LogOnce;
use AA_Plannedtask;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\FakeStore;
use Tests\Unit\Legacy\Fixtures\FormTarget;
use Tests\Unit\Legacy\Fixtures\RecordingDb;
use Tests\Unit\Legacy\Fixtures\SearchlessManager;

/**
 * "Run now" in the planned tasks Manager queues the task at once under a selector of its own, so the
 * scheduled run of the task stays as it was. It writes to the queue, so it runs only from a POST of
 * the page, and a task which imports data runs only for a user who may run the imports of the slice.
 * The queued row holds a copy of the task, so a second click waits for the first run and a saved
 * change of the task drops the copy.
 */
final class PlannedtaskRunnowTest extends TestCase
{
    private const MODULE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    private const OTHER  = 'b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1';
    private const TASK   = 'c6c6c6c6c6c6c6c6c6c6c6c6c6c6c6c6';
    private const IMPORT = 'c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7c7';
    private const COND   = 'c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8c8';
    private const VICTIM = 'd6d6d6d6d6d6d6d6d6d6d6d6d6d6d6d6';
    /** an object of another class */
    private const FORM   = 'e6e6e6e6e6e6e6e6e6e6e6e6e6e6e6e6';

    /** object id => [aa_owner, task, condition] as stored */
    private const TASKS = [
        self::TASK   => [self::MODULE, '{now}', ''],
        self::IMPORT => [self::MODULE, '{import:prices}', ''],
        self::COND   => [self::MODULE, '{now}', '{import:prices:check}'],
        self::VICTIM => [self::OTHER, '{now}', ''],
    ];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../Legacy/fixtures/object_form_fixtures.php';
    }

    protected function setUp(): void
    {
        session_id('test-session');   // FormToken keys its tokens with the session
        RecordingDb::reset();
        LogOnce::reset();
        $GLOBALS['spareDBs'] = [new RecordingDb()];
        AA::$module_id = self::MODULE;
        self::perms([self::MODULE => PS_FORMS, self::OTHER => '']);
        self::installTasks();
    }

    protected function tearDown(): void
    {
        session_id('');
        $GLOBALS['spareDBs'] = [];
        RecordingDb::reset();
        [$_GET, $_POST, $_REQUEST] = [[], [], []];
        unset($_SERVER['REQUEST_METHOD']);
        unset($GLOBALS['r_state'], $GLOBALS['permission_uid'], $GLOBALS['permission_to']);
        AA::$module_id = null;
    }

    public function test_perform_queuesWithOwnSelector(): void
    {
        $msg = self::act([self::TASK]);

        $this->assertSame('', $msg);
        $queued = RecordingDb::inserts('toexecute');
        $this->assertCount(1, $queued);
        $this->assertSame('Plannedtask_' . self::TASK . ':now', $queued[0]['selector']);
        $this->assertSame(100, $queued[0]['priority']);
        $this->assertSame([], self::queueDeletes(), 'the scheduled Plannedtask_<id> row stays');
    }

    public function test_perform_importTaskNeedsFeedingPermission(): void
    {
        $msg = self::act([self::IMPORT, self::COND]);

        $this->assertSame(2, substr_count($msg, '{import}'), 'one refusal for the task, one for the condition');
        $this->assertSame([], RecordingDb::inserts('toexecute'));
    }

    public function test_perform_importTaskRunsWithFeedingPermission(): void
    {
        self::perms([self::MODULE => PS_FORMS . PS_FEEDING, self::OTHER => '']);

        $msg = self::act([self::IMPORT]);

        $this->assertSame('', $msg);
        $this->assertSame(['Plannedtask_' . self::IMPORT . ':now'], array_column(RecordingDb::inserts('toexecute'), 'selector'));
    }

    public function test_perform_taskOfOtherModuleNotQueued(): void
    {
        $msg = self::act([self::VICTIM]);

        $this->assertStringContainsString('belong to a module you can not change', $msg);
        $this->assertSame([], RecordingDb::inserts('toexecute'));
    }

    public function test_perform_getRequestQueuesNothing(): void
    {
        $_GET     = ['akce' => 'Runnow', 'chb' => ['x' . self::TASK => ''], 'scr_st_Go' => '', 'scr_st_Mv' => ''];
        $_POST    = [];
        $_REQUEST = $_GET + ['srchbr_akce' => ''];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        self::manager()->performActions();

        $this->assertSame([], RecordingDb::inserts('toexecute'));
    }

    public function test_perform_allModulesNeedFeedingOnTaskOwner(): void
    {
        // the page of the other module lists the tasks of all modules; the feeding right there does not count
        AA::$module_id = self::OTHER;
        self::perms([self::MODULE => PS_FORMS, self::OTHER => PS_FORMS . PS_FEEDING]);

        $msg = self::act([self::IMPORT], self::manager(self::OTHER, true));

        $this->assertStringContainsString('{import}', $msg);
        $this->assertSame([], RecordingDb::inserts('toexecute'));
    }

    public function test_perform_allModulesQueuesTaskOfPermittedOwner(): void
    {
        AA::$module_id = self::OTHER;
        self::perms([self::MODULE => PS_FORMS, self::OTHER => PS_FORMS]);

        $this->assertSame('', self::act([self::TASK], self::manager(self::OTHER, true)));
        $this->assertSame(['Plannedtask_' . self::TASK . ':now'], array_column(RecordingDb::inserts('toexecute'), 'selector'));
    }

    public function test_perform_objectOfOtherClassNotQueued(): void
    {
        self::act([self::FORM]);

        $this->assertSame([], RecordingDb::inserts('toexecute'));
    }

    public function test_perform_managerOfOtherClassRefused(): void
    {
        $msg = self::act([self::FORM], self::manager(self::MODULE, false, FormTarget::class));

        $this->assertStringContainsString('does not work on this page', $msg);
        $this->assertSame([], RecordingDb::inserts('toexecute'));
    }

    public function test_perform_withoutFormsPermissionNothing(): void
    {
        self::perms([self::MODULE => PS_FEEDING, self::OTHER => '']);

        self::act([self::TASK]);

        $this->assertSame([], RecordingDb::inserts('toexecute'));
    }

    public function test_perform_alreadyWaitingNotQueuedAgain(): void
    {
        self::installTasks([['id' => 7, 'selector' => 'Plannedtask_' . self::TASK . ':now', 'priority' => 100, 'execute_after' => 1]]);

        $msg = self::act([self::TASK, self::COND]);

        $this->assertStringContainsString('already waiting in the queue', $msg);
        $this->assertSame([], RecordingDb::inserts('toexecute'), 'COND is refused for {import}, TASK waits');
    }

    public function test_perform_otherQueuedRowsDoNotBlock(): void
    {
        // the scheduled run and a Run now of another task
        self::installTasks([
            ['id' => 7, 'selector' => 'Plannedtask_' . self::TASK, 'priority' => 100, 'execute_after' => 1],
            ['id' => 8, 'selector' => 'Plannedtask_' . self::VICTIM . ':now', 'priority' => 100, 'execute_after' => 1],
        ]);

        $this->assertSame('', self::act([self::TASK]));
        $this->assertSame(['Plannedtask_' . self::TASK . ':now'], array_column(RecordingDb::inserts('toexecute'), 'selector'));
    }

    public function test_getButton_asksBeforeRun(): void
    {
        $code = (new Plannedtask_Runnow('Runnow'))->getButton('', '_#AA_ID___', self::MODULE)['Runnow'];

        $this->assertMatchesRegularExpression('/data-aa-confirm="[^"]+"/', $code);
    }

    public function test_aftersave_dropsWaitingRunNow(): void
    {
        $task = new AA_Plannedtask(['task' => '{now:Y}']);
        $task->setId(self::TASK)->setOwnerId(self::MODULE);

        $task->aftersave();

        $deletes = implode("\n", self::queueDeletes());
        $this->assertStringContainsString("'Plannedtask_" . self::TASK . "'", $deletes);
        $this->assertStringContainsString("'Plannedtask_" . self::TASK . ":now'", $deletes, 'the queued copy of the old task must not run');
    }

    /** @return array<string, array{string, string, bool}> task, condition, imports */
    public static function importExpressions(): array
    {
        return [
            'colon'          => ['{import:prices}', '', true],
            'upper case'     => ['{IMPORT:prices}', '', true],
            'parenthesis'    => ['{import(prices)}', '', true],
            'bare'           => ['{import}', '', true],
            'in condition'   => ['{now:Y}', '{Import:prices:check}', true],
            'other command'  => ['{importer:x}', '', false],
            'plain text'     => ['import prices', 'import:', false],
            'no import'      => ['{now:Y}', '{ifeq:1:1:1}', false],
        ];
    }

    #[DataProvider('importExpressions')]
    public function test_usesImport_recognizesImportCommand(string $task, string $condition, bool $imports): void
    {
        $this->assertSame($imports, (new AA_Plannedtask(['task' => $task, 'condition' => $condition]))->usesImport());
    }

    /** the first argument of each call - what State Check matches against an import's name or id */
    public function test_importCalls_firstArguments(): void
    {
        $this->assertSame(['prices', 'rates', '', 'a1b2'], AA_Plannedtask::importCalls("{import:prices:changed} {IMPORT(rates)} {import} {Import:a1b2}"));
        $this->assertSame([], AA_Plannedtask::importCalls('{importer:prices} import:prices'));
    }

    /** a call nested in the mode of another is a call of its own */
    public function test_importCallArgs_nestedCallInMode(): void
    {
        $this->assertSame([['a', '{', ':'], ['b', '', ':']], AA_Plannedtask::importCallArgs('{import:a:{import:b}}'));
        $this->assertSame([['a', 'x{', ':'], ['b', '', ':']], AA_Plannedtask::importCallArgs('{import:a:x{import:b}}'));
        $this->assertTrue((new AA_Plannedtask(['task' => '{import:a:x{import:b}}']))->usesImport());
    }

    /** the mode as written, '' where the call names none, '{' where an expression sets it; the delimiter */
    public function test_importCallArgs_withModeAndForm(): void
    {
        $this->assertSame(
            [['prices', 'changed', ':'], ['rates', '', '('], ['', '', ''], ['a1b2', '', ':'], ['x', 'check', ':'], ['y', '{', ':']],
            AA_Plannedtask::importCallArgs("{import:prices:changed} {IMPORT(rates)} {import} {Import:a1b2} {import:x:check:more} {import:y:{var:m}}"),
        );
    }

    public function test_allowGet_isFalse(): void
    {
        $this->assertFalse((new Plannedtask_Runnow('Runnow'))->allowGet());
    }

    /** @param array<string, string> $slices  module id => the permission letters of the (anonymous) test user */
    private static function perms(array $slices): void
    {
        $GLOBALS['permission_uid'] = AuthGetUid();
        $GLOBALS['permission_to']  = ['aa' => [AA_ID => ''], 'slice' => $slices];
    }

    /** the stored tasks and the queue - FakeStore knows owner and class, the task properties are added to the load
     *  @param list<array<string, mixed>> $queue  rows of `toexecute` */
    private static function installTasks(array $queue = []): void
    {
        $objects = array_map(static fn(array $t) => [$t[0], AA_Plannedtask::class], self::TASKS) + [self::FORM => [self::MODULE, FormTarget::class]];
        FakeStore::install($objects, ['toexecute' => $queue]);
        $store = RecordingDb::$answer;
        RecordingDb::$answer = static function (string $sql) use ($store): array {
            $rows = $store($sql);
            if (str_contains($sql, 'AS tbl')) {
                foreach (FakeStore::idsOf($sql, 'object_id') ?? [] as $id) {
                    if (isset(self::TASKS[$id]) && str_contains($sql, "'object_text' AS tbl")) {
                        $rows[] = ['tbl' => 'object_text', 'property' => 'task', 'value' => self::TASKS[$id][1], 'priority' => 0];
                        $rows[] = ['tbl' => 'object_text', 'property' => 'condition', 'value' => self::TASKS[$id][2], 'priority' => 0];
                    }
                }
            }
            return $rows;
        };
    }

    /** the tasks page of $module; $all_modules - it lists the tasks of all modules (aa_tasks.php) */
    private static function manager(string $module = self::MODULE, bool $all_modules = false, string $class = AA_Plannedtask::class): Manager
    {
        $actions = new Manageractions();
        $actions->addAction(new Plannedtask_Runnow('Runnow'));
        $GLOBALS['r_state'] = ['manager' => ['test' => null]];
        $managed  = new Managed_Object($class);
        $settings = ['module_id' => $module, 'actions' => $actions, 'actions_hint' => '', 'actions_hint_url' => '', 'switches' => null, 'searchbar' => null, 'use_profile' => false, 'scroller' => ['listlen' => 10]];
        $managed->setSettings(array_merge($managed->getSettings(), $settings));
        $manager = new SearchlessManager('test', $managed);
        return $all_modules ? $manager->setAllModules() : $manager;
    }

    /** the page posts the checked tasks with "Run now"; the message the Manager shows */
    private static function act(array $ids, ?Manager $manager = null): string
    {
        $chb  = array_fill_keys(array_map(static fn($id) => "x$id", $ids), '');
        $post = ['chb' => $chb, 'akce' => 'Runnow', 'akce_param' => [], 'token' => FormToken::get(Manager::FORM_TOKEN)];
        $_GET     = ['scr_st_Go' => '', 'scr_st_Mv' => ''];
        $_POST    = $post;
        $_REQUEST = $post + $_GET + ['srchbr_akce' => ''];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $manager ??= self::manager();
        $manager->performActions();
        return trim(strip_tags((string) $manager->getAndClearMessages()));
    }

    /** @return string[] the DELETEs of queue rows */
    private static function queueDeletes(): array
    {
        return array_values(array_filter(RecordingDb::$log, static fn(string $s) => (bool) preg_match('/^DELETE\b.*\btoexecute\b/is', $s)));
    }
}
