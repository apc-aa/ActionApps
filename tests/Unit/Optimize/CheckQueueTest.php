<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Later\Toexecute;
use AA\Optimize\CheckQueue;
use PHPUnit\Framework\TestCase;

/** runStats() reads the positional Xrun:done:started:duration:allowed rows */
final class CheckQueueTest extends TestCase
{
    public function test_counts_runs_over_limit_and_unfinished(): void
    {
        $stats = CheckQueue::runStats([
            'Xrun:3:3:12.5:59',      // fine
            'Xrun:5:6:61:59',        // over the limit and one task left undone
            'Xrun:0:0:0.01:59',      // empty run
            'Xrun:2:3:40:59',        // unfinished but within the limit
            'X:1.2:20M:22M:1:',      // a task row, not a run
            'garbage',
            'Xrun:1:1',              // too short
        ]);
        $this->assertSame(['runs' => 4, 'over' => 1, 'unfinished' => 2, 'max' => 61.0, 'allowed' => 59.0], $stats);
    }

    /** a failed step of an import stops its run in error, which CheckImports reports with its own advice */
    public function test_failedTasks_leaveImportStepsToCheckImports(): void
    {
        $where = CheckQueue::failedTasksOf(1_900_000_000);

        $this->assertContains(['type', Toexecute::FAILED_EVENT], $where);
        $this->assertContains(['time', 1_900_000_000 - 7 * 86400, '>='], $where);
        $this->assertContains(['selector', 'Import_', 'NOT BEGIN'], $where);
        $this->assertCount(3, $where);
    }

    public function test_no_rows_gives_zeros(): void
    {
        $this->assertSame(['runs' => 0, 'over' => 0, 'unfinished' => 0, 'max' => 0.0, 'allowed' => 0.0], CheckQueue::runStats([]));
    }
}
