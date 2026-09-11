<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use PHPUnit\Framework\TestCase;

/**
 * Characterization + regression tests for the two toexecute-queue races behind
 * duplicate task execution (2026-07-15 dataplan.info double mail, see
 * bugfixes/ZAVERY.md 2026-07-21):
 *
 * Race 1 (execution): Toexecute::_exeOne() "claimed" a due row only by
 * decrementing priority (100 -> 99, still matching `priority > 0`) and deleted
 * it AFTER execution, so a concurrent runner (OS-cron misc/toexecute.php vs.
 * the r5227 CronFallback) selected and executed the same row again — proven by
 * NSZMsql1.log: both runners ran AA_Plannedtask:6a446a97… at 02:16:03/02:16:06.
 *
 * Race 2 (scheduling): Toexecute::laterOnce() is check-then-insert on the
 * selector; two concurrent schedulers both saw "not scheduled" and both
 * INSERTed, leaving duplicate Plannedtask_* rows that one runner later executes
 * back-to-back (three such pairs created 2026-07-21 00:02 on dataplan).
 *
 * The fix: (1) an atomic CAS claim — one conditional UPDATE moving
 * execute_after forward by a lease, guarded by the previously-read
 * (execute_after, priority) values and verified via affected rows; (2) a DB
 * named lock (GET_LOCK) serializing laterOnce's check+insert.
 *
 * Toexecute is DB-coupled (DB_AA/Cvarset statics, getDB), so these tests
 * (a) model both races' interleavings over the queue-row algebra, and
 * (b) anchor to the production source so a revert to the racy form fails.
 */
final class ToexecuteQueueRaceTest extends TestCase
{
    private const SOURCE        = __DIR__ . '/../../../src/Later/Toexecute.php';
    private const RUNNER_SOURCE = __DIR__ . '/../../../misc/toexecute.php';
    private const MANAGER_CONF_SOURCE  = __DIR__ . '/../../../include/actions.php';
    private const EXECUTE_ACTION_SOURCE = __DIR__ . '/../../../src/Manageraction/Taskmanager_Execute.php';

    private const LEASE = 1800;

    private const USER_TASK_TIME = 2000000000;   // TOEXECUTE_USER_TASK_TIME

    /** @var array<int, array{id: int, execute_after: int, priority: int}> queue rows by id */
    private array $rows = [];

    private function seedRow(int $id, int $due, int $priority = 100): void
    {
        $this->rows[$id] = ['id' => $id, 'execute_after' => $due, 'priority' => $priority];
    }

    /** SELECT … WHERE execute_after < $now AND priority > 0 — a runner's scan. */
    private function selectDue(int $now): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['execute_after'] < $now && $row['priority'] > 0) {
                return $row;   // copy = the runner's read snapshot
            }
        }
        return null;
    }

    /**
     * The claim step, over a previously-read snapshot.
     *
     * @param string $variant 'old' (unconditional priority decrement) or
     *                        'new' (CAS on the read execute_after+priority, lease)
     * @return bool true = this runner owns the task and will execute it
     */
    private function claim(string $variant, array $snapshot, int $now): bool
    {
        $id = $snapshot['id'];
        if (!isset($this->rows[$id])) {
            return $variant === 'old';   // old code executed even a just-deleted row's snapshot
        }
        if ($variant === 'old') {
            $this->rows[$id]['priority'] = max($this->rows[$id]['priority'] - 1, 0);
            return true;                 // no condition, no affected-rows check
        }
        // new: UPDATE … WHERE id AND execute_after=<read> AND priority=<read>; affected==1
        if ($this->rows[$id]['execute_after'] !== $snapshot['execute_after']
            || $this->rows[$id]['priority'] !== $snapshot['priority']) {
            return false;
        }
        // user-queue tasks keep their parking time (stay invisible to the cron
        // scan even after a mid-task crash), everything else gets a real lease
        $this->rows[$id]['execute_after'] = $this->rows[$id]['execute_after'] === self::USER_TASK_TIME
            ? self::USER_TASK_TIME
            : $now + self::LEASE;
        $this->rows[$id]['priority'] = max($this->rows[$id]['priority'] - 1, 0);
        return true;
    }

    /**
     * executeTask()'s by-id fetch: null only for a missing (finished) row.
     * Deliberately NO lease-window check - manual "Execute" in the Task
     * Manager UI must work even for leased or near-future-scheduled rows.
     */
    private function selectById(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    /**
     * Manual "Execute" over a row, optionally guarded by the priority the UI
     * displayed (checkbox value "p-<n>"): a mismatch with the row's current
     * priority means the task changed meanwhile -> skip, no execution.
     * No expectation (null) = no check, the pre-existing behavior.
     */
    private function manualExecuteRuns(int $id, ?int $expected_priority, int $now): bool
    {
        $snap = $this->selectById($id);
        if ($snap === null || ($expected_priority !== null && $snap['priority'] !== $expected_priority)) {
            return false;
        }
        return $this->claim('new', $snap, $now);
    }

    /** Task done — remove it from queue (the post-execution DELETE). */
    private function finish(int $id): void
    {
        unset($this->rows[$id]);
    }

    /**
     * Race 1 interleaving as logged on dataplan 2026-07-15 02:16: runner A
     * selects and starts the task; while it executes (row still present),
     * runner B scans the queue. Returns how many times the task is executed.
     */
    private function overlappingRunnersExecutions(string $variant): int
    {
        $now = 1000000;
        $this->rows = [];
        $this->seedRow(7, $now - 60);

        $executions = 0;

        $snapA = $this->selectDue($now);                     // A: 02:16:02 scan
        if ($snapA && $this->claim($variant, $snapA, $now)) {
            ++$executions;                                   // A executes (~4 s)
        }
        $snapB = $this->selectDue($now + 4);                 // B: fallback scans mid-execution
        if ($snapB && $this->claim($variant, $snapB, $now + 4)) {
            ++$executions;                                   // B executes the same task
        }
        if ($snapA) {
            $this->finish($snapA['id']);                     // A: post-execution DELETE
        }
        return $executions;
    }

    public function testOldClaimExecutesOverlappedTaskTwice(): void
    {
        $this->assertSame(2, $this->overlappingRunnersExecutions('old'), 'priority-decrement "claim" lets a concurrent runner duplicate the task (the 2026-07-15 double mail)');
    }

    public function testCasClaimExecutesOverlappedTaskOnce(): void
    {
        $this->assertSame(1, $this->overlappingRunnersExecutions('new'), 'CAS lease claim must make the row invisible/unclaimable for the concurrent runner');
    }

    public function testCasClaimTightRaceBothSelectBeforeEitherClaims(): void
    {
        // Tightest interleaving: both runners hold identical snapshots before
        // either claims. Row locking serializes the UPDATEs; the loser's CAS
        // sees changed values and must not execute.
        $now = 1000000;
        $this->rows = [];
        $this->seedRow(7, $now - 60);

        $snapA = $this->selectDue($now);
        $snapB = $this->selectDue($now);
        $executions  = (int) $this->claim('new', $snapA, $now);
        $executions += (int) $this->claim('new', $snapB, $now);
        $this->assertSame(1, $executions, 'of two identical snapshots exactly one CAS claim may win');
    }

    public function testLeasedTaskReappearsAfterLeaseExpiry(): void
    {
        // A runner that crashes mid-task never DELETEs the row; the lease must
        // expire so the task retries instead of being lost forever.
        $now = 1000000;
        $this->rows = [];
        $this->seedRow(7, $now - 60);

        $snap = $this->selectDue($now);
        $this->claim('new', $snap, $now);                    // runner crashes here — no finish()
        $this->assertNull($this->selectDue($now + self::LEASE - 1), 'row is invisible while leased');
        $this->assertNotNull($this->selectDue($now + self::LEASE + 1), 'row is retried after the lease expires');
    }

    /**
     * Race 2: two schedulers run laterOnce("Plannedtask_X") concurrently.
     * 'old': both check-then-insert unserialized. 'new': GET_LOCK serializes
     * the whole check+insert critical section.
     * Returns the number of queue rows for the selector afterwards.
     */
    private function concurrentLaterOnceRows(string $variant): int
    {
        $exists = false;
        $rows   = 0;
        $checkThenInsert = static function () use (&$exists, &$rows): void {
            if (!$exists) {
                $exists = true;
                ++$rows;
            }
        };

        if ($variant === 'old') {
            // interleaved: both check (see "missing") before either inserts
            $checkA = $exists;
            $checkB = $exists;
            if (!$checkA) {
                $exists = true;
                ++$rows;
            }
            if (!$checkB) {
                $exists = true;
                ++$rows;
            }
        } else {
            // GET_LOCK: critical sections run one after the other
            $checkThenInsert();
            $checkThenInsert();
        }
        return $rows;
    }

    public function testUnserializedLaterOnceCreatesDuplicateRows(): void
    {
        $this->assertSame(2, $this->concurrentLaterOnceRows('old'), 'check-then-insert race seeds duplicate Plannedtask rows (three pairs created 2026-07-21 00:02)');
    }

    public function testSerializedLaterOnceCreatesSingleRow(): void
    {
        $this->assertSame(1, $this->concurrentLaterOnceRows('new'), 'serialized check+insert must yield exactly one row');
    }

    public function testCrashedUserQueueTaskStaysInvisibleToCronScan(): void
    {
        // User tasks are parked at TOEXECUTE_USER_TASK_TIME so execute()'s scan
        // never picks them. The claim must NOT replace that parking time with a
        // real lease - a crashed user task would surface to the cron scan after
        // 30 min and get auto-executed, which the user-queue contract forbids.
        $now = 1000000;
        $this->rows = [];
        $this->seedRow(9, self::USER_TASK_TIME);

        $snap = $this->selectById(9);
        $this->assertNotNull($snap, 'a parked user task is manually executable');
        $this->claim('new', $snap, $now);                    // runner crashes here — no finish()
        $this->assertNull($this->selectDue($now + self::LEASE + 1), 'crashed user task must never enter the cron scan');
        $this->assertNotNull($this->selectById(9), 'crashed user task stays manually re-runnable');
    }

    public function testManualExecuteStillReachesLeasedAndFutureRows(): void
    {
        // Manual "Execute" (Task Manager UI) is an explicit, informed action:
        // it must be able to fetch a row another runner has leased as well as
        // a row scheduled for the near future - only a finished (deleted) row
        // is skipped. The duplicate-run protection for two concurrent clicks
        // comes from the CAS itself (same pre-claim snapshot -> one winner).
        $now = 1000000;
        $this->rows = [];
        $this->seedRow(7, $now - 60);

        $snap = $this->selectDue($now);
        $this->assertTrue($this->claim('new', $snap, $now), 'cron runner claims the due task');
        $this->assertNotNull($this->selectById(7), 'by-id fetch still hands out a leased row (explicit user action)');
        $this->finish(7);
        $this->assertNull($this->selectById(7), 'finished (deleted) row is the only skip');
    }

    public function testManualExecuteWithStalePriorityIsSkipped(): void
    {
        // The Task Manager list showed priority 100; before the user clicked
        // Execute, a cron runner claimed the task (priority -> 99, running).
        // The stale expectation must skip the click - not run the task twice.
        $now = 1000000;
        $this->rows = [];
        $this->seedRow(7, $now - 60);

        $cron_snap = $this->selectDue($now);
        $this->assertTrue($this->claim('new', $cron_snap, $now), 'cron runner claims the due task');
        $this->assertFalse($this->manualExecuteRuns(7, 100, $now + 5), 'stale UI priority (100 vs current 99) must skip the manual run');
    }

    public function testManualExecuteWithFreshPriorityRuns(): void
    {
        // A refreshed list shows the current priority - the informed explicit
        // re-run is allowed (this is the deliberate "user knows what they are
        // doing" path). And with no expectation at all (select-all, GET links)
        // the check does not apply.
        $now = 1000000;
        $this->rows = [];
        $this->seedRow(7, $now - 60, 99);

        $this->assertTrue($this->manualExecuteRuns(7, 99, $now), 'matching UI priority executes');

        $this->rows = [];
        $this->seedRow(8, $now - 60);
        $this->assertTrue($this->manualExecuteRuns(8, null, $now), 'no expectation = no check (pre-existing behavior)');
    }

    public function testProductionWiresExpectedPriorityFromManagerUi(): void
    {
        $toexec = file_get_contents(self::SOURCE);
        $this->assertNotFalse($toexec, 'cannot read ' . self::SOURCE);
        $this->assertMatchesRegularExpression(
            '/function executeTask\(\$tasks,\s*array \$expected\s*=\s*\[\]\)/',
            $toexec,
            'executeTask must accept the optional id => expected-priority map',
        );

        $conf = file_get_contents(self::MANAGER_CONF_SOURCE);
        $this->assertNotFalse($conf, 'cannot read ' . self::MANAGER_CONF_SOURCE);
        $this->assertStringContainsString(
            'value="p-_#PRIORITY"',
            $conf,
            'the Task Manager checkbox value must carry the displayed priority as a parseable "p-<n>" token',
        );

        $action = file_get_contents(self::EXECUTE_ACTION_SOURCE);
        $this->assertNotFalse($action, 'cannot read ' . self::EXECUTE_ACTION_SOURCE);
        $this->assertStringContainsString(
            'p-(\d+)',
            $action,
            'Taskmanager_Execute must parse the "p-<n>" token from the checkbox values',
        );
    }

    public function testProductionClaimIsConditionalCasWithLease(): void
    {
        $src = file_get_contents(self::SOURCE);
        $this->assertNotFalse($src, 'cannot read ' . self::SOURCE);

        $this->assertMatchesRegularExpression(
            '/CLAIM_LEASE\s*=\s*1800/',
            $src,
            'Toexecute must define the CLAIM_LEASE used by the CAS claim',
        );
        $this->assertMatchesRegularExpression(
            '/DB_AA::update\(\s*\'toexecute\'.*CLAIM_LEASE.*\[\'execute_after\',\s*\$task\[\'execute_after\'\]/s',
            $src,
            'the claim UPDATE must set the lease and be guarded by the previously-read execute_after (CAS), not a bare priority decrement',
        );
        $this->assertStringContainsString(
            '$claimed !== 1',
            $src,
            'the claim must verify affected rows - only the runner whose UPDATE hit the row owns the task',
        );
        $this->assertMatchesRegularExpression(
            '/TOEXECUTE_USER_TASK_TIME\s*:\s*time\(\)\s*\+\s*self::CLAIM_LEASE/s',
            $src,
            'the claim must preserve the user-queue parking time instead of leasing it (crashed user tasks must not surface to the cron scan)',
        );
        $this->assertMatchesRegularExpression(
            '/GET_LOCK/',
            $src,
            'laterOnce must serialize its check+insert with a DB named lock (GET_LOCK)',
        );
        $this->assertMatchesRegularExpression(
            '/RELEASE_LOCK/',
            $src,
            'the DB named lock must be released',
        );
    }

    public function testRunnerTouchesFallbackGateBeforeProcessingQueue(): void
    {
        $src = file_get_contents(self::RUNNER_SOURCE);
        $this->assertNotFalse($src, 'cannot read ' . self::RUNNER_SOURCE);

        $touch   = strpos($src, 'touch(CronFallback::lockFile())');
        $execute = strpos($src, '$toexecute->execute()');
        $this->assertNotFalse($touch, 'misc/toexecute.php must touch the CronFallback gate file');
        $this->assertNotFalse($execute, 'misc/toexecute.php must run the queue');
        $this->assertLessThan(
            $execute,
            $touch,
            'the gate touch must happen BEFORE the queue run, so web traffic does not fire the fallback mid-run',
        );
    }
}
