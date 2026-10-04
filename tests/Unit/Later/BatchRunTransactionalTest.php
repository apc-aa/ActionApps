<?php

declare(strict_types=1);

/**
 * BatchRun whose work() writes in the transaction that ends the step (stepInTransaction(), Central:
 * a batch of items), with the data fetched before it, outside any transaction (fetch()).
 */

namespace Tests\Unit\Later;

use AA;
use AA\IO\DB\DB_AA;
use AA\Later\BatchBudget;
use AA\Later\BatchBudgetSpent;
use AA\Later\BatchFailed;
use AA\Later\BatchPosition;
use AA\Later\BatchRun;
use AA\Later\BatchStepResult;
use AA\Later\Toexecute;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Later\Fixtures\BatchRunTables;
use Tests\Unit\Later\Fixtures\BothStepModes;
use Tests\Unit\Later\Fixtures\ScriptedBatchRun;

require_once __DIR__ . '/Fixtures/BatchRunTables.php';

final class BatchRunTransactionalTest extends TestCase
{
    use BatchRunTables;
    use BothStepModes;

    /** runs $work inside a queue round of $allowed seconds of which $elapsed are gone */
    private static function inRound(float $allowed, float $elapsed, \Closure $work): mixed
    {
        $round = new \ReflectionProperty(Toexecute::class, 'round');
        $outer = $round->getValue();
        $round->setValue(null, [microtime(true) - $elapsed, $allowed]);
        try {
            return $work();
        } finally {
            $round->setValue(null, $outer);
        }
    }

    /** @return list<string> what the queued rows expect */
    private static function queued(): array
    {
        return array_map(static fn(array $row): string => self::stepOf($row)->expected, self::queue());
    }

    /** what the database keeps of the tables when the process dies now: an open transaction rolls back */
    private static function committed(): array
    {
        return (new \ReflectionProperty(TableDb::class, 'snapshot'))->getValue() ?? TableDb::$tables;
    }

    // ---------------------------------------------------------------- one transaction

    public function test_transactional_work_and_end_of_step_in_one_transaction(): void
    {
        self::mode(true);
        $id   = self::start()->getId();
        $from = 0;
        ScriptedBatchRun::$fetchScript = [['items' => 3]];
        ScriptedBatchRun::$script      = [static function (ScriptedBatchRun $run, array $at, BatchBudget $budget, mixed $fetched) use (&$from): BatchStepResult {
            $from = count(TableDb::$log);
            self::writeBatch('batch of ' . $fetched['items']);
            return new BatchStepResult(['n' => 3], false, ['written' => 3]);
        }];

        $this->assertSame('step 1', self::runQueued());

        $fetch = ScriptedBatchRun::call('fetch');
        $work  = ScriptedBatchRun::call('work');
        $this->assertSame([false, false, $id], [$fetch['tx'], $fetch['locked'], $fetch['running']], 'fetch() may wait for the source - outside any transaction');
        $this->assertSame([true, true, $id], [$work['tx'], $work['locked'], $work['running']], 'work() on the locked run');
        $this->assertNotSame('', $work['token']);
        $this->assertSame(['n' => 0], $work['arg']);

        // from work() to the commit: its batch, the cursor and the next step - nothing commits between
        $rest   = array_slice(TableDb::$log, $from);
        $commit = array_search('COMMIT', $rest, true);
        $this->assertNotFalse($commit);
        $inside = implode("\n", array_slice($rest, 0, $commit));
        $this->assertStringContainsString('INSERT INTO `log`', $inside);
        $this->assertStringContainsString("INSERT INTO `object_text` (`object_id`,`property`,`priority`,`value`) VALUES ('$id','position'", $inside);
        $this->assertStringContainsString('INSERT INTO `toexecute`', $inside);
        $this->assertNotContains('BEGIN', array_slice($rest, 0, $commit));

        $stored = self::stored($id);
        $this->assertSame(['batch of 3'], self::batches());
        $this->assertSame(['n' => 3], json_decode($stored['position'], true));
        $this->assertSame('1', $stored['step']);
        $this->assertSame('', $stored['lease_tok']);
        $this->assertSame(['written' => 3], json_decode($stored['counts'], true));
        $this->assertSame([BatchPosition::fingerprint(1, ['n' => 3])], self::queued());
    }

    public function test_transactional_foreign_lease_token_discards_data(): void
    {
        self::mode(true);
        $id = self::start()->getId();
        ScriptedBatchRun::$fetchScript = [static function () use ($id): array {
            self::storedSet($id, 'lease_tok', 'another-step');       // claimed again while this step fetched
            return ['items' => 3];
        }];
        $before = self::stored($id);

        $this->assertSame('lease lost', self::runQueued());

        $this->assertSame(['fetch'], ScriptedBatchRun::hooks(), 'the fetched batch is dropped unwritten');
        $after = self::stored($id);
        $this->assertSame([$before['step'], $before['position'], 'another-step'], [$after['step'], $after['position'], $after['lease_tok']]);
        $this->assertSame([], self::batches());
        $this->assertSame([], self::queue(), 'no next step');
    }

    // ---------------------------------------------------------------- failures

    public function test_transactional_work_throws_rollback_then_error(): void
    {
        self::mode(true);
        $id = self::start()->getId();
        ScriptedBatchRun::$script = [static function (): never {
            self::writeBatch('half a batch');
            AA::Contentcache()->set('batch-test', 'read from the half batch');
            throw new BatchFailed('TARGET_FULL', 'batch 2');
        }];

        $this->assertSame('error TARGET_FULL', self::runQueued());

        $this->assertFalse(AA::Contentcache()->get('batch-test'), 'what the request cached may come from the rolled back writes');

        $this->assertSame([], self::batches(), 'what work() wrote is rolled back');
        $stored = self::stored($id);
        $this->assertSame('error', $stored['state']);
        $this->assertSame(['TARGET_FULL', 'batch 2'], [$stored['error_code'], $stored['error_path']]);
        $this->assertSame(['n' => 0], json_decode($stored['position'], true));
        $this->assertSame('1', $stored['step']);
        $this->assertSame('', $stored['lease_tok']);
        $this->assertSame([], self::queue());
        $this->assertSame(['work'], ScriptedBatchRun::hooks(), 'no commitStep()');
        $rollback = array_search('ROLLBACK', TableDb::$log, true);
        $this->assertNotFalse($rollback);
        $this->assertContains('BEGIN', array_slice(TableDb::$log, $rollback), 'the error in a transaction of its own');
    }

    public function test_transactional_work_throws_error_only_with_own_lease_token(): void
    {
        self::mode(true);
        $id = self::start()->getId();
        ScriptedBatchRun::$script = [static function (): never {
            self::writeBatch('half a batch');
            throw new BatchFailed('TARGET_FULL');
        }];
        // between the rollback and the error's transaction another step claims the run
        TableDb::$answer = static function (string $sql) use ($id): ?array {
            if (in_array('ROLLBACK', TableDb::$log, true) && str_ends_with($sql, ' FOR UPDATE')) {
                TableDb::$answer = null;
                self::storedSet($id, 'lease_tok', 'another-step');
            }
            return null;
        };

        $this->assertSame('lease lost', self::runQueued());

        $stored = self::stored($id);
        $this->assertSame(['running', '', 'another-step', '0'], [$stored['state'], $stored['error_code'], $stored['lease_tok'], $stored['step']]);
        $this->assertSame([], self::batches());
    }

    public function test_transactional_fatal_leaves_claim_dead_step_next_time(): void
    {
        self::mode(true);
        $id   = self::start()->getId();
        $died = [];
        ScriptedBatchRun::$script = [static function () use (&$died): BatchStepResult {
            self::writeBatch('batch 1');
            $died = self::committed();          // the process dies here, past any catch
            return new BatchStepResult(['n' => 1], false);
        }];
        self::runQueued();
        TableDb::$tables         = $died;
        ScriptedBatchRun::$calls = [];

        $stored = self::stored($id);
        $this->assertSame([], self::batches());
        $this->assertSame(['running', '0', '{"n":0}'], [$stored['state'], $stored['step'], $stored['position']]);
        $this->assertNotSame('', $stored['lease_tok'], 'the claim stays');
        $this->assertSame([BatchPosition::fingerprint(0, ['n' => 0])], self::queued(), 'and so does the dead step\'s own row');

        // the queue runs that row again once its lease is out; this time the step gets through
        $seen                     = [];
        ScriptedBatchRun::$now    = (int) $stored['lease'] + 1;
        ScriptedBatchRun::$script = [static function () use ($id, &$seen): BatchStepResult {
            $seen = self::stored($id);
            self::writeBatch('batch 1');
            return new BatchStepResult(['n' => 1], false);
        }];

        $this->assertSame('step 1', self::runQueued());

        $this->assertSame('1', $seen['failures'], 'the dead step counts');
        $this->assertSame(['n' => 0], ScriptedBatchRun::call('work')['arg'], 'from the stored position');
        $this->assertSame(['batch 1'], self::batches());
        $this->assertSame('0', self::stored($id)['failures'], 'its progress clears them');
    }

    /** a no-progress result of work() is written with the step; retries spent, it ends in error -
     *  onRetriesExhausted() may ask the source, so it never runs inside the step's transaction */
    public function test_transactional_work_without_progress_exhausted_errors_without_hook(): void
    {
        self::mode(true);
        $id = self::start()->getId();
        self::storedSet($id, 'failures', '2');
        ScriptedBatchRun::$onExhausted = static fn(BatchStepResult $last): array => ['n' => 1];
        ScriptedBatchRun::$script      = [new BatchStepResult(['n' => 0], false, retry: 'SOURCE_UNSTABLE')];

        $this->assertSame('error SOURCE_UNSTABLE', self::runQueued());

        $this->assertSame(['work', 'commitStep'], ScriptedBatchRun::hooks());
        $this->assertSame(['error', '3'], [self::stored($id)['state'], self::stored($id)['failures']]);
    }

    // ---------------------------------------------------------------- fetch()

    #[DataProvider('bothModes')]
    public function test_fetch_retry_result_skips_work(bool $inTransaction): void
    {
        self::mode($inTransaction);
        $id = self::start()->getId();
        ScriptedBatchRun::$retryDelays = [1 => 300];
        ScriptedBatchRun::$fetchScript = [new BatchStepResult(['n' => 0], false, retry: 'TRANSPORT')];

        $this->assertSame('step 1', self::runQueued());

        $this->assertSame(['fetch', 'commitStep'], ScriptedBatchRun::hooks(), 'no data, no work()');
        $stored = self::stored($id);
        $this->assertSame(['running', '1', '1'], [$stored['state'], $stored['failures'], $stored['step']]);
        $this->assertSame((string) (self::NOW + 300), $stored['next_at'], 'retryDelay(failures)');
        $this->assertSame(self::NOW + 300, self::queue()[0]['execute_after']);
    }

    #[DataProvider('bothModes')]
    public function test_fetch_retry_exhausted_asks_hook_outside_transaction(bool $inTransaction): void
    {
        self::mode($inTransaction);
        $id = self::start()->getId();
        self::storedSet($id, 'failures', '2');
        ScriptedBatchRun::$fetchScript = [new BatchStepResult(['n' => 0], false, retry: 'TRANSPORT')];
        ScriptedBatchRun::$onExhausted = static fn(BatchStepResult $last): array => ['n' => 1];

        $this->assertSame('step 1', self::runQueued());

        $this->assertSame(['fetch', 'onRetriesExhausted', 'commitStep'], ScriptedBatchRun::hooks());
        $this->assertFalse(ScriptedBatchRun::call('onRetriesExhausted')['tx']);
        $this->assertSame(['n' => 1], json_decode(self::stored($id)['position'], true));
        $this->assertSame('0', self::stored($id)['failures']);
    }

    #[DataProvider('bothModes')]
    public function test_fetch_batch_failed_errors(bool $inTransaction): void
    {
        self::mode($inTransaction);
        $id = self::start()->getId();
        ScriptedBatchRun::$fetchScript = [new BatchFailed('SOURCE_LOGIN', 'batch 3')];

        $this->assertSame('error SOURCE_LOGIN', self::runQueued());

        $this->assertSame(['fetch'], ScriptedBatchRun::hooks());
        $stored = self::stored($id);
        $this->assertSame(['error', 'SOURCE_LOGIN', 'batch 3', '1', ''], [$stored['state'], $stored['error_code'], $stored['error_path'], $stored['step'], $stored['lease_tok']]);
        $this->assertSame([], self::queue());
    }

    #[DataProvider('bothModes')]
    public function test_fetch_hands_its_data_to_work(bool $inTransaction): void
    {
        self::mode($inTransaction);
        self::start();
        $got = null;
        ScriptedBatchRun::$fetchScript = [['batch' => [1, 2]]];
        ScriptedBatchRun::$script      = [static function (ScriptedBatchRun $run, array $from, BatchBudget $budget, mixed $fetched) use (&$got): BatchStepResult {
            $got = $fetched;
            return new BatchStepResult(['n' => 2], false);
        }];

        self::runQueued();

        $this->assertSame(['batch' => [1, 2]], $got);
    }

    #[DataProvider('bothModes')]
    public function test_fetch_end_during_fetch_nothing_written(bool $inTransaction): void
    {
        self::mode($inTransaction);
        $id = self::start()->getId();
        ScriptedBatchRun::$fetchScript = [static function () use ($id): array {
            DB_AA::transaction(static fn() => (ScriptedBatchRun::lockForUpdate($id) ?? throw new LogicException('No run'))->end(self::USER));
            return ['items' => 3];
        }];
        ScriptedBatchRun::$script = [static function (): BatchStepResult {
            self::writeBatch('batch 1');
            return new BatchStepResult(['n' => 3], false, ['written' => 3]);
        }];

        $this->assertSame('run ended', self::runQueued());

        $stored = self::stored($id);
        $this->assertSame(['ended', '{"n":0}', '0', ''], [$stored['state'], $stored['position'], $stored['step'], $stored['counts']]);
        $this->assertSame(['fetch'], ScriptedBatchRun::hooks(), 'no work() for an ended run');
        $this->assertSame([], self::batches(), 'the fetched batch is not written');
        $this->assertSame([BatchRun::CLOSE_ROW], self::queued(), 'no next step');

        self::runQueued();
        $this->assertSame(1, array_count_values(ScriptedBatchRun::hooks())['afterClose'] ?? 0, 'afterClose(ended) once');
    }

    public function test_fetch_by_default_gives_work_nothing(): void
    {
        self::start();
        $got = 'unset';
        ScriptedBatchRun::$script = [static function (ScriptedBatchRun $run, array $from, BatchBudget $budget, mixed $fetched) use (&$got): BatchStepResult {
            $got = $fetched;
            return new BatchStepResult(['n' => 2], false);
        }];

        self::runQueued();

        $this->assertNull($got);
    }

    // ---------------------------------------------------------------- the lease and the budget

    public function test_fetch_lease_run_out_without_transaction_drops_data_and_claims_again(): void
    {
        $id = self::start()->getId();
        ScriptedBatchRun::$fetchScript = [static function () use ($id): array {
            ScriptedBatchRun::$now = (int) self::stored($id)['lease'];         // fetch() took longer than the lease
            return ['items' => 3];
        }];

        $this->assertSame('lease out', self::runQueued());

        $this->assertSame(['fetch'], ScriptedBatchRun::hooks(), 'another step may claim the run while work() would write');
        $this->assertSame('0', self::stored($id)['step']);
        $this->assertSame([BatchPosition::fingerprint(0, ['n' => 0])], self::queued(), 'the run is not left without a step');
        $this->assertSame(ScriptedBatchRun::$now, self::queue()[0]['execute_after']);

        ScriptedBatchRun::$script = [new BatchStepResult(['n' => 3], false)];
        $this->assertSame('step 1', self::runQueued());
        $this->assertSame('0', self::stored($id)['failures'], 'the step that ran out counted, its progress cleared it');
        $this->assertSame(['n' => 0], ScriptedBatchRun::call('work')['arg']);
    }

    public function test_transactional_lease_run_out_unclaimed_still_writes(): void
    {
        self::mode(true);
        $id = self::start()->getId();
        ScriptedBatchRun::$fetchScript = [static function () use ($id): array {
            ScriptedBatchRun::$now = (int) self::stored($id)['lease'] + 5;
            return [];
        }];
        ScriptedBatchRun::$script = [static function (): BatchStepResult {
            self::writeBatch('batch 1');
            return new BatchStepResult(['n' => 1], false);
        }];

        $this->assertSame('step 1', self::runQueued(), 'under the lock with its own token, no other step can write');
        $this->assertSame(['batch 1'], self::batches());
    }

    #[DataProvider('bothModes')]
    public function test_budget_spent_before_fetch_is_no_progress(bool $inTransaction): void
    {
        self::mode($inTransaction);
        $id = self::start()->getId();
        ScriptedBatchRun::$hookValues  = ['minStepSeconds' => 0];
        ScriptedBatchRun::$fetchScript = [['items' => 3]];

        $this->assertSame('step 1', self::inRound(20.0, 18.5, static fn(): string => self::runQueued()));

        $this->assertSame(['commitStep'], ScriptedBatchRun::hooks(), 'no fetch() - a request would get no timeout');
        $stored = self::stored($id);
        $this->assertSame(['running', '1', '1', (string) self::NOW], [$stored['state'], $stored['failures'], $stored['step'], $stored['next_at']]);
    }

    #[DataProvider('bothModes')]
    public function test_fetch_out_of_budget_is_no_progress(bool $inTransaction): void
    {
        self::mode($inTransaction);
        $id = self::start()->getId();
        ScriptedBatchRun::$fetchScript = [new BatchBudgetSpent()];

        $this->assertSame('step 1', self::runQueued());

        $this->assertSame(['fetch', 'commitStep'], ScriptedBatchRun::hooks());
        $stored = self::stored($id);
        $this->assertSame(['running', '1', '{"n":0}', (string) self::NOW], [$stored['state'], $stored['failures'], $stored['position'], $stored['next_at']]);
    }

    /** a safety net only: what work() stored before stays, and the next step does it again from $from */
    public function test_work_out_of_budget_without_transaction_is_no_progress(): void
    {
        $id = self::start()->getId();
        ScriptedBatchRun::$script = [static function (): never {
            self::writeBatch('batch 1');
            throw new BatchBudgetSpent();
        }];

        $this->assertSame('step 1', self::runQueued());

        $this->assertSame(['batch 1'], self::batches());
        $this->assertSame(['work', 'commitStep'], ScriptedBatchRun::hooks());
        $stored = self::stored($id);
        $this->assertSame(['running', '1', '1', '{"n":0}', '', (string) self::NOW], [$stored['state'], $stored['failures'], $stored['step'], $stored['position'], $stored['lease_tok'], $stored['next_at']]);
        $this->assertSame([BatchPosition::fingerprint(1, ['n' => 0])], self::queued());
    }

    public function test_transactional_work_out_of_budget_rolls_back_then_no_progress(): void
    {
        self::mode(true);
        $id = self::start()->getId();
        ScriptedBatchRun::$script = [static function (): never {
            self::writeBatch('half a batch');
            throw new BatchBudgetSpent();
        }];

        $this->assertSame('step 1', self::runQueued());

        $this->assertSame([], self::batches());
        $stored = self::stored($id);
        $this->assertSame(['running', '1', '1', '{"n":0}', ''], [$stored['state'], $stored['failures'], $stored['step'], $stored['position'], $stored['lease_tok']]);
        $this->assertSame([BatchPosition::fingerprint(1, ['n' => 0])], self::queued());
    }

    // ---------------------------------------------------------------- tables and transactions

    /** @return array<string, array{0: bool, 1: string, 2: ?string, 3?: string}> */
    public static function workTables(): array
    {
        return [
            'in a transaction, InnoDB'          => [true, 'InnoDB', null],
            'in a transaction, MyISAM'          => [true, 'MyISAM', 'item'],
            'in a transaction, no such table'   => [true, '', 'item'],
            'named in capitals, InnoDB'         => [true, 'InnoDB', null, 'Item'],
            'outside a transaction, not looked' => [false, 'MyISAM', null],
        ];
    }

    #[DataProvider('workTables')]
    public function test_start_requires_innodb_for_tables_of_work(bool $inTransaction, string $engine, ?string $refused, string $name = 'item'): void
    {
        self::mode($inTransaction);
        ScriptedBatchRun::$hookValues = ['transactionalTables' => [$name]];
        if ($engine !== '') {
            TableDb::$engines['item'] = $engine;
        }
        try {
            self::start();
            $this->assertNull($refused, 'started');
        } catch (BatchFailed $e) {
            $this->assertSame(['NO_TRANSACTION', $refused], [$e->reason, $e->where]);
            $this->assertSame([], TableDb::rows('object_text'));
        }
    }

    public function test_transactional_step_inside_another_transaction_refused(): void
    {
        self::mode(true);
        $id = self::start()->getId();
        ScriptedBatchRun::$script = [new BatchStepResult(['n' => 1], false)];

        try {
            DB_AA::transaction(static fn() => self::runQueued());
            $this->fail('A step joined the caller\'s transaction');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame([], ScriptedBatchRun::hooks());
        $this->assertSame('', self::stored($id)['lease_tok'], 'the claim rolled back with the caller');
    }
}
