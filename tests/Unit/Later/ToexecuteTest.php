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

    use AA\IO\DB\DB_PDO;
    use AA\Later\Toexecute;
    use AA\Optimize\CheckQueue;
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
            $db = new DB_PDO(['type' => 'db_pdo:sqlite', 'database' => ':memory:']);
            (new ReflectionProperty(DB_PDO::class, 'dbh'))->setValue($db, $this->pdo);
            return $db;
        }

        /** @param object|string $task  the task, or its already serialized form */
        private function seed(object|string $task, string $selector, int $priority, ?int $execute_after = null): int
        {
            $this->pdo->prepare('INSERT INTO toexecute (execute_after, priority, selector, object, params) VALUES (?, ?, ?, ?, ?)')
                ->execute([$execute_after ?? time() - 60, $priority, $selector, is_string($task) ? $task : serialize($task), serialize([])]);
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
