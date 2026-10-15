<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\IO\DB\DB_AA;
use AA\IO\DB\DB_PDO;
use AA\Optimize\AbstractOptimize;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/** Handle that never connects - logs the statements and the transaction calls in one list */
final class TransactionLogDb extends DB_PDO
{
    /** @var string[] */
    public array $log = [];
    /** a statement starting with this fails the way DB_PDO fails with halting on 'report' */
    public ?string $fail_on = null;
    public bool $commit_ok  = true;
    public bool $begin_ok   = true;

    public function __construct()
    {
        parent::__construct(['type' => 'db_pdo:sqlite', 'database' => ':memory:']);
    }

    public function query($Query_String): bool
    {
        $this->log[] = $Query_String;
        return $this->fail_on === null || !str_starts_with($Query_String, $this->fail_on);
    }

    public function beginTransaction(): bool
    {
        $this->log[] = 'BEGIN';
        return $this->begin_ok;
    }

    public function commit(): bool
    {
        $this->log[] = 'COMMIT';
        return $this->commit_ok;
    }

    public function rollback(): bool
    {
        $this->log[] = 'ROLLBACK';
        return true;
    }
}

/** A seed rewrite as the Redefine* classes do it: DELETE, then the INSERTs, in one transaction */
final class TransactionOptimize extends AbstractOptimize
{
    public function __construct(DB_PDO $db)
    {
        $this->db = $db;
    }

    public function seed(bool $throw = false): string
    {
        return $this->transaction(function () use ($throw) {
            $this->query("DELETE FROM constant WHERE group_id='lt_test'");
            $this->query("INSERT INTO constant (id, group_id) VALUES ('AA-test000', 'lt_test')");
            if ($throw) {
                throw new LogicException('not a database error');
            }
            $this->query("INSERT INTO constant (id, group_id) VALUES ('AA-test001', 'lt_test')");
            return 'done';
        });
    }
}

final class AbstractOptimizeTransactionTest extends TestCase
{
    private const DELETE  = "DELETE FROM constant WHERE group_id='lt_test'";
    private const INSERT0 = "INSERT INTO constant (id, group_id) VALUES ('AA-test000', 'lt_test')";
    private const INSERT1 = "INSERT INTO constant (id, group_id) VALUES ('AA-test001', 'lt_test')";

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // safe() for the report of query()
    }

    protected function tearDown(): void
    {
        AbstractOptimize::justPrint(false);
    }

    public function test_transaction_commits_the_block_on_its_own_handle(): void
    {
        $db = new TransactionLogDb();

        $this->assertSame('done', (new TransactionOptimize($db))->seed());
        $this->assertSame(['BEGIN', self::DELETE, self::INSERT0, self::INSERT1, 'COMMIT'], $db->log);
    }

    public function test_transaction_rolls_back_and_rethrows_on_a_failing_statement(): void
    {
        $db          = new TransactionLogDb();
        $db->fail_on = self::INSERT0;

        try {
            (new TransactionOptimize($db))->seed();
            $this->fail('the failed statement must reach the caller');
        } catch (RuntimeException $e) {
            $this->assertSame('db err', $e->getMessage());
        }
        $this->assertSame(['BEGIN', self::DELETE, self::INSERT0, 'ROLLBACK'], $db->log, 'nothing after the failure, no commit');
    }

    public function test_transaction_throws_when_the_commit_fails(): void
    {
        $db            = new TransactionLogDb();
        $db->commit_ok = false;

        $this->expectExceptionMessage('commit failed');
        try {
            (new TransactionOptimize($db))->seed();
        } finally {
            $this->assertSame(['BEGIN', self::DELETE, self::INSERT0, self::INSERT1, 'COMMIT', 'ROLLBACK'], $db->log);
        }
    }

    public function test_transaction_rolls_back_and_rethrows_any_exception(): void
    {
        $db = new TransactionLogDb();

        try {
            (new TransactionOptimize($db))->seed(true);
            $this->fail('the exception must reach the caller');
        } catch (LogicException) {
        }
        $this->assertSame(['BEGIN', self::DELETE, self::INSERT0, 'ROLLBACK'], $db->log);
    }

    public function test_transaction_without_begin_runs_the_statements_as_they_are(): void
    {
        $db           = new TransactionLogDb();
        $db->begin_ok = false;

        $this->assertSame('done', (new TransactionOptimize($db))->seed());
        $this->assertSame(['BEGIN', self::DELETE, self::INSERT0, self::INSERT1], $db->log, 'no commit of a transaction never begun');
    }

    public function test_transaction_joins_an_open_db_aa_transaction(): void
    {
        $outer = new ReflectionProperty(DB_AA::class, 'tx_db');
        $outer->setValue(null, new TransactionLogDb());
        $db = new TransactionLogDb();
        try {
            $this->assertSame('done', (new TransactionOptimize($db))->seed());
        } finally {
            $outer->setValue(null, null);
        }
        $this->assertSame([self::DELETE, self::INSERT0, self::INSERT1], $db->log, 'a second BEGIN would fail on a shared connection');
    }

    public function test_dry_run_runs_the_block_without_transaction_or_statements(): void
    {
        AbstractOptimize::justPrint(true);
        $db       = new TransactionLogDb();
        $optimize = new TransactionOptimize($db);

        $this->assertSame('done', $optimize->seed());
        $this->assertSame([], $db->log);
        $this->assertSame([['txt' => self::DELETE], ['txt' => self::INSERT0], ['txt' => self::INSERT1]], array_map(
            static fn(array $row) => ['txt' => htmlspecialchars_decode($row['txt'], ENT_QUOTES | ENT_HTML5)],
            $optimize->reportRows(),
        ), 'the report still lists every statement');
    }
}
