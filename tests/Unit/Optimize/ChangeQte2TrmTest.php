<?php

declare(strict_types=1);

// AA_Field, its widget and the Trim inserter come from include/ - the constants config.php gives them

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

namespace Tests\Unit\Optimize {

    use AA\Optimize\ChangeQte2Trm;
    use AA_Field;
    use AA_Field_Slicedef;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeFields;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;

    /** Which text fields the optimizer switches from qte to Trim - the rules themselves: FieldTrimRefusalTest */
    final class ChangeQte2TrmTest extends TestCase
    {
        private const SLICE = '22222222222222222222222222222222';

        private static FakeSlice $slice;

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
            self::$slice = new FakeSlice(self::SLICE);   // isProtected() asks the slice for its password fields
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

        /** @param AA_Field[] $fields */
        private function inSlice(array $fields): FakeFields
        {
            self::$slice->setFields($fake = new FakeFields(self::SLICE, $fields));
            return $fake;
        }

        public function test_text_fields_are_the_content_text_with_the_insert_function(): void
        {
            $fields = $this->inSlice([
                $qte = $this->field('headline........'),
                $trm = $this->field('abstract........', ['input_insert_func' => 'TRM:']),
                $this->field('full_text.......', ['input_insert_func' => 'num:']),
                $this->field('number..........', ['text_stored' => 0]),
                $this->field('posted_by.......', ['in_item_tbl' => 'posted_by']),
                // defined in a field slice - set there
                new class (['id' => 'text...........1', 'text_stored' => 1, 'in_item_tbl' => '', 'input_insert_func' => 'trm']) extends AA_Field_Slicedef {
                    public function __construct(array $data)
                    {
                        $this->data = $data;
                    }
                },
            ]);

            $this->assertSame([$qte], ChangeQte2Trm::textFields($fields, 'qte'));
            $this->assertSame([$trm], ChangeQte2Trm::textFields($fields, 'trm'));
        }

        public function test_a_password_and_its_copy_are_left_alone(): void
        {
            $fields = $this->inSlice([
                $this->field('password........', ['input_insert_func' => 'pwd:text...........1']),
                $this->field('text...........1'),
                $this->field('password.......2'),
            ]);
            $this->assertSame([[], []], ChangeQte2Trm::fieldsToTrim($fields), 'neither the copy of the password nor a password field by its id');
        }

        public function test_fields_to_trim_lists_the_switched_fields_and_the_kept_ones_with_the_reason(): void
        {
            $fields = $this->inSlice([
                $headline = $this->field('headline........'),
                $code     = $this->field('full_text.......', ['input_show_func' => 'cod:']),
                $this->field('number..........', ['text_stored' => 0, 'input_insert_func' => 'num:']),
            ]);

            [$switch, $keep] = ChangeQte2Trm::fieldsToTrim($fields);

            $this->assertSame([$headline], $switch);
            $this->assertCount(1, $keep);
            $this->assertSame($code, $keep[0][0]);
        }
    }
}
