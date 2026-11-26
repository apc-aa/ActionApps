<?php

declare(strict_types=1);

// include/item_content.php needs the constants include/config.php normally provides

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
        'DEFAULT_LANG_INCLUDE' => 'en-utf8_news_lang.php',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA\Later\ComputedfieldsUpdater;
    use AA_Field;
    use AA_Field_Writer;
    use AA_Value;
    use ItemContent;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeFields;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * A recompute that gets the stored value again writes nothing (ItemContent::updateComputedFields()),
     * so the periodic recompute leaves the page cache alone; the slice setting fields are recomputed too.
     */
    final class ComputedFieldsStoreTest extends TestCase
    {
        private const SLICE    = '22222222222222222222222222222222';
        private const ITEM     = 'dddddddddddddddddddddddddddddddd';
        private const COMPUTED = 'text...........1';

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
            $GLOBALS['err']      = [];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
        }

        // ------------------------------------------------------------ AA_Field_Writer::storesSame()

        public static function sameStoredProvider(): array
        {
            return [
                'text, same'                    => [true, new AA_Value('a', 0), new AA_Value('a', FLAG_TEXT_STORED), true],
                'text, other value'             => [true, new AA_Value('a', 0), new AA_Value('b', FLAG_TEXT_STORED), false],
                'text, html flag differs'       => [true, new AA_Value('a', FLAG_HTML), new AA_Value('a', FLAG_TEXT_STORED), false],
                'text, stored as number'        => [true, new AA_Value('5', 0), new AA_Value('5', FLAG_HTML), false],
                'text, multi with a hole'       => [true, new AA_Value([0 => 'a', 2 => 'b'], 0), new AA_Value(['a', 'b'], FLAG_TEXT_STORED), true],
                'text, translation keeps index' => [true, new AA_Value([AA_Value::MAX_INDEX + 1 => 'x'], 0), new AA_Value([AA_Value::MAX_INDEX + 1 => 'x'], FLAG_TEXT_STORED), true],
                'empty, nothing stored'         => [true, new AA_Value([], 0), new AA_Value(), true],
                'empty string, nothing stored'  => [true, new AA_Value('', 0), new AA_Value(), false],
                'number, html flag on read'     => [false, new AA_Value('12', 0), new AA_Value(12, FLAG_HTML), true],
                'number, decimals cut'          => [false, new AA_Value('12.7', 0), new AA_Value('12', FLAG_HTML), true],
                'number, other value'           => [false, new AA_Value('13', 0), new AA_Value('12', FLAG_HTML), false],
                'number, read in value order'   => [false, new AA_Value(['5', '3'], 0), new AA_Value(['3', '5'], FLAG_HTML), true],
                'number, still stored as text'  => [false, new AA_Value('12', 0), new AA_Value('12', FLAG_TEXT_STORED), false],
            ];
        }

        #[DataProvider('sameStoredProvider')]
        public function test_storesSame_compares_with_the_value_read_back(bool $text_stored, AA_Value $value, AA_Value $stored, bool $same): void
        {
            $this->assertSame($same, AA_Field_Writer::storesSame($text_stored, $value, $stored));
        }

        public static function itemColumnSameProvider(): array
        {
            $midnight = mktime(0, 0, 0, 12, 1, 2026);
            return [
                'number, same'               => ['status_code', new AA_Value('1', 0), new AA_Value(1, 0), true],
                'number, other value'        => ['status_code', new AA_Value('2', 0), new AA_Value(1, 0), false],
                'expiry at midnight ends the day' => ['expiry_date', new AA_Value((string) $midnight, 0), new AA_Value(mktime(23, 59, 59, 12, 1, 2026), 0), true],
                'expiry stored at midnight'  => ['expiry_date', new AA_Value((string) $midnight, 0), new AA_Value($midnight, 0), false],
                'the last of more values'    => ['highlight', new AA_Value(['1', '0'], 0), new AA_Value(0, 0), true],
                'empty, nothing written'     => ['status_code', new AA_Value([], 0), new AA_Value(1, 0), true],
                'text column'                => ['posted_by', new AA_Value('Anna', 0), new AA_Value('Anna', 0), true],
                'text column, other value'   => ['posted_by', new AA_Value('Anna', 0), new AA_Value('Ann', 0), false],
            ];
        }

        #[DataProvider('itemColumnSameProvider')]
        public function test_itemColumnSame_compares_with_the_column_value(string $column, AA_Value $value, AA_Value $stored, bool $same): void
        {
            $this->assertSame($same, AA_Field_Writer::itemColumnSame($column, $value, $stored));
        }

        // ------------------------------------------------------------ updateComputedFields()

        private function field(string $id, string $insert, array $data = []): AA_Field
        {
            return new AA_Field($data + [
                'id'                => $id,
                'text_stored'       => 1,
                'in_item_tbl'       => '',
                'input_show_func'   => 'fld',
                'input_insert_func' => $insert,
                'content_edit'      => 0,
                'html_default'      => 0,
                'multiple'          => 0,
            ] + array_fill_keys(['alias1', 'alias1_func', 'alias1_help', 'alias2', 'alias2_func', 'alias2_help', 'alias3', 'alias3_func', 'alias3_help'], ''));
        }

        private function row(string $item, string $field, int $number, string $text, int $flag): array
        {
            return ['item' => $item, 'field' => $field, 'number' => $number, 'text' => $text, 'flag' => $flag];
        }

        /** the item as setByItemID() reads it: the stored computed value and the headline it is computed from */
        private function storedItem(string $stored): ItemContent
        {
            self::$slice->setFields(new FakeFields(self::SLICE, [$this->field(self::COMPUTED, 'co2:x{headline........}:x{headline........}::')]));
            RecordingDb::$content = [$this->row(self::ITEM, self::COMPUTED, 0, $stored, FLAG_TEXT_STORED)];
            $ic = new ItemContent();
            $ic->setItemID(self::ITEM);
            $ic->setSliceID(self::SLICE);
            $ic->setAaValue('headline........', new AA_Value('Hello', 0));
            $ic->setAaValue(self::COMPUTED, new AA_Value($stored, FLAG_TEXT_STORED));
            return $ic;
        }

        public function test_updateComputedFields_unchanged_value_is_not_written(): void
        {
            $this->assertFalse($this->storedItem('xHello')->updateComputedFields());

            $this->assertSame(0, RecordingDb::count('DELETE') + RecordingDb::count('INSERT'), implode("\n", RecordingDb::$log));
        }

        public function test_updateComputedFields_changed_value_is_written(): void
        {
            $this->assertTrue($this->storedItem('xOld')->updateComputedFields());

            $this->assertSame([$this->row(self::ITEM, self::COMPUTED, 0, 'xHello', FLAG_TEXT_STORED)], RecordingDb::$content);
        }

        /** an item whose expiry_date is computed */
        private function storedExpiry(int $stored): ItemContent
        {
            self::$slice->setFields(new FakeFields(self::SLICE, [$this->field('expiry_date.....', 'co2:2000000000:2000000000::', ['in_item_tbl' => 'expiry_date', 'text_stored' => 0])]));
            $ic = new ItemContent();
            $ic->setItemID(self::ITEM);
            $ic->setSliceID(self::SLICE);
            $ic->setAaValue('headline........', new AA_Value('Hello', 0));
            $ic->setAaValue('expiry_date.....', new AA_Value($stored, 0));
            return $ic;
        }

        public function test_updateComputedFields_unchanged_item_column_is_not_written(): void
        {
            $this->assertFalse($this->storedExpiry(2000000000)->updateComputedFields());

            $this->assertSame(0, RecordingDb::count('UPDATE') + RecordingDb::count('DELETE'), implode("\n", RecordingDb::$log));
        }

        public function test_updateComputedFields_changed_item_column_is_written(): void
        {
            $this->assertTrue($this->storedExpiry(1900000000)->updateComputedFields());

            $updates = array_values(array_filter(RecordingDb::$log, static fn($sql) => str_starts_with($sql, 'UPDATE')));
            $this->assertCount(1, $updates, implode("\n", RecordingDb::$log));
            $this->assertStringContainsString('2000000000', $updates[0]);
        }

        // ------------------------------------------------------------ slice setting fields

        public function test_updateComputedSettingFields_computes_from_the_setting_and_stores_with_the_slice(): void
        {
            $setting_field = '_price..........';
            self::$slice->setFields(new FakeFields(self::SLICE, [
                // the alias of a setting field - the slice aliases do not have it
                $this->field('_rate...........', 'qte', ['alias1' => '_#RATE____', 'alias1_func' => 'f_h', 'alias1_help' => '']),
                $this->field($setting_field, 'co2:_#RATE____x:_#RATE____x::minute'),
            ], 1));
            RecordingDb::$content = [
                $this->row(self::SLICE, '_rate...........', 0, '25', FLAG_TEXT_STORED),
                $this->row(self::SLICE, $setting_field, 0, 'old', FLAG_TEXT_STORED),
            ];
            // loadsettingfields() reads the setting rows of the slice
            RecordingDb::$answer = static fn(string $sql): array => str_starts_with($sql, 'SELECT * FROM content')
                ? array_map(static fn($r) => ['field_id' => $r['field'], 'number' => $r['number'], 'text' => $r['text'], 'flag' => $r['flag']], RecordingDb::$content)
                : [];

            $this->assertTrue(self::$slice->updateComputedSettingFields());
            $this->assertContains($this->row(self::SLICE, $setting_field, 0, '25x', FLAG_TEXT_STORED), RecordingDb::$content);
            $this->assertSame('25x', self::$slice->get_dynamic_setting_content(true)->getValue($setting_field), 'the slice reads the new value');

            RecordingDb::$log = [];
            $this->assertFalse(self::$slice->updateComputedSettingFields(), 'the second run gets the same value');
            $this->assertSame(0, RecordingDb::count('DELETE') + RecordingDb::count('INSERT'));
        }

        public function test_planItems_recomputes_only_a_slice_with_computed_fields(): void
        {
            self::$slice->setFields(new FakeFields(self::SLICE, [$this->field(self::COMPUTED, 'qte')]));
            ComputedfieldsUpdater::planItems(self::SLICE);
            $this->assertSame(0, RecordingDb::count('INSERT'), 'nothing to recompute');

            self::$slice->setFields(new FakeFields(self::SLICE, [$this->field(self::COMPUTED, 'co2:x{headline........}:x{headline........}::')]));
            ComputedfieldsUpdater::planItems(self::SLICE);
            $inserts = array_values(array_filter(RecordingDb::$log, static fn($sql) => str_starts_with($sql, 'INSERT')));
            $this->assertCount(1, $inserts, implode("\n", RecordingDb::$log));
            $this->assertStringContainsString('ComputedfieldsUpdater_now_' . self::SLICE, $inserts[0]);
        }
    }
}
