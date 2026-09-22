<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\Later\AlertsSweep;
use PHPUnit\Framework\TestCase;

/**
 * Since 2026-09 every periodic AA job is a queue task and the three cron
 * entry points share Toexecute::runQueue(). The wiring cannot run without a
 * database, so it is anchored on the source: one code path, every task planned.
 */
final class CronEntryPointsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../';

    private static function source(string $file): string
    {
        return php_strip_whitespace(self::ROOT . $file);   // comments may mention what the code must not do
    }

    public function test_every_entry_point_runs_the_queue_through_run_queue(): void
    {
        $this->assertStringContainsString("Toexecute::runQueue('cron.php')", self::source('cron.php'));
        $this->assertStringContainsString("Toexecute::runQueue('toexecute.php')", self::source('misc/toexecute.php'));
        $this->assertStringContainsString('Toexecute::runQueue(null,', self::source('src/Later/CronFallback.php'), 'the fallback logs no start row (the rows tell whether OS cron is alive) and passes its interval gate');

        foreach (['cron.php', 'misc/toexecute.php', 'src/Later/CronFallback.php'] as $file) {
            $this->assertStringNotContainsString('->execute()', self::source($file), "$file must not run the queue on its own");
            $this->assertStringNotContainsString('planAaTasks', self::source($file), "$file must not plan on its own");
        }
    }

    public function test_the_former_cron_rows_are_planned(): void
    {
        $registry = self::source('src/Later/ScheduleAaTasks.php');
        foreach (['AlertsSweep', 'FeedsRun', 'LinkcheckRun', 'OptimizeTables', 'AA_Mysqlauth_Maintain'] as $task) {
            $this->assertStringContainsString("new $task(", $registry, "$task was a cron table row - it has to be planned");
        }
        $this->assertStringNotContainsString('INSERT INTO cron', self::source('src/Optimize/RemoveAaCronEntries.php'));
    }

    public function test_alerts_schedule_covers_the_subscription_frequencies_and_points_to_the_future(): void
    {
        $this->assertSame(['instant', 'daily', 'weekly', 'twoweeks', 'monthly'], array_keys(AlertsSweep::SCHEDULE));
        $now = time();
        foreach (AlertsSweep::SCHEDULE as $howoften => $when) {
            $time = strtotime($when, $now);
            $this->assertNotFalse($time, "$howoften: '$when' is not a strtotime() format");
            $this->assertGreaterThan($now, $time, "$howoften: laterOnce() would run it at once");
        }
        $this->assertLessThanOrEqual($now + 32 * 86400, strtotime(AlertsSweep::SCHEDULE['monthly'], $now));
    }
}
