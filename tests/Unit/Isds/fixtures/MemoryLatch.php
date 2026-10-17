<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA\Isds\LoginLatch;

/**
 * LoginLatch over rows in memory instead of the AA log. A new latch over the rows of an old one is
 * the next run: it reads what the earlier run wrote.
 */
final class MemoryLatch extends LoginLatch
{
    /** @param list<array{selector: string, params: string, time: int}> $rows as the log would keep them */
    public function __construct(public array $rows = [], public int $now = 1790841600) {}

    /** the next run, over what this one wrote */
    public function nextRun(int $later = 3600): self
    {
        return new self($this->rows, $this->now + $later);
    }

    protected function last(string $selector): ?array
    {
        $last = null;
        foreach ($this->rows as $row) {
            if ($row['selector'] === $selector) {
                $last = ['what' => $row['params'], 'time' => $row['time']];
            }
        }

        return $last;
    }

    protected function record(string $selector, string $what): void
    {
        $this->rows[] = ['selector' => $selector, 'params' => $what, 'time' => $this->now];
    }

    protected function now(): int
    {
        return $this->now;
    }
}
