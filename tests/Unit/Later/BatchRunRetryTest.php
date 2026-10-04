<?php

declare(strict_types=1);

/**
 * BatchRun: steps that do not move on - network errors, a source that does not settle, a step that
 * died, a round too short - and the retries, which end in onRetriesExhausted() or in error.
 * One test (at least) per row of the step flow.
 */

namespace Tests\Unit\Later;

use AA\Later\BatchBudget;
use AA\Later\BatchFailed;
use AA\Later\BatchPosition;
use AA\Later\BatchStep;
use AA\Later\BatchStepResult;
use AA\Later\Toexecute;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Later\Fixtures\BatchRunTables;
use Tests\Unit\Later\Fixtures\BothStepModes;
use Tests\Unit\Later\Fixtures\QueueRefusingDb;
use Tests\Unit\Later\Fixtures\ScriptedBatchRun;

require_once __DIR__ . '/Fixtures/BatchRunTables.php';

final class BatchRunRetryTest extends TestCase
{
    use BatchRunTables;
    use BothStepModes;

    private const DELAYS = [0 => 60, 1 => 300, 2 => 900];

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

    /** runs the newest queued row at its time, as the queue does */
    private static function runNewest(): string
    {
        $rows = self::queue();
        $row  = end($rows) ?: throw new LogicException('Nothing queued');
        ScriptedBatchRun::$now = max(ScriptedBatchRun::$now, (int) $row['execute_after']);
        $ret = self::stepOf($row)->toexecutelater();
        TableDb::$tables['toexecute'] = array_values(array_filter(self::queue(), static fn(array $r): bool => $r['id'] !== $row['id']));
        return $ret;
    }

    /** @return list<string> the fingerprints queued */
    private static function queued(): array
    {
        return array_map(static fn(array $row): string => self::stepOf($row)->expected, self::queue());
    }

    /** a step that died: its lease ran out and it never released it */
    private static function diedHolding(string $id, int $failures, bool $closing = false): void
    {
        self::storedSet($id, 'lease', (string) (self::NOW - 1));
        self::storedSet($id, 'lease_tok', 'dead-step');
        self::storedSet($id, 'failures', (string) $failures);
        self::storedSet($id, 'closing', $closing ? '1' : '');
    }

    // ---------------------------------------------------------------- progress, but the source failed

    #[DataProvider('bothModes')]
    public function test_network_error_with_progress_queues_after_retry_delay(bool $inTransaction): void
    {
        self::mode($inTransaction);
        $id = self::start()->getId();
        self::storedSet($id, 'failures', '2');
        ScriptedBatchRun::$retryDelays = self::DELAYS;
        ScriptedBatchRun::$script      = [new BatchStepResult(['n' => 5], false, retry: 'TRANSPORT')];

        $this->assertSame('step 1', self::runQueued());

        $stored = self::stored($id);
        $this->assertSame('running', $stored['state']);
        $this->assertSame('0', $stored['failures'], 'progress clears the failures');
        $this->assertSame(['n' => 5], json_decode($stored['position'], true));
        $this->assertSame('1', $stored['step']);
        $this->assertSame((string) self::NOW, $stored['moved']);
        $this->assertSame((string) (self::NOW + 60), $stored['next_at'], 'retryDelay(0)');
        $this->assertSame('', $stored['lease']);
        $this->assertCount(1, self::queue());
        $this->assertSame(self::NOW + 60, self::queue()[0]['execute_after']);
        $this->assertSame([BatchPosition::fingerprint(1, ['n' => 5])], self::queued());
    }

    // ---------------------------------------------------------------- no progress

    /** @return array<string, array{string}> */
    public static function retryReasons(): array
    {
        return ['network error' => ['TRANSPORT'], 'a source that does not settle' => ['SOURCE_UNSTABLE'], 'a source going round' => ['SOURCE_CYCLE']];
    }

    /** @return array<string, array{bool, string}> */
    public static function retryReasonsInBothModes(): array
    {
        return self::inBothModes(self::retryReasons());
    }

    #[DataProvider('retryReasonsInBothModes')]
    public function test_no_progress_increments_failures_and_waits(bool $inTransaction, string $reason): void
    {
        self::mode($inTransaction);
        $id = self::start()->getId();
        self::storedSet($id, 'moved', '123');
        ScriptedBatchRun::$retryDelays = self::DELAYS;
        ScriptedBatchRun::$script      = [new BatchStepResult(['n' => 0], false, retry: $reason), new BatchStepResult(['n' => 0], false, retry: $reason)];

        self::runQueued();

        $stored = self::stored($id);
        $this->assertSame('running', $stored['state']);
        $this->assertSame('1', $stored['failures']);
        $this->assertSame('1', $stored['step'], 'a new fingerprint even without progress');
        $this->assertSame('123', $stored['moved'], 'no progress');
        $this->assertSame((string) (self::NOW + 300), $stored['next_at'], 'retryDelay(failures)');
        $this->assertSame('', $stored['lease_tok']);
        $this->assertSame(self::NOW + 300, self::queue()[0]['execute_after']);
        $this->assertSame([BatchPosition::fingerprint(1, ['n' => 0])], self::queued());

        self::runNewest();

        $stored = self::stored($id);
        $this->assertSame('2', $stored['failures']);
        $this->assertSame((string) (self::NOW + 300 + 900), $stored['next_at']);
        $this->assertSame([], array_diff(ScriptedBatchRun::hooks(), ['work', 'commitStep']), 'two tries are no reason to ask the user');
    }

    /** @return array<string, array{string}> */
    public static function noRetryReasons(): array
    {
        return ['the step ran out of time' => ['NO_PROGRESS'], 'no reason given' => ['']];
    }

    /** @return array<string, array{bool, string}> */
    public static function noRetryReasonsInBothModes(): array
    {
        return self::inBothModes(self::noRetryReasons());
    }

    #[DataProvider('noRetryReasonsInBothModes')]
    public function test_no_progress_without_source_error_queues_at_once(bool $inTransaction, string $reason): void
    {
        self::mode($inTransaction);
        $id = self::start()->getId();
        ScriptedBatchRun::$script = [new BatchStepResult(['n' => 0], false, retry: $reason)];

        self::runQueued();

        $stored = self::stored($id);
        $this->assertSame('1', $stored['failures']);
        $this->assertSame((string) self::NOW, $stored['next_at'], 'due now');
        $this->assertSame(self::NOW, self::queue()[0]['execute_after']);
    }

    #[DataProvider('bothModes')]
    public function test_no_progress_same_position_different_encoding_is_no_progress(bool $inTransaction): void
    {
        self::mode($inTransaction);
        $id = self::start(null, ['position' => ['n' => '5', 'job' => false]])->getId();
        ScriptedBatchRun::$script = [new BatchStepResult(['job' => 0, 'n' => 5], false, retry: 'TRANSPORT')];

        self::runQueued();

        $this->assertSame('1', self::stored($id)['failures']);
        $this->assertSame('', self::stored($id)['moved']);
    }

    public function test_final_check_throws_counts_as_no_progress(): void
    {
        $id = self::start()->getId();
        ScriptedBatchRun::$retryDelays  = self::DELAYS;
        ScriptedBatchRun::$script       = [new BatchStepResult(['n' => 7], true)];
        ScriptedBatchRun::$onFinalCheck = static function (): void {
            ScriptedBatchRun::$onFinalCheck = null;
            throw new \RuntimeException('source down');
        };

        $this->assertSame('step 2', self::runQueued());

        $stored = self::stored($id);
        $this->assertSame('running', $stored['state']);
        $this->assertSame('1', $stored['closing'], 'still only the final phase is left');
        $this->assertSame('1', $stored['failures']);
        $this->assertSame('2', $stored['step']);
        $this->assertSame((string) (self::NOW + 300), $stored['next_at']);
        $this->assertSame('', $stored['lease_tok'], 'the lease is released for the retry');
        $this->assertSame([BatchPosition::fingerprint(1, ['n' => 7]), BatchPosition::fingerprint(2, ['n' => 7])], self::queued(), 'the safety row is stale now');

        $this->assertSame('done', self::runNewest());
        $this->assertSame(['work', 'commitStep', 'finalCheck', 'finalCheck', 'afterClose'], ScriptedBatchRun::hooks());
        $this->assertSame('stale', self::runQueued(), 'the old safety row');
    }

    // ---------------------------------------------------------------- the queue round

    public function test_round_left_too_short_requeues_same_fingerprint_at_round_end(): void
    {
        $id     = self::start()->getId();
        $before = self::stored($id);
        TableDb::$log = [];

        $ret = self::inRound(20.0, 15.0, static fn(): string => self::runQueued());

        $this->assertSame('round end', $ret);
        $this->assertSame([], ScriptedBatchRun::hooks(), 'no claim, no work');
        $this->assertSame($before, self::stored($id), 'no failure counted');
        $this->assertSame([BatchPosition::fingerprint(0, ['n' => 0])], self::queued());
        $this->assertGreaterThanOrEqual(self::NOW + 5, self::queue()[0]['execute_after'], 'at the end of the round, not in the next second');
        $this->assertLessThanOrEqual(self::NOW + 6, self::queue()[0]['execute_after']);
        foreach (TableDb::writes() as $sql) {
            $this->assertStringStartsWith('INSERT INTO `toexecute`', $sql);
        }
    }

    public function test_round_too_short_errors_round_too_short(): void
    {
        $id = self::start()->getId();
        ScriptedBatchRun::$hookValues = ['minStepSeconds' => 20];

        $ret = self::inRound(15.0, 0.0, static fn(): string => self::runQueued());

        $this->assertSame('error ROUND_TOO_SHORT', $ret);
        $stored = self::stored($id);
        $this->assertSame('error', $stored['state']);
        $this->assertSame('ROUND_TOO_SHORT', $stored['error_code']);
        $this->assertSame('0', $stored['step']);
        $this->assertSame('0', $stored['failures']);
        $this->assertSame([], self::queue());
        $this->assertSame([], ScriptedBatchRun::hooks(), 'no work, no afterClose()');
    }

    public function test_round_end_queue_refusal_does_not_recurse(): void
    {
        self::start();
        $GLOBALS['spareDBs'] = [new QueueRefusingDb(), new QueueRefusingDb(), new QueueRefusingDb(), new QueueRefusingDb()];

        try {
            self::inRound(20.0, 15.0, static fn(): string => self::runQueued());
            $this->fail('A step queued again without its row');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('queue did not take', $e->getMessage());
        }
        $this->assertSame(1, QueueRefusingDb::$refused, 'the step later() ran at once did not queue itself again');
    }

    // ---------------------------------------------------------------- the lease

    public function test_valid_lease_requeues_at_lease_end(): void
    {
        $id = self::start()->getId();
        self::storedSet($id, 'lease', (string) (self::NOW + 100));
        self::storedSet($id, 'lease_tok', 'another-step');
        $before = self::stored($id);

        $this->assertSame('leased', self::runQueued());

        $this->assertSame([], ScriptedBatchRun::hooks());
        $this->assertSame($before, self::stored($id));
        $this->assertSame([BatchPosition::fingerprint(0, ['n' => 0])], self::queued(), 'if that step dies, this row finds it');
        $this->assertSame(self::NOW + 101, self::queue()[0]['execute_after']);
    }

    #[DataProvider('bothModes')]
    public function test_valid_lease_queue_refusal_does_not_recurse(bool $inTransaction): void
    {
        self::mode($inTransaction);
        $id = self::start()->getId();
        self::storedSet($id, 'lease', (string) (self::NOW + 100));
        self::storedSet($id, 'lease_tok', 'another-step');
        $GLOBALS['spareDBs'] = [new QueueRefusingDb(), new QueueRefusingDb(), new QueueRefusingDb(), new QueueRefusingDb()];

        try {
            self::runQueued();
            $this->fail('A step queued again without its row');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('queue did not take', $e->getMessage());
        }
        $this->assertSame(1, QueueRefusingDb::$refused);
    }

    #[DataProvider('bothModes')]
    public function test_dead_step_expired_lease_counts_failure_and_continues(bool $inTransaction): void
    {
        self::mode($inTransaction);
        $id   = self::start(null, ['position' => ['n' => 3]])->getId();
        $seen = [];
        self::diedHolding($id, 1);
        ScriptedBatchRun::$script = [static function () use ($id, &$seen): BatchStepResult {
            $seen = self::stored($id);
            return new BatchStepResult(['n' => 4], false);
        }];

        $this->assertSame('step 1', self::runQueued(), 'the dead step\'s own row does the work');

        $this->assertSame(['n' => 3], ScriptedBatchRun::call('work')['arg'], 'from the stored position');
        $this->assertSame('2', $seen['failures'], 'the dead step counts');
        $this->assertSame('0', $seen['step'], 'the claim keeps the step');
        $this->assertNotSame('dead-step', $seen['lease_tok'], 'a lease of its own');
        $this->assertGreaterThan(self::NOW, (int) $seen['lease']);
        $this->assertSame('0', self::stored($id)['failures'], 'its progress clears them');
    }

    #[DataProvider('bothModes')]
    public function test_dead_step_in_final_phase_repeats_final_phase(bool $inTransaction): void
    {
        self::mode($inTransaction);
        $id = self::start()->getId();
        self::diedHolding($id, 0, true);

        $this->assertSame('done', self::runQueued());

        $this->assertSame(['finalCheck', 'afterClose'], ScriptedBatchRun::hooks());
        $this->assertSame('done', self::stored($id)['state']);
    }

    public function test_round_too_short_keeps_the_lease_of_another_step(): void
    {
        $id = self::start()->getId();
        self::storedSet($id, 'lease', (string) (self::NOW + 100));
        self::storedSet($id, 'lease_tok', 'another-step');
        ScriptedBatchRun::$hookValues = ['minStepSeconds' => 20];

        self::inRound(15.0, 0.0, static fn(): string => self::runQueued());

        $stored = self::stored($id);
        $this->assertSame('ROUND_TOO_SHORT', $stored['error_code']);
        $this->assertSame((string) (self::NOW + 100), $stored['lease'], 'the step working elsewhere keeps its lease');
        $this->assertSame('another-step', $stored['lease_tok']);
    }

    // ---------------------------------------------------------------- retries spent

    public function test_retries_exhausted_hook_position_moves_on(): void
    {
        $id = self::start()->getId();
        self::storedSet($id, 'failures', '2');
        ScriptedBatchRun::$script      = [new BatchStepResult(['n' => 0], false, ['read' => 1], label: 'task 4', retry: 'TRANSPORT', extra: ['task' => 4])];
        ScriptedBatchRun::$onExhausted = static fn(BatchStepResult $last): array => ['n' => 1];

        $this->assertSame('step 1', self::runQueued());

        $this->assertSame(['work', 'onRetriesExhausted', 'commitStep'], ScriptedBatchRun::hooks());
        $this->assertSame('TRANSPORT', ScriptedBatchRun::call('onRetriesExhausted')['arg']->retry);
        $this->assertSame(['task' => 4], ScriptedBatchRun::call('commitStep')['arg'], 'the last step\'s data still count');
        $stored = self::stored($id);
        $this->assertSame('running', $stored['state']);
        $this->assertSame('0', $stored['failures']);
        $this->assertSame(['n' => 1], json_decode($stored['position'], true));
        $this->assertSame('1', $stored['step']);
        $this->assertSame((string) self::NOW, $stored['moved']);
        $this->assertSame((string) self::NOW, $stored['next_at'], 'due now');
        $this->assertSame(['read' => 1], json_decode($stored['counts'], true));
        $this->assertSame([BatchPosition::fingerprint(1, ['n' => 1])], self::queued());
        $this->assertSame(self::NOW, self::queue()[0]['execute_after'], 'at once');
    }

    #[DataProvider('bothModes')]
    public function test_retries_exhausted_hook_null_errors(bool $inTransaction): void
    {
        self::mode($inTransaction);
        $id = self::start()->getId();
        self::storedSet($id, 'failures', '2');
        ScriptedBatchRun::$script = [new BatchStepResult(['n' => 0], false, retry: 'SOURCE_UNSTABLE')];

        $this->assertSame('error SOURCE_UNSTABLE', self::runQueued());

        $stored = self::stored($id);
        $this->assertSame('error', $stored['state']);
        $this->assertSame('SOURCE_UNSTABLE', $stored['error_code']);
        $this->assertSame('3', $stored['failures']);
        $this->assertSame('1', $stored['step']);
        $this->assertSame(['n' => 0], json_decode($stored['position'], true));
        $this->assertSame('', $stored['lease_tok']);
        $this->assertSame([], self::queue(), 'no step');
        $this->assertNotContains('afterClose', ScriptedBatchRun::hooks());
    }

    #[DataProvider('bothModes')]
    public function test_retries_exhausted_hook_returns_start_position_errors(bool $inTransaction): void
    {
        self::mode($inTransaction);
        $id = self::start(null, ['position' => ['n' => 2]])->getId();
        self::storedSet($id, 'failures', '2');
        ScriptedBatchRun::$script      = [new BatchStepResult(['n' => 2], false, retry: 'SOURCE_CYCLE')];
        ScriptedBatchRun::$onExhausted = static fn(BatchStepResult $last): array => ['n' => '2'];

        self::runQueued();

        $this->assertSame('error', self::stored($id)['state']);
        $this->assertSame('SOURCE_CYCLE', self::stored($id)['error_code']);
    }

    public function test_retries_exhausted_hook_called_outside_transaction(): void
    {
        $id = self::start()->getId();
        self::storedSet($id, 'failures', '2');
        ScriptedBatchRun::$script = [new BatchStepResult(['n' => 0], false, retry: 'TRANSPORT')];

        self::runQueued();

        $call = ScriptedBatchRun::call('onRetriesExhausted');
        $this->assertFalse($call['tx'], 'it may ask the source');
        $this->assertFalse($call['locked']);
        $this->assertSame($id, $call['running'], 'code it reaches knows the run');
    }

    public function test_retries_exhausted_hook_failure_errors(): void
    {
        $id = self::start()->getId();
        self::storedSet($id, 'failures', '2');
        ScriptedBatchRun::$script      = [new BatchStepResult(['n' => 0], false, retry: 'TRANSPORT')];
        ScriptedBatchRun::$onExhausted = static fn(BatchStepResult $last): array => throw new BatchFailed('SOURCE_GONE', 'task 4');

        $this->assertSame('error SOURCE_GONE', self::runQueued());
        $this->assertSame('task 4', self::stored($id)['error_path']);
    }

    #[DataProvider('bothModes')]
    public function test_dead_step_exhausted_asks_the_hook(bool $inTransaction): void
    {
        self::mode($inTransaction);
        $id = self::start()->getId();
        self::diedHolding($id, 2);

        $this->assertSame('error STEP_DIED', self::runQueued());

        $this->assertSame(['onRetriesExhausted', 'commitStep'], ScriptedBatchRun::hooks(), 'the step that keeps dying is not run again');
        $this->assertSame('STEP_DIED', ScriptedBatchRun::call('onRetriesExhausted')['arg']->retry);
        $stored = self::stored($id);
        $this->assertSame('error', $stored['state']);
        $this->assertSame('STEP_DIED', $stored['error_code']);
        $this->assertSame('3', $stored['failures']);
        $this->assertSame('', $stored['lease_tok']);
    }

    #[DataProvider('bothModes')]
    public function test_dead_step_exhausted_hook_moves_on(bool $inTransaction): void
    {
        self::mode($inTransaction);
        $id = self::start()->getId();
        self::diedHolding($id, 2);
        ScriptedBatchRun::$onExhausted = static fn(BatchStepResult $last): array => ['n' => 1];

        $this->assertSame('step 1', self::runQueued());

        $stored = self::stored($id);
        $this->assertSame('running', $stored['state']);
        $this->assertSame('0', $stored['failures']);
        $this->assertSame(['n' => 1], json_decode($stored['position'], true));
        $this->assertSame([BatchPosition::fingerprint(1, ['n' => 1])], self::queued());
    }

    public function test_final_check_throws_three_times_errors_final_check(): void
    {
        $id = self::start()->getId();
        ScriptedBatchRun::$script       = [new BatchStepResult(['n' => 7], true)];
        ScriptedBatchRun::$onFinalCheck = static fn() => throw new \RuntimeException('source down');

        self::runQueued();
        self::runNewest();
        $this->assertSame('error FINAL_CHECK', self::runNewest());

        $stored = self::stored($id);
        $this->assertSame('error', $stored['state']);
        $this->assertSame('FINAL_CHECK', $stored['error_code']);
        $this->assertSame('3', $stored['failures']);
        $this->assertSame('', $stored['lease_tok']);
        $this->assertSame(['work', 'commitStep', 'finalCheck', 'finalCheck', 'finalCheck'], ScriptedBatchRun::hooks(), 'the final phase does not ask onRetriesExhausted()');
        $this->assertSame([BatchPosition::fingerprint(1, ['n' => 7])], self::queued(), 'only the stale safety row is left');
    }

    public function test_dead_final_phase_exhausted_errors_final_check(): void
    {
        $id = self::start()->getId();
        self::diedHolding($id, 2, true);

        $this->assertSame('error FINAL_CHECK', self::runQueued());

        $this->assertSame([], ScriptedBatchRun::hooks());
        $this->assertSame('FINAL_CHECK', self::stored($id)['error_code']);
        $this->assertSame([], self::queue());
    }

    #[DataProvider('bothModes')]
    public function test_long_task_repeated_network_error_does_not_loop_forever(bool $inTransaction): void
    {
        self::mode($inTransaction);
        $id = self::start()->getId();
        ScriptedBatchRun::$retryDelays = self::DELAYS;
        for ($n = 1; $n <= 5; ++$n) {
            ScriptedBatchRun::$script = [new BatchStepResult(['n' => $n], false, retry: 'TRANSPORT')];
            self::runNewest();
            $this->assertSame('0', self::stored($id)['failures'], 'each step moved on');
        }
        $this->assertSame('running', self::stored($id)['state'], 'a long task with a flaky source never errs');

        for ($i = 1; $i <= 3; ++$i) {
            ScriptedBatchRun::$script = [new BatchStepResult(['n' => 5], false, retry: 'TRANSPORT')];
            self::runNewest();
        }
        $this->assertSame('error', self::stored($id)['state'], 'one that does not move on does');
        $this->assertSame('TRANSPORT', self::stored($id)['error_code']);
    }

    // ---------------------------------------------------------------- permanent failures

    #[DataProvider('bothModes')]
    public function test_batch_failed_errors_with_code_and_where_not_message(bool $inTransaction): void
    {
        self::mode($inTransaction);
        $id = self::start()->getId();
        ScriptedBatchRun::$script = [new BatchFailed('SOURCE_LOGIN', 'stream 3')];

        $this->assertSame('error SOURCE_LOGIN', self::runQueued());

        $stored = self::stored($id);
        $this->assertSame('error', $stored['state']);
        $this->assertSame('SOURCE_LOGIN', $stored['error_code']);
        $this->assertSame('stream 3', $stored['error_path']);
        $this->assertSame('1', $stored['step']);
        $this->assertSame('0', $stored['failures']);
        $this->assertSame(['n' => 0], json_decode($stored['position'], true));
        $this->assertSame('', $stored['lease_tok']);
        $this->assertSame([], self::queue());
        $this->assertSame(['work'], ScriptedBatchRun::hooks(), 'no commitStep(), no afterClose()');
    }

    #[DataProvider('bothModes')]
    public function test_other_exception_errors_internal_without_its_text(bool $inTransaction): void
    {
        self::mode($inTransaction);
        $id = self::start()->getId();
        ScriptedBatchRun::$script = [new \RuntimeException('GET https://api/?token=hunter2 failed')];

        $this->assertSame('error INTERNAL', self::runQueued());

        $stored = self::stored($id);
        $this->assertSame('error', $stored['state']);
        $this->assertSame('INTERNAL', $stored['error_code']);
        $this->assertSame('RuntimeException', $stored['error_path']);
        $this->assertStringNotContainsString('hunter2', implode("\n", $stored));
    }

    #[DataProvider('bothModes')]
    public function test_php_error_errors_internal_and_logs_the_place(bool $inTransaction): void
    {
        self::mode($inTransaction);
        $id = self::start()->getId();
        ScriptedBatchRun::$script = [new \TypeError('token=hunter2 is no int')];

        $this->assertSame('error INTERNAL', self::runQueued());

        $stored = self::stored($id);
        $this->assertSame('INTERNAL', $stored['error_code']);
        $this->assertSame('TypeError', $stored['error_path']);
        $this->assertSame('', $stored['lease_tok']);
        $log = TableDb::rows('log');
        $this->assertCount(1, $log);
        $this->assertSame(Toexecute::FAILED_EVENT, $log[0]['type']);
        $this->assertSame('Scripted_' . $id, $log[0]['selector']);
        $this->assertMatchesRegularExpression('/^#' . $id . ' TypeError \S*BatchRunRetryTest\.php:\d+$/', $log[0]['params'], 'the class and the line, as the queue logs them');
        $this->assertStringNotContainsString('hunter2', implode("\n", $stored) . implode("\n", array_merge(...array_map('array_values', $log))));
    }

    /** @return array<string, array{\Throwable, string, string}> */
    public static function endOfStepFailures(): array
    {
        return [
            'a permanent failure' => [new BatchFailed('STREAM_STATE', 'stream 2'), 'STREAM_STATE', 'stream 2'],
            'anything else'       => [new \RuntimeException('Duplicate entry hunter2'), 'INTERNAL', 'RuntimeException'],
        ];
    }

    /** @return array<string, array{bool, \Throwable, string, string}> */
    public static function endOfStepFailuresInBothModes(): array
    {
        return self::inBothModes(self::endOfStepFailures());
    }

    #[DataProvider('endOfStepFailuresInBothModes')]
    public function test_end_of_step_failure_errors_and_keeps_nothing_of_the_step(bool $inTransaction, \Throwable $e, string $code, string $where): void
    {
        self::mode($inTransaction);
        $id = self::start()->getId();
        ScriptedBatchRun::$script       = [static function (): BatchStepResult {
            self::writeBatch('batch 1');
            return new BatchStepResult(['n' => 5], false, ['read' => 3]);
        }];
        ScriptedBatchRun::$commitThrows = $e;

        $this->assertSame("error $code", self::runQueued());

        $stored = self::stored($id);
        $this->assertSame('error', $stored['state'], 'not left active, waiting for a step never queued');
        $this->assertSame($code, $stored['error_code']);
        $this->assertSame($where, $stored['error_path']);
        $this->assertSame(['n' => 0], json_decode($stored['position'], true), 'the end of the step rolled back');
        $this->assertSame('', $stored['counts']);
        $this->assertSame('', $stored['lease_tok']);
        $this->assertSame([], self::queue(), 'nor its next step');
        $this->assertSame($inTransaction ? [] : ['batch 1'], self::batches(), 'the batch goes with the end of the step only in its transaction');
        $this->assertStringNotContainsString('hunter2', implode("\n", $stored));
    }

    public function test_hook_failure_before_the_claim_errors_internal(): void
    {
        $id = self::start()->getId();
        ScriptedBatchRun::$valid = new \RuntimeException('definition table: hunter2');

        $this->assertSame('error INTERNAL', self::runQueued());

        $stored = self::stored($id);
        $this->assertSame('error', $stored['state'], 'the row is gone after the step - the run must say why it stopped');
        $this->assertSame('INTERNAL', $stored['error_code']);
        $this->assertSame('RuntimeException', $stored['error_path']);
        $this->assertSame([], ScriptedBatchRun::hooks());
    }

    /** @return array<string, array{string}> */
    public static function hooksBeforeTheClaim(): array
    {
        return ['minStepSeconds' => ['minStepSeconds'], 'budgetLimits' => ['budgetLimits'], 'overrun' => ['overrun'], 'stepPriority' => ['stepPriority']];
    }

    #[DataProvider('hooksBeforeTheClaim')]
    public function test_hook_failure_before_the_claim_errors_under_the_lock(string $hook): void
    {
        $id = self::start()->getId();
        ScriptedBatchRun::$hookValues = [$hook => new \Error('config: hunter2')];

        $this->assertSame('error INTERNAL', self::runQueued());

        $stored = self::stored($id);
        $this->assertSame('error', $stored['state'], 'not active without a step');
        $this->assertSame('INTERNAL', $stored['error_code']);
        $this->assertSame('Error', $stored['error_path']);
        $this->assertSame('', $stored['lease_tok'], 'nothing claimed');
        $this->assertSame([], self::queue());
        $this->assertSame([], ScriptedBatchRun::hooks());
    }

    public function test_batch_failed_from_a_hook_before_the_claim_keeps_its_code(): void
    {
        $id = self::start()->getId();
        ScriptedBatchRun::$valid = new BatchFailed('DEFINITION_GONE', 'definition 7');

        $this->assertSame('error DEFINITION_GONE', self::runQueued());

        $this->assertSame('DEFINITION_GONE', self::stored($id)['error_code']);
        $this->assertSame('definition 7', self::stored($id)['error_path']);
        $this->assertSame([], TableDb::rows('log'), 'a code of its own is no fault to log');
    }

    public function test_php_error_from_retries_hook_errors_internal(): void
    {
        $id = self::start()->getId();
        self::storedSet($id, 'failures', '2');
        ScriptedBatchRun::$script      = [new BatchStepResult(['n' => 0], false, retry: 'TRANSPORT')];
        ScriptedBatchRun::$onExhausted = static fn(BatchStepResult $last): array => throw new \Error('source gone');

        $this->assertSame('error INTERNAL', self::runQueued());
        $this->assertSame('Error', self::stored($id)['error_path']);
    }

    public function test_php_error_from_final_check_counts_as_no_progress(): void
    {
        $id = self::start()->getId();
        ScriptedBatchRun::$script       = [new BatchStepResult(['n' => 7], true)];
        ScriptedBatchRun::$onFinalCheck = static fn() => throw new \Error('source gone');

        self::runQueued();

        $stored = self::stored($id);
        $this->assertSame('running', $stored['state']);
        $this->assertSame('1', $stored['closing']);
        $this->assertSame('1', $stored['failures']);
        $this->assertSame('', $stored['error_code']);
    }

    public function test_php_error_from_after_close_becomes_warning(): void
    {
        $id = self::start()->getId();
        ScriptedBatchRun::$script           = [new BatchStepResult(['n' => 7], true)];
        ScriptedBatchRun::$afterCloseThrows = new \Error('cache gone');

        $this->assertSame('done', self::runQueued());
        $this->assertSame('after_close', json_decode(self::stored($id)['progress'], true)['warning'] ?? null);
    }

    public function test_done_write_failure_is_a_permanent_error(): void
    {
        $id   = self::start()->getId();
        $deep = 1;
        for ($i = 0; $i < 16; ++$i) {
            $deep = [$deep];
        }
        ScriptedBatchRun::$script      = [new BatchStepResult(['n' => 7], true)];
        ScriptedBatchRun::$finalReport = ['d' => $deep];          // deeper than the run stores

        $this->assertSame('error INTERNAL', self::runQueued());

        $stored = self::stored($id);
        $this->assertSame('error', $stored['state']);
        $this->assertSame('JsonException', $stored['error_path']);
        $this->assertSame('0', $stored['failures'], 'not retried - it would fail the same way');
        $this->assertSame('', $stored['lease_tok']);
        $this->assertSame(['work', 'commitStep', 'finalCheck'], ScriptedBatchRun::hooks());
        $this->assertSame('stale', self::runQueued(), 'the safety row');
    }

    public function test_long_place_is_cut_to_the_column(): void
    {
        $id = self::start()->getId();
        ScriptedBatchRun::$script = [new BatchFailed('SOURCE_LOGIN', str_repeat('ž', 200))];

        self::runQueued();

        $this->assertSame(str_repeat('ž', 127), self::stored($id)['error_path'], '255 bytes, never half a character');
    }

    public function test_failure_after_lease_lost_writes_nothing(): void
    {
        $id = self::start()->getId();
        ScriptedBatchRun::$script = [static function () use ($id): never {
            self::storedSet($id, 'lease_tok', 'another-step');
            throw new BatchFailed('SOURCE_LOGIN');
        }];

        $this->assertSame('lease lost', self::runQueued());
        $this->assertSame('running', self::stored($id)['state']);
        $this->assertSame('', self::stored($id)['error_code']);
    }

    // ---------------------------------------------------------------- what the run stands on

    public function test_still_valid_false_ends_and_cancels_queue(): void
    {
        $run = self::start();
        $id  = $run->getId();
        self::storedSet($id, 'sealed', 'encrypted-secret');
        (new Toexecute())->later(new BatchStep(ScriptedBatchRun::class, $id, 'older'), [], $run->selector(), 5, self::NOW + 999);
        ScriptedBatchRun::$valid = false;

        $this->assertSame('ended', self::runQueued());

        $stored = self::stored($id);
        $this->assertSame('ended', $stored['state']);
        $this->assertSame((string) self::NOW, $stored['finished']);
        $this->assertSame('', $stored['sealed'], 'the secret goes with the run');
        $this->assertSame([], self::queue(), 'every row of the run');
        $this->assertSame(['afterClose'], ScriptedBatchRun::hooks(), 'no work');
        $this->assertSame('ended', ScriptedBatchRun::call('afterClose')['arg']);
    }

    // ---------------------------------------------------------------- budget, lease, priority

    public function test_budget_outside_a_round_is_thirty_seconds_less_margin(): void
    {
        self::start();
        $seen = null;
        ScriptedBatchRun::$script = [static function (ScriptedBatchRun $run, array $from, BatchBudget $budget) use (&$seen): BatchStepResult {
            $seen = $budget;
            return new BatchStepResult(['n' => 1], false);
        }];

        self::runQueued();

        $this->assertSame((float) self::NOW + 27, $seen->deadline);
        $this->assertSame([0, 0], [$seen->items, $seen->bytes]);
    }

    public function test_budget_shrinks_to_the_round_left(): void
    {
        self::start();
        $seen = null;
        ScriptedBatchRun::$hookValues = ['budgetLimits' => ['items' => 500, 'bytes' => 4_000_000]];
        ScriptedBatchRun::$script     = [static function (ScriptedBatchRun $run, array $from, BatchBudget $budget) use (&$seen): BatchStepResult {
            $seen = $budget;
            return new BatchStepResult(['n' => 1], false);
        }];

        self::inRound(20.0, 5.0, static fn(): string => self::runQueued());

        $this->assertEqualsWithDelta((float) self::NOW + 12, $seen->deadline, 0.5, 'the round\'s 15 s left, less the margin');
        $this->assertSame([500, 4_000_000], [$seen->items, $seen->bytes], 'budgetLimits()');
    }

    public function test_lease_covers_budget_plus_overrun(): void
    {
        $id   = self::start()->getId();
        $seen = [];
        ScriptedBatchRun::$hookValues = ['overrun' => 120];
        ScriptedBatchRun::$script     = [static function () use ($id, &$seen): BatchStepResult {
            $seen = self::stored($id);
            return new BatchStepResult(['n' => 1], false);
        }];

        self::runQueued();

        $this->assertSame((string) (self::NOW + 27 + 120), $seen['lease']);
    }

    public function test_step_priority_from_hook(): void
    {
        ScriptedBatchRun::$hookValues = ['stepPriority' => 7];
        self::start();
        ScriptedBatchRun::$script = [new BatchStepResult(['n' => 1], false)];

        $this->assertSame(7, self::queue()[0]['priority']);
        self::runQueued();
        $this->assertSame(7, self::queue()[0]['priority']);
    }
}
