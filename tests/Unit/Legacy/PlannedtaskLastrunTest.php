<?php

declare(strict_types=1);

// The constants the legacy boot needs come with fixtures/object_form_fixtures.php.

namespace {

    // the probe below extends a class of include/stringexpand.php, which the legacy boot loads
    require_once __DIR__ . '/fixtures/object_form_fixtures.php';

    /** {testplannedtasklastrun:<output>|throw} - what a task's expression answers */
    class AA_Stringexpand_Testplannedtasklastrun extends AA_Stringexpand_Nevercache
    {
        public function expand(...$args): string
        {
            $what = implode(':', $args);
            if ($what === 'throw') {
                throw new RuntimeException('the task failed');
            }
            return $what;
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA;
    use AA\Managed_Object;
    use AA_Content;
    use AA_Plannedtask;
    use AA_Value;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use RuntimeException;
    use Tests\Unit\Legacy\Fixtures\FakeStore;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * A planned task notes when it last ran and a short code of how it ended, for the task list. The
     * run writes only these two properties, so a user saving the task meanwhile loses nothing, and a
     * save from the form keeps them - the form neither shows nor posts them.
     */
    final class PlannedtaskLastrunTest extends TestCase
    {
        private const MODULE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
        // ids no other test uses - AA_Object::load() caches an object for the whole run
        private const TASK   = '4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b';
        private const GONE   = '5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c';

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
            unset($GLOBALS['permission_uid'], $GLOBALS['permission_to']);
            AA::$module_id = null;
            AA::$slice_id  = null;
        }

        /** @return array<string, array{string, string}> output of the task => code stored */
        public static function outputs(): array
        {
            return [
                'nothing'            => ['', 'empty'],
                'only spaces'        => ["  \n", 'empty'],
                'any text'           => ['Sent 3 mails to a@b.cz', 'ok'],
                'import queued'      => ['imp:queued', 'imp:queued'],
                'import code padded' => [" imp:busy\n", 'imp:busy'],
                'every import code'  => ['imp:denied', 'imp:denied'],
                'not an import code' => ['imp:secret', 'ok'],
                'code inside text'   => ['done imp:queued', 'ok'],
                'two codes'          => ['imp:queuedimp:off', 'ok'],
            ];
        }

        #[DataProvider('outputs')]
        public function test_resultCode_isShortCodeNeverOutput(string $output, string $code): void
        {
            $this->assertSame($code, AA_Plannedtask::resultCode($output));
        }

        public function test_toexecutelater_recordsOnlyLastrunAndResult(): void
        {
            $before = time();

            $ret = self::task(self::TASK, '{testplannedtasklastrun:imp:queued}')->toexecutelater();

            $this->assertStringContainsString(':=imp:queued', $ret, 'the log keeps the output as before');
            $this->assertSame(['lastresult', 'lastrun'], self::writtenProperties(), 'no other property of the task is touched');
            $stored = self::stored();
            $this->assertSame('imp:queued', $stored['lastresult']);
            $this->assertGreaterThanOrEqual($before, (int) $stored['lastrun']);
            $this->assertLessThanOrEqual(time(), (int) $stored['lastrun']);
        }

        public function test_toexecutelater_conditionNotMet_recordsSkipped(): void
        {
            $task = self::task(self::TASK, '{testplannedtasklastrun:x}');
            $task->setProperty('condition', '{testplannedtasklastrun:0}');

            $task->toexecutelater();

            $this->assertSame('skipped', self::stored()['lastresult']);
        }

        public function test_toexecutelater_failedTask_recordsErrorAndRethrows(): void
        {
            try {
                self::task(self::TASK, '{testplannedtasklastrun:throw}')->toexecutelater();
                $this->fail('the task should have thrown');
            } catch (RuntimeException $e) {
                $this->assertSame('the task failed', $e->getMessage());
            }
            $this->assertSame('error', self::stored()['lastresult']);
        }

        public function test_toexecutelater_writesUnderLockInOneTransaction(): void
        {
            self::task(self::TASK, '{testplannedtasklastrun:x}')->toexecutelater();

            $log    = RecordingDb::$log;
            $begin  = array_search('BEGIN', $log, true);
            $commit = array_search('COMMIT', $log, true);
            $this->assertIsInt($begin);
            $this->assertIsInt($commit);
            $body = array_slice($log, $begin + 1, $commit - $begin - 1);
            $this->assertStringEndsWith('FOR UPDATE', $body[0] ?? '', 'the task is locked first');
            $this->assertStringContainsString("'aa_type'", $body[0]);
            $this->assertSame(self::objectWrites(), array_values(array_filter($body, static fn(string $s) => in_array($s, self::objectWrites(), true))), 'every write inside the transaction');
            $this->assertNotEmpty(self::objectWrites());
        }

        /** the task has run - a note that cannot be written changes neither its result nor its log */
        public function test_toexecutelater_failedWrite_keepsResult(): void
        {
            RecordingDb::$fail_on = 'INSERT INTO `object_text`';

            $ret = self::task(self::TASK, '{testplannedtasklastrun:imp:queued}')->toexecutelater();

            $this->assertStringContainsString(':=imp:queued', $ret);
            $this->assertContains('ROLLBACK', RecordingDb::$log);
        }

        /** an event task runs on every item saved - the cached task notes the same result once a minute */
        public function test_toexecutelater_sameResultWithinMinute_writtenOnce(): void
        {
            $task = self::task(self::TASK, '{testplannedtasklastrun:x}');

            $task->toexecutelater();
            $task->toexecutelater();

            $this->assertSame(1, RecordingDb::count('BEGIN'), 'the second run writes nothing');
        }

        public function test_toexecutelater_changedResult_writtenAtOnce(): void
        {
            $task = self::task(self::TASK, '{testplannedtasklastrun:x}');
            $task->toexecutelater();

            $task->setProperty('task', '{testplannedtasklastrun:imp:busy}');
            $task->toexecutelater();

            $this->assertSame(2, RecordingDb::count('BEGIN'));
            $this->assertSame('imp:busy', self::stored()['lastresult']);
        }

        public function test_toexecutelater_sameResultAfterMinute_writtenAgain(): void
        {
            $task = self::task(self::TASK, '{testplannedtasklastrun:x}');
            $task->setProperty('lastresult', 'ok')->setProperty('lastrun', (string) (time() - 61));

            $task->toexecutelater();

            $this->assertSame(1, RecordingDb::count('BEGIN'));
            $this->assertGreaterThan(time() - 61, (int) self::stored()['lastrun']);
        }

        public function test_form_neitherShowsNorPostsLastrun(): void
        {
            $html = (string) (new Managed_Object(AA_Plannedtask::class))->getForm(self::TASK, self::MODULE)?->getObjectEditHtml();

            $this->assertStringContainsString('[task]', $html, 'the form is rendered');
            foreach (['lastrun', 'lastresult', 'saved_by'] as $property) {
                $this->assertStringNotContainsString("[$property]", $html);
            }
        }

        /** a queued copy of a task deleted meanwhile would leave rows of an object that is gone */
        public function test_toexecutelater_deletedTask_writesNothing(): void
        {
            self::task(self::GONE, '{testplannedtasklastrun:x}')->toexecutelater();

            $this->assertSame([], self::objectWrites());
        }

        public function test_formSave_keepsStoredLastrunAndIgnoresPosted(): void
        {
            $fake = RecordingDb::$answer;
            RecordingDb::$answer = static function (string $sql) use ($fake): array {
                if (str_starts_with($sql, 'SELECT property, value FROM `object_text`') && str_contains($sql, "'lastrun'")) {
                    return [['property' => 'lastrun', 'value' => '1700000000'], ['property' => 'lastresult', 'value' => 'imp:busy']];
                }
                return $fake($sql);
            };
            $GLOBALS['permission_uid'] = AuthGetUid();
            $GLOBALS['permission_to']  = ['aa' => [AA_ID => ''], 'slice' => [self::MODULE => PS_FORMS]];
            $_POST['aa'] = ['u' . self::TASK => ['task' => ['{now:Y}'], 'condition' => [''], 'event' => [''], 'time' => [''], 'shift' => ['0'], 'item_id' => [''], 'lastrun' => ['1'], 'lastresult' => ['forged']]];

            $this->assertNull((new Managed_Object(AA_Plannedtask::class))->save(self::MODULE, self::TASK));

            $stored = self::stored();
            $this->assertSame('1700000000', $stored['lastrun']);
            $this->assertSame('imp:busy', $stored['lastresult']);
            $this->assertStringNotContainsString('forged', implode("\n", RecordingDb::$log));
        }

        /** @return array<string, array{array<string, string>, string}> stored values => what the column shows */
        public static function columns(): array
        {
            return [
                'never ran'      => [[], '--'],
                'ran at'         => [['lastrun' => '1700000000', 'lastresult' => 'ok'], date('Y-m-d H:i', 1700000000)],
                'ran fine'       => [['lastrun' => '1700000000', 'lastresult' => 'ok'], '<br>done'],
                'no output'      => [['lastrun' => '1700000000', 'lastresult' => 'empty'], 'done, no output'],
                'condition'      => [['lastrun' => '1700000000', 'lastresult' => 'skipped'], 'condition not met'],
                'failed'         => [['lastrun' => '1700000000', 'lastresult' => 'error'], 'failed'],
                'import code'    => [['lastrun' => '1700000000', 'lastresult' => 'imp:queued'], 'imp:queued'],
                'tampered value' => [['lastrun' => '1700000000', 'lastresult' => '<b>x</b>'], '&lt;b&gt;x&lt;/b&gt;'],
            ];
        }

        /** @param array<string, string> $values */
        #[DataProvider('columns')]
        public function test_internalExpand_lastrun_showsTimeAndResult(array $values, string $shown): void
        {
            $content = new AA_Content();
            $content->setAaValue('aa_id', new AA_Value(self::TASK));
            foreach ($values as $property => $value) {
                $content->setAaValue($property, new AA_Value($value));
            }

            $html = AA_Plannedtask::internal_expand($content, 'lastrun');

            $this->assertStringContainsString($shown, $html);
            $this->assertStringNotContainsString('<b>', $html);
        }

        public function test_managerRow_hasLastrunColumnUnderItsHeader(): void
        {
            $top = (new ReflectionMethod(AA_Plannedtask::class, 'getManagerTopHtml'))->invoke(null, []);
            $row = (new ReflectionMethod(AA_Plannedtask::class, 'getManagerRowHtml'))->invoke(null, [], [], ['Edit' => '', 'DeleteObject' => '']);

            preg_match_all('/<th\b[^>]*>(.*?)<\/th>/s', $top, $headers);
            preg_match_all('/<td\b[^>]*>(.*?)<\/td>/s', $row, $cells);
            $this->assertSame(count($headers[1]), count($cells[1]));
            $column = array_search('Last run', $headers[1], true);
            $this->assertIsInt($column);
            $this->assertStringContainsString('{internal:AA_Plannedtask:lastrun}', $cells[1][$column]);
        }

        private static function task(string $id, string $expression): AA_Plannedtask
        {
            $task = new AA_Plannedtask(['task' => $expression]);
            $task->setId($id)->setOwnerId(self::MODULE)->setName("task $id");
            return $task;
        }

        /** @return string[] the writes (INSERT/DELETE/UPDATE) of object rows */
        private static function objectWrites(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn(string $s) => (bool) preg_match('/^(INSERT|DELETE|UPDATE|REPLACE)\b.*object_(text|integer|float)/s', $s)));
        }

        /** @return string[] the properties deleted or inserted, sorted */
        private static function writtenProperties(): array
        {
            $props = array_column(RecordingDb::inserts('object_text'), 'property');
            foreach (self::objectWrites() as $sql) {
                if (str_starts_with($sql, 'DELETE') && preg_match_all("/'(\\w+)'/", substr($sql, (int) strpos($sql, 'property')), $m)) {
                    array_push($props, ...$m[1]);
                }
            }
            $props = array_values(array_unique($props));
            sort($props);
            return $props;
        }

        /** @return array<string, string> property => the value last inserted into object_text */
        private static function stored(): array
        {
            return array_column(RecordingDb::inserts('object_text'), 'value', 'property');
        }
    }
}
