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

    use AA_Router_Seo;
    use PHPUnit\Framework\TestCase;

    /** /cz9999/... answers 404 only when every listing paged by {xpage} ends before that page */
    final class RouterXpageTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../modules/site/router.class.php';
        }

        public function test_xpageBeyondEnd_noListingPagedByXpage(): void
        {
            $this->assertFalse((new AA_Router_Seo([], '/cz/'))->xpageBeyondEnd(), 'the page may read {xpage} its own way');
        }

        public function test_xpageBeyondEnd_everyListingEndsBefore(): void
        {
            $router = new AA_Router_Seo([], '/cz/');
            $router->noteXpageListing(false);
            $router->noteXpageListing(false);

            $this->assertTrue($router->xpageBeyondEnd());
        }

        public function test_xpageBeyondEnd_oneListingHasThePage(): void
        {
            $router = new AA_Router_Seo([], '/cz/');
            $router->noteXpageListing(false);
            $router->noteXpageListing(true);

            $this->assertFalse($router->xpageBeyondEnd(), 'a short side listing must not hide the main one');
        }
    }
}
