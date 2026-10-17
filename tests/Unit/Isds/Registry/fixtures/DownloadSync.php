<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Registry\Fixtures;

use AA\Isds\Registry\RegisterIndex;
use AA\Isds\Registry\Sync;

require_once __DIR__ . '/LockedRegisterIndex.php';

/** Sync's static parts with the download lock and the log of attempts kept here, and the register's
 *  record in LockedRegisterIndex, instead of in the database. Static as they are - reset() before each
 *  test. */
final class DownloadSync extends Sync
{
    /** whether lockDownload() gets the lock */
    public static bool $free = true;

    /** the connection that holds the lock when it is not free, null = no lock at all */
    public static ?int $holder = 17;

    public static int $locks = 0;

    public static int $unlocks = 0;

    /** @var list<int> the attempts, as the log holds them */
    public static array $attempts = [];

    /** the $now of the running download, which recordAttempt() records - AA_Log::write() takes time() */
    private static int $now = 0;

    public static function reset(): void
    {
        self::$free     = true;
        self::$holder   = 17;
        self::$locks    = 0;
        self::$unlocks  = 0;
        self::$attempts = [];
        self::$now      = 0;
        LockedRegisterIndex::reset();
    }

    protected static function lockDownload(?int &$holder = null): bool
    {
        ++self::$locks;
        $holder = self::$free ? null : self::$holder;
        return self::$free;
    }

    protected static function unlockDownload(): void
    {
        ++self::$unlocks;
    }

    /** as the query over the log: younger than MIN_DOWNLOAD_AGE, not ahead of $now */
    protected static function lastAttempt(int $now): int
    {
        self::$now = $now;
        $recent    = array_filter(self::$attempts, static fn(int $t): bool => ($t > $now - self::MIN_DOWNLOAD_AGE) && ($t <= $now));
        return max([0, ...$recent]);
    }

    protected static function recordAttempt(): void
    {
        self::$attempts[] = self::$now;
    }

    protected static function index(string $file): RegisterIndex
    {
        return new LockedRegisterIndex($file);
    }
}
