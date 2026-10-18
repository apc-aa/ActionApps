<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA\Http\ClientInterface;
use AA\Isds\Account;
use AA\Isds\Client;
use AA\Isds\SendItems;
use AA\Isds\SendRun;
use AA\Isds\SliceRules;
use AA\Later\BatchBudget;

/** SendRun on the test's clock, include/config.php, ISDS answers and items in memory */
class TestSendRun extends SendRun
{
    public static ?int $now = null;
    /** the clock of the step's budget; null = $now, so the budget is never spent */
    public static ?float $microNow = null;
    /** @var array<string, mixed> the constants of include/config.php */
    public static array $constants = [];
    /** answers every call to ISDS; null = a call fails the test */
    public static ?ClientInterface $http = null;
    public static ?MemorySendItems $items = null;
    /** how many times a step invalidated the pages of the message slice */
    public static int $invalidated = 0;
    /** @var ?\Closure(): void runs after the claim, before work() - where another process may step in */
    public static ?\Closure $beforeWork = null;
    /** the rules a step asks of the slices; null = the slices pass them */
    public static ?SliceRules $rules = null;

    public static function reset(): void
    {
        self::$now         = null;
        self::$microNow    = null;
        self::$constants   = [];
        self::$http        = null;
        self::$items       = null;
        self::$invalidated = 0;
        self::$beforeWork  = null;
        self::$rules       = null;
    }

    public function retryDelayOf(int $failures): int
    {
        return $this->retryDelay($failures);
    }

    public function overrunOf(): int
    {
        return $this->overrun();
    }

    protected static function now(): int
    {
        return self::$now ?? parent::now();
    }

    protected static function microNow(): float
    {
        return self::$microNow ?? (float) self::now();
    }

    protected function fetch(array $from, BatchBudget $budget): mixed
    {
        if (self::$beforeWork) {
            (self::$beforeWork)();
        }
        return parent::fetch($from, $budget);
    }

    protected function constants(): array
    {
        return self::$constants;
    }

    protected function client(Account $account): Client
    {
        return new Client($account, self::$http ?? throw new \LogicException('This step must not call ISDS'), null, new MemoryLatch());
    }

    protected function sliceRules(): SliceRules
    {
        return self::$rules ?? new class extends SliceRules {
            public function sliceProblems(string $campaignSlice, string $messageSlice): array
            {
                return [];
            }
        };
    }

    protected function items(): SendItems
    {
        return self::$items ?? throw new \LogicException('No items');
    }

    protected function invalidate(): void
    {
        ++self::$invalidated;
    }
}
