<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\Later\HitcounterGroup;
use AA\Util\AA_Packtime;
use PHPUnit\Framework\TestCase;

class HitcounterGroupTest extends TestCase
{
    public function test_groupedRows_storeTheMeanTime(): void
    {
        // 9 uncached hits at index 30 and one at 46: the row must stand for their total time
        $scale   = AA_Packtime::scale();
        $seconds = 9 * $scale->index2middle(30) + $scale->index2middle(46);
        $rows    = HitcounterGroup::groupedRows([['id' => 7, 'state' => 0, 'hits' => 10, 'seconds' => $seconds]], 1000);

        $this->assertCount(1, $rows);
        $this->assertSame(['id' => 7, 'hits' => 10, 'time' => 1000], array_diff_key($rows[0], ['duration' => 0]));
        $this->assertSame(0, $rows[0]['duration'] & 3);
        $this->assertEqualsWithDelta($seconds, 10 * AA_Packtime::seconds($rows[0]['duration']), $seconds * ($scale->calculateStep() - 1) / 2);
    }

    public function test_groupedRows_keepTheCacheStateInTheLowBits(): void
    {
        $scale = AA_Packtime::scale();
        $rows  = HitcounterGroup::groupedRows([
            ['id' => 7, 'state' => 1, 'hits' => 2, 'seconds' => 2 * $scale->index2middle(20)],
            ['id' => 7, 'state' => 2, 'hits' => 3, 'seconds' => 3 * $scale->index2middle(5)],
            ['id' => 7, 'state' => 3, 'hits' => 1, 'seconds' => $scale->index2middle(4)],
        ], 1000);

        $this->assertSame([20 * 4 + 1, 5 * 4 + 2, 4 * 4 + 3], array_column($rows, 'duration'));
        $this->assertSame([2, 3, 1], array_column($rows, 'hits'));
    }

    public function test_groupedRows_keepHitsWithoutATimeApart(): void
    {
        $scale = AA_Packtime::scale();
        $rows  = HitcounterGroup::groupedRows([
            ['id' => 7, 'state' => -1, 'hits' => 5, 'seconds' => 5 * $scale->index2middle(0)],
            ['id' => 7, 'state' => 0, 'hits' => 2, 'seconds' => 2 * $scale->index2middle(30)],
        ], 1000);

        $this->assertSame([0, 30 * 4], array_column($rows, 'duration'));
        $this->assertSame([5, 2], array_column($rows, 'hits'));
    }
}
