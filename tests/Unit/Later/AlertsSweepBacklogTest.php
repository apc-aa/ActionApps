<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\Later\AlertsSweep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A sweep reaches back to alerts_collection_howoften.last. When it stops - a missing cron
 * row, a moved install - that value stays put, and the run which finally happens mails
 * everything since. Doložené 2026-09-22: toolkit's daily sweep resumed after 13 years and
 * sent 52 readers items from 2014.
 */
final class AlertsSweepBacklogTest extends TestCase
{
    private const NOW = 1790000000;

    /** @return array<string,array{string,int}> the frequencies which carry a period of their own */
    public static function cappedFrequencies(): array
    {
        $cases = [];
        foreach (AlertsSweep::FREQUENCY as $howoften => $period) {
            $cases[$howoften] = [$howoften, $period];
        }
        return $cases;
    }

    #[DataProvider('cappedFrequencies')]
    public function test_a_sweep_reaches_back_two_periods_at_most(string $howoften, int $period): void
    {
        $this->assertSame(self::NOW - (2 * $period), AlertsSweep::earliestBacklog($howoften, self::NOW));
    }

    /** @return array<string,array{string}> */
    public static function periodlessFrequencies(): array
    {
        return ['instant' => ['instant'], 'irregular' => ['irregular'], 'unknown name' => ['nonsense']];
    }

    /** The instant sweep reads `last` exactly like a digest (empty $item_id), and toolkit has
     *  instant rows on 2012 and 2016 - "no period of its own" must not mean "no cap". */
    #[DataProvider('periodlessFrequencies')]
    public function test_a_frequency_without_a_period_gets_the_tightest_cap(string $howoften): void
    {
        $this->assertSame(
            self::NOW - (2 * min(AlertsSweep::FREQUENCY)),
            AlertsSweep::earliestBacklog($howoften, self::NOW),
        );
    }

    /** twoweeks sat in FREQUENCY but not in SCHEDULE, so the queue never swept it */
    public function test_every_frequency_with_a_period_is_also_planned(): void
    {
        $this->assertSame([], array_diff(array_keys(AlertsSweep::FREQUENCY), array_keys(AlertsSweep::SCHEDULE)));
    }

    // the call sites in the legacy sending code are anchored by
    // Tests\Unit\Legacy\AlertsSendingWindowTest, next to that file's other SQL anchors
}
