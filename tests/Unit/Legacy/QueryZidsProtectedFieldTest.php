<?php

declare(strict_types=1);

/**
 * QueryZids() conditions and sorting on protected fields (AA_Field::isProtected()): a password, its
 * copy, a 2FA secret, a field with the pwd or 2fa insert function - values GetItemContent() never gives.
 *
 * Pins today's SQL: each such condition and sort compares the stored value.
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

    use AA\Util\LogOnce;
    use AA_Field;
    use AA_Module;
    use Closure;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeFields;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class QueryZidsProtectedFieldTest extends TestCase
    {
        /** the listed slice - readers, say; relation.......1 points to TARGET */
        private const OWN = '0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a';
        /** the declared target of relation.......1 */
        private const TARGET = '0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b';
        /** its relation.......2 points to OWN */
        private const SOURCE = '0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c';

        /** the listing part of the SQL: the own slice in the active bin */
        private const WHERE = ' WHERE item.slice_id = 0x' . self::OWN . " AND item.status_code=1 AND item.publish_date <= '%NOW%' AND item.expiry_date > '%NOW%'";

        /** @var array<string, FakeSlice> */
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
                self::field(self::OWN, 'headline........'),
                self::field(self::OWN, 'password........', 'pwd:', 'pwd:text...........2:phrase'),
                self::field(self::OWN, 'secret..........'),
                self::field(self::OWN, 'text...........1', 'fld', 'pwd'),   // a password under another id
                self::field(self::OWN, 'text...........2'),                  // the copy of password........
                self::field(self::OWN, 'relation.......1', 'sel:#sLiCe-' . self::TARGET),
            ]));
            $this->modules[self::TARGET]->setFields(new FakeFields(self::TARGET, [
                self::field(self::TARGET, 'headline........'),
                self::field(self::TARGET, 'password........', 'pwd:', 'pwd:'),
                self::field(self::TARGET, 'text...........1', 'fld', '2fa'),
            ]));
            $this->modules[self::SOURCE]->setFields(new FakeFields(self::SOURCE, [
                self::field(self::SOURCE, 'headline........'),
                self::field(self::SOURCE, 'relation.......2', 'sel:#sLiCe-' . self::OWN),
                self::field(self::SOURCE, 'secret..........'),
                self::field(self::SOURCE, 'text...........1'),
            ]));
        }

        /** the module cache, the log memo and $_SERVER are global - what a test put there must not reach the next one */
        protected function tearDown(): void
        {
            $_SERVER = $this->server;
            $GLOBALS['spareDBs'] = [];
            unset($GLOBALS['slice_info'], $GLOBALS['vid'], $GLOBALS['view_info']);
            RecordingDb::reset();
            LogOnce::reset();
            $ids = array_keys($this->modules);
            Closure::bind(static function () use ($ids): void {
                foreach ($ids as $id) {
                    unset(AA_Module::$_modules[$id]);
                }
            }, null, AA_Module::class)();
        }

        private static function field(string $slice_id, string $id, string $widget = '', string $insert = ''): AA_Field
        {
            return new AA_Field(['id' => $id, 'slice_id' => pack_id($slice_id), 'text_stored' => 1, 'input_show_func' => $widget, 'input_insert_func' => $insert] + array_fill_keys(AA_Field::USED_FIELDS, ''));
        }

        /** the SQL QueryZids() sends, without the debug comment and quoted as by MySQL (the test db is SQLite) */
        private static function sql(array $conds, array $sort = [], array $slices = [self::OWN]): string
        {
            RecordingDb::$log = [];
            QueryZids($slices, $conds, $sort, 'ACTIVE');
            $selects = array_values(array_filter(RecordingDb::$log, static fn($s) => str_contains($s, 'as itemid')));
            self::assertCount(1, $selects);

            $sql = str_replace('"', '`', preg_replace('/\s+/', ' ', explode(' -- ', $selects[0])[0]));
            // the bin condition carries the current time
            return preg_replace("/(publish_date <= |expiry_date > )'\\d+'/", "\$1'%NOW%'", $sql);
        }

        private static function cond(string $field, string $operator = 'BEGIN', string $value = '$2y$10$a'): array
        {
            return [$field => 1, 'operator' => $operator, 'value' => $value];
        }

        /** the join of one own content field, as an INNER JOIN for a condition its rows must match */
        private static function join(string $field): string
        {
            return "SELECT DISTINCT item.id as itemid FROM item INNER JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id='$field' ))";
        }

        public function test_ownField_password(): void
        {
            self::assertSame(
                self::join('password........') . self::WHERE . " AND ((`c0`.`text` LIKE '\$2y\$10\$a%')) ORDER BY item.publish_date DESC",
                self::sql([self::cond('password........')]),
            );
        }

        public function test_ownField_secret(): void
        {
            self::assertSame(
                self::join('secret..........') . self::WHERE . " AND ((`c0`.`text`LIKE '%A%%')) ORDER BY item.publish_date DESC",
                self::sql([self::cond('secret..........', 'LIKE', 'A*')]),
            );
        }

        /** a password under another id, and the copy of a password, are compared as any field */
        public function test_ownField_pwdInsertAndPasswordCopy(): void
        {
            foreach (['text...........1', 'text...........2'] as $field) {
                self::assertSame(
                    self::join($field) . self::WHERE . " AND ((`c0`.`text` LIKE '\$2y\$10\$a%')) ORDER BY item.publish_date DESC",
                    self::sql([self::cond($field)]),
                );
            }
        }

        public function test_ownField_withAnotherField(): void
        {
            self::assertSame(
                "SELECT DISTINCT item.id as itemid FROM item INNER JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id IN ('headline........','password........') ))"
                . self::WHERE . " AND ((`c0`.`text` LIKE 'x%')) ORDER BY item.publish_date DESC",
                self::sql([['headline........' => 1, 'password........' => 1, 'operator' => 'BEGIN', 'value' => 'x']]),
            );
        }

        /** the first listed slice describes the fields - a plain field there, the password of another slice */
        public function test_ownField_protectedInAnotherListedSlice(): void
        {
            self::assertStringContainsString(
                "INNER JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id='text...........1' ))",
                self::sql([self::cond('text...........1')], [], [self::SOURCE, self::OWN]),
            );
        }

        /** whether a password is set - the reader slices of NSZM list who may log in so */
        public function test_ownField_presence(): void
        {
            self::assertSame(
                self::join('password........') . self::WHERE . " AND (((`c0`.`text` IS NOT NULL) AND (`c0`.`text`<>''))) ORDER BY item.publish_date DESC",
                self::sql([self::cond('password........', 'NOTNULL', '1')]),
            );
            self::assertSame(
                "SELECT DISTINCT item.id as itemid FROM item LEFT JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id='password........' OR c0.field_id is NULL))"
                . self::WHERE . " AND (((`c0`.`text` IS NULL) OR (`c0`.`text`=''))) ORDER BY item.publish_date DESC",
                self::sql([self::cond('password........', 'ISNULL', '1')]),
            );
        }

        public function test_sort(): void
        {
            self::assertSame(
                "SELECT DISTINCT item.id as itemid FROM item LEFT JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id='password........' OR c0.field_id is NULL))"
                . self::WHERE . ' ORDER BY c0.text',
                self::sql([], [['password........' => 'a', 'limit' => 0]]),
            );
            self::assertSame(
                "SELECT DISTINCT item.id as itemid FROM item LEFT JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id='headline........' OR c0.field_id is NULL)) LEFT JOIN content as c1 ON (c1.item_id=item.id AND (c1.field_id='secret..........' OR c1.field_id is NULL))"
                . self::WHERE . ' ORDER BY c0.text,c1.text DESC',
                self::sql([], [['headline........' => 'a', 'limit' => 0], ['secret..........' => 'd', 'limit' => 0]]),
            );
        }

        public function test_allFields(): void
        {
            self::assertSame(
                'SELECT DISTINCT item.id as itemid FROM item INNER JOIN content as c0 ON (c0.item_id=item.id )'
                . self::WHERE . " AND ((`c0`.`text`LIKE '%A%%')) ORDER BY item.publish_date DESC",
                self::sql([self::cond('all_fields', 'LIKE', 'A*')]),
            );
        }

        public function test_relationForm(): void
        {
            foreach (['password........', 'text...........1'] as $field) {
                self::assertSame(
                    "SELECT DISTINCT item.id as itemid FROM item LEFT JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id='relation.......1' OR c0.field_id is NULL)) LEFT JOIN content as c1 ON (c1.item_id=UNHEX(c0.text) AND (c1.field_id='$field' OR c1.field_id is NULL) AND EXISTS (SELECT 1 FROM item AS r1 WHERE r1.id=c1.item_id AND r1.slice_id=0x" . self::TARGET . '))'
                    . self::WHERE . " AND ((`c1`.`text` LIKE '\$2y\$10\$a%')) ORDER BY item.publish_date DESC",
                    self::sql([self::cond("$field@relation.......1")]),
                );
            }
        }

        public function test_relationForm_allFields(): void
        {
            self::assertSame(
                "SELECT DISTINCT item.id as itemid FROM item LEFT JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id='relation.......1' OR c0.field_id is NULL)) LEFT JOIN content as c1 ON (c1.item_id=UNHEX(c0.text) AND EXISTS (SELECT 1 FROM item AS r1 WHERE r1.id=c1.item_id AND r1.slice_id=0x" . self::TARGET . '))'
                . self::WHERE . " AND ((`c1`.`text`LIKE '%A%%')) ORDER BY item.publish_date DESC",
                self::sql([self::cond('all_fields@relation.......1', 'LIKE', 'A*')]),
            );
        }

        public function test_sliceForm(): void
        {
            $source_row = static fn(string $rel): string => "LEFT JOIN content as c0 ON (c0.field_id='$rel' AND HEX(item.id)=c0.text AND EXISTS (SELECT 1 FROM item AS r1 WHERE r1.id=c0.item_id AND r1.slice_id=0x" . self::SOURCE . " AND r1.status_code=1 AND r1.publish_date <= '%NOW%' AND r1.expiry_date > '%NOW%' ))";
            self::assertSame(
                'SELECT DISTINCT item.id as itemid FROM item ' . $source_row('relation.......2') . " LEFT JOIN content as c1 ON (c1.item_id=c0.item_id AND (c1.field_id='secret..........' OR c1.field_id is NULL))"
                . self::WHERE . " AND ((`c1`.`text` LIKE '\$2y\$10\$a%')) ORDER BY item.publish_date DESC",
                self::sql([self::cond('secret..........@' . self::SOURCE . '/relation.......2')]),
            );
            // the secret as the relation field
            self::assertSame(
                'SELECT DISTINCT item.id as itemid FROM item ' . $source_row('secret..........') . " LEFT JOIN content as c1 ON (c1.item_id=c0.item_id AND (c1.field_id='headline........' OR c1.field_id is NULL))"
                . self::WHERE . " AND ((`c1`.`text` LIKE '\$2y\$10\$a%')) ORDER BY item.publish_date DESC",
                self::sql([self::cond('headline........@' . self::SOURCE . '/secret..........')]),
            );
        }
    }
}
