<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\Cache\ImgCache;
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

    public function test_cap_from_the_config_wins_and_zero_means_none(): void
    {
        $planned = 2 * 1024 ** 3;
        $this->assertSame($planned, MissCachePurge::cap(null, $planned), 'not configured');
        $this->assertSame(8 * 1024 ** 3, MissCachePurge::cap(8 * 1024 ** 3, $planned));
        $this->assertNull(MissCachePurge::cap(0, $planned), '0 = the 30 days only');
        $this->assertSame($planned, MissCachePurge::cap(-1, $planned), 'a negative number is a typo');
        $this->assertSame($planned, MissCachePurge::cap('8G', $planned), 'a string is not a size');
        $this->assertSame(1_610_612_736, MissCachePurge::cap(1.5 * 1024 ** 3, $planned), 'a float counts in whole bytes');
        $this->assertSame($planned, MissCachePurge::cap(INF, $planned));
        $this->assertSame($planned, MissCachePurge::cap(1e19, $planned), 'past PHP_INT_MAX the cast turns negative');
        $this->assertSame(1, MissCachePurge::cap(0.5, $planned), 'only 0 switches the cap off');
    }

    public function test_cap_is_read_from_the_image_cache_config(): void
    {
        $config = ['IMG_UPLOAD_URL' => 'https://x/img_upload/', 'IMG_UPLOAD_PATH' => '/x/', 'IMG_UPLOAD_DIR_MODE' => 0o775, 'AA_INSTAL_URL' => 'https://x/', 'AA_MISSCACHE_IMG_DISABLE' => false];
        try {
            ImgCache::useConfig($config + ['AA_MISSCACHE_MAX_BYTES' => 8 * 1024 ** 3]);
            $this->assertSame(8 * 1024 ** 3, MissCachePurge::cap(ImgCache::maxBytes(), 2 * 1024 ** 3));
            ImgCache::useConfig($config);
            $this->assertNull(ImgCache::maxBytes(), 'not in the config');
            ImgCache::useConfig(false);
            $this->assertNull(ImgCache::maxBytes(), 'no image cache');
        } finally {
            ImgCache::useConfig(null);
        }
    }
}
