<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use AA\Cache\CacheStr2find;
use AA\Cache\PageCache;
use AA\Optimize\CheckCookieReads;
use PHPUnit\Framework\TestCase;

/**
 * Cookies are not part of the site page-cache key: a cached page varies only on the
 * cookies its render read through PageCache::cookie(). Consent and ad frameworks set
 * cookies unique per visitor (euconsent-v2, udid, ...), and with every cookie in the key
 * each such visitor got a private copy of every page.
 *
 * The rows go through an in-memory PageCache (getRow()/store() overridden), so the lookup
 * and store logic runs without a database. The source scan at the end keeps AA code from
 * reading cookies behind the tracker's back - such a read would serve one visitor's
 * variant to everyone.
 */
final class PageCacheVaryTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->saved = ['cookie' => $_COOKIE, 'request' => $_REQUEST, 'get' => $_GET, 'post' => $_POST];
        self::readSince();   // reads of earlier tests must not leak in
    }

    protected function tearDown(): void
    {
        self::readSince();
        $_COOKIE  = $this->saved['cookie'];
        $_REQUEST = $this->saved['request'];
        $_GET     = $this->saved['get'];
        $_POST    = $this->saved['post'];
    }

    /** PageCache keeping its rows in an array */
    private static function cache(): PageCache
    {
        return new class extends PageCache {
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
    }

    /** Cookies read since the last call (name => value); starts a new request */
    private static function readSince(): array
    {
        $read = new \ReflectionProperty(PageCache::class, 'cookies_read');
        $was  = $read->getValue();
        $read->setValue(null, []);
        return $was;
    }

    /** the names row of $key */
    private static function namesRow(PageCache $cache, string $key): array
    {
        return $cache->rows[get_hash('vary', $key)];
    }

    // --- tracking ---

    public function test_cookie_read_is_recorded_whether_sent_or_not(): void
    {
        $_COOKIE = ['ZeSeznamu' => '1'];
        $this->assertSame('1', PageCache::cookie('ZeSeznamu'));
        $this->assertNull(PageCache::cookie('ZGoogle'));
        $this->assertSame(['ZeSeznamu' => '1', 'ZGoogle' => null], self::readSince(), 'an absent cookie is a value too');
    }

    public function test_the_first_read_value_holds_for_the_whole_request(): void
    {
        $_COOKIE = ['AA_Sess' => 'old'];
        PageCache::cookie('AA_Sess');
        $_COOKIE['AA_Sess'] = '';   // AA code rewrites $_COOKIE in places (logout)
        $this->assertSame('old', PageCache::cookie('AA_Sess'), 'the page is stored under the value it was rendered with');
    }

    // --- store and lookup ---

    public function test_render_reading_no_cookie_is_one_entry_for_everyone(): void
    {
        $cache = self::cache();
        $_COOKIE = ['euconsent-v2' => 'visitor-a'];
        $cache->storeVaried('k', 'html', new CacheStr2find());

        $this->assertCount(1, $cache->rows);
        self::readSince();
        $_COOKIE = ['euconsent-v2' => 'visitor-b'];
        $this->assertSame('html', $cache->getVaried('k'));
    }

    public function test_rows_never_use_the_key_itself(): void
    {
        $cache = self::cache();
        PageCache::cookie('A');
        $cache->storeVaried('k', 'html', new CacheStr2find());
        $this->assertArrayNotHasKey('k', $cache->rows, 'an older AA on the same database would take the names row for a page');
    }

    public function test_render_reading_a_cookie_is_shared_by_visitors_with_the_same_value(): void
    {
        $cache = self::cache();
        $_COOKIE = ['ZeSeznamu' => '1', 'euconsent-v2' => 'visitor-a', 'udid' => 'a'];
        PageCache::cookie('ZeSeznamu');
        $cache->storeVaried('k', 'seznam-variant', new CacheStr2find());

        $this->assertSame(1, self::namesRow($cache, 'k')['flag'], 'FLAG_VARY');
        $this->assertSame(['ZeSeznamu'], unserialize(self::namesRow($cache, 'k')['content']));

        self::readSince();
        $_COOKIE = ['ZeSeznamu' => '1', 'euconsent-v2' => 'visitor-b', 'udid' => 'b'];
        $this->assertSame('seznam-variant', $cache->getVaried('k'), 'other cookies do not matter');
        $this->assertSame(['ZeSeznamu' => '1'], self::readSince(), 'the page served depends on the cookie');

        $_COOKIE = ['euconsent-v2' => 'visitor-a', 'udid' => 'a'];
        $this->assertFalse($cache->getVaried('k'), 'a visitor without the cookie needs its own render');
    }

    public function test_names_found_at_lookup_stay_when_a_render_reads_fewer(): void
    {
        $cache = self::cache();
        $_COOKIE = ['A' => '1'];
        PageCache::cookie('A');
        PageCache::cookie('B');
        $cache->storeVaried('k', 'a-and-b', new CacheStr2find());

        self::readSince();
        $_COOKIE = ['A' => '2'];
        $this->assertFalse($cache->getVaried('k'));
        PageCache::cookie('A');   // the branch reading B not taken this time
        $cache->storeVaried('k', 'only-a', new CacheStr2find());

        $this->assertSame(['A', 'B'], unserialize(self::namesRow($cache, 'k')['content']), 'the variant for A=1 stays reachable');
        self::readSince();
        $_COOKIE = ['A' => '1'];
        $this->assertSame('a-and-b', $cache->getVaried('k'));
    }

    public function test_names_row_is_written_with_every_variant(): void
    {
        $cache = self::cache();
        $_COOKIE = ['A' => '1'];
        PageCache::cookie('A');
        $cache->storeVaried('k', 'one', new CacheStr2find());
        $cache->rows[get_hash('vary', 'k')]['stored'] = 42;

        self::readSince();
        $_COOKIE = ['A' => '2'];
        $cache->getVaried('k');
        $cache->storeVaried('k', 'two', new CacheStr2find());
        $this->assertSame(0, self::namesRow($cache, 'k')['stored'], 'an older names row would expire or be swept before the new variant');
        $this->assertCount(3, $cache->rows);
    }

    public function test_a_cookie_read_earlier_in_the_request_counts(): void
    {
        // a memoized expansion (a {view:...} repeated on the page) does not read its cookies again
        $cache = self::cache();
        $_COOKIE = ['A' => '1'];
        PageCache::cookie('A');
        $cache->storeVaried('k', 'html', new CacheStr2find());
        $this->assertSame(['A'], unserialize(self::namesRow($cache, 'k')['content']));
    }

    public function test_reading_all_cookies_varies_on_every_one(): void
    {
        $_COOKIE = ['b' => '2', 'a' => '1', '_ga' => 'GA1'];
        $this->assertSame(['_ga' => 'GA1', 'a' => '1', 'b' => '2'], PageCache::cookies(), 'any order in the header, one variant');
        $this->assertSame([["\0all"]], [array_keys(self::readSince())]);
    }

    public function test_an_include_gets_the_cookies_that_may_change_a_page_and_the_session(): void
    {
        $_COOKIE = ['lang' => 'cs', '_ga' => 'GA1', 'IDE' => 'dc', 'AA_Session' => 's1', 'euconsent-v2' => 'x'];
        $this->assertSame(['AA_Session' => 's1', 'euconsent-v2' => 'x', 'lang' => 'cs'], PageCache::forwardedCookies());
        $this->assertSame([["\0forwarded" => ['euconsent-v2' => 'x', 'lang' => 'cs']]], [self::readSince()], 'the session is keyed by its user ($auth4cache)');
    }

    public function test_jar_names_are_no_cookies(): void
    {
        $_COOKIE = ['AA_Session' => 's1'];
        $this->assertNull(PageCache::cookie("\0all"), '{qs:...} must not print the jar with the HttpOnly session');
        $this->assertSame([], self::readSince());
    }

    public function test_absent_and_empty_cookie_are_different_variants(): void
    {
        $this->assertNotSame(PageCache::variantKey('k', ['A' => null]), PageCache::variantKey('k', ['A' => '']));
    }

    // --- $_REQUEST ---

    public function test_request_without_cookies_merges_like_php(): void
    {
        $_GET  = ['nocache' => '1', 'x' => 'get', 'a' => ['g' => '1']];
        $_POST = ['x' => 'post', 'a' => ['p' => '2']];
        $this->assertSame(['nocache' => '1', 'x' => 'post', 'a' => ['g' => '1', 'p' => '2']], PageCache::requestWithoutCookies('GPC'), 'arrays merge, as in PHP');
        $this->assertSame('get', PageCache::requestWithoutCookies('PG')['x']);
    }

    // --- every cookie read in AA code goes through the tracker ---

    /** Files that get at cookies directly, how many lines, and why it cannot change a cached site page */
    private const COOKIE_ALLOWED = [
        'src/Cache/PageCache.php'           => [6, 'the tracker itself'],
        'src/Optimize/CheckCookieReads.php' => [1, 'the detector'],
        'include/phplib.php'                => [3, 'the session; its user is keyed as $auth4cache'],
        'modules/site/site.php'             => [2, 'AA_Session -> $auth4cache, debug log'],
        'include/locsess.php'               => [1, 'aa_debug -> AA::$debug, which is in the key'],
        'include/util.php'                  => [2, 'debug() log switch; Authorization header'],
        'include/stringexpand.php'          => [1, '{server:HTTP_COOKIE} turns storing off'],
        'include/debug.class.php'           => [2, 'PhpConsole, debugging only'],
        'include/request.class.php'         => [5, 'AA_Auth for external PHP pages'],
        'include/menu.php'                  => [1, 'admin'],
        'modules/links/menu.php'            => [1, 'admin'],
        'src/Util/UiPrefs.php'              => [7, 'admin'],
        'src/Util/InputVars.php'            => [1, '{qs:} skips a cookie PHP put into $_REQUEST'],
        'filldisc.php'                      => [1, 'form handler, never cached'],
        'filler.php'                        => [2, 'form handler, never cached'],
        'post2shtml.php'                    => [1, 'form handler, never cached'],
    ];

    public function test_aa_code_reads_cookies_only_through_the_tracker(): void
    {
        $files = glob(self::ROOT . '/*.php') ?: [];
        foreach (['include', 'src', 'modules'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . "/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() === 'php' && !str_contains($file->getPathname(), '/include/lang/')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        $installation = array_map(static fn(string $f): string => self::ROOT . "/$f", CheckCookieReads::installationFiles(self::ROOT . '/'));
        $found = [];
        foreach (array_diff($files, $installation) as $path) {   // CheckCookieReads watches those
            if ($lines = CheckCookieReads::cookieReads((string) file_get_contents($path))) {
                $found[substr($path, strlen(self::ROOT) + 1)] = count($lines);
            }
        }
        ksort($found);
        $allowed = array_map(static fn(array $a): int => $a[0], self::COOKIE_ALLOWED);
        ksort($allowed);
        $this->assertSame($allowed, $found, 'read cookies with PageCache::cookie() - the site cache key does not hold them');
    }

    public function test_detector_finds_every_way_to_the_cookies(): void
    {
        $code = <<<'PHP'
            <?php
            $a = $_COOKIE['x'];
            $b = $GLOBALS['_COOKIE'];
            $c = $_SERVER['HTTP_COOKIE'];
            $d = getallheaders();
            $e = filter_input(INPUT_COOKIE, 'x');
            $f = \apache_request_headers();
            $g = "header: $_SERVER[HTTP_COOKIE]";
            // $_COOKIE in a comment
            $h = 'cookies in a string';
            PHP;
        $this->assertSame([2, 3, 4, 5, 6, 7, 8], CheckCookieReads::cookieReads($code));
    }
}
