<?php

declare(strict_types=1);

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

namespace Tests\Unit\Legacy {

    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * DiscussionInSlice() - admin/discedit*.php get the item and the comment as ids in the URL; they may work
     * only on comments of items of the slice the page is opened for.
     */
    final class DiscussionInSliceTest extends TestCase
    {
        private const SLICE   = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
        private const OTHER   = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';
        private const ITEM    = 'c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3';
        private const COMMENT = 'd4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/discussion.php';
        }

        /** the item ITEM is in SLICE, the comment COMMENT under ITEM */
        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            RecordingDb::$answer = static function (string $sql): array {
                $has = static fn(string ...$ids): bool => array_reduce($ids, static fn(bool $all, string $id): bool => $all && str_contains($sql, "0x$id"), true);
                return match (true) {
                    (bool) preg_match('/FROM\W+item\W/', $sql)       => $has(self::ITEM, self::SLICE) ? [['id' => hex2bin(self::ITEM)]] : [],
                    (bool) preg_match('/FROM\W+discussion\W/', $sql) => $has(self::COMMENT, self::ITEM) ? [['id' => hex2bin(self::COMMENT)]] : [],
                    default                                 => [],
                };
            };
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        public function test_item_of_the_slice_is_allowed(): void
        {
            $this->assertTrue(\DiscussionInSlice(self::ITEM, '', self::SLICE));
            $this->assertTrue(\DiscussionInSlice(self::ITEM, self::COMMENT, self::SLICE));
        }

        public function test_item_of_another_slice_is_refused(): void
        {
            $this->assertFalse(\DiscussionInSlice(self::ITEM, '', self::OTHER));
            $this->assertFalse(\DiscussionInSlice(self::ITEM, self::COMMENT, self::OTHER));
        }

        public function test_comment_of_another_item_is_refused(): void
        {
            $this->assertFalse(\DiscussionInSlice(self::ITEM, self::OTHER, self::SLICE));
        }

        public function test_missing_item_is_refused_without_a_query(): void
        {
            $this->assertFalse(\DiscussionInSlice('', '', self::SLICE));
            $this->assertSame([], RecordingDb::$log);
        }
    }
}
