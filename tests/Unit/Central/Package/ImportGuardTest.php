<?php

declare(strict_types=1);

/**
 * What guards the import writer: the rollback is the only cleanup of a failed import, so the plan refuses
 * tables that are not InnoDB (E) and write() refuses such a plan, asks the engines again, refuses to join
 * a caller's transaction and to write without one; the engine question and the locking read of DbImportTarget.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\Package\DbImportTarget;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Planner;
use AA\Central\Package\Validator;
use AA\Central\Package\WriteError;
use AA\IO\DB\DB_AA;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\ImportFixture;
use Tests\Unit\Central\Package\Fixtures\TableDb;

require_once __DIR__ . '/fixtures/ImportFixture.php';

final class ImportGuardTest extends TestCase
{
    use ImportFixture;

    // ---------------------------------------------------------------- the engine

    public function test_a_plan_for_tables_without_transactions_is_refused(): void
    {
        $this->target->engines = ['view' => 'MyISAM', 'constant' => 'Aria'];
        $plan                  = (new Planner($this->target))->plan(self::example(), (new Validator())->validate(self::example()));

        self::assertFalse($plan->importable());
        self::assertSame([[Planner::TARGET, Planner::NO_TRANSACTION]], array_map(static fn(array $f): array => [$f[0], $f[1]->code], $plan->ofLevel(LintFinding::ERROR)));

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->importer()->write($plan);
        } finally {
            self::assertSame([], TableDb::$log, 'no statement');
        }
    }

    /** the plan is no reservation: a table converted from InnoDB since the preview stops the write before any statement */
    public function test_write_checks_the_engines_again(): void
    {
        $plan                  = $this->plan();
        $this->target->engines = ['object_text' => 'MyISAM'];

        $e = $this->failure($plan);

        self::assertInstanceOf(WriteError::class, $e->getPrevious());
        self::assertSame([WriteError::ENGINE, 'import'], [$e->getPrevious()->reason, $e->getPrevious()->path]);
        self::assertStringContainsString('object_text (MyISAM) - ', $e->getMessage());
        self::assertSame([], TableDb::$log, 'no statement');
    }

    public function test_the_db_target_names_the_tables_without_transactions(): void
    {
        TableDb::$engines = ['view' => 'InnoDB', 'email' => 'MyISAM', 'module' => 'Aria', 'field' => 'innodb'];

        self::assertSame(['email' => 'MyISAM', 'module' => 'Aria', 'slice' => ''], (new DbImportTarget())->nonTransactional(['view', 'email', 'module', 'slice', 'field']));
        self::assertSame(["SELECT TABLE_NAME, ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA = 'aa_test' AND TABLE_NAME IN ('view','email','module','slice','field')"], TableDb::$log);
        self::assertSame([], (new DbImportTarget())->nonTransactional([]));
    }

    /** a failed locking read (a lock wait timeout) is an error, never "free" - and no die() of a halting statement */
    public function test_the_db_target_throws_when_the_locking_read_fails(): void
    {
        TableDb::$fail = static fn(string $sql): bool => str_ends_with($sql, 'FOR UPDATE');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the locking read of the constant group ct_rubriky failed');
        (new DbImportTarget())->groupTaken('ct_rubriky', true);
    }

    // ---------------------------------------------------------------- the transaction

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
        self::assertSame(['BEGIN', 'ROLLBACK'], TableDb::$log);
    }

    public function test_no_transaction_no_write(): void
    {
        TableDb::$transactions = false;             // beginTransaction() fails: DB_AA::transaction() would run the block without one
        $foreign               = self::foreign();

        $e = $this->failure($this->plan());

        self::assertInstanceOf(WriteError::class, $e->getPrevious());
        self::assertSame([WriteError::DB, 'import'], [$e->getPrevious()->reason, $e->getPrevious()->path]);
        self::assertStringContainsString('nothing was written: db at import: no database transaction could be started', $e->getMessage());
        self::assertSame([], TableDb::$log, 'no statement');
        self::assertSame($foreign, array_filter(TableDb::$tables));
    }
}
