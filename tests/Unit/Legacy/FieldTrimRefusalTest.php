<?php

declare(strict_types=1);

/**
 * AA_Field::trimRefusal() (include/field.class.php) - where the Trim insert function would break a field:
 * a unique value (the form checks it before Trim), code, constants with spaces around, the text of related
 * items. The constants of a group come from the database, so the values array of the widget (const_arr)
 * stands for them here.
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

    use AA_Field;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    final class FieldTrimRefusalTest extends TestCase
    {
        private const SLICE = '22222222222222222222222222222222';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        private function field(string $id, array $data = []): AA_Field
        {
            return new AA_Field($data + [
                'id'                => $id,
                'slice_id'          => pack_id(self::SLICE),
                'text_stored'       => 1,
                'in_item_tbl'       => '',
                'input_show_func'   => 'fld:',
                'input_insert_func' => 'qte:',
                'input_validate'    => 'text:',
                'content_edit'      => 0,
            ]);
        }

        public function test_a_plain_text_field_may_trim(): void
        {
            $this->assertSame('', $this->field('headline........')->trimRefusal());
        }

        public static function unique(): array
        {
            return [
                'unique in the slice' => ['unique:headline........:1'],
                'unique username'     => ['unique'],
                'unique e-mail'       => ['e-unique'],
            ];
        }

        /** the form checks the value before Trim - a value with a space would pass next to the trimmed one */
        #[DataProvider('unique')]
        public function test_a_unique_field_is_refused(string $validate): void
        {
            $this->assertStringContainsString('unique', $this->field('headline........', ['input_validate' => $validate])->trimRefusal());
        }

        public function test_a_code_field_is_refused(): void
        {
            $this->assertStringContainsString('code', $this->field('full_text.......', ['input_show_func' => 'cod:8'])->trimRefusal());
        }

        public function test_constants_with_spaces_around_are_refused(): void
        {
            $this->assertStringContainsString('Praha', $this->field('category........', ['input_show_func' => 'sel::::::::{"Praha\\r"#:"Praha"}'])->trimRefusal());
            $this->assertSame('', $this->field('category.......1', ['input_show_func' => 'sel::::::::{"Praha"#:"Praha"}'])->trimRefusal());
        }

        public function test_related_item_ids_may_trim_but_their_text_is_refused(): void
        {
            $this->assertSame('', $this->field('relation........', ['input_show_func' => 'sel:#sLiCe-' . self::SLICE . ':'])->trimRefusal());
            $this->assertStringContainsString('text', $this->field('relation.......1', ['input_show_func' => 'sel:#sLiCe-' . self::SLICE . ':headline........:1'])->trimRefusal());
        }
    }
}
