<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\Run;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The counts of a run as its record keeps them - State Check reads them without loading the run. */
final class RunCountsTest extends TestCase
{
    private const ZERO = ['read' => 0, 'new' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'jobs' => 0, 'streams' => 0, 'unchanged' => 0, 'skipped_jobs' => 0];

    /** @return array<string, array{string, array<string, int>}> */
    public static function stored(): array
    {
        return [
            'empty'        => ['', self::ZERO],
            'some'         => ['{"read":10,"errors":3}', array_replace(self::ZERO, ['read' => 10, 'errors' => 3])],
            'unknown key'  => ['{"bogus":5,"new":"2"}', array_replace(self::ZERO, ['new' => 2])],
            'damaged'      => ['{"read":', self::ZERO],
            'not a object' => ['7', self::ZERO],
        ];
    }

    /** @param array<string, int> $counts */
    #[DataProvider('stored')]
    public function test_countsOf(string $json, array $counts): void
    {
        $this->assertSame($counts, Run::countsOf($json));
    }

    public function test_states_named(): void
    {
        $this->assertSame(['running', 'error', 'done', 'ended'], [Run::RUNNING, Run::ERROR, Run::DONE, Run::ENDED]);
    }
}
