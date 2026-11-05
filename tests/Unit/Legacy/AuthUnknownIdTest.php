<?php

declare(strict_types=1);

// RecordingDb (store_fixtures) loads the real locsess.php, which needs the constants include/config.php normally provides.

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
    use AA_Auth;
    use AA_Perm;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * A session keeps the user id it logged in with. After PERM_LIB changes (ldap -> sql) a session holding
     * an id no permission system knows must ask to log in again - not stay logged in without any right.
     * The same holds for a session of another database: there the same id is another user.
     */
    final class AuthUnknownIdTest extends TestCase
    {
        private mixed $perm = null;

        public static function setUpBeforeClass(): void
        {
            // loading locsess.php warns about the missing web request
            set_error_handler(static fn(): bool => true);
            try {
                require_once __DIR__ . '/fixtures/store_fixtures.php';
                require_once __DIR__ . '/../../../include/locauth.php';
            } finally {
                restore_error_handler();
            }
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $this->perm = AA::$perm;
            AA::$perm   = new AA_Perm(['sql', 'Reader']);
            $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
            $_SERVER['HTTP_HOST']   = 'aa.example.org';
            $_SERVER['REQUEST_URI'] = '/admin/index.php?x=1';
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            AA::$perm = $this->perm;
        }

        /** AA_Auth with the session data of a user logged in as $uid to the database $db (null: no mark), not expired */
        private static function sessionOf(string $uid, bool $nobody = false, ?string $db = DB_NAME): AA_Auth
        {
            $auth = new AA_Auth();
            $auth->set_nobody($nobody);
            $data = ['uid' => $uid, 'uname' => 'jan', 'perm' => '', 'exp' => time() + 600, 'refresh' => 0] + (is_null($db) ? [] : ['db' => $db]);
            (fn() => $this->auth = $data)->call($auth);
            return $auth;
        }

        public function test_isAuthenticated_dnSessionUnderSqlLogsOut(): void
        {
            $auth = self::sessionOf('uid=jan,ou=People,ou=AA');
            $this->assertFalse($auth->is_authenticated());
            $this->assertSame('', $auth->getUid());
            $this->assertSame('', $auth->getUname());
            [$row] = RecordingDb::inserts('log');
            $this->assertSame(['UNKNOWN_ID', 'jan'], [$row['type'], $row['params']]);
            $this->assertStringStartsWith('uid=jan,ou=People,ou=AA:', $row['selector']);
            $this->assertStringNotContainsString('x=1', $row['selector']);
            // logged out - the next check of the session does not log it again
            $this->assertFalse($auth->is_authenticated());
            $this->assertCount(1, RecordingDb::inserts('log'));
        }

        public function test_isAuthenticated_knownIdLogsNothing(): void
        {
            self::sessionOf('12')->is_authenticated();
            $this->assertSame([], RecordingDb::inserts('log'));
        }

        public function test_isAuthenticated_numericUserStays(): void
        {
            $auth = self::sessionOf('12');
            $this->assertSame('12', $auth->is_authenticated());
            $this->assertSame('12', $auth->getUid());
        }

        public function test_isAuthenticated_nobodyStays(): void
        {
            $auth = self::sessionOf('nobody', true);
            $this->assertSame('nobody', $auth->is_authenticated());
            $this->assertSame('nobody', $auth->getUid());
        }

        /** Auth::start() keeps the login form state in the session - it is no user id */
        public function test_isAuthenticated_loginFormStays(): void
        {
            $this->assertSame('form', self::sessionOf('form')->is_authenticated());
        }

        public function test_isAuthenticated_readerStays(): void
        {
            $reader = str_repeat('ab12', 8);
            $this->assertSame($reader, self::sessionOf($reader)->is_authenticated());
        }

        /** a site of its readers only (perm_mode 1/2) must not log out an admin whose session it opens */
        public function test_isAuthenticated_adminStaysOnReaderOnlySite(): void
        {
            AA::$perm->restrictPermsTo([]);
            $this->assertSame('12', self::sessionOf('12')->is_authenticated());
        }

        public function test_isAuthenticated_sessionOfOtherDatabaseLogsOut(): void
        {
            $auth = self::sessionOf('12', false, 'other_db');
            $this->assertFalse($auth->is_authenticated());
            $data = (new \ReflectionProperty($auth, 'auth'))->getValue($auth);
            $this->assertSame('', $data['uid']);
            $this->assertArrayNotHasKey('db', $data, 'a later login compares with no database');
            [$row] = RecordingDb::inserts('log');
            $this->assertSame(['OTHER_DB', 'other_db'], [$row['type'], $row['params']]);
            $this->assertStringStartsWith('12:', $row['selector']);
            $this->assertFalse($auth->is_authenticated());
            $this->assertCount(1, RecordingDb::inserts('log'));
        }

        /** pages that do not check the login (pageOpen('noauth')) read the user straight from the session */
        public function test_getUid_sessionOfOtherDatabaseHasNoUser(): void
        {
            $auth = self::sessionOf('12', false, 'other_db');
            $this->assertSame(['', '', false], [$auth->getUid(), $auth->getUname(), $auth->is_user()]);
            $this->assertSame('12', self::sessionOf('12')->getUid());
            $this->assertSame('nobody', self::sessionOf('nobody', true, null)->getUid());
        }

        /** a session from before the mark logs in again, and that is no event worth a log row */
        public function test_isAuthenticated_sessionWithoutDatabaseLogsInAgain(): void
        {
            $auth = self::sessionOf('12', false, null);
            $this->assertFalse($auth->is_authenticated());
            $this->assertSame('', $auth->getUid());
            $this->assertSame([], RecordingDb::inserts('log'));
        }

        public function test_isAuthenticated_nobodyAndLoginFormNeedNoDatabase(): void
        {
            $this->assertSame('nobody', self::sessionOf('nobody', true, null)->is_authenticated());
            $this->assertSame('form', self::sessionOf('form', false, null)->is_authenticated());
        }

        public function test_recognizesId(): void
        {
            $this->assertTrue(AA::$perm->recognizesId('12'));
            $this->assertTrue(AA::$perm->recognizesId(str_repeat('ab12', 8)));
            $this->assertFalse(AA::$perm->recognizesId('uid=jan,ou=People,ou=AA'));
            $this->assertFalse(AA::$perm->recognizesId(''));
        }
    }
}
