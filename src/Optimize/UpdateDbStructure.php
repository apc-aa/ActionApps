<?php

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;
use AA\Util\Difftext;
use AA_MbT;
use AA_Metabase;
use RuntimeException;

/** Brings the database structure to the AA template (include/metabase_schema.php): creates a missing
 *  table and changes a differing one by ALTER TABLE in place. The server keeps the rows and the writes
 *  that come meanwhile (InnoDB even lets them run); copying the table into a new one would lose them.
 *  A column or table the template does not know is only reported - it may hold data.
 */
class UpdateDbStructure extends \AA\Optimize\AbstractOptimize
{
    /** a table of up to so many rows is changed even when it has to be locked meanwhile - seconds */
    private const LOCKED_ROWS = 100000;

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
        return _m('Compares all the tables with the AA template of the database structure. Repair creates the missing tables and changes the differing ones in place (ALTER TABLE) - the data and the writes during the change stay. Columns and tables the template does not know are only listed. On a big table the change takes minutes - run it when the traffic is low.');
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

    /** Test function
     * @return string - a message
     */
    public function test(): bool
    {
        $different = false;
        foreach ($this->plan() as $tablename => $plan) {
            if ($plan['action'] === 'unknown') {
                $this->message(_m('Table %1 is not in the template - you can delete it manually.', [safe($tablename)]));
            } elseif ($plan['action'] === 'create') {
                $this->message(_m('Table %1 is missing.', [safe($tablename)]));
            } elseif ($plan['action'] === 'alter') {
                $this->message(_m('Table %1 differs: %2', [safe($tablename), safe(implode(', ', $plan['alter']))]));
                $this->message(Difftext::renderHtml($plan['live'], $plan['template']));
            }
            if ($plan['extra']) {
                $this->message(_m('Table %1 has columns or indexes the template does not know - delete them manually, if nothing uses them: %2', [safe($tablename), safe(implode(', ', $plan['extra']))]));
            }
            if ($plan['wider']) {
                $this->message(_m('Table %1 keeps columns wider than the template: %2', [safe($tablename), safe(implode(', ', $plan['wider']))]));
            }
            $different = $different || ($plan['action'] !== 'none') || $plan['extra'];
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
            if ($plan['extra']) {
                $this->message(_m('Table %1 has columns or indexes the template does not know - left as they are: %2', [safe($tablename), safe(implode(', ', $plan['extra']))]));
            }
            if ($plan['wider']) {
                $this->message(_m('Table %1 keeps columns wider than the template: %2', [safe($tablename), safe(implode(', ', $plan['wider']))]));
            }
            if ($plan['action'] === 'create') {
                $ok = $this->run($tablename, $this->templateMetabase()->getCreateSql($tablename)) && $ok;
            } elseif ($plan['action'] === 'alter') {
                $ok = $this->alter($tablename, $plan['alter']) && $ok;
            }
        }
        return $ok;
    }

    /** A big table is changed only without locking it (LOCK=NONE), as InnoDB can do it for indexes, NOT NULL,
     *  a new column or a longer varchar - another type of a column or a MyISAM table would stop the writes for
     *  minutes, so the server refuses and the ALTER is listed for a manual run when the traffic is low. A small
     *  table is changed in any way - its lock lasts seconds. A failed ALTER leaves the table as it was. */
    private function alter(string $tablename, array $clauses): bool
    {
        $sql  = "ALTER TABLE `$tablename` " . implode(', ', $clauses);
        $rows = (int) DB_AA::select1('TABLE_ROWS', 'SELECT TABLE_ROWS FROM information_schema.TABLES', [['TABLE_SCHEMA', constant('DB_NAME')], ['TABLE_NAME', $tablename]]);
        if ($rows <= self::LOCKED_ROWS) {
            return $this->run($tablename, $sql);
        }
        if ($this->run($tablename, "$sql, LOCK=NONE", false)) {
            return true;
        }
        $this->message(_m('Table %1 (%2 rows) not changed: the change needs the table locked, or a value does not fit the new definition. Run it when the traffic is low: %3', [safe($tablename), $rows, safe("$sql;")]));
        return false;
    }

    private function run(string $tablename, string $sql, bool $report = true): bool
    {
        try {
            $this->strictly($sql);
            $this->message(_m('%1 done.', [safe($tablename)]));
            return true;
        } catch (RuntimeException) {
            if ($report) {
                $this->message(_m('Table %1 not changed - the database refused the change.', [safe($tablename)]));
            }
            return false;
        }
    }

    /** The statement in strict SQL mode, so a value that does not fit the new definition (a NULL for NOT NULL,
     *  a too long text) stops it instead of being changed. The mode of the connection is set back - it may be
     *  persistent and serve the next request. */
    private function strictly(string $sql): void
    {
        $this->query("SET @aa_sql_mode = @@SESSION.sql_mode, SESSION sql_mode = 'STRICT_ALL_TABLES'");
        try {
            $this->query($sql);
        } finally {
            $this->query('SET SESSION sql_mode = @aa_sql_mode');
        }
    }

    /** What each table needs: action none|alter|create|unknown (not in the template)
     * @return array<string, array{action: string, alter: string[], extra: string[], wider: string[], live: string, template: string}>
     */
    private function plan(): array
    {
        $template = $this->templateMetabase();
        $live     = $this->liveMetabase();
        $plan     = [];
        foreach ($template->tables as $tablename => $table) {
            $current = $live->tables[$tablename] ?? null;
            $changes = $current ? self::changes($table, $current) : ['alter' => [], 'extra' => [], 'wider' => []];
            $plan[$tablename] = [
                'action'   => $current ? ($changes['alter'] ? 'alter' : 'none') : 'create',
                'alter'    => $changes['alter'],
                'extra'    => $changes['extra'],
                'wider'    => $changes['wider'],
                'live'     => $current ? $current->getCompareSql() : '',
                'template' => $table->getCompareSql(),
            ];
        }
        foreach (array_diff($live->getTableNames(), $template->getTableNames()) as $tablename) {
            $plan[$tablename] = ['action' => 'unknown', 'alter' => [], 'extra' => [], 'wider' => [], 'live' => '', 'template' => ''];
        }
        return $plan;
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

    /** The ALTER TABLE clauses that bring the live table to the template; the live columns and indexes the
     *  template does not know and the live columns that hold longer texts than the template allows are never
     *  changed - a column may hold data, an index may be there for a slow query of the site. The order of the columns stays: AA names the columns it writes, and moving one
     *  would rebuild the table for nothing.
     * @return array{alter: string[], extra: string[], wider: string[]}
     */
    public static function changes(AA_MbT $template, AA_MbT $live): array
    {
        $drop = $modify = $add = $add_index = $wider = $extra = [];
        foreach ($live->i as $name => $index) {
            if (!isset($template->i[$name])) {
                $extra[] = "index $name";
            } elseif ($template->i[$name]->getCreateSql() !== $index->getCreateSql()) {
                $drop[] = ($index->s === 'P') ? 'DROP PRIMARY KEY' : "DROP INDEX `$name`";
            }
        }
        foreach ($template->c as $name => $column) {
            if (!isset($live->c[$name])) {
                $add[] = 'ADD COLUMN ' . trim($column->getCreateSql());
            } elseif (AA_Metabase::comparable($column->getCreateSql()) !== AA_Metabase::comparable($live->c[$name]->getCreateSql())) {
                if (self::length($column->c[1]) < self::length($live->c[$name]->c[1])) {
                    $wider[] = "$name {$live->c[$name]->c[1]}";
                } else {
                    $modify[] = 'MODIFY COLUMN ' . trim($column->getCreateSql());
                }
            }
        }
        foreach ($template->i as $name => $index) {
            if (($live->i[$name] ?? null)?->getCreateSql() !== $index->getCreateSql()) {
                $add_index[] = 'ADD ' . $index->getCreateSql();
            }
        }
        return [
            'alter' => array_merge($drop, $modify, $add, $add_index),
            'extra' => array_merge(array_values(array_diff(array_keys($live->c), array_keys($template->c))), $extra),
            'wider' => $wider,
        ];
    }

    /** How long a text the column type takes (characters or bytes); 0 for the other types */
    private static function length(string $type): int
    {
        if (preg_match('/^(?:var)?(?:char|binary)\((\d+)\)/', $type, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/^(tiny|medium|long)?(?:text|blob)$/', $type, $m)) {
            return ['tiny' => 255, '' => 65535, 'medium' => 16777215, 'long' => 4294967295][$m[1] ?? ''];
        }
        return 0;
    }
}
