<?php

declare(strict_types=1);

// The constants the legacy boot needs come with fixtures/object_form_fixtures.php.

namespace {

    // the probe below extends a class of include/stringexpand.php, which the legacy boot loads
    require_once __DIR__ . '/fixtures/object_form_fixtures.php';

    /** {testplannedtaskrunning:id|nested|throw} - the id of the task that runs while the expression expands */
    class AA_Stringexpand_Testplannedtaskrunning extends AA_Stringexpand_Nevercache
    {
        /** the task {testplannedtaskrunning:nested} runs inside the outer one */
        public static ?AA_Plannedtask $inner = null;
        /** @var list<string> what the nested task returned */
        public static array $inner_results = [];

        public function expand($what = ''): string
        {
            if ($what === 'throw') {
                throw new RuntimeException('the task failed');
            }
            if (($what === 'nested') && self::$inner) {
                self::$inner_results[] = self::$inner->toexecutelater();
            }
            return 'running-' . (AA_Plannedtask::running()?->getId() ?? 'none');
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA;
    use AA_Plannedtask;
    use AA_Stringexpand_Testplannedtaskrunning;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * A planned task's expression may need to know which task runs it - an import starts only from a
     * task, never from a view or a page that happens to expand the same expression. The task is known
     * only while its toexecutelater() runs, also when it fails, and a task run by an event inside
     * another task hands the context back to the outer one when it ends.
     */
    final class PlannedtaskRunningTest extends TestCase
    {
        private const MODULE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
        private const OUTER  = 'c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5c5';
        private const INNER  = 'd5d5d5d5d5d5d5d5d5d5d5d5d5d5d5d5';

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            AA_Stringexpand_Testplannedtaskrunning::$inner         = null;
            AA_Stringexpand_Testplannedtaskrunning::$inner_results = [];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            AA::$slice_id = null;
        }

        private static function task(string $id, string $expression): AA_Plannedtask
        {
            $task = new AA_Plannedtask(['task' => $expression]);
            $task->setId($id)->setOwnerId(self::MODULE)->setName("task $id");
            return $task;
        }

        public function test_running_isSetDuringTaskAndClearedAfter(): void
        {
            $this->assertNull(AA_Plannedtask::running());

            $ret = self::task(self::OUTER, '{testplannedtaskrunning:id}')->toexecutelater();

            $this->assertStringContainsString('running-' . self::OUTER, $ret);
            $this->assertNull(AA_Plannedtask::running());
        }

        public function test_running_clearedWhenTaskThrows(): void
        {
            try {
                self::task(self::OUTER, '{testplannedtaskrunning:throw}')->toexecutelater();
                $this->fail('the task should have thrown');
            } catch (RuntimeException $e) {
                $this->assertSame('the task failed', $e->getMessage());
            }
            $this->assertNull(AA_Plannedtask::running());
        }

        public function test_running_restoresOuterTaskWhenNested(): void
        {
            AA_Stringexpand_Testplannedtaskrunning::$inner = self::task(self::INNER, '{testplannedtaskrunning:id}');

            $ret = self::task(self::OUTER, '{testplannedtaskrunning:nested}')->toexecutelater();

            $this->assertStringContainsString('running-' . self::INNER, AA_Stringexpand_Testplannedtaskrunning::$inner_results[0] ?? '');
            $this->assertStringContainsString('running-' . self::OUTER, $ret, 'after the inner task the outer one runs again');
            $this->assertNull(AA_Plannedtask::running());
        }
    }
}
