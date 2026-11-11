<?php

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;
use AA\Util\Difftext;
use AA_MbC;
use AA_MbI;
use AA_MbT;
use AA_Metabase;
use RuntimeException;

/** Brings the database structure to the AA template (include/metabase_schema.php): creates a missing
 *  table and changes a differing one by ALTER TABLE in place. The server keeps the rows and the writes
 *  that come meanwhile (InnoDB even lets them run); copying the table into a new one would lose them.
 *  What the template does not know (a table, a column, an index) and a column the template would narrow
 *  are only reported - they may hold data or serve a slow query of the site.
 */
class UpdateDbStructure extends \AA\Optimize\AbstractOptimize
{
    /** a table of up to so many bytes (data and indexes) is changed even when it has to be locked meanwhile - seconds */
    private const LOCKED_SIZE = 32 * 1024 * 1024;

    /** seconds an ALTER waits for the queries running on the table - the queries coming meanwhile wait for the ALTER */
    private const LOCK_WAIT = 5;

    /** @var array<string, array{action: string, alter: string[], extra: string[], kept: string[], live: string, template: string}>|null */
    private ?array $plan = null;

    /** @var array<string, array{ENGINE: ?string, ROW_FORMAT: ?string, size: int|string|null}>|null */
    private ?array $tables = null;

    /** Name function
     * @return string - a name
     */
    public static function name(): string
    {
        return _m("Update database structure");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string
    {
        return _m('Compares all the tables with the AA template of the database structure. Repair creates the missing tables and changes the differing ones in place (ALTER TABLE) - the data and the writes during the change stay. Tables, columns and indexes the template does not know and columns the template would narrow are only listed. On a big table the change takes minutes - run it when the traffic is low.');
    }

    /** checks if this Optimize class belongs to specified type (like "sql_update") */
    public static function types(): array
    {
        return ['sql_update'];
    }

    /** the optimizers are ordered by priority (less first) when executed
     *  in chain (like for sql_update)
     *  Default value is 100
     *  Execute this first
     */
    public static function priority(): int
    {
        return 50;
    }  // quite big priority but not first

    /** Only a missing or a differing table fails the test; what the repair leaves as it is, is just listed
     * @return bool
     */
    public function test(): bool
    {
        $different = false;
        foreach ($this->plan() as $tablename => $plan) {
            if ($plan['action'] === 'create') {
                $this->message(_m('Table %1 is missing.', [safe($tablename)]));
            } elseif ($plan['action'] === 'alter') {
                $this->message(_m('Table %1 differs: %2', [safe($tablename), safe(implode(', ', $plan['alter']))]));
                $this->message(Difftext::renderHtml($plan['live'], $plan['template']));
            }
            $this->reportLeft($tablename, $plan);
            $different = $different || in_array($plan['action'], ['create', 'alter'], true);
        }
        if (!$different) {
            $this->message(_m('OK - all the tables have the structure of the template'));
        }
        return !$different;
    }

    /** Main update function
     * @return bool
     */
    public function repair(): bool
    {
        $ok = true;
        foreach ($this->plan() as $tablename => $plan) {
            $this->reportLeft($tablename, $plan);   // the chain clears the report of test() before repair()
            if ($plan['action'] === 'create') {
                $ok = $this->run($tablename, $this->templateMetabase()->getCreateSql($tablename)) && $ok;
            } elseif ($plan['action'] === 'alter') {
                $ok = $this->alter($tablename, $plan['alter']) && $ok;
            }
        }
        $this->plan = $this->tables = null;
        return $ok;
    }

    /** @param array{action: string, extra: string[], kept: string[]} $plan */
    private function reportLeft(string $tablename, array $plan): void
    {
        if ($plan['action'] === 'unknown') {
            $this->message(_m('Table %1 is not in the template - you can delete it manually.', [safe($tablename)]));
        }
        if ($plan['extra']) {
            $this->message(_m('Table %1 has columns or indexes the template does not know - left as they are; delete them manually, if nothing uses them: %2', [safe($tablename), safe(implode(', ', $plan['extra']))]));
        }
        if ($plan['kept']) {
            $this->message(_m('Table %1 keeps the columns the template would narrow or change to another type, and the indexes over them - change them manually, if the data allow it: %2', [safe($tablename), safe(implode(', ', $plan['kept']))]));
        }
    }

    /** A big table is changed only without locking it (LOCK=NONE), as InnoDB can do it for indexes, NOT NULL,
     *  a new column or a longer varchar - another type of a column or a MyISAM table would stop the writes for
     *  minutes, so the server refuses and the ALTER is listed for a manual run when the traffic is low. A small
     *  table is changed in any way - its lock lasts seconds. A failed ALTER leaves the table as it was. */
    private function alter(string $tablename, array $clauses): bool
    {
        $table = $this->liveTables()[$tablename] ?? [];
        $size  = (int) ($table['size'] ?? 0);
        $lock  = ($size > self::LOCKED_SIZE) ? ', LOCK=NONE' : '';
        $sql   = "ALTER TABLE `$tablename` " . implode(', ', $clauses);
        $error = $this->strictly($sql . $lock);
        if (str_contains($error, ': 1118 ') && (($table['ENGINE'] ?? '') === 'InnoDB') && in_array($table['ROW_FORMAT'] ?? '', ['Compact', 'Redundant'], true)) {
            // strict InnoDB refuses to rebuild an old table whose row could exceed 8 kB (Row size too large)
            $sql  .= ', ROW_FORMAT=DYNAMIC';
            $error = $this->strictly($sql . $lock);
        }
        if (($lock === '') || !preg_match('/: (1845|1846|1799) /', $error)) {
            // 1845, 1846: not without a lock; 1799: too many writes meanwhile - a data error would stay at night
            return $this->result($tablename, $error);
        }
        $this->message(_m('Table %1 (%2 MB) not changed: %3. Run it when the traffic is low: %4', [safe($tablename), round($size / 1048576), safe($error), safe("SET SESSION sql_mode = 'STRICT_ALL_TABLES'; $sql;")]));
        return false;
    }

    private function run(string $tablename, string $sql): bool
    {
        return $this->result($tablename, $this->strictly($sql));
    }

    private function result(string $tablename, string $error): bool
    {
        if ($error === '') {
            $this->message(_m('%1 done.', [safe($tablename)]));
            return true;
        }
        $this->message(self::busy($error)
            ? _m('Table %1 not changed - other queries are using it. Try it again later.', [safe($tablename)])
            : _m('Table %1 not changed - the database refused the change: %2', [safe($tablename), safe($error)]));
        return false;
    }

    /** the ALTER waited for the queries running on the table longer than LOCK_WAIT */
    private static function busy(string $error): bool
    {
        return str_contains($error, 'Lock wait timeout');
    }

    /** The statement in strict SQL mode, so a value that does not fit the new definition (a NULL for NOT NULL,
     *  a too long text) stops it instead of being changed, and with a short wait for the table: the queries
     *  coming meanwhile queue behind a waiting ALTER, the site would stand. The connection settings are set
     *  back - it may be persistent and serve the next request.
     *  A failed SET (the connection lost) is reported for the table, the next tables are still tried.
     * @return string the error, '' when done */
    private function strictly(string $sql): string
    {
        try {
            $this->query("SET @aa_sql_mode = @@SESSION.sql_mode, @aa_lock_wait = @@SESSION.lock_wait_timeout, SESSION sql_mode = 'STRICT_ALL_TABLES', SESSION lock_wait_timeout = " . self::LOCK_WAIT);
            try {
                return $this->tryQuery($sql);
            } finally {
                $this->query('SET SESSION sql_mode = @aa_sql_mode, SESSION lock_wait_timeout = @aa_lock_wait');
            }
        } catch (RuntimeException) {
            return 'the connection to the database failed - check whether the table changed';
        }
    }

    /** What each table needs: action none|alter|create|unknown (not in the template) - one plan for test()
     *  and the repair() after it
     * @return array<string, array{action: string, alter: string[], extra: string[], kept: string[], live: string, template: string}>
     */
    private function plan(): array
    {
        if ($this->plan !== null) {
            return $this->plan;
        }
        $template   = $this->templateMetabase();
        $live       = $this->liveMetabase();
        $collations = $this->liveCollations();
        $tables     = $this->liveTables();
        $this->plan = [];
        foreach ($template->tables as $tablename => $table) {
            $current = $live->tables[$tablename] ?? null;
            $myisam  = ($tables[$tablename]['ENGINE'] ?? '') === 'MyISAM';
            $changes = $current ? self::changes($table, $current, $collations[$tablename] ?? [], $myisam) : ['alter' => [], 'extra' => [], 'kept' => [], 'checks' => []];
            if ($changes['checks'] && ((int) ($tables[$tablename]['size'] ?? 0) <= self::LOCKED_SIZE)) {
                $changes = self::withoutMisfits($changes, static fn(string $sql): bool => (bool) DB_AA::select1('misfit', $sql));
            }
            $this->plan[$tablename] = [
                'action'   => $current ? ($changes['alter'] ? 'alter' : 'none') : 'create',
                'alter'    => $changes['alter'],
                'extra'    => $changes['extra'],
                'kept'     => $changes['kept'],
                'live'     => $current ? $current->getCompareSql() : '',
                'template' => $table->getCompareSql(),
            ];
        }
        foreach (array_diff($live->getTableNames(), $template->getTableNames()) as $tablename) {
            $this->plan[$tablename] = ['action' => 'unknown', 'alter' => [], 'extra' => [], 'kept' => [], 'live' => '', 'template' => ''];
        }
        return $this->plan;
    }

    protected function templateMetabase(): AA_Metabase
    {
        return AA::Metabase();
    }

    /** the tables of this database, without the bck_ and tmp_ ones */
    protected function liveMetabase(): AA_Metabase
    {
        $live = new AA_Metabase();
        $live->loadFromDb(true);
        return $live;
    }

    /** engine, row format and size (data and indexes) of each table of this database
     * @return array<string, array{ENGINE: ?string, ROW_FORMAT: ?string, size: int|string|null}> */
    protected function liveTables(): array
    {
        return $this->tables ??= DB_AA::select(['TABLE_NAME' => []], 'SELECT TABLE_NAME, ENGINE, ROW_FORMAT, DATA_LENGTH + INDEX_LENGTH AS size FROM information_schema.TABLES', [['TABLE_SCHEMA', constant('DB_NAME')]]);
    }

    /** the collation of each text column of this database - the template names none
     * @return array<string, array<string, string>> table => column => collation */
    protected function liveCollations(): array
    {
        $collations = [];
        foreach (DB_AA::select([], 'SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS', [['TABLE_SCHEMA', constant('DB_NAME')], ['COLLATION_NAME', '', 'FILLED']]) as $row) {
            $collations[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row['COLLATION_NAME'];
        }
        return $collations;
    }

    /** The changes the data of the table do not fit, moved from 'alter' to 'kept' - the server would refuse the
     *  whole ALTER in strict mode. Checked on small tables only: on a big one the scan would take minutes.
     * @param array{alter: string[], extra: string[], kept: string[], checks: array<string, array{sql: string, kept: string, with: string[]}>} $changes
     * @param callable(string): bool $misfits does the query find a row
     * @return array{alter: string[], extra: string[], kept: string[], checks: array<string, array{sql: string, kept: string, with: string[]}>}
     */
    private static function withoutMisfits(array $changes, callable $misfits): array
    {
        foreach ($changes['checks'] as $clause => $check) {
            if ($misfits($check['sql'])) {
                $changes['alter']  = array_values(array_diff($changes['alter'], [$clause, ...$check['with']]));
                $changes['kept'][] = $check['kept'];
            }
        }
        return $changes;
    }

    /** The ALTER TABLE clauses that bring the live table to the template. Never changed: the live columns and
     *  indexes the template does not know (a column may hold data, an index may serve a slow query of the
     *  site), a column the template would narrow (a shorter text, a smaller number, signed for unsigned, a
     *  number for a text) and the template indexes over such a column. A changed column keeps its collation
     *  and comment; an index the template has under another name is renamed - never kept twice. The order of the columns
     *  stays: AA names the columns it writes, and moving one would rebuild the table for nothing.
     *  'checks' are the queries finding the rows a clause would not fit: a NULL for NOT NULL, a text longer
     *  than its new binary column, a duplicate in a new unique key ('with' = the clauses that go only with it:
     *  the drop of the key it replaces, auto_increment of its column - such a column must be a key).
     * @param array<string, string> $collations live column => collation
     * @param bool $myisam the live table is MyISAM - its index on a long text is a prefix
     * @return array{alter: string[], extra: string[], kept: string[], checks: array<string, array{sql: string, kept: string, with: string[]}>}
     */
    public static function changes(AA_MbT $template, AA_MbT $live, array $collations = [], bool $myisam = false): array
    {
        $modify = $add = $kept = $kept_columns = $checks = $to_binary = $auto = [];
        $from   = "SELECT 1 AS misfit FROM `{$template->t}`";
        foreach ($template->c as $name => $column) {
            if (!isset($live->c[$name])) {
                $add[] = 'ADD COLUMN ' . trim($column->getCreateSql());
            } elseif (AA_Metabase::comparable($column->getCreateSql()) !== AA_Metabase::comparable($live->c[$name]->getCreateSql())) {
                if (self::holds($column->c[1], $live->c[$name]->c[1])) {
                    $clause   = 'MODIFY COLUMN ' . self::definition($column, $live->c[$name], $collations[$name] ?? '');
                    $modify[] = $clause;
                    $misfit   = [];
                    if ($live->c[$name]->c[2] && !$column->c[2]) {
                        $misfit[] = "`$name` IS NULL";
                    }
                    if (($collations[$name] ?? '') && preg_match('/^(?:var)?binary\((\d+)\)|blob$/i', $column->c[1], $m)) {
                        $to_binary[$name] = true;
                        if (isset($m[1])) {
                            $misfit[] = "LENGTH(`$name`) > {$m[1]}";   // the binary column takes the bytes of the text
                        }
                    }
                    if ($misfit) {
                        $checks[$clause] = ['sql' => "$from WHERE " . implode(' OR ', $misfit), 'kept' => "$name {$live->c[$name]->c[1]} (template {$column->c[1]}) - the data do not fit", 'with' => []];
                    }
                    if (str_contains((string) $column->c[5], 'auto_increment') && !str_contains((string) $live->c[$name]->c[5], 'auto_increment')) {
                        $auto[$name] = $clause;
                    }
                } else {
                    $kept[]         = "$name {$live->c[$name]->c[1]} (template {$column->c[1]})";
                    $kept_columns[] = $name;
                }
            }
        }

        $drop    = $renamed = $add_index = [];
        $unknown = array_diff_key($live->i, $template->i);
        foreach ($template->i as $name => $index) {
            $current = $live->i[$name] ?? null;
            $wanted  = $myisam ? self::myisamIndex($index, $template, $collations) : $index;
            if ($current?->getCreateSql() === $wanted->getCreateSql()) {
                continue;
            }
            if (array_intersect(self::indexColumns($index), $kept_columns)) {
                $kept[] = "index $name";
                continue;
            }
            if (!$current && ($same = self::sameIndex($wanted, $unknown))) {
                unset($unknown[$same]);
                $renamed[] = "RENAME INDEX `$same` TO `$name`";
                continue;
            }
            $dropped = $current ? (($current->s === 'P') ? 'DROP PRIMARY KEY' : "DROP INDEX `$name`") : '';
            if ($dropped) {
                $drop[] = $dropped;
            }
            $add_index[] = $clause = 'ADD ' . $index->getCreateSql();
            if (($index->s !== 'I') && ($sql = self::duplicatesSql($index, $template, $live, $to_binary))) {
                $with            = array_values(array_intersect_key($auto, array_flip(self::indexColumns($index))));
                $checks[$clause] = ['sql' => $sql, 'kept' => "index $name - duplicate values", 'with' => array_values(array_filter([$dropped, ...$with]))];
            }
        }
        $extra = array_values(array_diff(array_keys($live->c), array_keys($template->c)));
        foreach (array_keys($unknown) as $name) {
            $extra[] = "index $name";
        }
        return ['alter' => array_merge($drop, $modify, $add, $renamed, $add_index), 'extra' => $extra, 'kept' => $kept, 'checks' => $checks];
    }

    /** The query finding the rows a new unique or primary key would not take, '' when there can be none. A column
     *  the same ALTER adds has one value in all the rows - an auto_increment one another in each row, and a NULL
     *  is allowed in a unique key any number of times.
     * @param array<string, true> $to_binary the columns the ALTER changes from a text to binary
     */
    private static function duplicatesSql(AA_MbI $index, AA_MbT $template, AA_MbT $live, array $to_binary): string
    {
        $parts = $nulls = [];
        foreach ($index->c as $col) {
            [$name, $prefix] = is_array($col) ? $col : [$col, ''];
            if (!isset($live->c[$name])) {
                $new = $template->c[$name] ?? null;
                if (!$new || str_contains((string) $new->c[5], 'auto_increment') || (($index->s === 'U') && $new->c[2] && ($new->c[4] === null))) {
                    return '';
                }
                continue;
            }
            $value   = isset($to_binary[$name]) ? "CAST(`$name` AS BINARY)" : "`$name`";   // the binary key tells 'a' from 'A'
            $parts[] = $prefix ? "LEFT($value, $prefix)" : $value;
            $nulls[] = "`$name` IS NULL";
        }
        $from  = "SELECT 1 AS misfit FROM `{$template->t}`";
        $group = $parts ? ' GROUP BY ' . implode(', ', $parts) : '';
        if ($index->s === 'P') {
            return "$from$group HAVING COUNT(*) > 1" . ($nulls ? ' OR MAX(' . implode(' OR ', $nulls) . ')' : '');   // no NULL in a primary key
        }
        return $from . ($nulls ? ' WHERE NOT (' . implode(' OR ', $nulls) . ')' : '') . "$group HAVING COUNT(*) > 1";
    }

    /** The template column with the live collation and comment - the template names neither, and MODIFY
     *  would set the table default collation and drop the comment */
    private static function definition(AA_MbC $template, AA_MbC $live, string $collation): string
    {
        $sql  = trim($template->getCreateSql());
        $type = "`{$template->c[0]}` {$template->c[1]}";
        if ($collation && preg_match('/^(?:(?:var)?char\(|(?:tiny|medium|long)?text$|enum\(|set\()/i', $template->c[1])) {
            $sql = "$type COLLATE $collation" . substr($sql, strlen($type));
        }
        if ((string) $live->c[6] !== '') {
            $sql .= " COMMENT '" . str_replace(['\\', "'"], ['\\\\', "''"], $live->c[6]) . "'";
        }
        return $sql;
    }

    /** Can the template type hold every value of the live type? A number and a non-number never; a date or an
     *  enum is written into a text, never a text into a date or an enum - a value might not fit; an enum (a set)
     *  holds the values of its shorter list. */
    private static function holds(string $template, string $live): bool
    {
        [$t_kind, $t_size, $t_unsigned] = self::kind($template);
        [$l_kind, $l_size, $l_unsigned] = self::kind($live);
        if ($t_kind !== $l_kind) {
            return ($t_kind === 'string') && in_array($l_kind, ['enum', 'set', 'other'], true);
        }
        if ($t_unsigned && !$l_unsigned) {
            return false;                                       // the negative numbers
        }
        return match ($t_kind) {
            // an unsigned number has one bit more than the signed one of the same size
            'int'     => ($t_size * 8 - ($t_unsigned ? 0 : 1)) >= ($l_size * 8 - ($l_unsigned ? 0 : 1)),
            'decimal' => ($t_size[0] - $t_size[1] >= $l_size[0] - $l_size[1]) && ($t_size[1] >= $l_size[1]),
            'float'   => ($t_size[0] >= $l_size[0]) && ($t_size[1] >= $l_size[1]) && ($t_size[2] >= $l_size[2]),
            'enum', 'set' => !array_diff($l_size, $t_size),
            'other'   => strtolower($template) === strtolower($live),
            default   => $t_size >= $l_size,
        };
    }

    /** [kind, size, unsigned] of a column type: int (bytes), float ([bytes, integer digits, decimals]), decimal ([digits, decimals]),
     *  string (characters or bytes it takes), enum and set (the values), other (0) - a date */
    private static function kind(string $type): array
    {
        $type     = strtolower($type);
        $unsigned = str_contains($type, 'unsigned');
        if (preg_match('/^(tiny|small|medium|big)?int\b/', $type, $m)) {
            return ['int', ['tiny' => 1, 'small' => 2, 'medium' => 3, '' => 4, 'big' => 8][$m[1] ?? ''], $unsigned];
        }
        if (preg_match('/^(float|double|real)(?:\((\d+),(\d+)\))?/', $type, $m)) {
            // float(M,D) rounds to D decimals; without them the value is stored as it is
            return ['float', [($m[1] === 'float') ? 4 : 8, isset($m[2]) ? (int) $m[2] - (int) $m[3] : PHP_INT_MAX, isset($m[3]) ? (int) $m[3] : PHP_INT_MAX], $unsigned];
        }
        if (preg_match('/^(?:decimal|numeric)(?:\((\d+)(?:,(\d+))?\))?/', $type, $m)) {
            return ['decimal', [(int) ($m[1] ?? 10), (int) ($m[2] ?? 0)], $unsigned];
        }
        if (preg_match('/^(?:var)?(?:char|binary)\((\d+)\)/', $type, $m)) {
            return ['string', (int) $m[1], false];
        }
        if (preg_match('/^(tiny|medium|long)?(?:text|blob)$/', $type, $m)) {
            return ['string', ['tiny' => 255, '' => 65535, 'medium' => 16777215, 'long' => 4294967295][$m[1] ?? ''], false];
        }
        if (preg_match('/^(enum|set)\((.*)\)/s', $type, $m)) {
            preg_match_all("/'((?:[^']|'')*)'/", $m[2], $values);
            return [$m[1], $values[1], false];
        }
        return ['other', 0, false];
    }

    /** The index as MariaDB makes it in a MyISAM table: a key holds at most 1000 bytes there, so a longer text
     *  column gets a prefix (varchar(255) in utf8mb4 => 250 characters) - without it the index would differ forever
     * @param array<string, string> $collations live column => collation
     */
    private static function myisamIndex(AA_MbI $index, AA_MbT $template, array $collations): AA_MbI
    {
        if ($index->s !== 'I') {
            return $index;   // a longer unique key becomes a HASH key, a primary one is refused
        }
        $wanted = clone $index;
        foreach ($wanted->c as $position => $column) {
            if (is_array($column) || !isset($template->c[$column])) {
                continue;
            }
            [$kind, $length] = self::kind($template->c[$column]->c[1]);
            $bytes = match (explode('_', $collations[$column] ?? '', 2)[0]) {
                'utf8mb4'         => 4,
                'utf8mb3', 'utf8' => 3,
                default           => 1,
            };
            if (($kind === 'string') && ($length * $bytes > 1000)) {
                $wanted->c[$position] = [$column, (string) intdiv(1000, $bytes)];
            }
        }
        return $wanted;
    }

    /** @return string[] the column names of the index */
    private static function indexColumns(AA_MbI $index): array
    {
        return array_map(static fn($col) => is_array($col) ? $col[0] : $col, $index->c);
    }

    /** the name of an index from $indexes with the same columns and kind as $index, '' when none
     * @param AA_MbI[] $indexes */
    private static function sameIndex(AA_MbI $index, array $indexes): string
    {
        foreach ($indexes as $name => $candidate) {
            $renamed    = clone $candidate;
            $renamed->n = $index->n;
            if (($index->s !== 'P') && ($renamed->getCreateSql() === $index->getCreateSql())) {
                return (string) $name;
            }
        }
        return '';
    }
}
