<?php

declare(strict_types=1);

// RecordingDb (store_fixtures) loads the real locsess.php, which needs the constants include/config.php normally provides.

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

    use AA_Content;
    use AA_Field;
    use AA_Value;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeFields;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * The {ajax} widget of a field (AA_Field::getWidgetAjaxHtml(), used by Get_Widget). A password, 2FA
     * secret, password copy or unreadable field shows only its own pwd/tfa widget, whatever widget is
     * asked for, and the stored value never reaches the HTML; with any other own widget it shows nothing.
     */
    final class FieldProtectedWidgetTest extends TestCase
    {
        private const SLICE = '5555555555555555aaaaaaaaaaaaaaaa';
        private const ITEM  = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        private const HASH  = '$2y$10$STOREDHASHSTOREDHASHSTOREDHASH';

        private static FakeSlice $slice;

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            self::$slice = new FakeSlice(self::SLICE);
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            self::$slice->setFields(new FakeFields(self::SLICE, []));
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        private static function field(string $id, array $data = []): AA_Field
        {
            return new AA_Field($data + ['id' => $id, 'slice_id' => pack_id(self::SLICE), 'name' => $id, 'input_show_func' => 'fld', 'input_insert_func' => 'qte', 'input_validate' => 'text', 'content_edit' => 0, 'text_stored' => 1] + array_fill_keys(['multiple', 'required', 'html_default', 'html_show', 'input_pri', 'search_pri', 'feed'], 0) + array_fill_keys(AA_Field::USED_FIELDS, ''));
        }

        private static function content(string $field_id, ?string $value): AA_Content
        {
            $content = new AA_Content();
            $content->setOwnerId(self::SLICE);
            $content->setId(self::ITEM);
            if ($value !== null) {
                $content->setAaValue($field_id, new AA_Value($value));
            }
            return $content;
        }

        private static function html(AA_Field $field, ?string $value, ?string $type = 'fld', array $props = []): string
        {
            return $field->getWidgetAjaxHtml(self::content($field->getId(), $value), $type, $props);
        }

        public function test_a_plain_field_shows_its_value_in_the_requested_widget(): void
        {
            $this->assertStringContainsString('STOREDHASH', self::html(self::field('text............'), self::HASH), 'the probe itself works');
        }

        public function test_password_field_never_prints_the_stored_value(): void
        {
            foreach (['fld', 'txt', 'hid', 'sel', null] as $type) {
                $html = self::html(self::field('password........', ['input_show_func' => 'pwd', 'input_insert_func' => 'pwd']), self::HASH, $type, ['const_arr' => ['x' => 'y']]);

                $this->assertStringNotContainsString('STOREDHASH', $html, (string) $type);
                $this->assertStringNotContainsString('value="*"', $html, (string) $type);
                $this->assertStringContainsString('type="password"', $html, (string) $type);
                $this->assertStringContainsString('[pwd][old]', $html, 'a value is there - the current password is asked for');
            }
        }

        public function test_empty_password_asks_for_no_current_password(): void
        {
            $html = self::html(self::field('password........', ['input_show_func' => 'pwd']), null);

            $this->assertStringNotContainsString('[pwd][old]', $html);
            $this->assertStringContainsString('[pwd][new1]', $html);
        }

        public function test_protected_field_with_another_own_widget_shows_nothing(): void
        {
            $fields = [
                'password copy by name'  => self::field('password.......1'),
                'unreadable'             => self::field('text...........1', ['content_edit' => FIELD_UNREADABLE]),
                'pwd inserter'           => self::field('text...........2', ['input_insert_func' => 'pwd']),
                '2fa inserter'           => self::field('text...........3', ['input_insert_func' => '2fa']),
                'module password field'  => self::field('_password......1'),
                'module secret field'    => self::field('_secret........1'),
            ];
            foreach ($fields as $case => $field) {
                $this->assertTrue($field->isProtected(), $case);
                $this->assertSame('', self::html($field, self::HASH), $case);
            }
        }

        public function test_bck_field_of_a_pwd_inserter_is_protected(): void
        {
            $copy = self::field('text............');
            self::$slice->setFields(new FakeFields(self::SLICE, [self::field('password........', ['input_show_func' => 'pwd', 'input_insert_func' => 'pwd:text............:phrase']), $copy]));

            $this->assertTrue($copy->isProtected());
            $this->assertSame('', self::html($copy, 'plain password copy'));
            $this->assertFalse(self::field('headline........')->isProtected());
        }

        public function test_secret_field_gets_its_tfa_widget(): void
        {
            $html = self::html(self::field('secret..........', ['input_show_func' => 'tfa']), 'JBSWY3DPEHPK3PXP');

            $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $html);
            $this->assertStringContainsString('[tfa][otc]', $html);
        }

        public function test_mch_selection_escapes_stored_values(): void
        {
            $key  = 'x"]\');alert(1);//';
            $html = self::html(self::field('category........'), $key, 'mch', ['const_arr' => [$key => '<img src=x onerror=alert(2)>']]);

            $this->assertStringContainsString('data-aa-mchval="x&quot;]&apos;);alert(1);//"', $html);
            $this->assertStringNotContainsString('<img', $html);
            $this->assertStringNotContainsString('alert(1);//&quot;]', $html, 'the value is not written into the script');
            $this->assertMatchesRegularExpression('/onclick="[^"]*this\.dataset\.aaMchval[^"]*"/', $html);
        }
    }
}
