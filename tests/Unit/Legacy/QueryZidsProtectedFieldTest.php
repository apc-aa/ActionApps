<?php

declare(strict_types=1);

/**
 * QueryZids() conditions and sorting on protected fields (AA_Field::isProtected()): a password, its
 * copy, a 2FA secret, a field with the pwd or 2fa insert function - values GetItemContent() never gives.
 *
 * Pins: a condition comparing such a value matches nothing, a sort by it is dropped, both logged for State
 * Check (VIEW_DENY); all_fields leaves the fields out; whether the field is filled may still be asked.
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
        /** the condition alone, no join - an item of the listing never matches */
        private const NOTHING = 'SELECT DISTINCT item.id as itemid FROM item' . self::WHERE . ' AND (1=0) ORDER BY item.publish_date DESC';

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
        private static function sql(array $conds, array $sort = [], array|false $slices = [self::OWN], ?\zids $restrict = null): string
        {
            RecordingDb::$log = [];
            QueryZids($slices, $conds, $sort, 'ACTIVE', 0, $restrict);
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

        /** the log rows written, as SQL */
        private static function logInserts(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn($s) => str_starts_with(ltrim($s), 'INSERT INTO `log`')));
        }

        public function test_ownField_password_matchesNothing(): void
        {
            self::assertSame(self::NOTHING, self::sql([self::cond('password........')]));
        }

        public function test_ownField_secret_matchesNothing(): void
        {
            self::assertSame(self::NOTHING, self::sql([self::cond('secret..........', 'LIKE', 'A*')]));
            self::assertSame(self::NOTHING, self::sql([self::cond('secret..........', '==', 'JBSWY3DPEHPK3PXP')]));
        }

        /** a password under another id, and the copy of a password, are protected as well */
        public function test_ownField_pwdInsertAndPasswordCopy_matchNothing(): void
        {
            self::assertSame(self::NOTHING, self::sql([self::cond('text...........1')]));
            self::assertSame(self::NOTHING, self::sql([self::cond('text...........2')]));
        }

        /** with another field the condition would still tell about the protected one - it matches nothing as a whole */
        public function test_ownField_withAnotherField_matchesNothing(): void
        {
            self::assertSame(self::NOTHING, self::sql([['headline........' => 1, 'password........' => 1, 'operator' => 'BEGIN', 'value' => 'x']]));
        }

        /** the first listed slice describes the fields, but the values come from all of them */
        public function test_ownField_protectedInAnotherListedSlice_matchesNothing(): void
        {
            self::assertStringEndsWith(' AND (1=0) ORDER BY item.publish_date DESC', self::sql([self::cond('text...........1')], [], [self::SOURCE, self::OWN]));
        }

        /** whether a password is set may be asked - the reader slices of NSZM list who may log in so */
        public function test_ownField_presence_kept(): void
        {
            self::assertSame(
                "SELECT DISTINCT item.id as itemid FROM item INNER JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id='password........' ))"
                . self::WHERE . " AND (((`c0`.`text` IS NOT NULL) AND (`c0`.`text`<>''))) ORDER BY item.publish_date DESC",
                self::sql([self::cond('password........', 'NOTNULL', '1')]),
            );
            self::assertSame(
                "SELECT DISTINCT item.id as itemid FROM item LEFT JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id='password........' OR c0.field_id is NULL))"
                . self::WHERE . " AND (((`c0`.`text` IS NULL) OR (`c0`.`text`=''))) ORDER BY item.publish_date DESC",
                self::sql([self::cond('password........', 'ISNULL', '1')]),
            );
            self::assertSame([], self::logInserts());
        }

        /** the order would tell the values - the sort is dropped, the others stay */
        public function test_sort_protectedFieldDropped(): void
        {
            self::assertSame('SELECT DISTINCT item.id as itemid FROM item' . self::WHERE . ' ', self::sql([], [['password........' => 'a', 'limit' => 0]]));
            self::assertSame(
                "SELECT DISTINCT item.id as itemid FROM item LEFT JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id='headline........' OR c0.field_id is NULL))"
                . self::WHERE . ' ORDER BY c0.text',
                self::sql([], [['headline........' => 'a', 'limit' => 0], ['secret..........' => 'd', 'limit' => 0]]),
            );
        }

        /** all_fields leaves out the protected fields of the slice and any field of a protected type */
        public function test_allFields_leavesProtectedFieldsOut(): void
        {
            self::assertSame(
                "SELECT DISTINCT item.id as itemid FROM item INNER JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id NOT LIKE 'password%' AND c0.field_id NOT LIKE 'secret%') AND c0.field_id NOT IN ('text...........1','text...........2') )"
                . self::WHERE . " AND ((`c0`.`text`LIKE '%A%%')) ORDER BY item.publish_date DESC",
                self::sql([self::cond('all_fields', 'LIKE', 'A*')]),
            );
            self::assertSame([], self::logInserts());
        }

        public function test_relationForm_protectedField_matchesNothing(): void
        {
            self::assertSame(self::NOTHING, self::sql([self::cond('password........@relation.......1')]));
            self::assertSame(self::NOTHING, self::sql([self::cond('text...........1@relation.......1')]));   // 2fa insert function
        }

        public function test_relationForm_presence_kept(): void
        {
            self::assertStringContainsString("(c1.field_id='password........' OR c1.field_id is NULL)", self::sql([self::cond('password........@relation.......1', 'NOTNULL', '1')]));
        }

        public function test_relationForm_allFields_leavesProtectedFieldsOut(): void
        {
            self::assertSame(
                "SELECT DISTINCT item.id as itemid FROM item LEFT JOIN content as c0 ON (c0.item_id=item.id AND (c0.field_id='relation.......1' OR c0.field_id is NULL)) LEFT JOIN content as c1 ON (c1.item_id=UNHEX(c0.text) AND (c1.field_id NOT LIKE 'password%' AND c1.field_id NOT LIKE 'secret%') AND c1.field_id <> 'text...........1' AND EXISTS (SELECT 1 FROM item AS r1 WHERE r1.id=c1.item_id AND r1.slice_id=0x" . self::TARGET . '))'
                . self::WHERE . " AND ((`c1`.`text`LIKE '%A%%')) ORDER BY item.publish_date DESC",
                self::sql([self::cond('all_fields@relation.......1', 'LIKE', 'A*')]),
            );
        }

        /** the protected field compared, or the relation field compared with the listed item's id */
        public function test_sliceForm_protectedField_matchesNothing(): void
        {
            self::assertSame(self::NOTHING, self::sql([self::cond('secret..........@' . self::SOURCE . '/relation.......2')]));
            self::assertSame(self::NOTHING, self::sql([self::cond('headline........@' . self::SOURCE . '/secret..........')]));
            self::assertSame(self::NOTHING, self::sql([self::cond('headline........@' . self::SOURCE . '/secret..........', 'NOTNULL', '1')]));
        }

        /** a listing by item ids (cmd[]=x-...) lists only the slice of the first item, so a field of the
         *  others is never compared - an item of the reader slice cannot match by its password copy */
        public function test_listingByIds_onlyTheFirstItemsSlice(): void
        {
            $first = '1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c';   // in SOURCE, where text...........1 is plain
            $other = '1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a';   // in OWN, where it holds a password
            RecordingDb::$answer = static fn(string $q): array => str_starts_with($q, 'SELECT slice_id FROM item') ? [['slice_id' => pack_id(self::SOURCE)]] : [];

            $sql = self::sql([self::cond('text...........1')], [], false, new \zids([$first, $other], 'l'));

            self::assertStringContainsString(' WHERE item.slice_id = 0x' . self::SOURCE . ' AND item.id IN (0x' . $first . ',0x' . $other . ')', $sql);
            self::assertStringContainsString("c0.field_id='text...........1'", $sql);
        }

        public function test_refusal_loggedOncePerSliceAndFieldForStateCheck(): void
        {
            $_SERVER['HTTP_HOST'] = 'example.org';
            $_SERVER['REQUEST_URI'] = '/ctenari?cmd[1]=d-password........-BEGIN-x';

            self::sql([self::cond('password........'), self::cond('password........', 'LIKE', 'y')], [['password........' => 'a', 'limit' => 0]]);
            $inserts = self::logInserts();
            self::sql([self::cond('secret..........@' . self::SOURCE . '/relation.......2')]);
            $inserts = [...$inserts, ...self::logInserts()];

            self::assertCount(2, $inserts);
            self::assertStringContainsString("'" . ViewSlices::EVENT . "'", $inserts[0]);
            self::assertStringContainsString("'" . ParamImplode([ViewSlices::PROTECTED_FIELD, self::OWN, 'password........']) . "'", $inserts[0]);
            self::assertStringContainsString("'example.org/ctenari'", $inserts[0]);
            self::assertStringContainsString("'" . ParamImplode([ViewSlices::PROTECTED_FIELD, self::SOURCE, 'secret..........']) . "'", $inserts[1]);
        }

        /** the row names the slice in which the field is protected, not the one describing the fields */
        public function test_refusal_namesTheSliceProtectingTheField(): void
        {
            self::sql([self::cond('text...........1')], [], [self::SOURCE, self::OWN]);

            self::assertStringContainsString("'" . ParamImplode([ViewSlices::PROTECTED_FIELD, self::OWN, 'text...........1']) . "'", self::logInserts()[0]);
        }
    }
}
