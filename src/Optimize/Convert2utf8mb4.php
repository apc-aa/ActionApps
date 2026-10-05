<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;

/**
 *
 */
class Convert2utf8mb4 extends AbstractOptimize
{
    /** shown on State Check - see AbstractOptimize::CHECK_* */
    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    /** Name function
     * @return string - a name
     */
    public static function name(): string
    {
        return _m("Convert old database collation utf8 to utf8mb4");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string
    {
        return _m("Convert old database collation utf8 (or utf8mb3) to utf8mb4 for all tables and set database default to utf8mb4");
    }

    /** Test function
     * @return bool
     */
    public function test(): bool
    {
        $plan = $this->plan();
        foreach (array_keys($plan['sqls']) as $tablename) {
            $this->message(_m('collation for %1 table is %2', [$tablename, $plan['tables'][$tablename]['TABLE_COLLATION']]));
        }
        $this->message(_m("We found %1 tables which need to be converted", [count($plan['sqls'])]));
        if ($plan['sqls']) {
            $this->connectionOk();
            $this->myisamTablesAdvised($plan);
        }
        return !$plan['sqls'] && str_starts_with($plan['db_collation'], 'utf8mb4_');
    }

    /**
     */
    public function repair(): bool
    {
        $plan = $this->plan();
        if (!$plan['sqls'] && str_starts_with($plan['db_collation'], 'utf8mb4_')) {
            $this->message(_m("We found %1 tables which need to be converted", [0]));
            return true;
        }
        if (!$this->connectionOk() || $this->myisamTablesAdvised($plan)) {
            return false;
        }
        $new_collation = $plan['collation'];
        foreach ($plan['sqls'] as $tablename => $sql) {
            $this->message(_m('Convert table %1 (%2) to %3', [$tablename, $plan['tables'][$tablename]['TABLE_COLLATION'], $new_collation]));
            $this->query($sql);
        }
        $this->query("ALTER DATABASE " . self::identifier($this->schema()) . " CHARACTER SET = utf8mb4 COLLATE = $new_collation");
        $this->message(_m('Database default set to %1', [$new_collation]));
        if ($this->connectionCharset() !== 'utf8mb4') {
            $this->message(_m('Please do not forget to change DB_CHARACTER_SET to "utf8mb4" and DB_COLLATION_CONNECTION to "%1" in your config.php', [$new_collation]));
        }
        return true;
    }

    /** @return string[] charsets of the columns to convert (utf8mb3 is called utf8 in MySQL and older MariaDB) */
    protected function sourceCharsets(): array
    {
        return ['utf8mb3', 'utf8'];
    }

    /** the collation for a database with no utf8mb4 table and no utf8mb4 default - the most used one in utf8mb4
     * @param array<string, string> $collations [table_name => table_collation]
     */
    protected function fallbackCollation(array $collations): string
    {
        $counts = array_count_values(array_filter(
            $collations,
            static fn($collation, $tablename) => preg_match('/^utf8(mb3)?_/', (string) $collation) && !str_ends_with((string) $collation, '_bin') && !str_starts_with((string) $tablename, 'bck_'),
            ARRAY_FILTER_USE_BOTH,
        ));
        arsort($counts);
        return (string) preg_replace('/^utf8(mb3)?_/', 'utf8mb4_', (string) (array_key_first($counts) ?? 'utf8mb4_unicode_ci'));
    }

    /**
     * @return array{tables: array<string, array<string, ?string>>, sqls: array<string, string>, collation: string, db_collation: string}
     */
    protected function plan(): array
    {
        $schema = $this->schema();
        $tables = DB_AA::select(['TABLE_NAME' => []], 'SELECT TABLE_NAME, TABLE_COLLATION, ENGINE, ROW_FORMAT FROM information_schema.TABLES', [['TABLE_SCHEMA', $schema], ['TABLE_TYPE', 'BASE TABLE']]);
        $charsets = $this->sourceCharsets();
        $columns = DB_AA::select([], 'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT, COLLATION_NAME FROM information_schema.COLUMNS', [['TABLE_SCHEMA', $schema], ['CHARACTER_SET_NAME', $charsets]]);
        $db_collation = (string) DB_AA::select1('DEFAULT_COLLATION_NAME', 'SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA', [['SCHEMA_NAME', $schema]]);
        $collations = array_map(static fn($table) => (string) $table['TABLE_COLLATION'], $tables);
        $collation = self::targetCollation($collations, $db_collation, $this->fallbackCollation($collations));
        return ['tables' => $tables, 'sqls' => self::alterSqls($tables, $columns, $charsets, $collation), 'collation' => $collation, 'db_collation' => $db_collation];
    }

    /** utf8mb3 texts fit utf8mb4 as they are, whatever the connection */
    protected function connectionOk(): bool
    {
        return true;
    }

    protected function connectionCharset(): string
    {
        return defined('DB_CHARACTER_SET') ? (string) constant('DB_CHARACTER_SET') : '';
    }

    protected function schema(): string
    {
        return (string) constant('DB_NAME');
    }

    /** MyISAM keys have at most 1000 bytes - an index on varchar(255) in utf8mb4 needs 1020 and the
     *  conversion would stop in the middle
     * @param array{tables: array<string, array<string, ?string>>, sqls: array<string, string>} $plan
     * @return bool true if there are MyISAM tables to convert
     */
    protected function myisamTablesAdvised(array $plan): bool
    {
        $myisam = array_keys(array_filter(array_intersect_key($plan['tables'], $plan['sqls']), static fn($table) => $table['ENGINE'] === 'MyISAM'));
        if ($myisam) {
            $this->message(_m('Convert the MyISAM tables %1 to InnoDB first - Optimize "%2".', [implode(', ', $myisam), Convert2innodb::name()]));
        }
        return (bool) $myisam;
    }

    /** One collation for all the tables - the database refuses to compare columns of two different
     *  utf8mb4 collations ("Illegal mix of collations"), e.g. in a join of content with constant.
     *  The tables already in utf8mb4 decide (not backups, not case sensitive _bin), then the database default.
     * @param array<string, string> $collations [table_name => table_collation]
     */
    public static function targetCollation(array $collations, string $db_collation, string $fallback): string
    {
        $utf8mb4 = array_count_values(array_filter(
            $collations,
            static fn($collation, $tablename) => str_starts_with((string) $collation, 'utf8mb4_') && $collation !== 'utf8mb4_bin' && !str_starts_with((string) $tablename, 'bck_'),
            ARRAY_FILTER_USE_BOTH,
        ));
        arsort($utf8mb4);
        return (string) (array_key_first($utf8mb4) ?? (str_starts_with($db_collation, 'utf8mb4_') ? $db_collation : $fallback));
    }

    /** ALTER for each table with a default or text column in the $charsets; backup tables bck_* stay as they are.
     *  MODIFY with the column type as it is - CONVERT TO CHARACTER SET would widen TEXT to MEDIUMTEXT
     *  and MEDIUMTEXT to LONGTEXT, so the tables would no longer match the AA schema. InnoDB COMPACT
     *  rows allow 767 bytes for a key, DYNAMIC 3072 - enough for varchar(255) in utf8mb4.
     * @param array<string, array<string, ?string>> $tables  [table_name => row of information_schema.TABLES]
     * @param array<int, array<string, ?string>>    $columns rows of information_schema.COLUMNS in the $charsets
     * @param string[]                              $charsets charsets to convert
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
        foreach ($tables as $tablename => $table) {
            if (str_starts_with($tablename, 'bck_') || (!isset($modify[$tablename]) && !in_array(strtok((string) $table['TABLE_COLLATION'], '_'), $charsets, true))) {
                continue;
            }
            $row_format = ($table['ENGINE'] === 'InnoDB' && in_array($table['ROW_FORMAT'], ['Compact', 'Redundant'], true)) ? ['ROW_FORMAT=DYNAMIC'] : [];
            $ret[$tablename] = implode(', ', array_merge(['ALTER TABLE ' . self::identifier($tablename) . " DEFAULT CHARACTER SET utf8mb4 COLLATE $collation"], $row_format, $modify[$tablename] ?? []));
        }
        return $ret;
    }

    /** MariaDB returns the default as an SQL expression ('text', NULL), MySQL the bare value */
    protected static function defaultClause(?string $default): string
    {
        if ($default === null) {
            return '';
        }
        return ' DEFAULT ' . (($default === 'NULL' || preg_match("/^'([^'\\\\]|''|\\\\.)*'$/s", $default)) ? $default : qquote($default));
    }

    protected static function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
