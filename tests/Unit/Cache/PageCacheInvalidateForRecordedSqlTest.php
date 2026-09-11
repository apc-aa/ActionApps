<?php

declare(strict_types=1);

// PageCache::invalidateFor() runs on DB_AA::select() + Cvarset, whose getDB()/freeDB()
// live in include/locsess.php (loaded by the RecordingDb fixture) and need the constants
// include/config.php normally provides. Safe test values, defined first.

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
        'PERM_LIB'        => 'sql',
        'DB_HOST'         => 'localhost',
        'DB_NAME'         => 'aa_test',
        'DB_USER'         => 'aa',
        'DB_PASSWORD'     => '',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Cache {

    use AA\Cache\PageCache;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * The real PageCache::invalidateFor() executed against a recording DB handle:
     * pins the SQL the optional $stored_before bound emits (JOIN on pagecache,
     * strict `<`), that the key list is deduplicated and counted, and that the
     * unbounded call still issues the historical statement.
     */
    final class PageCacheInvalidateForRecordedSqlTest extends TestCase
    {
        private const SLICE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        public function test_bounded_lookup_joins_pagecache_and_deletes_only_the_selected_keys(): void
        {
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, 'JOIN pagecache p')
                ? [['pagecache_id' => 'old1'], ['pagecache_id' => 'old1'], ['pagecache_id' => 'old2']]   // a page tagged twice
                : [];

            $deleted = (new PageCache(10800))->invalidateFor([self::SLICE], 'M', 2000);

            $this->assertSame(2, $deleted, 'deduplicated key count');
            $this->assertSame(
                "SELECT s.pagecache_id FROM pagecache_str2find s JOIN pagecache p ON p.id = s.pagecache_id WHERE s.str2find = 'M=" . self::SLICE . "' AND p.stored < 2000",
                RecordingDb::$log[0],
            );
            $this->assertCount(3, RecordingDb::$log, 'one lookup + one DELETE per table');
            $this->assertMatchesRegularExpression("/^DELETE .*`?pagecache`? .*id IN \\('old1','old2'\\)/", RecordingDb::$log[1]);
            $this->assertMatchesRegularExpression("/^DELETE .*pagecache_str2find.*pagecache_id IN \\('old1','old2'\\)/", RecordingDb::$log[2]);
        }

        public function test_unbounded_lookup_is_the_historical_statement(): void
        {
            RecordingDb::$answer = static fn(string $sql) => [['pagecache_id' => 'k1']];

            $deleted = (new PageCache(10800))->invalidateFor(self::SLICE);

            $this->assertSame(1, $deleted);
            $this->assertSame("SELECT pagecache_id FROM pagecache_str2find WHERE str2find = 'M=" . self::SLICE . "'", RecordingDb::$log[0]);
        }

        public function test_nothing_selected_issues_no_delete(): void
        {
            RecordingDb::$answer = static fn(string $sql) => [];

            $this->assertSame(0, (new PageCache(10800))->invalidateFor([self::SLICE], 'M', 2000));
            $this->assertCount(1, RecordingDb::$log, 'the idempotent second run costs one SELECT and nothing else');
        }
    }
}
