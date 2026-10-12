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

    use AA_Router_Seo;
    use PHPUnit\Framework\TestCase;

    /**
     * {go} and {xid:..:<url>} build the next state with AA_Router_Seo::newState(); the state names
     * the router {xid} then asks - AA_Router_Seo, also when a site's own router subclass calls it.
     */
    final class RouterSeoNewStateTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../modules/site/router.class.php';
        }

        private const STATE = ['xlang' => 'cz', 'xseo1' => 'about', 'xseo2' => 'team', 'xseo' => 'team', 'xqs' => 'a=1'];

        public function test_newState_names_the_seo_router(): void
        {
            $this->assertSame('AA_Router_Seo', AA_Router_Seo::newState(self::STATE, 'xseo1=about&xseo2=history')['router']);
        }

        public function test_newState_from_a_subclass_names_the_seo_router_too(): void
        {
            // a site's own router - extends the SEO router as the site control files do
            $own = new class extends AA_Router_Seo {
                public static function newState($apc_state, $query_string): array
                {
                    return parent::newState($apc_state, $query_string);
                }
            };
            $this->assertSame('AA_Router_Seo', $own::newState(self::STATE, 'xseo1=about&xseo2=history')['router']);
        }

        public function test_newState_moves_to_another_page_without_the_old_query(): void
        {
            $state = AA_Router_Seo::newState(self::STATE, 'xseo1=contact&xseo2=&xqs_year=2026');

            $this->assertSame(['contact', '', 'contact'], [$state['xseo1'], $state['xseo2'], $state['xseo']]);
            $this->assertSame('year=2026', $state['xqs']);
        }

        public function test_newState_on_the_same_page_adds_to_the_query(): void
        {
            $state = AA_Router_Seo::newState(self::STATE, 'xqs_year=2026');

            $this->assertSame(['about', 'team', 'team'], [$state['xseo1'], $state['xseo2'], $state['xseo']]);
            $this->assertSame('a=1&year=2026', $state['xqs']);
        }
    }
}
