<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\Later\OptimizeTables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AA\Later\OptimizeTables replaces misc/optimize.php (weekly OPTIMIZE TABLE,
 * fetched over HTTP with a piece of the DB password as the key). The pure parts
 * are the table choice and order and the night plan; what has to stay wired a
 * certain way - the plan, no password, resumable with a distinct selector - is
 * anchored on the source.
 */
final class OptimizeTablesTest extends TestCase
{
    private const SOURCE   = __DIR__ . '/../../../src/Later/OptimizeTables.php';
    private const REGISTRY = __DIR__ . '/../../../src/Later/ScheduleAaTasks.php';

    private string $timezone;

    /** a zone with summer time, to see the night plan across the switch */
    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Prague');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    public function test_only_schema_tables_that_exist_and_smallest_first(): void
    {
        $schema = ['pagecache', 'item', 'content', 'slice', 'tmp_never'];
        $sizes  = ['bck_item' => 5, 'item' => 300, 'pagecache' => 900_000, 'content' => 20_000, 'slice' => 300];

        $this->assertSame(['item', 'slice', 'content', 'pagecache'], OptimizeTables::order($schema, $sizes), 'bck_* is not in the schema, tmp_never is not in the DB; ties by name');
    }

    public function test_empty_database_gives_nothing_to_do(): void
    {
        $this->assertSame([], OptimizeTables::order(['item'], []));
    }

    public function test_worthRebuilding_weeklyTakesOnlyTablesWithSpaceToGiveBack(): void
    {
        $mb    = 1024 * 1024;
        $stats = [
            'pagecache'  => ['bytes' => 1479 * $mb, 'free' => 873 * $mb],   // NSZM after a week of purges
            'big_full'   => ['bytes' => 3569 * $mb, 'free' => 96 * $mb],    // 2.7 % free: a rebuild gives back little
            'small_hole' => ['bytes' => 10 * $mb, 'free' => 30 * $mb],      // most of it free, but only 30 MB
            'item'       => ['bytes' => '300', 'free' => '0'],              // information_schema gives strings
        ];
        $this->assertSame(['pagecache' => 1479 * $mb], OptimizeTables::worthRebuilding($stats, false));
        $this->assertSame(['pagecache' => 1479 * $mb, 'big_full' => 3569 * $mb, 'small_hole' => 10 * $mb, 'item' => 300], OptimizeTables::worthRebuilding($stats, true));
    }

    public function test_everyTable_onTheFirstSundayOfAMonth(): void
    {
        $this->assertTrue(OptimizeTables::everyTable((int) strtotime('2026-11-01 01:30')));
        $this->assertTrue(OptimizeTables::everyTable((int) strtotime('2026-10-04 03:00')));
        $this->assertFalse(OptimizeTables::everyTable((int) strtotime('2026-10-11 03:00')));
    }

    public function test_construct_rowQueuedByOlderCodeRunsTheWeeklyChoice(): void
    {
        $class  = OptimizeTables::class;
        $legacy = unserialize(sprintf('O:%d:"%s":1:{s:%d:"%s";a:0:{}}', strlen($class), $class, strlen("\0$class\0pending"), "\0$class\0pending"));
        $this->assertInstanceOf(OptimizeTables::class, $legacy);
        $this->assertFalse((new \ReflectionProperty(OptimizeTables::class, 'every_table'))->getValue($legacy));
    }

    #[DataProvider('plannedFrom')]
    public function test_weeklyAt_isSundayNight(string $now, string $sunday, string $db): void
    {
        $at = OptimizeTables::weeklyAt((int) strtotime($now), $db);
        $this->assertSame($sunday, date('Y-m-d', $at));
        $this->assertGreaterThanOrEqual(1, (int) date('G', $at));
        $this->assertLessThan(5, (int) date('G', $at));
    }

    public static function plannedFrom(): array
    {
        return [
            'monday daytime'             => ['2026-10-12 14:00', '2026-10-18', 'aadb'],
            'saturday late'              => ['2026-10-17 23:59', '2026-10-18', 'aadb'],
            'right after the run'        => ['2026-10-18 03:30', '2026-10-25', 'aadb'],
            'summer time ends'           => ['2026-10-24 12:00', '2026-10-25', 'aadb'],
            'summer time begins'         => ['2026-03-28 12:00', '2026-03-29', 'aadb'],
            'summer time begins, late'   => ['2026-03-28 12:00', '2026-03-29', 'sasovsql1'],   // 01:00 + 2:52 -> 04:52 CEST
        ];
    }

    public function test_weeklyAt_keepsEachDatabasesMoment(): void
    {
        $monday = (int) strtotime('2026-10-12 14:00');
        $first  = OptimizeTables::weeklyAt($monday, 'aadb');
        $this->assertSame(date('H:i:s', $first), date('H:i:s', OptimizeTables::weeklyAt($monday - 7 * 86400, 'aadb')), 'week after week (the next Sunday ends summer time and shifts it an hour)');
        $this->assertNotSame($first, OptimizeTables::weeklyAt($monday, 'aadb_utf'), 'databases on one server start apart');
    }

    #[DataProvider('followUps')]
    public function test_continueAt_waitsForTheNight(string $now, string $expected): void
    {
        $slot = date('H:i:s', OptimizeTables::weeklyAt((int) strtotime('2026-10-12 14:00'), 'aadb'));   // 03:16:58
        $this->assertSame(str_replace('SLOT', $slot, $expected), date('Y-m-d H:i:s', OptimizeTables::continueAt((int) strtotime($now), 'aadb')));
    }

    public static function followUps(): array
    {
        return [
            'at night: ten minutes later'   => ['2026-10-18 03:20:00', '2026-10-18 03:30:00'],
            'not past five'                 => ['2026-10-18 04:55:00', '2026-10-19 SLOT'],
            'started by day: tonight'       => ['2026-10-19 08:06:00', '2026-10-20 SLOT'],
            'late evening: not at 00:05'    => ['2026-10-19 23:55:00', '2026-10-20 SLOT'],
            'after midnight: tonight'       => ['2026-10-20 00:30:00', '2026-10-20 SLOT'],
        ];
    }

    public function test_is_planned_weekly_and_follow_up_uses_its_own_selector(): void
    {
        $registry = (string) file_get_contents(self::REGISTRY);
        $this->assertStringContainsString("\$optimize_at = OptimizeTables::weeklyAt(now(), constant('DB_NAME'));", $registry);
        $this->assertMatchesRegularExpression('/laterOnce\(new OptimizeTables\(\[\], OptimizeTables::everyTable\(\$optimize_at\)\), \[\], \'OptimizeTables\', \d+, \$optimize_at\)/', $registry);

        $this->assertNotSame('OptimizeTables', OptimizeTables::CONTINUE_SELECTOR, 'laterOnce() on the running task\'s selector would only move the claimed row, which is deleted when the task returns');
        $this->assertLessThan(59, OptimizeTables::BUDGET, 'a run must fit in the queue\'s allowed time (TOEXECUTE_ALLOWED_TIME 59 s)');
    }

    public function test_source_carries_no_password_and_no_http(): void
    {
        // php_strip_whitespace() drops comments on purpose: the class docblock itself
        // names DB_PASSWORD when describing what it replaced
        $code = php_strip_whitespace(self::SOURCE);
        $this->assertStringNotContainsString('DB_PASSWORD', $code);
        $this->assertStringNotContainsString('AA_INSTAL_URL', $code);
        $this->assertStringNotContainsString('fopen', $code);
    }
}
