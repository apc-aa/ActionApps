<?php

declare(strict_types=1);

/**
 * BatchRun::afterDeadStep(): a step that takes over the lease of a step that died lets the run clean
 * up after it - after the claim has committed, before the work, outside any transaction.
 */

namespace Tests\Unit\Later {

    use AA\IO\DB\DB_AA;
    use AA\Later\BatchStepResult;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Central\Package\Fixtures\TableDb;
    use Tests\Unit\Later\Fixtures\BatchRunTables;
    use Tests\Unit\Later\Fixtures\ScriptedBatchRun;

    // the helper class at the end extends the fixture, so it loads with the file, not in setUpBeforeClass()
    require_once __DIR__ . '/Fixtures/BatchRunTables.php';

    final class BatchRunDeadStepTest extends TestCase
    {
        use BatchRunTables {
            tearDown as private tablesTearDown;
        }

        protected function tearDown(): void
        {
            DeadStepBatchRun::$throws = null;
            $this->tablesTearDown();
        }

        public function test_afterDeadStep_after_the_claim_before_the_work(): void
        {
            $id = self::startDeadStep();
            self::diedHolding($id, 0);
            ScriptedBatchRun::$script = [new BatchStepResult(['n' => 1], false)];

            $this->assertSame('step 1', self::runQueued());

            $this->assertSame(['afterDeadStep', 'work'], array_slice(ScriptedBatchRun::hooks(), 0, 2));
            $hook = ScriptedBatchRun::call('afterDeadStep');
            $this->assertFalse($hook['tx'], 'outside any transaction');
            $this->assertNotSame('dead-step', $hook['token'], 'the claim of this step has committed');
            $this->assertSame(ScriptedBatchRun::call('work')['token'], $hook['token']);
        }

        public function test_afterDeadStep_also_when_the_dead_step_spends_the_retries(): void
        {
            $id = self::startDeadStep();
            self::diedHolding($id, 2);

            self::runQueued();

            $this->assertSame('afterDeadStep', ScriptedBatchRun::hooks()[0]);
            $this->assertNotContains('work', ScriptedBatchRun::hooks(), 'the run errs');
            $this->assertSame('error', self::stored($id)['state']);
        }

        public function test_afterDeadStep_not_after_a_step_that_ended(): void
        {
            self::startDeadStep();
            ScriptedBatchRun::$script = [new BatchStepResult(['n' => 1], false)];

            self::runQueued();

            $this->assertNotContains('afterDeadStep', ScriptedBatchRun::hooks());
        }

        public function test_afterDeadStep_exception_counted_in_warnings(): void
        {
            $id = self::startDeadStep();
            self::diedHolding($id, 0);
            DeadStepBatchRun::$throws = new \RuntimeException('secret text');
            ScriptedBatchRun::$script = [new BatchStepResult(['n' => 1], false, warnings: ['OWN' => 2])];

            $this->assertSame('step 1', self::runQueued(), 'the step goes on');

            $stored = self::stored($id);
            $this->assertSame(['AFTER_DEAD_STEP' => 1, 'OWN' => 2], json_decode($stored['warnings'], true), 'it outlives the end of the step');
            $this->assertStringNotContainsString('secret text', json_encode($stored, JSON_THROW_ON_ERROR));
        }

        public function test_afterDeadStep_after_continue_forgave_the_dead_step(): void
        {
            $id = self::startDeadStep();
            TableDb::$tables['toexecute'] = [];          // its row got lost
            self::diedHolding($id, 2);
            ScriptedBatchRun::$now = self::NOW + 9001;
            DB_AA::transaction(static fn() => DeadStepBatchRun::lockForUpdate($id)?->resume(self::USER));
            $seen                     = [];
            ScriptedBatchRun::$script = [static function () use ($id, &$seen): BatchStepResult {
                $seen = self::stored($id);
                return new BatchStepResult(['n' => 1], false);
            }];

            $this->assertSame('step 2', self::runQueued());

            $this->assertSame(['afterDeadStep', 'work'], array_slice(ScriptedBatchRun::hooks(), 0, 2));
            $this->assertSame('0', $seen['failures'], 'Continue forgave it - no failure counted');
        }

        private static function startDeadStep(): string
        {
            return DB_AA::transaction(static fn() => DeadStepBatchRun::start(['subject' => self::SUBJECT, 'user' => self::USER, 'position' => ['n' => 0]]))->getId();
        }

        /** the run as a step left it that died holding its lease */
        private static function diedHolding(string $id, int $failures): void
        {
            self::storedSet($id, 'lease', (string) (self::NOW - 1));
            self::storedSet($id, 'lease_tok', 'dead-step');
            self::storedSet($id, 'failures', (string) $failures);
        }
    }

    /** a scripted run that logs afterDeadStep() */
    final class DeadStepBatchRun extends ScriptedBatchRun
    {
        public static ?\Throwable $throws = null;

        protected function afterDeadStep(): void
        {
            $this->log('afterDeadStep');
            if (self::$throws) {
                throw self::$throws;
            }
        }
    }
}
