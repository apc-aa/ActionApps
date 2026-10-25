<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA\Isds\Actions;

/**
 * Actions with the core, the logs and post_max_size given here: Continue says what $resume says, Stop
 * and Abandon what $stop says, each records who asked it; a log row is recorded, not written - or
 * fails as $logFails says.
 */
final class FakeActions extends Actions
{
    /** @var ?\Closure(string, string): string what Continue of a campaign by a uid says; null = '' (going on) */
    public static ?\Closure $resume = null;

    /** @var list<array{string, string}> campaign, uid of each Continue asked of the core */
    public static array $resumed = [];

    /** @var ?\Closure(string, string, bool): string what Stop (false) or Abandon (true) of a campaign by a uid says; null = '' */
    public static ?\Closure $stop = null;

    /** @var list<array{string, string, bool}> campaign, uid, abandon of each Stop and Abandon asked of the core */
    public static array $stopped = [];

    /** @var list<array{string, string, list<string>}> event, selector, params of each log row */
    public static array $logged = [];

    /** @var list<array{string, string}> selector, params of each internal failure logged */
    public static array $failures = [];

    /** bytes, 0 = no limit */
    public static int $postMaxSize = 8 * 1024 * 1024;

    /** what writing a log row throws; null = it is recorded */
    public static ?\Throwable $logFails = null;

    public static function reset(): void
    {
        self::$resume      = null;
        self::$resumed     = [];
        self::$stop        = null;
        self::$stopped     = [];
        self::$logged      = [];
        self::$failures    = [];
        self::$postMaxSize = 8 * 1024 * 1024;
        self::$logFails    = null;
        Actions::forget();
    }

    protected function postMaxSize(): int
    {
        return self::$postMaxSize;
    }

    protected function resumeRun(string $campaign, string $uid): string
    {
        self::$resumed[] = [$campaign, $uid];

        return is_null(self::$resume) ? '' : (self::$resume)($campaign, $uid);
    }

    protected function stopRun(string $campaign, string $uid, bool $abandon): string
    {
        self::$stopped[] = [$campaign, $uid, $abandon];

        return is_null(self::$stop) ? '' : (self::$stop)($campaign, $uid, $abandon);
    }

    protected function log(string $event, string $selector, array $params): void
    {
        if (self::$logFails) {
            throw self::$logFails;
        }
        self::$logged[] = [$event, $selector, $params];
    }

    protected function logFailure(string $selector, string $params): void
    {
        self::$failures[] = [$selector, $params];
    }
}
