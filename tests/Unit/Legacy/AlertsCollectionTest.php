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
        'DEFAULT_LANG_INCLUDE' => 'en-utf8_news_lang.php',
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

        /** sendEmails() sets the language of the mails for the rest of the request */
        private mixed $xlang = null;

        protected function setUp(): void
        {
            RecordingDb::reset();
            LogOnce::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $this->xlang = $GLOBALS['apc_state']['xlang'] ?? null;
        }

        protected function tearDown(): void
        {
            $this->forgetReaderSlice();
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            LogOnce::reset();
            if ($this->xlang === null) {
                unset($GLOBALS['apc_state']['xlang']);
            } else {
                $GLOBALS['apc_state']['xlang'] = $this->xlang;
            }
            // sendEmails() registers the reading password of the reader slice for the request
            (new \ReflectionProperty(\AA_Credentials::class, 'instance'))->setValue(null, null);
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

        /** alerts_collection.stat_slice_id as the database gives it (packed, NULL, '') */
        private ?string $statSlice = null;

        /** AA_Module keeps every module it loaded for the request - the reader slice, and the Alerts module
         *  sendEmails() asks for its language */
        private function forgetReaderSlice(): void
        {
            Closure::bind(static function (string ...$ids): void {
                foreach ($ids as $id) {
                    unset(AA_Module::$_modules[$id]);
                }
            }, null, AA_Module::class)($this->readerSliceId, self::MODULE);
        }

        /** the reader slice of the collection */
        private string $readerSliceId = self::READERS;

        /** RecordingDb answers: the collection, the reader slice and the item searches over $readers
         *  @param string[]|null $fields  fields of the reader slice, null = confirmed, e-mail, frequency and city
         *  @param string        $slice   another id for other fields - AA_Fields keeps the fields of a slice for the process */
        private function readerSlice(?array $fields = null, string $slice = self::READERS): void
        {
            $this->readerSliceId = $slice;
            $fields ??= [FIELDID_MAIL_CONFIRMED, FIELDID_EMAIL, self::HOWOFTEN, self::CITY];
            RecordingDb::$answer = function (string $sql) use ($fields): array {
                if (str_starts_with($sql, 'SELECT * FROM alerts_collection ')) {
                    return [['id' => 'sQjwa', 'slice_id' => pack_id($this->readerSliceId), 'module_id' => pack_id(self::MODULE), 'emailid_welcome' => '1', 'emailid_alert' => '2', 'stat_slice_id' => $this->statSlice]];
                }
                if (str_contains($sql, 'FROM `module`')) {
                    return [['unpackid' => self::READERS, 'type' => 'S']];
                }
                if (preg_match('/FROM `slice` WHERE id = 0x([0-9a-f]{32})/', $sql, $m)) {
                    return [array_merge(array_fill_keys(AA::Metabase()->getColumnNames('slice'), ''), ['id' => pack_id($m[1]), 'type' => 'ReaderManagement', 'name' => 'Readers', 'flag' => '0'])];
                }
                if (preg_match('/FROM `field` WHERE `?slice_id`? = 0x([0-9a-f]{32})/', $sql, $m)) {
                    // one field (AA_Field::factoryFromDb()) or all of them
                    $asked = preg_match("/ AND id = '([^']*)'/", $sql, $id) ? array_intersect($fields, [$id[1]]) : $fields;
                    return array_map(
                        static fn(string $id): array => array_merge(array_fill_keys(AA::Metabase()->getColumnNames('field'), ''), ['id' => $id, 'slice_id' => pack_id($m[1]), 'text_stored' => '1']),
                        array_values($asked),
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
            $this->assertCount(2, $searches, 'the conds with the confirmation in one search, then the unsubscribed readers');
            $this->assertStringContainsString("c0.field_id='" . self::CITY . "'", $searches[0]);
            $this->assertStringContainsString('"c0"."text" = \'Praha\'', $searches[0]);
            $this->assertStringContainsString("c1.field_id='" . FIELDID_MAIL_CONFIRMED . "'", $searches[0]);
            $this->assertStringNotContainsString('item.id IN', $searches[0], 'no id list of every valid reader');
            $this->assertStringContainsString('"c0"."text" LIKE \'unsub%\'', $searches[1]);
        }

        public function test_validReaders_withConds_noValidReaderAsksNoMore(): void
        {
            $this->readers = [self::ANNA => [FIELDID_MAIL_CONFIRMED => '0', self::CITY => 'Praha']];
            $this->readerSlice();

            $valid = self::quietly(static fn() => (new AA_Collection('sQjwa'))->validReaders('d-' . self::CITY . '-==-Praha'));

            $this->assertTrue($valid->isEmpty());
            $this->assertCount(1, self::searches(), 'nobody found - nobody to have unsubscribed');
        }

        /** a reader slice without the frequency field of the collection - a condition on it would be dropped,
         *  match everyone and leave nobody */
        public function test_validReaders_noHowoftenField_everyConfirmedIsValid(): void
        {
            $this->readers = [
                self::ANNA => [FIELDID_MAIL_CONFIRMED => '1'],
                self::BOB  => [FIELDID_MAIL_CONFIRMED => '0'],
            ];
            $this->readerSlice([FIELDID_MAIL_CONFIRMED, FIELDID_EMAIL], 'f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3');

            $valid = self::quietly(static fn() => (new AA_Collection('sQjwa'))->validReaders());

            $this->assertSame([self::ANNA], $valid->longids());
            $this->assertCount(1, self::searches());
        }

        public static function condsOnUnknownFields(): array
        {
            return [
                'unknown field'          => ['d-text...........9-==-Praha'],
                'one of two unknown'     => ['d-text...........1,text...........9-==-Praha'],
                'unknown in 2nd cond'    => ['d-text...........1-==-Praha-text...........9-==-x'],
                'unknown relation field' => ['d-headline........@relation.......9-==-x'],
                'unknown with language'  => ['d-text...........9|en-==-Praha'],
            ];
        }

        /** AA_Set drops a condition on a field the slice lacks - the mailing would go to every reader */
        #[DataProvider('condsOnUnknownFields')]
        public function test_validReaders_condsOnUnknownField_findNobody(string $conds): void
        {
            $this->readers = [self::ANNA => [FIELDID_MAIL_CONFIRMED => '1', self::CITY => 'Praha']];
            $this->readerSlice();

            $valid = self::quietly(static fn() => (new AA_Collection('sQjwa'))->validReaders($conds));

            $this->assertTrue($valid->isEmpty());
            $this->assertSame([], self::searches());
        }

        public function test_validReaders_noConfirmationField_findNobody(): void
        {
            $this->readers = [self::ANNA => [FIELDID_MAIL_CONFIRMED => '1']];
            $this->readerSlice([FIELDID_EMAIL, self::HOWOFTEN], 'f4f4f4f4f4f4f4f4f4f4f4f4f4f4f4f4');

            $this->assertTrue(self::quietly(static fn() => (new AA_Collection('sQjwa'))->validReaders())->isEmpty());
        }

        public function test_validReaders_condsOnLanguageOfKnownField_search(): void
        {
            $this->readers = [self::ANNA => [FIELDID_MAIL_CONFIRMED => '1', self::CITY => 'Praha']];
            $this->readerSlice();

            self::quietly(static fn() => (new AA_Collection('sQjwa'))->validReaders('d-' . self::CITY . '|en-==-Praha'));

            $this->assertNotSame([], self::searches());
        }

        private const REMOTE   = 'f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9';
        private const RELATION = 'relation........';

        /** the reader slice $slice with a field relation........ shown by $show_func, and the slice REMOTE with a headline */
        private function relatedSlices(string $slice, string $show_func): void
        {
            $this->readerSlice([FIELDID_MAIL_CONFIRMED, FIELDID_EMAIL, self::HOWOFTEN, self::CITY, self::RELATION], $slice);
            $inner = RecordingDb::$answer;
            RecordingDb::$answer = static function (string $sql) use ($inner, $show_func): array {
                if (preg_match('/FROM `field` WHERE `?slice_id`? = 0x' . self::REMOTE . '/', $sql)) {
                    $asked = preg_match("/ AND id = '([^']*)'/", $sql, $id) ? array_intersect(['headline........'], [$id[1]]) : ['headline........'];
                    return array_map(
                        static fn(string $fid): array => array_merge(array_fill_keys(AA::Metabase()->getColumnNames('field'), ''), ['id' => $fid, 'slice_id' => pack_id(self::REMOTE), 'text_stored' => '1']),
                        array_values($asked),
                    );
                }
                $rows = $inner($sql);
                return str_contains($sql, 'FROM `field`')
                    ? array_map(static fn(array $row): array => ($row['id'] === self::RELATION) ? ['input_show_func' => $show_func] + $row : $row, $rows)
                    : $rows;
            };
        }

        public static function condsFields(): array
        {
            $points = 'sel:#sLiCe-f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9f9';
            return [
                'known field'                 => ['e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e1', $points, 'd-text...........1-==-Praha', []],
                'unknown field'               => ['e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e2', $points, 'd-text...........9-==-Praha-text...........1-==-x', ['text...........9-==-Praha']],
                'far field of the relation'   => ['e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e3', $points, 'd-headline........@relation........-==-x', []],
                'far field the target lacks'  => ['e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e4', $points, 'd-text...........7@relation........-==-x', ['text...........7@relation........-==-x']],
                'not a relation field'        => ['e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e5', 'fld', 'd-headline........@relation........-==-x', ['headline........@relation........-==-x']],
                'any field of the relation'   => ['e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e6', $points, 'd-all_fields@relation........-==-x', []],
                'empty value'                 => ['e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e7', $points, 'd-text...........1-==-', ['text...........1-==-']],
                'no condition at all'         => ['e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8', $points, 'text...........1-==-Praha', ['text...........1-==-Praha']],
                'AAnoCONDITION only'          => ['e8e8e8e8e8e8e8e8e8e8e8e8e8e8e8e9', $points, 'd-text...........1-==-AAnoCONDITION', ['d-text...........1-==-AAnoCONDITION']],
            ];
        }

        /** AA_Set leaves such conditions out of the search - the mailing would go to more readers than asked */
        #[DataProvider('condsFields')]
        public function test_droppedConds_namesWhatTheSearchLeavesOut(string $slice, string $show_func, string $conds, array $expected): void
        {
            $this->relatedSlices($slice, $show_func);

            $this->assertSame($expected, self::quietly(static fn() => (new AA_Collection('sQjwa'))->droppedConds($conds)));
            if ($expected) {
                $this->assertTrue(self::quietly(static fn() => (new AA_Collection('sQjwa'))->validReaders($conds))->isEmpty());
                $this->assertSame([], self::searches());
            }
        }

        public function test_droppedConds_noConfirmationField_namesTheConfirmation(): void
        {
            $this->readerSlice([FIELDID_EMAIL, self::HOWOFTEN], 'f4f4f4f4f4f4f4f4f4f4f4f4f4f4f4f5');

            $this->assertSame([FIELDID_MAIL_CONFIRMED . '-==-1'], self::quietly(static fn() => (new AA_Collection('sQjwa'))->droppedConds('')));
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
                'unsubscribed in caps'  => [['howoften' => 'UNSUBSCRIBED'], false],   // LIKE of the search ignores case too
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

        // ---------------------------------------------------------------- the mail of a collection

        private const SLICE_URL   = 'https://example.org/news';
        private const ACCESS_CODE = 'k3y5x';
        /** the alert template: both links in the body, so they reach the queued mail */
        private const ALERT_BODY = '<p>U=_#UNSBFORM F=_#COLLFORM</p>';

        /** readerSlice() plus the Alerts module's slice_url, the alert template and reader Anna's content */
        private function mailingWorld(string $slice_url = self::SLICE_URL): void
        {
            self::nothingSentAtOnce();
            $this->readers = [self::ANNA => [FIELDID_MAIL_CONFIRMED => '1', FIELDID_EMAIL => 'anna@example.org', FIELDID_ACCESS_CODE => self::ACCESS_CODE]];
            $this->readerSlice();
            $slice = RecordingDb::$answer;
            RecordingDb::$answer = function (string $sql) use ($slice, $slice_url): array {
                if (preg_match('/^SELECT \\* FROM item WHERE\s+item\.id = 0x([0-9a-f]{32})/', $sql, $m) && isset($this->readers[$m[1]])) {
                    return [array_merge(array_fill_keys(AA::Metabase()->getColumnNames('item'), ''), [
                        'id' => pack_id($m[1]), 'slice_id' => pack_id(self::READERS), 'status_code' => '1', 'publish_date' => '1', 'expiry_date' => '2000000000',
                    ])];
                }
                if (preg_match('/^SELECT \\* FROM content WHERE item_id = 0x([0-9a-f]{32})/', $sql, $m)) {
                    $rows = [];
                    foreach ($this->readers[$m[1]] ?? [] as $field => $text) {
                        $rows[] = ['item_id' => pack_id($m[1]), 'field_id' => $field, 'number' => '0', 'text' => $text, 'flag' => '0'];
                    }
                    return $rows;
                }
                if (str_starts_with($sql, 'SELECT slice_url FROM')) {
                    return [['slice_url' => $slice_url]];
                }
                if (str_contains($sql, 'FROM email') || str_contains($sql, 'FROM `email`')) {
                    return [array_merge(array_fill_keys(AA::Metabase()->getColumnNames('email'), ''), [
                        'id' => '2', 'subject' => 'News', 'body' => self::ALERT_BODY, 'header_from' => 'news@example.org', 'html' => '1', 'lang' => 'cz_utf8',
                    ])];
                }
                return $slice($sql);
            };
        }

        /** AA_Mail::sendLater() sends the first two mails of a process at once (by mail(), for real) - spend
         *  them on a mail that only pretends, so the mails of the test go to the queue, where they can be read */
        private static function nothingSentAtOnce(): void
        {
            $mail = new class extends \AA_Mail {
                protected function nativeMail(string $to, string $subject, string $body, string $headers, string $params = ''): bool
                {
                    return true;
                }
            };
            $mail->setFromArray(['subject' => 'x', 'body' => 'x', 'header_from' => 'test@example.org', 'reply_to' => '', 'errors_to' => '', 'sender' => '',
                'lang' => 'utf-8', 'html' => 0, 'cc' => '', 'bcc' => '', 'attachments' => '']);
            try {
                self::quietly(static fn() => $mail->sendLater(['one@example.org', 'two@example.org']));
            } catch (\Exception) {
                // spent already: this mail went for the queue, where an anonymous class cannot go
            }
            RecordingDb::$log = [];
        }

        /** @return string[] the mails queued, as their INSERTs into toexecute */
        private static function queuedMails(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn(string $sql) => str_starts_with($sql, 'INSERT INTO `toexecute`')));
        }

        /** @return array<string, string> the headers of a queued mail */
        private static function headersOf(string $insert): array
        {
            preg_match("/,'(O:7:.*)','a:/s", $insert, $m);
            $mail = (array) unserialize(stripslashes($m[1]), ['allowed_classes' => false]);
            return $mail["\0htmlMimeMail\0headers"];
        }

        public function test_sendEmails_readerMail_linksOfTheReader(): void
        {
            $this->mailingWorld();
            $collection = self::collection();

            $sent = self::quietly(static fn() => $collection->sendEmails('daily', [], false, '', self::ANNA));

            $this->assertSame(1, $sent);
            $mails = self::queuedMails();
            $this->assertCount(1, $mails);
            $this->assertStringContainsString('U=' . self::SLICE_URL . '?au=' . self::ACCESS_CODE . '&c=sQjwa F=' . self::SLICE_URL . '?ac=' . self::ACCESS_CODE . '<', $mails[0]);
            $this->assertStringContainsString('anna@example.org', $mails[0]);
        }

        /** a test send goes to the given addresses with the code ABCDE, which belongs to no reader */
        public function test_sendEmails_testMail_linksOfCodeABCDE(): void
        {
            $this->mailingWorld();
            $collection = self::collection();

            $sent = self::quietly(static fn() => $collection->sendEmails('daily', ['test@example.org'], false, '', null));

            $this->assertSame(1, $sent);
            $mails = self::queuedMails();
            $this->assertCount(1, $mails);
            $this->assertStringContainsString('U=' . self::SLICE_URL . '?au=ABCDE&c=sQjwa F=' . self::SLICE_URL . '?ac=ABCDE<', $mails[0]);
            $this->assertStringContainsString('test@example.org', $mails[0]);
        }

        public function test_readerLinks_unsubscribeAndForm(): void
        {
            $this->mailingWorld();

            $this->assertSame([
                'unsubscribe' => self::SLICE_URL . '?au=' . self::ACCESS_CODE . '&c=sQjwa',
                'form'        => self::SLICE_URL . '?ac=' . self::ACCESS_CODE,
            ], (new AA_Collection('sQjwa'))->readerLinks(self::ACCESS_CODE));
        }

        public function test_unsubscribeHeaders_https_bothHeaders(): void
        {
            $this->assertSame([
                'List-Unsubscribe'      => '<https://example.org/news?one=1&au=k3y5x&c=sQjwa>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ], AA_Collection::unsubscribeHeaders('https://example.org/news?one=1&au=k3y5x&c=sQjwa'));
        }

        /** a test mail or a reader without a code: the link would unsubscribe nobody */
        public function test_unsubscribeHeaders_noUrl_none(): void
        {
            $this->assertSame([], AA_Collection::unsubscribeHeaders(null));
        }

        public static function urlsWithoutHeaders(): array
        {
            return [
                'http'             => ['http://example.org/news?au=k3y5x&c=sQjwa'],
                'no scheme'        => ['example.org/news?au=k3y5x&c=sQjwa'],
                'relative'         => ['/news?au=k3y5x&c=sQjwa'],
                'empty'            => [''],
                'upper-case HTTPS' => ['HTTPS://example.org/news'],
                'header injection' => ["https://example.org/news\r\nBcc: x@example.com"],
                'line feed'        => ["https://example.org/news\nBcc: x@example.com"],
                'nul byte'         => ["https://example.org/news\0"],
                'space'            => ['https://example.org/my news'],
                'closing bracket'  => ['https://example.org/news>, <mailto:x@example.com'],
                'host missing'     => ['https://'],
                '8-bit'            => ['https://example.org/zprávy'],
            ];
        }

        /** mail services post only to https; a URL that would break the header gives none */
        #[DataProvider('urlsWithoutHeaders')]
        public function test_unsubscribeHeaders_http_none(string $url): void
        {
            $this->assertSame([], AA_Collection::unsubscribeHeaders($url));
        }

        public function test_oneClickUrl_https_marked(): void
        {
            $this->assertSame('https://example.org/news?one=1&au=k3y5x&c=sQjwa', AA_Collection::oneClickUrl('https://example.org/news?au=k3y5x&c=sQjwa'));
        }

        /** the body link is marked on http too - only the header needs https */
        public function test_oneClickUrl_http_markedToo(): void
        {
            $this->assertSame('http://example.org/news?one=1&au=k3y5x&c=sQjwa', AA_Collection::oneClickUrl('http://example.org/news?au=k3y5x&c=sQjwa'));
        }

        public function test_fitsHeader_https_true(): void
        {
            $this->assertTrue(AA_Collection::fitsHeader('https://example.org/news?au=x&c=x'));
        }

        #[DataProvider('urlsWithoutHeaders')]
        public function test_fitsHeader_http_false(string $url): void
        {
            $this->assertFalse(AA_Collection::fitsHeader($url));
        }

        public function test_sendEmails_readerMail_carriesUnsubscribeHeaders(): void
        {
            $this->mailingWorld();
            $collection = self::collection();

            self::quietly(static fn() => $collection->sendEmails('daily', [], false, '', self::ANNA));

            $headers = self::headersOf(self::queuedMails()[0]);
            $this->assertSame('<' . self::SLICE_URL . '?one=1&au=' . self::ACCESS_CODE . '&c=sQjwa>', $headers['List-Unsubscribe'] ?? null);
            $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post'] ?? null);
        }

        /** ABCDE is no reader's code - a one-click unsubscribe from a test mail would unsubscribe nobody */
        public function test_sendEmails_testMail_carriesNone(): void
        {
            $this->mailingWorld();
            $collection = self::collection();

            self::quietly(static fn() => $collection->sendEmails('daily', ['test@example.org'], false, '', null));

            $headers = self::headersOf(self::queuedMails()[0]);
            $this->assertArrayNotHasKey('List-Unsubscribe', $headers);
            $this->assertArrayNotHasKey('List-Unsubscribe-Post', $headers);
        }

        /** the link would unsubscribe nobody */
        public function test_sendEmails_readerWithoutAccessCode_carriesNone(): void
        {
            $this->mailingWorld();
            unset($this->readers[self::ANNA][FIELDID_ACCESS_CODE]);
            $collection = self::collection();

            self::quietly(static fn() => $collection->sendEmails('daily', [], false, '', self::ANNA));

            $mails = self::queuedMails();
            $this->assertCount(1, $mails);
            $this->assertArrayNotHasKey('List-Unsubscribe', self::headersOf($mails[0]));
        }

        public function test_sendEmails_httpSliceUrl_readerMailCarriesNone(): void
        {
            $this->mailingWorld('http://example.org/news');
            $collection = self::collection();

            self::quietly(static fn() => $collection->sendEmails('daily', [], false, '', self::ANNA));

            $mails = self::queuedMails();
            $this->assertCount(1, $mails, 'the mail goes, only without the headers');
            $this->assertArrayNotHasKey('List-Unsubscribe', self::headersOf($mails[0]));
        }

        /** a collection whose filters found news for everybody - the filter views are not what is tested here */
        private static function collection(): AA_Collection
        {
            return new class ('sQjwa') extends AA_Collection {
                public function createFilterText($ho, $update, $item_id): array
                {
                    return [];
                }

                public function getFilterText4Reader($readerContent, array $filters): string
                {
                    return '<p>news</p>';
                }
            };
        }

        public function test_getIdAndModuleId(): void
        {
            $this->readerSlice();
            $collection = new AA_Collection('sQjwa');

            $this->assertSame('sQjwa', $collection->getId());
            $this->assertSame(self::MODULE, $collection->getModuleId());
        }

        // ---------------------------------------------------------------- the language of the mails

        /** the Alerts module of the collection, in the language of $lang_file */
        private function alertsModule(string $lang_file): void
        {
            Closure::bind(static function (string $id): void {
                AA_Module::$_modules[$id] = new \AA_Module_Alerts($id);
            }, null, AA_Module::class)(self::MODULE);
            $inner = RecordingDb::$answer;
            RecordingDb::$answer = static fn(string $sql): array => str_starts_with($sql, 'SELECT name, deleted, slice_url, lang_file')
                ? [['name' => 'News', 'deleted' => '0', 'slice_url' => self::SLICE_URL, 'lang_file' => $lang_file]]
                : $inner($sql);
        }

        public function test_lang_twoLettersOfTheAlertsModule(): void
        {
            $this->readerSlice();
            $this->alertsModule('de-utf8_news_lang.php');

            $this->assertSame('de', (new AA_Collection('sQjwa'))->lang());
        }

        public function test_lang_noAlertsModule_none(): void
        {
            $this->readerSlice();

            $this->assertSame('', (new AA_Collection('sQjwa'))->lang());
        }

        /** the mails of the module are in its language, and the rest of the request stays so */
        public function test_sendEmails_setsTheLanguageOfTheAlertsModule(): void
        {
            $this->mailingWorld();
            $this->alertsModule('de-utf8_news_lang.php');

            self::quietly(static fn() => self::collection()->sendEmails('daily', ['test@example.org'], false, '', null));

            $this->assertSame('de', $GLOBALS['apc_state']['xlang'] ?? null);
        }

        public function test_inLang_onlyWhileRunning(): void
        {
            $GLOBALS['apc_state']['xlang'] = 'cz';

            $this->assertSame('de', AA_Collection::inLang('de', static fn(): string => $GLOBALS['apc_state']['xlang']));
            $this->assertSame('cz', $GLOBALS['apc_state']['xlang']);
            $this->assertSame('cz', AA_Collection::inLang('', static fn(): string => $GLOBALS['apc_state']['xlang']), "'' keeps the language");

            unset($GLOBALS['apc_state']['xlang']);
            try {
                AA_Collection::inLang('de', static fn() => throw new \RuntimeException('x'));
            } catch (\RuntimeException) {
            }
            $this->assertArrayNotHasKey('xlang', $GLOBALS['apc_state'], 'set back also when the expansion fails');
        }

        // ---------------------------------------------------------------- the statistics slice

        private const STATS = 'e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5';

        public static function storedStatSlices(): array
        {
            return [
                'set'          => [pack_id(self::STATS), self::STATS],
                'NULL'         => [null, ''],
                'empty'        => ['', ''],
            ];
        }

        #[DataProvider('storedStatSlices')]
        public function test_getStatSliceId(?string $stored, string $expected): void
        {
            $this->statSlice = $stored;
            $this->readerSlice();

            $this->assertSame($expected, (new AA_Collection('sQjwa'))->getStatSliceId());
        }

        public function test_statSlice_missingFields_areNamed(): void
        {
            $this->assertSame(['relation.......1', 'source_href.....'], AA_Collection::missingStatFields(['headline........', 'relation........', 'category........']));
            $this->assertSame([], AA_Collection::missingStatFields(array_merge(['headline........'], AA_Collection::STAT_FIELDS)));
        }

        /** RecordingDb answers the fields of the slice and the collection already measuring into it (null = none)
         *  @param bool $live    the slice is a live slice module
         *  @param bool $readers the slice is the reader slice of the collection */
        /** @param string $history the historylog setting of the slice; null = never set */
        private static function statSliceWorld(array $fields, ?array $other, bool $live = true, bool $readers = false, ?string $history = '0', string $password = ''): void
        {
            // the slice as this world has it, not as an earlier one left it in the module cache
            $modules = new \ReflectionProperty(\AA_Module::class, '_modules');
            $modules->setValue(null, array_diff_key((array) $modules->getValue(), [self::STATS => true]));
            RecordingDb::$answer = static fn(string $sql): array => match (true) {
                str_contains($sql, "FROM `object_text` WHERE object_id")      => ($history === null) ? [] : [
                    ['tbl' => 'object_text', 'property' => 'aa_type', 'value' => 'AA_Modulesettings_Slice', 'priority' => 0],
                    ['tbl' => 'object_text', 'property' => 'historylog', 'value' => $history, 'priority' => 0],
                ],
                str_starts_with($sql, 'SELECT id FROM "slice" ')              => ($password === '') ? [] : [['id' => pack_id(self::STATS)]],
                str_starts_with($sql, 'SELECT id FROM field ')                 => array_map(static fn(string $id): array => ['id' => $id], $fields),
                str_contains($sql, 'FROM alerts_collection AS c')             => $other ? [$other] : [],
                str_starts_with($sql, 'SELECT id FROM "module" ')             => $live ? [['id' => pack_id(self::STATS)]] : [],
                str_starts_with($sql, 'SELECT id FROM "alerts_collection" ')  => $readers ? [['id' => 'sQjwa']] : [],
                default                                                       => [],
            };
        }

        public function test_statSlice_notALiveSlice_isRefused(): void
        {
            self::statSliceWorld(AA_Collection::STAT_FIELDS, null, false);

            $this->assertNotSame('', AA_Collection::statSliceRefusal(self::STATS, 'sQjwa'));
            $sql = RecordingDb::$log[0];
            $this->assertStringContainsString('id = 0x' . self::STATS, $sql);
            $this->assertStringContainsString("type = 'S'", $sql);
            $this->assertStringContainsString('deleted = 0', $sql);
        }

        public function test_statSlice_theReaderSlice_isRefused(): void
        {
            self::statSliceWorld(AA_Collection::STAT_FIELDS, null, true, true);

            $this->assertNotSame('', AA_Collection::statSliceRefusal(self::STATS, 'sQjwa'));
            $this->assertStringContainsString("id = 'sQjwa' AND slice_id = 0x" . self::STATS, RecordingDb::$log[1]);
        }

        public function test_statSlice_usedByAnotherCollection_isRefused(): void
        {
            self::statSliceWorld(AA_Collection::STAT_FIELDS, ['id' => 'Other', 'name' => 'Newsletter']);

            $refusal = AA_Collection::statSliceRefusal(self::STATS, 'sQjwa');

            $this->assertStringContainsString('Newsletter', $refusal);
            $sql = (string) end(RecordingDb::$log);
            $this->assertStringContainsString('c.stat_slice_id = 0x' . self::STATS, $sql);
            $this->assertStringContainsString("c.id <> 'sQjwa'", $sql, 'its own collection does not count');
        }

        public function test_statSlice_freeWithAllFields_isAccepted(): void
        {
            self::statSliceWorld(AA_Collection::STAT_FIELDS, null);

            $this->assertSame('', AA_Collection::statSliceRefusal(self::STATS, 'sQjwa'));
            $this->assertSame('', AA_Collection::statSliceRefusal(self::STATS, ''), 'a list being created has no collection yet');
        }

        public function test_statSlice_missingFields_isRefusedByName(): void
        {
            self::statSliceWorld(['relation........', 'category........'], null);

            $refusal = AA_Collection::statSliceRefusal(self::STATS, 'sQjwa');

            $this->assertStringContainsString('relation.......1', $refusal);
            $this->assertStringContainsString('source_href.....', $refusal);
            $this->assertStringNotContainsString('category........', $refusal);
        }

        /** each new event would write a row of the log with the address of the request */
        public function test_statSlice_keepingHistory_isRefused(): void
        {
            foreach ([null, 'ALL', '30'] as $history) {
                self::statSliceWorld(AA_Collection::STAT_FIELDS, null, history: $history);
                $this->assertStringContainsString('history', AA_Collection::statSliceRefusal(self::STATS, 'sQjwa'), (string) $history);
            }
        }

        public function test_statSliceWarning_noReadingPassword(): void
        {
            self::statSliceWorld(AA_Collection::STAT_FIELDS, null);
            $this->assertStringContainsString('reading password', AA_Collection::statSliceWarning(self::STATS));
            $this->assertStringContainsString("reading_password NOT LIKE ''", (string) end(RecordingDb::$log));

            self::statSliceWorld(AA_Collection::STAT_FIELDS, null, password: 'set');
            $this->assertSame('', AA_Collection::statSliceWarning(self::STATS));
        }

        public function test_statSlice_malformedId_isRefusedWithoutAsking(): void
        {
            $this->assertNotSame('', AA_Collection::statSliceRefusal("0123' #", 'sQjwa'));
            $this->assertSame([], RecordingDb::$log);
        }
    }
}
