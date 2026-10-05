<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;

/**
 *
 */
class Convertlatin2Utf8Mb4 extends \AA\Optimize\Convert2utf8mb4
{
    /** Name function
     * @return string - a name
     */
    public static function name(): string
    {
        return _m("Convert database from 1Byte encoding (latin1, cp1250, ...) to utf8mb4 collation");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string
    {
        return _m("Convert all tables with 1Byte encoded texts (latin1, cp1250, ...) to utf8mb4 and set database default to utf8mb4. Texts keep the characters AA reads from them now.");
    }

    /** Test function
     * @return bool
     */
    public function test(): bool
    {
        $this->connectionIsUtf8();
        $tables = $this->loadTables();
        $charsets = $this->loadCharsets();
        $sqls = self::alterSqls($tables, $this->loadColumns($charsets), $charsets, self::targetCollation($tables, $this->dbCollation()));
        foreach (array_keys($sqls) as $tablename) {
            $this->message(_m('collation for %1 table is %2', [$tablename, $tables[$tablename]]));
        }
        $this->message(_m("We found %1 tables which need to be converted", [count($sqls)]));
        return true;
    }

    /**
     */
    public function repair(): bool
    {
        if (!$this->connectionIsUtf8()) {
            return false;
        }
        $tables = $this->loadTables();
        $db_collation = $this->dbCollation();
        $new_collation = self::targetCollation($tables, $db_collation);
        $charsets = $this->loadCharsets();
        $sqls = self::alterSqls($tables, $this->loadColumns($charsets), $charsets, $new_collation);
        if (!$sqls && str_starts_with($db_collation, 'utf8mb4_')) {
            $this->message(_m("We found %1 tables which need to be converted", [0]));
            return true;
        }
        foreach ($sqls as $tablename => $sql) {
            $this->message(_m('Convert table %1 (%2) to %3', [$tablename, $tables[$tablename], $new_collation]));
            $this->query($sql);
        }
        $this->query("ALTER DATABASE " . self::identifier((string) constant('DB_NAME')) . " CHARACTER SET = utf8mb4 COLLATE = $new_collation");
        $this->message(_m('Database default set to %1', [$new_collation]));
        if (constant('DB_CHARACTER_SET') !== 'utf8mb4') {
            $this->message(_m('Please do not forget to change DB_CHARACTER_SET to "utf8mb4" and DB_COLLATION_CONNECTION to "%1" in your config.php', [$new_collation]));
        }
        return true;
    }

    /** ALTER converts the bytes as the column charset says. AA reads the same characters as before
     *  only when it reads them converted to utf8 already - over a 1Byte connection the bytes may be
     *  in the encoding of the slice (cp1250 in a latin1 column) and would end up garbled. */
    protected function connectionIsUtf8(): bool
    {
        $charset = defined('DB_CHARACTER_SET') ? (string) constant('DB_CHARACTER_SET') : '';
        if (str_starts_with($charset, 'utf8')) {
            return true;
        }
        $this->message(_m('DB_CHARACTER_SET in config.php is "%1". Switch it to "utf8mb4" first and check the texts in AA look right - the conversion keeps what AA shows over the utf8mb4 connection.', [$charset]));
        return false;
    }

    protected function dbCollation(): string
    {
        return (string) DB_AA::select1('DEFAULT_COLLATION_NAME', 'SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA', [['SCHEMA_NAME', (string) constant('DB_NAME')]]);
    }

    /** @return array<string, string> [table_name => table_collation] */
    protected function loadTables(): array
    {
        return DB_AA::select(['TABLE_NAME' => 'TABLE_COLLATION'], 'SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES', [['TABLE_SCHEMA', (string) constant('DB_NAME')], ['TABLE_TYPE', 'BASE TABLE']]);
    }

    /** @return string[] 1Byte charsets to convert - ascii needs no conversion, binary holds bytes, not texts */
    protected function loadCharsets(): array
    {
        return array_values(array_diff(DB_AA::select('', 'SELECT CHARACTER_SET_NAME FROM information_schema.CHARACTER_SETS', [['MAXLEN', 1, 'i']]), ['ascii', 'binary']));
    }

    /**
     * @param string[] $charsets
     * @return array<int, array<string, ?string>> rows of information_schema.COLUMNS in the charsets
     */
    protected function loadColumns(array $charsets): array
    {
        return DB_AA::select([], 'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT, COLLATION_NAME FROM information_schema.COLUMNS', [['TABLE_SCHEMA', (string) constant('DB_NAME')], ['CHARACTER_SET_NAME', $charsets]]);
    }

    /** One collation for all the tables - the database refuses to compare columns of two different
     *  utf8mb4 collations ("Illegal mix of collations"), e.g. in a join of content with constant.
     *  The tables already in utf8mb4 decide (not backups, not case sensitive _bin), then the database default.
     * @param array<string, string> $tables [table_name => table_collation]
     */
    public static function targetCollation(array $tables, string $db_collation): string
    {
        $utf8mb4 = array_count_values(array_filter(
            $tables,
            static fn($collation, $tablename) => str_starts_with((string) $collation, 'utf8mb4_') && $collation !== 'utf8mb4_bin' && !str_starts_with((string) $tablename, 'bck_'),
            ARRAY_FILTER_USE_BOTH,
        ));
        arsort($utf8mb4);
        return (string) (array_key_first($utf8mb4) ?? (str_starts_with($db_collation, 'utf8mb4_') ? $db_collation : 'utf8mb4_unicode_ci'));
    }

    /** ALTER for each table with a 1Byte default or text column; backup tables bck_* stay as they are.
     *  MODIFY with the column type as it is - CONVERT TO CHARACTER SET would widen TEXT to MEDIUMTEXT
     *  and MEDIUMTEXT to LONGTEXT, so the tables would no longer match the AA schema.
     * @param array<string, string>               $tables  [table_name => table_collation]
     * @param array<int, array<string, ?string>>  $columns 1Byte rows of information_schema.COLUMNS
     * @param string[]                            $charsets 1Byte charsets to convert
     * @return array<string, string> [table_name => ALTER TABLE ...]
     */
    public static function alterSqls(array $tables, array $columns, array $charsets, string $collation): array
    {
        $modify = [];
        foreach ($columns as $col) {
            // a case sensitive column stays case sensitive
            $col_collation = preg_match('/_(bin|cs)$/', (string) $col['COLLATION_NAME']) ? 'utf8mb4_bin' : $collation;
            $modify[$col['TABLE_NAME']][] = 'MODIFY ' . self::identifier((string) $col['COLUMN_NAME']) . " {$col['COLUMN_TYPE']} CHARACTER SET utf8mb4 COLLATE $col_collation"
                . ($col['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL')
                . self::defaultClause($col['COLUMN_DEFAULT'])
                . (((string) $col['COLUMN_COMMENT'] !== '') ? ' COMMENT ' . qquote($col['COLUMN_COMMENT']) : '');
        }
        $ret = [];
        foreach ($tables as $tablename => $table_collation) {
            if (str_starts_with($tablename, 'bck_') || (!isset($modify[$tablename]) && !in_array(strtok((string) $table_collation, '_'), $charsets, true))) {
                continue;
            }
            $ret[$tablename] = implode(', ', array_merge(['ALTER TABLE ' . self::identifier($tablename) . " DEFAULT CHARACTER SET utf8mb4 COLLATE $collation"], $modify[$tablename] ?? []));
        }
        return $ret;
    }

    /** MariaDB returns the default as an SQL expression ('text', NULL), MySQL the bare value */
    protected static function defaultClause(?string $default): string
    {
        if ($default === null) {
            return '';
        }
        return ' DEFAULT ' . (($default === 'NULL' || str_starts_with($default, "'")) ? $default : qquote($default));
    }

    protected static function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
