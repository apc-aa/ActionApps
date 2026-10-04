<?php

declare(strict_types=1);

namespace Tests\Unit\Manageraction;

use AA;
use AA\Managed_Object;
use AA\Manager;
use AA\Manageraction\Manageractions;
use AA\Manageraction\Object_Move2slice;
use AA\Util\FormToken;
use AA\Util\LogOnce;
use AA_Plannedtask;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\FakeStore;
use Tests\Unit\Legacy\Fixtures\RecordingDb;
use Tests\Unit\Legacy\Fixtures\SearchlessManager;

/**
 * Moving planned tasks to another slice. A task with {import} is saved only by a user who manages the
 * feeding of its slice, so it moves only to a slice where the user manages the feeding - else the move
 * would hand an import to a slice whose feeding managers never saw it.
 */
final class ObjectMove2sliceTest extends TestCase
{
    private const MODULE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    private const DEST   = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';

    /** AA_Object::load() keeps the loaded objects for the whole process and a move changes their
     *  owner - fresh ids keep the tests (also those of other classes) apart */
    private static string $task   = '';
    private static string $import = '';

    /** @var array<string, array{string, string}> object id => [name, task] as stored, owned by MODULE */
    private static array $tasks = [];

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
        self::$task    = bin2hex(random_bytes(16));
        self::$import  = bin2hex(random_bytes(16));
        self::$tasks   = [self::$task => ['plain', '{now}'], self::$import => ['prices', '{import:prices}']];
        self::perms(PS_FULLTEXT);
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

    public function test_perform_import_task_without_feeding_on_destination_not_moved(): void
    {
        $msg = self::move([self::$import, self::$task]);

        $this->assertStringContainsString('{import}', $msg);
        $this->assertStringContainsString('prices', $msg);
        $this->assertSame([self::$task], self::moved(), 'the plain task moves');
    }

    public function test_perform_import_task_with_feeding_on_destination_moved(): void
    {
        self::perms(PS_FULLTEXT, PS_FULLTEXT . PS_FEEDING);

        $this->assertSame('', self::move([self::$import]));
        $this->assertSame([self::$import], self::moved());
    }

    public function test_perform_feeding_on_source_does_not_count(): void
    {
        self::perms(PS_FULLTEXT . PS_FEEDING);

        $this->assertStringContainsString('{import}', self::move([self::$import]));
        $this->assertSame([], self::moved());
    }

    public function test_perform_task_without_import_moved(): void
    {
        $this->assertSame('', self::move([self::$task]));
        $this->assertSame([self::$task], self::moved());
    }

    /** the permission letters of the (anonymous) test user on MODULE and DEST */
    private static function perms(string $module, string $dest = PS_FULLTEXT): void
    {
        $GLOBALS['permission_uid'] = AuthGetUid();
        $GLOBALS['permission_to']  = ['aa' => [AA_ID => ''], 'slice' => [self::MODULE => $module, self::DEST => $dest]];
    }

    /** the stored tasks and the destination slice - FakeStore knows owner and class, the task properties are added to the load */
    private static function installTasks(): void
    {
        $objects = array_map(static fn(array $t) => [self::MODULE, AA_Plannedtask::class], self::$tasks);
        FakeStore::install($objects, ['module' => [['id' => self::DEST, 'type' => 'S', 'deleted' => 0]]]);
        $store = RecordingDb::$answer;
        RecordingDb::$answer = static function (string $sql) use ($store): array {
            $rows = $store($sql);
            if (str_contains($sql, "'object_text' AS tbl")) {
                foreach (FakeStore::idsOf($sql, 'object_id') ?? [] as $id) {
                    if (isset(self::$tasks[$id])) {
                        $rows[] = ['tbl' => 'object_text', 'property' => 'aa_name', 'value' => self::$tasks[$id][0], 'priority' => 0];
                        $rows[] = ['tbl' => 'object_text', 'property' => 'task', 'value' => self::$tasks[$id][1], 'priority' => 0];
                    }
                }
            }
            return $rows;
        };
    }

    /** the tasks page of MODULE posts the checked tasks with "Move to another slice" DEST; the message the Manager shows */
    private static function move(array $ids): string
    {
        $actions = new Manageractions();
        $actions->addAction(new Object_Move2slice('Move2slice', self::MODULE));
        $GLOBALS['r_state'] = ['manager' => ['test' => null]];
        $managed  = new Managed_Object(AA_Plannedtask::class);
        $settings = ['module_id' => self::MODULE, 'actions' => $actions, 'actions_hint' => '', 'actions_hint_url' => '', 'switches' => null, 'searchbar' => null, 'use_profile' => false, 'scroller' => ['listlen' => 10]];
        $managed->setSettings(array_merge($managed->getSettings(), $settings));
        $manager = new SearchlessManager('test', $managed);

        $chb  = array_fill_keys(array_map(static fn($id) => "x$id", $ids), '');
        $post = ['chb' => $chb, 'akce' => 'Move2slice', 'akce_param' => ['dest_slice_id' => self::DEST], 'token' => FormToken::get(Manager::FORM_TOKEN)];
        $_GET     = ['scr_st_Go' => '', 'scr_st_Mv' => ''];
        $_POST    = $post;
        $_REQUEST = $post + $_GET + ['srchbr_akce' => ''];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $manager->performActions();
        return trim(strip_tags((string) $manager->getAndClearMessages()));
    }

    /** @return list<string> the tasks whose new owner DEST was written */
    private static function moved(): array
    {
        $moved = [];
        foreach (RecordingDb::inserts('object_text') as $row) {
            if ((($row['property'] ?? '') === 'aa_owner') && ((string) ($row['value'] ?? '') === self::DEST)) {
                $moved[] = (string) $row['object_id'];
            }
        }
        sort($moved);
        return $moved;
    }
}
