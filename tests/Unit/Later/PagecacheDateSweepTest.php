<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\IO\DB\DB_AA;
use AA\Later\PagecacheDateSweep;
use PHPUnit\Framework\TestCase;

/**
 * AA\Later\PagecacheDateSweep — the cron sweep that invalidates cached pages
 * after items expire / get published by the clock.
 *
 * The sweep is stateless: each run recomputes, from the item table and the
 * clock, one deletion bound per slice (max transition + SETTLE) and deletes only
 * the pages stored before it. These tests pin the pure part (window, where-list,
 * slice flags, bound computation) without a database; what has to stay wired a
 * certain way (registry line, static flag decision) is anchored on the source.
 */
final class PagecacheDateSweepTest extends TestCase
{
    private const SOURCE   = __DIR__ . '/../../../src/Later/PagecacheDateSweep.php';
    private const REGISTRY = __DIR__ . '/../../../src/Later/ScheduleAaTasks.php';

    private const A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const C = 'cccccccccccccccccccccccccccccccc';

    /** flags callback: every check active on every slice */
    private static function allChecks(): callable
    {
        return static fn(string $sid): array => ['expiry' => true, 'publish' => true];
    }

    /** production source with comments and whitespace removed (tokenizer-based) - for "must not use" anchors */
    private static function code(): string
    {
        return php_strip_whitespace(self::SOURCE);
    }

    // --- window() ---

    public function test_window_is_ttl_wide_and_ends_settle_seconds_ago(): void
    {
        $this->assertSame([989_140, 999_940], PagecacheDateSweep::window(1_000_000, 10800, 60, 0));
    }

    public function test_window_starts_at_oldest_cached_page_when_that_is_younger_than_ttl(): void
    {
        $this->assertSame(
            [994_940, 999_940],
            PagecacheDateSweep::window(1_000_000, 10800, 60, 995_000),
            'a transition older than min(stored) - settle has no page left to delete',
        );
    }

    public function test_window_ignores_oldest_page_older_than_ttl(): void
    {
        $this->assertSame([989_140, 999_940], PagecacheDateSweep::window(1_000_000, 10800, 60, 900_000));
    }

    public function test_window_is_empty_when_oldest_page_lies_in_the_future(): void
    {
        [$from, $cutoff] = PagecacheDateSweep::window(1_000_000, 10800, 60, 1_100_000);
        $this->assertGreaterThanOrEqual($cutoff, $from, 'clock skew must not select anything');
    }

    // --- whereFor() ---

    public function test_where_list_selects_active_items_in_the_half_open_window(): void
    {
        $where = PagecacheDateSweep::whereFor('expiry_date', 100, 200);
        $this->assertSame(
            'WHERE status_code = 1 AND expiry_date > 100 AND expiry_date <= 200 AND expiry_date > post_date',
            DB_AA::makeWhere($where),
            'E = cutoff is processed now, E = from was fully covered by earlier runs / is no longer served; '
            . 'a date reached at creation (a comment: publish_date = post_date) was invalidated by storeItem() then',
        );
    }

    // --- flagsFor() - the two branches of CreateBinCondition() ---

    public function test_expiry_check_is_dropped_only_together_with_allow_display_expired_items(): void
    {
        $this->assertSame(['expiry' => false, 'publish' => true], PagecacheDateSweep::flagsFor(true, false, true));
        $this->assertSame(['expiry' => true, 'publish' => true], PagecacheDateSweep::flagsFor(true, false, false), 'nocheck_expired alone does not drop the clause');
    }

    public function test_pending_check_is_dropped_by_nocheck_pending_alone(): void
    {
        $this->assertSame(['expiry' => true, 'publish' => false], PagecacheDateSweep::flagsFor(false, true, false));
        $this->assertSame(['expiry' => true, 'publish' => true], PagecacheDateSweep::flagsFor(false, false, true));
    }

    // --- computeBounds() ---

    public function test_empty_window_yields_no_groups(): void
    {
        $plan = PagecacheDateSweep::computeBounds([], [], self::allChecks(), 60);
        $this->assertSame(['groups' => [], 'slices' => 0, 'transitions' => 0, 'skipped' => 0], $plan);
    }

    public function test_expiry_only_bound_is_latest_expiry_plus_settle(): void
    {
        $plan = PagecacheDateSweep::computeBounds([self::A => [500, 900, 700]], [], self::allChecks(), 60);
        $this->assertSame(['groups' => [960 => [self::A]], 'slices' => 1, 'transitions' => 3, 'skipped' => 0], $plan);
    }

    public function test_publish_only_bound_is_latest_publish_plus_settle(): void
    {
        $plan = PagecacheDateSweep::computeBounds([], [self::A => ['800']], self::allChecks(), 60);
        $this->assertSame([860 => [self::A]], $plan['groups'], 'string timestamps from PDO are cast');
    }

    public function test_bound_is_the_max_over_both_kinds(): void
    {
        $plan = PagecacheDateSweep::computeBounds([self::A => [900]], [self::A => [950]], self::allChecks(), 60);
        $this->assertSame([1010 => [self::A]], $plan['groups']);
        $this->assertSame(2, $plan['transitions']);
    }

    public function test_nocheck_expired_skips_expiry_transitions_but_keeps_publish_ones(): void
    {
        $flags = static fn(string $sid): array => ['expiry' => false, 'publish' => true];
        $plan  = PagecacheDateSweep::computeBounds([self::A => [900, 950]], [self::A => [700]], $flags, 60);
        $this->assertSame([760 => [self::A]], $plan['groups'], 'only the publish transition counts');
        $this->assertSame(3, $plan['transitions']);
        $this->assertSame(2, $plan['skipped']);
    }

    public function test_slice_with_both_checks_disabled_is_absent(): void
    {
        $flags = static fn(string $sid): array => ['expiry' => false, 'publish' => false];
        $plan  = PagecacheDateSweep::computeBounds([self::A => [900]], [self::A => [700]], $flags, 60);
        $this->assertSame(['groups' => [], 'slices' => 0, 'transitions' => 2, 'skipped' => 2], $plan);
    }

    public function test_slices_with_the_same_bound_share_one_group(): void
    {
        $plan = PagecacheDateSweep::computeBounds([self::A => [900], self::B => [900]], [self::C => [100]], self::allChecks(), 60);
        $this->assertSame([160 => [self::C], 960 => [self::A, self::B]], $plan['groups'], 'one invalidateFor() per bound, ascending');
        $this->assertSame(3, $plan['slices']);
    }

    // --- toexecutelater() / interval() / isEnabled() ---

    public function test_task_is_disabled_without_page_cache(): void
    {
        $this->assertFalse(ENABLE_PAGE_CACHE, 'tests/bootstrap.php disables the page cache');
        $this->assertFalse(PagecacheDateSweep::isEnabled());
        $this->assertSame('sweep disabled', (new PagecacheDateSweep())->toexecutelater(), 'no database is touched');
    }

    public function test_default_interval_is_ten_minutes(): void
    {
        $this->assertFalse(defined('PAGECACHE_DATE_SWEEP_INTERVAL'));
        $this->assertSame(600, PagecacheDateSweep::interval());
        $this->assertSame(600, PagecacheDateSweep::DEFAULT_INTERVAL);
    }

    // --- anchors on the production source ---

    public function test_registry_schedules_the_sweep_like_the_other_maintenance_tasks(): void
    {
        $registry = file_get_contents(self::REGISTRY);
        $this->assertIsString($registry);
        $this->assertStringContainsString('if (PagecacheDateSweep::isEnabled())', $registry, 'one predicate decides scheduling');
        $this->assertStringContainsString(
            "laterOnce(new PagecacheDateSweep(), [], 'PagecacheDateSweep', 101,",
            $registry,
            'ScheduleAaTasks::planAaTasks() must (re)schedule the sweep on every queue tick',
        );
    }

    public function test_slice_flags_are_decided_statically(): void
    {
        $code = self::code();
        $this->assertStringContainsString('noExpiredBin()', $code);
        $this->assertStringContainsString('noPendingBin()', $code);
        $this->assertDoesNotMatchRegularExpression('/noCheck(Expired|Pending)\(/', $code, 'those evaluate AA expressions via AA::Un() - not decidable in cron');
    }

    public function test_sql_side_uses_the_bounded_invalidation_and_the_cache_own_ttl(): void
    {
        $src = file_get_contents(self::SOURCE);
        $this->assertIsString($src);
        $this->assertStringContainsString("->invalidateFor(\$sids, 'M', \$bound)", $src, 'the single canonical invalidation path, bounded by stored');
        $this->assertStringContainsString('SELECT MIN(`stored`) AS m FROM `pagecache`', $src);
        $this->assertStringContainsString('AA::Pagecache()->getCacheTime()', $src, 'the window mirrors what getById() serves');

        $code = self::code();
        $this->assertStringNotContainsString("'CACHE_TTL'", $code, 'the TTL comes from the PageCache instance, not a second read of the constant');
        $this->assertStringNotContainsString('QUERY_DATE_STEP', $code, 'the bound is the real date value, no step arithmetic');
    }
}
