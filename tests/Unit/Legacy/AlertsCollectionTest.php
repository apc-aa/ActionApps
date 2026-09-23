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

    use AA_Collection;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * AA_Collection (modules/alerts/alerts_sending.php): the id check for ids arriving from
     * a request (AA_Responder_Alertitem) and the filter lookup that used to splice the id into
     * its SQL - `xx' #` there mailed the filters of every collection to the caller - and the
     * test-address rule, where a bad address used to mean "none", i.e. a live send to all readers.
     */
    final class AlertsCollectionTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../modules/alerts/alerts_sending.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
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
    }
}
