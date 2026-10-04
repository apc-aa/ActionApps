<?php

declare(strict_types=1);

namespace AA\Later;

/** What one queue step may spend: wall time up to $deadline (microtime), and items or
 *  bytes written (0 = no limit). work() checks spent() and returns its position when true. */
final class BatchBudget
{
    /** @param ?\Closure(): float $now clock, microtime(true) by default; tests drive time with it */
    public function __construct(
        public readonly float $deadline,
        public readonly int $items = 0,
        public readonly int $bytes = 0,
        private readonly ?\Closure $now = null,
    ) {
        if (($items < 0) || ($bytes < 0)) {
            throw new \InvalidArgumentException('Batch budget limits must not be negative');
        }
    }

    public function spent(int $items = 0, int $bytes = 0): bool
    {
        return ($this->now() >= $this->deadline)
            || (($this->items > 0) && ($items >= $this->items))
            || (($this->bytes > 0) && ($bytes >= $this->bytes));
    }

    public function secondsLeft(): float
    {
        return max(0.0, $this->deadline - $this->now());
    }

    private function now(): float
    {
        return ($this->now === null) ? microtime(true) : (float) ($this->now)();
    }
}
