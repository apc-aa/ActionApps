<?php

namespace AA\IO\DB;

use AA;
use AA_Http;

/** proxy class for other db engines */
final class DB_AA
{
    /** handle owning the open transaction, or null - @see transaction() */
    private static ?DB_PDO $tx_db = null;

    /** is the transaction's handle currently checked out by getDB()? */
    private static bool $tx_db_out = false;

    /** a shutdown handler of atShutdown() runs - abandonTransaction() is allowed */
    private static bool $at_shutdown = false;

    /* private: constructor - NO INSTANCES! */
    private function __construct(array $connection) {}

    /** Runs $work as one database transaction.
     *
     *  Everything inside must run on the ONE connection that owns the transaction:
     *  with AA_USE_NON_PERSISTENT_CONNECT every DB_PDO is its own connection, so a
     *  statement on a second handle would silently fall outside. getDB() therefore
     *  hands out this handle (@see transactionDb()) and refuses to hand out a second.
     *
     *  Nesting is not emulated with savepoints - an inner call joins the outer
     *  transaction, so an inner failure rolls the whole block back.
     *
     * @usage DB_AA::transaction(static function () { ...several writes... });
     * @return mixed whatever $work returned
     */
    public static function transaction(callable $work): mixed
    {
        if (self::$tx_db) {
            return $work();                     // already inside - join the outer transaction
        }
        $db = getDB();
        if (!$db->beginTransaction()) {
            freeDB($db);
            return $work();                     // no transaction available - behave as before
        }
        self::$tx_db     = $db;
        self::$tx_db_out = false;
        try {
            $ret = $work();
            if (!$db->commit()) {
                // PDO runs in silent error mode, so a failed commit only returns false.
                // The writes are gone, so the caller must not be told the block succeeded.
                throw new \RuntimeException('DB_AA::transaction(): commit failed, the block was not stored');
            }
            return $ret;
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable) {
                // transaction already gone (implicit commit by DDL, lost connection) - keep the original error
            }
            throw $e;
        } finally {
            self::$tx_db     = null;   // unpin first - only then freeDB() returns the handle to the pool
            self::$tx_db_out = false;
            freeDB($db);
        }
    }

    /** true inside transaction() - a caller that must own its transaction (a commit of its own) refuses to join */
    public static function inTransaction(): bool
    {
        return self::$tx_db !== null;
    }

    /** Registers $handler as a shutdown function; while it runs, abandonTransaction() is allowed.
     *  @return \Closure the registered function - it returns what $handler returns (tests call it to
     *                  play the end of a request) */
    public static function atShutdown(\Closure $handler): \Closure
    {
        $registered = static function () use ($handler): mixed {
            $was              = self::$at_shutdown;
            self::$at_shutdown = true;
            try {
                return $handler();
            } finally {
                self::$at_shutdown = $was;
            }
        };
        register_shutdown_function($registered);
        return $registered;
    }

    /** @internal for a shutdown handler registered by atShutdown() only (LogicException elsewhere): the
     *  request died inside transaction() (a fatal error, exit()) and its catch/finally never ran - the
     *  transaction is still open and its handle pinned, so getDB() would hand out that handle (or refuse a
     *  second one). Rolls the transaction back and unpins the handle; it does not go back to the pool.
     *  A rollback() that fails: a ROLLBACK statement, then the handle's connection dropped - a non-persistent
     *  one closes and the server rolls back and frees the row locks. A persistent one stays open in PHP: when
     *  the ROLLBACK statement failed too, its transaction may still be open, and the next use of the
     *  connection (with persistent connections every DB_PDO shares it - the caller's cleanup too) joins it.
     *  @return bool false = that case: what runs after it on the connection may be rolled back at the end -
     *               the caller must not trust its cleanup. true = rolled back (or nothing was open). */
    public static function abandonTransaction(): bool
    {
        if (!self::$at_shutdown) {
            throw new \LogicException('DB_AA::abandonTransaction() is for a shutdown handler registered by DB_AA::atShutdown()');
        }
        if (!($db = self::$tx_db)) {
            return true;
        }
        self::$tx_db     = null;
        self::$tx_db_out = false;
        try {
            if ($db->rollback()) {
                return true;
            }
        } catch (\Throwable) {
            // PDO may not know of the transaction (or the connection is gone) - the statement below still may
        }
        try {
            $rolled_back = $db->query_halt('ROLLBACK', 'no');
        } catch (\Throwable) {
            $rolled_back = false;
        }
        $db->disconnect();
        return $rolled_back || !$db->isPersistent();
    }

    /** Handle getDB() must use, or null when no transaction runs. Marks it as checked
     *  out; freeDB() checks it back in (@see releaseTransactionDb()).
     * @throws \RuntimeException when a second handle is asked for inside a transaction
     */
    public static function transactionDb(): ?DB_PDO
    {
        if (!self::$tx_db) {
            return null;
        }
        if (self::$tx_db_out) {
            throw new \RuntimeException('DB_AA::transaction(): a second database handle was requested inside a transaction - its statements would fall outside it. Rework the block so it uses one handle at a time.');
        }
        self::$tx_db_out = true;
        return self::$tx_db;
    }

    /** Counterpart for freeDB(): true when $db belongs to the open transaction
     *  and must not go back to the $spareDBs pool. */
    public static function releaseTransactionDb($db): bool
    {
        if (!self::$tx_db || $db !== self::$tx_db) {
            return false;
        }
        self::$tx_db_out = false;
        return true;
    }

    /** just for debug purposes */
    public static function getDbType(): string
    {
        $db = getDB();
        $type = $db->getType();
        freeDB($db);
        return $type;
    }

    /**
     *  used as: $sdata = DB_AA::select1('', 'SELECT * FROM `slice`', [['id',$long_id, 'l']]));
     *           $chid  = DB_AA::select1('id', "SELECT id FROM `change` WHERE ...");
     *                    DB_AA::select1('last_edit', "SELECT last_edit FROM `item`", $where, ['last_edit-'])
     *                    DB_AA::select1('cnt', "SELECT count(*) as cnt FROM content, item", [['content.item_id','item.id', 'j'], ['item.slice_id', $slice_id, 'l'], ['content.field_id', $fld['id']], ['content.flag', 64, 'set']]);  // now use DB_AA::count()
     *          ['ENGINE'=>$engine, 'TABLE_COLLATION' => $collation] = DB_AA::select1(['ENGINE','TABLE_COLLATION'],'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES', [['TABLE_NAME',$engine_template_table]]);
     * @param string|array $column
     * @param string $query
     * @param array $where
     * @param array $order
     * @param int $row
     */
    public static function select1($column, $query, $where = [], $order = [], int $row = 1)
    {
        $db = getDB();
        $query .= $where ? ' ' . self::makeWhere($where) : '';
        $query .= $order ? ' ' . self::makeOrder($order) : '';

        $offset = ($row > 1) ? (($row - 1) . ',') : '';

        // AA::$debug & 8 && AA::$dbg->log("$query LIMIT $offset 1");

        $db->query("$query LIMIT $offset 1");
        $ret = false;
        if ($db->next_record()) {
            if (!is_array($column)) {
                $ret = $db->record($column);    // empty($column) ? $db->record() : $db->record($column);   -- it is done automatically with record()
            } elseif (!$column) {
                $ret = $db->record();
            } else {
                $key = key($column);
                $val = array_intersect_key($db->record(), array_flip($column));
                $ret = ctype_digit((string) $key) ? $val : [$db->record($key) => $val];
            }
        }
        freeDB($db);
        return $ret;
    }

    /**
     *  first parameter describes the desired output
     *  written with speed in mind - so all the loops are condition free
     *  used as: $chid = DB_AA::select('id', 'SELECT id FROM `change` WHERE ...');                   -> [id1, id2, ...]
     *                   DB_AA::select('',   'SELECT id, other FROM `change` WHERE ...');            -> [id1, id2, ...]
     *                   DB_AA::select([], 'SELECT id FROM `change`');                          -> [[id=>id1], [id=>id2], ...]
     *                   DB_AA::select([], 'SELECT id,other FROM `change`');                    -> [[id=>id1,other=>other1], [id=>id2,other=>other2], ...]
     *                   DB_AA::select(['id'=>1], 'SELECT id FROM `change`');                   -> [[id1=>1], [id2=>1], ...]
     *                   DB_AA::select(['id'=>'other'], 'SELECT id,other FROM `change`');       -> [[id1=>other1], [id2=>other2], ...]
     *   $slice_owners = DB_AA::select(['unpackid'=>'name'], 'SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `slice_owner` ORDER BY `name`');
     *                   DB_AA::select(['id'=>'+other'], 'SELECT id,other FROM `change`');      -> [[id1=>[other1a,other1b], [id2=>[other2]], ...]  // good for multivalues
     *                   DB_AA::select(['id'=>[]], 'SELECT id,other FROM `change`');       -> [[id1=>[id=>id1,other=>other1]], [id2=>[id=>id2,other=>other2]], ...]
     *                   DB_AA::select(['id'=>['other']], 'SELECT id,other FROM `change`');  -> [[id1=>[other=>other1]], [id2=>[other=>other2]], ...]
     *                   DB_AA::select('', 'SELECT source_id FROM relation', [['destination_id', $item_id, 'l'], ['flag', REL_FLAG_FEED, 'i']]);
     *
     * @param              $column
     * @param              $query
     * @param  array|null  $where
     * @param  array|null  $order
     * @param  array|null  $group
     * @param  int         $rows
     *
     * @return array
     */
    public static function select($column, $query, $where = null, $order = null, ?array $group = null, int $rows = 0): array
    {
        $db = getDB();
        $query .= is_array($where) ? ' ' . self::makeWhere($where) : '';
        $query .= $group ? ' ' . self::makeGroup($group) : '';
        $query .= is_array($order) ? ' ' . self::makeOrder($order) : '';
        $query .= ($rows > 0) ? ' LIMIT 0,' . $rows : '';

        $db->query($query);

        $ret = [];
        if (!is_array($column)) {
            if (empty($column)) {
                while ($db->next_record()) {
                    $arr = $db->record();
                    $ret[] = reset($arr);
                }
            } else {
                while ($db->next_record()) {
                    $ret[] = $db->record($column);
                }
            }
        } elseif (empty($column)) {
            while ($db->next_record()) {
                $ret[] = $db->record();
            }
        } else {
            $key = key($column);
            $values = reset($column);
            if (ctype_digit((string) $key) || empty($key)) {
                if (!is_array($values)) {
                    while ($db->next_record()) {
                        $ret[] = $db->record($values);
                    }
                } else {
                    $col_keys = array_flip($values);
                    while ($db->next_record()) {
                        $ret[] = array_intersect_key($db->record(), $col_keys);
                    }
                }
            } elseif (empty($values)) {
                while ($db->next_record()) {
                    $ret[$db->record($key)] = $db->record();
                }
            } elseif (!is_array($values)) {
                if (is_string($values)) {
                    if ($values[0] === '+') {                              // array('id'=>'+other')
                        $values = substr($values, 1);
                        while ($db->next_record()) {
                            $ret[$db->record($key)][] = $db->record($values);
                        }
                    } else {                                              // array('id'=>'other')  - we do not expect multivalues
                        while ($db->next_record()) {
                            $ret[$db->record($key)] = $db->record($values);
                        }
                    }
                } else {
                    while ($db->next_record()) {                          // array('id'=>1)
                        $ret[$db->record($key)] = $values;
                    }
                }
            } else {
                $col_keys = array_flip($values);
                while ($db->next_record()) {
                    $ret[$db->record($key)] = array_intersect_key($db->record(), $col_keys);
                }
            }
        }
        freeDB($db);
        return $ret;
    }

    /** used as: $count = DB_AA::count('content', [['item_id', $item_id, 'l'], ['field_id', $field_id, 'i']]);
     *           $count = DB_AA::count('profile')
     *           $numeric_rows = DB_AA::count(['content', 'item'], [['content.item_id', 'item.id', 'j'], ['item.slice_id', AA::$module_id, 'l'], ['content.field_id', $fld['id']], ['content.flag', 64, 'unset']]);
     *
     * @param string       $table   - single or multiple - like 'content' or 'content, item' with JOIN in $where
     * @param  array|null  $where
     *
     * @return int
     */
    public static function count(string|array $table, ?array $where = null): int
    {
        return self::select1('cnt', "SELECT count(*) as cnt FROM " . self::makeTables($table), $where);
    }


    /** static
     *  used as: DB_AA::sql("INSERT SELECT id FROM `change` WHERE ...");
     * @return false|int - number of affected rows (useful for INSERT/UPDATE/DELETE) or false on problem
    */
    public static function sql($query, $where = null): false|int
    {
        $db = getDB();
        $query .= is_array($where) ? ' ' . self::makeWhere($where) : '';
        $ret = $db->query($query) ? $db->affected_rows() : false;
        freeDB($db);
        return $ret;
    }

    /** used as: DB_AA::delete('perms', [['object_type', $object_type], ['objectid', $objectID], ['flag', REL_FLAG_FEED, 'i']]);
     * @param string $table
     * @param array $where
     * @return false|int
     */
    public static function delete($table, $where = null): false|int
    {
        return self::sql("DELETE FROM `$table` ", $where);
    }

    /** LOW PRIORITY version of DB_AA::delete()
     * @param string $table
     * @param array $where
     * @return false|int
     */
    public static function delete_low_priority($table, $where = null): false|int
    {
        return self::sql("DELETE LOW_PRIORITY FROM `$table` ", $where);
    }

    /** check if Table exists
     * @param string $table
     * @return bool
     */
    public static function exists_table(string $table): bool
    {
        return (false !== self::select1('', 'SELECT table_schema FROM information_schema.tables', [['table_schema', DB_NAME], ['table_name', $table]]));
    }

    /** check if Table column exists
     * @param string $table
     * @param string $col
     * @return bool
     */
    public static function exists_column(string $table, string $col): bool
    {
        return self::count('information_schema.columns', [['table_schema', DB_NAME], ['table_name', $table], ['column_name', $col]]) > 0;
    }

    /** creates cols string for INSERT or UPDATE
     * @param string[] $varlist
     * @return string
     */
    protected static function makeCols(array $varlist): string
    {
        $delim = '';
        $cols  = '';
        foreach ($varlist as $vardef) {
            // $vardef is array(varname, value, type)
            [$name, $value, $type] = $vardef;
            $part  = match ($type) {
                "i" => (int) $value,
                "l" => xpack_id($value),
                "q" => "'$value'",
                default => "'" . addslashes($value) . "'",
            };
            $cols .= "$delim $name = $part";
            $delim = " ,";
        }
        return $cols;
    }

    /** used as: DB_AA::update('slice', [['javascript', $_POST['javascript']]], [['id', $slice_id, 'l']]);
     *
     * @param string  $table
     * @param  array  $varlist
     * @param  array  $where
     *
     * @return false|int - number of affected rows or false on a problem
     */
    public static function update(string $table, array $varlist, array $where)
    {
        $cols = self::makeCols($varlist);
        $wh   = self::makeWhere($where);
        $ret  = false;
        if ($cols && $wh) {
            $db = getDB();
            $db->query("UPDATE `$table` SET $cols $wh");
            $ret = $db->affected_rows();
            freeDB($db);
        }
        return $ret;
    }

    /* DB_AA::insert('slice', [['javascript', $_POST['javascript']]]);
     * @deprecated - consider use AA::Metabase()->doInsert('table', ['id'=>$id,'vars'=>$vars,'time'=>time()]);
     */

    /** used as: DB_AA::test('perms', [['object_type', $object_type], ['objectid', $objectID], ['flag', REL_FLAG_FEED, 'i']]);
     *           DB_AA::test(['content','item'], [['content.item_id', 'item.id', 'j'],['item.slice_id', $slice_id, 'l']]);
     */
    public static function test(string|array $table, $where): bool
    {
        return (false !== self::select1('', "SELECT " . ($where[0][0]) . " FROM " . self::makeTables($table), $where));
    }

    private static function makeTables(string|array $tables): string
    {
        if (is_array($tables)) {
            // information_schema.columns should not be backticked
            return implode(',', array_map(static fn($t) => str_contains($t, '.') ? $t : self::quoteIdentifier($t), $tables));
        }
        return str_contains($tables, '.') ? $tables : self::quoteIdentifier($tables);
    }

    /** makeWhere function
     *  [[field_name, value, type], ...]
     *     type                         used operator    value              example
     *     s        - string (default)  =                single or array    ['field_id',$id]
     *     i        - integer           =                single or array    ['id', $task_id, 'i']
     *     l        - longid            =                single or array    ['item_id', $ids_arr, 'l']
     *     set      - flag is set       fld & val = val  single             ['flag', REL_FLAG_FEED, 'set'] - good for bitfields
     *     unset    - flag is not set   fld & val = 0    single             ['flag', 64, 'unset'] - good for bitfields
     *     j        - JOIN              =                single             ['slice.id', 'module.id', 'j'] - for table join  (SELECT ... FROM `slice`, `module`', '', [['slice.id', 'module.id', 'j'], a..
     *     >        - integer           >                single             ['date', 1478547854, '>']
     *     <        - integer           <                single             ['date', 1478547854, '<']
     *     >=       - integer           >=               single             ['date', 1478547854, '>=']
     *     <=       - integer           <=               single             ['date', 1478547854, '<=']
     *     <>       - integer           <>               single             ['date', 1478547854, '<>']
     *     i<>      - integer           <>               single             ['date', 1478547854, '<>']
     *     !=       - integer           <>               single             ['date', 1478547854, '!=']   // the same as above - "<>"
     *     s<>      - string            <>               single or array    ['name', $name, 's<>']
     *     l<>      - longid            <>               single             ['id', $slice_id, 'l<>']
     *     ISNULL   -                   IS NULL                             ['number', '', 'ISNULL']
     *     NOTNULL  -                   IS NOT NULL                         ['number', '', 'NOTNULL']
     *     LIKE     -                   LIKE             single             ['id', '\_%', 'LIKE']
     *     RLIKE    -                   LIKE             single             ['selector', $item_id, 'RLIKE']
     *     BEGIN    -                   LIKE             single or array    ['selector', $item_id, 'BEGIN'] // similar to RLIKE, but the value must begin with exact match of the string (including "%" and "_" characters)
     *     NOT BEGIN-                   LIKE             single or array    ['selector', $item_id, 'BEGIN']
     *     CONTAIN  -                   LIKE             single             ['selector', $item_id, 'CONTAIN'] // similar to LIKE, but the value must contain exact match of the string (including "%" and "_" characters)
     *     NOT LIKE -                   NOT LIKE         single             ['id', '\_%', 'NOT LIKE']
     *     FILLED   -                   > ''             single             ['field3', '', 'FILLED']     // not null, not empty, not any whitespace character(s)
     *
     * @return string
     */
    public static function makeWhere($varlist): string
    {
        $where = [];
        foreach ($varlist as $vardef) {
            // $vardef is array(varname, value, type) - the type is optional
            [$name, $value, $type] = array_pad($vardef, 3, null);
            $type = (string) $type;   // just normalize to RLIKE and LIKE (in this context we do not use expression parsing as we do for RLIKE in public search)
            if (in_array($type, ['>', '<', '<=', '>=', '<>', 'i<>', '!='])) {
                $operator = $type === 'i<>' ? '<>' : $type;
                $type = 'i';
            } elseif ($type === 'LIKE' || $type === 'NOT LIKE') {
                $where[] = "$name $type '" . addcslashes($value, "'") . "'";    // see field LIKE '\_%'
                continue;
            } elseif ($type === 'RLIKE') {
                $where[] = "$name LIKE " . qquote($value . '%');
                continue;
            } elseif ($type === 'BEGIN') {
                if (is_array($value)) {
                    // join conditions by OR
                    $where[] = '(' . implode(' OR ', array_map(fn($v) => "$name LIKE '" . addcslashes(quote($v), "_%") . "%'", $value)) . ')';
                } else {
                    $where[] = "$name LIKE '" . addcslashes(quote($value), "_%") . "%'";
                }
                continue;
            } elseif ($type === 'NOT BEGIN') {
                if (is_array($value)) {
                    // join conditions by OR
                    $where[] = '(' . implode(' AND ', array_map(fn($v) => "$name NOT LIKE '" . addcslashes(quote($v), "_%") . "%'", $value)) . ')';
                } else {
                    $where[] = "$name NOT LIKE '" . addcslashes(quote($value), "_%") . "%'";
                }
                continue;
            } elseif ($type === 'CONTAIN') {
                $where[] = "$name LIKE '%" . addcslashes(quote($value), "_%") . "%'";
                continue;
            } elseif ($type === 's<>' || $type === 's!=') {
                if (is_array($value)) {
                    switch (count($value)) {
                        case 0:
                            // no condition
                            break;
                        case 1:
                            $where[] = "$name <> " . qquote(reset($value));
                            break;
                        default:
                            $where[] = "$name NOT IN (" . implode(',', array_map('qquote', $value)) . ")";
                    }
                } else {
                    $where[] = "$name <> " . qquote($value);
                }
                continue;
            } elseif ($type === 'l<>' || $type === 'l!=') {
                $where[] = "$name <> " . xpack_id($value);
                continue;
            } elseif ($type === 'FILLED') {
                $where[] = "$name > '' ";             // this is kind of trick for - not null, not empty, not any whitespace character(s) - thanks: https://stackoverflow.com/questions/1869264#42723975
                continue;
            } elseif ($type === 'ISNULL') {
                $where[] = "$name IS NULL ";
                continue;
            } elseif ($type === 'NOTNULL') {
                $where[] = "$name IS NOT NULL ";
                continue;
            } else {
                $operator = '=';
            }

            if (!is_array($value)) {
                switch ($type) {
                    case "i":
                        $where[] = "$name $operator " . (int) $value;
                        break;
                    case "l":
                        $where[] = "$name = " . xpack_id($value);
                        break;
                    case "j":
                        $where[] = "$name = " . quote($value);
                        break;
                    case "set":
                        $value = (int) $value;
                        $where[] = "(($name & $value) = $value)";
                        break;
                    case "unset":
                        $value = (int) $value;
                        $where[] = "(($name & $value) = 0)";
                        break;
                    default:
                        $where[] = "$name = " . qquote($value);
                }
            } else {
                $value = array_filter($value, static fn($v) => $v !== null);
                $arr = match ($type) {
                    "i"     => array_map('intval', $value),
                    "l"     => array_map('xpack_id', $value),
                    default => array_map('qquote', $value),
                };
                switch (count($arr)) {
                    case 0:
                        $where = ["2=1"];
                        break;
                    case 1:
                        $where[] = "$name $operator " . reset($arr);
                        break;
                    default:
                        $where[] = "$name IN (" . implode(',', $arr) . ")";
                }
            }
        }
        return count($where) ? "WHERE " . implode(' AND ', $where) : '';
    }

    /** generate order phrase
     * @param $orderarr  array of order fields - ['id','time-']
     * @return string
     */
    protected static function makeOrder($orderarr): string
    {
        $order = [];
        foreach ($orderarr as $sort) {
            $order[] = match (substr($sort, -1)) {
                '-' => substr($sort, 0, -1) . ' DESC',
                '+' => substr($sort, 0, -1),
                default => $sort,
            };
        }
        return $order ? 'ORDER BY ' . implode(',', $order) : '';
    }

    /** generate GROUP BY phrase
     * @param array $group  fields - ['id','time']
     * @return string
     */
    protected static function makeGroup(array $group): string
    {
        return 'GROUP BY ' . implode(',', $group);
    }

    public static function quoteIdentifier(string $str): string
    {
        $char = in_array(DB_TYPE, ['db_mysql','db_mysqli','db_pdo:mysql']) ? '`' : '"';   // in original was used just '"' character (which is SQL standard and could be OK), but using PDO with mysql needs '`' character
        return $char . implode("$char.$char", explode(".", $str)) . $char;
    }

}
