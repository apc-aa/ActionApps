<?php

declare(strict_types=1);

// AA_Field_Cleaner lives in include/item_content.php, whose require chain (locsess) needs the
// constants include/config.php normally provides.

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

    use AA_Field;
    use AA_Field_Cleaner;
    use AA_Value;
    use ItemContent;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeFields;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * Which old rows an item update deletes before writing the new ones. A translated value lives at
     * index <lang number> * MAX_INDEX; saving one language (live edit, a one-language form) must
     * replace that language only, a value in the base range replaces the whole field.
     *
     * Asserted on the DELETE statements: RecordingDb ignores the number conditions when it applies them.
     */
    final class FieldCleanerTranslationsTest extends TestCase
    {
        private const SLICE = '11111111111111111111111111111111';
        private const ITEM  = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        private const CZ    = 78 * AA_Value::MAX_INDEX;

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        /** @return string[] the DELETE statements of cleaning headline........ holding $value */
        private function deletes(AA_Value $value): array
        {
            $fields = new FakeFields(self::SLICE, [new AA_Field([
                'id'              => 'headline........',
                'text_stored'     => 1,
                'in_item_tbl'     => '',
                'input_show_func' => 'fld',
                'content_edit'    => 0,
            ])]);
            $ic = new ItemContent();
            $ic->setAaValue('headline........', $value);
            (new AA_Field_Cleaner($fields))->clean_updated_fields($ic, self::ITEM);

            return array_values(array_filter(RecordingDb::$log, static fn(string $sql) => str_starts_with($sql, 'DELETE')));
        }

        public function test_one_translation_leaves_the_other_languages(): void
        {
            $deletes = $this->deletes(new AA_Value([self::CZ => 'Ahoj', self::CZ + 1 => 'Nazdar']));

            self::assertNotEmpty($deletes);
            foreach ($deletes as $sql) {
                self::assertStringContainsString('number', $sql, "no statement deletes the whole field:\n$sql");
            }
            self::assertStringContainsString((string) self::CZ, $deletes[0], 'the cz range');
            self::assertStringContainsString((string) (self::CZ + AA_Value::MAX_INDEX), $deletes[0]);
        }

        public function test_two_translations_delete_the_untranslated_value_once(): void
        {
            $de = self::CZ + AA_Value::MAX_INDEX;   // the next language
            $deletes = $this->deletes(new AA_Value([self::CZ => 'Ahoj', $de => 'Hallo']));

            self::assertCount(4, $deletes, implode("\n", $deletes));   // two language ranges, the base range, NULL numbers
        }

        public function test_translation_only_content_keeps_its_flag(): void
        {
            self::assertSame(FLAG_HTML, (new AA_Value([self::CZ => ['value' => '<b>Ahoj</b>', 'flag' => FLAG_HTML]]))->getFlag());
            self::assertSame(FLAG_HTML, (new AA_Value([['value' => '<b>Hi</b>', 'flag' => FLAG_HTML]]))->getFlag());
            self::assertSame(0, (new AA_Value(['one', 'two']))->getFlag());
        }

        public function test_base_value_replaces_the_whole_field(): void
        {
            $deletes = $this->deletes(new AA_Value(['one', 'two']));

            self::assertCount(1, $deletes);
            self::assertStringNotContainsString('number', $deletes[0]);
        }

        public function test_empty_value_clears_the_whole_field(): void
        {
            $deletes = $this->deletes(new AA_Value());

            self::assertCount(1, $deletes);
            self::assertStringNotContainsString('number', $deletes[0]);
        }
    }
}
