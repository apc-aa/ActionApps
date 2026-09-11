<?php

declare(strict_types=1);

/**
 * Characterization test for AA_Metabase::getContent() (include/metabase.class.php).
 *
 * The loader SELECTs the requested ids and returns AA_Content objects keyed by id,
 * unpacking the table's packed columns on the way. An id the SELECT did not return
 * must be absent from the result, not a null entry - a null entry crashed the unpack
 * loop with "Call to a member function setValue() on null"
 * (alerts/emails.php?id=<deleted>). Callers that guard the lookup (Manager,
 * AA_Form::loadContent()) then simply see "row does not exist".
 *
 * The DB is a RecordingDb answering the SELECT from an in-memory row list; no
 * database or session is involved.
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

    use AA_Metabase;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;
    use zids;

    final class MetabaseGetContentTest extends TestCase
    {
        private const MODULE = '67137e45742862e1ca08aafd1caea351';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            // the `email` table: ids 298 and 299 exist (answered in table order); owner_module_id is a packed column
            $rows = [
                ['id' => '298', 'description' => 'reminder', 'owner_module_id' => pack_id(self::MODULE)],
                ['id' => '299', 'description' => 'welcome', 'owner_module_id' => pack_id(self::MODULE)],
            ];
            RecordingDb::$answer = static fn(string $sql) => array_values(
                array_filter($rows, static fn(array $row) => str_contains($sql, "'{$row['id']}'")),
            );
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
        }

        /** Characterization: an existing row comes back keyed by id with packed columns unpacked. */
        public function test_existing_row_is_returned_unpacked(): void
        {
            $ret = AA_Metabase::getContent(new zids('299', 's'), ['table' => 'email']);

            $this->assertSame([299], array_keys($ret));   // PHP casts the numeric-string key to int
            $this->assertSame('welcome', $ret['299']->getValue('description'));
            $this->assertSame(self::MODULE, $ret['299']->getValue('owner_module_id'));
            $this->assertSame(1, RecordingDb::count('SELECT * FROM email WHERE'));
        }

        /** Regression: an id the SELECT did not return is simply absent - no null entry, no fatal. */
        public function test_missing_row_is_absent_from_result(): void
        {
            $ret = AA_Metabase::getContent(new zids('300', 's'), ['table' => 'email']);

            $this->assertSame([], $ret);
        }

        /** Regression: a mix keeps the existing rows and drops only the missing ones. */
        public function test_mixed_request_keeps_only_existing_rows(): void
        {
            $ret = AA_Metabase::getContent(new zids(['300', '299'], 's'), ['table' => 'email']);

            $this->assertSame([299], array_keys($ret));   // PHP casts the numeric-string key to int
            $this->assertSame(self::MODULE, $ret['299']->getValue('owner_module_id'));
        }

        /** Characterization: the result follows the requested order, not the order the SELECT returned. */
        public function test_result_keeps_requested_order(): void
        {
            $ret = AA_Metabase::getContent(new zids(['299', '298'], 's'), ['table' => 'email']);

            $this->assertSame([299, 298], array_keys($ret));
            $this->assertSame('reminder', $ret['298']->getValue('description'));
        }
    }
}
