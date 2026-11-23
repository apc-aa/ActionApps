<?php

declare(strict_types=1);

/**
 * BatchRun::stepNow(): the page that shows a run runs its due queue row at once, as the queue would -
 * only for a run that allows it (stepsFromPage()), never a row that waits or that another runner holds.
 * nextLookIn(): when such a page looks again.
 */

namespace Tests\Unit\Later {

    use AA\Later\BatchFailed;
    use AA\Later\BatchRun;
    use AA\Later\BatchStepResult;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Central\Package\Fixtures\TableDb;
    use Tests\Unit\Later\Fixtures\BatchRunTables;
    use Tests\Unit\Later\Fixtures\ScriptedBatchRun;

    require_once __DIR__ . '/Fixtures/BatchRunTables.php';

    final class BatchRunStepNowTest extends TestCase
    {
        use BatchRunTables;

        private static function fromPage(bool $allowed = true): void
        {
            ScriptedBatchRun::$hookValues['stepsFromPage'] = $allowed;
        }

        /** moves the run's queue row to $time, as a retry or another runner's claim does */
        private static function rowAt(int $time): void
        {
            foreach (TableDb::$tables['toexecute'] as $i => $row) {
                TableDb::$tables['toexecute'][$i]['execute_after'] = $time;
            }
        }

        public function test_runs_the_due_step_at_once(): void
        {
            self::fromPage();
            $id = self::start()->getId();
            ScriptedBatchRun::$script = [new BatchStepResult(['n' => 5], false, done: 1, total: 4)];

            $this->assertTrue(ScriptedBatchRun::stepNow($id, self::USER));

            $this->assertSame(['work', 'commitStep'], ScriptedBatchRun::hooks());
            $stored = self::stored($id);
            $this->assertSame(['n' => 5], json_decode($stored['position'], true));
            $this->assertSame('1', $stored['step']);
            $this->assertCount(1, self::queue(), 'the row run is gone, the next step is queued');
            $this->assertSame(0, ScriptedBatchRun::get($id)->nextLookIn(self::NOW), 'the next step is due at once');
        }

        public function test_a_run_that_does_not_allow_it_waits_for_the_queue(): void
        {
            $id    = self::start()->getId();
            $queue = self::queue();

            $this->assertFalse(ScriptedBatchRun::stepNow($id, self::USER));

            $this->assertSame([], ScriptedBatchRun::hooks());
            $this->assertSame($queue, self::queue());
        }

        public function test_a_step_that_waits_for_its_retry_is_left_alone(): void
        {
            self::fromPage();
            $id = self::start()->getId();
            self::rowAt(self::NOW + 60);

            $this->assertFalse(ScriptedBatchRun::stepNow($id, self::USER));

            $this->assertSame([], ScriptedBatchRun::hooks());
            $this->assertSame(60, ScriptedBatchRun::get($id)->nextLookIn(self::NOW));
        }

        public function test_a_row_another_runner_claimed_is_left_alone(): void
        {
            self::fromPage();
            $id = self::start()->getId();
            self::leased($id);
            self::rowAt(self::NOW + 1800);           // the claim pushes the row by the queue's lease

            $this->assertFalse(ScriptedBatchRun::stepNow($id, self::USER));

            $this->assertSame([], ScriptedBatchRun::hooks());
            $this->assertSame(1800, ScriptedBatchRun::get($id)->nextLookIn(self::NOW), 'the page caps the wait itself');
        }

        public function test_a_finished_run_has_no_step(): void
        {
            self::fromPage();
            $id = self::start()->getId();
            ScriptedBatchRun::$script = [new BatchStepResult(['n' => 1], true)];
            ScriptedBatchRun::stepNow($id, self::USER);             // the work
            ScriptedBatchRun::stepNow($id, self::USER);             // the final phase
            $this->assertSame('done', self::stored($id)['state']);
            $calls = ScriptedBatchRun::hooks();

            $this->assertFalse(ScriptedBatchRun::stepNow($id, self::USER));

            $this->assertSame($calls, ScriptedBatchRun::hooks());
            $this->assertNull(ScriptedBatchRun::get($id)->nextLookIn(self::NOW));
        }

        public function test_an_active_run_with_no_row_is_looked_at_again_later(): void
        {
            $id = self::start()->getId();
            TableDb::$tables['toexecute'] = [];

            $this->assertSame(BatchRun::LOOK_AGAIN, ScriptedBatchRun::get($id)->nextLookIn(self::NOW));
        }

        public function test_refused_to_one_who_may_not_control_the_run(): void
        {
            self::fromPage();
            $id = self::start()->getId();

            foreach ([[$id, 'someone-else'], ['0123456789abcdef0123456789abcdef', self::USER]] as [$run, $uid]) {
                try {
                    ScriptedBatchRun::stepNow($run, $uid);
                    $this->fail('A step run for one who may not');
                } catch (BatchFailed $e) {
                    $this->assertSame(BatchRun::FORBIDDEN, $e->reason);
                }
            }
            $this->assertSame([], ScriptedBatchRun::hooks());
        }
    }
}
