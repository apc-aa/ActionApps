<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * Characterization + regression tests for the "new items" selection window in
 * AA_Collection::createFilterText() (modules/alerts/alerts_sending.php).
 *
 * Instant alerts run as a cron sweep every ~5 min. Each sweep selects items that
 * became newly active since the stored cursor `last`, then advances `last := $now`.
 * The original window `moved2active BETWEEN $last AND $now` is inclusive on BOTH
 * ends, so the upper bound of one window (`$now`) equals the lower bound of the next
 * (`$last`). An item whose `moved2active` lands exactly on that shared boundary
 * second is selected — and emailed — in TWO consecutive sweeps (the 2026-06-23
 * double-send: a news item whose moved2active == the sweep second was sent to all
 * 492 readers twice).
 *
 * The fix makes the window half-open and defers the current (possibly still-open)
 * second: `moved2active >= $last AND moved2active < $now`. The boundary second is
 * processed by the NEXT sweep, when it is safely closed — so no item is sent twice
 * and none is lost (not even one inserted late within the boundary second, after
 * this sweep's SELECT already ran).
 *
 * createFilterText() is heavily DB-coupled (getDB/QueryZids/CVarset) and cannot be
 * invoked without a live DB here, so these tests (1) model the window predicate's
 * boundary semantics over two consecutive sweeps, and (2) anchor to the production
 * query's $where entries so a revert to the buggy BETWEEN form fails the suite.
 */
final class AlertsSendingWindowTest extends TestCase
{
    /** absolute path to the file under test */
    private const SOURCE = __DIR__ . '/../../../modules/alerts/alerts_sending.php';

    /**
     * Items selected by one cron sweep, for a given window variant.
     *
     * @param string $variant 'old' (current BETWEEN), 'new' (the fix), or
     *                        'bad' (the rejected `> $last AND <= $now` form)
     * @param int    $last    stored cursor (= previous sweep's $now)
     * @param int    $now     this sweep's time()
     * @param int[]  $visible moved2active values the sweep's SELECT can see right now
     * @return int[] moved2active values selected (= alert emailed) by this sweep
     */
    private function sweepSelect(string $variant, int $last, int $now, array $visible): array
    {
        return array_values(array_filter($visible, static fn(int $m2a): bool => match ($variant) {
            'old' => $m2a >= $last && $m2a <= $now,   // moved2active BETWEEN $last AND $now
            'new' => $m2a >= $last && $m2a <  $now,   // half-open: defer current second (the fix)
            'bad' => $m2a >  $last && $m2a <= $now,   // rejected: loses a late boundary insert
        }));
    }

    /**
     * How many times $boundaryItem is emailed across two consecutive sweeps
     * B (now=$nB) then C (now=$nC), starting with cursor at $nA. Sweep B advances
     * the cursor to $nB before sweep C runs (the production `last := $now`).
     *
     * @param int[] $visibleAtB moved2active values visible to sweep B's SELECT
     * @param int[] $visibleAtC moved2active values visible to sweep C's SELECT
     */
    private function sendsAcrossTwoSweeps(string $variant, int $nA, int $nB, int $nC, array $visibleAtB, array $visibleAtC, int $boundaryItem): int
    {
        $count  = in_array($boundaryItem, $this->sweepSelect($variant, $nA, $nB, $visibleAtB), true) ? 1 : 0;
        $count += in_array($boundaryItem, $this->sweepSelect($variant, $nB, $nC, $visibleAtC), true) ? 1 : 0;
        return $count;
    }

    public function testCurrentBetweenWindowDoubleSendsBoundaryItem(): void
    {
        // Reproduces 2026-06-23: item's moved2active == sweep B's second (= nB),
        // visible to both sweeps -> emailed twice.
        $sends = $this->sendsAcrossTwoSweeps('old', 100, 200, 300, [200], [200], 200);
        $this->assertSame(2, $sends, 'BETWEEN window (both-inclusive) double-sends the boundary item');
    }

    public function testHalfOpenWindowSendsBoundaryItemExactlyOnce(): void
    {
        $sends = $this->sendsAcrossTwoSweeps('new', 100, 200, 300, [200], [200], 200);
        $this->assertSame(1, $sends, 'half-open window emails the boundary item exactly once');
    }

    public function testHalfOpenWindowDoesNotLoseLateBoundaryInsert(): void
    {
        // Item inserted late in second nB, AFTER sweep B's SELECT -> not visible to B,
        // visible to C. The fix must still deliver it (deferred to C), not drop it.
        $sends = $this->sendsAcrossTwoSweeps('new', 100, 200, 300, [], [200], 200);
        $this->assertSame(1, $sends, 'half-open window still delivers a late boundary insert via the next sweep');
    }

    public function testRejectedExclusiveUpperWindowLosesLateBoundaryInsert(): void
    {
        // Why `> $last AND <= $now` was rejected: a late boundary insert is in
        // neither sweep -> lost forever.
        $sends = $this->sendsAcrossTwoSweeps('bad', 100, 200, 300, [], [200], 200);
        $this->assertSame(0, $sends, 'documents the rejected form: it loses the late boundary insert');
    }

    public function testMidIntervalItemUnaffectedAndSentOnce(): void
    {
        // A normal item well inside sweep B's window is sent once by old and new alike.
        foreach (['old', 'new'] as $variant) {
            $sends = $this->sendsAcrossTwoSweeps($variant, 100, 200, 300, [150], [150], 150);
            $this->assertSame(1, $sends, "mid-interval item sent exactly once ($variant)");
        }
    }

    public function testProductionSqlUsesHalfOpenWindowNotBetween(): void
    {
        $src = file_get_contents(self::SOURCE);
        $this->assertNotFalse($src, 'cannot read ' . self::SOURCE);

        $this->assertMatchesRegularExpression(
            '/\[\s*\'moved2active\'\s*,\s*\$last\s*,\s*\'>=\'\s*\]\s*,\s*\[\s*\'moved2active\'\s*,\s*\$now\s*,\s*\'<\'\s*\]/',
            $src,
            'createFilterText() must use the half-open window: [\'moved2active\', $last, \'>=\'], [\'moved2active\', $now, \'<\']',
        );
        $this->assertStringNotContainsString(
            'moved2active BETWEEN',
            $src,
            'the both-inclusive BETWEEN window (the double-send bug) must not return',
        );
    }

    /** The window's lower bound is capped (2026-09-22): a sweep that stopped for years
     *  would otherwise mail the whole backlog. Without the call the cap is dead code. */
    public function testProductionSqlCapsTheBacklogWindow(): void
    {
        $this->assertStringContainsString(
            'AlertsSweep::earliestBacklog(',
            php_strip_whitespace(self::SOURCE),
            'createFilterText() must clamp $last, or a stalled sweep mails everything since it stopped',
        );
    }

    /** $ho is interpolated into the howoften SQL unescaped and comes from POST on the
     *  admin "send now" forms, so send_emails() lets only the known frequencies through. */
    public function testOnlyKnownFrequenciesReachTheUnescapedSql(): void
    {
        $this->assertStringContainsString(
            'if (!is_string($ho) || !isset(get_howoften_options()[$ho])) {',
            php_strip_whitespace(self::SOURCE),
            'send_emails() must reject an unknown frequency before it reaches the SQL',
        );
    }
}
