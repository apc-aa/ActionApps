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

        /** a DB_PDO handle on the test's SQLite database (injected, so it never connects) */
        private function handle(): DB_PDO
        {
            $db = new DB_PDO(['type' => 'db_pdo:sqlite', 'database' => ':memory:']);
            (new ReflectionProperty(DB_PDO::class, 'dbh'))->setValue($db, $this->pdo);
            return $db;
        }

        private function seed(object $task, string $selector, int $priority, ?int $execute_after = null): int
        {
            $this->pdo->prepare('INSERT INTO toexecute (execute_after, priority, selector, object, params) VALUES (?, ?, ?, ?, ?)')
                ->execute([$execute_after ?? time() - 60, $priority, $selector, serialize($task), serialize([])]);
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
            $this->assertSame([['selector' => 'test-failing', 'params' => "#$failing $throwable"]], $failed, 'task, id and error class - nothing else');
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

        public function test_check_queue_reports_the_failures(): void
        {
            $failing = $this->seed(new ThrowingTask(\RuntimeException::class), 'test-failing', 100);
            (new Toexecute())->execute();

            $findings = array_values(array_filter((new CheckQueue())->findings(), static fn($f) => $f->key === 'failed'));

            $this->assertCount(1, $findings, 'a failing task no longer ends the run with a PHP fatal, so the State Check must show it');
            $this->assertSame(1, $findings[0]->count);
            $this->assertStringContainsString('test-failing', json_encode($findings[0]->evidence));
            $this->assertStringContainsString("#$failing RuntimeException", json_encode($findings[0]->evidence));
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
        /** @param class-string<\Throwable> $throwable */
        public function __construct(public string $throwable = \RuntimeException::class) {}

        public function toexecutelater(array $params): string
        {
            throw new ($this->throwable)(ToexecuteTest::SECRET);
        }
    }
}
