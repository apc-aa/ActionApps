<?php

declare(strict_types=1);

namespace AA\Perm\LdapToSql;

use AA\IO\DB\DB_AA;

/**
 * The columns of AA data that name a person by their user id, read and rewritten
 * directly in the database - once, for the conversion of an LDAP install to SQL ids.
 *
 * Values are compared through DB_AA::asText() everywhere, so the spellings dnValues()
 * lists are exactly the ones remap() matches - whatever the column's type or collation.
 * Every method reads each column in a full scan (no column here has a usable index).
 */
final class References
{
    public const COLUMNS = [['item', 'posted_by'], ['item', 'edited_by'], ['change', 'user'], ['log', 'user'],
        ['profile', 'uid'], ['links_links', 'created_by'], ['links_links', 'edited_by'], ['links_links', 'checked_by'],
        ['module', 'created_by'], ['slice', 'created_by'], ['searchlog', 'user'], ['toexecute', 'aa_user'], ['object_text', 'value']];

    /** the user of imported items - never a person, so its number stays out of the id range */
    private const IMPORTER = '9999999999';

    /** the highest id users.id (int(11)) holds */
    public const MAX_ID = 2147483647;

    /**
     * Extra conditions for columns holding more than user ids: object_text.value has
     * timestamps and counters too; a user id only under these properties (Note author,
     * AA_Plannedtask saved_by, BatchRun user).
     */
    private const ID_ROWS = ['object_text.value' => [['property', ['author', 'saved_by', 'user']]]];

    /** tables of the permission system itself, replaced by the import */
    private const OWN_TABLES = ['users', 'membership', 'perms'];

    /** what a DN of this install contains, in templates, views and content */
    private const MENTION = 'ou=AA';

    /** bigger tables are item content and logs, not hand-written settings */
    private const MENTION_MAX_ROWS = 200000;

    private const TEXT_TYPES = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob'];

    /**
     * Distinct values over COLUMNS that Dn::isDn() accepts, each exact spelling once.
     * The filter is only '=' followed by ',' - every DN has them, while a 'uid=' prefix
     * would miss ' uid=…', 'uid =…' and, in case-sensitive varbinary columns, 'UID=…'.
     * @return list<string>
     */
    public static function dnValues(): array
    {
        $values = [];
        foreach (self::present() as [$table, $column]) {
            $found = DB_AA::select(
                '',
                'SELECT DISTINCT ' . DB_AA::asText($column) . ' AS v FROM ' . DB_AA::quoteIdentifier($table),
                [[DB_AA::quoteIdentifier($column), '%=%,%', 'LIKE']],
            );
            foreach ($found as $value) {
                $values[(string) $value] = true;
            }
        }
        return array_values(array_filter(array_map('strval', array_keys($values)), Dn::isDn(...)));
    }

    /**
     * The id the conversion may start at: above every all-digit value in COLUMNS (ids of
     * the SQL era, foreign numbers) and above users.id, the importer left out.
     * @throws \RuntimeException when a maximum cannot be read or no int(11) id is left
     */
    public static function firstFreeId(): int
    {
        $max = self::maximum('users', 'MAX(' . DB_AA::quoteIdentifier('id') . ')', []);
        foreach (self::present() as [$table, $column]) {
            $col   = DB_AA::quoteIdentifier($column);
            $where = [[$col, '^[0-9]+$', 'REGEXP'], [$col, self::IMPORTER, 's<>']];
            foreach (self::ID_ROWS["$table.$column"] ?? [] as [$name, $value]) {
                $where[] = [DB_AA::quoteIdentifier($name), $value];
            }
            $max = max($max, self::maximum($table, "MAX(CAST($col AS UNSIGNED))", $where));
        }
        if ($max >= self::MAX_ID) {
            throw new \RuntimeException("The data already holds user id $max - no room left for new ids in users.id.");
        }
        return $max + 1;
    }

    /**
     * Rows rewrite() would change, with the time each count took - the scan the rewrite
     * repeats, so the sum estimates its run.
     * @param array<string, string> $remap exact value => new id
     * @return array<string, array{rows: int, seconds: float}> "table.column" => …
     */
    public static function count(array $remap): array
    {
        if (!$remap) {
            return [];
        }
        $keys = array_map('strval', array_keys($remap));
        $ret  = [];
        foreach (self::present() as [$table, $column]) {
            $start = hrtime(true);
            $rows  = DB_AA::count($table, [[DB_AA::asText($column), $keys]]);
            $ret["$table.$column"] = ['rows' => $rows, 'seconds' => (hrtime(true) - $start) / 1e9];
        }
        return $ret;
    }

    /**
     * Rewrites every column by $remap, one UPDATE per column.
     * @param array<string, string> $remap exact value => new id
     * @return array<string, int> "table.column" => changed rows
     * @throws \RuntimeException outside DB_AA::transaction() and when an UPDATE fails - the
     *                           caller's transaction then takes back the columns already done
     */
    public static function rewrite(array $remap): array
    {
        if (!DB_AA::inTransaction()) {
            throw new \RuntimeException('References are rewritten only inside DB_AA::transaction(), so that a failure leaves no column half converted.');
        }
        if (!$remap) {
            return [];
        }
        $ret = [];
        foreach (self::present() as [$table, $column]) {
            $changed = DB_AA::remap($table, $column, $remap);
            if ($changed === false) {
                throw new \RuntimeException("Rewriting the user references in $table.$column failed.");
            }
            $ret["$table.$column"] = $changed;
        }
        return $ret;
    }

    /**
     * Rows mentioning 'ou=AA' (any case) in the text columns of the smaller tables -
     * templates, views and settings that name a person by DN and need a manual fix.
     * Table and column names come from information_schema; one that would need escaping is
     * skipped rather than put into SQL.
     * @return array<string, int> "table.column" => rows, only columns with a mention
     */
    public static function mentions(): array
    {
        $db     = (string) constant('DB_NAME');
        $tables = DB_AA::select('t', 'SELECT TABLE_NAME AS t FROM information_schema.TABLES', [
            ['TABLE_SCHEMA', $db], ['TABLE_TYPE', 'BASE TABLE'], ['TABLE_ROWS', self::MENTION_MAX_ROWS, '<'],
        ]);
        $tables = array_values(array_filter(
            array_map('strval', $tables),
            static fn(string $t) => self::isName($t) && !in_array($t, self::OWN_TABLES, true),
        ));
        if (!$tables) {
            return [];
        }
        $columns = DB_AA::select(
            ['t' => '+c'],
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS',
            [['TABLE_SCHEMA', $db], ['TABLE_NAME', $tables], ['DATA_TYPE', self::TEXT_TYPES]],
            ['TABLE_NAME', 'ORDINAL_POSITION'],
        );

        $ret = [];
        foreach ($columns as $table => $names) {
            $table = (string) $table;
            $names = array_values(array_filter(
                array_map('strval', $names),
                static fn(string $c) => self::isName($c) && !in_array([$table, $c], self::COLUMNS, true),
            ));
            $conditions = array_map(static fn(string $c) => [DB_AA::asText($c, false), self::MENTION, 'CONTAIN'], $names);
            // one scan rules out most tables before a count per column
            if (count($conditions) > 1 && !DB_AA::count($table, [[null, array_map(static fn($c) => [$c], $conditions), 'OR']])) {
                continue;
            }
            foreach ($conditions as $i => $condition) {
                if ($rows = DB_AA::count($table, [$condition])) {
                    $ret["$table.{$names[$i]}"] = $rows;
                }
            }
        }
        return $ret;
    }

    /**
     * COLUMNS this database has - an install without the Links module may lack links_links.
     * @return list<array{0: string, 1: string}>
     * @throws \RuntimeException when not even item.posted_by is listed - the listing failed
     */
    private static function present(): array
    {
        $rows = DB_AA::select([], 'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS', [
            ['TABLE_SCHEMA', (string) constant('DB_NAME')],
            ['TABLE_NAME', array_values(array_unique(array_column(self::COLUMNS, 0)))],
            ['COLUMN_NAME', array_values(array_unique(array_column(self::COLUMNS, 1)))],
        ]);
        $have = [];
        foreach ($rows as $row) {
            $have[$row['t'] . '.' . $row['c']] = true;
        }
        if (!isset($have['item.posted_by'])) {
            throw new \RuntimeException('information_schema does not list item.posted_by - the columns referring to people cannot be told.');
        }
        return array_values(array_filter(self::COLUMNS, static fn(array $tc) => isset($have["$tc[0].$tc[1]"])));
    }

    /** @throws \RuntimeException when the query gave no row - a MAX() always gives one */
    private static function maximum(string $table, string $expression, array $where): int
    {
        $max = DB_AA::select1('m', "SELECT $expression AS m FROM " . DB_AA::quoteIdentifier($table), $where);
        if ($max === false) {
            throw new \RuntimeException("The highest user id in $table could not be read.");
        }
        return (int) $max;   // NULL = no numeric value there
    }

    private static function isName(string $name): bool
    {
        return preg_match('/^\w+\z/', $name) === 1;
    }
}
