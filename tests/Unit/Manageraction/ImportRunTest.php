<?php

declare(strict_types=1);

namespace Tests\Unit\Manageraction;

use AA\Import\Definition;
use AA\Import\Registry;
use AA\Import\Run;
use AA\IO\DB\DB_AA;
use AA\Managed_Object;
use AA\Manager;
use AA\Manageraction\Import_Delete;
use AA\Manageraction\Import_Run;
use AA\Manageraction\Manageractions;
use AA\Util\FormToken;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Import\Fixtures\RunSteps;
use Tests\Unit\Legacy\Fixtures\FakeStore;
use Tests\Unit\Legacy\Fixtures\FormTarget;
use Tests\Unit\Legacy\Fixtures\RecordingDb;
use Tests\Unit\Legacy\Fixtures\SearchlessManager;
use zids;

require_once __DIR__ . '/../Import/fixtures/RunSteps.php';

/**
 * "Run" in the Manager of a slice's imports queues a run of each checked import through
 * Service::start(), as a manual start of the user - only from a POST of the page, only for a user who
 * manages the feeding of the slice. Its message counts the runs queued, busy and refused by code.
 *
 * Each test in its own process: FakeSlice loads include/locsess.php, whose getDB() collides with
 * the stub tests/Unit/IO/DB loads when that directory runs first.
 */
#[RunTestsInSeparateProcesses]
final class ImportRunTest extends TestCase
{
    use RunSteps {
        setUp as private stepsSetUp;
        tearDown as private stepsTearDown;
    }

    private const SOURCE  = 'custom:service';
    private const SIBLING = 'fedcba9876543210fedcba9876543210';
    private const FOREIGN = '0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f';
    /** a site module, selected in the session */
    private const SITE_SELECTED = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    /** an import stored under SITE_SELECTED */
    private const OF_SITE = 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2';

    protected function setUp(): void
    {
        $this->stepsSetUp();
        require_once __DIR__ . '/../Legacy/fixtures/object_form_fixtures.php';
        session_id('test-session');   // FormToken keys its tokens with the session
        $dir = __DIR__ . '/../Import/fixtures/service';
        Registry::setCustomRootForTests(dirname($dir), $dir);
        Registry::sources();
        \ServiceSource::$streamKeys = ['s1'];
        \ServiceSource::$refuse     = null;
        \ServiceSource::$created    = [];
        TableDb::$tables['module'] = [['id' => self::SLICE, 'type' => 'S'], ['id' => self::OTHER, 'type' => 'S']];
    }

    protected function tearDown(): void
    {
        session_id('');
        RecordingDb::reset();
        Registry::setCustomRootForTests(null);
        [$_GET, $_POST, $_REQUEST] = [[], [], []];
        unset($_SERVER['REQUEST_METHOD'], $GLOBALS['r_state'], $GLOBALS['r_superuser']);
        $this->stepsTearDown();
    }

    // ------------------------------------------------------------ startAll() - over the stored definitions

    public function test_importRun_queuesViaService(): void
    {
        self::definition(self::DEF);

        $msg = implode(' ', Import_Run::startAll([self::DEF], AuthGetUid()));

        $runs = Run::unfinished(self::DEF);
        $this->assertCount(1, $runs);
        $run = self::stored((string) $runs[0]->getId());
        $this->assertSame(['run', 'manual', AuthGetUid(), ''], [$run['mode'], $run['trigger'], $run['user'], $run['dry']]);
        $this->assertSame(['Import_' . self::DEF], array_column(self::queue(), 'selector'));
        $this->assertStringContainsString('queued', $msg);
        $this->assertStringContainsString('1', $msg);
    }

    public function test_importRun_busyAndRefusedCounted(): void
    {
        self::definition(self::DEF);
        self::definition(self::SIBLING, ['mapping' => '{"rows":[{"to":"text...........9","t":"value","p":{"new_content":"<b>x</b>"}}]}']);
        Import_Run::startAll([self::DEF], AuthGetUid());

        $msg = implode(' ', Import_Run::startAll([self::DEF, self::SIBLING, 'ffffffffffffffffffffffffffffffff'], AuthGetUid()));

        $this->assertCount(1, Run::unfinished(self::DEF), 'no second run while one is unfinished');
        $this->assertSame([], Run::unfinished(self::SIBLING));
        $this->assertStringContainsString('still running', $msg);
        $this->assertStringContainsString('DEFINITION', $msg, 'the refusal by its code');
        $this->assertStringNotContainsString('<b>', $msg);
        $this->assertStringNotContainsString('queued', $msg);
    }

    /** an ended run whose last step still works keeps a new one waiting - the message says so, not "running" */
    public function test_importRun_endedRunStillFinishing(): void
    {
        self::definition(self::DEF);
        Import_Run::startAll([self::DEF], AuthGetUid());
        $run = (string) Run::unfinished(self::DEF)[0]->getId();
        self::storedSet($run, 'state', Run::ENDED);
        self::storedSet($run, 'lease', (string) (time() + 600));
        self::storedSet($run, 'lease_tok', 'working');

        $msg = implode(' ', Import_Run::startAll([self::DEF], AuthGetUid()));

        $this->assertStringContainsString('still finishing', $msg);
        $this->assertStringNotContainsString('still running', $msg);
        $this->assertSame([], Run::unfinished(self::DEF));

        // a done run whose last step still holds its lease keeps it waiting too - it was not ended
        self::storedSet($run, 'state', Run::DONE);
        $msg = implode(' ', Import_Run::startAll([self::DEF], AuthGetUid()));
        $this->assertStringContainsString('still finishing', $msg);
        $this->assertStringNotContainsString('ended', $msg);
    }

    public function test_importRun_runStoppedByErrorSaysSo(): void
    {
        self::definition(self::DEF);
        Import_Run::startAll([self::DEF], AuthGetUid());
        self::storedSet((string) Run::unfinished(self::DEF)[0]->getId(), 'state', Run::ERROR);

        $this->assertStringContainsString('stopped by an error', implode(' ', Import_Run::startAll([self::DEF], AuthGetUid())));
    }

    /** an import deleted meanwhile is no error of the user's */
    public function test_importRun_unknownIdSaysNothing(): void
    {
        $this->assertSame([], Import_Run::startAll(['ffffffffffffffffffffffffffffffff'], AuthGetUid()));
    }

    /** a start that fails (here: called inside a transaction) is logged by class and place, shown as FAILED */
    public function test_importRun_failureLoggedAsFailed(): void
    {
        self::definition(self::DEF);

        $msg = (string) DB_AA::transaction(static fn(): string => implode(' ', Import_Run::startAll([self::DEF], AuthGetUid())));

        $this->assertStringContainsString('(FAILED)', $msg);
        $logged = array_values(array_filter(TableDb::rows('log'), static fn(array $r): bool => $r['type'] === Definition::ERROR_EVENT));
        $this->assertCount(1, $logged);
        $this->assertSame('Import_' . self::DEF, $logged[0]['selector']);
        $this->assertSame([], Run::unfinished(self::DEF));
    }

    public function test_importRun_needsFeeding(): void
    {
        self::definition(self::DEF);
        self::perms(PS_EDIT_ALL_ITEMS . PS_EDIT, PS_FEEDING);

        $msg = implode(' ', Import_Run::startAll([self::DEF], AuthGetUid()));

        $this->assertSame([], Run::unfinished(self::DEF));
        $this->assertStringContainsString('FORBIDDEN', $msg);

        // the page offers the action only with the right, and the Manager runs it only then
        self::store([self::DEF => [self::SLICE, Definition::class]]);
        $this->assertFalse((new Import_Run(Import_Run::ID))->isPerm4Action(self::manager()));
        $this->assertSame('', self::act([self::DEF]));
        $this->assertSame([], RecordingDb::inserts('object_text'));
        $this->assertSame([], RecordingDb::inserts('toexecute'));
    }

    // ------------------------------------------------------------ the Manager action

    public function test_importRun_allowGetFalse(): void
    {
        self::store([self::DEF => [self::SLICE, Definition::class]]);

        $msg = self::act([self::DEF], 'GET');

        $this->assertFalse((new Import_Run(Import_Run::ID))->allowGet());
        $this->assertStringContainsString('use the button', $msg);
        $this->assertSame([], RecordingDb::inserts('object_text'));
        $this->assertSame([], RecordingDb::inserts('toexecute'));
    }

    public function test_importRun_withoutTokenNothing(): void
    {
        self::store([self::DEF => [self::SLICE, Definition::class]]);

        self::act([self::DEF], 'POST', 'forged');

        $this->assertSame([], RecordingDb::inserts('object_text'));
        $this->assertSame([], RecordingDb::inserts('toexecute'));
    }

    /** the checked imports reach Service - here it refuses, the stored definitions are only owner and class */
    public function test_importRun_postReachesServiceForOwnSliceOnly(): void
    {
        self::store([self::DEF => [self::SLICE, Definition::class], self::FOREIGN => [self::OTHER, Definition::class]]);
        self::perms(PS_FEEDING, PS_FEEDING);

        $msg = self::act([self::FOREIGN, self::DEF]);

        $this->assertStringContainsString('Imports not started: 1', $msg, 'only the import of the slice of the page');
        $this->assertStringContainsString('belong to a module', $msg);
        $this->assertSame([], RecordingDb::inserts('toexecute'));
    }

    public function test_importRun_pageOfAnotherClassRefused(): void
    {
        self::store([self::DEF => [self::SLICE, FormTarget::class]]);
        $actions = new Manageractions();
        $actions->addAction(new Import_Run(Import_Run::ID));
        $managed = new Managed_Object(FormTarget::class);

        $msg = self::act([self::DEF], managed: $managed->setSettings(['actions' => $actions, 'switches' => null] + $managed->getSettings()));

        $this->assertSame('This action does not work on this page.', $msg);
    }

    public function test_importRun_asksBeforeRun(): void
    {
        $code = (new Import_Run(Import_Run::ID))->getButton('', '_#AA_ID___', self::SLICE)[Import_Run::ID];

        $this->assertMatchesRegularExpression('/data-aa-confirm="[^"]+"/', $code);
        $this->assertStringContainsString('data-aa-token=', $code);
    }

    // ------------------------------------------------------------ the overview of all slices

    /** The session may have a site selected while the superadmin looks at the imports of all slices:
     *  Run and Delete work there, each import checked on its own slice - an import stored under a
     *  site is refused by Service and deleteWithRuns() themselves. */
    public function test_overview_runAndDeleteWithSiteSelected(): void
    {
        self::definition(self::DEF);
        self::definition(self::SIBLING);
        self::definition(self::OF_SITE, [], self::SITE_SELECTED);
        $GLOBALS['r_superuser'] = [AA_ID => true];
        $GLOBALS['permission_to']['slice'][self::SITE_SELECTED] = PS_FEEDING;
        // the type of each module, as AA_Module::getModuleInfo() reads it - TableDb has no HEX()
        TableDb::$answer = static fn(string $sql): ?array => str_contains($sql, 'LOWER(HEX(`id`))') ? [['unpackid' => self::SITE_SELECTED, 'type' => 'W']] : null;

        $this->assertTrue((new Import_Run(Import_Run::ID))->isPerm4Action(self::overview()));
        $this->assertTrue((new Import_Delete('DeleteObject'))->isPerm4Action(self::overview()));
        $msg = self::actOnOverview([self::DEF, self::OF_SITE], Import_Run::ID);

        $this->assertStringContainsString('Imports queued to run: 1', $msg);
        $this->assertStringContainsString('FORBIDDEN', $msg, 'the import under the site');
        $this->assertCount(1, Run::unfinished(self::DEF));
        $this->assertSame([], Run::unfinished(self::OF_SITE));

        $msg = self::actOnOverview([self::SIBLING, self::OF_SITE], 'DeleteObject');

        $this->assertNull(Definition::fresh(self::SIBLING));
        $this->assertNotNull(Definition::fresh(self::OF_SITE));
        $this->assertStringContainsString('not deleted', $msg);
        $this->assertNotNull(Definition::fresh(self::DEF));
    }

    /** the overview is a superadmin's - a slice admin with the site selected gets no action there */
    public function test_overview_notSuperadminRefused(): void
    {
        self::definition(self::DEF);
        $GLOBALS['r_superuser'] = [];
        $GLOBALS['permission_to']['slice'][self::SITE_SELECTED] = PS_FEEDING;

        $this->assertFalse((new Import_Run(Import_Run::ID))->isPerm4Action(self::overview()));
        $this->assertFalse((new Import_Delete('DeleteObject'))->isPerm4Action(self::overview()));
        self::actOnOverview([self::DEF], Import_Run::ID);
        self::actOnOverview([self::DEF], 'DeleteObject');

        $this->assertSame([], Run::unfinished(self::DEF));
        $this->assertNotNull(Definition::fresh(self::DEF));
    }

    // ------------------------------------------------------------ helpers

    /** @param array<string, string> $props */
    private static function definition(string $id, array $props = [], string $slice = self::SLICE): void
    {
        $def = new Definition();
        $def->setNew($id, $slice, 'prices ' . $id);
        $props += ['source' => self::SOURCE, 'mapping' => self::NO_PARAM_MAPPING, 'key_fields' => '["relation........"]', 'store_mode' => 'update/insert', 'enabled' => '1'];
        foreach ($props as $property => $value) {
            $def->setProperty($property, $value);
        }
        self::assertSame($id, $def->save());
    }

    /** @param array<string, array{string, string}> $objects  the only stored objects: id => [aa_owner, aa_type] */
    private static function store(array $objects): void
    {
        RecordingDb::reset();
        $GLOBALS['spareDBs'] = [new RecordingDb()];
        FakeStore::install($objects);
    }

    /** The Manager of the imports of SLICE, as Definition::getManagerConf() makes it (or of $managed),
     *  with the checked objects $ids sent to Run by $method; the message the Manager shows. */
    private static function act(array $ids, string $method = 'POST', ?string $token = null, ?Managed_Object $managed = null): string
    {
        $manager = self::manager($managed);
        $request = ['chb' => array_fill_keys(array_map(static fn(string $id): string => "x$id", $ids), ''), 'akce' => Import_Run::ID, 'token' => $token ?? FormToken::get(Manager::FORM_TOKEN)];
        $_GET     = ['scr_st_Go' => '', 'scr_st_Mv' => ''] + (($method === 'GET') ? $request : []);
        $_POST    = ($method === 'POST') ? $request : [];
        $_REQUEST = $_POST + $_GET + ['srchbr_akce' => ''];
        $_SERVER['REQUEST_METHOD'] = $method;
        $manager->performActions();
        return trim(strip_tags((string) $manager->getAndClearMessages()));
    }

    /** The Manager of admin/aa_dataimports.php: all slices, with a site selected in the session. Its
     *  actionable() passes every checked import on - TableDb cannot answer the owner query and
     *  querySet() of the real one, which ManagerActionOwnerTest covers; the right of each import is
     *  then up to the action. */
    private static function overview(): Manager
    {
        $GLOBALS['r_state'] = ['manager' => ['test' => null]];
        $managed = new class (Definition::class) extends Managed_Object {
            public function actionable(Manager $manager, zids $zids, string $perm): array
            {
                return [$zids, false];
            }
        };
        $managed->setSettings(array_merge(Definition::getManagerConf()->getSettings(), ['module_id' => self::SITE_SELECTED, 'searchbar' => null, 'use_profile' => false, 'actions_hint' => '', 'actions_hint_url' => '', 'scroller' => ['listlen' => 10]]));
        return (new SearchlessManager('test', $managed))->setAllModules();
    }

    /** posts action $akce on the checked $ids on the overview; the message it shows */
    private static function actOnOverview(array $ids, string $akce): string
    {
        $manager  = self::overview();
        $_GET     = ['scr_st_Go' => '', 'scr_st_Mv' => ''];
        $_POST    = ['chb' => array_fill_keys(array_map(static fn(string $id): string => "x$id", $ids), ''), 'akce' => $akce, 'token' => FormToken::get(Manager::FORM_TOKEN)];
        $_REQUEST = $_POST + $_GET + ['srchbr_akce' => ''];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $manager->performActions();
        return trim(strip_tags((string) $manager->getAndClearMessages()));
    }

    private static function manager(?Managed_Object $managed = null): Manager
    {
        $GLOBALS['r_state'] = ['manager' => ['test' => null]];
        $managed ??= Definition::getManagerConf();
        $managed->setSettings(array_merge($managed->getSettings(), ['module_id' => self::SLICE, 'searchbar' => null, 'use_profile' => false, 'actions_hint' => '', 'actions_hint_url' => '', 'scroller' => ['listlen' => 10]]));
        return new SearchlessManager('test', $managed);
    }
}
