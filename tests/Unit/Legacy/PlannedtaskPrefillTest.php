<?php

declare(strict_types=1);

// The constants the legacy boot needs come with fixtures/object_form_fixtures.php.

namespace Tests\Unit\Legacy {

    use AA;
    use AA\Managed_Object;
    use AA_Aliasgenerator_Object;
    use AA_Content;
    use AA_Item;
    use AA_Plannedtask;
    use AA_Slice;
    use AA_Value;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeStore;
    use Tests\Unit\Legacy\Fixtures\FormTarget;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    require_once __DIR__ . '/fixtures/object_form_fixtures.php';

    /** a slice in the module cache - its name is then read from the slice table */
    final class PrefillCachedSlice extends AA_Slice
    {
        public static function register(string $module_id): void
        {
            self::$_modules[$module_id] = new self($module_id);
        }

        public static function forget(string $module_id): void
        {
            unset(self::$_modules[$module_id]);
        }
    }

    /**
     * A link may open the form of a new planned task with a name, an {import} expression and a time
     * filled in - nothing is saved until the user posts the form. Only those three values are taken,
     * the expression only as a plain {import} call: a link from elsewhere must not slip in any
     * expression the user would just click through. Editing a stored task always shows the stored
     * values.
     */
    final class PlannedtaskPrefillTest extends TestCase
    {
        private const MODULE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
        private const TASK   = 'c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9';
        private const IMPORT = '{import:0123456789abcdef0123456789abcdef:changed}';

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            AA::$module_id = self::MODULE;
            FakeStore::install([self::TASK => [self::MODULE, AA_Plannedtask::class]]);
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            [$_GET, $_POST, $_REQUEST] = [[], [], []];
            AA::$module_id = null;
        }

        public function test_prefill_fills_only_allowed_keys_of_new_task(): void
        {
            $prefill = AA_Plannedtask::formPrefill([
                'aa_name'   => 'Import of prices',
                'task'      => self::IMPORT,
                'time'      => '03:00',
                'condition' => '{mail:x}',
                'item_id'   => self::TASK,
                'aa_owner'  => 'b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1',
            ]);

            $this->assertSame(['aa_name' => 'Import of prices', 'task' => self::IMPORT, 'time' => '03:00'], $prefill);
        }

        public function test_prefill_form_of_new_task_shows_values(): void
        {
            $_GET['prefill'] = ['aa_name' => 'Import of prices', 'task' => self::IMPORT, 'time' => '03:00', 'condition' => 'COND-FROM-LINK'];

            $html = self::formHtml('');

            $this->assertStringContainsString('Import of prices', $html);
            $this->assertStringContainsString(self::IMPORT, $html);
            $this->assertStringContainsString('03:00', $html);
            $this->assertStringNotContainsString('COND-FROM-LINK', $html);
        }

        public function test_prefill_ignored_when_editing(): void
        {
            $_GET['prefill'] = ['aa_name' => 'NAME-FROM-LINK', 'task' => self::IMPORT, 'time' => '03:00'];

            $html = self::formHtml(self::TASK);

            $this->assertStringNotContainsString('NAME-FROM-LINK', $html);
            $this->assertStringNotContainsString(self::IMPORT, $html);
            $this->assertStringNotContainsString('03:00', $html);
        }

        public function test_prefill_value_is_escaped(): void
        {
            $_GET['prefill'] = ['aa_name' => "O'Brien import"];

            $html = self::formHtml('');

            $this->assertStringNotContainsString("O'Brien", $html);
            $this->assertMatchesRegularExpression('/value="O&(#0?39|apos);Brien import"/', $html);
        }

        public function test_prefill_markup_never_reaches_form(): void
        {
            $_GET['prefill'] = ['aa_name' => '"><script>alert(1)</script>', 'time' => '"><b>'];

            $html = self::formHtml('');

            $this->assertStringNotContainsString('alert(1)', $html);
            $this->assertStringNotContainsString('"><b>', $html);
            $this->assertStringNotContainsString('&lt;b&gt;', $html);
        }

        /** @return array<string, array{string}> */
        public static function badNames(): array
        {
            return [
                'tag'              => ['<img src=x onerror=alert(1)>'],
                'ampersand'        => ['Prices & wages'],
                'double quote'     => ['The "prices"'],
                'expression'       => ['{mail:someone@example.org}'],
                'line break'       => ["Prices\nwages"],
                'empty'            => [''],
                'too long'         => [str_repeat('a', 101)],
                'invalid utf-8'    => ["Ceny \xC3"],
            ];
        }

        #[DataProvider('badNames')]
        public function test_prefill_name_with_markup_dropped(string $name): void
        {
            $this->assertSame([], AA_Plannedtask::formPrefill(['aa_name' => $name]));
        }

        /** @return array<string, array{string}> */
        public static function goodNames(): array
        {
            return ['plain' => ['Import of prices'], 'diacritics' => ['Import cen – ČSÚ (obce)'], 'longest' => [str_repeat('ž', 100)]];
        }

        #[DataProvider('goodNames')]
        public function test_prefill_name_accepted(string $name): void
        {
            $this->assertSame(['aa_name' => $name], AA_Plannedtask::formPrefill(['aa_name' => $name]));
        }

        /** @return array<string, array{string, bool}> time, accepted */
        public static function times(): array
        {
            return [
                'clock'            => ['03:00', true],
                'relative'         => ['+1 hour', true],
                'day of month'     => ['first day of this month 10:00', true],
                'nonsense'         => ['every full moon', false],
                'markup'           => ['"><b>', false],
                'empty'            => ['', false],
                'nul byte'         => ["03:00\0<b>", false],
            ];
        }

        #[DataProvider('times')]
        public function test_prefill_time_only_when_strtotime_reads_it(string $time, bool $accepted): void
        {
            $this->assertSame($accepted ? ['time' => $time] : [], AA_Plannedtask::formPrefill(['time' => $time]));
        }

        public function test_prefill_other_class_gets_nothing(): void
        {
            $_GET['prefill'] = ['aa_name' => 'NAME-FROM-LINK', 'code' => 'CODE-FROM-LINK'];

            $html = (string) (new Managed_Object(FormTarget::class))->getForm('', self::MODULE)?->getObjectEditHtml();

            $this->assertSame([], FormTarget::formPrefill($_GET['prefill']));
            $this->assertStringContainsString('[code]', $html, 'the form is rendered');
            $this->assertStringNotContainsString('NAME-FROM-LINK', $html);
            $this->assertStringNotContainsString('CODE-FROM-LINK', $html);
        }

        /** the name may come from anyone who saves a task - the list of the tasks shows it as text */
        public function test_manager_row_escapes_name(): void
        {
            $content = new AA_Content();
            $content->setAaValue('aa_name', new AA_Value('<img src=x onerror=alert(1)>'));
            $item = new AA_Item($content, AA_Aliasgenerator_Object::getAliasesFromParams(['object_type' => AA_Plannedtask::class]));

            $this->assertSame('&lt;img src=x onerror=alert(1)&gt;', $item->unalias('_#AA_NAME_'));
        }

        /** a Manager over all modules shows the module of each task by its name, which a slice admin sets */
        public function test_manager_row_escapes_module_name(): void
        {
            FakeStore::install([], ['slice' => [['id' => self::MODULE, 'name' => '<b>Prices</b>']]]);
            PrefillCachedSlice::register(self::MODULE);
            $content = new AA_Content();
            $content->setAaValue('aa_owner', new AA_Value(self::MODULE));
            $item = new AA_Item($content, AA_Aliasgenerator_Object::getAliasesFromParams(['object_type' => AA_Plannedtask::class]));

            try {
                $this->assertSame('&lt;b&gt;Prices&lt;/b&gt;', $item->unalias('_#AA_OW_NM'));
            } finally {
                PrefillCachedSlice::forget(self::MODULE);
            }
        }

        /** @return array<string, array{mixed}> */
        public static function otherTasks(): array
        {
            return [
                'other command'      => ['{mail:someone@example.org:subject:body}'],
                'import without id'  => ['{import:x}'],
                'import by name'     => ['{import:prices:changed}'],
                'unknown mode'       => ['{import:0123456789abcdef0123456789abcdef:delete}'],
                'upper case id'      => ['{import:0123456789ABCDEF0123456789ABCDEF:run}'],
                'import and more'    => [self::IMPORT . '{mail:x}'],
                'trailing newline'   => [self::IMPORT . "\n"],
                'array'              => [[self::IMPORT]],
            ];
        }

        #[DataProvider('otherTasks')]
        public function test_prefill_task_only_import_expression(mixed $task): void
        {
            $this->assertSame([], AA_Plannedtask::formPrefill(['task' => $task]));
        }

        /** @return array<string, array{mixed}> */
        public static function importTasks(): array
        {
            return ['run' => ['{import:0123456789abcdef0123456789abcdef:run}'], 'changed' => [self::IMPORT], 'check' => ['{import:0123456789abcdef0123456789abcdef:check}']];
        }

        #[DataProvider('importTasks')]
        public function test_prefill_task_import_modes_accepted(string $task): void
        {
            $this->assertSame(['task' => $task], AA_Plannedtask::formPrefill(['task' => $task]));
        }

        /** @return array<string, array{mixed}> */
        public static function notLists(): array
        {
            return ['none' => [null], 'string' => ['{import:x}'], 'number' => [3], 'array values' => [['aa_name' => ['x'], 'time' => ['03:00']]]];
        }

        #[DataProvider('notLists')]
        public function test_prefill_malformed_request_gives_nothing(mixed $requested): void
        {
            $this->assertSame([], AA_Plannedtask::formPrefill($requested));
        }

        public function test_prefill_form_with_malformed_request_renders(): void
        {
            $_GET['prefill'] = 'not-a-list';

            $this->assertStringNotContainsString('not-a-list', self::formHtml(''));
        }

        /** the form the Manager page of MODULE shows for the task $id ('' - a new one) */
        private static function formHtml(string $id): string
        {
            return (string) (new Managed_Object(AA_Plannedtask::class))->getForm($id, self::MODULE)?->getObjectEditHtml();
        }
    }
}
