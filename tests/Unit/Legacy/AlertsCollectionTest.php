<?php

declare(strict_types=1);

// alerts_sending.php pulls in include/locsess.php-dependent code; RecordingDb (store_fixtures)
// loads the real locsess.php, which needs the constants include/config.php normally provides.

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

    use AA;
    use AA\Optimize\CheckAlertitemRefusals;
    use AA\Util\LogOnce;
    use AA_Collection;
    use AA_Module;
    use Closure;
    use ItemContent;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * AA_Collection (modules/alerts/alerts_sending.php): the id check for ids arriving from
     * a request (AA_Responder_Alertitem) and the filter lookup that used to splice the id into
     * its SQL - `xx' #` there mailed the filters of every collection to the caller - and the
     * test-address rule, where a bad address used to mean "none", i.e. a live send to all readers -
     * and the relation of a collection to the slice whose item is sent through it - and who a mailing
     * may go to (validReaders(), isValidReader()), where RecordingDb plays the reader slice and answers
     * the item searches by the conditions QueryZids puts into the SQL.
     */
    final class AlertsCollectionTest extends TestCase
    {
        private const SLICE = '0123456789abcdef0123456789abcdef';

        // RecordingDb is a SQLite handle, so DB_AA quotes the table names with "" (MySQL: ``)

        /** the slice is the collection's reader slice (alerts_collection.slice_id) */
        private const READER_SQL = "SELECT id FROM \"alerts_collection\" WHERE id = 'sQjwa' AND slice_id = 0x0123456789abcdef0123456789abcdef LIMIT  1";
        /** a filter of the collection whose view belongs to the slice */
        private const FILTER_SQL = "SELECT alerts_collection_filter.collectionid FROM \"alerts_collection_filter\",\"alerts_filter\",\"view\""
            . " WHERE alerts_collection_filter.collectionid = 'sQjwa' AND alerts_collection_filter.filterid = alerts_filter.id"
            . " AND alerts_filter.vid = view.id AND view.slice_id = 0x0123456789abcdef0123456789abcdef LIMIT  1";

        /** a site module uses both the item's slice and the collection's reader slice or Alerts module
         *  (relation, flag REL_FLAG_MODULE_DEPEND = 1, source = the site) */
        private const SITE_SQL = "SELECT r1.source_id FROM `relation` AS r1"
            . " JOIN `relation` AS r2 ON r2.source_id = r1.source_id"
            . " JOIN `alerts_collection` AS c ON r2.destination_id IN (c.slice_id, c.module_id)"
            . " JOIN `module` AS m ON m.id = r1.source_id"
            . " WHERE c.id = 'sQjwa' AND r1.destination_id = 0x0123456789abcdef0123456789abcdef AND r1.flag = 1 AND r2.flag = 1 AND m.type = 'W' AND m.deleted = 0 LIMIT  1";

        private const ITEM = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../modules/alerts/alerts_sending.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            LogOnce::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            self::forgetReaderSlice();
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            LogOnce::reset();
        }

        public static function validIds(): array
        {
            return [
                'lower'        => ['abcde'],
                'mixed case'   => ['sQjwa'],
                'with digits'  => ['293sR'],
                'digits only'  => ['12345'],
            ];
        }

        #[DataProvider('validIds')]
        public function test_isValidId_acceptsFiveAsciiAlnum(string $id): void
        {
            $this->assertTrue(AA_Collection::isValidId($id));
        }

        public static function invalidIds(): array
        {
            return [
                'sql comment'      => ["xx' #"],
                'too short'        => ['abc'],
                'too long'         => ['abcdef'],
                'space'            => ['ab cd'],
                'dash'             => ['ab-cd'],
                'empty'            => [''],
                'trailing newline' => ["abcd\n"],
                'nul byte'         => ["abcd\0"],
                'non-ascii'        => ['abčd'],     // 5 bytes, 4 characters
                'non-ascii 5 chars' => ['ábcde'],
                'array'            => [['abcde']],
                'null'             => [null],
                'int'              => [12345],
            ];
        }

        #[DataProvider('invalidIds')]
        public function test_isValidId_rejectsAnythingElse(mixed $id): void
        {
            $this->assertFalse(AA_Collection::isValidId($id));
        }

        public static function emailInputs(): array
        {
            return [
                'empty = live send'   => ['', []],
                'absent = live send'  => [null, []],
                'valid'               => ['a@b.cz', ['a@b.cz']],
                'no at sign'          => ['x.cz', null],
                'at sign first'       => ['@x', null],
                'nothing after at'    => ['a@', null],
                'trailing newline'    => ["a@b.cz\n", null],
                'header injection'    => ["a@b.cz\nBcc: c@d.cz", null],
                'array'               => [['a@b.cz'], null],
            ];
        }

        #[DataProvider('emailInputs')]
        public function test_testEmails_invalidAddressIsNeverNone(mixed $input, ?array $expected): void
        {
            $this->assertSame($expected, AA_Collection::testEmails($input));
        }

        public function test_getFilterIds_quotesTheIdAndKeepsTheOrder(): void
        {
            RecordingDb::$answer = static fn(string $sql) => [['filterid' => '7'], ['filterid' => '3']];

            $ids = (new AA_Collection("xx' #"))->getFilterIds();

            $this->assertSame(['7', '3'], $ids, 'a plain list of filter ids, as GetTable2Array(NoCoLuMn) gave');
            $this->assertSame(
                "SELECT filterid FROM alerts_collection_filter WHERE collectionid = 'xx\\' #' ORDER BY myindex",
                RecordingDb::$log[0],
            );
        }

        public function test_relatesToSlice_viaTheReaderSlice(): void
        {
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, 'FROM "alerts_collection" ') ? [['id' => 'sQjwa']] : [];

            $this->assertTrue(AA_Collection::relatesToSlice('sQjwa', self::SLICE));
            $this->assertSame([self::READER_SQL], RecordingDb::$log, 'the reader slice is enough - no filter lookup');
        }

        public function test_relatesToSlice_viaAFilterOnTheSlice(): void
        {
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, '"view"') ? [['collectionid' => 'sQjwa']] : [];

            $this->assertTrue(AA_Collection::relatesToSlice('sQjwa', self::SLICE));
            $this->assertSame([self::READER_SQL, self::FILTER_SQL], RecordingDb::$log);
        }

        /** the sender may have no access to the Reader Management slice - a site using both is enough */
        public function test_relatesToSlice_viaASiteUsingBoth(): void
        {
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, 'FROM `relation` AS r1') ? [['source_id' => 'x']] : [];

            $this->assertTrue(AA_Collection::relatesToSlice('sQjwa', self::SLICE));
            $this->assertSame([self::READER_SQL, self::FILTER_SQL, self::SITE_SQL], RecordingDb::$log);
        }

        public function test_relatesToSlice_unrelatedCollection(): void
        {
            $this->assertFalse(AA_Collection::relatesToSlice('sQjwa', self::SLICE));
            $this->assertSame([self::READER_SQL, self::FILTER_SQL, self::SITE_SQL], RecordingDb::$log);
        }

        /** @return string[] the INSERTs into the log */
        private static function logInserts(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn(string $sql) => str_starts_with($sql, 'INSERT INTO `log`')));
        }

        public function test_logRefusal_writesOneRowPerCollectionSliceAndUser(): void
        {
            $this->assertTrue(AA_Collection::logRefusal('sQjwa', self::SLICE, self::ITEM));
            $this->assertFalse(AA_Collection::logRefusal('sQjwa', self::SLICE, self::ITEM), 'the same refusal again - no row, no query');
            $this->assertFalse(AA_Collection::logRefusal('sQjwa', self::SLICE, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'), 'another item of the same slice is the same problem');

            $inserts = self::logInserts();
            $this->assertCount(1, $inserts);
            $this->assertStringContainsString("'" . CheckAlertitemRefusals::EVENT . "'", $inserts[0]);
            $this->assertStringContainsString("'sQjwa:" . self::SLICE . ":'", $inserts[0], 'selector: collection, slice, user (none here)');
            $this->assertStringContainsString("'" . self::ITEM . "'", $inserts[0], 'params: the item');
            $this->assertSame(1, RecordingDb::count('SELECT'));
        }

        public function test_logRefusal_anotherSliceIsAnotherRow(): void
        {
            AA_Collection::logRefusal('sQjwa', self::SLICE, self::ITEM);
            AA_Collection::logRefusal('sQjwa', 'fedcba9876543210fedcba9876543210', self::ITEM);

            $this->assertCount(2, self::logInserts());
        }

        public function test_logRefusal_malformedIdsAreNotLogged(): void
        {
            $this->assertFalse(AA_Collection::logRefusal("xx' #", self::SLICE, self::ITEM));
            $this->assertFalse(AA_Collection::logRefusal('sQjwa', 'x', self::ITEM));
            $this->assertSame([], RecordingDb::$log);
        }

        public static function malformedRelationIds(): array
        {
            return [
                'sql in collection'  => ["xx' #", self::SLICE],
                'long collection'    => ['sQjwaX', self::SLICE],
                'short slice'        => ['sQjwa', '0123'],
                'slice not hex'      => ['sQjwa', str_repeat('g', 32)],
                'sql in slice'       => ['sQjwa', "0123456789abcdef0123456789abc' #"],
                'empty slice'        => ['sQjwa', ''],
            ];
        }

        #[DataProvider('malformedRelationIds')]
        public function test_relatesToSlice_malformedIdsAskNothing(string $collection_id, string $slice_id): void
        {
            RecordingDb::$answer = static fn(string $sql) => [['x' => 'x']];   // a match for anything that got through

            $this->assertFalse(AA_Collection::relatesToSlice($collection_id, $slice_id));
            $this->assertSame([], RecordingDb::$log);
        }

        // ---------------------------------------------------------------- who a mailing may go to

        private const READERS = 'f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1';
        private const MODULE  = 'f2f2f2f2f2f2f2f2f2f2f2f2f2f2f2f2';
        private const ANNA    = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
        private const BOB     = 'b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1';
        private const CYRIL   = 'c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1';
        private const DORA    = 'd1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1';
        private const CITY    = 'text...........1';
        /** the frequency field of collection sQjwa - getAlertsField(FIELDID_HOWOFTEN, 'sQjwa') */
        private const HOWOFTEN = 'alerts1....sQjwa';

        /** @var array<string, array<string, string>> reader item => field => text; a missing key = no content row */
        private array $readers = [];

        /** AA_Module keeps every module it loaded for the request */
        private static function forgetReaderSlice(): void
        {
            Closure::bind(static function (string $id): void {
                unset(AA_Module::$_modules[$id]);
            }, null, AA_Module::class)(self::READERS);
        }

        /** RecordingDb answers: the collection, the reader slice and the item searches over $readers */
        private function readerSlice(): void
        {
            RecordingDb::$answer = function (string $sql): array {
                if (str_starts_with($sql, 'SELECT * FROM alerts_collection ')) {
                    return [['id' => 'sQjwa', 'slice_id' => pack_id(self::READERS), 'module_id' => pack_id(self::MODULE), 'emailid_welcome' => '1', 'emailid_alert' => '2']];
                }
                if (str_contains($sql, 'FROM `module`')) {
                    return [['unpackid' => self::READERS, 'type' => 'S']];
                }
                if (preg_match('/FROM `slice` WHERE id = 0x([0-9a-f]{32})/', $sql, $m)) {
                    return [array_merge(array_fill_keys(AA::Metabase()->getColumnNames('slice'), ''), ['id' => pack_id($m[1]), 'type' => 'ReaderManagement', 'name' => 'Readers', 'flag' => '0'])];
                }
                if (preg_match('/FROM `field` WHERE `?slice_id`? = 0x([0-9a-f]{32})/', $sql, $m)) {
                    return array_map(
                        static fn(string $id): array => array_merge(array_fill_keys(AA::Metabase()->getColumnNames('field'), ''), ['id' => $id, 'slice_id' => pack_id($m[1]), 'text_stored' => '1']),
                        [FIELDID_MAIL_CONFIRMED, FIELDID_EMAIL, self::HOWOFTEN, self::CITY],
                    );
                }
                if (str_contains($sql, 'as itemid')) {
                    return array_map(static fn(string $id): array => ['itemid' => pack_id($id)], $this->search($sql));
                }
                return [];
            };
        }

        /** @return string[] ids of the readers the item search finds - by its "field" = 'value' / LIKE 'value%' conditions and its id list */
        private function search(string $sql): array
        {
            preg_match_all('/(c\d+)\.field_id=\'([^\']+)\'/', $sql, $fields, PREG_SET_ORDER);
            $field_of = array_column($fields, 2, 1);
            preg_match_all('/"(c\d+)"\."text" (=|LIKE) \'([^\']*)\'/', $sql, $conds, PREG_SET_ORDER);
            preg_match('/item\.id IN \(([^)]*)\)/', $sql, $in);
            $found = [];
            foreach ($this->readers as $id => $values) {
                if ($in && !str_contains($in[1], $id)) {
                    continue;
                }
                foreach ($conds as [, $alias, $op, $value]) {
                    $text = $values[$field_of[$alias] ?? ''] ?? null;
                    if (($text === null) || (($op === '=') ? ($text !== $value) : !str_starts_with($text, rtrim($value, '%')))) {
                        continue 2;
                    }
                }
                $found[] = $id;
            }
            return $found;
        }

        /** the legacy search code warns about globals a view request would have */
        private static function quietly(callable $fn): mixed
        {
            set_error_handler(static fn(int $no): bool => ($no === E_WARNING));
            try {
                return $fn();
            } finally {
                restore_error_handler();
            }
        }

        /** @return string[] the item searches, in order */
        private static function searches(): array
        {
            return array_values(preg_grep('/ as itemid /', RecordingDb::$log));
        }

        public function test_validReaders_confirmedAndNotUnsubscribed(): void
        {
            $this->readers = [
                self::ANNA  => [FIELDID_MAIL_CONFIRMED => '1', self::HOWOFTEN => 'daily'],
                self::BOB   => [FIELDID_MAIL_CONFIRMED => '1', self::HOWOFTEN => 'unsubscribed:2026-11-01 10:00:au'],
                self::CYRIL => [FIELDID_MAIL_CONFIRMED => '0', self::HOWOFTEN => 'daily'],
                self::DORA  => [FIELDID_MAIL_CONFIRMED => '1', self::HOWOFTEN => 'unsub'],
            ];
            $this->readerSlice();

            $valid = self::quietly(static fn() => (new AA_Collection('sQjwa'))->validReaders());

            $this->assertSame([self::ANNA], $valid->longids());
            $searches = self::searches();
            $this->assertCount(2, $searches, 'the confirmed readers, then the unsubscribed ones - the rest is a set difference');
            $this->assertStringContainsString("c0.field_id='" . FIELDID_MAIL_CONFIRMED . "'", $searches[0]);
            $this->assertStringContainsString('"c0"."text" = \'1\'', $searches[0]);
            $this->assertStringContainsString("c0.field_id='" . self::HOWOFTEN . "'", $searches[1]);
            $this->assertStringContainsString('"c0"."text" LIKE \'unsub%\'', $searches[1]);
            foreach ($searches as $sql) {
                $this->assertStringContainsString('item.slice_id = 0x' . self::READERS, $sql);
                $this->assertStringContainsString('item.status_code=1', $sql, 'active readers only');
            }
        }

        /** safariparkresort.cz: most confirmed readers have no frequency row at all */
        public function test_validReaders_emptyOrMissingHowoften_isValid(): void
        {
            $this->readers = [
                self::ANNA => [FIELDID_MAIL_CONFIRMED => '1', self::HOWOFTEN => ''],
                self::BOB  => [FIELDID_MAIL_CONFIRMED => '1'],
            ];
            $this->readerSlice();

            $valid = self::quietly(static fn() => (new AA_Collection('sQjwa'))->validReaders());

            $this->assertSame([self::ANNA, self::BOB], $valid->longids());
        }

        public function test_validReaders_withConds_restricts(): void
        {
            $this->readers = [
                self::ANNA  => [FIELDID_MAIL_CONFIRMED => '1', self::CITY => 'Praha'],
                self::BOB   => [FIELDID_MAIL_CONFIRMED => '1', self::CITY => 'Brno'],
                self::CYRIL => [FIELDID_MAIL_CONFIRMED => '1', self::CITY => 'Praha', self::HOWOFTEN => 'unsubscribed'],
                self::DORA  => [FIELDID_MAIL_CONFIRMED => '0', self::CITY => 'Praha'],
            ];
            $this->readerSlice();

            $valid = self::quietly(static fn() => (new AA_Collection('sQjwa'))->validReaders('d-' . self::CITY . '-==-Praha'));

            $this->assertSame([self::ANNA], $valid->longids());
            $searches = self::searches();
            $this->assertCount(3, $searches);
            $this->assertStringContainsString('"c0"."text" = \'Praha\'', $searches[2]);
            $this->assertMatchesRegularExpression('/item\.id IN \(0x' . self::ANNA . ',\s*0x' . self::BOB . '\)/', $searches[2], 'only among the valid readers');
        }

        public function test_validReaders_withConds_noValidReaderAsksNoMore(): void
        {
            $this->readers = [self::ANNA => [FIELDID_MAIL_CONFIRMED => '0', self::CITY => 'Praha']];
            $this->readerSlice();

            $valid = self::quietly(static fn() => (new AA_Collection('sQjwa'))->validReaders('d-' . self::CITY . '-==-Praha'));

            $this->assertTrue($valid->isEmpty(), 'an empty restriction finds nothing - not every reader of the slice');
            $this->assertCount(2, self::searches());
        }

        /** a collection row without a reader slice - nothing to search in */
        public function test_validReaders_noReaderSlice_isEmpty(): void
        {
            RecordingDb::$answer = static fn(string $sql): array => str_starts_with($sql, 'SELECT * FROM alerts_collection ')
                ? [['id' => 'sQjwa', 'slice_id' => '', 'module_id' => pack_id(self::MODULE), 'emailid_welcome' => '1', 'emailid_alert' => '2']] : [];

            $this->assertTrue((new AA_Collection('sQjwa'))->validReaders()->isEmpty());
            $this->assertSame([], self::searches());
        }

        /** a reader as GetItemContent() gives it: active in the reader slice, confirmed, subscribed, with an address
         *  @param array<string, mixed> $changes  field (or confirmed/email/howoften) => value, null = no such field */
        private static function reader(array $changes = []): ItemContent
        {
            $names  = ['confirmed' => FIELDID_MAIL_CONFIRMED, 'email' => FIELDID_EMAIL, 'howoften' => self::HOWOFTEN];
            $values = array_merge([
                'status_code.....' => 1,
                'publish_date....' => time() - 86400,
                'expiry_date.....' => time() + 86400,
                'confirmed'        => '1',
                'email'            => 'anna@example.org',
                'howoften'         => 'daily',
            ], $changes);
            $reader = new ItemContent();
            $reader->setSliceID(self::READERS);
            foreach ($values as $field => $value) {
                if ($value !== null) {
                    $reader->setValue($names[$field] ?? $field, $value);
                }
            }
            return $reader;
        }

        /** data providers run before setUpBeforeClass(), so they name the fields, the constants are not loaded yet */
        public static function readerChanges(): array
        {
            return [
                'valid'                 => [[], true],
                'no frequency field'    => [['howoften' => null], true],
                'empty frequency'       => [['howoften' => ''], true],
                'unsubscribed'          => [['howoften' => 'unsubscribed:2026-11-01 10:00:au'], false],
                'unconfirmed'           => [['confirmed' => '0'], false],
                'confirmation missing'  => [['confirmed' => null], false],
                'no email'              => [['email' => null], false],
                'blank email'           => [['email' => ' '], false],
                'in the holding bin'    => [['status_code.....' => 2], false],
                'expired'               => [['expiry_date.....' => time() - 60], false],
                'another slice'         => [[], false, 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'],
            ];
        }

        #[DataProvider('readerChanges')]
        public function test_isValidReader_unsubscribedUnconfirmedOrNoEmail_isFalse(array $changes, bool $expected, ?string $slice_id = null): void
        {
            $this->readerSlice();
            $reader = self::reader($changes);
            if ($slice_id !== null) {
                $reader->setSliceID($slice_id);
            }

            $this->assertSame($expected, (new AA_Collection('sQjwa'))->isValidReader($reader));
        }

        public function test_getIdAndModuleId(): void
        {
            $this->readerSlice();
            $collection = new AA_Collection('sQjwa');

            $this->assertSame('sQjwa', $collection->getId());
            $this->assertSame(self::MODULE, $collection->getModuleId());
        }
    }
}
