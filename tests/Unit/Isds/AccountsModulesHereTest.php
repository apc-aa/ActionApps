<?php

declare(strict_types=1);

// Accounts::modulesHere() runs on DB_AA::select(), whose getDB()/freeDB() live in
// include/locsess.php (loaded by the RecordingDb fixture) and need the constants
// include/config.php normally provides. Safe test values, defined first.

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

namespace Tests\Unit\Isds {

    use AA\Isds\Accounts;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * The one query State Check asks which sites and slices of the accounts are modules of this database,
     * against a recording DB handle: the ids packed, the answer a list of ids - a wrong one would make
     * every account one of another database, and nothing would be checked.
     */
    final class AccountsModulesHereTest extends TestCase
    {
        private const SITE  = '51de0000000000000000000000000001';
        private const SLICE = 'cafe0000000000000000000000000001';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
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

        public function test_modulesHere_theIdsTheDatabaseKnows(): void
        {
            RecordingDb::$answer = static fn(string $sql): array => [['id' => self::SLICE]];

            self::assertSame([self::SLICE], Accounts::modulesHere([self::SITE, self::SLICE]));
            self::assertSame(['SELECT LOWER(HEX(`id`)) AS id FROM `module` WHERE id IN (0x' . self::SITE . ',0x' . self::SLICE . ')'], RecordingDb::$log);
        }

        public function test_modulesHere_noIdNoQuery(): void
        {
            self::assertSame([], Accounts::modulesHere([]));
            self::assertSame([], RecordingDb::$log);
        }
    }
}
