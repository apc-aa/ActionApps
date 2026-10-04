<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\Later\BatchBudget;
use AA\Later\BatchBudgetSpent;
use PHPUnit\Framework\TestCase;

/** A step stops at whichever comes first: the time it may take or the volume it may write. */
final class BatchBudgetTest extends TestCase
{
    public function test_spent_after_deadline(): void
    {
        $this->assertTrue((new BatchBudget(microtime(true) - 0.5, 0, 0))->spent());
        $this->assertFalse((new BatchBudget(microtime(true) + 60, 0, 0))->spent());
    }

    public function test_spent_items_limit(): void
    {
        $budget = new BatchBudget(microtime(true) + 60, 500, 0);

        $this->assertFalse($budget->spent(499));
        $this->assertTrue($budget->spent(500));
    }

    public function test_spent_bytes_limit(): void
    {
        $budget = new BatchBudget(microtime(true) + 60, 0, 4_000_000);

        $this->assertFalse($budget->spent(10_000, 3_999_999));
        $this->assertTrue($budget->spent(0, 4_000_000));
    }

    public function test_spent_zero_limits_mean_none(): void
    {
        $this->assertFalse((new BatchBudget(microtime(true) + 60, 0, 0))->spent(PHP_INT_MAX, PHP_INT_MAX));
    }

    public function test_secondsLeft_counts_down_to_zero(): void
    {
        $left = (new BatchBudget(microtime(true) + 20, 0, 0))->secondsLeft();
        $this->assertGreaterThan(19.0, $left);
        $this->assertLessThanOrEqual(20.0, $left);

        $this->assertSame(0.0, (new BatchBudget(microtime(true) - 5, 0, 0))->secondsLeft(), 'a late step has no time, not negative time');
    }

    public function test_construct_rejects_negative_items(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BatchBudget(microtime(true) + 60, -1, 0);
    }

    public function test_construct_rejects_negative_bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BatchBudget(microtime(true) + 60, 0, -1);
    }

    public function test_spent_follows_given_clock(): void
    {
        $now    = 1000.0;
        $budget = new BatchBudget(1027.0, 0, 0, function () use (&$now): float {
            return $now;
        });

        $this->assertFalse($budget->spent());
        $this->assertSame(27.0, $budget->secondsLeft());

        $now = 1027.0;
        $this->assertTrue($budget->spent());
        $this->assertSame(0.0, $budget->secondsLeft());
    }

    public function test_httpTimeout_is_the_time_left(): void
    {
        $now    = 1000.0;
        $budget = new BatchBudget(1027.0, 0, 0, function () use (&$now): float {
            return $now;
        });

        $this->assertSame(27.0, $budget->httpTimeout());
        $now = 1026.5;
        $this->assertSame(0.5, $budget->httpTimeout());
    }

    /** @return array<string, array{float}> */
    public static function spentClocks(): array
    {
        return ['at the deadline' => [1027.0], 'past it' => [1030.0], 'less than curl counts' => [1026.9995]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('spentClocks')]
    public function test_httpTimeout_of_a_spent_budget_throws(float $now): void
    {
        $budget = new BatchBudget(1027.0, 0, 0, static fn(): float => $now);

        $this->expectException(BatchBudgetSpent::class);
        $budget->httpTimeout();             // 0 would be no timeout at all
    }
}
