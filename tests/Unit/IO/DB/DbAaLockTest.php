<?php

declare(strict_types=1);

// DB_AA::lock() builds its connection from dbConnectionParams() in include/locsess.php (loaded by
// the store fixtures), which needs the constants include/config.php normally provides.

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 4) . '/',
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

namespace Tests\Unit\IO\DB {

    use AA\IO\DB\DB_AA;
    use AA\IO\DB\DB_PDO;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use ReflectionProperty;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * A named lock belongs to the MySQL connection that took it: released on another one it stays
     * held, and on a persistent connection a fatal error would leave it held for the next request
     * of the same PHP process. So DB_AA keeps all locks on one connection of its own.
     */
    final class DbAaLockTest extends TestCase
    {
        private RecordingDb $lock_db;

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../../Legacy/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $this->lock_db       = new RecordingDb();
            $GLOBALS['spareDBs'] = [];
            self::lockDb($this->lock_db);
        }

        protected function tearDown(): void
        {
            self::lockDb(null);
            $GLOBALS['spareDBs'] = [];
        }

        /** the connection DB_AA takes its locks on - a database of its own for the test */
        public static function lockDb(?DB_PDO $db): void
        {
            (new ReflectionProperty(DB_AA::class, 'lock_db'))->setValue(null, $db);
        }

        public function test_lock_free_isGot_andReleasedOnTheSameConnection(): void
        {
            RecordingDb::$answer = static fn(string $sql) => [['got' => '1', 'holder' => '7']];

            self::assertTrue(DB_AA::lock('aa_test_job', 0, $holder));
            DB_AA::unlock('aa_test_job');

            self::assertNull($holder, 'nobody else holds it');
            self::assertSame([
                "SELECT GET_LOCK('aa_test_job_aa_test', 0) AS got, IS_USED_LOCK('aa_test_job_aa_test') AS holder",
                "SELECT RELEASE_LOCK('aa_test_job_aa_test')",
            ], RecordingDb::$log, 'the name is per database');
            self::assertSame([spl_object_id($this->lock_db), spl_object_id($this->lock_db)], RecordingDb::$handles);
            self::assertSame([], $GLOBALS['spareDBs'], 'the lock connection never goes to the pool');
        }

        public function test_lock_held_isNotGot_andNamesTheHolder(): void
        {
            RecordingDb::$answer = static fn(string $sql) => [['got' => '0', 'holder' => '4711']];

            self::assertFalse(DB_AA::lock('aa_test_job', 10, $holder));

            self::assertSame(4711, $holder);
            self::assertStringContainsString("GET_LOCK('aa_test_job_aa_test', 10)", RecordingDb::$log[0]);
        }

        public function test_lock_error_isNotGot(): void
        {
            RecordingDb::$answer = static fn(string $sql) => [['got' => null, 'holder' => null]];

            self::assertFalse(DB_AA::lock('aa_test_job', 0, $holder));
            self::assertNull($holder);
        }

        public function test_lock_longName_fitsTheMysqlLimit(): void
        {
            RecordingDb::$answer = static fn(string $sql) => [['got' => '1', 'holder' => '7']];
            $name = str_repeat('x', 70);

            DB_AA::lock($name);

            $expected = 'aa_' . md5($name . '_aa_test');
            self::assertStringContainsString("GET_LOCK('$expected', 0)", RecordingDb::$log[0]);
            self::assertLessThanOrEqual(64, strlen($expected));
        }

        public function test_lock_insideATransaction_staysOffItsConnection(): void
        {
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, 'GET_LOCK') ? [['got' => '1', 'holder' => '7']] : [['x' => '1']];
            $tx_db = new RecordingDb();
            $GLOBALS['spareDBs'] = [$tx_db];

            DB_AA::transaction(static function (): void {
                DB_AA::lock('aa_test_job');
                DB_AA::select1('x', 'SELECT x FROM t');   // the transaction's one connection is free for this
                DB_AA::unlock('aa_test_job');
            });

            // BEGIN and COMMIT are logged without a handle
            $statements = array_values(array_diff(RecordingDb::$log, ['BEGIN', 'COMMIT']));
            self::assertSame(['BEGIN', 'COMMIT'], array_values(array_intersect(RecordingDb::$log, ['BEGIN', 'COMMIT'])), 'it ran in a transaction');
            $lock = spl_object_id($this->lock_db);
            foreach ($statements as $i => $sql) {
                self::assertSame(str_contains($sql, '_LOCK(') ? $lock : spl_object_id($tx_db), RecordingDb::$handles[$i], $sql);
            }
            self::assertCount(3, $statements);
            self::assertSame([$tx_db], $GLOBALS['spareDBs'], 'the transaction connection is back in the pool');
        }

        public function test_lockConnection_isNeverPersistent(): void
        {
            $params = (new ReflectionMethod(DB_AA::class, 'lockConnectionParams'))->invoke(null);

            self::assertFalse($params['persistent']);
            self::assertSame(constant('DB_NAME'), $params['database']);
        }

        public function test_lock_onAFailedConnection_isNotGot(): void
        {
            // halting is off on the lock connection, so a statement on a lost connection answers false instead of dying
            $lost = new class (['type' => 'db_pdo:sqlite', 'database' => ':memory:']) extends DB_PDO {
                public function query($Query_String): bool
                {
                    return false;
                }
            };
            $lost->set_halt('no');
            self::lockDb($lost);

            self::assertFalse(DB_AA::lock('aa_test_job', 0, $holder));
            self::assertNull($holder);
        }
    }
}
