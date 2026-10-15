<?php

declare(strict_types=1);

// Toexecute runs on DB_AA + Metabase, whose getDB()/freeDB() live in include/locsess.php
// (loaded by the RecordingDb fixture) and need the constants include/config.php
// normally provides. Safe test values, defined first.

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
    use AA\Later\BatchStep;
    use AA\Later\Toexecute;
    use AA\Optimize\CheckQueue;
    use AA\Util\LogOnce;
    use AA_Logentry;
    use PDO;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;

    /**
     * A task that throws must cost only itself: before, the exception left
     * _exeOne() and ended the whole round, so every task queued behind it
     * waited for the next run - and hit the same task again after its lease.
     *
     * The real Toexecute runs against an in-memory SQLite `toexecute` + `log`
     * (the SQL DB_AA and Metabase emit for the queue is plain enough for it).
     */
    final class ToexecuteTest extends TestCase
    {
        /** what the tests' tasks must never leak into the log or the report */
        public const SECRET = 'password=hunter2';

        /** @var list<string> names of the tasks that ran, in order */
        public static array $ran = [];

        private PDO $pdo;

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            self::$ran = [];
            ClockTask::$seen = [];
            ParamsTask::$ran = [];
            SqliteDb::$locks = [];
            SqliteDb::$others = [];
            LogOnce::reset();   // one row a day per class - per process, so another test's row would hide this one's
            set_time_limit(0);   // the round size comes from the limit - another test may have left one
            $this->pdo = new PDO('sqlite::memory:');
            $this->pdo->exec('CREATE TABLE toexecute (id INTEGER PRIMARY KEY, created INTEGER NOT NULL DEFAULT 0, execute_after INTEGER NOT NULL, aa_user TEXT NOT NULL DEFAULT \'\', priority INTEGER NOT NULL, selector TEXT NOT NULL, object BLOB NOT NULL, params BLOB NOT NULL)');
            $this->pdo->exec('CREATE TABLE log (id INTEGER PRIMARY KEY, time INTEGER NOT NULL, user TEXT, type TEXT, selector TEXT, params TEXT)');
            $GLOBALS['spareDBs'] = [$this->handle(), $this->handle()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            set_time_limit(0);   // execute_one() sets a 30 s limit - it must not outlive the test
        }

        /** the tests that expect the default 16 s round cannot run with the constant set */
        private function requireDefaultRoundSize(): void
        {
            if (defined('TOEXECUTE_ALLOWED_TIME')) {
                $this->markTestSkipped('TOEXECUTE_ALLOWED_TIME is defined - the round has a fixed size');
            }
        }

        /** a DB_PDO handle on the test's SQLite database (injected, so it never connects) */
        private function handle(): DB_PDO
        {
            $db = new SqliteDb(['type' => 'db_pdo:sqlite', 'database' => ':memory:']);
            (new ReflectionProperty(DB_PDO::class, 'dbh'))->setValue($db, $this->pdo);
            return $db;
        }

        /** @param object|string $task  the task, or its already serialized form */
        private function seed(object|string $task, string $selector, int $priority, ?int $execute_after = null, array $params = [], ?int $created = null): int
        {
            $this->pdo->prepare('INSERT INTO toexecute (created, execute_after, priority, selector, object, params) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$created ?? time(), $execute_after ?? time() - 60, $priority, $selector, is_string($task) ? $task : serialize($task), serialize($params)]);
            return (int) $this->pdo->lastInsertId();
        }

        /** @return array<int, array{execute_after:int, priority:int}> queue rows left, by id */
        private function queue(): array
        {
            $rows = [];
            foreach ($this->pdo->query('SELECT id, execute_after, priority FROM toexecute ORDER BY id') as $row) {
                $rows[(int) $row['id']] = ['execute_after' => (int) $row['execute_after'], 'priority' => (int) $row['priority']];
            }
            return $rows;
        }

        /** a serialized object of $class with no properties - the length prefix must match, or unserialize() fails for another reason */
        private static function objectOf(string $class): string
        {
            return 'O:' . strlen($class) . ':"' . $class . '":0:{}';
        }

        /** runs $fn with PHP's warnings silenced - for payloads PHP warns about on purpose */
        private static function quietly(callable $fn): void
        {
            set_error_handler(static fn() => true, E_WARNING);
            try {
                $fn();
            } finally {
                restore_error_handler();
            }
        }

        /** the queue row is due again, as when its time has come */
        private function due(int $id): void
        {
            $this->pdo->prepare('UPDATE toexecute SET execute_after = ? WHERE id = ?')->execute([time() - 1, $id]);
        }

        /** @return array<string, mixed> the whole queue row as stored */
        private function row(int $id): array
        {
            $stmt = $this->pdo->prepare('SELECT * FROM toexecute WHERE id = ?');
            $stmt->execute([$id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }

        /** the params a queue row holds now */
        private function paramsOf(int $id): mixed
        {
            $stmt = $this->pdo->prepare('SELECT params FROM toexecute WHERE id = ?');
            $stmt->execute([$id]);
            return unserialize((string) $stmt->fetchColumn());
        }

        /** @return list<array{selector:string, params:string}> */
        private function logRows(string $type): array
        {
            $stmt = $this->pdo->prepare('SELECT selector, params FROM log WHERE type = ? ORDER BY id');
            $stmt->execute([$type]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        public static function throwables(): array
        {
            return [
                'exception' => [\RuntimeException::class],
                'error'     => [\TypeError::class],
            ];
        }

        #[DataProvider('throwables')]
        public function test_execute_failing_task_does_not_end_the_round(string $throwable): void
        {
            $this->seed(new RecordingTask('first'), 'test-first', 300);
            $failing = $this->seed(new ThrowingTask($throwable), 'test-failing', 200);
            $this->seed(new RecordingTask('last'), 'test-last', 100);

            (new Toexecute())->execute();

            $this->assertSame(['first', 'last'], self::$ran, 'the tasks before and after the failing one both ran');
            $left = $this->queue();
            $this->assertSame([$failing], array_keys($left), 'the done tasks are gone, the failing one stays queued');
            $this->assertSame(199, $left[$failing]['priority'], 'the failed attempt counts - priority sinks, 0 gives it up');
            $this->assertGreaterThan(time() + Toexecute::CLAIM_LEASE - 60, $left[$failing]['execute_after'], 'retried only after the claim lease');

            $failed = $this->logRows(Toexecute::FAILED_EVENT);
            $this->assertSame([['selector' => 'test-failing', 'params' => "#$failing $throwable tests/Unit/Later/ToexecuteTest.php:" . ThrowingTask::$line]], $failed, 'task, id, error class and where it was thrown - never the message');
            $this->assertCount(1, array_filter($this->logRows('TOEXECUTE'), static fn(array $r) => str_starts_with($r['params'], 'Xrun:3:3:')), 'the round finished and logged its run row');
        }

        public function test_executeTask_failing_user_task_stays_parked_and_reports_the_class_only(): void
        {
            $failing = $this->seed(new ThrowingTask(\RuntimeException::class), 'test-failing', 100, Toexecute::TOEXECUTE_USER_TASK_TIME);

            $toexecute = new Toexecute();
            $toexecute->executeTask([$failing]);

            $this->assertSame([$failing => ['execute_after' => Toexecute::TOEXECUTE_USER_TASK_TIME, 'priority' => 99]], $this->queue(), 'a user task stays in the user queue for another manual run');
            $report = json_encode($toexecute->reportRows());
            $this->assertStringContainsString('RuntimeException', $report, 'the user sees that the task failed and with what');
            $this->assertStringNotContainsString(self::SECRET, $report);
        }

        public function test_failureParams_cuts_a_long_path_from_the_left(): void
        {
            $params = Toexecute::failureParams(123, new LongPathException(str_repeat('deep/', 30)));

            $this->assertLessThanOrEqual(AA_Logentry::PARAMS_MAX, strlen($params), 'log.params is 128 bytes - a longer row would be cut from the end');
            $this->assertStringStartsWith('#123 ' . LongPathException::class . ' ', $params);
            $this->assertStringEndsWith('/Task.php:4242', $params, 'the file name and line survive, the path loses its head');
            $this->assertStringNotContainsString(self::SECRET, $params);
        }

        public function test_failureParams_cuts_a_multibyte_path_on_a_character_boundary(): void
        {
            // the pad after the 3-byte characters shifts the cut through all three alignments
            foreach (['', 'a', 'aa'] as $pad) {
                $params = Toexecute::failureParams(123, new LongPathException(str_repeat('€', 50) . $pad . '/'));

                $this->assertLessThanOrEqual(AA_Logentry::PARAMS_MAX, strlen($params), "pad '$pad'");
                $this->assertTrue(mb_check_encoding($params, 'UTF-8'), "pad '$pad': valid UTF-8");
                $this->assertStringEndsWith('/Task.php:4242', $params, "pad '$pad': the line survives");
            }
        }

        public function test_execute_task_of_missing_class_stays_queued_without_logging_its_payload(): void
        {
            // the class is gone (renamed, module removed) - unserialize() yields __PHP_Incomplete_Class
            $payload = 'O:16:"MissingTaskClass":1:{s:8:"password";s:' . strlen(self::SECRET) . ':"' . self::SECRET . '";}';
            $broken  = $this->seed($payload, 'test-broken', 100);

            $toexecute = new Toexecute();
            $toexecute->execute();

            $this->assertCount(1, $this->queue(), 'a task of a missing class stays queued - the class may be back after a deploy');
            $log = $this->logRows(Toexecute::LOG_CLASS_MISSING);
            $this->assertCount(1, array_filter($log, static fn(array $r) => str_contains(json_encode($r), 'MissingTaskClass')), 'the row names its class');
            $this->assertStringNotContainsString(self::SECRET, json_encode($log), 'a task object may hold credentials - its payload stays out of the log');
            $this->assertStringNotContainsString(self::SECRET, json_encode($toexecute->reportRows()), 'and out of the report');
        }

        public function test_execute_task_of_missing_class_keeps_its_priority_and_is_due_in_a_minute(): void
        {
            // another AA tree over the same database has the class, this one does not
            $broken = $this->seed(self::objectOf('AA\Later\NoSuchTaskClassX'), 'test-broken', 5);
            $this->seed(new RecordingTask('behind'), 'test-behind', 1);
            $before = $this->row($broken);

            $toexecute = new CountingToexecute();
            $toexecute->execute();

            $after = $this->row($broken);
            $this->assertSame(5, (int) $after['priority'], 'the miss does not sink the row - at 0 it would be lost');
            $this->assertEqualsWithDelta(time() + Toexecute::MISSING_CLASS_RETRY, (int) $after['execute_after'], 5, 'a runner that has the class takes it within a minute, not after the lease');
            $this->assertSame($before['params'], $after['params']);
            $this->assertSame(['behind'], self::$ran, 'the round went on past it');
            $this->assertSame(2, $toexecute->calls, 'each row once - the parked row is not due again in this round');
            $this->assertStringContainsString("#$broken", json_encode($toexecute->reportRows()), 'the report names the task');
            $this->assertSame([['selector' => 'AA\Later\NoSuchTaskClassX', 'params' => 'test-broken']], $this->logRows(Toexecute::LOG_CLASS_MISSING));
            $this->assertCount(1, array_filter($this->logRows('TOEXECUTE'), static fn(array $r) => str_starts_with($r['params'], 'Xrun:1:1:')), 'a parked row is not a started task - CheckQueue would read it as a round out of time');
        }

        public function test_execute_task_of_missing_class_keeps_its_priority_over_many_rounds(): void
        {
            $broken = $this->seed(self::objectOf('AA\Later\NoSuchTaskClassX'), 'test-broken', 5);

            for ($round = 1; $round <= 5; ++$round) {
                (new Toexecute())->execute();
                $this->assertSame(5, $this->queue()[$broken]['priority'], "round $round");
                $this->due($broken);   // a minute later
            }

            $this->assertCount(1, $this->logRows(Toexecute::LOG_CLASS_MISSING), 'one log row a day per class');
        }

        public static function missingClassAges(): array
        {
            return [
                'fresh'         => [0, 60],
                '24 minutes'    => [24 * 60, 60],
                'an hour'       => [3600, 150],
                'half a day'    => [12 * 3600, 1800],
                'a day'         => [86400, 3600],
                'a month'       => [30 * 86400, 3600],
                'created later' => [-600, 60],
            ];
        }

        #[DataProvider('missingClassAges')]
        public function test_missingClassDelay_grows_with_the_age(int $age, int $delay): void
        {
            $this->assertSame($delay, Toexecute::missingClassDelay($age));
        }

        public function test_execute_task_of_class_missing_for_long_is_tried_hourly(): void
        {
            $old   = $this->seed(self::objectOf('AA\Later\NoSuchTaskClassX'), 'test-old', 5, null, [], time() - 2 * 86400);
            $fresh = $this->seed(self::objectOf('AA\Later\NoSuchTaskClassX'), 'test-fresh', 5);

            (new Toexecute())->execute();

            $this->assertEqualsWithDelta(time() + Toexecute::MISSING_CLASS_RETRY_MAX, $this->queue()[$old]['execute_after'], 5, 'missing everywhere - one look an hour per row');
            $this->assertSame(5, $this->queue()[$old]['priority'], 'still never retired');
            $this->assertEqualsWithDelta(time() + Toexecute::MISSING_CLASS_RETRY, $this->queue()[$fresh]['execute_after'], 5, 'a fresh task of a deploy skew is tried again in a minute');
        }

        public function test_execute_park_does_not_overwrite_a_row_moved_meanwhile(): void
        {
            // the row is moved while this runner holds the claim (another runner's write) - giving the
            // priority back must match the claim's execute_after too, not only its priority
            $moved  = time() + 5000;
            $broken = $this->seed(self::objectOf('AA\Later\MovedMeanwhileTask'), 'test-broken', 5);
            $loader = function (string $class) use ($broken, $moved): void {
                if ($class === 'AA\Later\MovedMeanwhileTask') {
                    $this->pdo->prepare('UPDATE toexecute SET execute_after = ? WHERE id = ?')->execute([$moved, $broken]);
                }
            };
            spl_autoload_register($loader);
            try {
                (new Toexecute())->execute();
            } finally {
                spl_autoload_unregister($loader);
            }

            $this->assertSame(['execute_after' => $moved, 'priority' => 4], $this->queue()[$broken], 'the other write stands');
        }

        public function test_execute_report_escapes_what_a_task_returns(): void
        {
            $this->seed(new MarkupTask(), 'test-markup', 5);

            $toexecute = new Toexecute();
            $toexecute->execute();

            $report = json_encode($toexecute->reportRows());
            $this->assertStringNotContainsString('<img', $report, 'the Task Manager prints the report as HTML');
            $this->assertStringContainsString('&lt;img', $report);
        }

        public function test_execute_parked_task_is_not_overdue(): void
        {
            $broken = $this->seed(self::objectOf('AA\Later\NoSuchTaskClassX'), 'test-broken', 5, time() - 7200);

            (new Toexecute())->execute();

            $this->assertSame([], array_filter((new CheckQueue())->findings(), static fn($f) => $f->key === 'overdue'), 'tried every minute, it never waits an hour while the queue runs');
            $this->assertArrayHasKey($broken, $this->queue());
        }

        public function test_execute_runnable_task_behind_many_parked_rows_still_runs(): void
        {
            $parked = [];
            for ($i = 0; $i < 40; ++$i) {
                $parked[] = $this->seed(self::objectOf('AA\Later\NoSuchTaskClassX'), 'test-broken', 50);
            }
            $this->seed(new RecordingTask('behind'), 'test-behind', 1);

            $toexecute = new CountingToexecute(100);
            $toexecute->execute();

            $this->assertSame(['behind'], self::$ran);
            $this->assertSame(41, $toexecute->calls, 'each parked row costs one look, once');
            $this->assertSame([50], array_values(array_unique(array_column(array_intersect_key($this->queue(), array_flip($parked)), 'priority'))));
        }

        public function test_execute_self_serialized_task_of_missing_class_is_parked(): void
        {
            $class  = 'AA\Later\NoSuchSerialTask';
            $broken = $this->seed('C:' . strlen($class) . ':"' . $class . '":3:{abc}', 'test-broken', 5);

            self::quietly(static fn() => (new Toexecute())->execute());   // PHP warns: no unserializer

            $this->assertSame(5, $this->queue()[$broken]['priority']);
            $this->assertSame($class, $this->logRows(Toexecute::LOG_CLASS_MISSING)[0]['selector']);
        }

        public function test_execute_task_whose_class_comes_back_runs_with_its_own_params(): void
        {
            $late  = $this->seed('O:16:"LateArrivingTask":1:{s:4:"name";s:4:"late";}', 'test-late', 100, null, ['keep' => 1]);
            $plain = $this->seed(new ParamsTask('plain'), 'test-plain', 50, null, ['own' => 2]);

            (new Toexecute())->execute();
            $this->assertSame([['plain', ['own' => 2]]], ParamsTask::$ran);
            $this->assertSame(100, $this->queue()[$late]['priority']);

            class_alias(ParamsTask::class, 'LateArrivingTask');
            $this->due($late);
            (new Toexecute())->execute();

            $this->assertSame([['plain', ['own' => 2]], ['late', ['keep' => 1]]], ParamsTask::$ran);
            $this->assertSame([], $this->queue());
            $this->assertArrayNotHasKey($plain, $this->queue());
        }

        public function test_execute_batch_step_of_missing_run_class_is_parked_not_dropped(): void
        {
            // the batch core is deployed, the feature whose run it drives is not yet
            $step = $this->seed(new BatchStep('AA\Import\NoSuchRun', 'run1', 'step1'), 'test-step', 5);

            (new Toexecute())->execute();

            $this->assertArrayHasKey($step, $this->queue(), 'never deleted as "not a batch run"');
            $this->assertSame(5, $this->queue()[$step]['priority']);
            $this->assertSame('AA\Import\NoSuchRun', $this->logRows(Toexecute::LOG_CLASS_MISSING)[0]['selector'], 'the log names the class that is missing, not BatchStep');
        }

        public function test_execute_batch_step_of_a_class_that_is_no_batch_run_is_refused(): void
        {
            $step = $this->seed(new BatchStep(\stdClass::class, 'run1', 'step1'), 'test-step', 5);

            (new Toexecute())->execute();

            $this->assertArrayNotHasKey($step, $this->queue(), 'the class exists - the step refuses it and is done');
            $this->assertSame([], $this->logRows(Toexecute::LOG_CLASS_MISSING));
        }

        public function test_execute_task_of_removed_class_is_dropped(): void
        {
            $gone = $this->seed(self::objectOf('AA_Task_Import_Module_Chunk'), 'test-gone', 5);

            $toexecute = new Toexecute();
            $toexecute->execute();

            $this->assertArrayNotHasKey($gone, $this->queue(), 'a removed class can never run - its rows go');
            $this->assertSame([['selector' => 'AA_Task_Import_Module_Chunk', 'params' => 'test-gone']], $this->logRows(Toexecute::LOG_CLASS_GONE));
            $this->assertSame([], $this->logRows(Toexecute::LOG_CLASS_MISSING));
            $this->assertStringContainsString('no longer exists', json_encode($toexecute->reportRows()));
        }

        public function test_execute_task_whose_autoload_throws_fails_like_any_task(): void
        {
            $loader = static function (string $class): void {
                if ($class === 'AA\Later\ThrowingAutoloadTask') {
                    throw new \RuntimeException(ToexecuteTest::SECRET);
                }
            };
            $broken = $this->seed(self::objectOf('AA\Later\ThrowingAutoloadTask'), 'test-broken', 5);
            $this->seed(new RecordingTask('behind'), 'test-behind', 1);

            spl_autoload_register($loader);
            try {
                (new Toexecute())->execute();
            } finally {
                spl_autoload_unregister($loader);
            }

            $this->assertSame(['behind'], self::$ran, 'the round goes on');
            $this->assertSame(4, $this->queue()[$broken]['priority'], 'a failure, not a missing class - the claim stands');
            $this->assertGreaterThan(time() + Toexecute::CLAIM_LEASE - 60, $this->queue()[$broken]['execute_after']);
            $this->assertCount(1, $this->logRows(Toexecute::FAILED_EVENT));
        }

        public function test_execute_non_object_payload_is_removed(): void
        {
            $bad = $this->seed('a:1:{i:0;s:1:"x";}', 'test-bad', 5);

            $toexecute = new Toexecute();
            $toexecute->execute();

            $this->assertArrayNotHasKey($bad, $this->queue());
            $this->assertStringContainsString("unserialize err: #$bad ? (", json_encode($toexecute->reportRows()));
        }

        public function test_execute_payload_with_markup_for_a_class_name_is_removed_without_it(): void
        {
            $bad = $this->seed('O:22:"<img src=x onerror=1>":0:{}', 'test-bad', 5);

            $toexecute = new Toexecute();
            self::quietly(static fn() => $toexecute->execute());   // PHP warns: not a class name

            $this->assertArrayNotHasKey($bad, $this->queue(), 'not an object - the old unserialize-error path');
            $this->assertStringNotContainsString('<img', json_encode($toexecute->reportRows()), 'the Task Manager prints the report as HTML');
            $this->assertStringNotContainsString('<img', json_encode($this->logRows('TOEXECUTE')));
            $this->assertSame([], $this->logRows(Toexecute::LOG_CLASS_MISSING));
        }

        public function test_execute_task_is_claimed_before_it_runs(): void
        {
            ClaimSpyTask::$pdo  = $this->pdo;
            ClaimSpyTask::$seen = null;
            $id = $this->seed(new ClaimSpyTask(), 'test-spy', 5);
            ClaimSpyTask::$id = $id;

            (new Toexecute())->execute();

            $this->assertNotNull(ClaimSpyTask::$seen, 'the task ran');
            $this->assertSame(4, ClaimSpyTask::$seen['priority'], 'the claim lowers the priority');
            $this->assertGreaterThan(time() + Toexecute::CLAIM_LEASE - 60, ClaimSpyTask::$seen['execute_after'], 'and leases the row while it runs');
            $this->assertSame([], $this->queue(), 'the done task leaves the queue');
        }

        public function test_executeTask_user_task_of_missing_class_stays_parked(): void
        {
            $parked = $this->seed(self::objectOf('MissingSoonClass4'), 'test-parked', 100, Toexecute::TOEXECUTE_USER_TASK_TIME);

            $toexecute = new Toexecute();
            $toexecute->executeTask([$parked]);

            $this->assertSame([$parked => ['execute_after' => Toexecute::TOEXECUTE_USER_TASK_TIME, 'priority' => 100]], $this->queue(), 'the cron never runs a user task - not even a minute later');
            $this->assertSame([], $this->paramsOf($parked));
            $this->assertStringContainsString('MissingSoonClass4 is missing', json_encode($toexecute->reportRows()));
        }

        public function test_check_queue_reports_the_failures(): void
        {
            $failing = $this->seed(new ThrowingTask(\RuntimeException::class), 'test-failing', 100);
            (new Toexecute())->execute();

            $findings = array_values(array_filter((new CheckQueue())->findings(), static fn($f) => $f->key === 'failed'));

            $this->assertCount(1, $findings, 'a failing task no longer ends the run with a PHP fatal, so the State Check must show it');
            $this->assertSame(1, $findings[0]->count);
            $this->assertStringContainsString('test-failing', json_encode($findings[0]->evidence));
            $this->assertStringContainsString("#$failing RuntimeException", json_encode($findings[0]->evidence));
            $this->assertStringContainsString(intdiv(Toexecute::CLAIM_LEASE, 60) . ' minutes', $findings[0]->advice, 'the retry delay comes from the lease');
            $this->assertStringContainsString('Task Manager', $findings[0]->advice, 'a user-queue task is not retried on its own - the advice must say so');
        }

        public function test_timeLeft_is_null_outside_a_round(): void
        {
            $this->assertNull(Toexecute::timeLeft());
            $this->assertNull(Toexecute::roundSeconds());
        }

        public function test_timeLeft_inside_a_task_of_execute(): void
        {
            $this->requireDefaultRoundSize();
            $this->seed(new ClockTask(), 'test-clock', 100);

            (new Toexecute())->execute();

            $this->assertCount(1, ClockTask::$seen);
            [$left, $round] = ClockTask::$seen[0];
            $this->assertSame(16.0, $round, 'no TOEXECUTE_ALLOWED_TIME and no time limit - the default 16 s round');
            $this->assertGreaterThan(0.0, $left);
            $this->assertLessThanOrEqual(16.0, $left);
            $this->assertNull(Toexecute::timeLeft(), 'the round is closed after execute()');
        }

        public function test_timeLeft_inside_a_task_of_executeTask(): void
        {
            $this->requireDefaultRoundSize();
            $id = $this->seed(new ClockTask(), 'test-clock', 100, Toexecute::TOEXECUTE_USER_TASK_TIME);

            (new Toexecute())->executeTask([$id]);

            $this->assertCount(1, ClockTask::$seen);
            [$left, $round] = ClockTask::$seen[0];
            $this->assertSame(351.0, $round, 'executeTask() raises the time limit to 360 s before it sizes the round');
            $this->assertGreaterThan(0.0, $left);
            $this->assertLessThanOrEqual(351.0, $left);
            $this->assertNull(Toexecute::roundSeconds(), 'the round is closed after executeTask()');
        }

        public function test_timeLeft_failing_task_still_closes_the_round(): void
        {
            $this->seed(new ThrowingTask(\RuntimeException::class), 'test-failing', 100);

            (new Toexecute())->execute();

            $this->assertNull(Toexecute::timeLeft(), 'the round ends normally after a failed task');
            $this->assertNull(Toexecute::roundSeconds());
        }

        public function test_timeLeft_exception_out_of_the_round_closes_it(): void
        {
            // _exeOne() catches what a task throws, so only the loop itself can throw past the round
            ThrowingReportToexecute::$round = null;
            try {
                (new ThrowingReportToexecute())->executeTask([999]);
                $this->fail('the report exception must leave executeTask()');
            } catch (\LogicException) {
            }

            $this->assertNotNull(ThrowingReportToexecute::$round, 'the exception came from inside the round');
            $this->assertNull(Toexecute::timeLeft(), 'and the round did not stay open after it');
            $this->assertNull(Toexecute::roundSeconds());
        }

        public function test_cancel_all_removes_only_rows_of_the_selector(): void
        {
            $this->seed(new RecordingTask('a'), 'COUNT_LINKS', 100);
            $other = $this->seed(new RecordingTask('o'), 'Plan_1', 100);
            $this->seed(new RecordingTask('b'), 'COUNT_LINKS', 50, Toexecute::TOEXECUTE_USER_TASK_TIME);
            $longer = $this->seed(new RecordingTask('l'), 'COUNT_LINKS:now', 100);

            (new Toexecute())->cancel_all('COUNT_LINKS');

            $this->assertSame([$other, $longer], array_keys($this->queue()), 'every row of the selector, parked ones too, and only those');
        }

        public function test_scheduledTask_names_the_time_execute_after_also_when_nothing_is_queued(): void
        {
            $this->seed(new RecordingTask('x'), 'test-other', 100, 1234567890);

            $this->assertSame(['id' => false, 'execute_after' => false], Toexecute::scheduledTask('test-unscheduled'), 'callers read execute_after even for a selector not in the queue');
            $this->assertSame(1234567890, (int) Toexecute::scheduledTask('test-other')['execute_after']);
        }

        public function test_timeLeft_nested_round_gives_the_outer_one_back(): void
        {
            $this->requireDefaultRoundSize();
            $inner = $this->seed(new ClockTask(), 'test-clock', 100, Toexecute::TOEXECUTE_USER_TASK_TIME);
            $this->seed(new NestingTask($inner), 'test-nesting', 100);

            (new Toexecute())->execute();

            $this->assertCount(2, ClockTask::$seen, 'the nested task, then the outer one after it');
            $this->assertSame(351.0, ClockTask::$seen[0][1], 'the nested executeTask() has its own round');
            $this->assertSame(16.0, ClockTask::$seen[1][1], 'the outer task is back in its own round');
            $this->assertNull(Toexecute::timeLeft());
        }

        public function test_laterOnce_queues_the_task_under_one_lock(): void
        {
            $this->lockable();

            (new Toexecute())->laterOnce(new RecordingTask('once'), [], 'test-once', 100);

            $this->assertQueuedOnceUnderOneLock();
            // outside a transaction the check and the insert go on another handle: the one holding
            // the lock stays out of the pool until the lock is released
            $this->assertNotEmpty(SqliteDb::$others);
            $this->assertNotContains(SqliteDb::$locks[0][0], SqliteDb::$others, 'the lock connection runs nothing but the lock');
            $this->assertCount(2, $GLOBALS['spareDBs'], 'both handles are back in the pool');
        }

        public function test_laterOnce_inside_a_transaction_runs_on_its_one_handle(): void
        {
            // an item stored inside DB_AA::transaction() schedules a task from its event handler
            // (AA_Mysqlauth_Maintain)
            $this->lockable();
            $GLOBALS['spareDBs'] = [$this->handle()];

            DB_AA::transaction(static fn() => (new Toexecute())->laterOnce(new RecordingTask('once'), [], 'test-once', 100));

            $this->assertQueuedOnceUnderOneLock();
            $this->assertCount(1, $GLOBALS['spareDBs'], 'the handle is back in the pool');
        }

        public function test_laterOnce_releases_the_lock_when_queueing_fails(): void
        {
            $this->lockable();

            try {
                $this->assertLaterOnceThrows();
            } catch (\Exception) {
                // expected - only the lock and the handles matter here
            }

            $this->assertSame(['GET_LOCK', 'RELEASE_LOCK'], array_column(SqliteDb::$locks, 1));
            $this->assertCount(1, array_unique(array_column(SqliteDb::$locks, 0)));
            $this->assertCount(2, $GLOBALS['spareDBs'], 'both handles are back in the pool');
        }

        public function test_laterOnce_inside_a_transaction_releases_the_lock_when_queueing_fails(): void
        {
            $this->lockable();
            $GLOBALS['spareDBs'] = [$this->handle()];

            try {
                DB_AA::transaction(fn() => $this->assertLaterOnceThrows());
            } catch (\Throwable) {
                // the transaction passes the failure on - only the lock and the handle matter here
            }

            $this->assertSame(['GET_LOCK', 'RELEASE_LOCK'], array_column(SqliteDb::$locks, 1));
            $this->assertCount(1, $GLOBALS['spareDBs'], 'the handle is back in the pool, once');
        }

        /** an anonymous class cannot be serialized, so later() throws after the lock is taken */
        private function assertLaterOnceThrows(): void
        {
            try {
                (new Toexecute())->laterOnce(new class {}, [], 'test-once', 100);
            } catch (\Exception $e) {
                $this->assertStringContainsString('Serialization', $e->getMessage());
                throw $e;
            }
            $this->fail('laterOnce() was expected to throw');
        }

        /** MySQL's named lock, which SQLite does not have */
        private function lockable(): void
        {
            $this->pdo->sqliteCreateFunction('GET_LOCK', static fn(string $name, int $timeout): int => 1, 2);
            $this->pdo->sqliteCreateFunction('RELEASE_LOCK', static fn(string $name): int => 1, 1);
        }

        private function assertQueuedOnceUnderOneLock(): void
        {
            $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM toexecute WHERE selector = 'test-once'")->fetchColumn());
            $this->assertSame(['GET_LOCK', 'RELEASE_LOCK'], array_column(SqliteDb::$locks, 1));
            $this->assertCount(1, array_unique(array_column(SqliteDb::$locks, 0)), 'a named lock belongs to the connection that took it');
        }
    }

    /** records the queue round it runs in */
    final class ClockTask
    {
        /** @var list<array{?float, ?float}> [timeLeft(), roundSeconds()] per run */
        public static array $seen = [];

        public function toexecutelater(array $params): string
        {
            self::$seen[] = [Toexecute::timeLeft(), Toexecute::roundSeconds()];
            return 'ok';
        }
    }

    /** fails while reporting, i.e. in the round's own loop rather than in a task */
    final class ThrowingReportToexecute extends Toexecute
    {
        /** roundSeconds() at the moment of the throw */
        public static ?float $round = null;

        protected function message($row, int $verbosity = 1): void
        {
            self::$round = Toexecute::roundSeconds();
            throw new \LogicException('report failed');
        }
    }

    /** runs another queued task by hand from inside the round, then records the round it is back in */
    final class NestingTask
    {
        public function __construct(public int $inner) {}

        public function toexecutelater(array $params): string
        {
            (new Toexecute())->executeTask([$this->inner]);
            ClockTask::$seen[] = [Toexecute::timeLeft(), Toexecute::roundSeconds()];
            return 'ok';
        }
    }

    /** DB_AA writes strings with addslashes() escapes, which SQLite keeps as they are - a serialized
     *  value written back to the queue would not unserialize */
    final class SqliteDb extends DB_PDO
    {
        /** @var list<array{int, string}> [handle, GET_LOCK|RELEASE_LOCK] in the order they ran */
        public static array $locks = [];

        /** @var list<int> handle of every other statement, in the order they ran */
        public static array $others = [];

        public function query($Query_String): bool
        {
            if (preg_match('/\b(GET_LOCK|RELEASE_LOCK)\(/', (string) $Query_String, $m)) {
                self::$locks[] = [spl_object_id($this), $m[1]];
            } else {
                self::$others[] = spl_object_id($this);
            }
            return parent::query(preg_replace_callback("/'((?:[^'\\\\]|\\\\.)*)'/s", static function (array $m): string {
                $text = preg_replace_callback('/\\\\(.)/s', static fn(array $e): string => ($e[1] === '0') ? "\0" : $e[1], $m[1]);
                // SQLite ends a statement at a NUL byte - such a literal goes as hex
                return str_contains($text, "\0") ? "X'" . bin2hex($text) . "'" : "'" . str_replace("'", "''", $text) . "'";
            }, (string) $Query_String));
        }
    }

    /** counts the rows a round hands to _exeOne() - a round spinning on one row fails instead of hanging */
    final class CountingToexecute extends Toexecute
    {
        public int $calls = 0;

        public function __construct(private int $cap = 10) {}

        public function _exeOne($task, &$execute_times, $execute_start, $allowed_time): int
        {
            if (++$this->calls > $this->cap) {
                throw new \LogicException('the round keeps picking the same rows');
            }
            return parent::_exeOne($task, $execute_times, $execute_start, $allowed_time);
        }
    }

    /** records its own queue row as it is while the task runs */
    final class ClaimSpyTask
    {
        public static ?PDO $pdo = null;
        public static int $id = 0;
        /** @var array{priority:int, execute_after:int}|null */
        public static ?array $seen = null;

        public function toexecutelater(array $params): string
        {
            $stmt = self::$pdo->prepare('SELECT priority, execute_after FROM toexecute WHERE id = ?');
            $stmt->execute([self::$id]);
            self::$seen = array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC));
            return 'ok';
        }
    }

    /** returns markup, as a task may echo data it processed */
    final class MarkupTask
    {
        public function toexecutelater(array $params): string
        {
            return '<img src=x onerror=alert(1)>';
        }
    }

    /** records its name and the params it got */
    final class ParamsTask
    {
        /** @var list<array{string, array<mixed>}> */
        public static array $ran = [];

        public function __construct(public string $name = '') {}

        public function toexecutelater(array $params): string
        {
            self::$ran[] = [$this->name, $params];
            return 'ok';
        }
    }

    final class RecordingTask
    {
        public function __construct(public string $name = '') {}

        public function toexecutelater(array $params): string
        {
            ToexecuteTest::$ran[] = $this->name;
            return 'ok';
        }
    }

    final class ThrowingTask
    {
        /** the line of the throw below - the log row must point at it */
        public static int $line = 0;

        /** @param class-string<\Throwable> $throwable */
        public function __construct(public string $throwable = \RuntimeException::class) {}

        public function toexecutelater(array $params): string
        {
            self::$line = __LINE__ + 1;
            throw new ($this->throwable)(ToexecuteTest::SECRET);
        }
    }

    /** thrown from a file deep enough that "#id Class file:line" overflows log.params */
    final class LongPathException extends \RuntimeException
    {
        public function __construct(string $dir)
        {
            parent::__construct(ToexecuteTest::SECRET);
            $this->file = constant('AA_BASE_PATH') . $dir . 'Task.php';
            $this->line = 4242;
        }
    }
}
