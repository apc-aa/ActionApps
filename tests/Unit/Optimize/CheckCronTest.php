<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckCron;
use PHPUnit\Framework\TestCase;

final class CheckCronTest extends TestCase
{
    private function row(array $override = []): array
    {
        return $override + ['id' => 1, 'minutes' => '0-60/2', 'hours' => '*', 'mday' => '*', 'mon' => '*', 'wday' => '*', 'script' => 'misc/toexecute.php', 'params' => '', 'last_run' => null];
    }

    public function test_standard_schedules_are_valid(): void
    {
        $this->assertSame([], CheckCron::invalidFields($this->row()));
        $this->assertSame([], CheckCron::invalidFields($this->row(['minutes' => '38', 'hours' => '2', 'wday' => '2'])));
    }

    public function test_garbage_fields_are_named(): void
    {
        // the real AdamsBeauty row: keyboard mash in minutes, emoji in hours
        $this->assertSame(['minutes', 'hours'], CheckCron::invalidFields($this->row(['minutes' => 'asdfghjkl0/5', 'hours' => "\u{1F600}"])));
        $this->assertSame(['mday'], CheckCron::invalidFields($this->row(['mday' => ''])));
    }

    public function test_php3_and_php_rows_are_one_script(): void
    {
        $rows = [
            $this->row(['script' => 'misc/toexecute.php3']),
            $this->row(['script' => 'misc/toexecute.php']),
            $this->row(['script' => 'admin/xmlclient.php']),
            $this->row(['script' => 'modules/alerts/alerts.php', 'params' => 'howoften=daily']),
            $this->row(['script' => 'modules/alerts/alerts.php', 'params' => 'howoften=instant']),
            $this->row(['script' => 'modules/alerts/alerts.php3', 'params' => 'howoften=instant']),
        ];
        $this->assertSame(['misc/toexecute.php' => 2, 'modules/alerts/alerts.php howoften=instant' => 2], CheckCron::duplicateScripts($rows));
    }

    public function test_expected_period_reads_the_coarsest_field(): void
    {
        $this->assertSame(120, CheckCron::expectedPeriod($this->row()));
        $this->assertSame(300, CheckCron::expectedPeriod($this->row(['minutes' => '*/5'])));
        $this->assertSame(60, CheckCron::expectedPeriod($this->row(['minutes' => '*'])));
        $this->assertSame(3600, CheckCron::expectedPeriod($this->row(['minutes' => '15,45'])));
        $this->assertSame(6 * 3600, CheckCron::expectedPeriod($this->row(['minutes' => '0', 'hours' => '*/6'])));
        $this->assertSame(86400, CheckCron::expectedPeriod($this->row(['minutes' => '38', 'hours' => '2'])));
        $this->assertSame(7 * 86400, CheckCron::expectedPeriod($this->row(['minutes' => '38', 'hours' => '2', 'wday' => '2'])));
        $this->assertSame(60, CheckCron::expectedPeriod($this->row(['minutes' => '*/0'])), 'a zero step is not a division by zero');
    }

    public function test_stale_after_twice_the_period_but_never_within_an_hour(): void
    {
        $now = 1_000_000;
        $this->assertFalse(CheckCron::isStale($this->row(['last_run' => null]), $now), 'never ran is not stale');
        $this->assertFalse(CheckCron::isStale($this->row(['last_run' => $now - 2000]), $now), '2-minute row, 33 minutes ago: within the hour floor');
        $this->assertTrue(CheckCron::isStale($this->row(['last_run' => $now - 3601]), $now));
        $daily = $this->row(['minutes' => '38', 'hours' => '2']);
        $this->assertFalse(CheckCron::isStale($daily + ['last_run' => $now - 86400 * 2], $now));
        $this->assertTrue(CheckCron::isStale(['last_run' => $now - 86400 * 2 - 1] + $daily, $now));
    }
}
