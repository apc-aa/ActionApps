<?php

declare(strict_types=1);

/**
 * BatchRun::pageLook(): the page that shows runs runs the due queue row of the first of them at once, as
 * the queue would - only for a run that allows it (stepsFromPage()) and one the user may control, never
 * a row that waits or that another runner holds - and says when to look again.
 */

namespace Tests\Unit\Later {

    use AA\Later\BatchRun;
    use AA\Later\BatchStepResult;
    use AA\Later\Toexecute;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Central\Package\Fixtures\TableDb;
    use Tests\Unit\Later\Fixtures\BatchRunTables;
    use Tests\Unit\Later\Fixtures\ScriptedBatchRun;

    require_once __DIR__ . '/Fixtures/BatchRunTables.php';

    final class BatchRunPageLookTest extends TestCase
    {
        use BatchRunTables;

        private static function fromPage(bool $allowed = true): void
        {
            ScriptedBatchRun::$hookValues['stepsFromPage'] = $allowed;
        }

        /** moves the queue rows of run $id to $time, as a retry or another runner's claim does */
        private static function rowAt(string $id, int $time): void
        {
            foreach (TableDb::$tables['toexecute'] as $i => $row) {
                if ($row['selector'] === "Scripted_$id") {
                    TableDb::$tables['toexecute'][$i]['execute_after'] = $time;
                }
            }
        }

        public function test_pageLook_runs_the_due_step_at_once(): void
        {
            self::fromPage();
            $id = self::start()->getId();
            ScriptedBatchRun::$script = [new BatchStepResult(['n' => 5], false, done: 1, total: 4)];

            $this->assertSame(0, ScriptedBatchRun::pageLook([$id], self::USER), 'the next step is due at once');

            $this->assertSame(['work', 'commitStep'], ScriptedBatchRun::hooks());
            $stored = self::stored($id);
            $this->assertSame(['n' => 5], json_decode($stored['position'], true));
            $this->assertSame('1', $stored['step']);
            $this->assertCount(1, self::queue(), 'the row run is gone, the next step is queued');
            $rounds = array_filter(TableDb::rows('log'), static fn(array $r): bool => $r['selector'] === Toexecute::class);
            $this->assertSame([], $rounds, 'no queue round logged - the State Check must not take the page for the queue');
        }

        public function test_pageLook_runs_the_first_run_with_a_due_step(): void
        {
            self::fromPage();
            $first  = self::start()->getId();
            $second = self::start()->getId();
            ScriptedBatchRun::$script = [new BatchStepResult(['n' => 1], false)];

            $this->assertSame(0, ScriptedBatchRun::pageLook([$second, $first], self::USER));

            $this->assertSame('1', self::stored($second)['step']);
            $this->assertSame('0', self::stored($first)['step'], 'one step a look');
        }

        public function test_pageLook_leaves_a_run_that_does_not_allow_it_to_the_queue(): void
        {
            $id    = self::start()->getId();
            $queue = self::queue();

            $this->assertSame(BatchRun::LOOK_AGAIN, ScriptedBatchRun::pageLook([$id], self::USER));

            $this->assertSame([], ScriptedBatchRun::hooks());
            $this->assertSame($queue, self::queue());
        }

        public function test_pageLook_leaves_a_step_that_waits_for_its_retry(): void
        {
            self::fromPage();
            $id = self::start()->getId();
            self::rowAt($id, self::NOW + 5);

            $this->assertSame(5, ScriptedBatchRun::pageLook([$id], self::USER));
            self::rowAt($id, self::NOW + 60);
            $this->assertSame(BatchRun::LOOK_AGAIN, ScriptedBatchRun::pageLook([$id], self::USER), 'the page looks meanwhile');

            $this->assertSame([], ScriptedBatchRun::hooks());
        }

        public function test_pageLook_leaves_a_row_another_runner_claimed(): void
        {
            self::fromPage();
            $id = self::start()->getId();
            self::leased($id);
            self::rowAt($id, self::NOW + 1800);           // the claim pushes the row by the queue's lease

            $this->assertSame(BatchRun::LOOK_AGAIN, ScriptedBatchRun::pageLook([$id], self::USER));

            $this->assertSame([], ScriptedBatchRun::hooks());
        }

        public function test_pageLook_of_a_finished_run_is_null(): void
        {
            self::fromPage();
            $id = self::start()->getId();
            ScriptedBatchRun::$script = [new BatchStepResult(['n' => 1], true)];

            $this->assertNull(ScriptedBatchRun::pageLook([$id], self::USER), 'the work and the final phase in one look');

            $this->assertSame('done', self::stored($id)['state']);
            $calls = ScriptedBatchRun::hooks();
            $this->assertNull(ScriptedBatchRun::pageLook([$id], self::USER));
            $this->assertSame($calls, ScriptedBatchRun::hooks(), 'nothing more ran');
        }

        public function test_pageLook_of_an_active_run_with_no_row_looks_again_later(): void
        {
            $id = self::start()->getId();
            TableDb::$tables['toexecute'] = [];

            $this->assertSame(BatchRun::LOOK_AGAIN, ScriptedBatchRun::pageLook([$id], self::USER));
        }

        public function test_pageLook_skips_runs_one_may_not_control(): void
        {
            self::fromPage();
            $id = self::start()->getId();

            $this->assertNull(ScriptedBatchRun::pageLook([$id], 'someone-else'));
            $this->assertNull(ScriptedBatchRun::pageLook(['0123456789abcdef0123456789abcdef'], self::USER), 'no such run');

            $this->assertSame([], ScriptedBatchRun::hooks());
            $this->assertSame('0', self::stored($id)['step']);
        }
    }
}
