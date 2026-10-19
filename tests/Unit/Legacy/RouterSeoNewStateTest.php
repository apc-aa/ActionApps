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
    use PHPUnit\Framework\Attributes\DataProvider;
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

        public function test_parseApc_reads_page_flag_and_category(): void
        {
            $state = AA_Router_Seo::parseApc('/cz2t-bio_plyn.2/articles/biogas?year=2026', '/cz/home', ['cz']);

            $this->assertSame(['cz', '2', 't', 'bio_plyn.2'], [$state['xlang'], $state['xpage'], $state['xflag'], $state['xcat']]);
            $this->assertSame(['articles', 'biogas', 'year=2026'], [$state['xseo1'], $state['xseo2'], $state['xqs']]);
        }

        public function test_parseApc_keeps_an_encoded_category(): void
        {
            $this->assertSame('%C4%8Dlanky,2', AA_Router_Seo::parseApc('/cz-%C4%8Dlanky,2/articles', '/cz/home')['xcat']);
        }

        /** @return array<string, array{string}> */
        public static function mangledPaths(): array
        {
            return [
                'query in the category' => ['/czt-35&wa=WWW-kapalna-biopaliva/articles/biogas'],
                'grown discussion link' => ['/cz-34&add_disc=1&parent_id=b2ca1a47208667bfd8562e1f52b9411f/articles/biogas'],
                'entity in the category' => ['/czp-2%C4%82nbsp;&all_ids=1/articles/biogas'],
                'query as the flag'     => ['/cz&wa=WWW-bioodpady-a-kompostovani/o-biomu/autori'],
            ];
        }

        #[DataProvider('mangledPaths')]
        public function test_allowedState_refuses_a_query_in_the_first_segment(string $url): void
        {
            $this->assertFalse(AA_Router_Seo::allowedState(AA_Router_Seo::parseApc($url, '/cz/home', ['cz']), []));
        }

        public function test_allowedState_keeps_other_separators_in_the_category(): void
        {
            $this->assertTrue(AA_Router_Seo::allowedState(AA_Router_Seo::parseApc('/cz-bio&plyn;pelety,kotle/articles', '/cz/home'), []));
        }

        public function test_allowedState_applies_the_site_patterns_to_the_whole_value(): void
        {
            $rules = ['xcat' => '[a-z0-9-]*', 'xflag' => 't|p'];

            $this->assertTrue(AA_Router_Seo::allowedState(['xcat' => 'bio-plyn', 'xflag' => 't'], $rules));
            $this->assertTrue(AA_Router_Seo::allowedState(['xcat' => '', 'xflag' => ''], ['xcat' => '[a-z]*']));
            $this->assertFalse(AA_Router_Seo::allowedState(['xcat' => 'bio-plyn', 'xflag' => 'tp'], $rules));
            $this->assertFalse(AA_Router_Seo::allowedState(['xcat' => 'Bio', 'xflag' => ''], $rules));
            $this->assertTrue(AA_Router_Seo::allowedState(['xcat' => 'a~b', 'xflag' => ''], ['xcat' => 'a~b']), 'a delimiter-like character');
            $this->assertTrue(AA_Router_Seo::allowedState(['xcat' => 'a~b', 'xflag' => ''], ['xcat' => 'a\\~b']), 'an escaped one');
            $this->assertFalse(AA_Router_Seo::allowedState(['xcat' => 'ab', 'xflag' => ''], ['xcat' => 'a|b']), 'alternatives stay anchored');
        }

        public function test_allowedState_ignores_a_pattern_that_does_not_compile(): void
        {
            $log = ini_set('error_log', '/dev/null');   // it logs the pattern error
            try {
                $this->assertTrue(AA_Router_Seo::allowedState(['xcat' => 'x', 'xflag' => ''], ['xcat' => '[a-']));
            } finally {
                ini_set('error_log', (string) $log);
            }
        }

        public function test_allowedState_refuses_when_the_pattern_gives_up(): void
        {
            $this->assertFalse(AA_Router_Seo::allowedState(['xcat' => str_repeat('x', 5000), 'xflag' => ''], ['xcat' => '(x+x+)+y']));
        }

        public function test_parse_keeps_the_state_of_a_refused_url_without_the_refused_parts(): void
        {
            $_REQUEST['username'] = '';   // parse() logs in from the request
            $router = new AA_Router_Seo([], '/cz/home');
            $state  = $router->parse('/czt2-35&wa=x/articles/biogas?a=1');

            $this->assertTrue($router->rejected());
            $this->assertSame(['cz', '', '', '', '', 'articles', 'biogas'], [$state['xlang'], $state['xpage'], $state['xflag'], $state['xcat'], $state['xqs'], $state['xseo1'], $state['xseo2']]);
            $this->assertSame('AA_Router_Seo', $state['router']);
            $this->assertSame('/cz/articles/biogas', $state['state']);

            $router->parse('/czt-35/articles/biogas?a=1');
            $this->assertFalse($router->rejected());
            unset($_REQUEST['username']);
        }

        /** @return array<string, array{array<string, string>, bool}> */
        public static function paths(): array
        {
            return [
                'whole path published'      => [['xseo1' => 'about', 'xseo2' => 'team'], true],
                'page itself not published' => [['xseo1' => 'about', 'xseo2' => 'old'], true],
                'unknown part'              => [['xseo1' => 'nonsense', 'xseo2' => 'team'], false],
                'unpublished part above'    => [['xseo1' => 'old', 'xseo2' => 'team'], false],
                'unknown page'              => [['xseo1' => 'about', 'xseo2' => 'nonsense'], false],
                'empty part'                => [['xseo1' => 'about', 'xseo2' => '', 'xseo3' => 'team'], false],
                'home without a path'       => [[], true],
            ];
        }

        /** @param array<string, string> $state */
        #[DataProvider('paths')]
        public function test_xseoPathPublished(array $state, bool $expected): void
        {
            $router = new class extends AA_Router_Seo {
                /** @param array<string, string> $state */
                public function setState(array $state): void
                {
                    $this->apc = $state;
                }

                public function _xseo2id($seo_string)
                {
                    return ['about' => 'a1', 'team' => 't1', 'old' => 'o1'][$seo_string] ?? '';
                }

                protected function isPublished(string $id): bool
                {
                    return $id !== 'o1';
                }
            };
            $router->setState($state);
            $this->assertSame($expected, $router->xseoPathPublished());
        }
    }
}
