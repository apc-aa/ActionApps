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
     * so the periodic recompute leaves the page cache alone.
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
            ];
        }

        #[DataProvider('sameStoredProvider')]
        public function test_storesSame_compares_with_the_value_read_back(bool $text_stored, AA_Value $value, AA_Value $stored, bool $same): void
        {
            $this->assertSame($same, AA_Field_Writer::storesSame($text_stored, $value, $stored));
        }

        // ------------------------------------------------------------ updateComputedFields()

        private function field(string $id, string $insert): AA_Field
        {
            return new AA_Field([
                'id'                => $id,
                'text_stored'       => 1,
                'in_item_tbl'       => '',
                'input_show_func'   => 'fld',
                'input_insert_func' => $insert,
                'content_edit'      => 0,
                'html_default'      => 0,
                'multiple'          => 0,
            ]);
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

        public function test_unchanged_value_is_not_written(): void
        {
            $this->assertFalse($this->storedItem('xHello')->updateComputedFields());

            $this->assertSame(0, RecordingDb::count('DELETE') + RecordingDb::count('INSERT'), implode("\n", RecordingDb::$log));
        }

        public function test_changed_value_is_written(): void
        {
            $this->assertTrue($this->storedItem('xOld')->updateComputedFields());

            $this->assertSame([$this->row(self::ITEM, self::COMPUTED, 0, 'xHello', FLAG_TEXT_STORED)], RecordingDb::$content);
        }
    }
}
