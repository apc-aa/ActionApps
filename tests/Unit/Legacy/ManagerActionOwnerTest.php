<?php

declare(strict_types=1);

// The constants the legacy boot needs come with fixtures/object_form_fixtures.php.

namespace Tests\Unit\Legacy {

    use AA;
    use AA\Managed;
    use AA\Managed_Object;
    use AA\Managed_Table;
    use AA\Manager;
    use AA\Manageraction\Item_Move2Slice;
    use AA\Manageraction\Manageractions;
    use AA\Manageraction\Object_Delete;
    use AA\Manageraction\Object_Move2slice;
    use AA\Manageraction\Polls_DeleteTrash;
    use AA\Manageraction\Polls_MoveItem;
    use AA\Manageraction\Table_Row_Delete;
    use AA\Manageraction\View_Move2slice;
    use AA\Optimize\CheckObjectFormRefusals;
    use AA\Util\LogOnce;
    use AA_Condition;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeStore;
    use Tests\Unit\Legacy\Fixtures\FormTarget;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;
    use Tests\Unit\Legacy\Fixtures\RecordingItemFeed;
    use Tests\Unit\Legacy\Fixtures\SearchlessManager;
    use zids;

    /**
     * The Manager actions and table forms change only what the page may manage: the objects and rows
     * of the module the page works on - in a Manager over all modules (the tasks of all modules), the
     * objects of modules where the user has the right the action needs. The ids come from the posted
     * checkboxes and form keys, so without the check a user with rights on one module could move,
     * delete or rewrite the tasks, forms, aliases, views or polls of any other module by their ids.
     * A posted id of nothing stored (a reload after a delete) is skipped silently.
     */
    final class ManagerActionOwnerTest extends TestCase
    {
        private const MODULE  = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
        private const OTHER   = 'b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1';
        private const DEST    = 'f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1';
        private const OWN     = 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1';
        private const VICTIM  = 'd1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1';
        private const MISSING = 'e1e1e1e1e1e1e1e1e1e1e1e1e1e1e1e1';

        private const POLL_OWN   = 'e2e2e2e2e2e2e2e2e2e2e2e2e2e2e2e2';
        private const POLL_OTHER = 'e3e3e3e3e3e3e3e3e3e3e3e3e3e3e3e3';

        /** a slice where the user may add items only to the holding bin */
        private const HOLD       = 'f2f2f2f2f2f2f2f2f2f2f2f2f2f2f2f2';
        private const ITEM_OWN   = 'a3a3a3a3a3a3a3a3a3a3a3a3a3a3a3a3';
        private const ITEM_OTHER = 'b3b3b3b3b3b3b3b3b3b3b3b3b3b3b3b3';
        /** a polls module and a deleted slice - no targets of an export */
        private const POLLS      = 'f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3';
        private const DELETED    = 'f4f4f4f4f4f4f4f4f4f4f4f4f4f4f4f4';

        /** object id => [aa_owner, aa_type] as stored */
        private const OBJECTS = [
            self::OWN    => [self::MODULE, FormTarget::class],
            self::VICTIM => [self::OTHER, FormTarget::class],
        ];

        /** table => rows as stored (ids unpacked); both polls are in the trash */
        private const TABLES = [
            'view'     => [
                ['id' => 7, 'slice_id' => self::MODULE, 'name' => 'view 7'],
                ['id' => 8, 'slice_id' => self::OTHER, 'name' => 'view 8'],
            ],
            'polls'    => [
                ['id' => self::POLL_OWN, 'module_id' => self::MODULE, 'status_code' => 3],
                ['id' => self::POLL_OTHER, 'module_id' => self::OTHER, 'status_code' => 3],
            ],
            'email'    => [['id' => 298, 'owner_module_id' => self::OTHER, 'subject' => 'welcome']],
            'item'     => [
                ['id' => self::ITEM_OWN, 'slice_id' => self::MODULE],
                ['id' => self::ITEM_OTHER, 'slice_id' => self::OTHER],
            ],
            'rssfeeds' => [],
            'module'   => [
                ['id' => self::MODULE, 'type' => 'S', 'deleted' => 0, 'name' => 'Module'],
                ['id' => self::OTHER, 'type' => 'S', 'deleted' => 0, 'name' => 'Other'],
                ['id' => self::DEST, 'type' => 'S', 'deleted' => 0, 'name' => 'Dest'],
                ['id' => self::HOLD, 'type' => '', 'deleted' => 0, 'name' => 'Hold'],
                ['id' => self::POLLS, 'type' => 'P', 'deleted' => 0, 'name' => 'Polls'],
                ['id' => self::DELETED, 'type' => 'S', 'deleted' => 1, 'name' => 'Deleted'],
            ],
        ];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/object_form_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            LogOnce::reset();
            RecordingItemFeed::$fed = [];
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            AA::$module_id = self::MODULE;
            self::perms([self::MODULE => PS_FULLTEXT . PS_EDIT . PS_FORMS, self::OTHER => '', self::DEST => PS_FULLTEXT]);
            FakeStore::install(self::OBJECTS, self::TABLES);
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            [$_GET, $_POST, $_REQUEST] = [[], [], []];
            unset($GLOBALS['r_state'], $GLOBALS['permission_uid'], $GLOBALS['permission_to']);
            AA::$module_id = null;
        }

        /** @param array<string, string> $slices  module id => the permission letters of the (anonymous) user */
        private static function perms(array $slices): void
        {
            $GLOBALS['permission_uid'] = AuthGetUid();
            $GLOBALS['permission_to']  = ['aa' => [AA_ID => ''], 'slice' => $slices];
        }

        /** a Manager page of $module over $managed; $all_modules - it lists the objects of all modules (aa_tasks.php) */
        private static function manager(Managed $managed, ?Manageractions $actions = null, bool $all_modules = false, ?string $module = self::MODULE): Manager
        {
            $GLOBALS['r_state'] = ['manager' => ['test' => null]];
            $settings = ['module_id' => $module, 'actions' => $actions, 'actions_hint' => '', 'actions_hint_url' => '', 'switches' => null, 'searchbar' => null, 'use_profile' => false, 'scroller' => ['listlen' => 10]];
            $managed->setSettings(array_merge($managed->getSettings(), $settings));
            $manager = new SearchlessManager('test', $managed);
            return $all_modules ? $manager->setAllModules() : $manager;
        }

        /** the request the Manager reads besides the posted data */
        private static function request(array $get, array $post, array $request): void
        {
            $_GET     = $get + ['display_params' => '', 'listlen' => '', 'scr_st_Go' => '', 'scr_st_Mv' => ''];
            $_POST    = $post + ['aa' => [], 'aa_akce_all' => ''];
            $_REQUEST = $request + ['chb' => [], 'akce' => '', 'akce_param' => [], 'srchbr_akce' => ''];
        }

        /** the page posts the checked rows with an action (performActions()) */
        private static function act(Manager $manager, string $akce, array $ids, array|string $param = []): string
        {
            $chb = array_fill_keys(array_map(static fn($id) => "x$id", $ids), '');
            self::request([], ['chb' => $chb], ['chb' => $chb, 'akce' => $akce, 'akce_param' => $param]);
            $manager->performActions();
            return trim(strip_tags((string) $manager->getAndClearMessages()));
        }

        /** the edit form of the row $id ('' - the Add form) posted to the Manager */
        private static function postForm(Manager $manager, string $id, array $aa): string
        {
            self::request(['id' => $id], ['aa' => $aa], []);
            $manager->performActions();
            return trim(strip_tags((string) $manager->getAndClearMessages()));
        }

        /** @return string[] the writes (INSERT/DELETE/UPDATE) of $table */
        private static function writes(string $table): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn(string $s) => (bool) preg_match('/^(INSERT|DELETE|UPDATE|REPLACE)\b(?:(?!\bWHERE\b).)*\b`?' . $table . '`?[\s(]/s', $s)));
        }

        /** @return string[] the writes of object rows */
        private static function objectWrites(): array
        {
            return array_merge(self::writes('object_text'), self::writes('object_integer'), self::writes('object_float'));
        }

        /** @return list<list<string>> [module, object] of the logged refusals */
        private static function refusals(): array
        {
            $rows = array_filter(RecordingDb::inserts('log'), static fn(array $row) => ($row['type'] ?? '') === CheckObjectFormRefusals::EVENT);
            return array_values(array_map(static fn(array $row) => array_slice(ParamExplode((string) $row['selector']), 0, 2), $rows));
        }

        private static function objectManager(bool $all_modules = false): Manager
        {
            $actions = new Manageractions();
            $actions->addAction(new Object_Delete('DeleteObject'));
            $actions->addAction(new Object_Move2slice('Move2slice', self::MODULE));
            return self::manager(new Managed_Object(FormTarget::class), $actions, $all_modules);
        }

        // ------------------------------------------------------------------ objects

        public function test_delete_removes_the_own_object(): void
        {
            $msg = self::act(self::objectManager(), 'DeleteObject', [self::OWN]);

            $this->assertSame('', $msg);
            $this->assertNotEmpty(self::objectWrites());
            $this->assertStringContainsString(self::OWN, implode("\n", self::objectWrites()));
            $this->assertSame([], self::refusals());
        }

        public function test_delete_skips_an_object_of_another_module(): void
        {
            $msg = self::act(self::objectManager(), 'DeleteObject', [self::OWN, self::VICTIM]);

            $this->assertNotSame('', $msg, 'the page tells some were left');
            $this->assertStringNotContainsString(self::VICTIM, implode("\n", self::objectWrites()), 'the other module\'s object untouched');
            $this->assertStringContainsString(self::OWN, implode("\n", self::objectWrites()), 'the own one deleted');
            $this->assertSame([[self::MODULE, self::VICTIM]], self::refusals());
        }

        /** a reload of a delete link, or a page listing an object deleted meanwhile */
        public function test_delete_skips_a_posted_id_of_no_object_silently(): void
        {
            $msg = self::act(self::objectManager(), 'DeleteObject', [self::MISSING, 'not-an-id']);

            $this->assertSame('', $msg);
            $this->assertSame([], self::objectWrites());
            $this->assertSame([], self::refusals());
        }

        /** the tasks of all modules (aa_tasks.php) list also objects the user can not change - refused, not logged */
        public function test_delete_over_all_modules_needs_the_right_in_the_owner(): void
        {
            $this->assertNotSame('', self::act(self::objectManager(true), 'DeleteObject', [self::VICTIM]));
            $this->assertSame([], self::objectWrites(), 'no right in OTHER');
            $this->assertSame([], self::refusals(), 'no forged request - the page listed it');

            self::perms([self::MODULE => PS_EDIT, self::OTHER => PS_EDIT]);
            $this->assertSame('', self::act(self::objectManager(true), 'DeleteObject', [self::VICTIM]));
            $this->assertStringContainsString(self::VICTIM, implode("\n", self::objectWrites()));
        }

        public function test_move_to_slice_moves_the_own_task(): void
        {
            $msg = self::act(self::objectManager(true), 'Move2slice', [self::OWN], ['dest_slice_id' => self::DEST]);

            $this->assertSame('', $msg);
            $inserts = array_filter(self::objectWrites(), static fn(string $s) => str_starts_with($s, 'INSERT'));
            $this->assertNotEmpty($inserts);
            $this->assertStringContainsString(self::DEST, implode("\n", $inserts), 'owned by the destination now');
            $this->assertSame([], self::refusals());
        }

        /** aa_tasks.php posted with the task of a module the user has no right to - it would become
         *  the destination's, and so editable there */
        public function test_move_to_slice_skips_a_foreign_task(): void
        {
            $msg = self::act(self::objectManager(true), 'Move2slice', [self::OWN, self::VICTIM], ['dest_slice_id' => self::DEST]);

            $this->assertNotSame('', $msg);
            $this->assertStringNotContainsString(self::VICTIM, implode("\n", self::objectWrites()));
            $this->assertStringContainsString(self::OWN, implode("\n", self::objectWrites()));
            $this->assertSame([], self::refusals(), 'the page over all modules listed it');
        }

        public function test_move_to_slice_on_one_module_moves_only_its_tasks(): void
        {
            self::perms([self::MODULE => PS_FULLTEXT, self::OTHER => PS_FULLTEXT, self::DEST => PS_FULLTEXT]);

            $this->assertNotSame('', self::act(self::objectManager(), 'Move2slice', [self::VICTIM], ['dest_slice_id' => self::DEST]));

            $this->assertSame([], self::objectWrites(), 'the page of MODULE, not of OTHER');
            $this->assertSame([[self::MODULE, self::VICTIM]], self::refusals());
        }

        /** the letters of the rights are shared by all module types - a task has to stay in a slice */
        public function test_move_to_slice_refuses_a_module_which_is_not_a_slice(): void
        {
            $GLOBALS['permission_to']['slice'][self::POLLS] = PS_FULLTEXT;

            $this->assertNotSame('', self::act(self::objectManager(true), 'Move2slice', [self::OWN], ['dest_slice_id' => self::POLLS]));
            $this->assertNotSame('', self::act(self::objectManager(true), 'Move2slice', [self::OWN], ['dest_slice_id' => self::DELETED]));
            $this->assertSame([], self::objectWrites());
        }

        public function test_move_to_slice_skips_a_missing_task_silently(): void
        {
            $this->assertSame('', self::act(self::objectManager(true), 'Move2slice', [self::MISSING], ['dest_slice_id' => self::DEST]));

            $this->assertSame([], self::objectWrites());
            $this->assertSame([], self::refusals());
        }

        public function test_an_object_action_on_a_page_without_a_module_tells_so(): void
        {
            $actions = new Manageractions();
            $actions->addAction(new Object_Delete('DeleteObject'));
            $manager = self::manager(new Managed_Object(FormTarget::class), $actions, module: null);

            $this->assertNotSame('', self::act($manager, 'DeleteObject', [self::OWN]), 'a misconfigured page shows');
            $this->assertSame([], self::objectWrites());
        }

        // ------------------------------------------------------------------ table rows

        /** the views of MODULE, as se_views.php manages them */
        private static function viewManager(): Manager
        {
            $actions = new Manageractions();
            $actions->addAction(new Table_Row_Delete('DeleteTRow'));
            return self::manager((new Managed_Table('view'))->setAction('add', false), $actions);
        }

        /** the views of MODULE with the action se_views.php offers to move them */
        private static function viewMoveManager(): Manager
        {
            $actions = new Manageractions();
            $actions->addAction(new View_Move2slice('Move2slice', self::MODULE));
            return self::manager((new Managed_Table('view'))->setAction('add', false), $actions);
        }

        public function test_view_move_moves_the_own_view_to_a_slice(): void
        {
            $this->assertSame('', self::act(self::viewMoveManager(), 'Move2slice', ['7'], ['dest_slice_id' => self::DEST]));
            $this->assertCount(1, self::writes('view'));
        }

        public function test_view_move_refuses_a_target_which_is_not_a_stored_slice(): void
        {
            $GLOBALS['permission_to']['slice'][self::POLLS] = PS_FULLTEXT;
            foreach ([self::POLLS, self::DELETED, self::MISSING, 'x', ['a'], ''] as $dest) {
                $this->assertNotSame('', self::act(self::viewMoveManager(), 'Move2slice', ['7'], ['dest_slice_id' => $dest]), var_export($dest, true));
            }
            $this->assertSame([], self::writes('view'));
        }

        public function test_the_own_view_is_updated(): void
        {
            $msg = self::postForm(self::viewManager(), '7', ['u7' => ['name' => ['renamed']]]);

            $this->assertSame('', $msg);
            $updates = self::writes('view');
            $this->assertCount(1, $updates);
            $this->assertStringContainsString('renamed', $updates[0]);
            $this->assertMatchesRegularExpression('/\bid\s*=\s*\'?7\b/', $updates[0]);
            $this->assertSame([], self::refusals());
        }

        public function test_a_view_of_another_slice_is_not_updated(): void
        {
            $msg = self::postForm(self::viewManager(), '8', ['u8' => ['name' => ['{hijacked}']]]);

            $this->assertStringContainsString('not saved', $msg);
            $this->assertSame([], self::writes('view'));
            $this->assertSame([[self::MODULE, '8']], self::refusals());
        }

        public function test_a_forged_key_of_another_view_is_not_updated(): void
        {
            // the form of the own view 7, posted with the key of another slice's view
            $msg = self::postForm(self::viewManager(), '7', ['u8' => ['name' => ['{hijacked}']]]);

            $this->assertStringContainsString('not saved', $msg);
            $this->assertSame([], self::writes('view'));
            $this->assertSame([[self::MODULE, '8']], self::refusals());
        }

        public function test_the_view_can_not_be_given_to_another_slice(): void
        {
            self::postForm(self::viewManager(), '7', ['u7' => ['name' => ['mine'], 'slice_id' => [self::OTHER]]]);

            $updates = self::writes('view');
            $this->assertCount(1, $updates);
            $this->assertStringNotContainsString(self::OTHER, $updates[0]);
            $this->assertStringContainsString(self::MODULE, $updates[0], 'stays in the slice of the page');
        }

        /** se_views.php adds views by its own form - the Manager must not insert one */
        public function test_a_manager_without_add_inserts_no_row(): void
        {
            $msg = self::postForm(self::viewManager(), '', ['n1_' . self::MODULE => ['name' => ['new'], 'type' => ['full']]]);

            $this->assertSame([], self::writes('view'));
            $this->assertNotSame('', $msg);
        }

        /** se_rssfeeds.php adds a feed - of the slice of the page, whatever the form posts */
        public function test_a_new_row_is_owned_by_the_module_of_the_page(): void
        {
            $manager = self::manager(new Managed_Table('rssfeeds'));

            $msg = self::postForm($manager, '', ['n1_' . self::MODULE => ['name' => ['feed'], 'server_url' => ['https://example.org/rss'], 'slice_id' => [self::OTHER]]]);

            $this->assertSame('', $msg);
            $inserts = self::writes('rssfeeds');
            $this->assertCount(1, $inserts);
            $this->assertStringContainsString(self::MODULE, $inserts[0]);
            $this->assertStringNotContainsString(self::OTHER, $inserts[0]);
        }

        public function test_row_delete_removes_the_own_view(): void
        {
            $this->assertSame('', self::act(self::viewManager(), 'DeleteTRow', ['7']));

            $deletes = self::writes('view');
            $this->assertCount(1, $deletes);
            $this->assertMatchesRegularExpression('/\bid\s*=\s*\'?7\b/', $deletes[0]);
        }

        public function test_row_delete_skips_a_view_of_another_slice(): void
        {
            $msg = self::act(self::viewManager(), 'DeleteTRow', ['7', '8']);

            $this->assertNotSame('', $msg);
            $deletes = self::writes('view');
            $this->assertCount(1, $deletes, 'only the own one');
            $this->assertDoesNotMatchRegularExpression('/\bid\s*=\s*\'?8\b/', $deletes[0]);
            $this->assertSame([[self::MODULE, '8']], self::refusals());
        }

        public function test_row_delete_skips_a_missing_row_silently(): void
        {
            $this->assertSame('', self::act(self::viewManager(), 'DeleteTRow', ['9']));

            $this->assertSame([], self::writes('view'));
            $this->assertSame([], self::refusals());
        }

        /** "Run for all" of se_views.php - the Manager lists only the views of the slice, so the action
         *  gets just them, and nothing is refused */
        public function test_the_action_for_all_rows_takes_the_listed_module_only(): void
        {
            $actions = new Manageractions();
            $actions->addAction(new Table_Row_Delete('DeleteTRow'));
            $managed = (new Managed_Table('view'))->setSettings(['itemview' => ['query_funct' => [[AA::Metabase(), 'queryZids'], ['table' => 'view']]]]);
            $manager = self::manager($managed, $actions);
            $manager->setRestrictionConds([new AA_Condition('slice_id', '==', pack_id(self::MODULE))]);

            self::request([], ['aa_akce_all' => '1'], ['chb' => null, 'akce' => 'DeleteTRow']);
            $manager->performActions();

            $this->assertSame('', trim(strip_tags((string) $manager->getAndClearMessages())));
            $deletes = self::writes('view');
            $this->assertCount(1, $deletes);
            $this->assertMatchesRegularExpression('/\bid\s*=\s*\'?7\b/', $deletes[0]);
            $this->assertSame([], self::refusals());
        }

        /** a Manager without a module (the e-mails of all modules - aa_emails.php, alerts/emails.php)
         *  does not check the rows */
        public function test_a_manager_without_a_module_passes_the_rows(): void
        {
            $actions = new Manageractions();
            $actions->addAction(new Table_Row_Delete('DeleteTRow'));

            self::postForm(self::manager(new Managed_Table('email'), $actions, module: null), '298', ['u298' => ['subject' => ['hello']]]);
            $this->assertCount(1, self::writes('email'));

            $this->assertSame('', self::act(self::manager(new Managed_Table('email'), $actions, module: null), 'DeleteTRow', ['298']));
            $this->assertCount(2, self::writes('email'));
            $this->assertSame([], self::refusals());
        }

        /** a table without a module column (superadmin pages like aa_cron.php) - its rows are not checked */
        public function test_a_table_without_a_module_column_is_saved_as_before(): void
        {
            $manager = self::manager(new Managed_Table('cron'));

            self::postForm($manager, '3', ['u5' => ['minutes' => ['*/5']]]);

            $this->assertCount(1, self::writes('cron'));
            $this->assertSame([], self::refusals());
        }

        // ------------------------------------------------------------------ polls (modules/polls/index.php)

        private static function pollManager(): Manager
        {
            self::perms([self::MODULE => PS_ITEMS2TRASH . PS_ITEMS2ACT . PS_DELETE_ITEMS, self::OTHER => '']);
            $actions = new Manageractions();
            $actions->addAction(new Polls_MoveItem('Activate', 1));
            $actions->addAction(new Polls_DeleteTrash('DeleteTrashAction', true));
            $manager = self::manager(new Managed_Table('polls'), $actions);
            $manager->setBin('folder3');
            return $manager;
        }

        public function test_poll_move_changes_the_own_poll(): void
        {
            $this->assertSame('', self::act(self::pollManager(), 'Activate', [self::POLL_OWN]));

            $updates = self::writes('polls');
            $this->assertCount(1, $updates);
            $this->assertStringContainsString(self::POLL_OWN, $updates[0]);
            $this->assertSame([], self::refusals());
        }

        public function test_poll_move_skips_a_poll_of_another_module(): void
        {
            $msg = self::act(self::pollManager(), 'Activate', [self::POLL_OWN, self::POLL_OTHER]);

            $this->assertNotSame('', $msg);
            $this->assertStringNotContainsString(self::POLL_OTHER, implode("\n", self::writes('polls')));
            $this->assertStringContainsString(self::POLL_OWN, implode("\n", self::writes('polls')));
            $this->assertSame([[self::MODULE, self::POLL_OTHER]], self::refusals());
        }

        public function test_poll_delete_removes_the_own_trashed_poll(): void
        {
            $this->assertSame('', self::act(self::pollManager(), 'DeleteTrashAction', [self::POLL_OWN]));

            $deletes = self::writes('polls');
            $this->assertCount(1, $deletes);
            $this->assertStringContainsString(self::POLL_OWN, $deletes[0]);
        }

        public function test_poll_delete_skips_a_poll_of_another_module(): void
        {
            $msg = self::act(self::pollManager(), 'DeleteTrashAction', [self::POLL_OWN, self::POLL_OTHER]);

            $this->assertNotSame('', $msg);
            $this->assertStringNotContainsString(self::POLL_OTHER, implode("\n", self::writes('polls')));
            $this->assertStringContainsString(self::POLL_OWN, implode("\n", self::writes('polls')));
            $this->assertSame([[self::MODULE, self::POLL_OTHER]], self::refusals());
        }

        /** the "empty trash" switch - the trash of the module, not of all */
        public function test_emptying_the_trash_removes_only_the_modules_polls(): void
        {
            $this->assertFalse((new Polls_DeleteTrash('DeleteTrash', false))->perform(self::pollManager(), new zids([], 'l'), ''));

            $deletes = self::writes('polls');
            $this->assertCount(1, $deletes);
            $this->assertStringContainsString(self::POLL_OWN, $deletes[0]);
            $this->assertStringNotContainsString(self::POLL_OTHER, $deletes[0]);
        }

        // ------------------------------------------------------------------ export to slice (admin/index.php)

        /** the Item Manager of MODULE; the user may add approved items to DEST, to HOLD only to its holding bin */
        private static function itemManager(): Manager
        {
            self::perms([self::MODULE => PS_EDIT_SELF_ITEMS, self::OTHER => '', self::DEST => PS_EDIT_SELF_ITEMS . PS_ITEMS2ACT, self::HOLD => PS_EDIT_SELF_ITEMS]);
            $actions = new Manageractions();
            $actions->addAction(new RecordingItemFeed('Feed', self::MODULE));
            return self::manager(new Managed(), $actions);
        }

        public function test_feed_exports_the_own_item_to_the_allowed_slices(): void
        {
            $msg = self::act(self::itemManager(), 'Feed', [self::ITEM_OWN], '1-' . self::DEST . ',2-' . self::HOLD);

            $this->assertSame('', $msg);
            $this->assertSame([[self::ITEM_OWN, self::DEST, 'y'], [self::ITEM_OWN, self::HOLD, 'n']], RecordingItemFeed::$fed);
            $this->assertSame([], self::refusals());
        }

        /** the item ids come from the POST - an item of another slice would be copied out of it */
        public function test_feed_skips_an_item_of_another_slice(): void
        {
            $msg = self::act(self::itemManager(), 'Feed', [self::ITEM_OWN, self::ITEM_OTHER], '1-' . self::DEST);

            $this->assertNotSame('', $msg, 'the page tells some were left');
            $this->assertSame([[self::ITEM_OWN, self::DEST, 'y']], RecordingItemFeed::$fed);
            $this->assertSame([[self::MODULE, self::ITEM_OTHER]], self::refusals());
        }

        public function test_feed_skips_a_missing_item_silently(): void
        {
            $this->assertSame('', self::act(self::itemManager(), 'Feed', [self::MISSING], '1-' . self::DEST));

            $this->assertSame([], RecordingItemFeed::$fed);
            $this->assertSame([], self::refusals());
        }

        /** the targets come from the POST too - feed_to.php offers only the slices the user may add to */
        public function test_feed_refuses_a_slice_without_the_right(): void
        {
            $msg = self::act(self::itemManager(), 'Feed', [self::ITEM_OWN], '1-' . self::OTHER . ',2-' . self::OTHER . ',1-' . self::HOLD . ',2-' . self::DEST);

            $this->assertNotSame('', $msg);
            $this->assertSame([[self::ITEM_OWN, self::DEST, 'n']], RecordingItemFeed::$fed, 'no right in OTHER, HOLD only to the holding bin');
        }

        public function test_feed_refuses_a_malformed_slice(): void
        {
            foreach (['1-not-a-slice', '1-', 'x', '1-' . self::DEST . "' OR 1=1", ['1-' . self::DEST]] as $param) {
                $this->assertNotSame('', self::act(self::itemManager(), 'Feed', [self::ITEM_OWN], $param), var_export($param, true));
            }
            $this->assertSame([], RecordingItemFeed::$fed);
        }

        public function test_feed_is_offered_to_who_may_edit_items_of_the_slice(): void
        {
            $action = new RecordingItemFeed('Feed', self::MODULE);
            $manager = self::itemManager();
            $this->assertTrue($action->isPerm4Action($manager));

            self::perms([self::MODULE => PS_EDIT_ALL_ITEMS]);
            $this->assertTrue($action->isPerm4Action($manager), 'the Item Manager opens also for this right');

            self::perms([self::MODULE => '', self::DEST => PS_EDIT_SELF_ITEMS . PS_ITEMS2ACT]);
            $this->assertFalse($action->isPerm4Action($manager));
            self::act($manager, 'Feed', [self::ITEM_OWN], '1-' . self::DEST);
            $this->assertSame([], RecordingItemFeed::$fed);
        }

        /** AA-wide rights pass any module id - the slice must exist, feed_to.php lists only those */
        public function test_feed_refuses_an_unknown_or_deleted_slice_even_with_aa_wide_rights(): void
        {
            $manager = self::itemManager();
            $GLOBALS['permission_to']['aa'][AA_ID] = \AA_Perm::ROLES['SUPER']['perm'];

            $this->assertNotSame('', self::act($manager, 'Feed', [self::ITEM_OWN], '1-' . self::MISSING . ',1-' . self::DELETED));
            $this->assertSame([], RecordingItemFeed::$fed);

            $this->assertSame('', self::act($manager, 'Feed', [self::ITEM_OWN], '1-' . self::DEST));
            $this->assertSame([[self::ITEM_OWN, self::DEST, 'y']], RecordingItemFeed::$fed);
        }

        /** 'a' and 'h' are the rights to add and approve polls in a polls module */
        public function test_feed_refuses_a_module_which_is_not_a_slice(): void
        {
            $manager = self::itemManager();
            $GLOBALS['permission_to']['slice'][self::POLLS] = PS_MODP_ADD_POLL . PS_MODP_POLLS2ACT;

            $this->assertNotSame('', self::act($manager, 'Feed', [self::ITEM_OWN], '1-' . self::POLLS . ',2-' . self::POLLS));
            $this->assertSame([], RecordingItemFeed::$fed);
        }

        /** feed_to.php posts 1 - active, 2 - holding bin; 0 means "do not export" */
        public function test_feed_skips_other_statuses(): void
        {
            $msg = self::act(self::itemManager(), 'Feed', [self::ITEM_OWN], '0-' . self::DEST . ',3-' . self::DEST . ',-' . self::DEST);

            $this->assertNotSame('', $msg, 'no slice selected');
            $this->assertSame([], RecordingItemFeed::$fed);
        }

        /** State Check tells an export from a write */
        public function test_a_refused_export_is_reported_as_one(): void
        {
            self::act(self::itemManager(), 'Feed', [self::ITEM_OTHER], '1-' . self::DEST);
            $row = RecordingDb::inserts('log')[0];

            [$finding] = CheckObjectFormRefusals::findingsFor([['days' => '1', 'first' => '1758000000', 'last' => '1758000000'] + $row], []);
            $this->assertStringContainsString('export', $finding->title);
            $this->assertStringContainsString(self::ITEM_OTHER, $finding->title);
        }

        // ------------------------------------------------------------------ move to another slice (admin/index.php)

        /** the Item Manager of MODULE; the user may move items out of it and approve items in DEST and HOLD */
        private static function moveManager(): Manager
        {
            self::perms([self::MODULE => PS_DELETE_ITEMS, self::OTHER => '', self::DEST => PS_ITEMS2ACT, self::HOLD => PS_ITEMS2ACT]);
            $actions = new Manageractions();
            $actions->addAction(new Item_Move2Slice('Move2slice', self::MODULE));
            return self::manager(new Managed(), $actions);
        }

        /** @return string the writes of the items in lowercase - the ids as the SQL names them */
        private static function moved(): string
        {
            return strtolower(implode("\n", self::writes('item')));
        }

        public function test_move_moves_the_own_item_to_a_slice(): void
        {
            $this->assertSame('', self::act(self::moveManager(), 'Move2slice', [self::ITEM_OWN], ['dest_slice_id' => self::DEST]));

            $this->assertCount(1, self::writes('item'));
            $this->assertStringContainsString('0x' . self::DEST, self::moved());
            $this->assertStringContainsString(self::ITEM_OWN, self::moved());
        }

        /** a module without a type is a slice of an old AA */
        public function test_move_moves_to_an_old_slice_without_a_type(): void
        {
            $this->assertSame('', self::act(self::moveManager(), 'Move2slice', [self::ITEM_OWN], ['dest_slice_id' => self::HOLD]));

            $this->assertStringContainsString('0x' . self::HOLD, self::moved());
        }

        public function test_move_leaves_an_item_of_another_slice(): void
        {
            self::act(self::moveManager(), 'Move2slice', [self::ITEM_OWN, self::ITEM_OTHER], ['dest_slice_id' => self::DEST]);

            $this->assertStringContainsString(self::ITEM_OWN, self::moved());
            $this->assertStringNotContainsString(self::ITEM_OTHER, self::moved());
        }

        public function test_move_refuses_a_slice_without_the_right(): void
        {
            $this->assertNotSame('', self::act(self::moveManager(), 'Move2slice', [self::ITEM_OWN], ['dest_slice_id' => self::OTHER]));
            $this->assertSame([], self::writes('item'));
        }

        /** the letters of the rights are shared by all module types ('h' approves polls in a polls module) */
        public function test_move_refuses_a_polls_module_with_the_same_letter(): void
        {
            $this->assertSame(PS_ITEMS2ACT, PS_MODP_POLLS2ACT);
            $manager = self::moveManager();
            $GLOBALS['permission_to']['slice'][self::POLLS] = PS_MODP_POLLS2ACT;

            $this->assertNotSame('', self::act($manager, 'Move2slice', [self::ITEM_OWN], ['dest_slice_id' => self::POLLS]));
            $this->assertSame([], self::writes('item'));
        }

        /** AA-wide rights pass any module id - the target must be a stored slice */
        public function test_move_refuses_a_deleted_slice(): void
        {
            $manager = self::moveManager();
            $GLOBALS['permission_to']['aa'][AA_ID] = \AA_Perm::ROLES['SUPER']['perm'];

            $this->assertNotSame('', self::act($manager, 'Move2slice', [self::ITEM_OWN], ['dest_slice_id' => self::DELETED]));
            $this->assertSame([], self::writes('item'));
        }

        public function test_move_refuses_an_unknown_module(): void
        {
            $manager = self::moveManager();
            $GLOBALS['permission_to']['aa'][AA_ID] = \AA_Perm::ROLES['SUPER']['perm'];

            $this->assertNotSame('', self::act($manager, 'Move2slice', [self::ITEM_OWN], ['dest_slice_id' => self::MISSING]));
            $this->assertSame([], self::writes('item'));
        }

        public function test_move_refuses_a_malformed_target(): void
        {
            foreach (['', self::DEST, ['dest_slice_id' => [self::DEST]], ['dest_slice_id' => 'not-a-slice'], ['dest_slice_id' => self::DEST . "' OR 1=1"]] as $param) {
                $this->assertNotSame('', self::act(self::moveManager(), 'Move2slice', [self::ITEM_OWN], $param), var_export($param, true));
            }
            $this->assertSame([], self::writes('item'));
        }

        // ------------------------------------------------------------------ poll edit page (modules/polls/polledit.php)

        public function test_is_own_row_takes_the_own_poll(): void
        {
            $this->assertTrue((new Managed_Table('polls'))->isOwnRow(self::MODULE, self::POLL_OWN));
            $this->assertSame([], self::refusals());
        }

        /** the poll id comes from the request - its update would move the poll to this module */
        public function test_is_own_row_refuses_and_logs_a_poll_of_another_module(): void
        {
            $this->assertFalse((new Managed_Table('polls'))->isOwnRow(self::MODULE, self::POLL_OTHER));
            $this->assertSame([[self::MODULE, self::POLL_OTHER]], self::refusals());
        }

        public function test_is_own_row_does_not_find_a_missing_poll(): void
        {
            $this->assertNull((new Managed_Table('polls'))->isOwnRow(self::MODULE, self::MISSING));
            $this->assertSame([], self::refusals());
        }
    }
}
