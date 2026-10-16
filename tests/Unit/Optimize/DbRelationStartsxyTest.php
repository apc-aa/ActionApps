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

namespace Tests\Unit\Optimize {

    use AA\Optimize\DbRelationStartsxy;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /** The check must stay an index range on `content` - a join with `field` ran minutes on aadb */
    final class DbRelationStartsxyTest extends TestCase
    {
        private const ID = '0123456789abcdef0123456789abcdef';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';    // the legacy boot, no DB
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [];
        }

        /** @param list<string> $field_ids the relation fields; $bad the rows found in content */
        private static function answer(array $field_ids, array $bad = []): void
        {
            RecordingDb::$answer = static fn(string $sql): array => match (true) {
                str_contains($sql, 'FROM `field`')       => array_map(static fn($id) => ['id' => $id], $field_ids),
                str_contains($sql, 'count(*)')           => [['cnt' => (string) count($bad)]],
                str_contains($sql, 'FROM `content`')     => $bad,
                default                                  => [],
            };
        }

        private static function contentQuery(): string
        {
            return (string) current(array_filter(RecordingDb::$log, static fn(string $sql): bool => preg_match('/FROM [`"]?content[`"]? /', $sql) === 1));
        }

        private static function assertBadRelationWhere(string $sql): void
        {
            $any_id = str_repeat('_', 32);
            self::assertStringContainsString("WHERE field_id IN ('relation........','category........') AND (`text` LIKE 'x$any_id' OR `text` LIKE 'y$any_id')", $sql);
            self::assertStringNotContainsString('field.', $sql);
        }

        /** field.id repeats in every slice - each id once, no join, the length in the pattern */
        public function test_test_reads_content_by_the_distinct_relation_field_ids(): void
        {
            self::answer(['relation........', 'category........']);
            self::assertTrue((new DbRelationStartsxy())->test());

            self::assertStringContainsString('SELECT DISTINCT `id` FROM `field`', RecordingDb::$log[0]);
            self::assertStringStartsWith('SELECT count(*)', self::contentQuery());
            self::assertBadRelationWhere(self::contentQuery());
        }

        public function test_test_reports_the_bad_rows(): void
        {
            self::answer(['relation........'], [['item_id' => 'i1', 'text' => 'x' . self::ID], ['item_id' => 'i2', 'text' => 'y' . self::ID]]);
            $check = new DbRelationStartsxy();
            self::assertFalse($check->test());
            self::assertStringContainsString('2 bad relation records found', $check->report());
        }

        public function test_test_without_relation_fields_does_not_touch_content(): void
        {
            self::answer([]);
            self::assertTrue((new DbRelationStartsxy())->test());
            self::assertSame('', self::contentQuery());
        }

        public function test_repair_drops_the_leading_char_of_the_found_rows(): void
        {
            self::answer(['relation........', 'category........'], [['item_id' => 'i1', 'text' => 'y' . self::ID], ['item_id' => 'i2', 'text' => 'xylophone-and-twenty-four-chars!']]);
            self::assertTrue((new DbRelationStartsxy())->repair());

            self::assertBadRelationWhere(self::contentQuery());

            $updates = array_values(array_filter(RecordingDb::$log, static fn(string $sql): bool => str_starts_with($sql, 'UPDATE')));
            self::assertCount(1, $updates, 'a text that is no item id stays as it is');
            self::assertStringContainsString("item_id = 0x6931", $updates[0]);
            self::assertStringContainsString("`text` = '" . self::ID . "'", $updates[0]);
            self::assertStringContainsString("`text` = 'y" . self::ID . "'", $updates[0]);
        }
    }
}
