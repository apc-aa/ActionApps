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
    use AA_Perm;
    use AA_Permsystem;
    use AA_Permsystem_Reader;
    use AA_Permsystem_Sql;
    use AA\Central\AppStore;
    use AA\Util\LoginToken;
    use AA\Util\LogOnce;
    use AA\Util\Secret;
    use AA_Auth;
    use AA_Stringexpand_Jwtdecode;
    use Firebase\JWT\JWT;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunInSeparateProcess;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;
    use UnexpectedValueException;

    /**
     * The Bearer login of Central (central/responder.php): a JWT under Secret::key('jwt') that dies
     * with a password change, accepted only at the token entry, never kept in a session.
     * Users come through AA::$perm - the real SQL backend over RecordingDb, a Reader and an LDAP
     * backend faked (they need items or an LDAP server).
     */
    final class LoginTokenTest extends TestCase
    {
        /** users.password of user 7 (login "central") */
        private string $hash = '$2y$10$abcdefghijklmnopqrstuuFIRSThash';
        private mixed $perm  = null;

        private const READER = '0123456789abcdef0123456789abcdef';
        private const LDAP   = 'uid=central,ou=People,dc=example,dc=org';

        /** @var array<string, array{login: string, stamp: ?string}> the users of the fake Reader and LDAP backends */
        private array $others = [];

        /** a users row answers every `users` query - as MySQL compares '7a7a...' = 7 */
        private bool $loose_users = false;

        public static function setUpBeforeClass(): void
        {
            // loading locsess.php warns about the missing web request; a separate process would report it as the test's
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
            RecordingDb::$answer = fn(string $sql): array => (str_contains($sql, '`users`') && ($this->loose_users || str_contains($sql, 'id = 7'))) ? [['id' => '7', 'uid' => 'central', 'login' => 'central', 'name' => '', 'givenname' => 'Central', 'sn' => 'Admin', 'mail' => '', 'type' => 'User', 'password' => $this->hash]] : [];
            $this->perm   = AA::$perm;
            $this->others = [
                self::READER => ['login' => 'reader1', 'stamp' => '$2y$10$readerhashreaderhashreaderhash'],
                self::LDAP   => ['login' => 'central', 'stamp' => '{SSHA}ldaphash'],
            ];
            $this->loose_users = false;
            AA::$perm = $this->perm(['sql', 'reader', 'ldap']);
            $_POST = [];
            $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
            $_SERVER['HTTP_HOST']   = 'aa.example.org';
            $_SERVER['REQUEST_URI'] = '/central/responder.php';
            unset($_SERVER['HTTP_AUTHORIZATION']);
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            LogOnce::reset();
            AA::$perm = $this->perm;
            $_POST = [];
            unset($_SERVER['HTTP_AUTHORIZATION']);
        }

        /** AA_Perm over the real SQL backend and the fake Reader and LDAP ones, asked in $order */
        private function perm(array $order): AA_Perm
        {
            $others  = &$this->others;
            $systems = [
                'sql'    => new AA_Permsystem_Sql(),
                'reader' => new class ($others) extends AA_Permsystem_Reader {
                    public function __construct(private array &$users) {}

                    public function getIDsInfo($uid): false|array
                    {
                        return (is_long_id($uid) && isset($this->users[$uid])) ? ['id' => $uid, 'login' => $this->users[$uid]['login'], 'type' => 'Reader'] : false;
                    }

                    public function credentialStamp(string $uid): ?string
                    {
                        return is_long_id($uid) ? ($this->users[$uid]['stamp'] ?? null) : null;
                    }
                },
                'ldap'   => new class ($others) extends AA_Permsystem {
                    public function __construct(private array &$users) {}

                    public function userIdFormatMatches($uid): bool
                    {
                        return str_starts_with($uid, 'uid=') || str_starts_with($uid, 'cn=');
                    }

                    public function getIDsInfo($uid): false|array
                    {
                        return isset($this->users[$uid]) ? ['id' => $uid, 'login' => $this->users[$uid]['login'], 'type' => 'User'] : false;
                    }

                    public function credentialStamp(string $uid): ?string
                    {
                        return $this->users[$uid]['stamp'] ?? null;
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
                },
            ];
            $perm = new AA_Perm([]);
            (new \ReflectionProperty(AA_Perm::class, 'perm_systems'))->setValue($perm, array_map(static fn(string $name): AA_Permsystem => $systems[$name], $order));
            return $perm;
        }

        /** a token signed right and tagged with the password of user 7 - only its sub decides */
        private function tokenFor(string $sub): string
        {
            return JWT::encode(['sub' => $sub, 'iat' => time(), 'exp' => time() + 3600, 'pwd' => substr(hash_hmac('sha256', $this->hash, Secret::key('jwt-pwd')), 0, 16)], Secret::key('jwt'), 'HS256');
        }

        /** firebase/php-jwt 7 throws a TypeError on a non-string alg */
        private const MALFORMED = 'eyJhbGciOlsieCJdfQ.eyJzdWIiOiI3In0.c2ln';

        /** @return array<string, mixed> */
        private static function claims(string $jwt): array
        {
            return json_decode(JWT::urlsafeB64Decode(explode('.', $jwt)[1]), true);
        }

        public function test_a_token_logs_in_its_user(): void
        {
            $this->assertSame(['7', 'central', LoginToken::SCOPE_ADMIN], LoginToken::verify(LoginToken::issue('7')));
        }

        public function test_the_token_carries_the_user_a_day_and_a_tag_of_the_password(): void
        {
            $claims = self::claims(LoginToken::issue('7'));
            $this->assertSame(['sub', 'iat', 'exp', 'pwd'], array_keys($claims));
            $this->assertSame('7', $claims['sub']);
            $this->assertSame(24 * 3600, $claims['exp'] - $claims['iat'], 'no ttl - the longest a token lives');
            $this->assertSame(substr(hash_hmac('sha256', $this->hash, Secret::key('jwt-pwd')), 0, 16), $claims['pwd'], 'keyed - a leaked token does not help to crack the hash');
        }

        /** @return array<string, array{0: int, 1: int}> asked lifetime, the one the token gets */
        public static function lifetimes(): array
        {
            return [
                'an hour'           => [3600, 3600],
                'a day'             => [24 * 3600, 24 * 3600],
                'a week is capped'  => [7 * 24 * 3600, 24 * 3600],
                'a year is capped'  => [365 * 24 * 3600, 24 * 3600],
                'zero is a minute'  => [0, 60],
                'negative'          => [-5, 60],
            ];
        }

        #[DataProvider('lifetimes')]
        public function test_a_token_lives_at_most_a_day(int $asked, int $lifetime): void
        {
            $claims = self::claims(LoginToken::issue('7', null, $asked));
            $this->assertSame($lifetime, $claims['exp'] - $claims['iat']);
        }

        public function test_a_shorter_token_logs_in_like_a_full_one(): void
        {
            $this->assertSame(['7', 'central', LoginToken::SCOPE_ADMIN], LoginToken::verify(LoginToken::issue('7', null, 3600)));
        }

        public function test_an_expired_token_of_a_day_is_refused(): void
        {
            $this->expectException(UnexpectedValueException::class);
            LoginToken::verify(LoginToken::issue('7', time() - 25 * 3600));
        }

        /** a token of a longer life, as AAs issued them before - whatever its exp says */
        private function signedToken(int $iat, int $exp): string
        {
            return JWT::encode(['sub' => '7', 'iat' => $iat, 'exp' => $exp, 'pwd' => substr(hash_hmac('sha256', $this->hash, Secret::key('jwt-pwd')), 0, 16)], Secret::key('jwt'), 'HS256');
        }

        public function test_a_token_issued_more_than_a_day_ago_is_refused_whatever_its_exp(): void
        {
            $this->expectException(UnexpectedValueException::class);
            LoginToken::verify($this->signedToken(time() - 25 * 3600, time() + 300 * 24 * 3600));
        }

        public function test_a_token_of_a_year_issued_just_now_logs_in_until_a_day_passes(): void
        {
            $this->assertSame(['7', 'central', LoginToken::SCOPE_ADMIN], LoginToken::verify($this->signedToken(time() - 3600, time() + 365 * 24 * 3600)));
        }

        public function test_a_day_old_token_passes_within_the_clock_skew(): void
        {
            $this->assertSame(['7', 'central', LoginToken::SCOPE_ADMIN], LoginToken::verify($this->signedToken(time() - 24 * 3600 - 60, time() + 3600)));
        }

        public function test_a_token_issued_in_the_future_is_refused(): void
        {
            $this->expectException(UnexpectedValueException::class);
            LoginToken::verify($this->signedToken(time() + 600, time() + 3600));
        }

        public function test_a_token_without_iat_is_refused(): void
        {
            $this->expectException(UnexpectedValueException::class);
            LoginToken::verify(JWT::encode(['sub' => '7', 'exp' => time() + 3600, 'pwd' => substr(hash_hmac('sha256', $this->hash, Secret::key('jwt-pwd')), 0, 16)], Secret::key('jwt'), 'HS256'));
        }

        public function test_an_apps_token_carries_its_scope(): void
        {
            $jwt = LoginToken::issue('7', null, 3600, LoginToken::SCOPE_APPS);

            $this->assertSame('apps', self::claims($jwt)['scp']);
            $this->assertSame(['7', 'central', LoginToken::SCOPE_APPS], LoginToken::verify($jwt));
        }

        public function test_an_apps_token_never_lives_longer_than_a_day(): void
        {
            foreach ([null, 365 * 24 * 3600] as $ttl) {
                $claims = self::claims(LoginToken::issue('7', null, $ttl, LoginToken::SCOPE_APPS));
                $this->assertSame(24 * 3600, $claims['exp'] - $claims['iat'], 'ttl ' . var_export($ttl, true));
            }
        }

        public function test_an_admin_token_has_no_scope_claim(): void
        {
            $this->assertArrayNotHasKey('scp', self::claims(LoginToken::issue('7', null, 3600, LoginToken::SCOPE_ADMIN)));
        }

        public function test_an_unknown_scope_gives_no_token(): void
        {
            $this->assertSame('', LoginToken::issue('7', null, 3600, 'root'));
        }

        public function test_a_token_of_an_unknown_scope_is_refused(): void
        {
            $pwd = substr(hash_hmac('sha256', $this->hash, Secret::key('jwt-pwd')), 0, 16);
            $this->expectException(UnexpectedValueException::class);
            LoginToken::verify(JWT::encode(['sub' => '7', 'iat' => time(), 'exp' => time() + 3600, 'pwd' => $pwd, 'scp' => 'root'], Secret::key('jwt'), 'HS256'));
        }

        /** @return array<string, array{0: string, 1: string}> user id, login name */
        public static function usersOfEveryBackend(): array
        {
            return [
                'sql'    => ['7', 'central'],
                'reader' => [self::READER, 'reader1'],
                'ldap'   => [self::LDAP, 'central'],
            ];
        }

        #[DataProvider('usersOfEveryBackend')]
        public function test_a_token_logs_in_a_user_of_every_backend(string $uid, string $login): void
        {
            $this->assertSame([$uid, $login, LoginToken::SCOPE_ADMIN], LoginToken::verify(LoginToken::issue($uid)));
        }

        #[DataProvider('usersOfEveryBackend')]
        public function test_a_password_change_revokes_the_token_in_every_backend(string $uid): void
        {
            $jwt = LoginToken::issue($uid);
            if ($uid === '7') {
                $this->hash = '$2y$10$abcdefghijklmnopqrstuuNEWhash';
            } else {
                $this->others[$uid]['stamp'] .= 'NEW';
            }
            $this->expectException(UnexpectedValueException::class);
            LoginToken::verify($jwt);
        }

        /** the SQL tag is the keyed HMAC of users.password - existing tokens depend on it */
        public function test_the_sql_tag_is_the_keyed_hmac_of_users_password(): void
        {
            $this->assertFalse(defined('AA_SECRET'), 'the vector is for the key made of the test DB constants');
            $this->assertSame('1b95bf7817acbe86', self::claims(LoginToken::issue('7'))['pwd']);
        }

        public function test_no_token_for_a_user_without_a_credential_stamp(): void
        {
            $this->others[self::LDAP]['stamp'] = null;
            $this->assertSame('', LoginToken::issue(self::LDAP));
        }

        /** @return array<string, array{0: string, 1: bool}> user id, whether every `users` query finds user 7 */
        public static function unknownUsers(): array
        {
            return [
                'unknown sql id'        => ['8', false],
                'unknown reader id'     => ['fedcba9876543210fedcba9876543210', true],
                'unknown ldap dn'       => ['uid=nobody,ou=People,dc=example,dc=org', true],
                'no backend has it'     => ['central', true],
                'nobody'                => ['nobody', true],
                'empty'                 => ['', true],
                'digits then letters'   => ['7a', true],
                'reader-like, 7 first'  => [str_repeat('7a', 16), true],
                'a 32 digit reader id'  => [str_repeat('7', 32), true],
            ];
        }

        #[DataProvider('unknownUsers')]
        public function test_no_token_for_an_id_no_backend_knows(string $uid, bool $loose_users): void
        {
            $this->loose_users = $loose_users;
            $this->assertSame('', LoginToken::issue($uid));
        }

        #[DataProvider('unknownUsers')]
        public function test_a_token_of_an_id_no_backend_knows_is_refused(string $uid, bool $loose_users): void
        {
            $this->loose_users = $loose_users;
            $this->expectException(UnexpectedValueException::class);
            LoginToken::verify($this->tokenFor($uid));
        }

        public function test_a_reader_id_never_reaches_the_users_table(): void
        {
            $this->loose_users = true;
            foreach ([str_repeat('7a', 16), str_repeat('7', 32)] as $uid) {
                $this->assertNull(AA::$perm->credentialStamp($uid));
                $this->assertNull((new AA_Permsystem_Sql())->credentialStamp($uid));
            }
            $this->assertSame([], preg_grep('/`users`/', RecordingDb::$log));
        }

        public function test_an_sql_id_never_resolves_to_a_reader(): void
        {
            $this->others['7'] = ['login' => 'reader-seven', 'stamp' => $this->hash];
            AA::$perm = $this->perm(['reader', 'ldap', 'sql']);
            $this->assertSame(['7', 'central', LoginToken::SCOPE_ADMIN], LoginToken::verify(LoginToken::issue('7')));
            $this->assertNull((new AA_Permsystem_Reader())->credentialStamp('7'), 'not an item id - no lookup');
        }

        public function test_a_token_signed_with_aa_id_is_refused(): void
        {
            $old = JWT::encode(['sub' => '7', 'name' => 'central', 'iat' => time(), 'exp' => time() + 3600], AA_ID, 'HS256');
            $this->expectException(UnexpectedValueException::class);
            LoginToken::verify($old);
        }

        public function test_an_expired_token_is_refused(): void
        {
            $this->expectException(UnexpectedValueException::class);
            LoginToken::verify(LoginToken::issue('7', time() - 366 * 24 * 3600, 365 * 24 * 3600));
        }

        public function test_a_token_without_pwd_is_refused(): void
        {
            $this->expectException(UnexpectedValueException::class);
            LoginToken::verify(JWT::encode(['sub' => '7', 'iat' => time(), 'exp' => time() + 3600], Secret::key('jwt'), 'HS256'));
        }

        public function test_a_token_without_exp_is_refused(): void
        {
            $pwd = substr(hash_hmac('sha256', $this->hash, Secret::key('jwt-pwd')), 0, 16);
            $this->expectException(UnexpectedValueException::class);
            LoginToken::verify(JWT::encode(['sub' => '7', 'iat' => time(), 'pwd' => $pwd], Secret::key('jwt'), 'HS256'));
        }

        public function test_a_password_change_revokes_the_token(): void
        {
            $jwt        = LoginToken::issue('7');
            $this->hash = '$2y$10$abcdefghijklmnopqrstuuNEWhash';
            $this->expectException(UnexpectedValueException::class);
            LoginToken::verify($jwt);
        }

        public function test_bearer_is_ignored_outside_the_token_entry(): void
        {
            $this->assertFalse(defined('AA_TOKEN_ENTRY'));
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . LoginToken::issue('7');
            $this->assertFalse((new AA_Auth())->auth_preauth());
            $this->assertSame('', AA_Auth::login());
        }

        #[RunInSeparateProcess]
        #[PreserveGlobalState(false)]
        public function test_bearer_logs_in_at_the_token_entry(): void
        {
            define('AA_TOKEN_ENTRY', true);
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . LoginToken::issue('7');
            $auth = new AA_Auth();
            $this->assertSame('7', $auth->auth_preauth());
            $this->assertSame('central', $auth->getUname());
            $this->assertSame('token', AA_Auth::login(), 'not "password" - a token login never mints another token');
            $this->assertSame(LoginToken::SCOPE_ADMIN, AA_Auth::scope());
        }

        #[RunInSeparateProcess]
        #[PreserveGlobalState(false)]
        public function test_bearer_of_an_apps_token_logs_in_with_its_scope(): void
        {
            define('AA_TOKEN_ENTRY', true);
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . LoginToken::issue('7', null, 3600, LoginToken::SCOPE_APPS);
            $this->assertSame('7', (new AA_Auth())->auth_preauth());
            $this->assertSame(LoginToken::SCOPE_APPS, AA_Auth::scope());
        }

        /** @return array<string, array{0: string}> */
        public static function refusedTokens(): array
        {
            return [
                'signed with AA_ID' => [JWT::encode(['sub' => '7', 'iat' => time(), 'exp' => time() + 3600], AA_ID, 'HS256')],
                'malformed header'  => [self::MALFORMED],
            ];
        }

        #[DataProvider('refusedTokens')]
        #[RunInSeparateProcess]
        #[PreserveGlobalState(false)]
        public function test_a_refused_bearer_is_logged_without_the_token(string $jwt): void
        {
            define('AA_TOKEN_ENTRY', true);
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;
            $this->assertFalse((new AA_Auth())->auth_preauth());
            $this->assertSame('bad-token', AA_Auth::login(), 'the responder answers with its own error');
            $this->assertSame(['JWT_DENY'], array_column(RecordingDb::inserts('log'), 'type'));
            foreach (explode('.', $jwt) as $part) {
                $this->assertStringNotContainsString($part, implode("\n", RecordingDb::$log));
            }
        }

        public function test_jwtdecode_survives_a_malformed_header(): void
        {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::MALFORMED;
            $this->assertSame('', (new AA_Stringexpand_Jwtdecode([]))->expand(str_repeat('k', 32)));
        }

        public function test_password_login_is_flagged_for_this_request_only(): void
        {
            AA::$perm = self::oneUser('central');
            $auth  = new AA_Auth();
            $_POST = ['username' => 'central', 'password' => 'pw', 'code2fa' => ''];
            $this->assertSame('7', $auth->auth_preauth());
            $this->assertSame('password', AA_Auth::login());

            $_POST = ['username' => 'central', 'password' => 'wrong', 'code2fa' => ''];
            $this->assertFalse($auth->auth_preauth());
            $this->assertSame('', AA_Auth::login(), 'a failed login does not keep the state of the previous one');
        }

        /** AA_Perm of one user, 7, of the login $login - a reader when $reader */
        private static function oneUser(string $login, bool $reader = false): object
        {
            return new class ($login, $reader) {
                public function __construct(private string $login, private bool $reader) {}

                public function authenticateUsername($username, $password, $code2fa = ''): false|string
                {
                    return ($username === $this->login && $password === 'pw') ? '7' : false;
                }

                public function isUserEditable($uid): bool
                {
                    return ($uid === '7') && !$this->reader;
                }

                public function getUserLoginName($uid): string
                {
                    return ($uid === '7') ? $this->login : '';
                }
            };
        }

        public function test_the_store_account_is_the_user_of_its_login(): void
        {
            AA::$perm = self::oneUser(AppStore::PUBLIC_USER);
            $this->assertTrue(AppStore::isPublic('7'));
            $this->assertFalse(AppStore::isPublic('8'));
            $this->assertFalse(AppStore::isPublic(''));

            AA::$perm = self::oneUser(AppStore::PUBLIC_USER, true);
            $this->assertFalse(AppStore::isPublic('7'), 'a reader of the same login is no store account');

            AA::$perm = self::oneUser('central');
            $this->assertFalse(AppStore::isPublic('7'));
        }

        /** its password is public: it must not open the administration, nor a session anywhere */
        public function test_the_store_account_logs_in_nowhere_but_the_token_entry(): void
        {
            $this->assertFalse(defined('AA_TOKEN_ENTRY'));
            AA::$perm = self::oneUser(AppStore::PUBLIC_USER);
            $_POST    = ['username' => AppStore::PUBLIC_USER, 'password' => 'pw', 'code2fa' => ''];

            $this->assertFalse((new AA_Auth())->auth_preauth());
            $this->assertSame('', AA_Auth::login());
            $this->assertFalse((new AA_Auth())->auth_validatelogin(), 'the login form');

            AA::$perm = self::oneUser('central');
            $_POST    = ['username' => 'central', 'password' => 'pw', 'code2fa' => ''];
            $this->assertSame('7', (new AA_Auth())->auth_preauth(), 'any other user logs in');
        }

        #[RunInSeparateProcess]
        #[PreserveGlobalState(false)]
        public function test_the_store_account_logs_in_at_the_token_entry(): void
        {
            define('AA_TOKEN_ENTRY', true);
            AA::$perm = self::oneUser(AppStore::PUBLIC_USER);
            $_POST    = ['username' => AppStore::PUBLIC_USER, 'password' => 'pw', 'code2fa' => ''];

            $this->assertSame('7', (new AA_Auth())->auth_preauth(), 'the responder lets it only ask for a token of the store (ResponderPolicy::publicAllows())');
            $this->assertSame('password', AA_Auth::login());
        }

        /** a real request to the built-in server: the session a token login opened sends no cookie and stores nothing */
        public function test_forget_session_sends_no_cookie_and_saves_nothing(): void
        {
            $dir = sys_get_temp_dir() . '/aa-logintoken-' . bin2hex(random_bytes(4));
            mkdir($dir);
            file_put_contents("$dir/page.php", '<?php
                require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';
                session_save_path(__DIR__);
                session_start();
                setcookie(session_name(), session_id());
                AA\Util\LoginToken::forgetSession();
                $_SESSION["auth"] = "uid 7";
                session_write_close();
                echo "done";');
            $port   = stream_socket_server('tcp://127.0.0.1:0');
            $addr   = stream_socket_get_name($port, false);
            fclose($port);
            $server = proc_open([PHP_BINARY, '-S', $addr, '-t', $dir], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            try {
                for ($i = 0; $i < 50 && !($up = @fsockopen('tcp://' . $addr)); $i++) {
                    usleep(100_000);
                }
                $this->assertNotFalse($up, 'php -S did not start');
                fclose($up);
                $body    = file_get_contents("http://$addr/page.php");
                $headers = implode("\n", $http_response_header);
            } finally {
                proc_terminate($server);
                proc_close($server);
                $saved = array_filter(glob("$dir/sess_*") ?: [], static fn(string $f): bool => filesize($f) > 0);
                array_map('unlink', glob("$dir/*") ?: []);
                rmdir($dir);
            }
            $this->assertSame('done', $body);
            $this->assertStringNotContainsStringIgnoringCase('Set-Cookie', $headers);
            $this->assertSame([], $saved);
        }
    }
}
