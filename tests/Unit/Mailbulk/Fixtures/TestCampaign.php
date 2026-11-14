<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbulk\Fixtures;

use AA\IO\DB\DB_AA;
use AA\Mailbulk\Campaign;

/** A sending of TestRun runs, its lock held by others when the test says so. */
final class TestCampaign extends Campaign
{
    public static bool $lockedByOthers = false;
    /** @var list<string> lock and unlock, with whether a transaction was open */
    public static array $lockLog = [];

    public static function reset(): void
    {
        self::$lockedByOthers = false;
        self::$lockLog        = [];
    }

    protected static function runClass(): string
    {
        return TestRun::class;
    }

    protected static function lockPair(string $list, string $itemId): bool
    {
        self::$lockLog[] = 'lock' . (DB_AA::inTransaction() ? ' in a transaction' : '');
        return !self::$lockedByOthers;
    }

    protected static function unlockPair(string $list, string $itemId): void
    {
        self::$lockLog[] = 'unlock' . (DB_AA::inTransaction() ? ' in a transaction' : '');
    }
}
