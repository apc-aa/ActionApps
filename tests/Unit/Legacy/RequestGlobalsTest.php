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
    use AA_Perm;
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
        private const TOUCHED = ['r_superuser', 'permission_uid', 'permission_to', 'auth', 'sess', 'slices', 'plain', 'encap', 'slice_id', 'x', 'r_packed_state_vars'];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/constants.php';
            require_once __DIR__ . '/../../../include/util.php';
            require_once __DIR__ . '/../../../include/locauth.php';
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

        public function test_perm_cache_keeps_in_the_session_its_own_values_not_the_request_ones(): void
        {
            $session = $_SESSION ?? null;
            $_SESSION = [];
            // the same as Session::register(): a missing key is taken from the global
            $GLOBALS['sess'] = new class {
                public function register(string $name): void
                {
                    if (!isset($_SESSION[$name])) {
                        $_SESSION[$name] = $GLOBALS[$name];
                    }
                }
            };
            $GLOBALS['r_superuser']    = ['aa' => true];
            $GLOBALS['permission_uid'] = 'u1';
            $GLOBALS['permission_to']  = ['aa' => ['aa' => 'A'], 'other' => 1];
            $perm = new class ([]) extends AA_Perm {
                public function getUserPerms($uid, $objectType, $flags = 0): array
                {
                    return [];
                }
            };
            try {
                $perm->cache('u1');
                $this->assertSame([], $_SESSION['r_superuser']);
                $this->assertSame('u1', $_SESSION['permission_uid']);
                $this->assertSame(['slice' => [], 'aa' => []], $_SESSION['permission_to']);
            } finally {
                $_SESSION = $session;
            }
        }

        public function test_restored_state_does_not_set_the_permission_cache(): void
        {
            RestoreVariables(['r_superuser' => ['aa' => true], 'permission_uid' => '1', 'x' => '5']);

            $this->assertArrayNotHasKey('r_superuser', $GLOBALS);
            $this->assertArrayNotHasKey('permission_uid', $GLOBALS);
            $this->assertSame('5', $GLOBALS['x']);
        }

        public function test_stored_post_does_not_set_the_permission_cache(): void
        {
            $vars = serialize(['post' => ['r_superuser' => ['aa' => true], 'permission_uid' => '1', 'plain' => 'x'], 'get' => [], 'files' => [], 'cookie' => []]);
            $db = new class ($vars) {
                public function __construct(private string $vars) {}

                public function query(string $sql): void {}

                public function next_record(): bool
                {
                    return true;
                }

                public function f(string $name): string
                {
                    return $this->vars;
                }

                public function record(?string $name = null): array|string
                {
                    return $name ? $this->vars : ['vars' => $this->vars];
                }
            };
            $spare = $GLOBALS['spareDBs'] ?? null;
            $uri   = $_SERVER['REQUEST_URI'] ?? null;
            $GLOBALS['spareDBs']    = [$db];
            $_SERVER['REQUEST_URI'] = '/page.shtml?post2shtml_id=' . str_repeat('ab', 16);
            try {
                add_post2shtml_vars();
            } finally {
                $GLOBALS['spareDBs']    = $spare;
                $_SERVER['REQUEST_URI'] = $uri;
                unset($GLOBALS['post2shtml_id']);
            }
            $this->assertArrayNotHasKey('r_superuser', $GLOBALS);
            $this->assertArrayNotHasKey('permission_uid', $GLOBALS);
            $this->assertSame('x', $GLOBALS['plain']);
        }

        /** @return array<string, array{string}> */
        public static function requestLoopScripts(): array
        {
            return [
                'view.php'       => ['view.php'],
                'slice.php'      => ['slice.php'],
                'jsview.php'     => ['jsview.php'],
                'discussion.php' => ['discussion.php'],
                'filler.php'     => ['filler.php'],
                'getxml.php'     => ['admin/getxml.php'],
            ];
        }

        /** The public scripts copy $_REQUEST into globals before the autoloader exists, so each loop must skip the protected names itself. */
        #[DataProvider('requestLoopScripts')]
        public function test_request_loops_skip_protected_names(string $script): void
        {
            $src = (string) file_get_contents(__DIR__ . '/../../../' . $script);
            $loops   = preg_match_all('/foreach \(\$_REQUEST as \$k => \$v\)/', $src);
            $guarded = preg_match_all('/foreach \(\$_REQUEST as \$k => \$v\) \{\s*if \(!\\\\AA\\\\Util\\\\InputVars::isProtectedGlobal\(\(string\) \$k\)\)/', $src);
            $this->assertGreaterThan(0, $loops);
            $this->assertSame($loops, $guarded);
            $this->assertMatchesRegularExpression('#require_once __DIR__ \. \'[./]*/src/Util/InputVars\.php\';#', $src);
        }
    }
}
