<?php

declare(strict_types=1);

namespace Tests\Unit\Later\Fixtures;

use AA\IO\DB\DB_AA;
use AA\Later\BatchBudget;
use AA\Later\BatchRun;
use AA\Later\BatchStates;
use AA\Later\BatchStep;
use AA\Later\BatchStepResult;
use AA_Property;

/** A batch run whose steps the test scripts: work() hands out the prepared results (or throws the
 *  prepared exceptions, or runs a prepared closure), every hook logs its call, the clock is the test's. */
class ScriptedBatchRun extends BatchRun
{
    /** @var list<BatchStepResult|\Throwable|\Closure(self, array, BatchBudget): BatchStepResult> what the next work() calls give */
    public static array $script = [];
    /** @var list<array{hook: string, tx: bool, running: ?string, token: string, locked: bool, arg: mixed}> the hook calls, in order */
    public static array $calls = [];
    public static ?int $now = null;
    public static ?float $microNow = null;
    /** @var array<string, mixed> what finalCheck() reports */
    public static array $finalReport = [];
    public static ?\Throwable $afterCloseThrows = null;
    /** @var ?\Closure(): void runs inside finalCheck() - a test looks at the run there */
    public static ?\Closure $onFinalCheck = null;
    /** @var ?\Closure(BatchStepResult): ?array what onRetriesExhausted() answers; null = the core's own */
    public static ?\Closure $onExhausted = null;
    /** what stillValid() answers, or throws */
    public static bool|\Throwable $valid = true;
    public static ?\Throwable $commitThrows = null;
    /** @var array<int, int> failures => seconds; a missing count = the core's own */
    public static array $retryDelays = [];
    /** @var array<string, mixed> hook => value that replaces the core's own, or a Throwable it throws
     *                           (overrun, minStepSeconds, stepPriority, budgetLimits, sealPurpose) */
    public static array $hookValues = [];
    /** @var list<int> the times expireExtra() was called with - kept out of $calls, start() calls it */
    public static array $expiredAt = [];
    /** @var array<string, mixed> what progressExtra() adds */
    public static array $progressExtra = [];

    /** an own property, as a user of the core has */
    protected $note = '';

    public static function reset(): void
    {
        self::$script           = [];
        self::$calls            = [];
        self::$now              = null;
        self::$microNow         = null;
        self::$finalReport      = [];
        self::$afterCloseThrows = null;
        self::$onFinalCheck     = null;
        self::$onExhausted      = null;
        self::$valid            = true;
        self::$commitThrows     = null;
        self::$retryDelays      = [];
        self::$hookValues       = [];
        self::$expiredAt        = [];
        self::$progressExtra    = [];
    }

    /** @return list<string> the names of the hooks called, in order */
    public static function hooks(): array
    {
        return array_column(self::$calls, 'hook');
    }

    /** @return array{hook: string, tx: bool, running: ?string, token: string, locked: bool, arg: mixed} the first call of $hook */
    public static function call(string $hook): array
    {
        foreach (self::$calls as $call) {
            if ($call['hook'] === $hook) {
                return $call;
            }
        }
        throw new \LogicException("$hook was not called");
    }

    public static function getClassProperties(): array
    {
        return parent::getClassProperties() + ['note' => new AA_Property('note', 'note', 'text', false, true, 'text')];
    }

    protected static function states(): BatchStates
    {
        return new BatchStates('running', 'error', 'done', 'ended', ['preview' => ['running']]);
    }

    public function selector(): string
    {
        return 'Scripted_' . $this->getId();
    }

    public function mayControl(string $uid): bool
    {
        return $uid === $this->user;
    }

    protected function work(array $from, BatchBudget $budget, mixed $fetched): BatchStepResult
    {
        $this->log('work', $from);
        $next = array_shift(self::$script) ?? throw new \LogicException('No scripted step left');
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return ($next instanceof \Closure) ? $next($this, $from, $budget) : $next;
    }

    protected function commitStep(BatchStepResult $r): void
    {
        $this->log('commitStep', $r->extra);
        if (self::$commitThrows) {
            throw self::$commitThrows;
        }
    }

    protected function finalCheck(): array
    {
        $this->log('finalCheck');
        if (self::$onFinalCheck) {
            (self::$onFinalCheck)();
        }
        return self::$finalReport;
    }

    protected function afterClose(string $state): void
    {
        $this->log('afterClose', $state);
        if (self::$afterCloseThrows) {
            throw self::$afterCloseThrows;
        }
    }

    protected function onRetriesExhausted(BatchStepResult $last): ?array
    {
        $this->log('onRetriesExhausted', $last);
        return self::$onExhausted ? (self::$onExhausted)($last) : parent::onRetriesExhausted($last);
    }

    protected function stillValid(): bool
    {
        if (self::$valid instanceof \Throwable) {
            throw self::$valid;
        }
        return self::$valid;
    }

    protected function retryDelay(int $failures): int
    {
        return self::$retryDelays[$failures] ?? parent::retryDelay($failures);
    }

    protected function overrun(): int
    {
        return self::hookValue('overrun') ?? parent::overrun();
    }

    protected function minStepSeconds(): int
    {
        return self::hookValue('minStepSeconds') ?? parent::minStepSeconds();
    }

    protected function stepPriority(): int
    {
        return self::hookValue('stepPriority') ?? parent::stepPriority();
    }

    protected function budgetLimits(): array
    {
        return self::hookValue('budgetLimits') ?? parent::budgetLimits();
    }

    /** the sealed secret, as the user's own code reads it */
    public function secret(): ?string
    {
        return $this->unseal();
    }

    protected static function sealPurpose(): string
    {
        return self::hookValue('sealPurpose') ?? parent::sealPurpose();
    }

    protected function progressExtra(): array
    {
        return self::$progressExtra;
    }

    protected static function expireExtra(int $now): void
    {
        self::$expiredAt[] = $now;
    }

    /** the test's value of $hook, null = the core's own; a Throwable is thrown */
    private static function hookValue(string $hook): mixed
    {
        $value = self::$hookValues[$hook] ?? null;
        if ($value instanceof \Throwable) {
            throw $value;
        }
        return $value;
    }

    protected static function now(): int
    {
        return self::$now ?? parent::now();
    }

    protected static function microNow(): float
    {
        return self::$microNow ?? parent::microNow();
    }

    protected function log(string $hook, mixed $arg = null): void
    {
        $locked        = (new \ReflectionProperty(BatchRun::class, 'locked'))->getValue($this);
        self::$calls[] = ['hook' => $hook, 'tx' => DB_AA::inTransaction(), 'running' => BatchStep::running()?->getId(), 'token' => (string) $this->lease_tok, 'locked' => $locked, 'arg' => $arg];
    }
}
