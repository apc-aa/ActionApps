<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA\Http\ClientInterface;
use AA\IO\DB\DB_AA;
use AA\Isds\Account;
use AA\Isds\Client;
use AA\Isds\DatovkaConf;
use AA\Isds\SendItems;
use AA\Isds\SendRun;
use AA\Isds\SliceRules;
use AA\Later\BatchBudget;

/** SendRun on the test's clock, datovka_conf.php, memory_limit, ISDS answers, items in memory and the campaign locks */
class TestSendRun extends SendRun
{
    public static ?int $now = null;
    /** the clock of the step's budget; null = $now, so the budget is never spent */
    public static ?float $microNow = null;
    /** the settings of datovka_conf.php; null = no account */
    public static ?DatovkaConf $conf = null;
    /** answers every call to ISDS; null = a call fails the test */
    public static ?ClientInterface $http = null;
    public static ?MemorySendItems $items = null;
    /** how many times a step invalidated the pages of the message slice */
    public static int $invalidated = 0;
    /** @var ?\Closure(): void runs after the claim, before work() - where another process may step in */
    public static ?\Closure $beforeWork = null;
    /** the rules a step asks of the slices; null = the slices pass them */
    public static ?SliceRules $rules = null;
    /** memory_limit in bytes, -1 = none; whether it may be raised, to what a step raised it and what it put back */
    public static int $memoryLimit     = -1;
    public static bool $mayRaise       = false;
    public static ?int $raisedTo       = null;
    public static ?string $restoredTo  = null;
    /** what invalidate() throws; null = it invalidates */
    public static ?\Throwable $invalidateFails = null;
    /** @var list<string> campaigns whose lock another process holds */
    public static array $lockedByOthers = [];
    /** @var list<string> each lock and unlock of a campaign, and whether a transaction was open then */
    public static array $lockLog = [];
    /** @var ?\Closure(): void runs when a campaign lock is taken - what changed while a request waited for it */
    public static ?\Closure $onLock = null;

    public static function reset(): void
    {
        self::$now         = null;
        self::$microNow    = null;
        self::$conf        = null;
        self::$http        = null;
        self::$items       = null;
        self::$invalidated = 0;
        self::$beforeWork  = null;
        self::$rules       = null;
        self::$memoryLimit = -1;
        self::$mayRaise    = false;
        self::$raisedTo    = null;
        self::$restoredTo  = null;
        self::$invalidateFails = null;
        self::$lockedByOthers  = [];
        self::$lockLog         = [];
        self::$onLock          = null;
    }

    public function retryDelayOf(int $failures): int
    {
        return $this->retryDelay($failures);
    }

    public function overrunOf(): int
    {
        return $this->overrun();
    }

    /** the real restoreMemoryLimit(), which the steps here do not run */
    public function restoreMemoryLimitOf(string $limit): void
    {
        parent::restoreMemoryLimit($limit);
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

    protected function conf(): DatovkaConf
    {
        return self::$conf ?? DatovkaConf::fromArray([]);
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

    protected function memoryLimit(): int
    {
        return self::$memoryLimit;
    }

    protected function raiseMemoryLimit(int $bytes): ?string
    {
        self::$raisedTo = $bytes;
        return self::$mayRaise ? (string) self::$memoryLimit : null;
    }

    protected function restoreMemoryLimit(string $limit): void
    {
        self::$restoredTo = $limit;
    }

    protected static function lockCampaign(string $campaign): bool
    {
        self::$lockLog[] = "lock $campaign" . (DB_AA::inTransaction() ? ' in a transaction' : '');
        if (self::$onLock) {
            (self::$onLock)();
        }
        return !in_array($campaign, self::$lockedByOthers, true);
    }

    protected static function unlockCampaign(string $campaign): void
    {
        self::$lockLog[] = "unlock $campaign" . (DB_AA::inTransaction() ? ' in a transaction' : '');
    }

    protected function invalidate(): void
    {
        ++self::$invalidated;
        if (self::$invalidateFails) {
            throw self::$invalidateFails;
        }
    }
}
