<?php

declare(strict_types=1);

namespace Tests\Unit\Import\Fixtures;

use AA\Import\Context;
use AA\Import\Mapping;
use AA\Import\Run;
use AA\Import\SourceInterface;
use AA\IO\Grabber\AbstractGrabber;
use AA\IO\Saver;
use AA\Later\BatchBudget;
use AA\Later\BatchStepResult;
use AA;
use AA_Item;
use AA_Set;
use Symfony\Component\HttpClient\MockHttpClient;
use Tests\Unit\IO\Fixtures\RecordingSaver;
use zids;

require_once __DIR__ . '/../../IO/fixtures/RecordingSaver.php';
require_once __DIR__ . '/ScriptedSource.php';

/**
 * A Run over the test's source, with savers that record instead of storing, an HTTP connection
 * that never leaves the process and the test's clock. Each item a saver looks up by its key costs
 * $itemSeconds of that clock, so a test decides how much of a job fits in a step.
 */
final class TestRun extends Run
{
    public static ?int $now = null;
    public static ?float $microNow = null;
    public static float $itemSeconds = 0.0;
    /** null = the source of the snapshot, from the Registry */
    public static ?SourceInterface $source = null;
    /** @var array<string, string> key values => item id the savers' key lookup finds */
    public static array $existing = [];
    /** @var list<RecordingSaver> the savers of the jobs, in order */
    public static array $savers = [];
    /** @var list<BatchBudget> the budget of each work() */
    public static array $budgets = [];
    /** @var list<BatchStepResult> what each step handed to commitStep() */
    public static array $committed = [];
    /** @var list<BatchBudget> the budget of each Context made */
    public static array $contextBudgets = [];
    /** null = the core's */
    public static ?int $overrun = null;
    /** @var ?\Closure(string, AA_Item, string): void runs in place of the expression after a stream:
     *  expression, its item, AA::$slice_id; null = AA::Un() itself */
    public static ?\Closure $evaluate = null;
    /** @var list<array{string, array<string, mixed>, string}> the expressions run: expression, item fields, slice */
    public static array $evaluated = [];

    public static function reset(): void
    {
        self::$now       = null;
        self::$microNow  = null;
        self::$itemSeconds = 0.0;
        self::$source    = null;
        self::$existing  = [];
        self::$savers    = [];
        self::$budgets   = [];
        self::$committed = [];
        self::$contextBudgets = [];
        self::$overrun   = null;
        self::$evaluate  = null;
        self::$evaluated = [];
        ScriptedSource::$credentials = [];
    }

    /** @return list<array<string, list<string>>> what the savers stored: field => values of each item */
    public static function stored(): array
    {
        $items = [];
        foreach (self::$savers as $saver) {
            foreach ($saver->stored as $stored) {
                $items[] = array_map(
                    static fn(array $values): array => array_map(static fn($v): string => (string) (is_array($v) ? ($v['value'] ?? '') : $v), $values),
                    array_filter($stored['content']->getContent(), 'is_array'),
                );
            }
        }
        return $items;
    }

    /** @return list<string> the first value of $field of each stored item */
    public static function storedValues(string $field): array
    {
        return array_map(static fn(array $item): string => $item[$field][0] ?? '', self::stored());
    }

    protected function source(): SourceInterface
    {
        return self::$source ?? parent::source();
    }

    protected function saver(AbstractGrabber $g, array $transformations, Mapping $mapping): Saver
    {
        $saver = new class ($g, $transformations, $this->targetSlice(), $mapping->storeMode, $mapping->idMode()) extends RecordingSaver {
            protected function findByKey(AA_Set $set): zids
            {
                TestRun::$microNow = (TestRun::$microNow ?? 0.0) + TestRun::$itemSeconds;
                return parent::findByKey($set);
            }
        };
        $saver->existing = self::$existing;
        self::$savers[]  = $saver;
        return $saver;
    }

    protected function context(SourceInterface $source, BatchBudget $budget): Context
    {
        self::$contextBudgets[] = $budget;
        $offline = new MockHttpClient(static fn() => throw new \LogicException('A test run went to the network'));
        return new Context($source::credentials(), $budget, Context::DEFAULT_MAX_BYTES, $offline, static fn(): array => []);
    }

    protected function work(array $from, BatchBudget $budget, mixed $fetched): BatchStepResult
    {
        self::$budgets[] = $budget;
        return parent::work($from, $budget, $fetched);
    }

    /** one work() of a step from $from, outside the queue, with the step's 27 s */
    public function workOnce(array $from): BatchStepResult
    {
        return $this->work($from, new BatchBudget((self::$microNow ?? 0.0) + 27.0, 0, 0, static fn(): float => self::$microNow ?? 0.0), null);
    }

    /** onRetriesExhausted() called directly, as the core calls it after three steps without progress */
    public function exhausted(BatchStepResult $last): ?BatchStepResult
    {
        return $this->onRetriesExhausted($last);
    }

    protected function commitStep(BatchStepResult $r): void
    {
        self::$committed[] = $r;
        parent::commitStep($r);
    }

    protected function unalias(string $expression, AA_Item $item): void
    {
        $fields = [];
        foreach ($item->getContent() as $name => $values) {
            $fields[(string) $name] = is_array($values) ? array_map(static fn($v) => is_array($v) ? ($v['value'] ?? '') : $v, $values) : $values;
        }
        self::$evaluated[] = [$expression, $fields, (string) AA::$slice_id];
        if (self::$evaluate) {
            (self::$evaluate)($expression, $item, (string) AA::$slice_id);
            return;
        }
        parent::unalias($expression, $item);
    }

    protected function overrun(): int
    {
        return self::$overrun ?? parent::overrun();
    }

    protected static function now(): int
    {
        return self::$now ?? parent::now();
    }

    protected static function microNow(): float
    {
        return self::$microNow ?? parent::microNow();
    }
}
