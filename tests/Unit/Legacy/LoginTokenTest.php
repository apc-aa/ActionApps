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
     */
    final class LoginTokenTest extends TestCase
    {
        /** users.password of user 7 (login "central") */
        private string $hash = '$2y$10$abcdefghijklmnopqrstuuFIRSThash';
        private mixed $perm  = null;

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
            RecordingDb::$answer = fn(string $sql): array => (str_contains($sql, 'users') && str_contains($sql, 'id = 7') && str_contains($sql, "type = 'User'")) ? [['uid' => 'central', 'password' => $this->hash]] : [];
            $this->perm = AA::$perm;
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

        /** firebase/php-jwt 7 throws a TypeError on a non-string alg */
        private const MALFORMED = 'eyJhbGciOlsieCJdfQ.eyJzdWIiOiI3In0.c2ln';

        /** @return array<string, mixed> */
        private static function claims(string $jwt): array
        {
            return json_decode(JWT::urlsafeB64Decode(explode('.', $jwt)[1]), true);
        }

        public function test_a_token_logs_in_its_user(): void
        {
            $this->assertSame(['7', 'central'], LoginToken::verify(LoginToken::issue('7')));
        }

        public function test_the_token_carries_the_user_a_year_and_a_tag_of_the_password(): void
        {
            $claims = self::claims(LoginToken::issue('7'));
            $this->assertSame(['sub', 'iat', 'exp', 'pwd'], array_keys($claims));
            $this->assertSame('7', $claims['sub']);
            $this->assertSame(365 * 24 * 3600, $claims['exp'] - $claims['iat']);
            $this->assertSame(substr(hash_hmac('sha256', $this->hash, Secret::key('jwt-pwd')), 0, 16), $claims['pwd'], 'keyed - a leaked token does not help to crack the hash');
        }

        public function test_no_token_for_a_user_outside_the_users_table(): void
        {
            $this->assertSame('', LoginToken::issue('8'));
            $this->assertSame('', LoginToken::issue(str_repeat('7a', 16)), 'a reader id is not looked up as a number');
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
            LoginToken::verify(LoginToken::issue('7', time() - 366 * 24 * 3600));
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
            AA::$perm = new class {
                public function authenticateUsername($username, $password, $code2fa = ''): false|string
                {
                    return ($username === 'central' && $password === 'pw') ? '7' : false;
                }
            };
            $auth  = new AA_Auth();
            $_POST = ['username' => 'central', 'password' => 'pw', 'code2fa' => ''];
            $this->assertSame('7', $auth->auth_preauth());
            $this->assertSame('password', AA_Auth::login());

            $_POST = ['username' => 'central', 'password' => 'wrong', 'code2fa' => ''];
            $this->assertFalse($auth->auth_preauth());
            $this->assertSame('', AA_Auth::login(), 'a failed login does not keep the state of the previous one');
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
