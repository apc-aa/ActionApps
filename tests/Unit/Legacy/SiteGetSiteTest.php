<?php

declare(strict_types=1);

/**
 * AA_Module_Site::getSite() - the page of a site: the spots its tree chooses, expanded, after the
 * postprocessing of the page.
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

    /** {sitegetsiteprobe} - the site whose page is being rendered, as a spot sees it; the page of
     *  another site first with {sitegetsiteprobe:inner} */
    function stringexpand_sitegetsiteprobe(string $fail = ''): string
    {
        if ($fail === 'inner') {
            $inner = (\Tests\Unit\Legacy\SiteGetSiteTest::$inner)();
            return $inner . ' then ' . AA_Module_Site::renderingPage();
        }
        if ($fail !== '') {
            throw new RuntimeException($fail);
        }
        return (string) AA_Module_Site::renderingPage();
    }
}

namespace Tests\Unit\Legacy {

    use AA;
    use AA_Module_Site;
    use AA_Stringexpander;
    use Closure;
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class SiteGetSiteTest extends TestCase
    {
        private const SITE  = '5173000000000000000000000000000a';
        private const OTHER = '5173000000000000000000000000000b';

        /** what {sitegetsiteprobe:inner} renders before it reports */
        public static ?Closure $inner = null;

        /** one start spot, as RedefineSiteTemplates writes the structure of a new site */
        private const TREE = 'O:8:"sitetree":2:{s:4:"tree";a:1:{i:1;O:4:"spot":8:{s:2:"id";s:1:"1";s:1:"n";s:5:"start";s:1:"c";N;s:1:"v";N;s:1:"p";s:1:"1";s:2:"po";a:1:{i:0;s:1:"1";}s:2:"ch";N;s:1:"f";i:0;}}s:8:"start_id";s:1:"1";}';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/util.php';
            require_once __DIR__ . '/../../../modules/site/sitetree.php';
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            self::$inner = null;
            (new ReflectionProperty(AA::class, 'stringexpander'))->setValue(null, null);
        }

        /** the site with its start spot holding $content; the spot comes from site_spot */
        private static function site(string $content, string $site_id = self::SITE): AA_Module_Site
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb(), new RecordingDb(), new RecordingDb(), new RecordingDb()];
            RecordingDb::$answer = static fn(string $sql): array => str_contains($sql, 'site_spot') ? [['spot_id' => '1', 'content' => $content, 'flag' => '0']] : [];

            return new class ($site_id, self::TREE) extends AA_Module_Site {
                public function __construct(string $site_id, private string $structure)
                {
                    $this->module_id = $site_id;
                }

                public function getProperty($prop)
                {
                    return ($prop === 'structure') ? $this->structure : null;
                }
            };
        }

        public function test_getSite_expandsTheChosenSpotsAndTrims(): void
        {
            $this->assertSame('<p>yes</p>', self::site("\n  <p>{ifeq:1:1:yes:no}</p>\n")->getSite(['item' => null]));
        }

        public function test_renderingPage_isTheSiteWhileItsSpotsExpand(): void
        {
            $site = self::site('{sitegetsiteprobe}');

            $this->assertNull(AA_Module_Site::renderingPage());
            $this->assertSame(self::SITE, $site->getSite(['item' => null]));
            $this->assertNull(AA_Module_Site::renderingPage());
        }

        public function test_renderingPage_isLowercase(): void
        {
            $this->assertSame(self::SITE, self::site('{sitegetsiteprobe}', strtoupper(self::SITE))->getSite(['item' => null]));
        }

        /** the gate's UI may sit in what postprocess() puts into the page */
        public function test_renderingPage_isTheSiteDuringPostprocess(): void
        {
            (new ReflectionProperty(AA::class, 'stringexpander'))->setValue(null, new class extends AA_Stringexpander {
                public function postprocess(string $out): string
                {
                    return $out . '|' . AA_Module_Site::renderingPage();
                }
            });

            $this->assertSame('page|' . self::SITE, self::site('page')->getSite(['item' => null]));
        }

        public function test_renderingPage_innerPageOfAnotherSite_givesTheOuterSiteBack(): void
        {
            $outer = self::site('{sitegetsiteprobe:inner}');
            self::$inner = static fn(): string => self::site('{sitegetsiteprobe}', self::OTHER)->getSite(['item' => null]);

            $this->assertSame(self::OTHER . ' then ' . self::SITE, $outer->getSite(['item' => null]));
            $this->assertNull(AA_Module_Site::renderingPage());
        }

        public function test_renderingPage_isNoneAfterAFailedRender(): void
        {
            try {
                self::site('{sitegetsiteprobe:broken}')->getSite(['item' => null]);
                $this->fail('the spot throws');
            } catch (\RuntimeException $e) {
                $this->assertSame('broken', $e->getMessage());
            }
            $this->assertNull(AA_Module_Site::renderingPage());
        }
    }
}
