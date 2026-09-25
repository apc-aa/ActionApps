<?php

declare(strict_types=1);

namespace Tests\Unit\IO\DB;

use AA\IO\DB\DB_AA;
use AA\IO\DB\DB_PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for DB_AA::transaction() - the first transaction support in AA.
 *
 * The design rests on one guarantee: everything inside the closure runs on the ONE
 * connection that owns the transaction. That matters because AA can be configured
 * either way (AA_USE_NON_PERSISTENT_CONNECT) - with persistent connections all
 * DB_PDO objects share one physical connection, without them every object is its
 * own connection, and a statement on a second connection would silently fall
 * outside the transaction. getDB() therefore hands out the transaction's handle
 * and refuses to hand out a second one.
 *
 * The bootstrap never loads include/locsess.php (it would start a session and a DB
 * connection), so getDB()/freeDB() come from a stub mirroring it, which steps aside
 * if that file ever is loaded. Both go through the same $spareDBs pool, prefilled
 * here with a recording fake handle.
 */
final class DbAaTransactionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/fixtures/locsess_pool_stub.php';
    }

    protected function setUp(): void
    {
        FakeDb::$log = [];
        FakeDb::$begin_fails = false;
        FakeDb::$commit_fails = false;
        FakeDb::$rollback_throws = false;
        FakeDb::$query_fails = false;
        $GLOBALS['spareDBs'] = [new FakeDb()];
    }

    protected function tearDown(): void
    {
        $GLOBALS['spareDBs'] = [];
    }

    public function test_commits_and_returns_the_closure_value(): void
    {
        $ret = DB_AA::transaction(static function () {
            $db = getDB();
            $db->query('DELETE FROM x');
            freeDB($db);
            return 'result';
        });

        $this->assertSame('result', $ret);
        $this->assertSame(['begin', 'DELETE FROM x', 'commit'], FakeDb::$log);
    }

    public function test_every_statement_runs_on_the_same_handle(): void
    {
        $handles = [];
        DB_AA::transaction(static function () use (&$handles) {
            foreach (['DELETE FROM a', 'INSERT INTO a', 'REPLACE INTO b'] as $sql) {
                $db        = getDB();
                $handles[] = spl_object_id($db);
                $db->query($sql);
                freeDB($db);
            }
        });

        $this->assertCount(3, $handles, 'sanity: three handles were taken in sequence');
        $this->assertCount(1, array_unique($handles), 'all three ran on the same handle');
        $this->assertSame(
            ['begin', 'DELETE FROM a', 'INSERT INTO a', 'REPLACE INTO b', 'commit'],
            FakeDb::$log,
        );
    }

    public function test_the_handle_is_the_one_the_transaction_was_started_on(): void
    {
        $started_on = $GLOBALS['spareDBs'][0];
        $inside     = null;
        DB_AA::transaction(static function () use (&$inside) {
            $db     = getDB();
            $inside = $db;      // not freeDB($inside) - freeDB() nulls the variable it gets
            freeDB($db);
        });
        $this->assertSame($started_on, $inside);
    }

    public function test_rolls_back_and_rethrows_on_exception(): void
    {
        try {
            DB_AA::transaction(static function () {
                $db = getDB();
                $db->query('DELETE FROM x');
                freeDB($db);
                throw new RuntimeException('boom');
            });
            $this->fail('exception was swallowed');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(['begin', 'DELETE FROM x', 'rollback'], FakeDb::$log);
    }

    public function test_second_handle_inside_a_transaction_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/second database handle/i');

        DB_AA::transaction(static function () {
            getDB();     // fine - this is the transaction's handle
            getDB();     // a second live handle would be a second connection
        });
    }

    public function test_nested_transaction_joins_the_outer_one(): void
    {
        DB_AA::transaction(static function () {
            $db = getDB();
            $db->query('OUTER');
            freeDB($db);

            DB_AA::transaction(static function () {
                $db = getDB();
                $db->query('INNER');
                freeDB($db);
            });
        });

        // one begin, one commit - PDO cannot nest, so the inner call just joins
        $this->assertSame(['begin', 'OUTER', 'INNER', 'commit'], FakeDb::$log);
    }

    public function test_failure_inside_a_nested_call_rolls_the_whole_block_back(): void
    {
        try {
            DB_AA::transaction(static function () {
                $db = getDB();
                $db->query('OUTER');
                freeDB($db);
                DB_AA::transaction(static fn() => throw new RuntimeException('inner failed'));
            });
            $this->fail('exception was swallowed');
        } catch (RuntimeException $e) {
            $this->assertSame('inner failed', $e->getMessage());
        }

        $this->assertSame(['begin', 'OUTER', 'rollback'], FakeDb::$log);
    }

    public function test_handle_goes_back_to_the_pool_afterwards(): void
    {
        DB_AA::transaction(static function () {
            $db = getDB();
            $db->query('X');
            freeDB($db);
        });

        $this->assertCount(1, $GLOBALS['spareDBs'], 'the pinned handle was released back');

        // the pin is gone, so plain use works again
        $db = getDB();
        $db->query('AFTER');
        freeDB($db);
        $this->assertCount(1, $GLOBALS['spareDBs']);
        $this->assertSame('AFTER', end(FakeDb::$log));
    }

    public function test_handle_is_released_even_when_the_closure_throws(): void
    {
        try {
            DB_AA::transaction(static fn() => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
            // expected
        }
        $this->assertCount(1, $GLOBALS['spareDBs'], 'the pinned handle was released back');

        $db = getDB();   // must not throw - the pin is gone
        freeDB($db);
        $this->assertCount(1, $GLOBALS['spareDBs']);
    }

    public function test_failed_commit_is_reported_not_swallowed(): void
    {
        // PDO is in silent error mode here, so commit() only returns false - the writes
        // are gone and the caller must not be told the block succeeded
        FakeDb::$commit_fails = true;

        try {
            DB_AA::transaction(static function () {
                $db = getDB();
                $db->query('INSERT INTO x');
                freeDB($db);
                return 'looks fine';
            });
            $this->fail('a failed commit was reported as success');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('commit failed', $e->getMessage());
        }

        $this->assertCount(1, $GLOBALS['spareDBs'], 'the handle is still released');
    }

    public function test_a_failing_rollback_does_not_mask_the_original_error(): void
    {
        // an implicit commit inside the block (DDL) leaves nothing to roll back, and PDO
        // raises for that regardless of error mode - the real cause must still surface
        FakeDb::$rollback_throws = true;

        try {
            DB_AA::transaction(static fn() => throw new RuntimeException('the real cause'));
            $this->fail('exception was swallowed');
        } catch (RuntimeException $e) {
            $this->assertSame('the real cause', $e->getMessage());
        }

        $this->assertCount(1, $GLOBALS['spareDBs'], 'the handle is still released');
    }

    public function test_a_shutdown_handler_can_abandon_a_transaction_the_request_died_in(): void
    {
        $spare   = new FakeDb();
        $handler = DB_AA::atShutdown(static function (): string {
            return DB_AA::abandonTransaction() ? 'done' : 'unreliable';     // nothing open at the end of the test run: true
        });
        DB_AA::transaction(function () use ($spare, $handler) {
            $this->assertTrue(DB_AA::inTransaction());
            $db = getDB();                      // checked out, as when a fatal error strikes mid-statement
            $db->query('INSERT INTO x');
            $GLOBALS['spareDBs'][] = $spare;

            $this->assertSame('done', $handler(), 'the registered function returns what the handler does');
            $this->assertFalse(DB_AA::inTransaction(), 'unpinned');
            $other = getDB();
            $this->assertSame($spare, $other, 'getDB() serves the pool again, no "second handle" refusal');
            freeDB($other);
        });

        $this->assertSame(['begin', 'INSERT INTO x', 'rollback', 'commit'], FakeDb::$log, 'rolled back (the later commit is the test closure returning)');
        $this->assertFalse(DB_AA::inTransaction());
    }

    /** @return array<string, array{bool, bool, list<string>, bool}> persistent, ROLLBACK statement fails, log, reliable */
    public static function failedRollbacks(): array
    {
        return [
            'explicit ROLLBACK'             => [false, false, ['begin', 'rollback', 'ROLLBACK', 'disconnect'], true],
            'connection closed'             => [false, true, ['begin', 'rollback', 'ROLLBACK', 'disconnect'], true],
            'persistent, explicit ROLLBACK' => [true, false, ['begin', 'rollback', 'ROLLBACK', 'disconnect'], true],
            'persistent, nothing works'     => [true, true, ['begin', 'rollback', 'ROLLBACK', 'disconnect'], false],
        ];
    }

    /** a failed rollback(): a ROLLBACK statement, then the connection dropped - reliable unless a persistent
     *  connection (it stays open, its next use would join the transaction) refused both */
    #[\PHPUnit\Framework\Attributes\DataProvider('failedRollbacks')]
    public function test_a_failed_rollback(bool $persistent, bool $statement_fails, array $log, bool $reliable): void
    {
        $GLOBALS['spareDBs']     = [new FakeDb($persistent)];
        FakeDb::$rollback_throws = true;
        FakeDb::$query_fails     = $statement_fails;
        $handler                 = DB_AA::atShutdown(static fn(): bool => DB_AA::abandonTransaction());
        $result                  = null;
        try {
            DB_AA::transaction(static function () use ($handler, &$result) {
                $result = $handler();
                throw new RuntimeException('the request goes on in the test');
            });
        } catch (RuntimeException) {
        }
        FakeDb::$query_fails = false;
        $this->assertSame([...$log, 'rollback'], FakeDb::$log, '(the last rollback: the test request going on)');
        $this->assertSame($reliable, $result);
    }

    public function test_abandon_is_only_for_a_shutdown_handler(): void
    {
        $this->expectException(\LogicException::class);
        DB_AA::abandonTransaction();
    }

    public function test_runs_without_a_transaction_when_it_cannot_be_started(): void
    {
        FakeDb::$begin_fails = true;

        $ret = DB_AA::transaction(static function () {
            $db = getDB();
            $db->query('DELETE FROM x');
            freeDB($db);
            return 42;
        });

        $this->assertSame(42, $ret, 'the work still runs');
        $this->assertSame(['DELETE FROM x'], FakeDb::$log, 'no begin/commit, and no exception');
    }
}

/** Stands in for a DB_PDO handle; records what was asked of it. Never connects. */
final class FakeDb extends DB_PDO
{
    public static array $log = [];
    public static bool $begin_fails = false;
    public static bool $commit_fails = false;
    public static bool $rollback_throws = false;
    public static bool $query_fails = false;

    public function __construct(bool $persistent = false)
    {
        parent::__construct(['type' => 'db_pdo:sqlite', 'database' => ':memory:', 'persistent' => $persistent]);
    }

    public function beginTransaction(): bool
    {
        if (self::$begin_fails) {
            return false;
        }
        self::$log[] = 'begin';
        return true;
    }

    public function commit(): bool
    {
        self::$log[] = 'commit';
        return !self::$commit_fails;
    }

    public function rollback(): bool
    {
        self::$log[] = 'rollback';
        if (self::$rollback_throws) {
            throw new RuntimeException('There is no active transaction');
        }
        return true;
    }

    public function query($Query_String): bool
    {
        self::$log[] = $Query_String;
        return !self::$query_fails;
    }

    public function disconnect(): void
    {
        self::$log[] = 'disconnect';
    }
}
