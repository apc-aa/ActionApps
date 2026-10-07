<?php

declare(strict_types=1);

/**
 * What AA_Field (include/field.class.php) reads from the widget of a field - the related slice or the
 * constant group, a multiline editor, the Manager widget. A field of a field slice (AA_Field_Slicedef)
 * has no input_show_func, its widget comes from the field slice.
 */

namespace {
    foreach ([
        'AA_INSTAL_URL'        => 'http://localhost/aa/',
        'AA_INSTAL_PATH'       => '/aa/',
        'IMG_UPLOAD_PATH'      => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'         => dirname(__DIR__, 3) . '/',
        'PERM_LIB'             => 'sql',
        'DB_HOST'              => 'localhost',
        'DB_NAME'              => 'aa_test',
        'DB_USER'              => 'aa',
        'DB_PASSWORD'          => '',
        'DEFAULT_LANG_INCLUDE' => 'en-utf8_news_lang.php',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA\Widget\Widget;
    use AA_Field;
    use AA_Field_Slicedef;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    final class FieldWidgetParamsTest extends TestCase
    {
        private const SLICE = '22222222222222222222222222222222';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        /** @param string $widget input_show_func, e.g. sel:lt_languages */
        private static function field(string $widget): AA_Field
        {
            return new AA_Field(['id' => 'text...........1', 'input_show_func' => $widget]);
        }

        /** the field as the AA_Field_Slicedef constructor leaves it, without the item of the field slice */
        private static function slicedef(string $type, array $params = []): AA_Field_Slicedef
        {
            return new class (['id' => 'text...........1', 'input_show_func' => ''], $params + ['class' => Widget::constructClassName($type)]) extends AA_Field_Slicedef {
                public function __construct(array $data, array $params)
                {
                    $this->data          = $data;
                    $this->widget_params = $params;
                }
            };
        }

        public static function relations(): array
        {
            return [
                'related items'  => [['relation', self::SLICE], 'sel:#sLiCe-' . self::SLICE, 'sel', ['const' => '#sLiCe-' . self::SLICE]],
                'constant group' => [['constants', 'lt_languages'], 'sel:lt_languages', 'sel', ['const' => 'lt_languages']],
                'no constants'   => [[], 'fld:', 'fld'],
            ];
        }

        #[DataProvider('relations')]
        public function test_getRelation_reads_the_widget_of_both_kinds_of_field(array $expected, string $widget, string $type, array $params = []): void
        {
            $this->assertSame($expected, self::field($widget)->getRelation());
            $this->assertSame($expected, self::slicedef($type, $params)->getRelation());
        }

        public function test_isMultiline_reads_the_widget_of_both_kinds_of_field(): void
        {
            $this->assertTrue(self::field('txt:4')->isMultiline());
            $this->assertFalse(self::field('fld:')->isMultiline());
            $this->assertTrue(self::slicedef('txt', ['rows' => '4'])->isMultiline());
            $this->assertFalse(self::slicedef('fld')->isMultiline());
        }

        public function test_disable_insert_as_new_reads_the_widget_of_both_kinds_of_field(): void
        {
            $this->assertTrue(self::field('mng:')->disable_insert_as_new());
            $this->assertFalse(self::field('fld:')->disable_insert_as_new());
            $this->assertTrue(self::slicedef('mng')->disable_insert_as_new());
            $this->assertFalse(self::slicedef('fld')->disable_insert_as_new());
        }

        public function test_a_field_without_widget_has_no_relation_and_no_special_editor(): void
        {
            $this->assertSame([], self::field('')->getRelation());
            $this->assertFalse(self::field('')->isMultiline());
            $this->assertFalse(self::field('')->disable_insert_as_new());
        }
    }
}
