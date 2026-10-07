<?php

declare(strict_types=1);

/**
 * The insert function of a field defined in a field slice (AA_Field_Slicedef) that sets none: a file
 * uploads, text trims - unless Trim would break the field (AA_Field::trimRefusal()). The constants of a
 * group are not read there, as the fields are built on every request.
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
    use AA_Field_Slicedef;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    final class FieldSlicedefInsertTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        /** the field as the constructor leaves it, without the item of the field slice */
        /** @param string $set _#FLD_INS_ of the field slice, '' - none */
        private static function insert(string $type, array $params = [], string $validate = '', int $text_stored = 1, string $set = ''): string
        {
            $field = new class (['input_show_func' => '', 'input_validate' => $validate, 'text_stored' => $text_stored], $params + ['class' => Widget::constructClassName($type)]) extends AA_Field_Slicedef {
                public function __construct(array $data, array $params)
                {
                    $this->data          = $data;
                    $this->widget_params = $params;
                }

                public function insert(string $type, string $set = ''): string
                {
                    return $this->insertFunction($set, $type);
                }
            };
            return $field->insert($type, $set);
        }

        public static function fields(): array
        {
            return [
                'text'                          => ['trm', 'fld'],
                'text area'                     => ['trm', 'txt', ['rows' => '2']],
                'file'                          => ['fil', 'fil'],
                'file, upper case'              => ['fil', 'FIL'],
                'password'                      => ['qte', 'pwd'],
                '2FA'                           => ['qte', 'tfa'],
                'code'                          => ['qte', 'cod'],
                'number'                        => ['qte', 'fld', [], '', 0],
                'unique'                        => ['qte', 'fld', [], 'unique:text...........1:1'],
                'e-mail unique'                 => ['qte', 'fld', [], 'e-unique'],
                'e-mail'                        => ['trm', 'fld', [], 'email'],
                'clean constants'               => ['trm', 'rio', ['const_arr' => ['ANO' => 'ANO', 'NE' => 'NE']]],
                'constant with a new line'      => ['qte', 'rio', ['const_arr' => ["ANO\r" => 'ANO', 'NE' => 'NE']]],
                'constant group - not read'     => ['qte', 'sel', ['const' => 'lt_languages']],
                'related item ids'              => ['trm', 'sel', ['const' => '#sLiCe-22222222222222222222222222222222']],
                'text of the related items'     => ['qte', 'sel', ['const' => '#sLiCe-22222222222222222222222222222222', 'use_name' => '1']],
                // the widget offers the values array before the constants
                'group and clean values array'  => ['trm', 'sel', ['const' => 'lt_languages', 'const_arr' => ['ANO' => 'ANO']]],
                'related and spaced values'     => ['qte', 'sel', ['const' => '#sLiCe-22222222222222222222222222222222', 'const_arr' => ["ANO\r" => 'ANO']]],
            ];
        }

        #[DataProvider('fields')]
        public function test_a_field_slice_field_without_insert_function_trims_where_it_breaks_nothing(string $expected, string $type, array $params = [], string $validate = '', int $text_stored = 1): void
        {
            $this->assertSame($expected, self::insert($type, $params, $validate, $text_stored));
        }

        public function test_the_insert_function_the_field_slice_sets_is_kept(): void
        {
            $this->assertSame('qte', self::insert('fld', [], '', 1, 'qte'));
            $this->assertSame('boo', self::insert('chb', [], '', 1, 'boo'));
        }
    }
}
