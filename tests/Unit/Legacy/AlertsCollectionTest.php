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

    use AA\Optimize\CheckAlertitemRefusals;
    use AA\Util\LogOnce;
    use AA_Collection;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * AA_Collection (modules/alerts/alerts_sending.php): the id check for ids arriving from
     * a request (AA_Responder_Alertitem) and the filter lookup that used to splice the id into
     * its SQL - `xx' #` there mailed the filters of every collection to the caller - and the
     * test-address rule, where a bad address used to mean "none", i.e. a live send to all readers -
     * and the relation of a collection to the slice whose item is sent through it.
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
    }
}
