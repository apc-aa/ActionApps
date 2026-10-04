<?php

declare(strict_types=1);

/**
 * BatchRun: the record of a long run, its start, and a queue step that moves on - or finishes
 * through the final phase. The record lives in TableDb's object_text, the steps in its toexecute.
 */

// AA_Object (include/statestore.php) and the queue need the config constants include/config.php gives

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
        'PERM_LIB'        => 'sql',
        'DB_HOST'         => 'localhost',
        'DB_NAME'         => 'aa_test',
        'DB_USER'         => 'aa',
        'DB_PASSWORD'     => '',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Later {

    use AA\IO\DB\DB_AA;
    use AA\IO\DB\DB_PDO;
    use AA\Later\BatchFailed;
    use AA\Later\BatchPosition;
    use AA\Later\BatchStep;
    use AA\Later\BatchStepResult;
    use InvalidArgumentException;
    use AA_Property;
    use JsonException;
    use LogicException;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Central\Package\Fixtures\TableDb;
    use Tests\Unit\Later\Fixtures\ScriptedBatchRun;

    // the helper classes at the end extend the fixture, so it loads with the file, not in setUpBeforeClass()
    require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
    require_once __DIR__ . '/Fixtures/ScriptedBatchRun.php';

    final class BatchRunStepTest extends TestCase
    {
        /** after the real clock, so the queue keeps the times the run asks for */
        private const NOW     = 1_900_000_000;
        private const SUBJECT = '5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a';
        private const USER    = 'uid-1';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../../../include/constants.php';
            require_once __DIR__ . '/../../../include/util.php';
            require_once __DIR__ . '/../Central/Package/fixtures/TableDb.php';
        }

        protected function setUp(): void
        {
            TableDb::reset();
            TableDb::$engines    = ['object_text' => 'InnoDB', 'toexecute' => 'InnoDB'];
            $GLOBALS['spareDBs'] = [new TableDb(), new TableDb(), new TableDb(), new TableDb()];
            ScriptedBatchRun::reset();
            ScriptedBatchRun::$now      = self::NOW;
            ScriptedBatchRun::$microNow = (float) self::NOW;
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            TableDb::reset();
            ScriptedBatchRun::reset();
            QueueRefusingDb::$refused = 0;
            set_time_limit(0);   // a task later() runs at once sets a 30 s limit - it must not outlive the test
        }

        /** a run started as its users start it - in their own transaction */
        private static function start(?string $state = null, array $props = []): ScriptedBatchRun
        {
            return DB_AA::transaction(static fn() => ScriptedBatchRun::start($props + ['subject' => self::SUBJECT, 'user' => self::USER, 'position' => ['n' => 0], 'note' => 'mine'], $state));
        }

        /** @return array<string, string> property => value of the stored record */
        private static function stored(string $id): array
        {
            $rows = array_filter(TableDb::rows('object_text'), static fn(array $r): bool => $r['object_id'] === $id);
            return array_map('strval', array_column($rows, 'value', 'property'));
        }

        /** changes a stored property behind the run's back, as another process would */
        private static function storedSet(string $id, string $property, string $value): void
        {
            foreach (TableDb::$tables['object_text'] as $i => $row) {
                if (($row['object_id'] === $id) && ($row['property'] === $property)) {
                    TableDb::$tables['object_text'][$i]['value'] = $value;
                    return;
                }
            }
            TableDb::$tables['object_text'][] = ['id' => 999, 'object_id' => $id, 'property' => $property, 'priority' => 0, 'value' => $value, 'flag' => null];
        }

        /** @return list<array<string, mixed>> the queue rows */
        private static function queue(): array
        {
            return TableDb::rows('toexecute');
        }

        private static function stepOf(array $row): BatchStep
        {
            $step = unserialize((string) $row['object']);
            self::assertInstanceOf(BatchStep::class, $step);
            return $step;
        }

        /** runs the oldest queued step as the queue does: the row goes away after the step */
        private static function runQueued(): string
        {
            $row = self::queue()[0] ?? throw new LogicException('Nothing queued');
            $ret = self::stepOf($row)->toexecutelater();
            TableDb::$tables['toexecute'] = array_values(array_filter(TableDb::rows('toexecute'), static fn(array $r): bool => $r['id'] !== $row['id']));
            return $ret;
        }

        // ---------------------------------------------------------------- start

        public function test_start_active_queues_first_step(): void
        {
            $run    = self::start();
            $stored = self::stored($run->getId());

            $this->assertSame(ScriptedBatchRun::class, $stored['aa_type']);
            $this->assertSame(self::SUBJECT, $stored['aa_owner'], 'the run belongs to its subject');
            $this->assertSame('running', $stored['state']);
            $this->assertSame(self::SUBJECT, $stored['subject']);
            $this->assertSame(self::USER, $stored['user']);
            $this->assertSame((string) self::NOW, $stored['started']);
            $this->assertSame('0', $stored['step']);
            $this->assertSame('mine', $stored['note']);
            $this->assertSame([], TableDb::rows('object_integer'), 'one table holds the whole record');

            $queue = self::queue();
            $this->assertCount(1, $queue);
            $this->assertSame('Scripted_' . $run->getId(), $queue[0]['selector']);
            $this->assertSame(5, $queue[0]['priority']);
            $this->assertSame(self::NOW, $queue[0]['execute_after']);
            $step = self::stepOf($queue[0]);
            $this->assertSame([ScriptedBatchRun::class, $run->getId(), BatchPosition::fingerprint(0, ['n' => 0])], [$step->class, $step->run, $step->expected]);
        }

        public function test_start_own_state_queues_nothing(): void
        {
            $run = self::start('preview');

            $this->assertSame('preview', self::stored($run->getId())['state']);
            $this->assertSame([], self::queue());
        }

        public function test_start_refuses_a_role_as_own_state(): void
        {
            $this->expectException(InvalidArgumentException::class);
            self::start('done');
        }

        /** @return array<string, array{string}> */
        public static function transactionalTables(): array
        {
            return ['the record (its lock)' => ['object_text'], 'the queue (a step seen before the record commits)' => ['toexecute']];
        }

        #[DataProvider('transactionalTables')]
        public function test_start_refuses_without_innodb(string $table): void
        {
            TableDb::$engines[$table] = 'MyISAM';
            try {
                self::start();
                $this->fail("A run started without a transactional $table");
            } catch (BatchFailed $e) {
                $this->assertSame('NO_TRANSACTION', $e->reason);
                $this->assertSame($table, $e->where);
            }
            $this->assertSame([], TableDb::rows('object_text'));
            $this->assertSame([], self::queue());
        }

        public function test_start_outside_transaction_throws(): void
        {
            $this->expectException(LogicException::class);
            ScriptedBatchRun::start(['subject' => self::SUBJECT, 'user' => self::USER, 'position' => []]);
        }

        public function test_start_refuses_core_property_from_caller(): void
        {
            $this->expectException(InvalidArgumentException::class);
            self::start(null, ['lease_tok' => 'forged']);
        }

        public function test_start_refuses_subject_that_is_no_id(): void
        {
            $this->expectException(InvalidArgumentException::class);
            self::start(null, ['subject' => 'Import_1']);
        }

        /** @return array<string, array{string, string}> */
        public static function oddProperties(): array
        {
            return [
                'stored as a number'          => ['count', 'int'],
                'named as the object\'s own' => ['aa_owner', 'text'],
                'named as the lock flag'      => ['locked', 'text'],
                'longer than the column'      => ['seventeen_bytes__', 'text'],
            ];
        }

        #[DataProvider('oddProperties')]
        public function test_start_refuses_odd_own_property(string $id, string $type): void
        {
            OddPropsBatchRun::$odd = [$id => new AA_Property($id, $id, $type, false, true, $type)];
            try {
                DB_AA::transaction(static fn() => OddPropsBatchRun::start(['subject' => self::SUBJECT, 'user' => self::USER]));
                $this->fail("A run started with an own property $id ($type)");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            } finally {
                OddPropsBatchRun::$odd = [];
            }
            $this->assertSame([], TableDb::rows('object_text'));
        }

        public function test_start_position_as_deep_as_stored_reads_back(): void
        {
            $deep = 1;
            for ($i = 0; $i < 15; ++$i) {
                $deep = [$deep];
            }
            $id = self::start(null, ['position' => ['d' => $deep]])->getId();          // 16 levels of arrays
            $this->assertSame(['d' => $deep], ScriptedBatchRun::get($id)->position());

            $this->expectException(JsonException::class);
            self::start(null, ['position' => ['d' => [$deep]]]);
        }

        public function test_start_queue_refusal_runs_nothing(): void
        {
            $GLOBALS['spareDBs'] = [new QueueRefusingDb(), new QueueRefusingDb(), new QueueRefusingDb(), new QueueRefusingDb()];
            try {
                self::start();
                $this->fail('A run started without its first step queued');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('queue did not take', $e->getMessage());
            }
            $this->assertSame(1, QueueRefusingDb::$refused);
            $this->assertSame([], ScriptedBatchRun::hooks(), 'the step later() ran at once found no record');
            $this->assertSame([], TableDb::rows('object_text'));
        }

        // ---------------------------------------------------------------- reading

        public function test_get_reads_past_object_cache(): void
        {
            $id = self::start()->getId();
            $this->assertSame('running', ScriptedBatchRun::get($id)->state());

            self::storedSet($id, 'state', 'error');

            $this->assertSame('error', ScriptedBatchRun::get($id)->state(), 'another process changed it');
            $this->assertSame(['n' => 0], ScriptedBatchRun::get($id)->position());
            $this->assertSame(0, ScriptedBatchRun::get($id)->step());
        }

        public function test_get_of_another_class_is_null(): void
        {
            $id = self::start()->getId();
            self::storedSet($id, 'aa_type', 'AA_Plannedtask');

            $this->assertNull(ScriptedBatchRun::get($id));
            $this->assertNull(ScriptedBatchRun::get('not-an-id'));
        }

        public function test_lockForUpdate_outside_transaction_throws(): void
        {
            $id = self::start()->getId();
            $this->expectException(LogicException::class);
            ScriptedBatchRun::lockForUpdate($id);
        }

        public function test_lockForUpdate_reads_for_update(): void
        {
            $id = self::start()->getId();
            TableDb::$log = [];
            DB_AA::transaction(static fn() => ScriptedBatchRun::lockForUpdate($id));

            $reads = array_filter(TableDb::$log, static fn(string $sql): bool => str_starts_with($sql, 'SELECT') && str_contains($sql, '`object_text`'));
            $this->assertCount(1, $reads);
            $this->assertStringEndsWith(' FOR UPDATE', reset($reads));
        }

        // ---------------------------------------------------------------- stale rows

        public function test_runStep_stale_fingerprint_does_nothing(): void
        {
            $id = self::start()->getId();
            TableDb::$log = [];

            $ret = ScriptedBatchRun::runStep(ScriptedBatchRun::class, $id, BatchPosition::fingerprint(1, ['n' => 0]));

            $this->assertSame('stale', $ret);
            $this->assertSame([], ScriptedBatchRun::hooks());
            $this->assertSame([], TableDb::writes());
        }

        public function test_runStep_stale_state_does_nothing(): void
        {
            $id = self::start()->getId();
            self::storedSet($id, 'state', 'error');
            TableDb::$log = [];

            $this->assertSame('stale', self::runQueued());
            $this->assertSame([], ScriptedBatchRun::hooks());
            $this->assertSame([], TableDb::writes());
        }

        public function test_runStep_rejects_non_batch_run_class(): void
        {
            $id = self::start()->getId();
            TableDb::$log = [];

            $ret = ScriptedBatchRun::runStep(\ArrayObject::class, $id, BatchPosition::fingerprint(0, ['n' => 0]));

            $this->assertStringContainsString('not a batch run', $ret);
            $this->assertSame([], TableDb::$log, 'nothing is read for a row naming another class');
        }

        // ---------------------------------------------------------------- a step that moves on

        public function test_runStep_success_with_work_left_queues_next_step(): void
        {
            $id = self::start()->getId();
            ScriptedBatchRun::$script = [
                new BatchStepResult(['n' => 5], false, ['read' => 3, 'jobs' => ['ok' => 1]], ['W1' => 1], 'stream 1', 1, 4),
                new BatchStepResult(['n' => 9], false, ['read' => 2, 'jobs' => ['ok' => 1, 'bad' => 1]], [], 'stream 2', 2, 4),
            ];
            self::storedSet($id, 'failures', '2');

            self::runQueued();

            $stored = self::stored($id);
            $this->assertSame('running', $stored['state']);
            $this->assertSame('0', $stored['failures'], 'progress clears the failures');
            $this->assertSame(['n' => 5], json_decode($stored['position'], true));
            $this->assertSame('1', $stored['step']);
            $this->assertSame((string) self::NOW, $stored['moved']);
            $this->assertSame('', $stored['lease'], 'the lease is released');
            $this->assertSame(['read' => 3, 'jobs' => ['ok' => 1]], json_decode($stored['counts'], true));
            $this->assertSame(['label' => 'stream 1', 'done' => 1, 'total' => 4], json_decode($stored['progress'], true));
            $queue = self::queue();
            $this->assertCount(1, $queue, 'the step\'s own row is gone, the next one is queued');
            $this->assertSame(BatchPosition::fingerprint(1, ['n' => 5]), self::stepOf($queue[0])->expected);
            $this->assertSame(self::NOW, $queue[0]['execute_after']);

            ScriptedBatchRun::$now = self::NOW + 40;
            self::runQueued();

            $stored = self::stored($id);
            $this->assertSame(['read' => 5, 'jobs' => ['ok' => 2, 'bad' => 1]], json_decode($stored['counts'], true), 'counts add the deltas');
            $this->assertSame(['W1' => 1], json_decode($stored['warnings'], true));
            $this->assertSame('2', $stored['step']);
            $this->assertSame((string) (self::NOW + 40), $stored['moved']);
        }

        public function test_runStep_works_outside_transaction_from_stored_position(): void
        {
            self::start(null, ['position' => ['n' => 3, 'job' => 'a']]);
            ScriptedBatchRun::$script = [new BatchStepResult(['n' => 4], false)];

            self::runQueued();

            $work = ScriptedBatchRun::call('work');
            $this->assertFalse($work['tx']);
            $this->assertSame(['n' => 3, 'job' => 'a'], $work['arg']);
        }

        public function test_runStep_success_finished_runs_final_phase_to_done(): void
        {
            $id = self::start()->getId();
            self::storedSet($id, 'sealed', 'encrypted-secret');
            self::storedSet($id, 'sealed_until', (string) (self::NOW + 3600));
            ScriptedBatchRun::$script = [new BatchStepResult(['n' => 7], true, ['read' => 1])];
            ScriptedBatchRun::$finalReport = ['checked' => 12];

            $ret = self::runQueued();

            $this->assertSame(['work', 'commitStep', 'finalCheck', 'afterClose'], ScriptedBatchRun::hooks());
            $this->assertFalse(ScriptedBatchRun::call('finalCheck')['tx'], 'the final check may call the source');
            $this->assertFalse(ScriptedBatchRun::call('afterClose')['tx'], 'afterClose runs after the commit');
            $this->assertSame('done', ScriptedBatchRun::call('afterClose')['arg']);
            $stored = self::stored($id);
            $this->assertSame('done', $stored['state']);
            $this->assertSame(['checked' => 12], json_decode($stored['report'], true));
            $this->assertSame((string) self::NOW, $stored['finished']);
            $this->assertSame('', $stored['sealed'], 'the secret goes with the run');
            $this->assertSame('', $stored['sealed_until']);
            $this->assertSame('', $stored['lease']);
            $this->assertSame('1', $stored['step']);
            $this->assertSame('done', $ret);

            // only the safety row of the final phase is left, and it finds the run done
            $this->assertCount(1, self::queue());
            $this->assertSame('stale', self::runQueued());
            $this->assertSame(['work', 'commitStep', 'finalCheck', 'afterClose'], ScriptedBatchRun::hooks());
        }

        public function test_runStep_final_phase_keeps_lease_and_safety_row(): void
        {
            $id   = self::start()->getId();
            $seen = [];
            ScriptedBatchRun::$script       = [new BatchStepResult(['n' => 7], true)];
            ScriptedBatchRun::$onFinalCheck = static function () use ($id, &$seen): void {
                $seen = [self::stored($id), self::queue()];          // what a process dying here leaves behind
            };

            self::runQueued();

            [$stored, $queue] = $seen;
            $this->assertSame('running', $stored['state']);
            $this->assertSame('1', $stored['closing']);
            $this->assertGreaterThan(self::NOW, (int) $stored['lease'], 'the lease covers the final check');
            $this->assertNotSame('', $stored['lease_tok'], 'the lease is not released');
            $this->assertCount(2, $queue, 'the step\'s own row goes only after the step');
            $this->assertSame((int) $stored['lease'] + 1, $queue[1]['execute_after'], 'the safety row repeats the phase after the lease');
            $this->assertSame(BatchPosition::fingerprint(1, ['n' => 7]), self::stepOf($queue[1])->expected);
        }

        public function test_runStep_end_of_step_refused_when_lease_token_changed(): void
        {
            $id = self::start()->getId();
            ScriptedBatchRun::$script = [static function () use ($id): BatchStepResult {
                self::storedSet($id, 'lease_tok', 'another-step');       // Continue, or another process claimed meanwhile
                return new BatchStepResult(['n' => 5], false, ['read' => 3]);
            }];
            $before = self::stored($id);

            $this->assertSame('lease lost', self::runQueued());

            $after = self::stored($id);
            $this->assertSame($before['step'], $after['step']);
            $this->assertSame($before['position'], $after['position']);
            $this->assertSame($before['counts'] ?? '', $after['counts'] ?? '');
            $this->assertSame('another-step', $after['lease_tok']);
            $this->assertSame([], self::queue(), 'no next step');
            $this->assertNotContains('commitStep', ScriptedBatchRun::hooks());
        }

        public function test_runStep_without_progress_keeps_moved(): void
        {
            $id = self::start()->getId();
            self::storedSet($id, 'moved', '123');
            ScriptedBatchRun::$script = [new BatchStepResult(['n' => 0], false, retry: 'NO_PROGRESS')];

            self::runQueued();

            $stored = self::stored($id);
            $this->assertSame('123', $stored['moved'], 'moved is the last progress, not the last step');
            $this->assertSame('1', $stored['step'], 'the next row differs from the old one even so');
            $this->assertSame(BatchPosition::fingerprint(1, ['n' => 0]), self::stepOf(self::queue()[0])->expected);
        }

        public function test_runStep_queue_refusal_keeps_the_step_unwritten(): void
        {
            $id = self::start()->getId();
            ScriptedBatchRun::$script = [new BatchStepResult(['n' => 5], false)];
            $GLOBALS['spareDBs']      = [new QueueRefusingDb(), new QueueRefusingDb(), new QueueRefusingDb(), new QueueRefusingDb()];

            try {
                self::runQueued();
                $this->fail('A step ended without its next step queued');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }

            $this->assertSame(1, QueueRefusingDb::$refused);
            $this->assertSame(['work', 'commitStep'], ScriptedBatchRun::hooks(), 'the next step later() ran at once found the old record - stale');
            $stored = self::stored($id);
            $this->assertSame('0', $stored['step']);
            $this->assertSame(['n' => 0], json_decode($stored['position'], true));
        }

        public function test_runStep_final_phase_refused_when_lease_token_changed(): void
        {
            $id = self::start()->getId();
            ScriptedBatchRun::$script       = [new BatchStepResult(['n' => 7], true)];
            ScriptedBatchRun::$onFinalCheck = static function () use ($id): void {
                self::storedSet($id, 'lease_tok', 'another-step');
            };

            $this->assertSame('lease lost', self::runQueued());

            $stored = self::stored($id);
            $this->assertSame('running', $stored['state']);
            $this->assertSame('', $stored['report']);
            $this->assertNotContains('afterClose', ScriptedBatchRun::hooks());
        }

        public function test_runStep_safety_row_repeats_failed_final_phase(): void
        {
            $id = self::start()->getId();
            ScriptedBatchRun::$script       = [new BatchStepResult(['n' => 7], true)];
            ScriptedBatchRun::$onFinalCheck = static function (): void {
                ScriptedBatchRun::$onFinalCheck = null;
                throw new \RuntimeException('source down');
            };
            try {
                self::runQueued();
                $this->fail('finalCheck() did not throw');
            } catch (\RuntimeException) {
                $this->assertSame('running', self::stored($id)['state']);
            }

            // the queue keeps the dead step's own row until its lease; the safety row runs the phase again
            $safety                = self::queue()[1];
            ScriptedBatchRun::$now = (int) $safety['execute_after'];

            $this->assertSame('done', self::stepOf($safety)->toexecutelater());
            $this->assertSame(['work', 'commitStep', 'finalCheck', 'finalCheck', 'afterClose'], ScriptedBatchRun::hooks());
            $this->assertSame('done', self::stored($id)['state']);
        }

        public function test_runStep_commit_step_runs_under_lock(): void
        {
            self::start();
            ScriptedBatchRun::$script = [new BatchStepResult(['n' => 5], false, extra: ['stream' => 'a'])];

            self::runQueued();

            $work   = ScriptedBatchRun::call('work');
            $commit = ScriptedBatchRun::call('commitStep');
            $this->assertTrue($commit['tx']);
            $this->assertNotSame('', $work['token']);
            $this->assertSame($work['token'], $commit['token'], 'on the instance whose token was checked');
            $this->assertSame(['stream' => 'a'], $commit['arg']);
        }

        public function test_runStep_claim_takes_a_lease(): void
        {
            $id = self::start()->getId();
            $seen = [];
            ScriptedBatchRun::$script = [static function () use ($id, &$seen): BatchStepResult {
                $seen = self::stored($id);
                return new BatchStepResult(['n' => 5], false);
            }];

            self::runQueued();

            $this->assertGreaterThan(self::NOW, (int) $seen['lease']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $seen['lease_tok']);
        }

        public function test_runStep_after_close_exception_becomes_warning(): void
        {
            $id = self::start()->getId();
            ScriptedBatchRun::$script           = [new BatchStepResult(['n' => 7], true)];
            ScriptedBatchRun::$afterCloseThrows = new \RuntimeException('cache purge failed: password=hunter2');

            $ret = self::runQueued();

            $stored = self::stored($id);
            $this->assertSame('done', $stored['state'], 'the state stays');
            $this->assertSame('done', $ret);
            $this->assertSame('AFTER_CLOSE', json_decode($stored['progress'], true)['warning'] ?? null);
            $this->assertStringNotContainsString('hunter2', implode("\n", $stored));
        }

        // ---------------------------------------------------------------- enqueueFrom

        public function test_enqueueFrom_in_callers_transaction(): void
        {
            $id = self::start('preview')->getId();

            try {
                DB_AA::transaction(static function () use ($id): void {
                    ScriptedBatchRun::lockForUpdate($id)->enqueueFrom('preview', ['n' => 1]);
                    throw new \RuntimeException('the caller\'s own write failed');
                });
            } catch (\RuntimeException) {
            }
            $this->assertSame('preview', self::stored($id)['state'], 'rolled back with the caller');
            $this->assertSame([], self::queue());

            DB_AA::transaction(static fn() => ScriptedBatchRun::lockForUpdate($id)->enqueueFrom('preview', ['n' => 1]));

            $stored = self::stored($id);
            $this->assertSame('running', $stored['state']);
            $this->assertSame(['n' => 1], json_decode($stored['position'], true));
            $this->assertSame('1', $stored['step']);
            $this->assertCount(1, self::queue());
            $this->assertSame(BatchPosition::fingerprint(1, ['n' => 1]), self::stepOf(self::queue()[0])->expected);
        }

        public function test_enqueueFrom_needs_a_locked_run(): void
        {
            $id = self::start('preview')->getId();
            $this->expectException(LogicException::class);
            DB_AA::transaction(static fn() => ScriptedBatchRun::get($id)->enqueueFrom('preview', ['n' => 1]));
        }

        public function test_enqueueFrom_another_state_throws(): void
        {
            $id = self::start()->getId();
            $this->expectException(LogicException::class);
            DB_AA::transaction(static fn() => ScriptedBatchRun::lockForUpdate($id)->enqueueFrom('preview', ['n' => 1]));
        }

        public function test_enqueueFrom_error_is_no_own_state(): void
        {
            $id = self::start()->getId();
            self::storedSet($id, 'state', 'error');
            $this->expectException(LogicException::class);
            DB_AA::transaction(static fn() => ScriptedBatchRun::lockForUpdate($id)->enqueueFrom('error', ['n' => 1]));
        }

        public function test_enqueueFrom_with_closing_goes_to_final_phase(): void
        {
            $id = self::start('preview')->getId();
            DB_AA::transaction(static fn() => ScriptedBatchRun::lockForUpdate($id)->enqueueFrom('preview', ['n' => 1], true));

            $this->assertSame('done', self::runQueued());

            $this->assertSame(['finalCheck', 'afterClose'], ScriptedBatchRun::hooks(), 'no work - straight to the final phase');
            $this->assertSame('done', self::stored($id)['state']);
        }

        // ---------------------------------------------------------------- BatchStep

        public function test_runStep_hooks_get_no_lock_after_its_commit(): void
        {
            self::start();
            ScriptedBatchRun::$script = [new BatchStepResult(['n' => 7], true)];

            self::runQueued();

            $this->assertTrue(ScriptedBatchRun::call('commitStep')['locked'], 'commitStep() runs under the lock');
            foreach (['work', 'finalCheck', 'afterClose'] as $hook) {
                $this->assertFalse(ScriptedBatchRun::call($hook)['locked'], "$hook() must not pass as locked");
            }
        }

        public function test_batchStep_running_is_set_during_work_only(): void
        {
            $id = self::start()->getId();
            ScriptedBatchRun::$script = [new BatchStepResult(['n' => 7], true)];
            $this->assertNull(BatchStep::running());

            self::runQueued();

            $this->assertSame($id, ScriptedBatchRun::call('work')['running']);
            $this->assertNull(ScriptedBatchRun::call('commitStep')['running']);
            $this->assertNull(ScriptedBatchRun::call('afterClose')['running']);
            $this->assertNull(BatchStep::running());
        }

        public function test_batchStep_running_is_cleared_when_work_throws(): void
        {
            self::start();
            ScriptedBatchRun::$script = [new \RuntimeException('source down')];
            try {
                self::runQueued();
            } catch (\RuntimeException) {
            }
            $this->assertNull(BatchStep::running());
        }

        public function test_batchStep_serialized_holds_no_run_object(): void
        {
            $step = new BatchStep(ScriptedBatchRun::class, self::SUBJECT, str_repeat('f', 40));
            $text = serialize($step);

            $this->assertStringStartsWith('O:' . strlen(BatchStep::class) . ':"' . BatchStep::class . '":3:{', $text);
            $this->assertSame(['class' => ScriptedBatchRun::class, 'run' => self::SUBJECT, 'expected' => str_repeat('f', 40)], get_object_vars(unserialize($text)));
        }

        public function test_batchStep_log_selector_names_the_run(): void
        {
            $run   = self::start();
            $entry = self::stepOf(self::queue()[0])->logSelector();

            $this->assertSame($run->selector(), $entry->cls);
            $this->assertSame($run->getId(), $entry->id);
        }
    }

    /** ScriptedBatchRun with own properties the test gives it */
    final class OddPropsBatchRun extends ScriptedBatchRun
    {
        /** @var array<string, AA_Property> */
        public static array $odd = [];

        public static function getClassProperties(): array
        {
            return parent::getClassProperties() + self::$odd;
        }
    }

    /** TableDb whose queue INSERT fails without halting, as a DB_PDO in the 'no' halt mode does - so
     *  Toexecute::later() runs the task at once */
    final class QueueRefusingDb extends DB_PDO
    {
        public static int $refused = 0;

        private TableDb $db;

        public function __construct()
        {
            parent::__construct(['type' => 'db_pdo:sqlite', 'database' => ':memory:']);
            $this->db = new TableDb();
        }

        public function query($Query_String): bool
        {
            if (str_starts_with($Query_String, 'INSERT INTO `toexecute`')) {
                ++self::$refused;
                return false;
            }
            return $this->db->query($Query_String);
        }

        public function query_halt(string $Query_String, string $mode): bool
        {
            return $this->query($Query_String);
        }

        public function next_record(): bool
        {
            $more         = $this->db->next_record();
            $this->Record = $this->db->record();
            return $more;
        }

        public function num_rows(): int
        {
            return $this->db->num_rows();
        }

        public function affected_rows(): int
        {
            return $this->db->affected_rows();
        }

        public function last_insert_id(): false|string
        {
            return $this->db->last_insert_id();
        }

        public function beginTransaction(): bool
        {
            return $this->db->beginTransaction();
        }

        public function commit(): bool
        {
            return $this->db->commit();
        }

        public function rollback(): bool
        {
            return $this->db->rollback();
        }
    }
}
