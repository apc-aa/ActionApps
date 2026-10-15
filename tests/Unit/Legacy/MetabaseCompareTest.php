<?php

declare(strict_types=1);

/**
 * AA_Metabase::compare() decides whether UpdateDbStructure copies a whole table into a new one.
 * The order of the indexes means nothing, but an index dropped and added again goes last on the
 * live table - that alone must not make the content table look different.
 */

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

    use AA_MbT;
    use AA_Metabase;
    use PHPUnit\Framework\TestCase;

    final class MetabaseCompareTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        private const TEXT   = ['t' => 'content', 'n' => 'text', 's' => 'I', 'c' => [1 => ['text', '12'], 2 => 'field_id']];
        private const ITEM   = ['t' => 'content', 'n' => 'item_id', 's' => 'I', 'c' => [1 => 'item_id', 2 => 'field_id']];
        private const NUMBER = ['t' => 'content', 'n' => 'number', 's' => 'I', 'c' => [1 => 'item_id', 2 => 'number']];

        private static function metabase(array $indexes, string $text_type = 'longtext'): AA_Metabase
        {
            $metabase = new AA_Metabase();
            $metabase->tables['content'] = AA_MbT::fromArray([
                't' => 'content',
                'k' => [],
                'i' => $indexes,
                'c' => [
                    'item_id'  => ['item_id', 'varbinary(16)', false, 'MUL', '                ', '', ''],
                    'field_id' => ['field_id', 'varbinary(16)', false, '', '                ', '', ''],
                    'number'   => ['number', 'bigint(20)', true, '', null, '', ''],
                    'text'     => ['text', $text_type, true, 'MUL', null, '', ''],
                ],
                'f' => null,
            ]);
            return $metabase;
        }

        public function test_compare_indexesInAnotherOrder_equal(): void
        {
            $template = self::metabase(['text' => self::TEXT, 'item_id' => self::ITEM, 'number' => self::NUMBER]);
            $live     = self::metabase(['item_id' => self::ITEM, 'number' => self::NUMBER, 'text' => self::TEXT]);

            self::assertTrue($template->compare($live)['content']['equal']);
        }

        public function test_compare_differentIndex_notEqual(): void
        {
            $template = self::metabase(['text' => self::TEXT, 'item_id' => self::ITEM]);
            $live     = self::metabase(['text' => ['c' => [1 => ['text', '12']]] + self::TEXT, 'item_id' => self::ITEM]);

            self::assertFalse($template->compare($live)['content']['equal']);
        }

        public function test_compare_differentColumn_notEqual(): void
        {
            $template = self::metabase(['text' => self::TEXT]);
            $live     = self::metabase(['text' => self::TEXT], 'mediumtext');

            self::assertFalse($template->compare($live)['content']['equal']);
        }
    }
}
