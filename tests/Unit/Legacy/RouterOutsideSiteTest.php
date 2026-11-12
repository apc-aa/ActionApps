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
    use PHPUnit\Framework\Attributes\RunInSeparateProcess;
    use PHPUnit\Framework\TestCase;

    /**
     * The site router outside the site module: code that translates an address of a website into
     * its state builds one with "new" - site.php is not there to include the file or to hand it
     * the website's languages through singleton().
     */
    final class RouterOutsideSiteTest extends TestCase
    {
        #[RunInSeparateProcess]
        public function test_theRouterLoadsByItsName(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';   // the real locsess.php and its autoloader

            self::assertFalse(class_exists('AA_Router', false), 'nothing has included it');
            self::assertTrue(class_exists('AA_Router_Seo'));
        }

        /** the website's languages decide how an address without one is read - also for a router built with "new" */
        public function test_setWebLangs_aRouterBuiltWithNewTakesTheLanguages(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../modules/site/router.class.php';

            $router = new class (null, '/cz/home') extends AA_Router_Seo {
                /** @return array<string> */
                public function langs(): array
                {
                    return $this->web_languages;
                }
            };
            $router->setWebLangs(['cz', 'en']);

            self::assertSame(['cz', 'en'], $router->langs());
        }
    }
}
