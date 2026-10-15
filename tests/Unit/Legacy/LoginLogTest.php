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
    use AA\Util\LogOnce;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakePermsystem;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * The LOGIN rows of the log: one per attempt somebody typed, with the name cut to the column, and none
     * for the public accounts the free links log in on every visit (a FREE_LOGIN row a day instead).
     */
    final class LoginLogTest extends TestCase
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
            LogOnce::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $this->perm = AA::$perm;
            AA::$perm   = self::permOf(['central' => 'pw', 'anonym' => 'anonym']);
            $_POST      = [];
            $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
            $_SERVER['HTTP_HOST']   = 'aa.example.org';
            $_SERVER['REQUEST_URI'] = '/admin/index.php';
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            LogOnce::reset();
            AA::$perm = $this->perm;
            $_POST    = [];
        }

        /** AA_Perm over one backend of these login => password users (uid 7, 8, ...) */
        private static function permOf(array $users): AA_Perm
        {
            return FakePermsystem::perm(new FakePermsystem(static function ($username, $password) use ($users): false|string {
                $uid = array_search($username, array_keys($users), true);
                return (($uid !== false) && ($users[$username] === $password)) ? (string) ($uid + 7) : false;
            }));
        }

        /** [type, selector, params] of the log rows written so far */
        private static function logRows(): array
        {
            return array_map(static fn(array $r): array => [$r['type'], $r['selector'], $r['params']], RecordingDb::inserts('log'));
        }

        public function test_a_login_logs_the_user_and_the_name(): void
        {
            $_POST = ['username' => 'central', 'password' => 'pw', 'code2fa' => ''];
            $this->assertSame('7', (new AA_Auth())->auth_preauth());
            [[$type, $selector, $params]] = self::logRows();
            $this->assertSame(['LOGIN', 'central'], [$type, $params]);
            $this->assertStringStartsWith('7:', $selector);
            $this->assertStringContainsString(':192.0.2.1:', $selector);
        }

        public function test_a_failed_login_logs_the_name_without_a_user(): void
        {
            $_POST = ['username' => 'central', 'password' => 'wrong', 'code2fa' => ''];
            $this->assertFalse((new AA_Auth())->auth_preauth());
            [[$type, $selector, $params]] = self::logRows();
            $this->assertSame(['LOGIN', 'central'], [$type, $params]);
            $this->assertStringStartsWith(':', $selector);
        }

        public function test_the_login_form_state_without_a_name_logs_nothing(): void
        {
            $this->assertFalse((new AA_Auth())->auth_validatelogin());   // what Auth::start() asks on every page of a session left in the form
            $this->assertSame([], self::logRows());
        }

        public function test_an_over_long_name_is_cut_to_the_column(): void
        {
            $_POST = ['username' => str_repeat('ž', 100), 'password' => 'x', 'code2fa' => ''];
            (new AA_Auth())->auth_preauth();
            [[, , $params]] = self::logRows();
            $this->assertSame(str_repeat('ž', 64), $params, '128 bytes, no character split');
        }

        public function test_the_free_login_logs_once_a_day_instead_of_every_visit(): void
        {
            $this->assertSame('8', (new AA_Auth())->freeLogin('anonym', 'anonym'));
            $this->assertSame('8', (new AA_Auth())->freeLogin('anonym', 'anonym'));
            $this->assertSame([['FREE_LOGIN', 'anonym', '']], self::logRows());
        }

        public function test_a_failed_free_login_is_a_failed_login(): void
        {
            $_SERVER['REQUEST_URI'] = '/admin/index.php?free=central&freepwd=guess';
            $this->assertFalse((new AA_Auth())->freeLogin('central', 'guess'));
            [[$type, $selector, $params]] = self::logRows();
            $this->assertSame(['LOGIN', 'central'], [$type, $params]);
            $this->assertStringStartsWith(':', $selector);
            $this->assertStringContainsString('aa.example.org/admin/index.php', $selector);
            $this->assertStringNotContainsString('guess', $selector, 'the password from the URL stays out of the log');
        }
    }
}
