<?php

declare(strict_types=1);

/**
 * QueryZids() conditions on a field of a related item: <field>@<relation> (the listed slice points to
 * the related items) and <field>@<slice>[+]/<relation> (items of <slice> point to the listed one).
 *
 * Pins: the values come only from items of the relation's declared slice, or of the named slice in its
 * bins; a slice whose reading password the request does not know makes the condition match nothing (left
 * out, it would list more), logged for State Check; sorting never reads the related value.
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

    use AA\Security\ViewSlices;
    use AA\Util\LogOnce;
    use AA_Credentials;
    use AA_Field;
    use AA_Module;
    use AA_Moduleconfig;
    use Closure;
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
        /** a module that is no slice */
        private const NOT_SLICE = '0d0d0d0d0d0d0d0d0d0d0d0d0d0d0d0d';
        /** a slice the conditions never name - its items only appear in the data of the SQLite tests */
        private const OTHER = '0e0e0e0e0e0e0e0e0e0e0e0e0e0e0e0e';

        /** the listing part of the SQL: the own slice in the active bin */
        private const WHERE = ' WHERE item.slice_id = 0x' . self::OWN . " AND item.status_code=1 AND item.publish_date <= '%NOW%' AND item.expiry_date > '%NOW%'";
        /** the condition alone, no join - an item of the listing never matches */
        private const NOTHING = 'SELECT DISTINCT item.id as itemid FROM item' . self::WHERE . ' AND (1=0) ORDER BY item.publish_date DESC';
        /** the relation row of the second form, tied to SOURCE in the active bin */
        private const SOURCE_ROW = "LEFT JOIN content as c0 ON (c0.field_id='relation.......2' AND HEX(item.id)=c0.text AND EXISTS (SELECT 1 FROM item AS r1 WHERE r1.id=c0.item_id AND r1.slice_id=0x" . self::SOURCE . " AND r1.status_code=1 AND r1.publish_date <= '%NOW%' AND r1.expiry_date > '%NOW%' ))";

        /** @var array<string, AA_Module> */
        private array $modules = [];
        private array $server = [];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            LogOnce::reset();
            $this->server = $_SERVER;
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $GLOBALS['slice_info'] = $GLOBALS['vid'] = $GLOBALS['view_info'] = null;   // read by the SQL comment

            $this->modules = [self::OWN => new FakeSlice(self::OWN), self::TARGET => new FakeSlice(self::TARGET), self::SOURCE => new FakeSlice(self::SOURCE)];
            $this->modules[self::OWN]->setFields(new FakeFields(self::OWN, [
                self::field('headline........'),
                self::field('relation.......1', 'sel:#sLiCe-' . self::TARGET),
                self::field('relation.......3', 'sel:#sLiCe-nonsense'),
                self::field('relation.......4', 'sel:#sLiCe-' . self::NOT_SLICE),
                self::field('category.......1', 'sel:some_constants'),
            ]));
            $this->modules[self::TARGET]->setFields(new FakeFields(self::TARGET, [
                self::field('headline........'),
                self::field('status_code.....', '', 'status_code'),
            ]));
            $this->modules[self::SOURCE]->setFields(new FakeFields(self::SOURCE, [
                self::field('headline........'),
                self::field('relation.......2', 'sel:#sLiCe-' . self::OWN),
                self::field('status_code.....', '', 'status_code'),
            ]));
            // a site, say - AA_Slice::getOwnModule() must not take it for a slice
            $this->modules[self::NOT_SLICE] = new class (self::NOT_SLICE) extends AA_Module {
                public function __construct(string $id)
                {
                    $this->module_id = $id;
                    AA_Module::$_modules[$id] = $this;
                }

                public static function getModuleconfig(): AA_Moduleconfig
                {
                    throw new \LogicException('not used');
                }
            };
        }

        /** the module cache, the credentials, the log memo and $_SERVER are global - what a test put there must not reach the next one */
        protected function tearDown(): void
        {
            $_SERVER = $this->server;
            $GLOBALS['spareDBs'] = [];
            unset($GLOBALS['slice_info'], $GLOBALS['vid'], $GLOBALS['view_info'], $GLOBALS['slice_pwd']);
            RecordingDb::reset();
            LogOnce::reset();
            $ids = array_keys($this->modules);
            Closure::bind(static function () use ($ids): void {
                foreach ($ids as $id) {
                    unset(AA_Module::$_modules[$id]);
                }
            }, null, AA_Module::class)();
            Closure::bind(function (): void {
                $this->_pwd = [];
            }, AA_Credentials::singleton(), AA_Credentials::class)();
        }

        private function protect(string $slice_id, string $password): void
        {
            $this->modules[$slice_id]->reading_password = AA_Credentials::encrypt($password);
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

        public function test_relationForm_contentField(): void
        {
            self::assertSame(
                "SELECT DISTINCT item.id as itemid FROM item LEFT JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id='relation.......1' OR c0.field_id is NULL)) LEFT JOIN content as c1 ON (c1.item_id=UNHEX(c0.text) AND (c1.field_id='headline........' OR c1.field_id is NULL) AND EXISTS (SELECT 1 FROM item AS r1 WHERE r1.id=c1.item_id AND r1.slice_id=0x" . self::TARGET . "))"
                . self::WHERE . " AND ((`c1`.`text` = 'x')) ORDER BY item.publish_date DESC",
                self::sql([self::cond('headline........@relation.......1')]),
            );
        }

        public function test_relationForm_itemField(): void
        {
            self::assertSame(
                "SELECT DISTINCT item.id as itemid FROM item LEFT JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id='relation.......1' OR c0.field_id is NULL)) LEFT JOIN item as c1 ON (c1.id=UNHEX(c0.text) AND c1.slice_id=0x" . self::TARGET . ")"
                . self::WHERE . " AND ((`c1`.`status_code` = '1')) ORDER BY item.publish_date DESC",
                self::sql([self::cond('status_code.....@relation.......1', '==', '1')]),
            );
        }

        public function test_relationForm_allFields(): void
        {
            self::assertSame(
                "SELECT DISTINCT item.id as itemid FROM item LEFT JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id='relation.......1' OR c0.field_id is NULL)) LEFT JOIN content as c1 ON (c1.item_id=UNHEX(c0.text) AND EXISTS (SELECT 1 FROM item AS r1 WHERE r1.id=c1.item_id AND r1.slice_id=0x" . self::TARGET . "))"
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

        /** a relation to no slice - not an id, or a module of another type - cannot be narrowed to one */
        public function test_relationForm_targetNoSlice_matchesNothing(): void
        {
            self::assertSame(self::NOTHING, self::sql([self::cond('headline........@relation.......3')]));
            self::assertSame(self::NOTHING, self::sql([self::cond('headline........@relation.......4')]));
        }

        public function test_sliceForm_contentField(): void
        {
            self::assertSame(
                'SELECT DISTINCT item.id as itemid FROM item ' . self::SOURCE_ROW . " LEFT JOIN content as c1 ON (c1.item_id=c0.item_id AND (c1.field_id='headline........' OR c1.field_id is NULL))"
                . self::WHERE . " AND ((`c1`.`text` = 'x')) ORDER BY item.publish_date DESC",
                self::sql([self::cond('headline........@' . self::SOURCE . '/relation.......2')]),
            );
        }

        public function test_sliceForm_contentField_allBins(): void
        {
            self::assertStringContainsString(
                "LEFT JOIN content as c0 ON (c0.field_id='relation.......2' AND HEX(item.id)=c0.text AND EXISTS (SELECT 1 FROM item AS r1 WHERE r1.id=c0.item_id AND r1.slice_id=0x" . self::SOURCE . ")) LEFT JOIN content as c1 ON (c1.item_id=c0.item_id AND ",
                self::sql([self::cond('headline........@' . self::SOURCE . '+/relation.......2')]),
            );
        }

        public function test_sliceForm_itemField_activeBin(): void
        {
            self::assertSame(
                'SELECT DISTINCT item.id as itemid FROM item ' . self::SOURCE_ROW . ' LEFT JOIN item as c1 ON (c1.id=c0.item_id)'
                . self::WHERE . " AND ((`c1`.`status_code` = '1')) ORDER BY item.publish_date DESC",
                self::sql([self::cond('status_code.....@' . self::SOURCE . '/relation.......2', '==', '1')]),
            );
        }

        public function test_sliceForm_itemField_allBins(): void
        {
            self::assertSame(
                "SELECT DISTINCT item.id as itemid FROM item LEFT JOIN content as c0 ON (c0.field_id='relation.......2' AND HEX(item.id)=c0.text AND EXISTS (SELECT 1 FROM item AS r1 WHERE r1.id=c0.item_id AND r1.slice_id=0x" . self::SOURCE . ')) LEFT JOIN item as c1 ON (c1.id=c0.item_id)'
                . self::WHERE . " AND ((`c1`.`status_code` = '1')) ORDER BY item.publish_date DESC",
                self::sql([self::cond('status_code.....@' . self::SOURCE . '+/relation.......2', '==', '1')]),
            );
        }

        /** a module of another type is no slice - nothing matches, as in the first form */
        public function test_sliceForm_notSlice_matchesNothing(): void
        {
            self::assertSame(self::NOTHING, self::sql([self::cond('headline........@' . self::NOT_SLICE . '/relation.......2')]));
        }

        /** sorting by the field reads the relation row - of the listed item, or of the named slice in its bin - never the related value */
        public function test_sortByConditionField_ordersByTheRelationRow(): void
        {
            $sort = [['headline........' => 'a', 'limit' => 0]];

            self::assertStringEndsWith(' ORDER BY c0.text', self::sql([self::cond('headline........@relation.......1')], $sort));
            $sql = self::sql([self::cond('headline........@' . self::SOURCE . '/relation.......2')], $sort);
            self::assertStringContainsString(self::SOURCE_ROW, $sql);
            self::assertStringEndsWith(' ORDER BY c0.text', $sql);
        }

        /** an item whose relation points into another slice has no value: ISNULL takes it, a value does not */
        public function test_relationForm_relatedItemOfOtherSlice_isNoValue(): void
        {
            // 1 relates to TARGET, 2 to OTHER, 3 to nothing; both related items have the headline x
            $items   = [[1, self::OWN], [2, self::OWN], [3, self::OWN], [11, self::TARGET], [12, self::OTHER]];
            $content = [[1, 'relation.......1', 11], [2, 'relation.......1', 12], [11, 'headline........', 'x'], [12, 'headline........', 'x']];

            self::assertSame([1], self::selected($items, $content, self::sql([self::cond('headline........@relation.......1')])));
            self::assertSame([2, 3], self::selected($items, $content, self::sql([self::cond('headline........@relation.......1', 'ISNULL', '1')])));
        }

        /** an item an item of another slice points to has no value - for the condition and for the sort */
        public function test_sliceForm_relatingItemOfOtherSlice_isNoValue(): void
        {
            // SOURCE item 11 points to 1, OTHER item 12 (same field ids) to 2, nothing to 3
            $items   = [[1, self::OWN], [2, self::OWN], [3, self::OWN], [11, self::SOURCE], [12, self::OTHER]];
            $content = [[11, 'relation.......2', 1], [12, 'relation.......2', 2], [11, 'headline........', 'x'], [12, 'headline........', 'x']];
            $field   = 'headline........@' . self::SOURCE . '/relation.......2';

            self::assertSame([1], self::selected($items, $content, self::sql([self::cond($field)])));
            self::assertSame([2, 3], self::selected($items, $content, self::sql([self::cond($field, 'ISNULL', '1')])));
            // the sort column: only 1 has a relation row
            $sql = str_replace('SELECT DISTINCT item.id as itemid', 'SELECT DISTINCT item.id as itemid, c0.text IS NOT NULL', self::sql([self::cond($field, 'ISNULL', '1')], [['headline........' => 'a', 'limit' => 0]]));
            self::assertSame([[2, 0], [3, 0]], self::selected($items, $content, $sql, \PDO::FETCH_NUM));
            $sql = str_replace('SELECT DISTINCT item.id as itemid', 'SELECT DISTINCT item.id as itemid, c0.text IS NOT NULL', self::sql([self::cond($field, '==', 'x')], [['headline........' => 'a', 'limit' => 0]]));
            self::assertSame([[1, 1]], self::selected($items, $content, $sql, \PDO::FETCH_NUM));
        }

        /**
         * Runs the SQL on SQLite over these items [id, slice] (active, ids as numbers 1-255) and content rows
         * [item, field, value]; a value that is a number is the id of an item, stored as AA stores ids in content.
         * @return list<mixed> the selected items in the order of their ids
         */
        private static function selected(array $items, array $content, string $sql, int $mode = \PDO::FETCH_COLUMN): array
        {
            $id = static fn(int $n): string => str_repeat('0', 30) . sprintf('%02x', $n);
            $db = new \PDO('sqlite::memory:');
            try {
                $db->query("SELECT UNHEX('00')");
            } catch (\PDOException) {   // SQLite before 3.41
                $db->sqliteCreateFunction('UNHEX', static fn(?string $hex) => (is_null($hex) || !ctype_xdigit($hex)) ? null : hex2bin($hex), 1);
            }
            $db->exec('CREATE TABLE item (id BLOB PRIMARY KEY, slice_id BLOB, status_code INTEGER, publish_date INTEGER, expiry_date INTEGER)');
            $db->exec('CREATE TABLE content (item_id BLOB, field_id TEXT, number INTEGER, text TEXT)');
            // ids as blobs, as the packed ids of MySQL - a bound string would be text, which never equals x'..'
            foreach ($items as [$item, $slice]) {
                $db->exec("INSERT INTO item VALUES (x'" . $id($item) . "', x'$slice', 1, 0, 9999999999)");
            }
            $insert = $db->prepare("INSERT INTO content VALUES (unhex(?), ?, NULL, ?)");
            foreach ($content as [$item, $field, $value]) {
                // SQLite HEX() writes upper case and compares case-sensitively; MySQL compares the text case-insensitively
                $insert->execute([$id($item), $field, is_int($value) ? strtoupper($id($value)) : $value]);
            }
            $sql = preg_replace(['/0x([0-9a-f]{32})/', "/'%NOW%'/", '/ ORDER BY .*/'], ["x'\$1'", '1792990000', ' ORDER BY item.id'], $sql);
            $rows = $db->query($sql)->fetchAll($mode);
            return array_map(static fn($row) => is_array($row) ? [hexdec(bin2hex($row[0])), (int) $row[1]] : hexdec(bin2hex($row)), $rows);
        }

        public function test_relationForm_protectedTarget_matchesNothing(): void
        {
            $this->protect(self::TARGET, 'secret');

            self::assertSame(self::NOTHING, self::sql([self::cond('headline........@relation.......1')]));
            self::assertSame(self::NOTHING, self::sql([self::cond('status_code.....@relation.......1', '==', '1')]));
            self::assertSame(self::NOTHING, self::sql([self::cond('all_fields@relation.......1')]));
        }

        public function test_relationForm_knownPassword_kept(): void
        {
            $this->protect(self::TARGET, 'secret');
            AA_Credentials::singleton()->register(AA_Credentials::encrypt('secret'));   // {credentials:secret}

            self::assertStringContainsString('AND EXISTS (SELECT 1 FROM item AS r1 WHERE r1.id=c1.item_id AND r1.slice_id=0x' . self::TARGET . '))', self::sql([self::cond('headline........@relation.......1')]));
        }

        public function test_relationForm_passwordFromUrl_kept(): void
        {
            $this->protect(self::TARGET, 'secret');
            $GLOBALS['slice_pwd'] = 'secret';

            self::assertStringNotContainsString('(1=0)', self::sql([self::cond('headline........@relation.......1')]));
        }

        /** a public slice named in the condition gives no way to the content of a protected one */
        public function test_sliceForm_protectedSlice_matchesNothing(): void
        {
            $this->protect(self::SOURCE, 'secret');

            self::assertSame(self::NOTHING, self::sql([self::cond('headline........@' . self::SOURCE . '/relation.......2')]));
            self::assertSame(self::NOTHING, self::sql([self::cond('status_code.....@' . self::SOURCE . '+/relation.......2', '==', '1')]));
        }

        /** refused before the fields are looked at - the result must not tell which fields the slice has */
        public function test_sliceForm_protectedSlice_unknownField_matchesNothingToo(): void
        {
            $this->protect(self::SOURCE, 'secret');

            self::assertSame(self::NOTHING, self::sql([self::cond('no_such_field...@' . self::SOURCE . '/relation.......2')]));
        }

        public function test_refusal_loggedOnceForStateCheck(): void
        {
            $this->protect(self::SOURCE, 'secret');
            $_SERVER['HTTP_HOST'] = 'example.org';
            $_SERVER['REQUEST_URI'] = '/zpravy?cmd[1]=d-x';

            self::sql([self::cond('headline........@' . self::SOURCE . '/relation.......2'), self::cond('status_code.....@' . self::SOURCE . '/relation.......2', '==', '1')]);
            $inserts = array_values(array_filter(RecordingDb::$log, static fn($s) => str_starts_with(ltrim($s), 'INSERT INTO `log`')));

            self::assertCount(1, $inserts);
            self::assertStringContainsString("'" . ViewSlices::EVENT . "'", $inserts[0]);
            self::assertStringContainsString("'" . ParamImplode([ViewSlices::CONDITION, self::SOURCE]) . "'", $inserts[0]);
            self::assertStringContainsString("'example.org/zpravy'", $inserts[0]);
        }

        /** a refused condition is no sort column - the sort joins the listed item's own field */
        public function test_refusal_sortReadsTheOwnField(): void
        {
            $this->protect(self::TARGET, 'secret');

            $sql = self::sql([self::cond('headline........@relation.......1')], [['headline........' => 'a', 'limit' => 0]]);

            self::assertStringContainsString("LEFT JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id='headline........' OR c0.field_id is NULL))", $sql);
            self::assertStringEndsWith(' AND (1=0) ORDER BY c0.text', $sql);
        }
    }
}
