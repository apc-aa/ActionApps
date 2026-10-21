<?php

declare(strict_types=1);

/**
 * {server:...}, {qs:...} and {qss:...} read the request through AA\Cache\PageCache, so a cached site
 * page is stored once per value they read - the key leaves out the tracking parameters (utm_*, ...)
 * and holds the host without www. A value of the visitor's own (REMOTE_ADDR, ...) keeps the page
 * out of the cache.
 *
 * include/stringexpand.php cannot be loaded on its own, so the classes are extracted from the real
 * file and evaluated without their parent, as StringexpandProtectmailTest does.
 */

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
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;

    final class StringexpandServerQsTest extends TestCase
    {
        /** @var array<string, mixed> */
        private array $saved = [];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/util.php';
            if (class_exists('AA_Test_Stringexpand_Server', false)) {
                return;
            }
            $source = (string) file_get_contents(__DIR__ . '/../../../include/stringexpand.php');
            $rename = [
                'PageCache::' => '\AA\Cache\PageCache::',
                'class AA_Stringexpand_Server extends AA_Stringexpand_Nevercache' => 'class AA_Test_Stringexpand_Server',
                'class AA_Stringexpand_Qs extends AA_Stringexpand_Nevercachenotrim' => 'class AA_Test_Stringexpand_Qs',
                'class AA_Stringexpand_Qss extends AA_Stringexpand_Qs' => 'class AA_Test_Stringexpand_Qss extends AA_Test_Stringexpand_Qs',
            ];
            foreach (['AA_Stringexpand_Server', 'AA_Stringexpand_Qs', 'AA_Stringexpand_Qss'] as $name) {
                eval(strtr(self::extract($source, $name), $rename));
            }
        }

        protected function setUp(): void
        {
            $this->saved = ['request' => $_REQUEST, 'get' => $_GET, 'cookie' => $_COOKIE, 'server' => $_SERVER, 'pagecache' => (new ReflectionProperty(AA::class, 'pagecache'))->getValue()];
            (new ReflectionProperty(AA::class, 'pagecache'))->setValue(null, new PageCache(600));
            self::readSince();
            $_COOKIE = [];
        }

        protected function tearDown(): void
        {
            self::readSince();
            $_REQUEST = $this->saved['request'];
            $_GET     = $this->saved['get'];
            $_COOKIE  = $this->saved['cookie'];
            $_SERVER  = $this->saved['server'];
            (new ReflectionProperty(AA::class, 'pagecache'))->setValue(null, $this->saved['pagecache']);
        }

        /** Values read since the last call; starts a new request */
        private static function readSince(): array
        {
            $read = new ReflectionProperty(PageCache::class, 'vary_reads');
            $was  = $read->getValue();
            $read->setValue(null, []);
            return $was;
        }

        private static function nostore(): bool
        {
            return (new ReflectionProperty(PageCache::class, 'page_nostore'))->getValue(AA::Pagecache());
        }

        public function test_server_varies_the_page_on_the_value(): void
        {
            $_SERVER['HTTP_HOST'] = 'www.example.org';
            self::assertSame('www.example.org', (new \AA_Test_Stringexpand_Server())->expand('HTTP_HOST'));
            self::assertSame(["\0server:HTTP_HOST" => 'www.example.org'], self::readSince());
            self::assertFalse(self::nostore());
        }

        /** @return array<string, array{string}> */
        public static function perVisitor(): array
        {
            return [
                'address'    => ['REMOTE_ADDR'],
                'browser'    => ['HTTP_USER_AGENT'],
                'referer'    => ['HTTP_REFERER'],
                'time'       => ['REQUEST_TIME'],
                'misspelt'   => ['REFERER'],
            ];
        }

        #[\PHPUnit\Framework\Attributes\DataProvider('perVisitor')]
        public function test_server_value_of_the_visitor_keeps_the_page_out_of_the_cache(string $name): void
        {
            $_SERVER[$name] = 'visitor-a';
            self::assertSame('visitor-a', (new \AA_Test_Stringexpand_Server())->expand($name));
            self::assertTrue(self::nostore());
            self::assertSame([], self::readSince(), 'no variant per visitor');
        }

        public function test_server_value_in_the_key_is_read_as_it_is(): void
        {
            $_SERVER['DOCUMENT_ROOT'] = '/data/www';
            self::assertSame('/data/www', (new \AA_Test_Stringexpand_Server())->expand('DOCUMENT_ROOT'));
            self::assertSame([], self::readSince());
            self::assertFalse(self::nostore());
        }

        public function test_uri_with_a_click_id_keeps_the_page_out_of_the_cache(): void
        {
            $_SERVER['REQUEST_URI'] = '/cz/page?gclid=abc&x=1';
            (new \AA_Test_Stringexpand_Server())->expand('REQUEST_URI');
            self::assertTrue(self::nostore(), 'a variant per click');
        }

        public function test_qs_of_a_click_id_keeps_the_page_out_of_the_cache(): void
        {
            $_REQUEST = $_GET = ['fbclid' => 'abc'];
            self::assertSame('abc', (new \AA_Test_Stringexpand_Qs())->expand('fbclid'));
            self::assertTrue(self::nostore());
            self::assertSame([], self::readSince());
        }

        /** @return array<string, array{string}> */
        public static function secrets(): array
        {
            return ['cookie jar' => ['HTTP_COOKIE'], 'authorization' => ['HTTP_AUTHORIZATION'], 'password' => ['PHP_AUTH_PW']];
        }

        #[\PHPUnit\Framework\Attributes\DataProvider('secrets')]
        public function test_server_secret_keeps_every_result_of_the_render_out(string $name): void
        {
            $_SERVER[$name] = 'AA_Session=0123';
            (new \AA_Test_Stringexpand_Server())->expand($name);
            self::assertTrue((new ReflectionProperty(PageCache::class, 'cache_nostore'))->getValue(AA::Pagecache()));
            self::assertSame([], self::readSince());
        }

        public function test_output_of_a_page_left_out_is_not_stored(): void
        {
            // slice.php stores its listing by storeOutput()
            (new \AA_Test_Stringexpand_Server())->expand('REMOTE_ADDR');
            self::assertFalse(AA::Pagecache()->storeOutput('k', 'listing', new \AA\Cache\CacheStr2find()));
        }

        public function test_lookup_of_a_uri_with_a_click_id_stores_no_variant(): void
        {
            // a render may skip the {qs} which read the URI first - the names row has it anyway
            $cache = new class extends PageCache {
                /** @var array<string, array{content: string, stored: int, flag: int}> */
                public array $rows = [];

                protected function getRow($key, string $action = 'get'): ?array
                {
                    return $this->rows[$key] ?? null;
                }

                public function store($key, $content, $str2find, $force = false, int $flag = 0): bool
                {
                    $this->rows[$key] = ['content' => $content, 'stored' => 0, 'flag' => $flag];
                    return true;
                }
            };
            (new ReflectionProperty(AA::class, 'pagecache'))->setValue(null, $cache);
            $_SERVER['REQUEST_URI'] = '/cz/page';
            PageCache::server('REQUEST_URI');
            $cache->storeVaried('k', 'html', new \AA\Cache\CacheStr2find());

            self::readSince();
            $_SERVER['REQUEST_URI'] = '/cz/page?fbclid=abc';
            self::assertFalse($cache->getVaried('k'));
            self::assertFalse($cache->storeVaried('k', 'html', new \AA\Cache\CacheStr2find()));
        }

        public function test_page_left_out_still_caches_smaller_results(): void
        {
            (new \AA_Test_Stringexpand_Server())->expand('REMOTE_ADDR');
            self::assertFalse((new ReflectionProperty(PageCache::class, 'cache_nostore'))->getValue(AA::Pagecache()), 'field definitions, dictionaries');
        }

        public function test_qs_of_a_tracking_param_varies_the_page(): void
        {
            $_REQUEST = $_GET = ['utm_source' => 'www.seznam.cz', 'vid' => '1'];
            self::assertSame('www.seznam.cz', (new \AA_Test_Stringexpand_Qs())->expand('utm_source'));
            self::assertSame('1', (new \AA_Test_Stringexpand_Qs())->expand('vid'));
            self::assertSame(["\0param:utm_source" => 'www.seznam.cz'], self::readSince(), 'vid is in the key');
        }

        public function test_qss_of_a_tracking_param_varies_the_page_and_escapes(): void
        {
            $_REQUEST = $_GET = ['utm_source' => '<b>'];
            self::assertSame('&lt;b&gt;', (new \AA_Test_Stringexpand_Qss())->expand('utm_source'));
            self::assertSame(["\0param:utm_source" => '<b>'], self::readSince());
        }

        public function test_whole_query_string_varies_the_page_on_the_uri(): void
        {
            // the key leaves the tracking parameters out of the URI, the query string keeps them
            $_SERVER['REQUEST_URI'] = '/cz/page?x=1&utm_source=seznam';
            self::assertSame('x=1&amp;utm_source=seznam', (new \AA_Test_Stringexpand_Qss())->expand());
            self::assertSame(["\0server:REQUEST_URI" => '/cz/page?x=1&utm_source=seznam'], self::readSince());
        }

        public function test_qs_of_a_bracketed_name_varies_the_page_on_the_uri(): void
        {
            // the name is looked up in the decoded query string, where a tracking parameter's value may hold it
            $_SERVER['REQUEST_URI'] = '/cz/page?utm_source=x%26aa%5Bf%5D%3Devil';
            self::assertSame('evil', (new \AA_Test_Stringexpand_Qs())->expand('aa[f]'));
            self::assertSame(["\0server:REQUEST_URI" => '/cz/page?utm_source=x%26aa%5Bf%5D%3Devil'], self::readSince());
        }

        private static function extract(string $source, string $name): string
        {
            $tokens = token_get_all($source);
            $start  = null;
            $depth  = 0;
            $out    = '';
            foreach ($tokens as $i => $token) {
                if (is_null($start)) {
                    if (is_array($token) && $token[0] === T_CLASS && ($tokens[$i + 2][1] ?? '') === $name) {
                        $start = $i;
                    } else {
                        continue;
                    }
                }
                $text = is_array($token) ? $token[1] : $token;
                $out .= $text;
                if ($text === '{') {
                    $depth++;
                } elseif ($text === '}' && --$depth === 0) {
                    return $out;
                }
            }
            self::fail("class $name not found");
        }
    }
}
