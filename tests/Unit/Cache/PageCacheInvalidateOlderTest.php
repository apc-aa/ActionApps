<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for PageCache::invalidateOlder() — the time-based pagecache
 * purge, which silently stopped purging anything (see bugfixes/ZAVERY.md
 * 2026-09-01). Measured on aadev: 243 165 rows older than the cache time, oldest
 * `stored` from January 2025, table at 20.5 GB.
 *
 * The purge deletes str2find rows by an auto_increment prefix rather than by a
 * join ("takes ages", see AA\Later\PagecachePurge). That prefix trick is sound
 * only because CacheStr2find::store() DELETEs and re-INSERTs a key's str2find
 * rows on every store, so their ids are monotonic in pagecache.stored. Three
 * defects broke it:
 *
 *   1. the anchor row was picked with ORDER BY id DESC, but pagecache.id is an
 *      md5 hash — the "highest old" row was effectively random;
 *   2. its str2find id was read without an order, so the LOWEST id of that key
 *      was used as the cut, leaving the key's remaining rows behind while the
 *      parent pagecache row was deleted — manufacturing the very orphans that
 *      break the next run;
 *   3. both DELETEs sat inside `if ($highest_old_id)`, so a missing counterpart
 *      skipped the pagecache DELETE too, although it never depended on str2find.
 *
 * Defects 2+3 are self-reinforcing: orphans make the anchor more likely to have
 * no counterpart, which stops the purge, which lets orphans pile up. On aadev
 * ~75 % of pagecache rows had no counterpart and the row the code actually
 * picked (ffffff767ba639eac988dfcc5b848331) had zero.
 *
 * PageCache is DB-coupled (DB_AA statics + getDB), so these tests model the
 * two-table row algebra and anchor to the production source, per the
 * ToexecuteQueueRaceTest precedent.
 */
final class PageCacheInvalidateOlderTest extends TestCase
{
    private const SOURCE = __DIR__ . '/../../../src/Cache/PageCache.php';

    /** @var array<string, int> pagecache: id (md5 hash) => stored */
    private array $pagecache = [];

    /** @var array<int, string> pagecache_str2find: auto_increment id => pagecache_id */
    private array $str2find = [];

    private int $next_str2find_id = 1;

    /** One store() of $key at time $stored, with $keys str2find strings.
     *  Mirrors CacheStr2find::store(): delete this key's rows, re-insert them,
     *  so the new ids are always the highest so far.
     */
    private function store(string $key, int $stored, int $keys = 2): void
    {
        $this->pagecache[$key] = $stored;
        $this->str2find = array_filter($this->str2find, static fn($pid) => $pid !== $key);
        for ($i = 0; $i < $keys; $i++) {
            $this->str2find[$this->next_str2find_id++] = $key;
        }
    }

    /** SELECT id FROM pagecache WHERE stored < $time ORDER BY $by DESC LIMIT 1 */
    private function highestOldPid(int $time, string $by): ?string
    {
        $old = array_filter($this->pagecache, static fn($s) => $s < $time);
        if (!$old) {
            return null;
        }
        if ($by === 'id') {
            $ids = array_keys($old);
            rsort($ids);
            return $ids[0];
        }
        arsort($old);                     // by stored DESC
        return (string) array_key_first($old);
    }

    /** SELECT id FROM pagecache_str2find WHERE pagecache_id = $pid [ORDER BY id DESC] LIMIT 1 */
    private function str2findIdFor(?string $pid, bool $highest): ?int
    {
        if ($pid === null) {
            return null;
        }
        $ids = array_keys(array_filter($this->str2find, static fn($p) => $p === $pid));
        if (!$ids) {
            return null;
        }
        return $highest ? max($ids) : min($ids);
    }

    /** @param 'old'|'new' $variant */
    private function invalidateOlder(int $time, string $variant): void
    {
        if ($variant === 'old') {
            $pid = $this->highestOldPid($time, 'id');
            $cut = $this->str2findIdFor($pid, false);
            if ($cut) {
                $this->str2find  = array_filter($this->str2find, static fn($p, $id) => $id > $cut, ARRAY_FILTER_USE_BOTH);
                $this->pagecache = array_filter($this->pagecache, static fn($s) => $s >= $time);
            }
            return;
        }
        $pid = $this->highestOldPid($time, 'stored');
        $cut = $this->str2findIdFor($pid, true);
        if ($cut) {
            $this->str2find = array_filter($this->str2find, static fn($p, $id) => $id > $cut, ARRAY_FILTER_USE_BOTH);
        }
        $this->pagecache = array_filter($this->pagecache, static fn($s) => $s >= $time);
    }

    private function orphans(): array
    {
        return array_keys(array_filter($this->str2find, fn($pid) => !isset($this->pagecache[$pid])));
    }

    // --- defect 3: a counterpart-less anchor froze the whole purge ---

    public function test_old_purge_does_nothing_when_anchor_row_has_no_str2find(): void
    {
        $this->store('aaaa', 1000, 2);
        $this->store('ffff', 1100, 0);      // lexicographically highest, no counterpart
        $this->store('bbbb', 5000, 2);      // fresh

        $this->invalidateOlder(2000, 'old');

        $this->assertSame(['aaaa', 'ffff', 'bbbb'], array_keys($this->pagecache), 'old code purged nothing');
    }

    public function test_new_purge_removes_old_rows_even_without_counterpart(): void
    {
        $this->store('aaaa', 1000, 2);
        $this->store('ffff', 1100, 0);
        $this->store('bbbb', 5000, 2);

        $this->invalidateOlder(2000, 'new');

        $this->assertSame(['bbbb'], array_keys($this->pagecache), 'only the fresh row survives');
    }

    /** Documented residual limitation, NOT a regression: when the anchor page
     *  itself has no str2find rows there is no id to cut at, so older str2find
     *  rows stay behind. The single-anchor prefix trick cannot avoid this — a
     *  page may have zero str2find rows on either side of the cut-off, and the
     *  orphan-free alternative is the semi-join that AA\Later\PagecachePurge
     *  rejected as too slow. What matters is that this no longer stops the purge:
     *  pagecache is emptied regardless, so orphans stay bounded and cosmetic
     *  instead of freezing the whole run. AA\Optimize\FixPagecache clears them.
     */
    public function test_new_purge_may_leave_orphans_when_anchor_has_no_counterpart(): void
    {
        $this->store('aaaa', 1000, 2);
        $this->store('ffff', 1100, 0);
        $this->store('bbbb', 5000, 2);

        $this->invalidateOlder(2000, 'new');

        $this->assertSame([1, 2], $this->orphans(), 'known leftover, cleaned by FixPagecache');
        $this->assertSame(['bbbb'], array_keys($this->pagecache), 'but the purge itself still ran');
    }

    // --- defect 2: lowest-id cut orphaned the anchor key's own siblings ---

    public function test_old_purge_orphans_siblings_of_the_anchor_key(): void
    {
        $this->store('aaaa', 1000, 3);      // str2find ids 1,2,3
        $this->store('bbbb', 5000, 2);      // fresh

        $this->invalidateOlder(2000, 'old');

        $this->assertSame([], array_diff(array_keys($this->pagecache), ['bbbb']), 'aaaa was deleted');
        $this->assertNotSame([], $this->orphans(), 'old code left aaaa str2find rows behind');
    }

    public function test_new_purge_leaves_no_orphans(): void
    {
        $this->store('aaaa', 1000, 3);
        $this->store('bbbb', 5000, 2);

        $this->invalidateOlder(2000, 'new');

        $this->assertSame(['bbbb'], array_keys($this->pagecache));
        $this->assertSame([], $this->orphans());
    }

    // --- the prefix trick must never cut into fresh keys ---

    public function test_new_purge_keeps_every_str2find_row_of_fresh_keys(): void
    {
        $this->store('aaaa', 1000, 2);
        $this->store('cccc', 1500, 2);
        $this->store('bbbb', 5000, 3);
        $fresh = array_keys(array_filter($this->str2find, static fn($p) => $p === 'bbbb'));

        $this->invalidateOlder(2000, 'new');

        $this->assertSame($fresh, array_keys($this->str2find), 'fresh key keeps all its rows');
    }

    /** A key re-stored after the cut-off must survive: its str2find rows were
     *  re-inserted with fresh ids, so the prefix delete must not reach them. */
    public function test_new_purge_survives_restore_of_an_old_key(): void
    {
        $this->store('aaaa', 1000, 2);
        $this->store('cccc', 1200, 2);
        $this->store('aaaa', 6000, 2);      // re-stored => fresh ids

        $this->invalidateOlder(2000, 'new');

        $this->assertSame(['aaaa'], array_keys($this->pagecache));
        $this->assertSame(['aaaa', 'aaaa'], array_values($this->str2find));
        $this->assertSame([], $this->orphans());
    }

    // --- anchors on the production source ---

    public function test_source_anchors_the_fix(): void
    {
        $src = file_get_contents(self::SOURCE);
        $this->assertIsString($src);
        $body = substr($src, (int) strpos($src, 'function invalidateOlder'));
        $body = substr($body, 0, (int) strpos($body, "\n    }"));

        $this->assertMatchesRegularExpression(
            "/FROM pagecache'.*\\['stored-'\\]/",
            $body,
            "anchor row must be picked by stored DESC - pagecache.id is an md5 hash, ordering by it picks a random row",
        );
        $this->assertMatchesRegularExpression(
            "/FROM pagecache_str2find'.*\\['id-'\\]/",
            $body,
            'the cut must be the HIGHEST str2find id of the anchor key, or its own siblings are orphaned',
        );
        $delete_pagecache = (int) strpos($body, "delete_low_priority('pagecache',");
        $closing_brace    = (int) strpos($body, "        }\n");
        $this->assertGreaterThan($closing_brace, $delete_pagecache, 'the pagecache DELETE must sit OUTSIDE the counterpart guard');
    }
}
