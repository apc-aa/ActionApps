<?php

declare(strict_types=1);

/**
 * QueryZids() with a LIMIT checks the content conditions by EXISTS instead of a JOIN.
 *
 * Pins: no LIMIT - the SQL is as it was; a join the ORDER BY uses stays a join (and DISTINCT
 * stays); LEFT JOIN conditions keep matching items without the value.
 */

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

    use AA_Field;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeFields;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class QueryZidsSemijoinTest extends TestCase
    {
        private const SLICE = '9b2023b8a4e8d6c1ef76f58c81578399';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $GLOBALS['slice_info'] = $GLOBALS['vid'] = $GLOBALS['view_info'] = null;   // read by the SQL comment
            $slice = new FakeSlice(self::SLICE);
            $slice->setFields(new FakeFields(self::SLICE, [
                self::field('category.......1', 1),
                self::field('category.......7', 1),
                self::field('headline........', 1),
                self::field('switch.........2', 0),
            ]));
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            unset($GLOBALS['slice_info'], $GLOBALS['vid'], $GLOBALS['view_info']);
            RecordingDb::reset();
        }

        private static function field(string $id, int $text_stored): AA_Field
        {
            return new AA_Field(['id' => $id, 'text_stored' => $text_stored] + array_fill_keys(AA_Field::USED_FIELDS, ''));
        }

        /** the SQL QueryZids() sends, without the debug comment and quoted as by MySQL (the test db is SQLite) */
        private static function sql(array $conds, array $sort, int $limit): string
        {
            QueryZids([self::SLICE], $conds, $sort, 'ACTIVE', 0, null, 'LIKE', $limit);
            $selects = array_values(array_filter(RecordingDb::$log, static fn($s) => str_contains($s, 'as itemid')));
            self::assertCount(1, $selects);

            return str_replace('"', '`', preg_replace('/\s+/', ' ', explode(' -- ', $selects[0])[0]));
        }

        private const FILLED = ['category.......1' => 1, 'operator' => 'NOTNULL', 'value' => '1'];
        private const EMPTY  = ['category.......7' => 1, 'operator' => 'ISNULL', 'value' => '1'];

        public function test_noLimit_joinsAsBefore(): void
        {
            self::assertSame(
                "SELECT DISTINCT item.id as itemid FROM item INNER JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id='category.......1' )) LEFT JOIN content as c1 ON (c1.item_id=item.id AND (c1.field_id='category.......7' OR c1.field_id is NULL)) WHERE item.slice_id = 0x" . self::SLICE . " AND item.status_code=1 AND item.publish_date <= '%NOW%' AND item.expiry_date > '%NOW%' AND (((`c0`.`text` IS NOT NULL) AND (`c0`.`text`<>''))) AND (((`c1`.`text` IS NULL) OR (`c1`.`text`=''))) ORDER BY item.publish_date DESC",
                self::now(self::sql([self::FILLED, self::EMPTY], [], 0)),
            );
        }

        public function test_limit_conditionsByExists_noDistinct(): void
        {
            self::assertSame(
                "SELECT item.id as itemid FROM item WHERE item.slice_id = 0x" . self::SLICE . " AND item.status_code=1 AND item.publish_date <= '%NOW%' AND item.expiry_date > '%NOW%'"
                . " AND (EXISTS (SELECT 1 FROM content AS c0 WHERE c0.item_id=item.id AND (c0.field_id='category.......1' ) AND ((`c0`.`text` IS NOT NULL) AND (`c0`.`text`<>''))))"
                . " AND ((EXISTS (SELECT 1 FROM content AS c1 WHERE c1.item_id=item.id AND (c1.field_id='category.......7' OR c1.field_id is NULL) AND ((`c1`.`text` IS NULL) OR (`c1`.`text`='')))"
                . " OR NOT EXISTS (SELECT 1 FROM content AS c1 WHERE c1.item_id=item.id AND (c1.field_id='category.......7' OR c1.field_id is NULL))))"
                . " ORDER BY item.publish_date DESC LIMIT 3",
                self::now(self::sql([self::FILLED, self::EMPTY], [], 3)),
            );
        }

        public function test_limit_sortedByConditionField_keepsThatJoinAndDistinct(): void
        {
            $sql = self::sql([self::FILLED, self::EMPTY], [['category.......1' => 'a', 'limit' => 0]], 3);

            self::assertStringStartsWith('SELECT DISTINCT item.id', $sql);
            self::assertStringContainsString("INNER JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id='category.......1' ))", $sql);
            self::assertStringContainsString('ORDER BY c0.text LIMIT 3', $sql);
            self::assertStringNotContainsString('JOIN content as c1', $sql);
            self::assertStringContainsString('EXISTS (SELECT 1 FROM content AS c1 ', $sql);
        }

        public function test_limit_sortedByOtherField_sortJoinStaysWithDistinct(): void
        {
            $sql = self::sql([self::FILLED], [['headline........' => 'a', 'limit' => 0]], 5);

            self::assertStringStartsWith('SELECT DISTINCT item.id', $sql);
            self::assertStringContainsString('EXISTS (SELECT 1 FROM content AS c0 ', $sql);
            self::assertStringContainsString("LEFT JOIN content as c1 ON (c1.item_id=item.id AND (c1.field_id='headline........' OR c1.field_id is NULL))", $sql);
        }

        public function test_limit_multiFieldCondition_existsOverAllTheFields(): void
        {
            $sql = self::sql([['headline........' => 1, 'category.......1' => 1, 'operator' => '==', 'value' => 'x']], [], 1);

            self::assertStringStartsWith('SELECT item.id', $sql);
            self::assertStringContainsString("EXISTS (SELECT 1 FROM content AS c0 WHERE c0.item_id=item.id AND (c0.field_id IN ('headline........','category.......1') ) AND (`c0`.`text` = 'x'))", $sql);
        }

        public function test_limit_comparison_itemWithoutValueNeverMatches(): void
        {
            // NULL > 5 is not true, so the LEFT JOIN of a comparison needs no branch for the missing value
            $sql = self::sql([['switch.........2' => 1, 'operator' => '>', 'value' => '5']], [], 1);

            self::assertStringContainsString("AND (EXISTS (SELECT 1 FROM content AS c0 WHERE c0.item_id=item.id AND (c0.field_id='switch.........2' OR c0.field_id is NULL) AND (`c0`.`number` > 5)))", $sql);
            self::assertStringNotContainsString('NOT EXISTS', $sql);
        }

        public function test_limit_notEqual_itemsWithoutTheValue(): void
        {
            $sql = self::sql([['category.......1' => 1, 'operator' => '<>', 'value' => 'x']], [], 1);

            self::assertStringContainsString("OR NOT EXISTS (SELECT 1 FROM content AS c0 WHERE c0.item_id=item.id AND (c0.field_id='category.......1' OR c0.field_id is NULL) AND (`c0`.`text` = 'x')))", $sql);
        }

        public function test_limit_numberCondition_onNumberColumn(): void
        {
            $sql = self::sql([['switch.........2' => 1, 'operator' => '==', 'value' => '0']], [], 1);

            self::assertStringContainsString("EXISTS (SELECT 1 FROM content AS c0 WHERE c0.item_id=item.id AND (c0.field_id='switch.........2' ) AND (`c0`.`number` = '0'))", $sql);
        }

        /** the bin condition carries the current time - replaced, so the SQL can be pinned */
        private static function now(string $sql): string
        {
            return preg_replace("/(publish_date <= |expiry_date > )'\\d+'/", "\$1'%NOW%'", $sql);
        }
    }
}
