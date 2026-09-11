<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;

/**
 * PageCache::invalidateFor($strings, $type, $stored_before) — the optional
 * `stored <` bound added for AA\Later\PagecacheDateSweep.
 *
 * With the bound, only pages stored before it are deleted: pages rendered after
 * an item's publish/expiry moment are already correct and must survive, which is
 * what makes the sweep idempotent. Without the bound the method behaves as
 * before (every tagged page goes).
 *
 * PageCache is DB-coupled (DB_AA statics + getDB), so these tests model the
 * two-table row algebra in memory and anchor to the production source, per the
 * PageCacheInvalidateOlderTest precedent.
 */
final class PageCacheInvalidateForStoredBeforeTest extends TestCase
{
    private const SOURCE = __DIR__ . '/../../../src/Cache/PageCache.php';

    /** @var array<string, int> pagecache: id => stored */
    private array $pagecache = [];

    /** @var array<int, array{0: string, 1: string}> pagecache_str2find: auto_increment id => [pagecache_id, str2find] */
    private array $str2find = [];

    private int $next_str2find_id = 1;

    /** One store() of $key at time $stored, tagged with slices $tags (CacheStr2find::store(): delete + re-insert). */
    private function store(string $key, int $stored, array $tags): void
    {
        $this->pagecache[$key] = $stored;
        $this->str2find = array_filter($this->str2find, static fn($row) => $row[0] !== $key);
        foreach ($tags as $tag) {
            $this->str2find[$this->next_str2find_id++] = [$key, "M=$tag"];
        }
    }

    /** SELECT s.pagecache_id FROM pagecache_str2find s [JOIN pagecache p ON p.id = s.pagecache_id]
     *   WHERE s.str2find IN (...) [AND p.stored < $bound]  →  invalidateById()
     * @return int keys selected for deletion */
    private function invalidateFor(array $slices, ?int $bound): int
    {
        $tags = array_map(static fn($s) => "M=$s", $slices);
        $keys = [];
        foreach ($this->str2find as [$pid, $tag]) {
            if (!in_array($tag, $tags, true)) {
                continue;
            }
            if ($bound !== null && (!isset($this->pagecache[$pid]) || $this->pagecache[$pid] >= $bound)) {
                continue;   // the JOIN drops orphans and pages stored at/after the bound
            }
            $keys[] = $pid;
        }
        $keys = array_values(array_unique($keys));
        foreach ($keys as $key) {
            unset($this->pagecache[$key]);
        }
        $this->str2find = array_filter($this->str2find, static fn($row) => !in_array($row[0], $keys, true));
        return count($keys);
    }

    private function orphans(): array
    {
        return array_keys(array_filter($this->str2find, fn($row) => !isset($this->pagecache[$row[0]])));
    }

    public function test_bound_deletes_only_pages_stored_before_it(): void
    {
        $this->store('old1', 1000, ['X']);
        $this->store('old2', 1500, ['X', 'Y']);
        $this->store('new1', 2100, ['X']);       // rendered after the transition

        $this->assertSame(2, $this->invalidateFor(['X'], 2000));
        $this->assertSame(['new1'], array_keys($this->pagecache));
        $this->assertSame([], $this->orphans());
    }

    public function test_bound_keeps_old_pages_without_the_tag(): void
    {
        $this->store('other', 1000, ['Z']);
        $this->store('mine', 1000, ['X']);

        $this->assertSame(1, $this->invalidateFor(['X'], 2000));
        $this->assertSame(['other'], array_keys($this->pagecache));
    }

    public function test_page_stored_exactly_at_the_bound_survives(): void
    {
        $this->store('edge', 2000, ['X']);

        $this->assertSame(0, $this->invalidateFor(['X'], 2000), 'stored < bound is strict');
        $this->assertSame(['edge'], array_keys($this->pagecache));
    }

    public function test_second_run_with_the_same_bound_deletes_nothing(): void
    {
        $this->store('old', 1000, ['X']);
        $this->store('new', 2500, ['X']);
        $this->invalidateFor(['X'], 2000);

        $this->assertSame(0, $this->invalidateFor(['X'], 2000), 'idempotent - this is why the sweep needs no watermark');
        $this->assertSame(['new'], array_keys($this->pagecache));
    }

    public function test_without_bound_every_tagged_page_goes(): void
    {
        $this->store('old', 1000, ['X']);
        $this->store('new', 2500, ['X']);

        $this->assertSame(2, $this->invalidateFor(['X'], null), 'legacy behaviour of the 40 existing call sites');
        $this->assertSame([], $this->pagecache);
        $this->assertSame([], $this->str2find);
    }

    public function test_key_restored_after_the_bound_survives(): void
    {
        $this->store('page', 1000, ['X']);
        $this->store('page', 3000, ['X']);      // re-rendered after the transition

        $this->assertSame(0, $this->invalidateFor(['X'], 2000));
        $this->assertSame(['page'], array_keys($this->pagecache));
    }

    public function test_page_matching_two_tags_is_counted_once(): void
    {
        $this->store('page', 1000, ['X', 'Y']);

        $this->assertSame(1, $this->invalidateFor(['X', 'Y'], 2000), 'array_unique on the selected keys');
        $this->assertSame([], $this->orphans());
    }

    public function test_orphan_str2find_rows_are_not_selected_by_the_join(): void
    {
        $this->store('gone', 1000, ['X']);
        unset($this->pagecache['gone']);        // pagecache row vanished, str2find rows stayed

        $this->assertSame(0, $this->invalidateFor(['X'], 2000));
    }

    // --- anchors on the production source ---

    public function test_source_anchors_the_bounded_lookup(): void
    {
        $src = file_get_contents(self::SOURCE);
        $this->assertIsString($src);
        $body = substr($src, (int) strpos($src, 'function invalidateFor'));
        $body = substr($body, 0, (int) strpos($body, "\n    }"));

        $this->assertStringContainsString('?int $stored_before = null): int', $body, 'optional third parameter, count returned');
        $this->assertStringContainsString('SELECT pagecache_id FROM pagecache_str2find', $body, 'the unbounded branch is unchanged');
        $this->assertStringContainsString('JOIN pagecache p ON p.id = s.pagecache_id', $body, 'the bound needs pagecache.stored');
        $this->assertStringContainsString("['p.stored', \$stored_before, '<']", $body, 'strict: a page stored at the bound was rendered after the transition');
        $this->assertStringNotContainsString('content', str_replace('// never touches `content`', '', $body), 'the lookup must never read the blob');
        $this->assertStringContainsString('array_unique($keys)', $body);
    }
}
