<?php

declare(strict_types=1);

/**
 * BatchRun::deleteOfSubject(): a subject that goes away takes all its runs, their queued steps and
 * close rows with it - in the caller's transaction, with the subject's own records.
 */

namespace Tests\Unit\Later;

use AA\IO\DB\DB_AA;
use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Later\Fixtures\BatchRunTables;
use Tests\Unit\Later\Fixtures\ScriptedBatchRun;

require_once __DIR__ . '/Fixtures/BatchRunTables.php';

final class BatchRunDeleteOfSubjectTest extends TestCase
{
    use BatchRunTables;

    private const OTHER_SUBJECT = '7b7b7b7b7b7b7b7b7b7b7b7b7b7b7b7b';

    public function test_deleteOfSubject_deletesItsRunsStepsAndCloseRows(): void
    {
        $ended = self::start()->getId();
        DB_AA::transaction(static fn() => ScriptedBatchRun::lockForUpdate($ended)?->end(self::USER));
        $active = self::start()->getId();
        $other  = self::start(null, ['subject' => self::OTHER_SUBJECT])->getId();
        $held   = self::stepOf(self::rowsOf('Scripted_' . $active)[0]);
        $kept   = self::objectRows($other);
        $this->assertCount(1, self::rowsOf('BatchClose_' . $ended));

        $deleted = DB_AA::transaction(static fn() => ScriptedBatchRun::deleteOfSubject(self::SUBJECT));

        $this->assertSame(2, $deleted);
        $this->assertSame([], [...self::objectRows($ended), ...self::objectRows($active)]);
        $this->assertSame([], self::rowsOf('Scripted_' . $active), 'the step of the unfinished run is cancelled');
        $this->assertSame([], self::rowsOf('BatchClose_' . $ended), 'and the close row of the ended one');
        $this->assertSame($kept, self::objectRows($other), 'a run of another subject stays');
        $this->assertCount(1, self::rowsOf('Scripted_' . $other));
        $this->assertSame('stale', $held->toexecutelater(), 'a step taken before finds no run');
        $this->assertSame([], self::objectRows($active));
    }

    public function test_deleteOfSubject_noRunsDeletesNothing(): void
    {
        $other = self::start(null, ['subject' => self::OTHER_SUBJECT])->getId();
        $rows  = TableDb::rows('object_text');

        $this->assertSame(0, DB_AA::transaction(static fn() => ScriptedBatchRun::deleteOfSubject(self::SUBJECT)));
        $this->assertSame($rows, TableDb::rows('object_text'));
        $this->assertCount(1, self::rowsOf('Scripted_' . $other));
    }

    public function test_deleteOfSubject_outsideTransactionThrows(): void
    {
        self::start();

        $this->expectException(LogicException::class);
        ScriptedBatchRun::deleteOfSubject(self::SUBJECT);
    }

    /** @return list<array<string, mixed>> the stored rows of the object $id */
    private static function objectRows(string $id): array
    {
        return array_values(array_filter(TableDb::rows('object_text'), static fn(array $r): bool => $r['object_id'] === $id));
    }

    /** @return list<array<string, mixed>> the queue rows of $selector */
    private static function rowsOf(string $selector): array
    {
        return array_values(array_filter(self::queue(), static fn(array $r): bool => $r['selector'] === $selector));
    }
}
