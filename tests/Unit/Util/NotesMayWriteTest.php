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
        'CACHE_TTL'       => 600,
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Util {

    use AA;
    use AA\Util\Notes;
    use AA_Perm;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeAuth;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;
    use Tests\Unit\Legacy\Fixtures\OwnPagesModule;

    /**
     * Who gets the note corner. Its form posts to admin/aa_note.php on the page's module, so the
     * corner follows what that module lets its users run (AA_Module::mayRunPage()) - on a type
     * whose letters are not slice rights, "edit all items" is no reason to show it.
     */
    final class NotesMayWriteTest extends TestCase
    {
        private const OWN   = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        private const SLICE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        private ?string $module_id = null;

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../Legacy/fixtures/OwnPagesModule.php';
            require_once __DIR__ . '/../../../include/mgettext.php';
            class_exists(AA_Perm::class);   // include/perm_core.php: PS_* letters, IsSuperadmin(), IfSlPerm()
        }

        protected function setUp(): void
        {
            $this->module_id = AA::$module_id;
            OwnPagesModule::register(self::OWN);
            new FakeSlice(self::SLICE);
        }

        protected function tearDown(): void
        {
            AA::$module_id = $this->module_id;
            OwnPagesModule::forget(self::OWN);
            OwnPagesModule::forget(self::SLICE);
            unset($GLOBALS['auth'], $GLOBALS['permission_uid'], $GLOBALS['permission_to'], $GLOBALS['r_superuser']);
        }

        private static function on(string $module_id, string $role, bool $superadmin = false): void
        {
            AA::$module_id             = $module_id;
            $GLOBALS['auth']           = new FakeAuth('u1');
            $GLOBALS['permission_uid'] = 'u1';
            $GLOBALS['permission_to']  = [
                'aa'    => [AA_ID => $superadmin ? AA_Perm::ROLES['SUPER']['perm'] : ''],
                'slice' => $role ? [$module_id => AA_Perm::ROLES[$role]['perm']] : [],
            ];
            $GLOBALS['r_superuser']    = [AA_ID => $superadmin];
        }

        public function test_mayWrite_onASlice_theEditorOfAllItems(): void
        {
            self::on(self::SLICE, 'EDITOR');
            self::assertTrue(Notes::mayWrite());

            self::on(self::SLICE, 'AUTHOR');
            self::assertFalse(Notes::mayWrite());
        }

        public function test_mayWrite_onAModuleThatKeepsTheGenericPages_onlyTheSuperadmin(): void
        {
            self::on(self::OWN, 'ADMINISTRATOR');
            self::assertFalse(Notes::mayWrite(), 'aa_note.php does not run there for anybody else');

            self::on(self::OWN, '', true);
            self::assertTrue(Notes::mayWrite());
        }
    }
}
