<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\AA_Packtime;
use PHPUnit\Framework\TestCase;

class HitcounterTest extends TestCase
{
    public function test_getAsInt_aMeasuredHitNeverPacksToZero(): void
    {
        // 0 marks a hit stored without its time; an uncached view can take under 2 ms
        $this->assertSame(1 * 4, (new AA_Packtime(0.001, false, false))->getAsInt());
        $this->assertSame(0 * 4 + 2, (new AA_Packtime(0.001, true, false))->getAsInt());
        $this->assertSame(30 * 4 + 3, (new AA_Packtime(AA_Packtime::scale()->index2middle(30), true, true))->getAsInt());
    }

    public function test_packMean_ofATinyMeanStaysMeasured(): void
    {
        $this->assertSame(1 * 4, AA_Packtime::packMean(0.0005, 0));
        $this->assertSame(0 * 4 + 1, AA_Packtime::packMean(0.0005, 1));
    }

    public function test_seconds_isTheMiddleOfTheStoredIndex(): void
    {
        $this->assertSame(AA_Packtime::scale()->index2middle(30), AA_Packtime::seconds(30 * 4 + 2));
        $this->assertSame(AA_Packtime::scale()->index2middle(30), AA_Packtime::seconds(30 * 4));
    }
}
