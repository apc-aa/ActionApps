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

    use AA\Util\InputVars;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Session;

    /**
     * Request variables copied into globals (InputVars::QuoteVars() on the admin item and slice-field
     * forms, add_vars() on the pages) must not set the session's login and permission cache:
     * r_superuser[AA_ID]=1 with a matching permission_uid made any editor a superadmin, and freeze()
     * kept it in the session.
     */
    final class RequestGlobalsTest extends TestCase
    {
        private const TOUCHED = ['r_superuser', 'permission_uid', 'permission_to', 'auth', 'sess', 'slices', 'plain', 'encap', 'slice_id'];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/constants.php';
            require_once __DIR__ . '/../../../include/util.php';
        }

        protected function setUp(): void
        {
            $_POST = [];
            $_GET  = [];
            foreach (self::TOUCHED as $name) {
                unset($GLOBALS[$name]);
            }
        }

        protected function tearDown(): void
        {
            $this->setUp();
        }

        /** @return array<string, array{string, bool}> */
        public static function names(): array
        {
            return [
                'superuser cache'  => ['r_superuser', true],
                'permission cache' => ['permission_to', true],
                'cache owner'      => ['permission_uid', true],
                'login'            => ['auth', true],
                'session object'   => ['sess', true],
                'manager state'    => ['r_state', true],
                'superglobal'      => ['_SESSION', true],
                'any _ name'       => ['_m', true],
                'globals array'    => ['GLOBALS', true],
                'slice.php list'   => ['slices', false],
                'form field'       => ['v686561646c696e65', false],
                'item id'          => ['id', false],
                'numeric key'      => ['0', false],
            ];
        }

        #[DataProvider('names')]
        public function test_protected_names(string $name, bool $protected): void
        {
            $this->assertSame($protected, InputVars::isProtectedGlobal($name));
        }

        public function test_every_session_global_but_slices_is_protected(): void
        {
            foreach (Session::LEGACY_GLOBAL_VARS as $name) {
                $this->assertSame($name !== 'slices', InputVars::isProtectedGlobal($name), $name);
            }
        }

        public function test_quotevars_does_not_set_the_permission_cache(): void
        {
            $_POST = ['r_superuser' => ['aa' => 1], 'permission_uid' => '1', 'permission_to' => ['slice' => []], 'plain' => "O'Neil", 'encap' => '1', 'slice_id' => 'x'];

            InputVars::QuoteVars('post', ['encap' => 1, 'slice_id' => 1]);

            $this->assertArrayNotHasKey('r_superuser', $GLOBALS);
            $this->assertArrayNotHasKey('permission_uid', $GLOBALS);
            $this->assertArrayNotHasKey('permission_to', $GLOBALS);
            $this->assertArrayNotHasKey('encap', $GLOBALS);
            $this->assertArrayNotHasKey('slice_id', $GLOBALS);
            $this->assertSame("O\\'Neil", $GLOBALS['plain'], 'form fields still come, slashed');
        }

        public function test_add_vars_does_not_set_the_permission_cache(): void
        {
            add_vars('r_superuser[aa]=1&permission_uid=1&slices=abc&plain=x');

            $this->assertArrayNotHasKey('r_superuser', $GLOBALS);
            $this->assertArrayNotHasKey('permission_uid', $GLOBALS);
            $this->assertSame('abc', $GLOBALS['slices']);
            $this->assertSame('x', $GLOBALS['plain']);
        }

        public function test_post2shtml_id_other_than_an_id_does_not_reach_the_database(): void
        {
            $db = new class {
                /** @var list<string> */
                public array $queries = [];

                public function query(string $sql): void
                {
                    $this->queries[] = $sql;
                }

                public function next_record(): bool
                {
                    return false;
                }

                public function f(string $name): string
                {
                    return '';
                }
            };
            $spare = $GLOBALS['spareDBs'] ?? null;
            $uri   = $_SERVER['REQUEST_URI'] ?? null;
            $GLOBALS['spareDBs']    = [$db];
            $_SERVER['REQUEST_URI'] = '/page.shtml?post2shtml_id=' . rawurlencode("x' OR '1'='1");
            try {
                add_post2shtml_vars();
            } finally {
                $GLOBALS['spareDBs']    = $spare;
                $_SERVER['REQUEST_URI'] = $uri;
                unset($GLOBALS['post2shtml_id']);
            }
            $this->assertSame([], $db->queries);
        }
    }
}
