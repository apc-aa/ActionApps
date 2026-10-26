<?php

declare(strict_types=1);

/**
 * QueryZids() conditions on a field of a related item: <field>@<relation> (the listed slice points to
 * the related items) and <field>@<slice>[+]/<relation> (items of <slice> point to the listed one).
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

    final class QueryZidsRemoteFieldTest extends TestCase
    {
        /** the listed slice; relation.......1 points to TARGET */
        private const OWN = '0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a';
        /** the declared target of relation.......1 */
        private const TARGET = '0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b';
        /** its relation.......2 points to OWN */
        private const SOURCE = '0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $GLOBALS['slice_info'] = $GLOBALS['vid'] = $GLOBALS['view_info'] = null;   // read by the SQL comment

            (new FakeSlice(self::OWN))->setFields(new FakeFields(self::OWN, [
                self::field('headline........'),
                self::field('relation.......1', 'sel:#sLiCe-' . self::TARGET),
                self::field('category.......1', 'sel:some_constants'),
            ]));
            (new FakeSlice(self::TARGET))->setFields(new FakeFields(self::TARGET, [
                self::field('headline........'),
                self::field('status_code.....', '', 'status_code'),
            ]));
            (new FakeSlice(self::SOURCE))->setFields(new FakeFields(self::SOURCE, [
                self::field('headline........'),
                self::field('relation.......2', 'sel:#sLiCe-' . self::OWN),
                self::field('status_code.....', '', 'status_code'),
            ]));
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            unset($GLOBALS['slice_info'], $GLOBALS['vid'], $GLOBALS['view_info']);
            RecordingDb::reset();
        }

        private static function field(string $id, string $widget = '', string $in_item_tbl = ''): AA_Field
        {
            return new AA_Field(['id' => $id, 'text_stored' => 1, 'input_show_func' => $widget, 'in_item_tbl' => $in_item_tbl] + array_fill_keys(AA_Field::USED_FIELDS, ''));
        }

        /** the SQL QueryZids() sends, without the debug comment and quoted as by MySQL (the test db is SQLite) */
        private static function sql(array $conds, array $sort = []): string
        {
            RecordingDb::$log = [];
            QueryZids([self::OWN], $conds, $sort, 'ACTIVE');
            $selects = array_values(array_filter(RecordingDb::$log, static fn($s) => str_contains($s, 'as itemid')));
            self::assertCount(1, $selects);

            $sql = str_replace('"', '`', preg_replace('/\s+/', ' ', explode(' -- ', $selects[0])[0]));
            // the bin condition carries the current time
            return preg_replace("/(publish_date <= |expiry_date > )'\\d+'/", "\$1'%NOW%'", $sql);
        }

        private static function cond(string $field, string $operator = '==', string $value = 'x'): array
        {
            return [$field => 1, 'operator' => $operator, 'value' => $value];
        }

        /** the listing part of the SQL: the own slice in the active bin */
        private const WHERE = ' WHERE item.slice_id = 0x' . self::OWN . " AND item.status_code=1 AND item.publish_date <= '%NOW%' AND item.expiry_date > '%NOW%'";

        public function test_relationForm_contentField(): void
        {
            self::assertSame(
                "SELECT DISTINCT item.id as itemid FROM item LEFT JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id='relation.......1' OR c0.field_id is NULL)) LEFT JOIN content as c1 ON (c1.item_id=UNHEX(c0.text) AND (c1.field_id='headline........' OR c1.field_id is NULL))"
                . self::WHERE . " AND ((`c1`.`text` = 'x')) ORDER BY item.publish_date DESC",
                self::sql([self::cond('headline........@relation.......1')]),
            );
        }

        public function test_relationForm_itemField(): void
        {
            self::assertSame(
                "SELECT DISTINCT item.id as itemid FROM item LEFT JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id='relation.......1' OR c0.field_id is NULL)) LEFT JOIN item as c1 ON (c1.id=UNHEX(c0.text))"
                . self::WHERE . " AND ((`c1`.`status_code` = '1')) ORDER BY item.publish_date DESC",
                self::sql([self::cond('status_code.....@relation.......1', '==', '1')]),
            );
        }

        public function test_relationForm_allFields(): void
        {
            self::assertSame(
                "SELECT DISTINCT item.id as itemid FROM item LEFT JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id='relation.......1' OR c0.field_id is NULL)) LEFT JOIN content as c1 ON (c1.item_id=UNHEX(c0.text))"
                . self::WHERE . " AND ((`c1`.`text` = 'x')) ORDER BY item.publish_date DESC",
                self::sql([self::cond('all_fields@relation.......1')]),
            );
        }

        /** a field with constants is no relation - the condition is left out */
        public function test_relationForm_notRelation_conditionLeftOut(): void
        {
            self::assertSame(
                'SELECT DISTINCT item.id as itemid FROM item' . self::WHERE . ' ORDER BY item.publish_date DESC',
                self::sql([self::cond('headline........@category.......1')]),
            );
        }

        public function test_sliceForm_contentField(): void
        {
            self::assertSame(
                "SELECT DISTINCT item.id as itemid FROM item LEFT JOIN content as c0 ON (c0.field_id='relation.......2' AND HEX(item.id)=c0.text) LEFT JOIN content as c1 ON (c1.item_id=c0.item_id AND (c1.field_id='headline........' OR c1.field_id is NULL))"
                . self::WHERE . " AND ((`c1`.`text` = 'x')) ORDER BY item.publish_date DESC",
                self::sql([self::cond('headline........@' . self::SOURCE . '/relation.......2')]),
            );
        }

        public function test_sliceForm_itemField_activeBin(): void
        {
            self::assertSame(
                "SELECT DISTINCT item.id as itemid FROM item LEFT JOIN content as c0 ON (c0.field_id='relation.......2' AND HEX(item.id)=c0.text) LEFT JOIN item as c1 ON (c1.id=c0.item_id AND c1.slice_id=0x" . self::SOURCE . " AND c1.status_code=1 AND c1.publish_date <= '%NOW%' AND c1.expiry_date > '%NOW%' )"
                . self::WHERE . " AND ((`c1`.`status_code` = '1')) ORDER BY item.publish_date DESC",
                self::sql([self::cond('status_code.....@' . self::SOURCE . '/relation.......2', '==', '1')]),
            );
        }

        public function test_sliceForm_itemField_allBins(): void
        {
            self::assertSame(
                "SELECT DISTINCT item.id as itemid FROM item LEFT JOIN content as c0 ON (c0.field_id='relation.......2' AND HEX(item.id)=c0.text) LEFT JOIN item as c1 ON (c1.id=c0.item_id AND c1.slice_id=0x" . self::SOURCE . ')'
                . self::WHERE . " AND ((`c1`.`status_code` = '1')) ORDER BY item.publish_date DESC",
                self::sql([self::cond('status_code.....@' . self::SOURCE . '+/relation.......2', '==', '1')]),
            );
        }

        /** sorting by the field reads the relation row, never the related value */
        public function test_sortByConditionField_ordersByTheRelationRow(): void
        {
            self::assertStringEndsWith(
                ' ORDER BY c0.text',
                self::sql([self::cond('headline........@relation.......1')], [['headline........' => 'a', 'limit' => 0]]),
            );
            self::assertStringEndsWith(
                ' ORDER BY c0.text',
                self::sql([self::cond('headline........@' . self::SOURCE . '/relation.......2')], [['headline........' => 'a', 'limit' => 0]]),
            );
        }
    }
}
