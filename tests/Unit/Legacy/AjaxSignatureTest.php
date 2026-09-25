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
        'AA_ID'           => '0123456789abcdef0123456789abcdef',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA\Util\LogOnce;
    use AA\Widget\AjaxSignature;
    use AA_Field;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * {ajax} signs the widget type and properties it puts into the page; Get_Widget uses them only with
     * a valid signature. Anything else - an old cached page, a forged or changed request - gets the
     * field's own widget and a log row for State Check.
     */
    final class AjaxSignatureTest extends TestCase
    {
        private const SLICE = '0123456789abcdef0123456789abcdef';
        private const ITEM  = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        private const PROPS = '{"filter_conds_rw":"d-relation.......6-==-f4d5892d6b0d75fbacfe47c3adb34daa","sort_by":"headline........"}';
        private const PROBE = '{"slice_field":"PROBE-{math:6*7}-END"}';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            class_exists('AA_Stringexpand_Ajax');   // json2asoc()
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            LogOnce::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            LogOnce::reset();
        }

        private static function field(string $id = 'relation........'): AA_Field
        {
            return new AA_Field(['id' => $id, 'slice_id' => pack_id(self::SLICE), 'name' => $id, 'input_show_func' => 'sel:#sLiCe-' . self::SLICE, 'input_insert_func' => 'qte', 'input_validate' => 'text', 'text_stored' => 1] + array_fill_keys(['content_edit', 'multiple', 'required', 'html_default', 'html_show', 'input_pri', 'search_pri', 'feed'], 0) + array_fill_keys(AA_Field::USED_FIELDS, ''));
        }

        /** @return string[] the INSERTs into the log */
        private static function logInserts(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn(string $sql) => str_starts_with($sql, 'INSERT INTO `log`')));
        }

        /** the arguments of displayInput() as the browser gets them from the onclick attribute */
        private static function browserArgs(string $onclick): array
        {
            $js = html_entity_decode(substr($onclick, strlen('onclick="displayInput('), -2), ENT_QUOTES | ENT_HTML5, 'ISO-8859-1');
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/s", $js, $m);
            return array_map(static fn(string $s) => preg_replace_callback('/\\\\(.)/s', static fn($c) => ['n' => "\n", 'r' => "\r"][$c[1]] ?? $c[1], $s), $m[1]);
        }

        public function test_signed_settings_are_used_as_sent(): void
        {
            $sig = AjaxSignature::sign(self::ITEM, 'relation........', 'sel', self::PROPS);

            [$type, $props] = AjaxSignature::widgetArgs(self::field(), self::ITEM, 'sel', self::PROPS, $sig);

            $this->assertSame('sel', $type);
            $this->assertSame(['filter_conds_rw' => 'd-relation.......6-==-f4d5892d6b0d75fbacfe47c3adb34daa', 'sort_by' => 'headline........'], $props);
            $this->assertSame([], self::logInserts());
        }

        public function test_onclick_sends_exactly_what_is_signed(): void
        {
            $nasty = "{\"const_arr\":{\"a'b\":\"x \\\\ \\\" &amp; </script> \n ž\"}}";

            $args = self::browserArgs(AjaxSignature::onclick('ajaxv_x', self::ITEM, 'relation........', 'sel', $nasty, 'CRED'));

            $this->assertCount(7, $args);
            [$valdivid, $item_id, $field_id, $type, $props, $context, $sig] = $args;
            $this->assertSame(['ajaxv_x', self::ITEM, 'relation........', 'sel', $nasty, 'CRED'], [$valdivid, $item_id, $field_id, $type, $props, $context]);
            $this->assertSame('sel', AjaxSignature::widgetArgs(self::field(), $item_id, $type, $props, $sig)[0]);
        }

        public function test_onclick_is_one_html_attribute(): void
        {
            $onclick = AjaxSignature::onclick('ajaxv_x', self::ITEM, 'relation........', 'sel', '{"a":"\" onmouseover=\"alert(1)"}', 'CRED');

            $this->assertSame(2, substr_count($onclick, '"'), 'no quote breaks out of the attribute');
            $this->assertStringNotContainsString('<', $onclick);
        }

        public function test_the_probe_without_signature_is_not_used_and_is_logged(): void
        {
            [$type, $props] = AjaxSignature::widgetArgs(self::field(), self::ITEM, 'sel', self::PROBE, '');
            AjaxSignature::widgetArgs(self::field(), self::ITEM, 'fld', self::PROBE, '');

            $this->assertNull($type, 'the field keeps its own widget');
            $this->assertSame([], $props);
            $inserts = self::logInserts();
            $this->assertCount(1, $inserts, 'once a day per slice and field');
            $this->assertStringContainsString("'" . AjaxSignature::EVENT . "'", $inserts[0]);
            $this->assertStringContainsString("'" . ParamImplode([self::SLICE, 'relation........']) . "'", $inserts[0]);
            $this->assertStringContainsString("'unsigned'", $inserts[0]);
            $this->assertStringNotContainsString('math', $inserts[0], 'the properties are not logged');
        }

        public function test_a_changed_request_loses_its_settings(): void
        {
            $sig = AjaxSignature::sign(self::ITEM, 'relation........', 'sel', self::PROPS);

            foreach ([
                'item'  => [self::field(), 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'sel', self::PROPS],
                'field' => [self::field('relation.......1'), self::ITEM, 'sel', self::PROPS],
                'type'  => [self::field(), self::ITEM, 'fld', self::PROPS],
                'props' => [self::field(), self::ITEM, 'sel', self::PROBE],
            ] as $changed => [$field, $item_id, $type, $props]) {
                $this->assertSame([null, []], AjaxSignature::widgetArgs($field, $item_id, $type, $props, $sig), $changed);
            }
            $this->assertStringContainsString("'bad signature'", self::logInserts()[0]);
        }

        public function test_a_plain_ajax_needs_no_signature(): void
        {
            $this->assertSame([null, []], AjaxSignature::widgetArgs(self::field(), self::ITEM, '', '', ''));
            $this->assertSame([], self::logInserts());
        }

        public function test_signed_properties_without_a_widget_type(): void
        {
            $sig = AjaxSignature::sign(self::ITEM, 'relation........', '', self::PROPS);

            [$type, $props] = AjaxSignature::widgetArgs(self::field(), self::ITEM, '', self::PROPS, $sig);

            $this->assertNull($type);
            $this->assertArrayHasKey('sort_by', $props);
        }

        public function test_a_page_in_another_charset_signs_the_utf8_the_browser_sends(): void
        {
            $page_props = (string) iconv('UTF-8', 'WINDOWS-1250', '{"const_arr":{"1":"ano ž"}}');

            $args = self::browserArgs(AjaxSignature::onclick('ajaxv_x', self::ITEM, 'relation........', 'sel', $page_props, 'CRED', 'windows-1250'));
            [$type, $props] = AjaxSignature::widgetArgs(self::field(), self::ITEM, 'sel', '{"const_arr":{"1":"ano ž"}}', $args[6]);

            $this->assertSame('sel', $type);
            $this->assertSame(['const_arr' => ['1' => 'ano ž']], $props, 'decoded from the very string that verified');
        }

        public function test_signed_conds_and_sort_are_not_expanded_again(): void
        {
            $field = self::field();
            $props = ['sort_by' => '{math:6*7}', 'filter_conds_rw' => 'd-headline........-=-{math:6*7}'];
            $field->getWidgetAjaxHtml(new \AA_Content(), 'sel', $props);   // Get_Widget marks the request properties

            $widget = $field->getWidget('sel', $props);
            $this->assertSame([null, 'd-headline........-=-{math:6*7}', '{math:6*7}'], $widget->expandConds('', null, $props['filter_conds_rw'], $props['sort_by']));
            $this->assertSame(['d-x-=-42', null, '42'], (new \AA\Widget\SelWidget())->expandConds('', 'd-x-=-{math:6*7}', null, '{math:6*7}'), 'the field\'s own conds are expanded');
        }

        public function test_signed_sort_by_alias_gets_no_postsort(): void
        {
            $postsort = static fn(\AA_Set $set) => (new \ReflectionProperty(\AA_Set::class, 'postsort'))->getValue($set);

            $literal = (new \AA\Widget\SelWidget())->setLiteral(['sort_by'])->optionsSet(self::SLICE, null, null, '_#HEADLINE', 1);
            $own     = (new \AA\Widget\SelWidget())->optionsSet(self::SLICE, null, null, '_#HEADLINE', 1);

            $this->assertSame([], $postsort($literal));
            $this->assertNotSame([], $postsort($own));
            $this->assertSame([], $postsort((new \AA\Widget\SelWidget())->setLiteral(['sort_by'])->optionsSet(self::SLICE, null, null, 'sort[0]=_%23HEADLINE', 1)), 'percent-encoded too');
        }
    }
}
