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

    use AA_Perm;
    use AA\Security\LoginThrottle;
    use AA\Util\LogOnce;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakePermsystem;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * AA_Perm::authenticateUsername() - the one gate every password check passes: it logs the failures and
     * refuses to check a password while the address has to wait (AA\Security\LoginThrottle). The log answers
     * with the LOGIN rows the gate wrote, so what is counted is what AA_Log::context() really stores.
     */
    final class PermAuthenticateTest extends TestCase
    {
        /** the passwords the backend was asked to check */
        private array $checked = [];

        public static function setUpBeforeClass(): void
        {
            // loading locsess.php warns about the missing web request
            set_error_handler(static fn(): bool => true);
            try {
                require_once __DIR__ . '/fixtures/store_fixtures.php';
            } finally {
                restore_error_handler();
            }
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            LogOnce::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $this->checked = [];
            RecordingDb::$answer = static fn(string $sql): array => str_starts_with($sql, 'SELECT `time`, `type`, `selector`, `params` FROM `log`')
                ? array_values(array_filter(RecordingDb::inserts('log'), static fn(array $row): bool => in_array($row['type'], ['LOGIN', LoginThrottle::TEMPLATE], true)))
                : [];
            unset($_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_SEC_FETCH_DEST']);
            $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
            $_SERVER['HTTP_HOST']   = 'aa.example.org';
            $_SERVER['REQUEST_URI'] = '/admin/index.php';
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            LogOnce::reset();
        }

        /** AA_Perm over one backend: user 7 "admin" with password "pw", checked by a real hash compare */
        private function perm(): AA_Perm
        {
            $hash    = password_hash('pw', PASSWORD_BCRYPT, ['cost' => 4]);
            $checked = &$this->checked;
            return FakePermsystem::perm(new FakePermsystem(static function ($username, $password) use ($hash, &$checked): false|string {
                $checked[] = $password;
                return (($username === 'admin') && AA_Perm::comparePwds($password, $hash)) ? '7' : false;
            }));
        }

        /** [type, selector, params] of the log rows written so far */
        private static function logRows(): array
        {
            return array_map(static fn(array $r): array => [$r['type'], $r['selector'], $r['params']], RecordingDb::inserts('log'));
        }

        /** $count wrong passwords of $name through the gate */
        private function failedBefore(int $count, string $name): void
        {
            for ($i = 0; $i < $count; ++$i) {
                $this->assertFalse($this->perm()->authenticateUsername($name, "guess$i"));
            }
            $this->checked = [];
        }

        public function test_a_failure_is_logged_for_the_counters(): void
        {
            $this->assertFalse($this->perm()->authenticateUsername('admin', 'guess'));
            [[$type, $selector, $params]] = self::logRows();
            $this->assertSame(['LOGIN', 'admin'], [$type, $params]);
            $this->assertStringStartsWith(':', $selector, 'no user');
            $this->assertStringContainsString(':192.0.2.1:', $selector);
            $this->assertNull(LoginThrottle::refuse());
        }

        public function test_a_login_without_a_name_is_no_guess(): void
        {
            $this->assertFalse($this->perm()->authenticateUsername('', 'guess'));
            $this->assertFalse($this->perm()->authenticateUsername(null, 'guess'));
            $this->assertSame([], self::logRows());
        }

        /** it holds the server for the padded second all the same - uncounted, it could do so without end */
        public function test_an_empty_password_counts(): void
        {
            $this->assertFalse($this->perm()->authenticateUsername('admin', ''));
            $this->assertCount(1, self::logRows());
        }

        public function test_under_the_limit_the_password_is_checked(): void
        {
            $this->failedBefore(4, 'admin');
            $this->assertSame('7', $this->perm()->authenticateUsername('admin', 'pw'));
            $this->assertCount(4, self::logRows(), 'a success is logged by the login itself (AA_Auth), not by every check');
        }

        public function test_over_the_limit_no_password_is_checked_and_nothing_is_counted(): void
        {
            $this->failedBefore(5, 'admin');
            $this->assertFalse($this->perm()->authenticateUsername('admin', 'pw'), 'not even the right one');
            $this->assertSame([], $this->checked);
            $this->assertStringContainsString('Try again in 1 min', (string) LoginThrottle::refuse());
            $this->assertSame('1', (new \AA_Stringexpand_Loginwait([]))->expand(), 'what a site page of the reader login shows');
            $rows = self::logRows();
            $this->assertCount(6, $rows, 'five failures and the refusal');
            $this->assertSame([LoginThrottle::EVENT, ParamImplode(['refused', '192.0.2.1', '']), ''], end($rows));
        }

        public function test_other_names_from_the_address_are_checked(): void
        {
            $this->failedBefore(5, 'admin');
            $this->assertFalse($this->perm()->authenticateUsername('editor', 'pw'));
            $this->assertSame(['pw'], $this->checked);
        }

        public function test_credentials_a_template_passed_count_for_the_name_only(): void
        {
            for ($i = 0; $i < 21; ++$i) {   // one over the free failures of an address
                $this->assertFalse($this->perm()->authenticateUsername("example$i", 'guess', '', true));
            }
            $this->assertSame([LoginThrottle::TEMPLATE], array_values(array_unique(array_column(self::logRows(), 0))));
            $this->assertSame('7', $this->perm()->authenticateUsername('admin', 'pw'), 'wrong example credentials in a template do not slow its visitors down');

            for ($i = 0; $i < 5; ++$i) {
                $this->assertFalse($this->perm()->authenticateUsername('admin', "guess$i", '', true));
            }
            $this->checked = [];
            $this->assertFalse($this->perm()->authenticateUsername('admin', 'pw', '', true), 'one name guessed through a template waits');
            $this->assertSame([], $this->checked);
        }

        /** an image or frame of another page the visitor opens must not spend their tries */
        public function test_a_login_embedded_by_another_site_is_not_checked(): void
        {
            $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
            $_SERVER['HTTP_SEC_FETCH_DEST'] = 'image';
            $this->assertFalse($this->perm()->authenticateUsername('admin', 'pw'));
            $this->assertSame([], $this->checked);
            $this->assertSame([], self::logRows());

            $_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';   // a link from another site
            $this->assertSame('7', $this->perm()->authenticateUsername('admin', 'pw'));
        }

        public function test_a_script_without_an_address_is_not_counted(): void
        {
            unset($_SERVER['REMOTE_ADDR']);
            $this->assertSame('7', $this->perm()->authenticateUsername('admin', 'pw'));
            $this->assertSame(0, RecordingDb::count('SELECT `time`'));
        }
    }
}
