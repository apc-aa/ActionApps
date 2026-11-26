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
    use AA\Util\Note;
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

        /** aa_note.php takes the resource from the POST - it writes only where the form was rendered */
        public function test_isSigned_onlyTheFormTheUserWasShownOnThisModule(): void
        {
            self::on(self::SLICE, 'EDITOR');
            $resource = 'page:' . self::SLICE . ':admin/index.php';
            $sig      = Notes::sign('u1', self::SLICE, 'add', $resource);

            self::assertTrue(Notes::isSigned('add', $resource, $sig));
            self::assertFalse(Notes::isSigned('add', 'page:' . self::SLICE . ':admin/se_fields.php', $sig), 'another page of the module');
            self::assertFalse(Notes::isSigned('add', 'page:' . self::OWN . ':admin/index.php', $sig), 'a page of another module');
            self::assertFalse(Notes::isSigned('edit', $resource, $sig), 'the signature of another form on that resource');
            self::assertFalse(Notes::isSigned('add', $resource, ''), 'a form from before the signature');
            self::assertFalse(Notes::isSigned('add', $resource, Notes::sign('u2', self::SLICE, 'add', $resource)), 'signed for somebody else');

            self::on(self::OWN, '', true);
            self::assertFalse(Notes::isSigned('add', $resource, $sig), 'posted on another module - the superadmin too');
        }

        /** the signature a rendered form carries is the one the endpoint asks for */
        public function test_isSigned_theSignatureOfARenderedForm(): void
        {
            self::on(self::SLICE, '', true);
            self::assertSame(1, preg_match('/name="sig" value="([0-9a-f]{64})"/', Notes::resolveForm('finding:Check:key', '/aa/admin/aa_statecheck.php'), $m));
            self::assertTrue(Notes::isSigned('resolve', 'finding:Check:key', $m[1]));
            self::assertFalse(Notes::isSigned('add', 'finding:Check:key', $m[1]));
        }

        /** the slice wizard has no module: its form posts without one and runs on the user's fallback module */
        public function test_isSigned_aFormOfAPageWithoutModule_onAnyModule(): void
        {
            self::on(self::SLICE, '', true);
            AA::$module_id = null;
            self::assertSame(1, preg_match('/name="sig" value="([0-9a-f]{64})"/', Notes::resolveForm('page::admin/slicewiz.php', '/aa/admin/slicewiz.php'), $m));

            self::on(self::SLICE, '', true);
            self::assertTrue(Notes::isSigned('resolve', 'page::admin/slicewiz.php', $m[1]));
        }

        public function test_render_theEditFormIsForTheAuthorOnly(): void
        {
            self::on(self::SLICE, 'EDITOR');
            // the note head asks for the author's name - no user database here
            $perm     = AA::$perm;
            AA::$perm = new class {
                public function getUserName(string $uid): string
                {
                    return "name of $uid";
                }
            };
            try {
                $this->assertEditFormOnlyForTheAuthor();
            } finally {
                AA::$perm = $perm;
            }
        }

        private function assertEditFormOnlyForTheAuthor(): void
        {
            $note = new Note(['resource' => 'page:' . self::SLICE . ':admin/index.php', 'author' => 'u1', 'created' => 1, 'text' => 'a <b>typo</b></textarea><script>']);

            $own = Notes::render($note, '/aa/admin/index.php');
            self::assertStringContainsString('name="action" value="edit"', $own);
            self::assertStringContainsString('<textarea name="text" rows="3" required>a &lt;b&gt;typo&lt;/b&gt;&lt;/textarea&gt;&lt;script&gt;</textarea>', $own, 'the stored text, escaped');

            $note->state = Note::STATE_RESOLVED;
            self::assertStringContainsString('<textarea name="text" rows="3">a &lt;b&gt;', Notes::render($note, '/aa/admin/index.php'), 'the reason of a resolution may be emptied');
            $note->state = Note::STATE_VALID;

            $note->author = 'u2';
            self::assertStringNotContainsString('value="edit"', Notes::render($note, '/aa/admin/index.php'));
        }
    }
}
