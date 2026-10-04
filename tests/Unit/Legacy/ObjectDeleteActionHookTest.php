<?php

declare(strict_types=1);

// The constants the legacy boot needs come with fixtures/object_form_fixtures.php.

namespace Tests\Unit\Legacy {

    use AA;
    use AA\Import\Definition;
    use AA\Managed;
    use AA\Manageraction\AbstractManageraction;
    use AA\Manageraction\Import_Delete;
    use AA\Manageraction\Manageractions;
    use AA\Manageraction\Object_Delete;
    use AA\Manageraction\Object_Move2slice;
    use AA_Object;
    use AA_Plannedtask;
    use AA_Property;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FormTarget;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    // the probe below extends AA_Object of include/statestore.php, which the legacy boot loads
    require_once __DIR__ . '/fixtures/object_form_fixtures.php';

    /** an object the Manager never deletes */
    final class UndeletableTarget extends AA_Object
    {
        public $code;

        public static function getClassProperties(): array
        {
            return ['code' => new AA_Property('code', 'Code', 'string', false)];
        }

        protected static function deleteAction(): ?AbstractManageraction
        {
            return null;
        }
    }

    /**
     * The per-row Delete of an object Manager comes from the class (AA_Object::deleteAction()): an
     * import definition deletes its runs, stream state and catalog with it, which the generic
     * Object_Delete would leave behind. The action keeps its id, so the row button stays the same.
     */
    final class ObjectDeleteActionHookTest extends TestCase
    {
        private const MODULE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';

        protected function setUp(): void
        {
            session_id('test-session');   // the row buttons carry a FormToken, keyed with the session
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            AA::$module_id = self::MODULE;
        }

        protected function tearDown(): void
        {
            session_id('');
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            AA::$module_id = null;
        }

        /** @return array<string, array{class-string<AA_Object>}> */
        public static function plainClasses(): array
        {
            return ['form target' => [FormTarget::class], 'planned task' => [AA_Plannedtask::class]];
        }

        /** @param class-string<AA_Object> $class */
        #[DataProvider('plainClasses')]
        public function test_hook_defaultIsObjectDelete(string $class): void
        {
            $delete = self::actions($class::getManagerConf())->getAction('DeleteObject');

            $this->assertSame(Object_Delete::class, $delete ? $delete::class : null);
        }

        public function test_hook_definitionUsesImportDelete(): void
        {
            $delete = self::actions(Definition::getManagerConf())->getAction('DeleteObject');

            $this->assertSame(Import_Delete::class, $delete ? $delete::class : null);
        }

        public function test_hook_keepsActionsOfThePage(): void
        {
            $actions = new Manageractions();
            $actions->addAction(new Object_Move2slice('Move2slice', self::MODULE));

            $managed = self::actions(Definition::getManagerConf($actions));

            $this->assertInstanceOf(Object_Move2slice::class, $managed->getAction('Move2slice'));
            $this->assertInstanceOf(Import_Delete::class, $managed->getAction('DeleteObject'));
        }

        public function test_hook_nullAddsNoDelete(): void
        {
            // getAction() of an id not there warns
            $this->assertFalse(isset(self::actions(UndeletableTarget::getManagerConf())['DeleteObject']));
        }

        private static function actions(Managed $managed): Manageractions
        {
            $actions = $managed->getSettings()['actions'] ?? null;
            self::assertInstanceOf(Manageractions::class, $actions);
            return $actions;
        }
    }
}
