<?php

declare(strict_types=1);

// store_fixtures loads the real locsess.php (class AA), which needs the constants include/config.php normally provides.

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
    use AA_Perm;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    /** ChangeRole() (include/se_users.php) gives a user a role in a slice. The role name comes from the
     *  request, so only the roles a slice has may pass - SUPER belongs to the whole AA, not to a slice. */
    final class SeUsersChangeRoleTest extends TestCase
    {
        private mixed $perm = null;
        private object $backend;

        public static function setUpBeforeClass(): void
        {
            // loading locsess.php warns about the missing web request
            set_error_handler(static fn(): bool => true);
            try {
                require_once __DIR__ . '/fixtures/store_fixtures.php';
                require_once __DIR__ . '/../../../include/se_users.php';
            } finally {
                restore_error_handler();
            }
        }

        protected function setUp(): void
        {
            class_exists(AA_Perm::class);              // its first load sets AA::$perm - not over the stub
            $this->perm    = AA::$perm;
            // a granted role stops at addPerm(): the cache invalidation after it would need a database
            $this->backend = new class {
                /** @var list<string> */
                public array $calls = [];

                public function getModulePerms(string $user_id, string $obj_id, bool $whole = true): string
                {
                    $this->calls[] = 'getModulePerms';
                    return '';
                }

                public function addPerm(string $user_id, string $obj_id, string $obj_type, mixed $perm): never
                {
                    throw new \LogicException("addPerm $perm");
                }
            };
            AA::$perm = $this->backend;
        }

        protected function tearDown(): void
        {
            AA::$perm = $this->perm;
        }

        /** @return array<string, array{string}> */
        public static function foreignRoles(): array
        {
            return [
                'superadmin' => ['SUPER'],
                'unknown'    => ['OWNER'],
                'lowercase'  => ['editor'],
                'empty'      => [''],
            ];
        }

        #[DataProvider('foreignRoles')]
        public function test_role_outside_the_slice_roles_is_not_granted(string $role): void
        {
            // the editor's extra letters put it above any role (compare() counts letters), so only the name decides
            \ChangeRole('7', false, '7e5700000000000000000000000000ab', AA_Perm::ROLES['ADMINISTRATOR']['perm'] . 'XYZ', $role);
            $this->assertSame([], $this->backend->calls);
        }

        public function test_slice_role_is_granted(): void
        {
            $this->expectExceptionMessage('addPerm ' . AA_Perm::ROLES['EDITOR']['id']);
            \ChangeRole('7', false, '7e5700000000000000000000000000ab', AA_Perm::ROLES['ADMINISTRATOR']['perm'], 'EDITOR');
        }
    }
}
