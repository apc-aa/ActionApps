<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../include/metabase.class.php';

/**
 * CacheStr2find::store() rewrites a key's pagecache_str2find rows as
 * DELETE + ONE multi-row INSERT instead of a statement per string. Measured
 * across installs, a page carries 5-6 such strings on average and up to 23
 * (NSZM), so the old loop paid that many round trips per cached page.
 *
 * The rewrite is only safe because it preserves the invariant
 * PageCache::invalidateOlder() depends on: it cuts the old part of
 * pagecache_str2find by an auto_increment prefix, which holds only while
 * str2find ids run in the same order as pagecache.stored. That in turn holds
 * only while every INSERT lets MariaDB assign `id` itself.
 *
 * doInsertMulti() names only the columns getSQLData() returns for the rows, so
 * the invariant reduces to: an omitted `id` must stay out of getSQLData(), never
 * get a value. That is what these tests pin - pure schema behaviour, no DB
 * connection.
 */
final class CacheStr2findStoreTest extends TestCase
{
    private const SOURCE  = __DIR__ . '/../../../src/Cache/CacheStr2find.php';
    private const SCHEMA  = __DIR__ . '/../../../include/metabase_schema.php';

    private function table(): \AA_MbT
    {
        $metabase = \AA_Metabase::fromArray(require self::SCHEMA);
        return $metabase->tables['pagecache_str2find'];
    }

    /** The load-bearing invariant: `id` is left to auto_increment. */
    public function test_omitted_id_stays_out_so_auto_increment_still_assigns_it(): void
    {
        $sql = $this->table()->getSQLData(['pagecache_id' => 'abc', 'str2find' => 'M=xyz']);

        $this->assertArrayNotHasKey('id', $sql, 'a value here would break invalidateOlder()\'s id-prefix cut');
        $this->assertSame(['pagecache_id', 'str2find'], array_keys($sql));
    }

    /** Values still go through the metabase escaping, not raw interpolation. */
    public function test_values_are_escaped(): void
    {
        $sql = $this->table()->getSQLData(['pagecache_id' => "a'b", 'str2find' => "M=x'y"]);

        $this->assertSame("'a\\'b'", $sql['pagecache_id']);
        $this->assertSame("'M=x\\'y'", $sql['str2find']);
    }

    public function test_source_uses_one_batch_insert_and_no_raw_interpolation(): void
    {
        $src  = file_get_contents(self::SOURCE);
        $body = substr($src, (int) strpos($src, 'public function store('));

        $this->assertStringContainsString("doInsertMulti('pagecache_str2find'", $body, 'one multi-row INSERT, not a statement per string');
        $this->assertStringNotContainsString("doInsert('pagecache_str2find')", $body, 'the row-at-a-time loop must be gone');
        $this->assertStringNotContainsString('"pagecache_id=\'$keyid\'"', $body, 'the DELETE must not interpolate $keyid raw into SQL');
        $this->assertStringContainsString("DB_AA::delete('pagecache_str2find'", $body, 'DELETE goes through the escaping helper');
    }

    // --- add_str2find(): the typed parameter replaced a docblock claim ---

    /** include/itemview.php hands over the previous level's accumulator, which is
     *  NULL on the outermost render - nothing has stored one yet. That is why the
     *  parameter is `?self` and null is a normal argument, not an error. */
    public function test_merging_a_null_parent_is_a_no_op(): void
    {
        $level = new \AA\Cache\CacheStr2find(['x']);

        $level->add_str2find(null);

        $this->assertSame(['M=x'], $level->getStrings());
    }

    /** The upward merge itemview.php relies on: a nested level's keys end up in
     *  the entry the outer level stores, so invalidating a nested source also
     *  invalidates the outer cached page. */
    public function test_merge_is_a_union_of_both_levels(): void
    {
        $outer = new \AA\Cache\CacheStr2find(['outerslice']);
        $inner = new \AA\Cache\CacheStr2find(['innerslice']);

        $inner->add_str2find($outer);

        $this->assertSame(['M=innerslice', 'M=outerslice'], $inner->getStrings());
        $this->assertSame(['M=outerslice'], $outer->getStrings(), 'merging must not mutate the source');
    }
}
