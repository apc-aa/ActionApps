<?php

declare(strict_types=1);

// The constants the legacy boot needs come with fixtures/object_form_fixtures.php.

namespace Tests\Unit\Legacy {

    use AA;
    use AA\Managed_Object;
    use AA_Object;
    use AA_Plannedtask;
    use AA_Property;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeStore;
    use Tests\Unit\Legacy\Fixtures\FormTarget;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    // the probe below extends AA_Object of include/statestore.php, which the legacy boot loads
    require_once __DIR__ . '/fixtures/object_form_fixtures.php';

    /** an object which may never be saved from a form */
    final class RefusingFormTarget extends AA_Object
    {
        public $code;

        public static function getClassProperties(): array
        {
            return ['code' => new AA_Property('code', 'Code', 'string', false)];
        }

        public function formRefusal(): ?string
        {
            return 'Not saved - the probe refuses.';
        }
    }

    /**
     * An object may refuse to be saved from a form (AA_Object::formRefusal()) - the form then shows why
     * and nothing is written, not even the delete an edit starts with. A planned task with {import} is
     * saved only by a user who manages the feeding of its slice: the page of the tasks also lets in
     * users who only edit the forms.
     */
    final class ObjectFormRefusalTest extends TestCase
    {
        private const MODULE  = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
        private const OWN     = 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1';
        private const TASK    = 'c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9';

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            AA::$module_id = self::MODULE;
            FakeStore::install([
                self::OWN  => [self::MODULE, RefusingFormTarget::class],
                self::TASK => [self::MODULE, AA_Plannedtask::class],
            ]);
            self::perms(PS_FORMS);
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            [$_GET, $_POST, $_REQUEST] = [[], [], []];
            unset($GLOBALS['permission_uid'], $GLOBALS['permission_to']);
            AA::$module_id = null;
        }

        /** @return array<string, array{string}> the id the form was built for ('' - a new object) */
        public static function formTargets(): array
        {
            return ['new object' => [''], 'edited object' => [self::OWN]];
        }

        #[DataProvider('formTargets')]
        public function test_save_refused_object_is_not_stored(string $id): void
        {
            $error = self::save(RefusingFormTarget::class, $id, ['code' => ['x']]);

            $this->assertSame('Not saved - the probe refuses.', $error);
            $this->assertSame([], self::objectWrites());
        }

        public function test_save_object_without_refusal_is_stored(): void
        {
            $this->assertNull(self::save(FormTarget::class, '', ['code' => ['{own}']]));
            $this->assertNotEmpty(self::objectWrites());
        }

        /** @return array<string, array{string, string}> task, condition */
        public static function importTasks(): array
        {
            return [
                'in task'      => ['{import:prices}', ''],
                'in condition' => ['{now:Y}', '{import:prices:check}'],
            ];
        }

        #[DataProvider('importTasks')]
        public function test_plannedtask_import_without_feeding_refused(string $task, string $condition): void
        {
            $error = self::saveTask('', $task, $condition);

            $this->assertIsString($error);
            $this->assertStringContainsString('{import}', $error);
            $this->assertSame([], self::objectWrites());
            $this->assertSame([], RecordingDb::inserts('toexecute'), 'nothing scheduled');
        }

        public function test_plannedtask_import_edit_without_feeding_refused(): void
        {
            $error = self::saveTask(self::TASK, '{import:prices}', '');

            $this->assertIsString($error);
            $this->assertStringContainsString('{import}', $error);
            $this->assertSame([], self::objectWrites(), 'the stored task stays as it was');
        }

        #[DataProvider('importTasks')]
        public function test_plannedtask_import_with_feeding_saved(string $task, string $condition): void
        {
            self::perms(PS_FORMS . PS_FEEDING);

            $this->assertNull(self::saveTask('', $task, $condition));
            $this->assertTrue(self::stores($task), 'the task is stored');
        }

        public function test_plannedtask_feeding_on_other_slice_does_not_count(): void
        {
            $GLOBALS['permission_uid'] = AuthGetUid();
            $GLOBALS['permission_to']  = ['aa' => [AA_ID => ''], 'slice' => [self::MODULE => PS_FORMS, 'b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1' => PS_FEEDING]];

            $error = self::saveTask('', '{import:prices}', '');

            $this->assertIsString($error);
            $this->assertStringContainsString('{import}', $error);
            $this->assertSame([], self::objectWrites());
        }

        public function test_plannedtask_without_import_unaffected(): void
        {
            $this->assertNull(self::saveTask('', '{now:Y}', ''));
            $this->assertTrue(self::stores('{now:Y}'));
        }

        /** the permission letters of the (anonymous) test user on MODULE */
        private static function perms(string $letters): void
        {
            $GLOBALS['permission_uid'] = AuthGetUid();
            $GLOBALS['permission_to']  = ['aa' => [AA_ID => ''], 'slice' => [self::MODULE => $letters]];
        }

        /** the form of $class on a page of MODULE posted with $values; the error the form shows, null - saved */
        private static function save(string $class, string $id, array $values): ?string
        {
            $key          = ($id === '') ? 'n1_' . self::MODULE : "u$id";
            $_POST['aa']  = [$key => $values];
            return (new Managed_Object($class))->save(self::MODULE, $id);
        }

        private static function saveTask(string $id, string $task, string $condition): ?string
        {
            // the whole form, as the page posts it
            return self::save(AA_Plannedtask::class, $id, ['task' => [$task], 'condition' => [$condition], 'event' => [''], 'time' => [''], 'shift' => ['0'], 'item_id' => ['']]);
        }

        /** @return string[] the writes (INSERT/DELETE/UPDATE) of object rows */
        private static function objectWrites(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn(string $s) => (bool) preg_match('/^(INSERT|DELETE|UPDATE|REPLACE)\b.*object_(text|integer|float)/s', $s)));
        }

        /** a value $text was inserted into the object rows */
        private static function stores(string $text): bool
        {
            return (bool) array_filter(self::objectWrites(), static fn(string $s) => str_starts_with($s, 'INSERT') && str_contains($s, $text));
        }
    }
}
