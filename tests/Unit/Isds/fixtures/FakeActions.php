<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA\Isds\Actions;
use AA\Isds\SendStart;
use AA\Isds\Setup;
use AA\Isds\TestResult;

/**
 * Actions with the core, the logs and post_max_size given here: Continue says what $resume says, Stop
 * and Abandon what $stop says, Verify what $verify says, Send what $queue, Test what $test, Save what $save, Copy what $copy, each records who asked it; a log row
 * is recorded, not written - or fails as $logFails says.
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

    /** @var ?\Closure(string, string, string): string what Verify of a message of a campaign by a uid says; null = '' (verified now) */
    public static ?\Closure $verify = null;

    /** @var list<array{string, string, string}> campaign, uid, message of each Verify asked of the core */
    public static array $verified = [];

    /** @var ?\Closure(Setup, string, string, int): SendStart what Send of a campaign by a uid with the count confirmed says; null = the test asks none */
    public static ?\Closure $queue = null;

    /** @var list<array{string, string, int}> campaign, uid, count confirmed of each Send asked of the core */
    public static array $queued = [];

    /** @var ?\Closure(Setup, string, string, string): TestResult what Test of a campaign by a uid to a box says; null = the test asks none */
    public static ?\Closure $test = null;

    /** @var list<array{string, string, string}> campaign, uid, box of each Test asked of the core */
    public static array $tested = [];

    /** @var ?\Closure(Setup, string, array<array-key, mixed>, array<array-key, mixed>): array{code: string, campaign: string, field: string, file: string} what Save of a campaign with the form and its files says; null = the test asks none */
    public static ?\Closure $save = null;

    /** @var list<array{string, array<array-key, mixed>, array<array-key, mixed>}> campaign, form and files of each Save asked of the core */
    public static array $saved = [];

    /** @var ?\Closure(Setup, string, string, array<array-key, mixed>, list<string>): array{code: string, campaign: string, made: bool} what Copy of a campaign in a scope says; null = the test asks none */
    public static ?\Closure $copy = null;

    /** @var list<array{string, string, list<string>}> source, scope and the items unsent of each Copy asked of the core */
    public static array $copied = [];

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
        self::$verify      = null;
        self::$verified    = [];
        self::$queue       = null;
        self::$queued      = [];
        self::$test        = null;
        self::$tested      = [];
        self::$save        = null;
        self::$saved       = [];
        self::$copy        = null;
        self::$copied      = [];
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

    protected function verifyRun(string $campaign, string $uid, string $msg): string
    {
        self::$verified[] = [$campaign, $uid, $msg];

        return is_null(self::$verify) ? '' : (self::$verify)($campaign, $uid, $msg);
    }

    protected function queueRun(Setup $setup, string $campaign, string $uid, int $confirmed): SendStart
    {
        self::$queued[] = [$campaign, $uid, $confirmed];

        return (self::$queue ?? throw new \LogicException('This test sends nothing'))($setup, $campaign, $uid, $confirmed);
    }

    protected function testRun(Setup $setup, string $campaign, string $uid, string $box): TestResult
    {
        self::$tested[] = [$campaign, $uid, $box];

        return (self::$test ?? throw new \LogicException('This test sends no test message'))($setup, $campaign, $uid, $box);
    }

    protected function saveDraft(Setup $setup, string $campaign, array $post, array $files): array
    {
        self::$saved[] = [$campaign, $post, $files];

        return (self::$save ?? throw new \LogicException('This test saves nothing'))($setup, $campaign, $post, $files);
    }

    protected function copyDraft(Setup $setup, string $source, string $scope, array $post, array $unsent): array
    {
        self::$copied[] = [$source, $scope, $unsent];

        return (self::$copy ?? throw new \LogicException('This test copies nothing'))($setup, $source, $scope, $post, $unsent);
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
