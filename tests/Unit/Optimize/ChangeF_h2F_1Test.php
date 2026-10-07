<?php

declare(strict_types=1);

// AA_Field and AA_Item come from include/ - the constants config.php gives them

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

    use AA\IO\DB\DB_AA;
    use AA\Optimize\ChangeF_h2F_1;
    use AA\Optimize\ChangeF_t2F_1;
    use AA_Field;
    use AA_Item;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;
    use ReflectionClassConstant;

    /** The content lookup itself needs the database - the double answers it and counts the calls */
    final class ChangeF_h2F_1Double extends ChangeF_h2F_1
    {
        public int $lookups = 0;

        public function __construct(private bool $plain_value_changes = false) {}

        public function printsAsIsPublic(AA_Field $fld): bool
        {
            return $this->printsAsIs($fld);
        }

        protected function hasPlainValueText2HtmlChanges(AA_Field $fld): bool
        {
            ++$this->lookups;
            return $this->plain_value_changes;
        }
    }

    /** When f_1 may replace f_h: / f_t: without parameter - only where every stored value prints the same */
    final class ChangeF_h2F_1Test extends TestCase
    {
        private const SLICE = '22222222222222222222222222222222';

        private function field(array $data = []): AA_Field
        {
            return new AA_Field($data + [
                'id'                => 'headline........',
                'slice_id'          => pack_id(self::SLICE),
                'text_stored'       => 1,
                'in_item_tbl'       => '',
                'html_default'      => 1,
                'html_show'         => 0,
                'multiple'          => 0,
                'input_show_func'   => 'fld:',
                'input_insert_func' => 'qte:',
                'input_validate'    => 'text:',
                'content_edit'      => 0,
            ]);
        }

        private static function text2Html(string $txt, int $flag): string
        {
            return (new ReflectionClass(AA_Item::class))->newInstanceWithoutConstructor()->text2Html($txt, $flag);
        }

        /** @return array<string, array{string}> */
        public static function changedChars(): array
        {
            return array_combine(array_map('json_encode', AA_Item::TEXT2HTML_CHANGES), array_map(fn($c) => [$c], AA_Item::TEXT2HTML_CHANGES));
        }

        #[DataProvider('changedChars')]
        public function test_text2Html_changes_each_listed_char_of_a_plain_value(string $char): void
        {
            $this->assertNotSame("a{$char}b", self::text2Html("a{$char}b", 0));
        }

        public function test_text2Html_keeps_a_plain_value_without_the_listed_chars(): void
        {
            $others = implode('', array_diff(array_map('chr', range(32, 126)), AA_Item::TEXT2HTML_CHANGES)) . "\tŽluťoučký kůň – €";
            $this->assertSame($others, self::text2Html($others, 0));
        }

        public function test_item_table_field_prints_as_is_without_lookup(): void
        {
            $optimize = new ChangeF_h2F_1Double(true);
            $this->assertTrue($optimize->printsAsIsPublic($this->field(['id' => 'posted_by.......', 'in_item_tbl' => 'posted_by', 'html_default' => 0])));
            $this->assertSame(0, $optimize->lookups);
        }

        /** @return array<string, array{array<string, int>}> */
        public static function contentDecides(): array
        {
            return [
                'html only'                   => [[]],
                'html only with more values'  => [['multiple' => 1]],
                'number (older text rows)'    => [['text_stored' => 0, 'html_default' => 0, 'html_show' => 1]],
            ];
        }

        /** @param array<string, int> $data */
        #[DataProvider('contentDecides')]
        public function test_the_content_decides(array $data): void
        {
            $this->assertTrue((new ChangeF_h2F_1Double(false))->printsAsIsPublic($this->field($data)));
            $this->assertFalse((new ChangeF_h2F_1Double(true))->printsAsIsPublic($this->field($data)));
        }

        /** @return array<string, array{array<string, int>}> */
        public static function notHtmlOnly(): array
        {
            return [
                'plain by default'     => [['html_default' => 0]],
                'editor may switch'    => [['html_show' => 1]],
                'multilingual'         => [['multiple' => 2]],
            ];
        }

        /** @param array<string, int> $data */
        #[DataProvider('notHtmlOnly')]
        public function test_a_field_whose_values_may_be_plain_stays(array $data): void
        {
            $optimize = new ChangeF_h2F_1Double(false);
            $this->assertFalse($optimize->printsAsIsPublic($this->field($data)));
            $this->assertSame(0, $optimize->lookups);
        }

        public function test_the_lookup_finds_plain_values_text2Html_changes_in_this_field(): void
        {
            $this->assertSame(
                "WHERE content.item_id = item.id AND item.slice_id = 0x" . self::SLICE . " AND content.field_id = 'headline........' AND ((COALESCE(content.flag, 0) & 1) = 0) AND "
                . "(content.text LIKE '%&%' OR content.text LIKE '%<%' OR content.text LIKE '%>%' OR content.text LIKE '%\\\"%' OR content.text LIKE '%\\'%' OR content.text LIKE '%\n%' OR content.text LIKE '%\r%')",
                DB_AA::makeWhere(ChangeF_h2F_1::plainValueText2HtmlChangesWhere($this->field())),
            );
        }

        public function test_each_optimization_replaces_its_own_function(): void
        {
            $this->assertSame('f_h:', (new ReflectionClassConstant(ChangeF_h2F_1::class, 'FNC'))->getValue());
            $this->assertSame('f_t:', (new ReflectionClassConstant(ChangeF_t2F_1::class, 'FNC'))->getValue());
        }

        public function test_the_content_of_a_field_is_looked_up_once(): void
        {
            $optimize = new ChangeF_h2F_1Double(false);
            $optimize->printsAsIsPublic($this->field());
            $optimize->printsAsIsPublic($this->field());
            $optimize->printsAsIsPublic($this->field(['id' => 'abstract........']));
            $this->assertSame(2, $optimize->lookups);
        }
    }
}
