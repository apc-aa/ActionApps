<?php

declare(strict_types=1);

// AA_Form and AA_Object live in include/form.class.php and include/statestore.php, whose require chain
// reaches include/locsess.php and needs the constants include/config.php normally provides.

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
        'PERM_LIB'        => 'sql',
        'DB_HOST'         => 'localhost',
        'DB_NAME'         => 'aa_test',
        'DB_USER'         => 'aa',
        'DB_PASSWORD'     => '',
        'SCROLLER_LENGTH' => 3,
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA\Managed_Object;
    use AA\Manager;
    use AA\Optimize\CheckObjectFormRefusals;
    use AA\Optimize\Finding;
    use AA\Util\LogOnce;
    use AA_Form;
    use AA_Module;
    use AA_Module_Site;
    use AA_Modulesettings_Site;
    use AA_Modulesettings_Slice;
    use AA_Object;
    use AA_Property;
    use AA_Searchbar;
    use AA_Slice;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /** an object edited on an AA form - like the settings of a module or a planned task */
    final class FormTarget extends AA_Object
    {
        public $code;

        public static function getClassProperties(): array
        {
            return ['code' => new AA_Property('code', 'Code', 'string', false)];
        }
    }

    /** a Manager page without a searchbar - the Manager itself leaves the property unset then */
    final class SearchlessManager extends Manager
    {
        protected ?AA_Searchbar $searchbar = null;
    }

    /**
     * An object form (AA_Form::process() -> Managed_Object::save()) writes only the object it was built
     * for, owned by the module the page works on and of the form's class - the object id comes from the
     * POSTed keys, so without the check any object of any module (other sites' settings with their login
     * code, Central's records) could be overwritten by id. A new object gets a fresh id.
     */
    final class ObjectFormOwnerTest extends TestCase
    {
        private const MODULE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
        private const OTHER  = 'b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1';
        private const OWN    = 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1';
        private const VICTIM = 'd1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1';

        /** object id => [aa_owner, aa_type] as stored */
        private const STORED = [
            self::OWN    => [self::MODULE, FormTarget::class],
            self::VICTIM => [self::OTHER, FormTarget::class],
        ];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            LogOnce::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            self::stored(self::STORED);
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            [$_GET, $_POST, $_REQUEST] = [[], [], []];
            unset($GLOBALS['r_state']);
        }

        /** answers the look-ups of aa_owner / aa_type and the loads of $objects; nothing else is stored */
        private static function stored(array $objects): void
        {
            RecordingDb::$answer = static function (string $sql) use ($objects): array {
                if (!preg_match("/object_id = '([0-9a-f]{32})'/", $sql, $m) || !isset($objects[$m[1]])) {
                    return [];
                }
                [$owner, $type] = $objects[$m[1]];
                if (str_contains($sql, "'aa_owner'")) {
                    return [['property' => 'aa_owner', 'value' => $owner], ['property' => 'aa_type', 'value' => $type]];
                }
                if (str_contains($sql, 'AS tbl')) {     // AA_Object::load()
                    return [['tbl' => 'object_text', 'property' => 'aa_owner', 'value' => $owner, 'priority' => 0], ['tbl' => 'object_text', 'property' => 'aa_type', 'value' => $type, 'priority' => 0]];
                }
                return [];
            };
        }

        /** the form of a page working on MODULE, built for the object $id ('' - a new one), posted with $aa */
        private static function post(string $id, array $aa, string $class = FormTarget::class): int
        {
            $_POST['aa'] = $aa;
            $form = new AA_Form();
            $form->setObject($class, $id, self::MODULE);
            return $form->process($aa);
        }

        /** @return string[] the writes (INSERT/DELETE/UPDATE) of object rows */
        private static function objectWrites(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn(string $s) => (bool) preg_match('/^(INSERT|DELETE|UPDATE|REPLACE)\b.*object_(text|integer|float)/s', $s)));
        }

        private static function refusalLogged(): bool
        {
            return (bool) array_filter(RecordingDb::$log, static fn(string $s) => str_starts_with($s, 'INSERT') && str_contains($s, "'" . CheckObjectFormRefusals::EVENT . "'"));
        }

        /** @return list<string> the selectors of the logged refusals */
        private static function refusalSelectors(): array
        {
            return array_values(array_map(static fn(array $row) => (string) $row['selector'], array_filter(RecordingDb::inserts('log'), static fn(array $row) => ($row['type'] ?? '') === CheckObjectFormRefusals::EVENT)));
        }

        public function test_a_forged_id_of_another_modules_object_is_refused(): void
        {
            // the settings form of MODULE, posted with the id of another module's settings
            $state = self::post(self::OWN, ['u' . self::VICTIM => ['code' => ['{hijacked}']]]);

            $this->assertSame(AA_Form::REFUSED, $state);
            $this->assertSame([], self::objectWrites(), 'nothing written');
            $this->assertTrue(self::refusalLogged(), 'logged for State Check');
        }

        public function test_a_form_built_for_another_modules_object_is_refused(): void
        {
            // a Manager page of MODULE opened with ?id= of another module's object
            $state = self::post(self::VICTIM, ['u' . self::VICTIM => ['code' => ['{hijacked}']]]);

            $this->assertSame(AA_Form::REFUSED, $state);
            $this->assertSame([], self::objectWrites());
            $this->assertTrue(self::refusalLogged());
        }

        public function test_an_own_object_of_another_class_is_refused(): void
        {
            self::stored([self::OWN => [self::MODULE, 'AA_Modulesettings_Site']]);

            $this->assertSame(AA_Form::REFUSED, self::post(self::OWN, ['u' . self::OWN => ['code' => ['x']]]));
            $this->assertSame([], self::objectWrites());
        }

        public function test_a_missing_object_is_not_edited(): void
        {
            $missing = 'e1e1e1e1e1e1e1e1e1e1e1e1e1e1e1e1';

            $this->assertSame(AA_Form::REFUSED, self::post($missing, ['u' . $missing => ['code' => ['x']]]));
            $this->assertSame([], self::objectWrites());
        }

        public function test_the_own_object_is_saved(): void
        {
            $state = self::post(self::OWN, ['u' . self::OWN => ['code' => ['{own}']]]);

            $this->assertSame(AA_Form::SAVED, $state);
            $writes = self::objectWrites();
            $this->assertNotEmpty($writes);
            foreach ($writes as $sql) {
                $this->assertStringContainsString(self::OWN, $sql);
            }
            $this->assertNotEmpty(array_filter($writes, static fn(string $s) => str_starts_with($s, 'INSERT') && str_contains($s, '{own}')));
            $this->assertFalse(self::refusalLogged());
        }

        public function test_a_new_object_gets_a_fresh_id(): void
        {
            $this->assertSame(AA_Form::SAVED, self::post('', ['n1_' . self::MODULE => ['code' => ['{new}']]]));

            $writes = self::objectWrites();
            $this->assertNotEmpty($writes);
            $this->assertSame(1, preg_match("/'([0-9a-f]{32})'/", (string) end($writes), $m));
            $this->assertNotContains($m[1], [self::OWN, self::VICTIM, self::MODULE, self::OTHER]);
            $this->assertStringContainsString(self::MODULE, implode("\n", $writes), 'owned by the module of the page');
        }

        public function test_a_new_object_never_takes_a_posted_id(): void
        {
            // an Add form posted with the key of an existing object - of another module
            $this->assertSame(AA_Form::SAVED, self::post('', ['u' . self::VICTIM => ['code' => ['{new}']]]));

            $writes = self::objectWrites();
            $this->assertNotEmpty($writes);
            $this->assertStringNotContainsString(self::VICTIM, implode("\n", $writes), 'the other object untouched');
        }

        public function test_a_managed_object_without_add_creates_nothing(): void
        {
            $_POST['aa'] = ['n1_' . self::MODULE => ['code' => ['{new}']]];
            $error = (new Managed_Object(FormTarget::class))->setAction('add', false)->save(self::MODULE, '');

            $this->assertNotNull($error);
            $this->assertSame([], self::objectWrites());
        }

        /** the posted id may be any string or a fresh one: only a stored object goes to the selector,
         *  so a long id cannot push the user out of it and each new one does not make a new log row */
        public function test_a_posted_id_of_no_object_is_not_logged(): void
        {
            $keys = ['u' . str_repeat('x', 300), 'u' . rawurlencode('a:b%c'), 'u', 'n1_' . self::MODULE, 'ue1e1e1e1e1e1e1e1e1e1e1e1e1e1e1e1', 'u' . strtoupper(self::VICTIM)];
            foreach ($keys as $key) {
                $this->assertSame(AA_Form::REFUSED, self::post(self::OWN, [$key => ['code' => ['x']]]), $key);
            }
            $this->assertSame([], self::objectWrites());

            $selectors = self::refusalSelectors();
            $this->assertCount(1, $selectors, 'one row for all of them');
            $this->assertSame([self::MODULE, CheckObjectFormRefusals::NO_OBJECT], array_slice(ParamExplode($selectors[0]), 0, 2));
        }

        public function test_the_posted_object_is_logged(): void
        {
            $this->assertSame(AA_Form::REFUSED, self::post(self::OWN, ['u' . self::VICTIM => ['code' => ['x']]]));

            $selectors = self::refusalSelectors();
            $this->assertCount(1, $selectors);
            $this->assertSame([self::MODULE, self::VICTIM], array_slice(ParamExplode($selectors[0]), 0, 2));
        }

        /** the settings forms of a slice and a site (slicedit.php, modedit.php) - of the module's own
         *  settings object, whose id comes from the module id */
        public static function moduleSettings(): array
        {
            return [
                'slice' => [AA_Slice::class, AA_Modulesettings_Slice::class, 'historylog', '0'],
                'site'  => [AA_Module_Site::class, AA_Modulesettings_Site::class, 'page404', '1'],
            ];
        }

        /** @param class-string<AA_Module> $module */
        #[\PHPUnit\Framework\Attributes\DataProvider('moduleSettings')]
        public function test_the_module_settings_form_refuses_the_settings_of_another_module(string $module, string $class, string $property, string $value): void
        {
            [$own, $victim] = [string2id($class . self::MODULE), string2id($class . self::OTHER)];
            self::stored([$own => [self::MODULE, $class], $victim => [self::OTHER, $class]]);
            $_POST['aa'] = ['u' . $victim => [$property => [$value]]];

            $error = $module::processModuleObject(self::MODULE);

            $this->assertIsString($error, 'the callers show it in $err[\'settings\']');
            $this->assertNotSame('', $error);
            $this->assertSame([], self::objectWrites());
            $this->assertSame([self::MODULE, $victim], array_slice(ParamExplode(self::refusalSelectors()[0] ?? ''), 0, 2));
        }

        /** @param class-string<AA_Module> $module */
        #[\PHPUnit\Framework\Attributes\DataProvider('moduleSettings')]
        public function test_the_module_settings_form_saves_the_own_settings(string $module, string $class, string $property, string $value): void
        {
            $own = string2id($class . self::MODULE);
            self::stored([$own => [self::MODULE, $class]]);
            $_POST['aa'] = ['u' . $own => [$property => [$value]]];

            $this->assertNull($module::processModuleObject(self::MODULE));
            $writes = self::objectWrites();
            $this->assertNotEmpty($writes);
            foreach ($writes as $sql) {
                $this->assertStringContainsString($own, $sql);
            }
            $this->assertFalse(self::refusalLogged());
        }

        /** the tasks Manager of a page of MODULE with an edit form of FormTarget; $state - as a former
         *  request left it in the session */
        private static function manager(?array $state = null): Manager
        {
            $GLOBALS['r_state'] = ['manager' => ['task' => $state]];
            $managed = (new Managed_Object(FormTarget::class))->setAction('edit')->setSettings(['module_id' => self::MODULE, 'actions' => null, 'switches' => null, 'searchbar' => null, 'use_profile' => false, 'scroller' => ['listlen' => 10]]);
            return new SearchlessManager('task', $managed);
        }

        /** one manager id serves the tasks of all modules - the module of the page counts, not the
         *  one of the first visit, else editing an object of another module is refused */
        public function test_the_manager_works_on_the_module_of_the_page(): void
        {
            $state = self::manager()->getState();
            $state['searchbar'] = (new AA_Searchbar())->getState();
            $state['module_id'] = self::OTHER;     // the first visit was on another module

            $this->assertSame(self::MODULE, self::manager($state)->getModuleId());
        }

        /** the edit form posted to the Manager (performActions()) */
        private static function postToManager(Manager $manager, array $aa): void
        {
            $_GET     = ['id' => self::OWN, 'display_params' => '', 'listlen' => ''];
            $_POST    = ['aa' => $aa, 'aa_akce_all' => ''];
            $_REQUEST = ['chb' => [], 'akce' => '', 'srchbr_akce' => ''];
            $manager->performActions();
        }

        public function test_the_manager_shows_why_the_form_was_refused(): void
        {
            $manager = self::manager();
            self::postToManager($manager, ['u' . self::VICTIM => ['code' => ['{hijacked}']]]);

            $this->assertStringContainsString('it is not the object of this form', (string) $manager->getAndClearMessages());
            $this->assertSame([], self::objectWrites());
        }

        public function test_the_manager_saves_the_edited_object(): void
        {
            $manager = self::manager();
            self::postToManager($manager, ['u' . self::OWN => ['code' => ['{own}']]]);

            $this->assertStringNotContainsString('not saved', (string) $manager->getAndClearMessages());
            $this->assertNotEmpty(self::objectWrites());
        }

        public function test_refusals_become_warnings(): void
        {
            $row = ['selector' => ParamImplode([self::MODULE, self::VICTIM, 'editor1']), 'days' => '2', 'first' => '1758000000', 'last' => '1758700000', 'params' => ParamImplode([FormTarget::class, FormTarget::class, self::OTHER])];

            [$finding] = CheckObjectFormRefusals::findingsFor([$row], [self::MODULE => 'My site', self::OTHER => '<Other>']);

            $this->assertSame(Finding::WARNING, $finding->severity);
            $this->assertStringContainsString('My site', $finding->title);
            $this->assertSame(2, $finding->count);
            $this->assertSame('&lt;Other&gt;', $finding->evidence[0]['Owner']);
            $this->assertSame(self::VICTIM, $finding->evidence[0]['Object']);

            $row['selector'] = ParamImplode([self::MODULE, CheckObjectFormRefusals::NO_OBJECT, 'editor1']);
            [$finding] = CheckObjectFormRefusals::findingsFor([$row], []);
            $this->assertSame('(no such object)', $finding->evidence[0]['Object']);
            $this->assertSame([Finding::OK], array_map(static fn(Finding $f) => $f->severity, CheckObjectFormRefusals::findingsFor([], [])));
        }
    }
}
