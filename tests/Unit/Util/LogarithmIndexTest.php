<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\AA_Packtime;
use AA\Util\LogarithmIndex;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LogarithmIndexTest extends TestCase
{
    public function test_packtimeScale_startsAtTwoMillisecondsAndEndsAtEightySeconds(): void
    {
        $index = AA_Packtime::scale();
        $this->assertEqualsWithDelta(0.002, $index->index2float(1), 1e-12);
        $this->assertEqualsWithDelta(80.0, $index->index2float(63), 1e-9);
        $this->assertSame(46, $index->getIndex(4.4));
    }

    public function test_calculateStep_isTheRatioOfNeighbouringIndexes(): void
    {
        $index = AA_Packtime::scale();
        $this->assertEqualsWithDelta($index->index2float(41) / $index->index2float(40), $index->calculateStep(), 1e-12);
    }

    public function test_index2middle_liesHalfwayAcrossTheBucket(): void
    {
        // getIndex() floors, so index 40 holds every value from index2float(40) up to index2float(41)
        $index = AA_Packtime::scale();
        $this->assertEqualsWithDelta(($index->index2float(40) + $index->index2float(41)) / 2, $index->index2middle(40), 1e-12);
        $this->assertSame(40, $index->getIndex($index->index2middle(40)));
    }

    public function test_exactIndex_invertsIndex2float(): void
    {
        $index = AA_Packtime::scale();
        $this->assertEqualsWithDelta(37.25, $index->exactIndex($index->index2float(37.25)), 1e-9);
    }

    #[DataProvider('nearestIndexProvider')]
    public function test_nearestIndex_picksTheBucketWhoseMiddleIsClosest(float $value, int $expected): void
    {
        $this->assertSame($expected, AA_Packtime::scale()->nearestIndex($value));
    }

    public static function nearestIndexProvider(): array
    {
        $index = AA_Packtime::scale();
        return [
            'a middle'          => [$index->index2middle(30), 30],
            'just above it'     => [$index->index2middle(30) * 1.05, 30],
            'closer to next'    => [$index->index2middle(30) * 1.15, 31],
            'below the scale'   => [0.0001, 0],
            'zero'              => [0.0, 0],
            'above the scale'   => [500.0, 63],
        ];
    }

    public function test_nearestIndex_ofAnArithmeticMeanKeepsTheSlowHits(): void
    {
        // 9 hits at 100 ms and 1 at 1.5 s: the time spent is 240 ms a hit, not the 131 ms
        // that averaging the logarithmic indexes gives
        $index  = AA_Packtime::scale();
        $fast   = $index->getIndex(0.1);
        $slow   = $index->getIndex(1.5);
        $mean   = (9 * $index->index2middle($fast) + $index->index2middle($slow)) / 10;
        $stored = $index->index2middle($index->nearestIndex($mean));
        // the stored middle is within half a bucket of the mean
        $this->assertEqualsWithDelta($mean, $stored, $mean * ($index->calculateStep() - 1) / 2);
        $this->assertGreaterThan(1.5 * $index->index2middle((9 * $fast + $slow) / 10), $stored);
    }

    #[DataProvider('sqlIndexProvider')]
    public function test_sqlMiddle_matchesIndex2middle(int $stored): void
    {
        $pdo = new PDO('sqlite::memory:');
        try {
            $pdo->query('SELECT exp(1)');
        } catch (\PDOException) {
            $this->markTestSkipped('SQLite without math functions');
        }
        $index = AA_Packtime::scale();
        // the stored byte keeps the index above two cache bits, as AA_Packtime packs it
        $packed = $stored * 4 + 3;
        $value  = $pdo->query('SELECT ' . $index->sqlMiddle("$packed >> 2"))->fetchColumn();
        $this->assertEqualsWithDelta($index->index2middle($stored), (float) $value, $index->index2middle($stored) * 1e-9);
    }

    public static function sqlIndexProvider(): array
    {
        return ['first' => [0], 'middle' => [31], 'last' => [63]];
    }

    public function test_sqlMiddle_writesTheConstantsAsFloatingPoint(): void
    {
        // a plain decimal literal would make MySQL compute every row in DECIMAL
        $this->assertMatchesRegularExpression('/^\(EXP\(\(x\) \* \d\.\d+E[+-]\d+\) \* \d\.\d+E[+-]\d+\)$/', AA_Packtime::scale()->sqlMiddle('x'));
    }
}
