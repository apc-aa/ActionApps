<?php

declare(strict_types=1);

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
    use AA_Router_Seo;
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;

    /**
     * {go} and the pager links keep the state's query string. The site cache key leaves the tracking
     * parameters out, so a campaign parameter printed in a link varies the cached page; an id of one
     * click is left out of the link, and {go:clean} leaves out all of them (og:url).
     */
    final class RouterGo2urlTest extends TestCase
    {
        /** @var array<string, mixed> */
        private array $saved = [];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../modules/site/router.class.php';
        }

        protected function setUp(): void
        {
            $this->saved = ['apc_state' => $GLOBALS['apc_state'] ?? null, 'request' => $_REQUEST, 'get' => $_GET, 'pagecache' => (new ReflectionProperty(AA::class, 'pagecache'))->getValue()];
            (new ReflectionProperty(AA::class, 'pagecache'))->setValue(null, new PageCache(600));
            self::readSince();
        }

        protected function tearDown(): void
        {
            self::readSince();
            $GLOBALS['apc_state'] = $this->saved['apc_state'];
            $_REQUEST = $this->saved['request'];
            $_GET     = $this->saved['get'];
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

        /** {go:$query_string} on /cz/clanek?$xqs, the visit's own parameters being $get */
        private static function go(string $xqs, string $query_string = '', array $get = []): string
        {
            $GLOBALS['apc_state'] = ['xlang' => 'cz', 'xpage' => '', 'xflag' => '', 'xcat' => '', 'xseo1' => 'clanek', 'xseo' => 'clanek', 'xqs' => $xqs];
            $_REQUEST = $_GET = $get;
            return (new AA_Router_Seo([], '/cz/'))->go2url($query_string);
        }

        public function test_go2url_keeps_the_campaign_and_varies_the_page_on_it(): void
        {
            $this->assertSame('/cz/clanek?page=2&utm_source=seznam', self::go('page=2&utm_source=seznam', '', ['page' => '2', 'utm_source' => 'seznam']));
            $this->assertSame(["\0param:utm_source" => 'seznam'], self::readSince());
        }

        public function test_go2url_leaves_out_a_campaign_value_the_page_cannot_vary_on(): void
        {
            // PHP reads utm_a.b as utm_a_b and keeps the last of repeated names - the url would not match the variant
            $this->assertSame('/cz/clanek?page=2', self::go('utm_a.b=x&page=2', '', ['utm_a_b' => 'x', 'page' => '2']));
            $this->assertSame('/cz/clanek?utm_source=seznam', self::go('utm_source=evil&utm_source=seznam', '', ['utm_source' => 'seznam']));
            $this->assertSame('/cz/clanek', self::go('utm_source[]=x', '', ['utm_source' => ['x']]));
        }

        public function test_go2url_encodes_the_campaign_value_anew(): void
        {
            // a raw quote and its %22 share one variant - the url must not depend on the spelling
            $this->assertSame('/cz/clanek?utm_source=x%22y%27z', self::go('utm_source=x"y\'z', '', ['utm_source' => 'x"y\'z']));
            $this->assertSame('/cz/clanek?utm_source=x%22y%27z', self::go('utm_source=x%22y%27z', '', ['utm_source' => 'x"y\'z']));
        }

        public function test_go2url_keeps_a_campaign_the_template_sets(): void
        {
            $this->assertSame('/cz/clanek?page=2&utm_campaign=nl', self::go('page=2', 'xqs_utm_campaign=nl'));
            $this->assertSame('/cz/clanek?utm_source=newsletter', self::go('utm_source=seznam', 'xqs=utm_source=newsletter', ['utm_source' => 'seznam']));
            $this->assertSame('/cz/clanek?utm_campaign=nl', self::go('utm_source=seznam', 'clean&xqs_utm_campaign=nl', ['utm_source' => 'seznam']));
            $this->assertSame([], self::readSince(), 'the same url for every visit');
        }

        public function test_go2url_leaves_out_an_id_of_one_click(): void
        {
            $this->assertSame('/cz/clanek?page=2', self::go('gclid=abc&page=2&sznclid=x'));
            $this->assertSame([], self::readSince(), 'the url is the same for every click');
            $this->assertFalse((new ReflectionProperty(PageCache::class, 'page_nostore'))->getValue(AA::Pagecache()));
        }

        public function test_go2url_without_tracking_is_one_page_for_everyone(): void
        {
            $this->assertSame('/cz/clanek?page=2', self::go('page=2'));
            $this->assertSame('/cz/clanek', self::go(''));
            $this->assertSame([], self::readSince());
        }

        public function test_clean_leaves_out_every_tracking_parameter(): void
        {
            $this->assertSame('/cz/clanek?page=2', self::go('utm_source=x&page=2&gclid=y', 'clean'));
            $this->assertSame([], self::readSince());
        }

        public function test_clean_goes_with_other_changes(): void
        {
            $this->assertSame('/cz/clanek?page=2&year=2023', self::go('utm_source=x&page=2', 'clean&xqs_year=2023'));
            $this->assertSame('/cz/clanek?page=2&year=2023', self::go('utm_source=x&page=2', 'xqs_year=2023&clean=1'));
        }

        public function test_clean_is_no_parameter_of_the_url(): void
        {
            $this->assertSame('/cz/clanek?page=2', self::go('page=2', 'clean'));
            $this->assertSame('/cz/clanek?page=2&cleaning=1', self::go('page=2', 'xqs_cleaning=1'), 'only the word itself');
        }
    }
}
