<?php

declare(strict_types=1);

// store_fixtures loads the real locsess.php, which needs the constants include/config.php normally provides.

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
    use AA\Cache\PageCache;
    use AA_Item;
    use AA_Session;
    use AA_Stringexpand_Sessurl;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;

    /**
     * An anonymous visitor of the site module has no session - it opens only to log in or when the page
     * stores something in it (AA_Session::ensureStarted()). Without it the page renders the same for every
     * anonymous visitor and stays cacheable; a session id ({sessurl}, alias function f_e:session) must never
     * get into a page cached for others. (Opening a real session is checked on aadev, not here.)
     */
    final class LazySessionTest extends TestCase
    {
        /** @var array{bool, mixed, mixed} whether $GLOBALS['sess'] existed, its value, AA::$pagecache */
        private array $before = [false, null, null];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/item.php';
        }

        protected function setUp(): void
        {
            $pagecache    = new ReflectionProperty(AA::class, 'pagecache');
            $this->before = [array_key_exists('sess', $GLOBALS), $GLOBALS['sess'] ?? null, $pagecache->getValue()];
            unset($GLOBALS['sess']);
            $pagecache->setValue(null, new PageCache(600));
        }

        protected function tearDown(): void
        {
            [$had_sess, $sess, $pagecache] = $this->before;
            unset($GLOBALS['sess']);
            if ($had_sess) {
                $GLOBALS['sess'] = $sess;
            }
            (new ReflectionProperty(AA::class, 'pagecache'))->setValue(null, $pagecache);
        }

        private static function fakeSession(): void
        {
            $GLOBALS['sess'] = new class {
                public function id(): string
                {
                    return '0123456789abcdef0123456789abcdef';
                }

                public function name(): string
                {
                    return 'AA_Session';
                }
            };
        }

        private static function nostore(): bool
        {
            return (new ReflectionProperty(PageCache::class, 'cache_nostore'))->getValue(AA::Pagecache());
        }

        private static function sessionAlias(): string
        {
            return (new AA_Item(['headline........' => [['value' => 'Hello', 'flag' => 0]]], []))->f_e('headline........', 'session');
        }

        public function test_f_e_session_withoutSession_isEmptyAndCacheable(): void
        {
            $this->assertSame('', self::sessionAlias());
            $this->assertFalse(self::nostore());
        }

        public function test_f_e_session_withSession_printsIdAndStopsCaching(): void
        {
            self::fakeSession();
            $this->assertSame('0123456789abcdef0123456789abcdef', self::sessionAlias());
            $this->assertTrue(self::nostore());
        }

        public function test_sessurl_url_withoutSession_isTheUrl(): void
        {
            $this->assertSame('/en/contact', (new AA_Stringexpand_Sessurl([]))->expand('/en/contact'));
            $this->assertFalse(self::nostore());
        }

        public function test_sessurl_id_withoutSession_isEmptyAndCacheable(): void
        {
            $this->assertSame('', (new AA_Stringexpand_Sessurl([]))->expand(''));
            $this->assertFalse(self::nostore());
        }

        public static function visitors(): array
        {
            $sid = ['AA_Session' => 'abc'];
            return [
                'no cookie'                         => [[], [], [], true, false],
                'session cookie'                    => [$sid, [], [], true, true],
                'another cookie'                    => [['lang' => 'cz'], [], [], true, false],
                'empty cookie'                      => [['AA_Session' => ''], [], [], true, false],
                'cookie sent as an array'           => [['AA_Session' => ['abc']], [], [], true, false],
                'login name in the url'             => [$sid, ['username' => 'eva'], [], true, false],
                'login name posted'                 => [$sid, [], ['username' => 'eva'], true, false],
                'login name only a cookie'          => [$sid + ['username' => 'eva'], [], [], true, true],
                'no login here: a name is a filter' => [$sid, ['username' => 'eva'], [], false, true],
                'no login here: posted name'        => [$sid, [], ['username' => 'eva'], false, true],
                'no login here: no cookie'          => [[], ['username' => 'eva'], [], false, false],
            ];
        }

        /** public pages open the session only for the browser that has one; the site module takes a login by name later, itself */
        #[DataProvider('visitors')]
        public function test_visitorHasOne_request(array $cookie, array $get, array $post, bool $login, bool $expected): void
        {
            $this->assertSame($expected, AA_Session::visitorHasOne($cookie, $get, $post, $login));
        }

        public static function sessions(): array
        {
            $now   = 1_800_000_000;
            $empty = ['auth' => new \stdClass(), 'perm_cache_v' => 2];
            return [
                'nothing at all'                  => [[], 'nobody', true],
                'a visit without login'           => [$empty, 'nobody', true],
                'no login object'                 => [['perm_cache_v' => 2], '', true],
                'rights cached for nobody'        => [$empty + ['permission_uid' => 'nobody', 'permission_to' => ['slice' => [], 'aa' => []], 'r_superuser' => []], 'nobody', true],
                'only tokens too old to be used'  => [$empty + ['r_tokens' => ['a' => $now - 172800, 'b' => $now - 200000]], 'nobody', true],
                'token list emptied'              => [$empty + ['r_tokens' => []], 'nobody', true],
                'broken token list'               => [$empty + ['r_tokens' => 'x'], 'nobody', true],
                'logged in'                       => [$empty, '7', false],
                'login form shown'                => [$empty, 'form', false],
                'a form waits for its token'      => [$empty + ['r_tokens' => ['a' => $now - 60]], 'nobody', false],
                'one of the tokens still valid'   => [$empty + ['r_tokens' => ['a' => $now - 200000, 'b' => $now - 172799]], 'nobody', false],
                'connector of a widget'           => [$empty + ['AA_4ajax' => ['w_mng' => ['x' => 'y']]], 'nobody', false],
                'passkey challenge'               => [$empty + ['aa_passkey' => []], 'nobody', false],
                'state of slice.php'              => [$empty + ['slices' => null], 'nobody', false],
                'a key this list does not know'   => [$empty + ['anything' => 0], 'nobody', false],
            ];
        }

        /** a session that keeps nothing is ended - only the keys every plain visit leaves count as nothing */
        #[DataProvider('sessions')]
        public function test_keepsNothing_session(array $session, string $uid, bool $expected): void
        {
            $this->assertSame($expected, AA_Session::keepsNothing($session, $uid, 1_800_000_000));
        }

        /** an ended session takes its own cookie out of the answer (renewal, new id) and no other one */
        public function test_cookiesExcept_keepsOtherCookies(): void
        {
            $headers = [
                'Content-Type: text/html',
                'Set-Cookie: AA_Session=abc; path=/; HttpOnly',
                'Set-Cookie: lang=cz; path=/',
                'set-cookie:AA_Session=def',
                'Set-Cookie: AA_Session2=x',
                'X-Note: Set-Cookie: AA_Session=no',
            ];
            $this->assertSame(['Set-Cookie: lang=cz; path=/', 'Set-Cookie: AA_Session2=x'], AA_Session::cookiesExcept($headers, 'AA_Session'));
            $this->assertSame([], AA_Session::cookiesExcept(['Content-Type: text/html'], 'AA_Session'));
        }

        public function test_sessurl_param_withSession_stopsCaching(): void
        {
            self::fakeSession();
            $this->assertSame('AA_Session=0123456789abcdef0123456789abcdef', (new AA_Stringexpand_Sessurl([]))->expand('param'));
            $this->assertTrue(self::nostore());
        }
    }
}
