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
        $this->plan = null;
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
        $sql  = "ALTER TABLE `$tablename` " . implode(', ', $clauses);
        $size = (int) DB_AA::select1('size', 'SELECT DATA_LENGTH + INDEX_LENGTH AS size FROM information_schema.TABLES', [['TABLE_SCHEMA', constant('DB_NAME')], ['TABLE_NAME', $tablename]]);
        if ($size <= self::LOCKED_SIZE) {
            return $this->run($tablename, $sql);
        }
        $error = $this->strictly("$sql, LOCK=NONE");
        if (($error === '') || self::busy($error)) {
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
        $this->plan = [];
        foreach ($template->tables as $tablename => $table) {
            $current = $live->tables[$tablename] ?? null;
            $changes = $current ? self::changes($table, $current, $collations[$tablename] ?? []) : ['alter' => [], 'extra' => [], 'kept' => []];
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

    /** The ALTER TABLE clauses that bring the live table to the template. Never changed: the live columns and
     *  indexes the template does not know (a column may hold data, an index may serve a slow query of the
     *  site), a column the template would narrow (a shorter text, a smaller number, signed for unsigned, a
     *  number for a text) and the template indexes over such a column. A changed column keeps its collation
     *  and comment; an index the template has under another name is renamed - never kept twice. The order of the columns
     *  stays: AA names the columns it writes, and moving one would rebuild the table for nothing.
     * @param array<string, string> $collations live column => collation
     * @return array{alter: string[], extra: string[], kept: string[]}
     */
    public static function changes(AA_MbT $template, AA_MbT $live, array $collations = []): array
    {
        $modify = $add = $kept = $kept_columns = [];
        foreach ($template->c as $name => $column) {
            if (!isset($live->c[$name])) {
                $add[] = 'ADD COLUMN ' . trim($column->getCreateSql());
            } elseif (AA_Metabase::comparable($column->getCreateSql()) !== AA_Metabase::comparable($live->c[$name]->getCreateSql())) {
                if (self::holds($column->c[1], $live->c[$name]->c[1])) {
                    $modify[] = 'MODIFY COLUMN ' . self::definition($column, $live->c[$name], $collations[$name] ?? '');
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
            if ($current?->getCreateSql() === $index->getCreateSql()) {
                continue;
            }
            if (array_intersect(self::indexColumns($index), $kept_columns)) {
                $kept[] = "index $name";
                continue;
            }
            if (!$current && ($same = self::sameIndex($index, $unknown))) {
                unset($unknown[$same]);
                $renamed[] = "RENAME INDEX `$same` TO `$name`";
                continue;
            }
            if ($current) {
                $drop[] = ($current->s === 'P') ? 'DROP PRIMARY KEY' : "DROP INDEX `$name`";
            }
            $add_index[] = 'ADD ' . $index->getCreateSql();
        }
        $extra = array_values(array_diff(array_keys($live->c), array_keys($template->c)));
        foreach (array_keys($unknown) as $name) {
            $extra[] = "index $name";
        }
        return ['alter' => array_merge($drop, $modify, $add, $renamed, $add_index), 'extra' => $extra, 'kept' => $kept];
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

    /** Can the template type hold every value of the live type? A number and a non-number never; a text,
     *  a date and an enum are changed into each other (strict mode stops a value that does not fit). */
    private static function holds(string $template, string $live): bool
    {
        [$t_kind, $t_size, $t_unsigned] = self::kind($template);
        [$l_kind, $l_size, $l_unsigned] = self::kind($live);
        $numeric = ['int', 'float', 'decimal'];
        if ($t_kind !== $l_kind) {
            return !in_array($t_kind, $numeric, true) && !in_array($l_kind, $numeric, true);
        }
        if ($t_unsigned && !$l_unsigned) {
            return false;                                       // the negative numbers
        }
        return match ($t_kind) {
            // an unsigned number has one bit more than the signed one of the same size
            'int'     => ($t_size * 8 - ($t_unsigned ? 0 : 1)) >= ($l_size * 8 - ($l_unsigned ? 0 : 1)),
            'decimal' => ($t_size[0] - $t_size[1] >= $l_size[0] - $l_size[1]) && ($t_size[1] >= $l_size[1]),
            'float'   => ($t_size[0] >= $l_size[0]) && ($t_size[1] >= $l_size[1]) && ($t_size[2] >= $l_size[2]),
            default   => $t_size >= $l_size,
        };
    }

    /** [kind, size, unsigned] of a column type: int (bytes), float ([bytes, integer digits, decimals]), decimal ([digits, decimals]),
     *  string (characters or bytes it takes), other (0) */
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
        return ['other', 0, false];
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
