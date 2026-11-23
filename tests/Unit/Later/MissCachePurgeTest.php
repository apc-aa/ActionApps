<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\Later\MissCachePurge;
use PHPUnit\Framework\TestCase;

/**
 * The purge reports in the queue log, which keeps 40 bytes of a task's answer - the numbers
 * needed to size the cache cap must come first.
 */
final class MissCachePurgeTest extends TestCase
{
    private const STATS = [
        'scanned' => 0, 'deleted_age' => 0, 'deleted_size' => 0, 'deleted_tmp' => 0, 'deleted_stale' => 0,
        'bytes_freed' => 0, 'dirs_removed' => 0, 'total_after' => 0,
    ];

    public function test_summary_puts_the_cap_size_and_free_disk_first(): void
    {
        $line = MissCachePurge::summary(['scanned' => 48477, 'deleted_age' => 12, 'deleted_size' => 6123, 'bytes_freed' => 419_430_400, 'total_after' => 1_932_735_283] + self::STATS, 225_485_783_040.0);
        $this->assertSame('-6123cap -12old =1.8G free 210G freed 400M of 48477 files', $line);
    }

    public function test_summary_fits_the_log_up_to_the_free_disk_even_when_big(): void
    {
        $line = MissCachePurge::summary(['deleted_age' => 99999, 'deleted_size' => 99999, 'total_after' => 1023 * 1024 ** 3] + self::STATS, 1023.0 * 1024 ** 4);
        $this->assertLessThanOrEqual(40, strlen(substr($line, 0, (int) strpos($line, ' freed'))), $line);
        $this->assertStringNotContainsString(':', $line, 'the log escapes ":" to "#:", which costs bytes');
    }

    public function test_summary_shows_an_unknown_free_disk_and_rare_deletions(): void
    {
        $line = MissCachePurge::summary(['deleted_tmp' => 2, 'deleted_stale' => 1] + self::STATS, false);
        $this->assertSame('-0cap -0old =0B free ? -2tmp -1blank freed 0B of 0 files', $line);
    }
}
