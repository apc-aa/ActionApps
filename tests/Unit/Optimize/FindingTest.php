<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckRunner;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;

final class FindingTest extends TestCase
{
    public function test_sort_puts_worst_first_then_most_frequent(): void
    {
        $sorted = CheckRunner::sort([
            new Finding(Finding::OK, 'fine'),
            new Finding(Finding::WARNING, 'few', count: 2),
            new Finding(Finding::INFO, 'hint'),
            new Finding(Finding::ERROR, 'broken'),
            new Finding(Finding::WARNING, 'many', count: 20),
        ]);
        $this->assertSame(['broken', 'many', 'few', 'hint', 'fine'], array_map(static fn(Finding $f) => $f->title, $sorted));
    }

    public function test_summary_counts_every_severity_even_when_absent(): void
    {
        $summary = Finding::summary([new Finding(Finding::ERROR, 'a'), new Finding(Finding::ERROR, 'b'), new Finding(Finding::OK, 'c')]);
        $this->assertSame(['error' => 2, 'warning' => 0, 'info' => 0, 'ok' => 1], $summary);
        $this->assertSame(['error' => 0, 'warning' => 0, 'info' => 0, 'ok' => 0], Finding::summary([]));
    }

    public function test_has_error_and_is_problem(): void
    {
        $this->assertTrue(Finding::hasError([new Finding(Finding::OK, 'a'), new Finding(Finding::ERROR, 'b')]));
        $this->assertFalse(Finding::hasError([new Finding(Finding::WARNING, 'a')]));
        $this->assertTrue((new Finding(Finding::INFO, 'a'))->isProblem());
        $this->assertFalse((new Finding(Finding::OK, 'a'))->isProblem());
    }
}
