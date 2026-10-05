<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\Convertlatin2Utf8Mb4;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** a table left out keeps its 1Byte texts - characters outside the charset are saved as '?' */
final class Convertlatin2Utf8Mb4Test extends TestCase
{
    private const CHARSETS = ['latin1', 'latin2', 'cp1250'];

    /** @return array<string, ?string> */
    private static function column(string $table, string $name, string $type = 'mediumtext', string $collation = 'latin1_swedish_ci', string $nullable = 'YES', ?string $default = null, string $comment = ''): array
    {
        return ['TABLE_NAME' => $table, 'COLUMN_NAME' => $name, 'COLUMN_TYPE' => $type, 'IS_NULLABLE' => $nullable, 'COLUMN_DEFAULT' => $default, 'COLUMN_COMMENT' => $comment, 'COLLATION_NAME' => $collation];
    }

    /** @return array<string, array{array<string, string>, array<int, array<string, ?string>>, string[]}> */
    public static function tablesProvider(): array
    {
        return [
            'latin1 table without texts'  => [['hit_archive' => 'latin1_swedish_ci'], [], ['hit_archive']],
            'cp1250 table'                => [['slice' => 'cp1250_general_ci'], [self::column('slice', 'name', 'varchar(100)', 'cp1250_general_ci')], ['slice']],
            'latin1 column in utf8 table' => [['content' => 'utf8mb4_general_ci'], [self::column('content', 'text', 'longtext')], ['content']],
            'utf8, ascii and binary'      => [['view' => 'utf8mb4_czech_ci', 'a' => 'ascii_general_ci', 'b' => 'binary'], [], []],
            'backup table'                => [['bck_item' => 'latin1_swedish_ci'], [self::column('bck_item', 'flags')], []],
        ];
    }

    /**
     * @param array<string, string>              $tables
     * @param array<int, array<string, ?string>> $columns
     * @param string[]                           $expected
     */
    #[DataProvider('tablesProvider')]
    public function test_alterSqls_convertsTablesWithAny1ByteText(array $tables, array $columns, array $expected): void
    {
        self::assertSame($expected, array_keys(Convertlatin2Utf8Mb4::alterSqls($tables, $columns, self::CHARSETS, 'utf8mb4_general_ci')));
    }

    /** CONVERT TO CHARACTER SET would widen the types; MODIFY keeps type, NULL, default and comment */
    public function test_alterSqls_keepsColumnDefinitions(): void
    {
        $columns = [
            self::column('field', 'input_help'),
            self::column('field', 'name', 'varchar(255)', 'cp1250_general_ci', 'NO', "''", "it's shown"),
            self::column('field', 'code', 'char(10)', 'latin1_bin', 'NO', 'abc'),
        ];
        $sqls = Convertlatin2Utf8Mb4::alterSqls(['field' => 'cp1250_general_ci'], $columns, self::CHARSETS, 'utf8mb4_general_ci');

        self::assertSame(
            'ALTER TABLE `field` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
            . ', MODIFY `input_help` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL'
            . ", MODIFY `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT 'it\\'s shown'"
            . ", MODIFY `code` char(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'abc'",
            $sqls['field'],
        );
    }

    /** @return array<string, array{array<string, string>, string, string}> */
    public static function collationProvider(): array
    {
        return [
            'utf8mb4 tables decide'  => [['a' => 'utf8mb4_czech_ci', 'c' => 'utf8mb4_bin', 'c2' => 'utf8mb4_bin', 'bck_a' => 'utf8mb4_unicode_ci', 'bck_b' => 'utf8mb4_unicode_ci', 'd' => 'latin1_swedish_ci'], 'utf8mb4_general_ci', 'utf8mb4_czech_ci'],
            'then database default'  => [['d' => 'latin1_swedish_ci', 'e' => 'utf8mb3_general_ci'], 'utf8mb4_general_ci', 'utf8mb4_general_ci'],
            'else unicode'           => [['d' => 'latin1_swedish_ci'], 'latin1_swedish_ci', 'utf8mb4_unicode_ci'],
        ];
    }

    /** @param array<string, string> $tables */
    #[DataProvider('collationProvider')]
    public function test_targetCollation_picksOneForAllTables(array $tables, string $db_collation, string $expected): void
    {
        self::assertSame($expected, Convertlatin2Utf8Mb4::targetCollation($tables, $db_collation));
    }
}
