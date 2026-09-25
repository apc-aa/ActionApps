<?php

declare(strict_types=1);

/**
 * What guards the import writer, C4.4 part 4b (design balicek-v1.md § 7.2 steps 5-6): the import lock
 * (GET_LOCK on a connection of its own; held by another, or not asked for at all), write() refused inside
 * a transaction, the cleanup kept alive (user abort, time limit), the shutdown handler of a request that
 * dies inside the write and its log line, the engine question of DbImportTarget.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\Package\DbImportTarget;
use AA\Central\Package\Importer;
use AA\Central\Package\WriteError;
use AA\IO\DB\DB_AA;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Unit\Central\Package\Fixtures\CompensationFixture;
use Tests\Unit\Central\Package\Fixtures\TableDb;

require_once __DIR__ . '/fixtures/CompensationFixture.php';

final class ImportGuardTest extends TestCase
{
    use CompensationFixture;

    // ---------------------------------------------------------------- the lock

    public function test_no_lock_no_statement(): void
    {
        $this->target->lockFree = false;
        $plan                   = $this->plan();

        $e = $this->failure($plan);

        self::assertInstanceOf(WriteError::class, $e->getPrevious());
        self::assertSame([WriteError::LOCKED, 'import'], [$e->getPrevious()->reason, $e->getPrevious()->path]);
        self::assertStringContainsString('another import holds the lock ' . self::LOCK, $e->getMessage());
        self::assertSame([], TableDb::$log, 'nothing written, nothing read');
        self::assertTrue($e->journal->isEmpty());
        self::assertSame(['lock ' . self::LOCK . ' 3'], $this->target->locks, 'never released - it was not ours');
    }

    public function test_the_lock_is_held_around_the_write_and_the_compensation(): void
    {
        TableDb::$transactions = false;
        $plan                  = $this->plan();
        $at                    = [];
        $this->target->onLock   = static function () use (&$at): void {
            $at['lock'] = count(TableDb::$log);
        };
        $this->target->onUnlock = static function () use (&$at): void {
            $at['unlock'] = count(TableDb::$log);
        };
        self::failAt(self::LAST);

        $this->failure($plan);

        self::assertSame(0, $at['lock'], 'before any statement');
        self::assertSame(count(TableDb::$log), $at['unlock'], 'after the last statement of the compensation');
        self::assertNull($this->target->holds());

        TableDb::$fail = null;
        $importer      = new Importer($this->target, 'u', null, 1, 'my_lock', 7);
        $importer->write($this->plan());
        self::assertSame(['lock my_lock 7', 'unlock my_lock'], array_slice($this->target->locks, -2), 'released after a success too');
    }

    public function test_the_db_target_holds_the_lock_on_a_connection_of_its_own(): void
    {
        $opened = [];
        $open   = static function () use (&$opened): TableDb {
            return $opened[] = new TableDb();
        };
        [$one, $other] = [new DbImportTarget($open), new DbImportTarget($open)];

        self::assertTrue($one->lock("it's", 2));
        self::assertFalse($other->lock("it's", 0), 'another session holds it');
        $one->unlock("it's");
        $one->unlock("it's");                    // not held: nothing

        self::assertSame(["SELECT GET_LOCK('it\\'s', 2) AS result", "SELECT GET_LOCK('it\\'s', 0) AS result", "SELECT RELEASE_LOCK('it\\'s') AS result"], TableDb::$log);
        self::assertSame([spl_object_id($opened[0]), spl_object_id($opened[1]), spl_object_id($opened[0])], TableDb::$handles, 'released on the connection that holds it');
        self::assertCount(4, $GLOBALS['spareDBs'], 'no pooled handle used');
        self::assertSame([], TableDb::$locks);
        self::assertTrue($other->lock("it's", 0));
        $other->unlock("it's");
    }

    public function test_a_lock_that_cannot_be_asked_for_is_its_own_error(): void
    {
        $this->target->lockError = new RuntimeException('no connection to the database for the import lock');

        $e = $this->failure($this->plan());

        self::assertSame([WriteError::DB, 'import'], [$e->getPrevious()->reason, $e->getPrevious()->path]);
        self::assertStringContainsString('the import lock could not be asked for: no connection', $e->getMessage());
        self::assertSame([], TableDb::$log);
    }

    public function test_the_db_target_reports_a_failed_connection_and_a_null_lock(): void
    {
        $target        = new DbImportTarget(static fn(): TableDb => new TableDb());
        TableDb::$fail = static fn(string $sql): bool => str_contains($sql, 'GET_LOCK');
        try {
            $target->lock('x', 1);
            self::fail('no connection passed');
        } catch (RuntimeException $e) {
            self::assertSame('no connection to the database for the import lock', $e->getMessage());
        }
        TableDb::$fail   = null;
        TableDb::$answer = static fn(string $sql): array => [['result' => null]];        // GET_LOCK() NULL: an error of the server
        $this->expectExceptionMessage('GET_LOCK() of the import lock failed (NULL)');
        $target->lock('x', 1);
    }

    // ---------------------------------------------------------------- write() in a transaction, the time limit

    public function test_write_refuses_to_join_a_transaction(): void
    {
        $plan = $this->plan();
        try {
            DB_AA::transaction(function () use ($plan): void {
                $this->importer()->write($plan);
            });
            self::fail('written inside a transaction');
        } catch (LogicException $e) {
            self::assertStringContainsString('inside DB_AA::transaction()', $e->getMessage());
        }
        self::assertSame([], $this->target->locks, 'before the lock');
        self::assertSame(['BEGIN', 'ROLLBACK'], TableDb::$log);
    }

    public function test_the_cleanup_ignores_the_user_abort_and_raises_the_time_limit(): void
    {
        $abort = ignore_user_abort();
        TableDb::$transactions = false;
        $plan  = $this->plan();
        self::failAt(self::LAST);
        try {
            ignore_user_abort(false);
            set_time_limit(20);
            $this->failure($plan);
            self::assertSame(['1', '60'], [ini_get('ignore_user_abort'), ini_get('max_execution_time')]);
            set_time_limit(90);
            $this->failure($this->plan());
            self::assertSame('90', ini_get('max_execution_time'), 'only raised');
        } finally {
            set_time_limit(0);
            ignore_user_abort((bool) $abort);
        }
        $this->failure($this->plan());
        self::assertSame('0', ini_get('max_execution_time'), 'no limit (CLI) stays none');
    }

    // ---------------------------------------------------------------- a request dying inside the write

    /** @return array<string, array{bool}> */
    public static function engines(): array
    {
        return ['transaction' => [true], 'MyISAM' => [false]];
    }

    #[DataProvider('engines')]
    public function test_the_shutdown_handler_compensates_a_request_that_dies_inside_the_write(bool $transactions): void
    {
        TableDb::$transactions = $transactions;
        $foreign               = self::foreign();
        $plan                  = $this->plan();
        $importer              = $this->importer();
        $died                  = null;
        $log                   = (string) tempnam(sys_get_temp_dir(), 'aa-import-log');
        $was                   = ini_set('error_log', $log);
        TableDb::$fail         = function (string $sql) use ($importer, &$died): bool {
            if (($died !== null) || !preg_match(self::LAST, $sql)) {
                return false;
            }
            // a fatal error here - its message is package text; PHP runs the shutdown handler with the
            // transaction open and its handle checked out
            @trigger_error("forged\nAA Central: all is well", E_USER_WARNING);
            $report = ($importer->shutdownHandler())();
            $died   = [$report, array_filter(TableDb::$tables), $this->target->holds(), TableDb::$halted, DB_AA::inTransaction()];
            return true;                        // (the dead request would stop here; the test lets it end)
        };
        try {
            $this->failure($plan, $importer);
            $lines = file($log, FILE_IGNORE_NEW_LINES);
        } finally {
            ini_set('error_log', (string) $was);
            @unlink($log);
        }

        [$report, $tables, $held, $halted, $open] = $died;
        self::assertNotNull($report);
        self::assertTrue($report->clean(), $report->summary());
        self::assertSame($foreign, $tables, 'compensated in the handler');
        self::assertNull($held, 'the lock released');
        self::assertSame([], $halted);
        self::assertFalse($open, 'the transaction abandoned');
        self::assertSame($foreign, array_filter(TableDb::$tables));
        self::assertNull(($importer->shutdownHandler())(), 'once');
        self::assertCount(1, $lines, 'one line - the package text cannot forge another');
        self::assertStringContainsString('AA Central: the import died at slice-module[' . self::SLICE . ']/object[' . self::SET . ']@aa_subobjects[1] (forged AA Central: all is well) - compensated', $lines[0]);
    }

    /** a persistent connection whose rollback fails keeps its transaction open - the compensation's deletes
     *  may join it and be lost: the report never claims clean */
    public function test_a_failed_rollback_on_a_persistent_connection_makes_the_cleanup_unreliable(): void
    {
        $GLOBALS['spareDBs'] = [new TableDb(true), new TableDb(true), new TableDb(true), new TableDb(true)];
        $plan                = $this->plan();
        $importer            = $this->importer();
        $report              = null;
        TableDb::$fail       = static function (string $sql) use ($importer, &$report): bool {
            if (($report !== null) || !preg_match(self::LAST, $sql)) {
                return false;
            }
            TableDb::$rollbackFails = true;
            $was    = ini_set('error_log', '/dev/null');
            $report = ($importer->shutdownHandler())();
            ini_set('error_log', (string) $was);
            return true;
        };

        $this->failure($plan, $importer);

        self::assertFalse($report->clean());
        self::assertSame([['transaction', ['cleanup not reliable: the transaction could not be rolled back - check manually']]], $report->failures());
        self::assertContains('ROLLBACK', TableDb::$log, 'an explicit ROLLBACK was tried');
    }

    public function test_the_shutdown_handler_does_nothing_after_the_write(): void
    {
        $importer = $this->importer();
        self::assertNull($importer->shutdownHandler(), 'registered by the first write');
        $importer->write($this->plan());
        $log = TableDb::$log;

        self::assertNull(($importer->shutdownHandler())());
        self::assertSame($log, TableDb::$log);
        self::assertSame('COMMIT', end(TableDb::$log));
    }

    public function test_the_shutdown_handler_logs_what_it_cannot_do_and_throws_nothing(): void
    {
        TableDb::$transactions = false;
        $importer              = $this->importer();
        $this->target->onUnlock = static fn() => throw new RuntimeException("unlock\nfailed");
        $log                   = (string) tempnam(sys_get_temp_dir(), 'aa-import-log');
        $was                   = ini_set('error_log', $log);
        $result                = 'not run';
        TableDb::$fail         = static function (string $sql) use ($importer, &$result): bool {
            if (($result === 'not run') && preg_match(self::LAST, $sql)) {
                $result = ($importer->shutdownHandler())();
            }
            return (bool) preg_match(self::LAST, $sql);
        };
        try {
            try {
                $importer->write($this->plan());
            } catch (\Throwable) {
            }
            $lines = file($log, FILE_IGNORE_NEW_LINES);
        } finally {
            ini_set('error_log', (string) $was);
            @unlink($log);
        }

        self::assertNull($result);
        self::assertCount(1, $lines);
        self::assertStringContainsString('AA Central: the cleanup of a dead import failed: RuntimeException: unlock failed', $lines[0]);
    }

    // ---------------------------------------------------------------- the engine check

    public function test_the_db_target_names_the_tables_without_transactions(): void
    {
        TableDb::$engines = ['view' => 'InnoDB', 'email' => 'MyISAM', 'module' => 'Aria', 'field' => 'innodb'];

        self::assertSame(['email' => 'MyISAM', 'module' => 'Aria', 'slice' => ''], (new DbImportTarget())->nonTransactional(['view', 'email', 'module', 'slice', 'field']));
        self::assertSame(["SELECT TABLE_NAME, ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA = 'aa_test' AND TABLE_NAME IN ('view','email','module','slice','field')"], TableDb::$log);
        self::assertSame([], (new DbImportTarget())->nonTransactional([]));
    }
}
