<?php

declare(strict_types=1);

/**
 * BatchRun::beforeAction() and recordResult(): a step writes own properties of the run at once, under
 * the run's lock - before an action it cannot take back, and what that action did - only while its
 * claim still owns the run.
 */

namespace Tests\Unit\Later;

use AA\IO\DB\DB_AA;
use AA\Later\BatchRun;
use AA\Later\BatchStep;
use AA\Later\BatchStepResult;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Later\Fixtures\BatchRunTables;
use Tests\Unit\Later\Fixtures\BothStepModes;
use Tests\Unit\Later\Fixtures\ScriptedBatchRun;

require_once __DIR__ . '/Fixtures/BatchRunTables.php';

final class BatchRunCheckpointTest extends TestCase
{
    use BatchRunTables;
    use BothStepModes;

    /** a record that sets the note */
    private static function setNote(string $value): \Closure
    {
        return static function (ScriptedBatchRun $locked) use ($value): bool {
            $locked->note($value);
            return true;
        };
    }

    /** a record that adds $key to the note, a JSON map */
    private static function addToNote(string $key): \Closure
    {
        return static function (ScriptedBatchRun $locked) use ($key): bool {
            $locked->note(self::withKey($locked->note(), $key));
            return true;
        };
    }

    private static function withKey(string $json, string $key): string
    {
        return json_encode([...(array) json_decode($json, true), $key => 1], JSON_THROW_ON_ERROR);
    }

    /** true when $do throws a LogicException */
    private static function refuses(\Closure $do): bool
    {
        try {
            $do();
        } catch (LogicException) {
            return true;
        }
        return false;
    }

    /** $script as the one step's work(), run from the queue */
    private static function inStep(\Closure $script): string
    {
        ScriptedBatchRun::$script = [static function (ScriptedBatchRun $run) use ($script): BatchStepResult {
            $script($run);
            return new BatchStepResult(['n' => 1], false);
        }];
        return self::runQueued();
    }

    public function test_beforeAction_writes_at_once(): void
    {
        $id   = self::start()->getId();
        $seen = [];
        self::inStep(static function (ScriptedBatchRun $run) use ($id, &$seen): void {
            $ok   = $run->callBeforeAction(static function (ScriptedBatchRun $locked) use (&$seen): bool {
                $seen['locked'] = DB_AA::inTransaction();
                $locked->note('sending 1');
                return true;
            }, 60);
            $seen += ['ok' => $ok, 'stored' => self::stored($id)['note'], 'tx' => DB_AA::inTransaction(), 'own' => $run->note()];
        });

        $this->assertSame(['locked' => true, 'ok' => true, 'stored' => 'sending 1', 'tx' => false, 'own' => 'sending 1'], $seen, 'committed before the step acts');
        $this->assertSame('sending 1', self::stored($id)['note'], 'the end of the step keeps it');
    }

    public function test_recordResult_merges_with_a_concurrent_write(): void
    {
        $id   = self::start(null, ['note' => '{}'])->getId();
        $seen = '';
        self::inStep(static function (ScriptedBatchRun $run) use ($id, &$seen): void {
            $run->callBeforeAction(self::addToNote('a'), 60);
            self::storedSet($id, 'note', self::withKey(self::stored($id)['note'], 'stop'));     // another process, as a stop writes
            $run->callRecordResult(self::addToNote('b'));
            $seen = $run->note();
        });

        $this->assertSame(['a' => 1, 'stop' => 1, 'b' => 1], json_decode(self::stored($id)['note'], true));
        $this->assertSame(self::stored($id)['note'], $seen, 'the step has the note as stored, with what the other process added');
    }

    /** a closure that changes nothing records nothing - not even that the step may act */
    public function test_beforeAction_unchanged_writes_nothing_nor_acts(): void
    {
        self::start();
        $seen = [];
        self::inStep(static function (ScriptedBatchRun $run) use (&$seen): void {
            $writes    = count(TableDb::writes());
            $unchanged = static fn(ScriptedBatchRun $locked): bool => true;
            $seen      = [$run->callBeforeAction($unchanged, 60), $run->callRecordResult($unchanged), count(TableDb::writes()) - $writes];
        });

        $this->assertSame([false, null, 0], $seen);
    }

    /** @return array<string, array{mixed, bool, string}> */
    public static function ownValues(): array
    {
        return [
            'a whole number, as start() takes it' => [5, false, '5'],
            'a list'                              => [['a'], true, 'mine'],
            'nothing'                             => [null, true, 'mine'],
            'a fraction'                          => [0.5, true, 'mine'],
        ];
    }

    /** a run read back has text only - the step must not keep another type */
    #[DataProvider('ownValues')]
    public function test_recordResult_stores_own_properties_as_text(mixed $value, bool $refused, string $stored): void
    {
        $id   = self::start()->getId();
        $seen = [];
        self::inStep(static function (ScriptedBatchRun $run) use ($id, $value, &$seen): void {
            $note = new \ReflectionProperty(ScriptedBatchRun::class, 'note');
            $seen = [self::refuses(static fn() => $run->callRecordResult(static function (ScriptedBatchRun $locked) use ($note, $value): bool {
                $note->setValue($locked, $value);
                return true;
            })), self::stored($id)['note'], $note->getValue($run)];
        });

        $this->assertSame([$refused, $stored, $stored], $seen);
    }

    /** @return array<string, array{\Closure(ScriptedBatchRun): bool}> */
    public static function coreChanges(): array
    {
        return [
            'a core property' => [static function (ScriptedBatchRun $locked): bool {
                (new \ReflectionProperty(BatchRun::class, 'lease_tok'))->setValue($locked, 'taken');
                return true;
            }],
            'its owner'       => [static function (ScriptedBatchRun $locked): bool {
                $locked->setOwnerId(str_repeat('b', 32));
                return true;
            }],
            'End'             => [static function (ScriptedBatchRun $locked): bool {
                $locked->end(self::USER);
                return true;
            }],
            'End, then a veto' => [static function (ScriptedBatchRun $locked): bool {
                $locked->end(self::USER);
                return false;
            }],
        ];
    }

    #[DataProvider('coreChanges')]
    public function test_recordResult_refuses_core_change(\Closure $change): void
    {
        $id   = self::start()->getId();
        $seen = [];
        self::inStep(static function (ScriptedBatchRun $run) use ($id, $change, &$seen): void {
            $before = [self::stored($id), self::queue()];
            $seen   = [self::refuses(static fn() => $run->callRecordResult(static function (ScriptedBatchRun $locked) use ($change): bool {
                $locked->note('with it');
                return $change($locked);
            })), $before === [self::stored($id), self::queue()]];
        });

        $this->assertSame([true, true], $seen, 'refused, nothing stored');
    }

    /** @return array<string, array{bool, string}> */
    public static function transactions(): array
    {
        return [
            'in a transaction of its own'                            => [false, 'work'],
            'in the step\'s transaction, on a run without the claim' => [true, 'work'],
            'in fetch() of a step working in a transaction'          => [true, 'fetch'],
        ];
    }

    /** work() in the step's transaction gets the run read there - refused already for want of the claim */
    #[DataProvider('transactions')]
    public function test_recordResult_refuses_inside_transaction(bool $inTransaction, string $hook): void
    {
        self::mode($inTransaction);
        $id      = self::start()->getId();
        $refused = [];
        $try     = static function (ScriptedBatchRun $run) use (&$refused): void {
            $refused[] = self::refuses(static fn() => $run->callRecordResult(self::setNote('in a transaction')));
        };
        if ($hook === 'fetch') {
            ScriptedBatchRun::$fetchScript = [static function (ScriptedBatchRun $run) use ($try): ?array {
                $try($run);
                return null;
            }];
        }

        $this->assertSame('step 1', self::inStep(static function (ScriptedBatchRun $run) use ($try, $hook, $inTransaction): void {
            if ($hook === 'work') {
                $inTransaction ? $try($run) : DB_AA::transaction(static fn() => $try($run));
            }
        }));

        $this->assertSame([true], $refused);
        $this->assertSame('mine', self::stored($id)['note']);
    }

    /** @return array<string, array{bool}> */
    public static function readRuns(): array
    {
        return ['a run read by get()' => [false], 'one passed off as the working step' => [true]];
    }

    /** a read of the run carries the token of the step working now - but no claim */
    #[DataProvider('readRuns')]
    public function test_recordResult_refuses_outside_its_claim(bool $during): void
    {
        $id = self::start()->getId();
        self::leased($id);
        $run    = ScriptedBatchRun::get($id) ?? throw new LogicException('No run');
        $record = static fn() => $run->callRecordResult(self::setNote('not mine'));

        $this->assertTrue(self::refuses($during ? static fn() => BatchStep::during($run, $record) : $record));
        $this->assertSame('mine', self::stored($id)['note']);
    }

    public function test_beforeAction_closure_veto_writes_nothing(): void
    {
        $id   = self::start()->getId();
        $seen = [];
        self::inStep(static function (ScriptedBatchRun $run) use ($id, &$seen): void {
            $kept = null;
            $ok   = $run->callBeforeAction(static function (ScriptedBatchRun $locked) use (&$kept): bool {
                $kept = $locked;
                $locked->note('vetoed');
                return false;
            }, 60);
            $seen = [$ok, $run->note(), self::stored($id)['note'], self::refuses(static fn() => DB_AA::transaction(static fn() => $kept?->end(self::USER)))];
        });

        $this->assertSame([false, 'mine', 'mine', true], $seen, 'the instance it kept is no longer locked');
    }

    /** @return array<string, array{bool, int, bool}> */
    public static function reserves(): array
    {
        return [
            'the lease outlasts the action'     => [false, -1, true],
            'it ends as the action may'         => [false, 0, false],
            'the lease ran out, no one claimed' => [true, 0, false],
        ];
    }

    /** no one claims the run while the step acts - its lease must outlast the action; claim() takes
     *  a lease ending now for one run out */
    #[DataProvider('reserves')]
    public function test_beforeAction_needs_the_lease_to_outlast_the_action(bool $leaseOut, int $more, bool $acts): void
    {
        $id   = self::start()->getId();
        $seen = [];
        self::inStep(static function (ScriptedBatchRun $run) use ($id, $leaseOut, $more, &$seen): void {
            $lease = (int) self::stored($id)['lease'];
            if ($leaseOut) {
                ScriptedBatchRun::$now = $lease;
            }
            $seen = [$run->callBeforeAction(self::setNote('sending 1'), max(0, $lease - ScriptedBatchRun::clock() + $more)), self::stored($id)['note']];
        });

        $this->assertSame([$acts, $acts ? 'sending 1' : 'mine'], $seen);
    }

    public function test_beforeAction_refuses_negative_reserve(): void
    {
        $id      = self::start()->getId();
        $refused = false;
        self::inStep(static function (ScriptedBatchRun $run) use (&$refused): void {
            try {
                $run->callBeforeAction(self::setNote('sending 1'), -1);
            } catch (\InvalidArgumentException) {
                $refused = true;
            }
        });

        $this->assertTrue($refused);
        $this->assertSame('mine', self::stored($id)['note']);
    }

    /** @return array<string, array{string, bool, ?bool, string}> */
    public static function meanwhile(): array
    {
        // what happened while the step worked: may it act, is its result recorded, the note when nothing is written
        return [
            'nothing'                   => ['nothing', true, true, 'mine'],
            'End'                       => ['End', false, false, 'mine'],
            'an error of the run'       => ['error', false, false, 'mine'],
            'Continue'                  => ['Continue', false, null, 'mine'],
            'a claim of another step'   => ['claim', false, null, 'mine'],
            'the run deleted'           => ['deleted', false, null, 'gone'],
        ];
    }

    private static function happen(string $what, string $id): void
    {
        match ($what) {
            'nothing'  => null,
            'End'      => self::locked($id, static fn(ScriptedBatchRun $r) => $r->end(self::USER)),
            // the step's own row run again finds a hook failing: the run errs, the lease stays
            'error'    => (static function (): void {
                ScriptedBatchRun::$valid = new \RuntimeException('hook failed');
                self::runQueued();
                ScriptedBatchRun::$valid = true;
            })(),
            'Continue' => (static function () use ($id): void {
                ScriptedBatchRun::$now = self::NOW + 1000;          // long past the lease: Continue takes the step for dead
                self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER));
            })(),
            'claim'    => self::storedSet($id, 'lease_tok', 'another-step'),
            'deleted'  => DB_AA::transaction(static fn() => ScriptedBatchRun::deleteOfSubject(self::SUBJECT)),
        };
    }

    #[DataProvider('meanwhile')]
    public function test_beforeAction_only_in_run_still_its_own(string $what, bool $acts, ?bool $recorded, string $untouched): void
    {
        $id = self::start()->getId();
        $ok = null;
        self::inStep(static function (ScriptedBatchRun $run) use ($id, $what, &$ok): void {
            self::happen($what, $id);
            $ok = $run->callBeforeAction(self::setNote('done'), 0);
        });

        $this->assertSame($acts, $ok);
        $this->assertSame($acts ? 'done' : $untouched, self::stored($id)['note'] ?? 'gone');
    }

    #[DataProvider('meanwhile')]
    public function test_recordResult_outcomes(string $what, bool $acts, ?bool $recorded, string $untouched): void
    {
        $id = self::start()->getId();
        $ok = 'not called';
        self::inStep(static function (ScriptedBatchRun $run) use ($id, $what, &$ok): void {
            self::happen($what, $id);
            $ok = $run->callRecordResult(self::setNote('done'));
        });

        $this->assertSame($recorded, $ok, 'null = not recorded, false = recorded but the step stops');
        $this->assertSame(($recorded === null) ? $untouched : 'done', self::stored($id)['note'] ?? 'gone');
    }

    public function test_recordResult_commit_failure_writes_nothing(): void
    {
        $id   = self::start()->getId();
        $seen = [];
        self::inStep(static function (ScriptedBatchRun $run) use ($id, &$seen): void {
            TableDb::$commitFails = true;
            try {
                $run->callRecordResult(self::setNote('sent 1'));
            } catch (\RuntimeException $e) {
                $seen[] = $e->getMessage();
            } finally {
                TableDb::$commitFails = false;
            }
            $seen = [...$seen, $run->note(), self::stored($id)['note']];
        });

        $this->assertSame(['DB_AA::transaction(): commit failed, the block was not stored', 'mine', 'mine'], $seen);
    }

    /** the step that ends in the final phase goes on there with its claim */
    public function test_recordResult_from_final_phase(): void
    {
        $id = self::start()->getId();
        $ok = null;
        ScriptedBatchRun::$script       = [new BatchStepResult(['n' => 1], true)];
        ScriptedBatchRun::$onFinalCheck = static function (ScriptedBatchRun $run) use (&$ok): void {
            $ok = $run->callRecordResult(self::setNote('reported'));
        };

        $this->assertSame('done', self::runQueued());

        $this->assertTrue($ok);
        $this->assertSame('reported', self::stored($id)['note']);
    }

    /** the made-up result of a step that kept dying ends the step without overwriting what it recorded */
    public function test_recordResult_step_died_keeps_recorded_props(): void
    {
        $id = self::start()->getId();
        self::storedSet($id, 'failures', '2');
        $died = [];
        self::inStep(static function (ScriptedBatchRun $run) use (&$died): void {
            $run->callBeforeAction(self::setNote('inflight 0'), 60);
            $died = TableDb::$tables;                               // the process dies here, the step never ends
        });
        TableDb::$tables         = $died;
        ScriptedBatchRun::$calls = [];
        ScriptedBatchRun::$now   = self::NOW + 1000;               // past its lease, the queue repeats the step

        $this->assertSame('error STEP_DIED', self::runQueued());

        $this->assertSame(['onRetriesExhausted', 'commitStep'], ScriptedBatchRun::hooks(), 'work() does not run');
        $this->assertSame([], ScriptedBatchRun::call('commitStep')['arg']);
        $this->assertSame('inflight 0', self::stored($id)['note']);
    }
}
