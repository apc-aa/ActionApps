<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\Later\NightSlot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Daily maintenance tasks run at a night moment of their own instead of "a day after the last
 * run", which drifted with every wait in the queue.
 */
final class NightSlotTest extends TestCase
{
    private const REGISTRY = __DIR__ . '/../../../src/Later/ScheduleAaTasks.php';

    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Prague');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    #[DataProvider('moments')]
    public function test_next_isTonightWhileAheadElseTomorrow(string $now, string $day): void
    {
        $slot = date('H:i:s', NightSlot::on('today', (int) strtotime('2026-11-23 12:00'), 'aadb:MissCachePurge'));
        $this->assertSame("$day $slot", date('Y-m-d H:i:s', NightSlot::next((int) strtotime(str_replace('SLOT', $slot, $now)), 'aadb:MissCachePurge')));
    }

    public static function moments(): array
    {
        return [
            'before midnight: the coming night' => ['2026-11-23 23:30:00', '2026-11-24'],
            'after midnight, before the slot'   => ['2026-11-24 00:30:00', '2026-11-24'],
            'at the slot: not again tonight'    => ['2026-11-24 SLOT', '2026-11-25'],
            'ran late in the afternoon'         => ['2026-11-24 14:50:00', '2026-11-25'],
        ];
    }

    public function test_on_staysInTheWindowAndApartPerKey(): void
    {
        $now     = (int) strtotime('2026-11-23 12:00');
        $moments = [];
        foreach (['aadb', 'aadb_utf', 'NSZMsql1'] as $db) {
            foreach (['MissCachePurge', 'StateCheck', 'LogCleanup', 'ToexecuteCleanup'] as $task) {
                $at = NightSlot::on('tomorrow', $now, "$db:$task");
                $this->assertGreaterThanOrEqual(strtotime('2026-11-24 01:00'), $at);
                $this->assertLessThan(strtotime('2026-11-24 04:00'), $at);
                $moments[] = $at;
            }
        }
        $this->assertGreaterThan(10, count(array_unique($moments)), 'tasks and databases do not all start at once');
        $this->assertSame(
            date('H:i:s', NightSlot::on('tomorrow', $now, 'aadb:StateCheck')),
            date('H:i:s', NightSlot::on('tomorrow', $now + 3 * 86400, 'aadb:StateCheck')),
            'the same moment night after night',
        );
    }

    public function test_daily_maintenance_is_planned_in_the_night(): void
    {
        $registry = (string) file_get_contents(self::REGISTRY);
        $this->assertStringContainsString("NightSlot::next(now(), constant('DB_NAME')", $registry, 'a moment of its own per database');
        foreach (['LogCleanup', 'StateCheck', 'ToexecuteCleanup', 'MissCachePurge'] as $task) {
            $this->assertMatchesRegularExpression("/[\"']{$task}[\"'], \\d+, \\\$night\\('{$task}'\\)\\);/", $registry, "$task runs in the night");
        }
    }
}
