<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\Convert2utf8mb4;
use AA\Optimize\Convertlatin2Utf8Mb4;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** a table left out keeps texts that utf8mb4 would hold - characters outside its charset are saved as '?' */
final class Convert2utf8mb4Test extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../include/mgettext.php';
    }

    private const ONE_BYTE = ['latin1', 'latin2', 'cp1250'];
    private const UTF8MB3 = ['utf8mb3', 'utf8'];

    /** @return array<string, ?string> */
    private static function table(string $collation, string $engine = 'InnoDB', string $row_format = 'Dynamic'): array
    {
        return ['TABLE_COLLATION' => $collation, 'ENGINE' => $engine, 'ROW_FORMAT' => $row_format];
    }

    /** @return array<string, ?string> */
    private static function column(string $table, string $name, string $type = 'mediumtext', string $collation = 'latin1_swedish_ci', string $nullable = 'YES', ?string $default = null, string $comment = ''): array
    {
        return ['TABLE_NAME' => $table, 'COLUMN_NAME' => $name, 'COLUMN_TYPE' => $type, 'IS_NULLABLE' => $nullable, 'COLUMN_DEFAULT' => $default, 'COLUMN_COMMENT' => $comment, 'COLLATION_NAME' => $collation];
    }

    /** @return array<string, array{array<string, array<string, ?string>>, array<int, array<string, ?string>>, string[], string[]}> */
    public static function tablesProvider(): array
    {
        return [
            'latin1 table without texts'  => [['hit_archive' => self::table('latin1_swedish_ci')], [], self::ONE_BYTE, ['hit_archive']],
            'cp1250 table'                => [['slice' => self::table('cp1250_general_ci')], [self::column('slice', 'name', 'varchar(100)', 'cp1250_general_ci')], self::ONE_BYTE, ['slice']],
            'latin1 column in utf8 table' => [['content' => self::table('utf8mb4_general_ci')], [self::column('content', 'text', 'longtext')], self::ONE_BYTE, ['content']],
            'utf8mb4 and binary'          => [['view' => self::table('utf8mb4_czech_ci'), 'b' => self::table('binary')], [], self::ONE_BYTE, []],
            'backup table'                => [['bck_item' => self::table('latin1_swedish_ci')], [self::column('bck_item', 'flags')], self::ONE_BYTE, []],
            'utf8mb3 tables'              => [['item' => self::table('utf8mb3_czech_ci'), 'old' => self::table('utf8_general_ci'), 'slice' => self::table('latin1_swedish_ci')], [], self::UTF8MB3, ['item', 'old']],
        ];
    }

    /**
     * @param array<string, array<string, ?string>> $tables
     * @param array<int, array<string, ?string>>    $columns
     * @param string[]                              $charsets
     * @param string[]                              $expected
     */
    #[DataProvider('tablesProvider')]
    public function test_alterSqls_convertsTablesWithAnyTextInTheCharsets(array $tables, array $columns, array $charsets, array $expected): void
    {
        self::assertSame($expected, array_keys(Convert2utf8mb4::alterSqls($tables, $columns, $charsets, 'utf8mb4_general_ci')));
    }

    /** CONVERT TO CHARACTER SET would widen the types; MODIFY keeps type, NULL, default and comment */
    public function test_alterSqls_keepsColumnDefinitions(): void
    {
        $columns = [
            self::column('field', 'input_help'),
            self::column('field', 'name', 'varchar(255)', 'cp1250_general_ci', 'NO', "''", "it's shown"),
            self::column('field', 'code', 'char(10)', 'latin1_bin', 'NO', 'abc'),
        ];
        $sqls = Convert2utf8mb4::alterSqls(['field' => self::table('cp1250_general_ci')], $columns, self::ONE_BYTE, 'utf8mb4_general_ci');

        self::assertSame(
            'ALTER TABLE `field` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
            . ', MODIFY `input_help` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL'
            . ", MODIFY `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT 'it\\'s shown'"
            . ", MODIFY `code` char(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'abc'",
            $sqls['field'],
        );
    }

    /** utf8mb4 keys of varchar(255) do not fit the 767 bytes of COMPACT rows */
    public function test_alterSqls_switchesCompactRowsToDynamic(): void
    {
        $tables = ['users' => self::table('utf8mb3_general_ci', 'InnoDB', 'Compact'), 'log' => self::table('utf8mb3_general_ci', 'MyISAM', 'Dynamic')];
        $sqls = Convert2utf8mb4::alterSqls($tables, [self::column('users', 'name', 'varchar(255)', 'utf8mb3_general_ci', 'NO')], self::UTF8MB3, 'utf8mb4_czech_ci');

        self::assertSame('ALTER TABLE `users` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci, ROW_FORMAT=DYNAMIC, MODIFY `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci NOT NULL', $sqls['users']);
        self::assertSame('ALTER TABLE `log` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci', $sqls['log']);
    }

    /** @return array<string, array{array<string, string>, string, string}> */
    public static function collationProvider(): array
    {
        return [
            'utf8mb4 tables decide'  => [['a' => 'utf8mb4_czech_ci', 'c' => 'utf8mb4_bin', 'c2' => 'utf8mb4_bin', 'bck_a' => 'utf8mb4_unicode_ci', 'bck_b' => 'utf8mb4_unicode_ci', 'd' => 'latin1_swedish_ci'], 'utf8mb4_general_ci', 'utf8mb4_czech_ci'],
            'then database default'  => [['d' => 'latin1_swedish_ci', 'e' => 'utf8mb3_general_ci'], 'utf8mb4_general_ci', 'utf8mb4_general_ci'],
            'else the fallback'      => [['d' => 'latin1_swedish_ci'], 'latin1_swedish_ci', 'utf8mb4_unicode_ci'],
        ];
    }

    /** @param array<string, string> $collations */
    #[DataProvider('collationProvider')]
    public function test_targetCollation_picksOneForAllTables(array $collations, string $db_collation, string $expected): void
    {
        self::assertSame($expected, Convert2utf8mb4::targetCollation($collations, $db_collation, 'utf8mb4_unicode_ci'));
    }

    /** an utf8mb3 database keeps the sorting of its tables, e.g. Czech */
    public function test_fallbackCollation_mapsTheMostUsedUtf8mb3Collation(): void
    {
        $optimize = new class extends Convert2utf8mb4 {
            /** @param array<string, string> $collations */
            public function fallback(array $collations): string
            {
                return $this->fallbackCollation($collations);
            }
        };

        self::assertSame('utf8mb4_czech_ci', $optimize->fallback(['a' => 'utf8mb3_general_ci', 'b' => 'utf8mb3_czech_ci', 'c' => 'utf8mb3_czech_ci', 'd' => 'latin1_swedish_ci']));
        self::assertSame('utf8mb4_general_ci', $optimize->fallback(['a' => 'utf8_general_ci']));
        self::assertSame('utf8mb4_unicode_ci', $optimize->fallback(['d' => 'latin1_swedish_ci']));
    }
    /** @return array<string, array{string, string}> */
    public static function defaultProvider(): array
    {
        return [
            'MariaDB literal'        => ["'it''s'", " DEFAULT 'it''s'"],
            'MariaDB NULL'           => ['NULL', ' DEFAULT NULL'],
            'MySQL bare value'       => ['abc', " DEFAULT 'abc'"],
            'MySQL unbalanced quote' => ["'abc", " DEFAULT '\\'abc'"],
        ];
    }

    #[DataProvider('defaultProvider')]
    public function test_alterSqls_writesDefaultsAsSql(string $default, string $expected): void
    {
        $sqls = Convert2utf8mb4::alterSqls(['t' => self::table('latin1_swedish_ci')], [self::column('t', 'c', 'char(10)', 'latin1_swedish_ci', 'NO', $default)], self::ONE_BYTE, 'utf8mb4_general_ci');

        self::assertStringEndsWith('NOT NULL' . $expected, $sqls['t']);
    }

    /** @return array<string, array{string, array<string, array<string, ?string>>, string, bool}> */
    public static function checkProvider(): array
    {
        return [
            'converted'               => [Convert2utf8mb4::class, ['item' => self::table('utf8mb4_general_ci')], 'utf8mb4_general_ci', true],
            'utf8mb3 table'           => [Convert2utf8mb4::class, ['item' => self::table('utf8mb3_general_ci')], 'utf8mb4_general_ci', false],
            'database default'        => [Convert2utf8mb4::class, ['item' => self::table('utf8mb4_general_ci')], 'utf8mb3_general_ci', false],
            'latin1 table for 1Byte'  => [Convertlatin2Utf8Mb4::class, ['item' => self::table('latin1_swedish_ci')], 'utf8mb4_general_ci', false],
        ];
    }

    /**
     * State Check shows the tables to convert
     * @param class-string<Convert2utf8mb4>          $class
     * @param array<string, array<string, ?string>> $tables
     */
    #[DataProvider('checkProvider')]
    public function test_test_failsWhileSomethingIsToConvert(string $class, array $tables, string $db_collation, bool $expected): void
    {
        self::assertSame($expected, self::fake($class, $tables, $db_collation, 'utf8mb4')->test());
    }

    /** @return array<string, array{string, array<string, array<string, ?string>>, string, bool, int}> */
    public static function repairProvider(): array
    {
        return [
            'utf8mb3 over latin1 connection' => [Convert2utf8mb4::class, ['item' => self::table('utf8mb3_general_ci')], 'latin1', true, 2],
            '1Byte over latin1 connection'   => [Convertlatin2Utf8Mb4::class, ['item' => self::table('latin1_swedish_ci')], 'latin1', false, 0],
            '1Byte over utf8 connection'     => [Convertlatin2Utf8Mb4::class, ['item' => self::table('latin1_swedish_ci')], 'utf8', true, 2],
            'MyISAM table'                   => [Convertlatin2Utf8Mb4::class, ['item' => self::table('latin1_swedish_ci', 'MyISAM')], 'utf8mb4', false, 0],
            'MyISAM backup only'             => [Convertlatin2Utf8Mb4::class, ['item' => self::table('latin1_swedish_ci'), 'bck_item' => self::table('latin1_swedish_ci', 'MyISAM')], 'utf8mb4', true, 2],
        ];
    }

    /**
     * the conversion must not stop in the middle - it does not start when a table cannot be converted
     * @param class-string<Convert2utf8mb4>          $class
     * @param array<string, array<string, ?string>> $tables
     */
    #[DataProvider('repairProvider')]
    public function test_repair_runsOnlyWhenAllTablesCanBeConverted(string $class, array $tables, string $connection, bool $expected, int $queries): void
    {
        $optimize = self::fake($class, $tables, 'latin1_swedish_ci', $connection);

        self::assertSame($expected, $optimize->repair());
        self::assertCount($queries, $optimize->queries);
        if ($queries) {
            self::assertSame('ALTER DATABASE `aadb` CHARACTER SET = utf8mb4 COLLATE = ' . (($class === Convert2utf8mb4::class) ? 'utf8mb4_general_ci' : 'utf8mb4_unicode_ci'), end($optimize->queries));
        }
    }

    /**
     * @param class-string<Convert2utf8mb4>          $class
     * @param array<string, array<string, ?string>> $tables
     */
    private static function fake(string $class, array $tables, string $db_collation, string $connection): Convert2utf8mb4Fake|Convertlatin2Utf8Mb4Fake
    {
        $optimize = ($class === Convert2utf8mb4::class) ? new Convert2utf8mb4Fake() : new Convertlatin2Utf8Mb4Fake();
        $optimize->fake($tables, $db_collation, $connection, ($class === Convert2utf8mb4::class) ? self::UTF8MB3 : self::ONE_BYTE);
        return $optimize;
    }
}

/** information_schema and the database replaced by the test's data */
trait FakeDb
{
    /** @var string[] */
    public array $queries = [];
    /** @var array<string, array<string, ?string>> */
    private array $fake_tables = [];
    /** @var string[] */
    private array $fake_charsets = [];
    private string $fake_db_collation = '';
    private string $fake_connection = '';

    /**
     * @param array<string, array<string, ?string>> $tables
     * @param string[]                              $charsets
     */
    public function fake(array $tables, string $db_collation, string $connection, array $charsets): void
    {
        [$this->fake_tables, $this->fake_db_collation, $this->fake_connection, $this->fake_charsets] = [$tables, $db_collation, $connection, $charsets];
    }

    protected function plan(): array
    {
        $collations = array_map(static fn($table) => (string) $table['TABLE_COLLATION'], $this->fake_tables);
        $collation = self::targetCollation($collations, $this->fake_db_collation, $this->fallbackCollation($collations));
        return ['tables' => $this->fake_tables, 'sqls' => self::alterSqls($this->fake_tables, [], $this->fake_charsets, $collation), 'collation' => $collation, 'db_collation' => $this->fake_db_collation];
    }

    protected function connectionCharset(): string
    {
        return $this->fake_connection;
    }

    protected function schema(): string
    {
        return 'aadb';
    }

    public function query($SQL): bool
    {
        $this->queries[] = $SQL;
        return true;
    }
}

final class Convert2utf8mb4Fake extends Convert2utf8mb4
{
    use FakeDb;
}

final class Convertlatin2Utf8Mb4Fake extends Convertlatin2Utf8Mb4
{
    use FakeDb;
}
