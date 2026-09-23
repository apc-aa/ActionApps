<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\Later\CronFallback;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shot stamp only throttles how often a request fires the runner - it must
 * never be able to stop the fallback for good (a stamp nobody deletes, a clock
 * skewed between the GlusterFS nodes, a file PHP cannot read).
 */
final class CronFallbackTest extends TestCase
{
    public static function shotStamps(): array
    {
        return [
            'no stamp yet'                 => [false, true],
            'fired just now'               => [1000, false],
            'fired one second ago'         => [999, false],
            'fired interval - 1 ago'       => [881, false],
            'fired exactly interval ago'   => [880, true],
            'stamp left for years'         => [1, true],
            'another node 5 s ahead'       => [1005, false],
            'future by exactly interval'   => [1120, false],
            'future by more than interval' => [1121, true],
            'stamp touched to 2030'        => [1_900_000_000, true],
        ];
    }

    #[DataProvider('shotStamps')]
    public function test_isShotDue_never_blocks_for_good(int|false $stamp, bool $due): void
    {
        $this->assertSame($due, CronFallback::isShotDue(1000, $stamp, 120));
    }
}
