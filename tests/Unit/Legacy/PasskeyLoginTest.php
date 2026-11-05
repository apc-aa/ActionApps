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
    use AA_Permsystem;
    use AA\Optimize\CheckPasskeys;
    use AA\Passkey\Ceremony;
    use AA\Passkey\Challenges;
    use AA\Passkey\Credential;
    use AA\Passkey\Store;
    use AA\Util\LogOnce;
    use AA\Util\Txt;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;
    use Tests\Unit\Passkey\Fixtures\MemoryCredentials;
    use Tests\Unit\Passkey\Fixtures\SoftAuthenticator;

    /** A backend of users 7 "honza" and 8 "eva", password "pw" - both may have passkeys */
    final class PasskeyBackend extends AA_Permsystem
    {
        private const USERS = ['honza' => '7', 'eva' => '8'];
        /** the directory does not answer */
        public bool $down = false;

        public function userIdFormatMatches($uid): bool
        {
            return in_array((string) $uid, self::USERS, true);
        }

        public function authenticateUsername($username, $password, $code2fa = ''): false|string
        {
            return ($password === 'pw') ? (self::USERS[$username] ?? false) : false;
        }

        public function passkeyId(string $uid): ?string
        {
            if ($this->down) {
                throw new \RuntimeException('directory down');
            }
            return $this->userIdFormatMatches($uid) ? $uid : null;
        }

        public function credentialStamp(string $uid): ?string
        {
            return $this->userIdFormatMatches($uid) ? "hash-$uid" : null;
        }

        public function getIDsInfo($uid): false|array
        {
            $login = array_search((string) $uid, self::USERS, true);
            return ($login === false) ? false : ['id' => $uid, 'login' => $login, 'type' => 'User', 'name' => ucfirst($login), 'mails' => ['']];
        }

        public function getGroup($group_id): false|array
        {
            return false;
        }

        public function getGroupMembers($group_id): array
        {
            return [];
        }

        public function getMembership($id, $flags = 0): array
        {
            return [];
        }
    }

    /**
     * Passkeys at the login: the password of an account with a passkey is refused like a wrong one (but not the
     * public password of a free login), and the login form's passkey answer logs in through AA_Auth::auth_preauth().
     */
    final class PasskeyLoginTest extends TestCase
    {
        private mixed $perm = null;
        private PasskeyBackend $backend;
        /** login names the free links logged in by */
        private array $free = [];
        /** the table authenticator exists */
        private bool $table = true;
        /** uid => the passkey rows the table answers with */
        private array $rows = [];

        public static function setUpBeforeClass(): void
        {
            // loading locsess.php warns about the missing web request
            set_error_handler(static fn(): bool => true);
            try {
                require_once __DIR__ . '/fixtures/store_fixtures.php';
                require_once __DIR__ . '/../../../include/locauth.php';
                require_once __DIR__ . '/../Passkey/fixtures/SoftAuthenticator.php';
            } finally {
                restore_error_handler();
            }
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            LogOnce::reset();
            Store::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $this->perm = AA::$perm;
            $this->backend = new PasskeyBackend();
            $this->free    = [];
            AA::$perm      = new AA_Perm([]);
            (new \ReflectionProperty(AA_Perm::class, 'perm_systems'))->setValue(AA::$perm, [$this->backend]);
            $this->table = true;
            $this->rows  = [];
            RecordingDb::$answer = fn(string $sql): array => $this->answer($sql);
            $_POST    = [];
            $_SESSION = [];
            $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
            $_SERVER['HTTP_HOST']   = 'aa.example.org';
            $_SERVER['HTTPS']       = 'on';
            $_SERVER['REQUEST_URI'] = '/admin/index.php';
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            LogOnce::reset();
            Store::reset();
            AA::$perm = $this->perm;
            $_POST    = [];
            $_SESSION = [];
            unset($_SERVER['HTTPS'], $GLOBALS['permission_uid'], $GLOBALS['permission_to'], $GLOBALS['r_superuser']);
        }

        private function answer(string $sql): array
        {
            if (str_contains($sql, 'information_schema.tables')) {
                return $this->table ? [['table_schema' => 'aa_test']] : [];
            }
            if (str_contains($sql, 'slice_url')) {   // a site of this AA is at the host
                return str_contains($sql, 'https://aa.example.org/') ? [['slice_url' => 'https://aa.example.org/']] : [];
            }
            if (str_contains($sql, 'FREE_LOGIN')) {
                foreach ($this->free as $login) {
                    if (str_contains($sql, "'$login'")) {
                        return [['type' => 'FREE_LOGIN']];
                    }
                }
                return [];
            }
            if (!str_contains($sql, 'authenticator')) {
                return [];
            }
            if (str_contains($sql, 'COUNT(*) AS cnt')) {   // Store::hosts()
                $hosts = array_count_values(array_column(array_merge([], ...array_values($this->rows)), 'rp_id'));
                return array_map(static fn(string $host, int $count): array => ['rp_id' => $host, 'cnt' => $count], array_keys($hosts), $hosts);
            }
            foreach ($this->rows as $uid => $rows) {
                foreach ($rows as $row) {
                    if (str_contains($sql, "'" . $row['credential_hash'] . "'") || (str_starts_with($sql, 'SELECT uid') && str_contains($sql, "uid = '$uid'"))) {
                        return [$row];
                    }
                }
            }
            return [];
        }

        /** a passkey of the account in the table, as Store keeps it */
        private function stored(SoftAuthenticator $key, string $uid): Credential
        {
            $bag      = [];
            $ceremony = Ceremony::forRequest($_SERVER, new MemoryCredentials(), new Challenges($bag), 'Test AA');
            $c        = $ceremony->register($uid, $key->create($ceremony->registrationOptions($uid, 'honza', ''), 'https://aa.example.org'), 'Laptop');
            $this->rows[$uid][] = [
                'id' => 3, 'uid' => $c->uid, 'rp_id' => $c->rpId, 'credential_hash' => hash('sha256', $c->credentialId), 'credential_id' => Txt::bin2url($c->credentialId),
                'public_key' => $c->publicKey, 'sign_count' => 0, 'user_handle' => Txt::bin2url($c->userHandle), 'aaguid' => $c->aaguid,
                'backup_eligible' => 1, 'backed_up' => 1, 'name' => $c->name, 'created' => $c->created, 'last_used' => 0,
            ];
            return $c;
        }

        /** [type, selector, params] of the log rows written so far */
        private static function logRows(): array
        {
            return array_map(static fn(array $r): array => [$r['type'], $r['selector'], $r['params']], RecordingDb::inserts('log'));
        }

        public function test_password_accountWithoutPasskey_logsIn(): void
        {
            $_POST = ['username' => 'honza', 'password' => 'pw'];
            $auth  = new AA_Auth();

            $this->assertSame('7', $auth->auth_preauth());
            $this->assertSame('password', AA_Auth::login());
            $this->assertTrue($auth->isFresh());
            // AA_Auth::is_authenticated() takes the session only in this database
            $this->assertSame(DB_NAME, (new \ReflectionProperty($auth, 'auth'))->getValue($auth)['db']);
        }

        /** the same id logged in to another database before - the rights cached for it there must go, for this
         *  request (globals) and the next ones (session) */
        public function test_password_afterSessionOfOtherDatabase_dropsCachedRights(): void
        {
            $this->assertSame([[], null, null], $this->loginOver(['uid' => '7', 'uname' => 'honza', 'db' => 'other_db']));
        }

        /** a login of the same user in the same database again (the free login repeats on every request) - the cache stays */
        public function test_password_sameUserAgain_keepsCachedRights(): void
        {
            $cached = ['permission_uid' => '7', 'r_superuser' => ['x' => true]];
            $this->assertSame([$cached, '7', ['x' => true]], $this->loginOver(['uid' => '7', 'uname' => 'honza', 'db' => DB_NAME]));
        }

        /** [session, global permission_uid, global r_superuser] after a login by password over the session data $before */
        private function loginOver(array $before): array
        {
            $_SESSION = ['permission_uid' => '7', 'r_superuser' => ['x' => true]];
            $GLOBALS['permission_uid'] = '7';
            $GLOBALS['r_superuser']    = ['x' => true];
            $_POST    = ['username' => 'honza', 'password' => 'pw'];
            $auth     = new AA_Auth();
            (new \ReflectionProperty($auth, 'auth'))->setValue($auth, $before);
            $this->assertSame('7', $auth->auth_preauth());
            return [$_SESSION, $GLOBALS['permission_uid'], $GLOBALS['r_superuser']];
        }

        public function test_password_accountWithPasskey_refusedLikeAWrongOne(): void
        {
            $this->stored(new SoftAuthenticator(), '7');
            $_POST = ['username' => 'honza', 'password' => 'pw'];

            $this->assertFalse((new AA_Auth())->auth_preauth());
            [$known, $failed] = self::logRows();
            $this->assertSame([CheckPasskeys::EVENT, 'password:honza'], [$known[0], $known[1]], 'State Check: someone knows the password');
            $this->assertSame(['LOGIN', 'honza'], [$failed[0], $failed[2]]);
            $this->assertStringStartsWith(':', $failed[1], 'a failed login - counted by LoginThrottle');
            $this->assertSame('8', AA::$perm->authenticateUsername('eva', 'pw'), 'another account is not held back');
        }

        public function test_password_noTableYet_logsIn(): void
        {
            $this->table = false;
            $this->assertSame('7', AA::$perm->authenticateUsername('honza', 'pw'));
            $this->assertSame([], array_filter(RecordingDb::$log, static fn(string $sql): bool => str_contains($sql, 'FROM "authenticator"')));
        }

        /** ?free= takes any account - it must not be a way around the passkey */
        public function test_freeLogin_accountWithPasskey_refused(): void
        {
            $this->stored(new SoftAuthenticator(), '7');

            $this->assertFalse((new AA_Auth())->freeLogin('honza', 'pw'));
        }

        public function test_freeLogin_neverFresh(): void
        {
            $auth = new AA_Auth();

            $this->assertSame('7', $auth->freeLogin('honza', 'pw'));
            $this->assertFalse($auth->isFresh(), 'a free login never changes passkeys');
        }

        public function test_passkey_answerOfTheLoginForm_logsIn(): void
        {
            $key = new SoftAuthenticator();
            $this->stored($key, '7');
            $options = Ceremony::here()->loginOptions();
            $_POST   = ['passkey_login' => $key->get($options, 'https://aa.example.org'), 'username' => '', 'password' => ''];
            $auth    = new AA_Auth();

            $this->assertSame('7', $auth->auth_preauth());
            $this->assertSame(['passkey', '7', 'honza'], [AA_Auth::login(), $auth->getUid(), $auth->getUname()]);
            $this->assertTrue($auth->isFresh());
            [[$type, $selector, $params]] = self::logRows();
            $this->assertSame(['LOGIN', 'honza'], [$type, $params]);
            $this->assertStringStartsWith('7:', $selector);
            $this->assertNotEmpty(array_filter(RecordingDb::$log, static fn(string $sql): bool => str_starts_with($sql, 'UPDATE') && str_contains($sql, 'last_used')), 'the use is recorded');
        }

        public function test_passkey_sameAnswerAgain_refused(): void
        {
            $key = new SoftAuthenticator();
            $this->stored($key, '7');
            $_POST = ['passkey_login' => $key->get(Ceremony::here()->loginOptions(), 'https://aa.example.org')];
            $this->assertSame('7', (new AA_Auth())->auth_preauth());

            $this->assertFalse((new AA_Auth())->auth_preauth());
            $this->assertSame('bad-passkey', AA_Auth::login());
        }

        public function test_passkey_deleted_refusedAndLogged(): void
        {
            $key = new SoftAuthenticator();
            $this->stored($key, '7');
            $options    = Ceremony::here()->loginOptions();
            $this->rows = [];
            $_POST      = ['passkey_login' => $key->get($options, 'https://aa.example.org')];

            $_POST['username'] = 'honza';   // filled in by the browser
            $_POST['password'] = 'pw';
            $auth = new AA_Auth();

            $this->assertFalse($auth->auth_preauth());
            $this->assertSame([[CheckPasskeys::EVENT, 'unknown:', '']], self::logRows(), 'the password is not tried');
            $this->assertSame('', $auth->getUid(), 'not left in the "form" state, which would try it next');
        }

        public function test_passkey_accountGone_refused(): void
        {
            $key = new SoftAuthenticator();
            $this->stored($key, '9');   // no backend knows 9 any more
            $_POST = ['passkey_login' => $key->get(Ceremony::here()->loginOptions(), 'https://aa.example.org')];

            $this->assertFalse((new AA_Auth())->auth_preauth());
            $this->assertSame([[CheckPasskeys::EVENT, 'unknown:account', '']], self::logRows());
        }

        public function test_passkey_plainHttp_noLogin(): void
        {
            $key = new SoftAuthenticator();
            $this->stored($key, '7');
            $options = Ceremony::here()->loginOptions();
            unset($_SERVER['HTTPS']);
            $_POST = ['passkey_login' => $key->get($options, 'https://aa.example.org')];

            $this->assertFalse((new AA_Auth())->auth_preauth());
        }

        public function test_passkey_hostThisAaDoesNotServe_noCeremony(): void
        {
            $_SERVER['HTTP_HOST'] = 'evil.example';

            $this->assertNull(Ceremony::here(), 'a Host header of the visitor would put a passkey on any domain');
        }

        public function test_password_directoryDown_refusedWhilePasskeysExist(): void
        {
            $this->stored(new SoftAuthenticator(), '8');
            $this->backend->down = true;

            $this->assertFalse(AA::$perm->authenticateUsername('honza', 'pw'), 'its passkey may be under the DN the directory would give');
            $this->assertSame([CheckPasskeys::EVENT, 'directory:honza'], array_slice(self::logRows()[0], 0, 2));
        }

        public function test_password_directoryDownNoPasskeys_logsIn(): void
        {
            $this->backend->down = true;

            $this->assertSame('7', AA::$perm->authenticateUsername('honza', 'pw'));
        }

        /** a token was issued for the password, which no longer logs the account in */
        public function test_credentialStamp_accountWithPasskey_none(): void
        {
            $this->stored(new SoftAuthenticator(), '7');

            $this->assertNull(AA::$perm->credentialStamp('7'));
            $this->assertSame('hash-8', AA::$perm->credentialStamp('8'));
        }

        public function test_publicPassword_accountOfTheFreeLinks(): void
        {
            $this->free = ['eva'];

            $this->assertTrue(AA_Auth::publicPassword('8'));
            $this->assertFalse(AA_Auth::publicPassword('7'));
        }

        public function test_isFresh_tenMinutesAfterTheLogin_no(): void
        {
            $_POST = ['username' => 'honza', 'password' => 'pw'];
            $auth  = new AA_Auth();
            $auth->auth_preauth();
            $data = new \ReflectionProperty($auth, 'auth');
            $data->setValue($auth, ['at' => time() - AA_Auth::FRESH_SECONDS + 5] + $data->getValue($auth));
            $this->assertTrue($auth->isFresh());

            $data->setValue($auth, ['at' => time() - AA_Auth::FRESH_SECONDS - 1] + $data->getValue($auth));
            $this->assertFalse($auth->isFresh());
        }
    }
}
