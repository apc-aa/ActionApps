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

    /**
     * A session keeps the user id it logged in with. After PERM_LIB changes (ldap -> sql) a session holding
     * an id no permission system knows must ask to log in again - not stay logged in without any right.
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
            $this->perm = AA::$perm;
            AA::$perm   = new AA_Perm(['sql', 'Reader']);
        }

        protected function tearDown(): void
        {
            AA::$perm = $this->perm;
        }

        /** AA_Auth with the session data of a user logged in as $uid, not expired */
        private static function sessionOf(string $uid, bool $nobody = false): AA_Auth
        {
            $auth = new AA_Auth();
            $auth->set_nobody($nobody);
            (fn() => $this->auth = ['uid' => $uid, 'uname' => 'jan', 'perm' => '', 'exp' => time() + 600, 'refresh' => 0])->call($auth);
            return $auth;
        }

        public function test_isAuthenticated_dnSessionUnderSqlLogsOut(): void
        {
            $auth = self::sessionOf('uid=jan,ou=People,ou=AA');
            $this->assertFalse($auth->is_authenticated());
            $this->assertSame('', $auth->getUid());
            $this->assertSame('', $auth->getUname());
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

        public function test_recognizesId(): void
        {
            $this->assertTrue(AA::$perm->recognizesId('12'));
            $this->assertTrue(AA::$perm->recognizesId(str_repeat('ab12', 8)));
            $this->assertFalse(AA::$perm->recognizesId('uid=jan,ou=People,ou=AA'));
            $this->assertFalse(AA::$perm->recognizesId(''));
        }
    }
}
