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

    use AA_Session;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;

    /**
     * The permission cache in a session started before the request variables were kept out of it may be
     * forged (r_superuser from the URL). Such a session gets the cache dropped once - a marked one keeps it.
     * (The session itself is not started here - the method works on $_SESSION and the globals.)
     */
    final class SessionPermissionCacheTest extends TestCase
    {
        private const KEYS = ['permission_uid', 'permission_to', 'r_superuser'];

        private array $session_before = [];
        private array $globals_before = [];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            $this->session_before = $_SESSION ?? [];
            foreach (self::KEYS as $key) {
                $this->globals_before[$key] = $GLOBALS[$key] ?? null;
            }
        }

        protected function tearDown(): void
        {
            $_SESSION = $this->session_before;
            foreach ($this->globals_before as $key => $value) {
                $GLOBALS[$key] = $value;
            }
        }

        private static function drop(): void
        {
            $session = (new ReflectionClass(AA_Session::class))->newInstanceWithoutConstructor();
            (new \ReflectionMethod(AA_Session::class, 'dropUnmarkedPermissionCache'))->invoke($session);
        }

        public function test_unmarked_session_loses_the_cache_and_keeps_the_rest(): void
        {
            $_SESSION = ['permission_uid' => '', 'permission_to' => ['aa' => ['x' => 'Q']], 'r_superuser' => ['x' => true], 'r_state' => ['a' => 1]];
            $GLOBALS['r_superuser'] = ['x' => true];

            self::drop();

            $this->assertSame(['r_state' => ['a' => 1], 'perm_cache_v' => 2], $_SESSION);
            $this->assertNull($GLOBALS['r_superuser']);
        }

        public function test_marked_session_keeps_its_cache(): void
        {
            $_SESSION = ['permission_uid' => 'u1', 'r_superuser' => ['x' => true], 'perm_cache_v' => 2];

            self::drop();

            $this->assertSame(['x' => true], $_SESSION['r_superuser']);
        }
    }
}
