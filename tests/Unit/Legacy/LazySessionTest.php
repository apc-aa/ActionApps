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

        public function test_sessurl_param_withSession_stopsCaching(): void
        {
            self::fakeSession();
            $this->assertSame('AA_Session=0123456789abcdef0123456789abcdef', (new AA_Stringexpand_Sessurl([]))->expand('param'));
            $this->assertTrue(self::nostore());
        }
    }
}
