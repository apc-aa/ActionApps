<?php

declare(strict_types=1);

namespace {
    // FLAG_TEXT_STORED (include/constants.php) and AA_Value (include/item_content.php) pull the legacy includes
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

    use AA\IO\DB\DB_AA;
    use AA\Optimize\MultivalueDuplicates;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /** What counts as a duplicate, and that a repair deletes the copies of its group only, never the last one */
    final class MultivalueDuplicatesTest extends TestCase
    {
        private const ITEM = '0123456789abcdef0123456789abcdef';

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

        /** @return array<string, int|string|null> a group as textDuplicatesSql() returns it */
        private static function textDup(array $over = []): array
        {
            return $over + ['item_id' => hex2bin(self::ITEM), 'field_id' => 'category........', 'number' => '1000002', 'flag' => '64', 'text_hash' => hash('sha256', 'Praha'), 'text' => 'Praha', 'cnt' => '3'];
        }

        /** The DB answers: the engine of `content`, the text groups, the number candidates, and $copies for
         *  every count of a group's rows */
        private static function answer(string $engine, array $text, int $copies, array $numbers = []): void
        {
            RecordingDb::$answer = static fn(string $sql): array => match (true) {
                str_starts_with($sql, 'SELECT ENGINE')                      => [['ENGINE' => $engine]],
                str_contains($sql, '`text_hash`')                           => $text,
                str_contains($sql, 'GROUP BY')                              => $numbers,
                str_starts_with(strtolower($sql), 'select count(*) as') => [['cnt' => (string) $copies]],
                default                                                     => [],
            };
            RecordingDb::$affected = static fn(string $sql): int => preg_match('/LIMIT (\d+)$/', $sql, $m) ? (int) $m[1] : 1;
        }

        /** a text repeated at another position may be meant (translations pair by position, '' pads them) */
        public function test_textDuplicatesSql_groups_a_row_of_one_position_by_its_bytes_and_flag(): void
        {
            $sql = MultivalueDuplicates::textDuplicatesSql();
            self::assertStringContainsString('SHA2(`text`, 256) AS `text_hash`', $sql);
            self::assertStringContainsString('GROUP BY `item_id`, `field_id`, `number`, `flag`, `text_hash` HAVING `cnt` > 1', $sql);
        }

        /** rows written before 2004 hold text without the flag - GetItemContent() reads them as text */
        public function test_textDuplicatesSql_takes_the_text_rows_as_GetItemContent_does(): void
        {
            self::assertStringContainsString('WHERE (COALESCE(`flag` & 64, 0) <> 0 OR COALESCE(LENGTH(`text`), 0) > 0) AND ', MultivalueDuplicates::textDuplicatesSql());
            self::assertStringNotContainsString('`text`', MultivalueDuplicates::numberCandidatesSql(), 'InnoDB would read every long text');
        }

        public function test_textWhere_names_the_rows_of_the_group_only(): void
        {
            $where = DB_AA::makeWhere(MultivalueDuplicates::textWhere(self::textDup()));
            foreach (['item_id = 0x' . self::ITEM, 'field_id = 0x' . bin2hex('category........'), 'number = 1000002', 'flag = 64', "SHA2(`text`, 256) = '" . hash('sha256', 'Praha') . "'"] as $condition) {
                self::assertStringContainsString($condition, $where);
            }
        }

        public function test_textWhere_matches_a_null_of_the_group_as_null(): void
        {
            $where = DB_AA::makeWhere(MultivalueDuplicates::textWhere(self::textDup(['item_id' => null, 'field_id' => null, 'number' => null, 'flag' => null, 'text_hash' => null])));
            foreach (['item_id IS NULL', 'field_id IS NULL', 'number IS NULL', 'flag IS NULL', 'text IS NULL'] as $condition) {
                self::assertStringContainsString($condition, $where);
            }
        }

        /** DbBinaryTrailingZeros cut the trailing zero bytes of some ids - no long id, its bytes still name it */
        public function test_textWhere_names_a_shortened_item_id_by_its_bytes(): void
        {
            $where = DB_AA::makeWhere(MultivalueDuplicates::textWhere(self::textDup(['item_id' => hex2bin(substr(self::ITEM, 0, 30))])));
            self::assertStringContainsString('item_id = 0x' . substr(self::ITEM, 0, 30) . ' ', $where);
        }

        public function test_numberWhere_names_the_numeric_rows_of_the_group_only(): void
        {
            $where = DB_AA::makeWhere(MultivalueDuplicates::numberWhere(['item_id' => hex2bin(self::ITEM), 'field_id' => 'date............', 'number' => '1790812800', 'flag' => '0', 'cnt' => '2']));
            foreach (['item_id = 0x' . self::ITEM, 'field_id = 0x' . bin2hex('date............'), 'number = 1790812800', 'flag = 0', '(NOT (COALESCE(`flag` & 64, 0) <> 0 OR COALESCE(LENGTH(`text`), 0) > 0)) = 1'] as $condition) {
                self::assertStringContainsString($condition, $where);
            }
        }

        /** a candidate of old text rows without the flag and one number is no duplicate */
        public function test_numberDuplicates_counts_the_numeric_rows_of_a_candidate_again(): void
        {
            $candidate = ['item_id' => hex2bin(self::ITEM), 'field_id' => 'relation.......1', 'number' => null, 'flag' => '0', 'cnt' => '3'];
            self::answer('InnoDB', [], 1, [$candidate]);
            self::assertSame([], MultivalueDuplicates::numberDuplicates());

            self::answer('InnoDB', [], 2, [$candidate]);
            self::assertSame([array_replace($candidate, ['cnt' => 2])], MultivalueDuplicates::numberDuplicates());
            self::assertStringContainsString('(NOT (COALESCE(`flag` & 64, 0) <> 0 OR COALESCE(LENGTH(`text`), 0) > 0)) = 1', (string) end(RecordingDb::$log));
        }

        public function test_test_lists_each_group(): void
        {
            self::answer('InnoDB', [self::textDup(['text' => '<b>Praha</b>'])], 3);
            $check = new MultivalueDuplicates();

            self::assertFalse($check->test());
            self::assertStringContainsString('Duplicates (3) in item ' . self::ITEM . ' - field category........ - value &lt;b&gt;Praha&lt;/b&gt;', $check->report());
        }

        /** State Check offers no repair that cannot run */
        public function test_hasAction_offers_no_repair_without_innodb(): void
        {
            self::answer('MyISAM', [], 0);
            self::assertFalse((new MultivalueDuplicates())->hasAction('repair'));
            self::assertTrue((new MultivalueDuplicates())->hasAction('test'));
            self::answer('InnoDB', [], 0);
            self::assertTrue((new MultivalueDuplicates())->hasAction('repair'));
        }

        public function test_repair_deletes_all_copies_but_one_as_counted_again_under_a_lock(): void
        {
            self::answer('InnoDB', [self::textDup()], 2);          // an editor removed one of the 3 copies since the scan
            $repair = new MultivalueDuplicates();

            self::assertTrue($repair->repair());
            $deletes = array_values(array_filter(RecordingDb::$log, static fn(string $sql): bool => str_starts_with($sql, 'DELETE')));
            self::assertCount(1, $deletes);
            self::assertStringEndsWith(' LIMIT 1', $deletes[0]);
            self::assertStringContainsString('FOR UPDATE', RecordingDb::$log[array_search($deletes[0], RecordingDb::$log, true) - 1]);
            self::assertSame(['BEGIN', 'COMMIT'], array_values(array_filter(RecordingDb::$log, static fn(string $sql): bool => in_array($sql, ['BEGIN', 'COMMIT'], true))));
            self::assertStringContainsString('1 rows deleted', $repair->report());
        }

        public function test_repair_deletes_the_copies_of_a_number(): void
        {
            self::answer('InnoDB', [], 2, [['item_id' => hex2bin(self::ITEM), 'field_id' => 'date............', 'number' => '1790812800', 'flag' => '0', 'cnt' => '2']]);
            self::assertTrue((new MultivalueDuplicates())->repair());
            $deletes = array_values(array_filter(RecordingDb::$log, static fn(string $sql): bool => str_starts_with($sql, 'DELETE')));
            self::assertCount(1, $deletes);
            self::assertStringContainsString('number = 1790812800', $deletes[0]);
            self::assertStringEndsWith(' LIMIT 1', $deletes[0]);
        }

        /** a statement that throws (a refused COMMIT does) rolls its chunk back - the report says how far the repair got */
        public function test_repair_reports_where_it_stopped(): void
        {
            self::answer('InnoDB', [self::textDup()], 2);
            RecordingDb::$fail_on = 'DELETE';
            $repair = new MultivalueDuplicates();

            self::assertFalse($repair->repair());
            self::assertStringContainsString('The repair stopped after 0 rows deleted', $repair->report());
        }

        public function test_repair_keeps_the_last_copy(): void
        {
            self::answer('InnoDB', [self::textDup()], 1);
            self::assertTrue((new MultivalueDuplicates())->repair());
            self::assertSame(0, RecordingDb::count('DELETE'));
        }

        /** MyISAM ignores the transaction and FOR UPDATE - a save between the count and the DELETE could lose the value */
        public function test_repair_deletes_nothing_without_innodb(): void
        {
            self::answer('MyISAM', [self::textDup()], 3);
            $repair = new MultivalueDuplicates();

            self::assertFalse($repair->repair());
            self::assertSame(0, RecordingDb::count('DELETE'));
            self::assertStringContainsString('not InnoDB', $repair->report());
        }
    }
}
