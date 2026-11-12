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

    use AA_Router;
    use AA_Router_Seo;
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;

    /** AA_Router::singleton() makes the router of the class it is given - the name comes from apc_state */
    final class RouterSingletonTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../modules/site/router.class.php';
        }

        protected function setUp(): void
        {
            (new ReflectionProperty(AA_Router::class, '_instance'))->setValue(null, null);
        }

        protected function tearDown(): void
        {
            (new ReflectionProperty(AA_Router::class, '_instance'))->setValue(null, null);
        }

        public function test_singleton_routerClass_isMade(): void
        {
            $own = new class ([], '/cz/') extends AA_Router_Seo {};

            self::assertInstanceOf($own::class, AA_Router::singleton($own::class, [], '/cz/'));
        }

        public function test_singleton_otherClass_getsTheSeoRouter(): void
        {
            self::assertInstanceOf(AA_Router_Seo::class, AA_Router::singleton('stdClass', [], '/cz/'));
        }

        public function test_singleton_noClass_getsTheSeoRouter(): void
        {
            self::assertInstanceOf(AA_Router_Seo::class, AA_Router::singleton(['x'], [], '/cz/'));
        }
    }
}
