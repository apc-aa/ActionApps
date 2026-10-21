<?php

declare(strict_types=1);

/**
 *
 * PHP version 8.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 */

namespace AA\Later;

use AA;
use AA\IO\DB\DB_AA;
use AA_Slice;

/**
 * Closes the clock-driven gap in page-cache invalidation.
 *
 * The page cache is invalidated only when something is WRITTEN
 * (ItemContent::storeItem() -> AA::Pagecache()->invalidateFor($slice_id)).
 * An active item also changes its public visibility purely by the clock: it
 * disappears when expiry_date passes and appears when publish_date passes
 * (CreateBinCondition() in include/util.php). Nothing invalidated the cache at
 * those moments, so a cached page could show an expired item for up to CACHE_TTL.
 *
 * Stateless periodic sweep - no watermark. Each run at time S processes the
 * transitions E (expiry_date / publish_date of active items) with
 *   max(S - SETTLE - CACHE_TTL, min(pagecache.stored) - SETTLE) < E <= S - SETTLE
 * and deletes, per slice, the cached pages tagged M=<slice> that were stored
 * before max(E of the slice) + SETTLE. Pages stored later were rendered without
 * the expired (with the published) item and survive, so a repeated run over the
 * same window finds nothing (idempotent). Transitions older than the window only
 * concern pages that PageCache::getById() no longer serves or that do not exist
 * any more, so nothing is lost between runs, after a cron outage or when two
 * runners overlap.
 *
 * Only dates after the item's post_date count: a date the item had reached when it
 * was created needs no sweep, creating it invalidated the slice (storeItem()). A
 * comment is published the moment it is posted, and sweeping it would delete the
 * pages rendered since - on a busy discussion every page twice. (post_date, not
 * last_edit: last_edit is NULL on items inserted without that field.)
 *
 * The bound is the real date value, whatever the time of day: every render path
 * switches the item at E at the latest (CreateBinCondition() rounds `now` UP to
 * QUERY_DATE_STEP, so it switches even earlier), and a render that started before
 * E is stored within SETTLE seconds.
 *
 * Slices that disable the check statically (nocheck_expired / nocheck_pending = 1)
 * are skipped for that kind of transition. An AA expression in those settings is
 * request-dependent and cannot be decided here, so it counts as "check".
 *
 * Known limits (a page hitting one of these still ages out by CACHE_TTL only):
 *  - the nocheck_* skip follows the ACTIVE-bin branch of CreateBinCondition(); an
 *    archive (bin=EXPIRED), an "upcoming" view (bin=PENDING) or a {tree} compares
 *    the dates even on a nocheck slice, so such a page on such a slice is not swept
 *  - polls carry their own publish_date / expiry_date (modules/polls) - not swept
 *  - a fragment cached at DB level for CACHE_TTL ({dictionary:...} pairs) can be
 *    stale when a page is rendered after the transition but before the sweep
 *  - a render taking longer than SETTLE is stored past the bound and survives
 *  - a render that read the slice before an item was created and is stored after
 *    the creation invalidated the slice lacks the item - as after any other write
 *  - post_date is set on insert only; an edit or import moving it past a pending
 *    publish / expiry date hides that transition from the sweep
 *
 * Scheduled from ScheduleAaTasks every PAGECACHE_DATE_SWEEP_INTERVAL seconds
 * (effective period: interval + up to two cron ticks, measured from completion).
 */
class PagecacheDateSweep implements LaterInterface
{
    /** Every render that started before a transition is stored within SETTLE
     *  seconds (measured max MISS render 1.6 s). Used twice: transitions younger
     *  than SETTLE are left to the next run, and the per-slice deletion bound is
     *  max(transition) + SETTLE. */
    public const SETTLE = 60;

    /** Default re-schedule interval - see PAGECACHE_DATE_SWEEP_INTERVAL in config.php */
    public const DEFAULT_INTERVAL = 600;

    /** Seconds between runs; 0 = the sweep is not scheduled at all */
    public static function interval(): int
    {
        return defined('PAGECACHE_DATE_SWEEP_INTERVAL') ? (int) constant('PAGECACHE_DATE_SWEEP_INTERVAL') : self::DEFAULT_INTERVAL;
    }

    /** Scheduled only when the page cache is on and the interval is positive */
    public static function isEnabled(): bool
    {
        return self::interval() > 0 && defined('ENABLE_PAGE_CACHE') && constant('ENABLE_PAGE_CACHE');
    }

    /** @see \AA\Later\LaterInterface */
    public function toexecutelater($params = []): string
    {
        if (!self::isEnabled()) {
            return 'sweep disabled';
        }
        // select1() gives false when the table is empty, null when MIN() sees no row;
        // stored = 0 is a valid (very old) timestamp and must not read as "empty"
        $min_stored = DB_AA::select1('m', 'SELECT MIN(`stored`) AS m FROM `pagecache`');
        if ($min_stored === false || $min_stored === null || $min_stored === '') {
            return 'page cache empty';
        }
        [$from, $cutoff] = self::window(time(), AA::Pagecache()->getCacheTime(), self::SETTLE, (int) $min_stored);

        [$expiry, $publish] = self::loadTransitions($from, $cutoff);
        $plan = self::computeBounds($expiry, $publish, self::sliceFlags(...), self::SETTLE);

        $deleted = 0;
        foreach ($plan['groups'] as $bound => $sids) {
            $deleted += AA::Pagecache()->invalidateFor($sids, 'M', $bound);
        }
        return sprintf(
            'window %d..%d: %d transitions, %d slices, %d skipped (nocheck), %d pages selected for deletion',
            $from,
            $cutoff,
            $plan['transitions'],
            $plan['slices'],
            $plan['skipped'],
            $deleted,
        );
    }

    // ---- pure part (unit-tested without a database) --------------------------

    /** Window of transitions to process: $from < E <= $cutoff
     *
     * @param int $now        wall-clock time of the run
     * @param int $ttl        CACHE_TTL - getById() serves a page while stored > now - ttl, so a page
     *                        left stale by E (stored < E + settle) can still be served at S only when
     *                        E + settle > S - ttl; older transitions need no work
     * @param int $settle     self::SETTLE
     * @param int $min_stored oldest pagecache.stored - transitions older than that have no page left
     * @return array{0: int, 1: int} [$from, $cutoff]
     */
    public static function window(int $now, int $ttl, int $settle, int $min_stored): array
    {
        $cutoff = $now - $settle;
        return [max($cutoff - $ttl, $min_stored - $settle), $cutoff];
    }

    /** DB_AA where-list selecting active items whose $column lies in ($from, $cutoff]
     *  and after their post_date (see the class comment) */
    public static function whereFor(string $column, int $from, int $cutoff): array
    {
        return [['status_code', 1, 'i'], [$column, $from, '>'], [$column, $cutoff, '<='], [$column, 'post_date', 'j>']];
    }

    /** Which transitions change visibility on a slice - mirrors CreateBinCondition():
     *  the expiry clause is dropped only when nocheck_expired = 1 AND
     *  ALLOW_DISPLAY_EXPIRED_ITEMS, the pending clause whenever nocheck_pending = 1.
     *
     * @return array{expiry: bool, publish: bool} true = the check is active
     */
    public static function flagsFor(bool $no_expired_bin, bool $no_pending_bin, bool $expired_allowed): array
    {
        return ['expiry' => !($no_expired_bin && $expired_allowed), 'publish' => !$no_pending_bin];
    }

    /** Turns the transitions found in the window into one deletion bound per slice.
     *
     * @param array<string, list<int|string>> $expiry  slice id => expiry_date values in the window
     * @param array<string, list<int|string>> $publish slice id => publish_date values in the window
     * @param callable(string): array{expiry: bool, publish: bool} $flags see flagsFor()
     * @param int $settle self::SETTLE
     * @return array{groups: array<int, list<string>>, slices: int, transitions: int, skipped: int}
     *         groups: bound => slice ids whose pages stored before the bound are to be deleted (ascending)
     */
    public static function computeBounds(array $expiry, array $publish, callable $flags, int $settle): array
    {
        $groups = [];
        $slices = $transitions = $skipped = 0;
        foreach (array_keys($expiry + $publish) as $sid) {
            $exp = array_map('intval', $expiry[$sid] ?? []);
            $pub = array_map('intval', $publish[$sid] ?? []);
            $transitions += count($exp) + count($pub);
            $f = $flags($sid);
            $ts = array_merge($f['expiry'] ? $exp : [], $f['publish'] ? $pub : []);
            $skipped += count($exp) + count($pub) - count($ts);
            if ($ts) {
                $groups[max($ts) + $settle][] = $sid;
                $slices++;
            }
        }
        ksort($groups);
        return ['groups' => $groups, 'slices' => $slices, 'transitions' => $transitions, 'skipped' => $skipped];
    }

    // ---- thin database layer --------------------------------------------------

    /** Maintenance-only scan of item metadata (no content, no permissions, no items
     *  built) - the canonical GetItemContent()/AA_Items path cannot express a date
     *  window grouped by slice; same practice as the other cron tasks reading `item`
     *  directly (HitcounterStats, HitcounterUpdate).
     *
     * @return array{0: array<string, list<int|string>>, 1: array<string, list<int|string>>} [expiry, publish]
     */
    protected static function loadTransitions(int $from, int $cutoff): array
    {
        return [
            DB_AA::select(['sid' => '+t'], 'SELECT LOWER(HEX(`slice_id`)) AS sid, `expiry_date` AS t FROM `item`', self::whereFor('expiry_date', $from, $cutoff)),
            DB_AA::select(['sid' => '+t'], 'SELECT LOWER(HEX(`slice_id`)) AS sid, `publish_date` AS t FROM `item`', self::whereFor('publish_date', $from, $cutoff)),
        ];
    }

    /** flagsFor() of one slice, decided statically (cron has no request context).
     *  Deliberately not noCheckExpired()/noCheckPending() - they evaluate AA
     *  expressions via AA::Un(). Not a slice id (orphaned items) = nothing to do.
     *
     * @return array{expiry: bool, publish: bool}
     */
    protected static function sliceFlags(string $sid): array
    {
        $slice = AA_Slice::getModule($sid);
        if ($slice === null) {
            return ['expiry' => false, 'publish' => false];
        }
        $expired_allowed = defined('ALLOW_DISPLAY_EXPIRED_ITEMS') && constant('ALLOW_DISPLAY_EXPIRED_ITEMS');
        return self::flagsFor($slice->noExpiredBin(), $slice->noPendingBin(), $expired_allowed);
    }
}
