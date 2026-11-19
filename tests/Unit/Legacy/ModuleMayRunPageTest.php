<?php

declare(strict_types=1);

/**
 * Which admin pages may run with a module selected - AA_Module::mayRunPage(), asked by
 * include/init_page.php before every page.
 *
 * The generic pages (admin/*, central/*) read the role letters as slice rights. A module type
 * whose letters mean something else names the generic pages its users may open; the types AA
 * has today read them as slice rights and refuse nothing.
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
        'CACHE_TTL'       => 600,
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA_Module;
    use AA_Moduleregistry;
    use AA_Perm;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeAuth;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;
    use Tests\Unit\Legacy\Fixtures\OwnPagesModule;

    final class ModuleMayRunPageTest extends TestCase
    {
        private const OWN   = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        private const SLICE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/fixtures/OwnPagesModule.php';
            require_once __DIR__ . '/../../../include/mgettext.php';
            class_exists(AA_Perm::class);   // include/perm_core.php: the PS_* letters, IsSuperadmin(), CheckPerms()
        }

        protected function tearDown(): void
        {
            OwnPagesModule::forget(self::OWN);
            OwnPagesModule::forget(self::SLICE);
            unset($GLOBALS['auth'], $GLOBALS['permission_uid'], $GLOBALS['permission_to'], $GLOBALS['r_superuser']);
        }

        /** AA's permission cache as AA_Perm::cache() leaves it: $role on both modules, or the superadmin */
        private static function holding(string $role, bool $superadmin = false): void
        {
            $GLOBALS['auth']           = new FakeAuth('u1');
            $GLOBALS['permission_uid'] = 'u1';
            $GLOBALS['permission_to']  = [
                'aa'    => [AA_ID => $superadmin ? AA_Perm::ROLES['SUPER']['perm'] : ''],
                'slice' => $role ? [self::OWN => AA_Perm::ROLES[$role]['perm'], self::SLICE => AA_Perm::ROLES[$role]['perm']] : [],
            ];
            $GLOBALS['r_superuser']    = [AA_ID => $superadmin];
        }

        public function test_mayRunPage_theTypesOfAA_refuseNothing(): void
        {
            self::holding('AUTHOR');

            foreach (AA_Moduleregistry::singleton() as $type => $conf) {
                self::assertTrue($conf->get('class')::mayRunPage('admin/se_tasks.php', self::SLICE), $type);
            }
        }

        /**
         * Asked of a module id rather than of a type - what a page asks about another page it links to
         * (the Profile buttons of se_users.php, the note corner). No module, or one of a type AA no
         * longer knows, runs as a slice and refuses nothing - as in init_page.php.
         */
        public function test_mayRunPageOn_asksTheTypeOfThatModule(): void
        {
            OwnPagesModule::register(self::OWN);
            new FakeSlice(self::SLICE);

            self::holding('ADMINISTRATOR');
            self::assertFalse(AA_Module::mayRunPageOn('admin/se_profile.php', self::OWN));
            self::assertTrue(AA_Module::mayRunPageOn('modules/polls/index.php', self::OWN));
            self::assertTrue(AA_Module::mayRunPageOn('admin/se_profile.php', self::SLICE));
            self::assertTrue(AA_Module::mayRunPageOn('admin/se_profile.php', ''));
            self::assertTrue(AA_Module::mayRunPageOn('admin/se_profile.php', null));

            self::holding('', true);
            self::assertTrue(AA_Module::mayRunPageOn('admin/se_profile.php', self::OWN));
        }

        /** init_page.php sends a refused page to the module's own one - which must not be refused in turn */
        public function test_mayRunPage_theModulesOwnPage_isNeverRefused(): void
        {
            self::holding('');

            foreach (AA_Moduleregistry::singleton() as $type => $conf) {
                self::assertTrue($conf->get('class')::mayRunPage($conf->get('url'), self::SLICE), $type);
            }
            self::assertTrue(OwnPagesModule::mayRunPage(OwnPagesModule::getModuleconfig()->get('url'), self::OWN));
        }
    }
}
