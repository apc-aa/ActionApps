<?php


namespace AA\IO\DB;

use AA;
use AA_Http;

/** proxy class for other db engines */
class DB_AA extends AbstractDB
{

    /** @var string DB connection - host  */
    protected $Host;
    /** @var string DB connection - database name  */
    protected $Database;
    /** @var string DB connection - username  */
    protected $User;
    /** @var string DB connection - password  */
    protected $Password;

    /** @var string database link  */
    protected $dbase;

    public static $_instances_no = 0;

    /* public: constructor */
    function __construct(array $connection) {
        self::$_instances_no++;
        switch (mb_strtolower($connection['type'])) {
            case 'db_msql':
                $this->dbase = new DB_mSQL($connection);
                break;
            case 'db_mssql':
                $this->dbase = new DB_MSSQL($connection);
                break;
            case 'db_oci8':
                $this->dbase = new DB_OCI8($connection);
                break;
            case 'db_odbc':
                $this->dbase = new DB_ODBC($connection);
                break;
            case 'db_oracle':
                $this->dbase = new DB_Oracle($connection);
                break;
            case 'db_pdo':
                $this->dbase = new DB_PDO($connection);
                break;
            case 'db_pgsql':
                $this->dbase = new DB_PgSQL($connection);
                break;
            case 'db_sybase':
                $this->dbase = new DB_Sybase($connection);
                break;
            case 'db_mysql':
            case 'db_mysqli':
            default:
                $this->dbase = new DB_MySQL($connection);
        }
    }


    /**
     * @deprecated Used just as compatibility layer for external scripts calling $db->Record['name']
     * @param $name
     * @return array|mixed
     */
    public function __get($name) {
        if ($name == 'Record') {
            return $this->record();
        }
    }


    /**
     *  used as: $sdata = DB_AA::select1('SELECT * FROM `slice`', '', [['id',$long_id, 'l']]));
     *           $chid  = DB_AA::select1("SELECT id FROM `change` WHERE ...", 'id');
     *                    DB_AA::select1("SELECT last_edit FROM `item`", 'last_edit', $where, ['last_edit-'])
     *                    DB_AA::select1("SELECT count(*) as cnt FROM content, item", 'cnt', [['content.item_id','item.id', 'j'], ['item.slice_id', $slice_id, 'l'], ['content.field_id', $fld['id']], ['content.flag', 64, 'set']]);
     * @param string|array $column
     * @param string $query
     * @param array $where
     * @param array $order
     * @param int $row
     * @return array|false|mixed
     */
    public static function select1($column, $query, $where = [], $order = [], int $row=1) {
        $db = getDB();
        $query .= $where ? ' ' . DB_AA::makeWhere($where) : '';
        $query .= $order ? ' ' . DB_AA::makeOrder($order) : '';

        $offset = ($row>1) ? (($row-1).',') : '';

        AA::$debug & 8 && AA::$dbg->log("$query LIMIT $offset 1");

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
                $ret = ctype_digit((string)$key) ? $val : [$db->record($key) => $val];
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
     * @param        $column
     * @param        $query
     * @param null   $where
     * @param null   $order
     * @return array
     */
    static function select($column, $query, $where = null, $order = null): array {
        $db = getDB();
        $query .= is_array($where) ? ' ' . DB_AA::makeWhere($where) : '';
        $query .= is_array($order) ? ' ' . DB_AA::makeOrder($order) : '';

        AA::$debug & 2 && AA::$dbg->log("$query");

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
            if (ctype_digit((string)$key) or empty($key)) {
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
                    if ($values[0] == '+') {                              // array('id'=>'+other')
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

    /** static
     *  used as: DB_AA::sql("INSERT SELECT id FROM `change` WHERE ...");
     * @return false|int - number of affected rows (useful for INSERT/UPDATE/DELETE) or false on problem
     **/
    static function sql($query, $where = null) {
        $db = getDB();
        $query .= is_array($where) ? ' ' . DB_AA::makeWhere($where) : '';
        $ret = $db->query($query) ? $db->affected_rows() : false;
        freeDB($db);
        return $ret;
    }

    /** used as: DB_AA::delete('perms', array(array('object_type', $object_type), array('objectid', $objectID), array('flag', REL_FLAG_FEED, 'i')));
     * @param string $table
     * @param array $where
     * @return false|int
     */
    static function delete($table, $where = null) {
        return DB_AA::sql("DELETE FROM `$table` ", $where);
    }

    /** LOW PRIORITY version of DB_AA::delete()
     * @param string $table
     * @param array $where
     * @return false|int
     */
    static function delete_low_priority($table, $where = null) {
        return DB_AA::sql("DELETE LOW_PRIORITY FROM `$table` ", $where);
    }

    /** check if Table exists
     * @param string $table
     * @return bool
     */
    static function exists_table($table) {
        return (false !== DB_AA::select1('', 'SELECT table_schema FROM information_schema.tables', [['table_schema', DB_NAME], ['table_name', $table]]));
    }

    /** creates cols string for INSERT or UPDATE
     * @param string[] $varlist
     * @return string
     */
    protected static function makeCols(array $varlist): string {
        $delim = '';
        $cols  = '';
        foreach ($varlist as $vardef) {
            // $vardef is array(varname, value, type)
            [$name, $value, $type] = $vardef;
            switch ($type) {
                case "i":
                    $part = (int)$value;
                    break;
                case "l":
                    $part = xpack_id($value);
                    break;
                case "q":
                    $part = "'$value'";
                    break;
                default:
                    $part = "'" . addslashes($value) . "'";
            }
            $cols .= "$delim $name = $part";
            $delim = " ,";
        }
        return $cols;
    }

    /** used as: DB_AA::update('slice', [['javascript', $_POST['javascript']]], [['id', $slice_id, 'l']]);
     * @param string $table
     * @param array $varlist
     * @param array $where
     * @return false|void
     */
    static function update(string $table, array $varlist, array $where) {
        $cols = DB_AA::makeCols($varlist);
        $wh   = DB_AA::makeWhere($where);
        $ret  = false;
        if ($cols and $wh) {
            $db = getDB();
            $db->query("UPDATE `$table` SET $cols $wh");
            $ret = $db->affected_rows();
            freeDB($db);
        }
        return $ret;
    }

    /** used as: DB_AA::insert('slice', [['javascript', $_POST['javascript']]]);
     * @param string $table
     * @param array  $varlist
     * @return false|int
     */
    static function insert(string $table, array $varlist) {
        $cols = DB_AA::makeCols($varlist);
        $ret  = false;
        if ($cols) {
            $db = getDB();
            $db->query("INSERT `$table` SET $cols");
            $ret = $db->affected_rows();
            freeDB($db);
        }
        return $ret;
    }

    /** used as: DB_AA::test('perms', [['object_type', $object_type], ['objectid', $objectID], ['flag', REL_FLAG_FEED, 'i']]);
     *           DB_AA::test(['content','item'], [['content.item_id', 'item.id', 'j'],['item.slice_id', $slice_id, 'l']]);
     * @return bool - true if the record exist
     */
    static function test($table, $where) {
        $tbl = is_array($table) ? '`' . join('`,`', $table) . '`' : "`$table`";
        return (false !== DB_AA::select1('', "SELECT " . ($where[0][0]) . " FROM $tbl ", $where));
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
     *     s<>      - string            <>               single             ['name', $name, 's<>']
     *     l<>      - longid            <>               single             ['id', $slice_id, 'l<>']
     *     !=       - integer           <>               single             ['date', 1478547854, '!=']   // the same as above - "<>"
     *     ISNULL   -                   IS NULL                             ['number', 'ISNULL']
     *     NOTNULL  -                   IS NOT NULL                         ['number', 'NOTNULL']
     *     LIKE     -                   LIKE             single             ['id', '\_%', 'LIKE']
     *     RLIKE    -                   LIKE             single             ['selector', $item_id, 'RLIKE']
     *     BEGIN    -                   LIKE             single             ['selector', $item_id, 'BEGIN'] // similar to RLIKE, but the value must begin with exact match of the string (including "%" and "_" characters)
     *     NOT LIKE -                   NOT LIKE         single             ['id', '\_%', 'NOT LIKE']
     *     FILLED   -                   > ''             single             ['field3', '', 'FILLED']     // not null, not empty, not any whitespace character(s)
     *
     * @param $tablename
     * @return string
     */
    static public function makeWhere($varlist) {
        $where = [];
        foreach ($varlist as $vardef) {
            // $vardef is array(varname, type, value)
            [$name, $value, $type] = $vardef;
            $type = (string)$type;   // just normalize to RLIKE and LIKE (in this context we do not use expresion parsing as we do for RLIKE in public search)
            if (in_array($type, ['>', '<', '<=', '>=', '<>', '!='])) {
                $operator = $type;
                $type = 'i';
            } elseif (($type == 'LIKE') or ($type == 'NOT LIKE')) {
                $where[] = "$name $type " . qquote($value);
                continue;
            } elseif ($type == 'RLIKE') {
                $where[] = "$name LIKE " . qquote($value . '%');
                continue;
            } elseif ($type == 'BEGIN') {
                $where[] = "$name LIKE '". addcslashes(quote($value),"_%") ."%'";
                continue;
            } elseif (($type == 's<>') or ($type == 's!=')) {
                if (is_array($value)) {
                    switch (count($value)) {
                        case 0:
                            // no condition
                            break;
                        case 1:
                            $where[] = "$name <> " . qquote(reset($value));
                            break;
                        default:
                            $where[] = "$name NOT IN (" . join(',', array_map('qquote', $value)) . ")";
                    }
                } else {
                    $where[] = "$name <> " . qquote($value);
                }
                continue;
            } elseif (($type == 'l<>') or ($type == 'l!=')) {
                $where[] = "$name <> " . xpack_id($value);
                continue;
            } elseif ($type == 'FILLED') {
                $where[] = "$name > '' ";             // this is kind of trick for - not null, not empty, not any whitespace character(s) - thanks: https://stackoverflow.com/questions/1869264#42723975
                continue;
            } elseif ((string)$value == 'ISNULL') {
                $where[] = "$name IS NULL ";
                continue;
            } elseif ((string)$value == 'NOTNULL') {
                $where[] = "$name IS NOT NULL ";
                continue;
            } else {
                $operator = '=';
            }

            if (!is_array($value)) {
                switch ($type) {
                    case "i":
                        $where[] = "$name $operator " . (int)$value;
                        break;
                    case "l":
                        $where[] = "$name = " . xpack_id($value);
                        break;
                    case "j":
                        $where[] = "$name = " . quote($value);
                        break;
                    case "set":
                        $value = (int)$value;
                        $where[] = "(($name & $value) = $value)";
                        break;
                    case "unset":
                        $value = (int)$value;
                        $where[] = "(($name & $value) = 0)";
                        break;
                    default:
                        $where[] = "$name = " . qquote($value);
                }
            } else {
                switch ($type) {
                    case "i":
                        $arr = array_map('intval', $value);
                        break;
                    case "l":
                        $arr = array_map('xpack_id', $value);
                        break;
                    default:
                        $arr = array_map('qquote', $value);
                }
                switch (count($arr)) {
                    case 0:
                        $where = ["2=1"];
                        break;
                    case 1:
                        $where[] = "$name $operator " . reset($arr);
                        break;
                    default:
                        $where[] = "$name IN (" . join(',', $arr) . ")";
                }
            }
        }
        return count($where) ? "WHERE " . join(' AND ', $where) : '';
    }

    /** generate order phrase
     * @param $orderarr  array of order fields - ['id','time-']
     * @return string
     */
    static protected function makeOrder($orderarr) {
        $order = [];
        foreach ($orderarr as $sort) {
            switch (substr($sort, -1)) {    // last character
                case '-':
                    $order[] = substr($sort, 0, -1) . ' DESC';
                    break;
                case '+':
                    $order[] = substr($sort, 0, -1);
                    break;
                default:
                    $order[] = $sort;
            }
        }
        return $order ? 'ORDER BY ' . join(',', $order) : '';
    }


    /** query function
     * @param $SQL
     * @return int
     */
    function query($SQL) {
        AA::$debug & 16 && AA::$dbg->tracestart('Query', $SQL);
        $ret = $this->dbase->query($SQL);
        AA::$debug & 16 && AA::$dbg->traceend('Query', (stripos($SQL, "SELECT") === 0) ? $this->dbase->num_rows() : $this->dbase->affected_rows());
        return $ret;
    }

    /** query_nohalt function - do not halt on database error
     * @param $SQL
     * @return int
     */
    function query_nohalt($SQL) {
        $store_halt = $this->Halt_On_Error;
        $this->Halt_On_Error = 'no';
        $retval = $this->dbase->query($SQL);
        $this->Halt_On_Error = $store_halt;
        return $retval;
    }

    /** halt function
     * @param $msg
     */
    function halt($msg) {
        if ($this->Halt_On_Error == "no") {
            return;
        }

        // if you want to display special error page, then define DB_ERROR_PAGE
        // in config.php3 file. You can use following variables on that page
        // (in case you will use php page):
        // $_POST['Err'], $_POST['ErrMsg'] and $_POST['Msg'] variables
        // --- Disabled -- AA_Http::go() for POST works in the way, that the
        // page content is grabbed into variable and printed on current page.
        // It works pretty well, but if you link the external css on that page,
        // then it is not found, which is unexpected behavior. So, you can't use
        // the variables on that page. Honza, 2007-12-05
        if (defined('DB_ERROR_PAGE') and ($this->Halt_On_Error == "yes")) {
            ob_end_clean();
            // AA_Http::go(DB_ERROR_PAGE, array('Err'=>$this->Errno, 'ErrMsg'=>$this->Error, 'Msg'=>$msg), 'POST', false);
            // sending variables disabled - see the comment above
            AA_Http::go(DB_ERROR_PAGE, null, 'GET', false);
            exit;
        }

        // If you do not want (for security reasons) display messages like:
        // "Database error: mysql_pconnect(mysqldbserver, aadbuser, $Password) failed."
        // then just define DB_ERROR_PAGE constant in your config.php3 file
        echo "\n<br><b>Database error:</b> $msg";
        echo "\n<br><b>Error Number:</b>: " . $this->Errno;
        echo "\n<br><b>Error Description:</b>: " . $this->Error;
        echo "\n<br>Please contact " . ERROR_REPORTING_EMAIL . " and report the exact error message.<br>\n";
        if ($this->Halt_On_Error == "yes") {
            die("Session halted.");
        }
    }

    function quote($string) { return $this->dbase->quote($string); }

    function connect() { return $this->dbase->connect(); }

    function connect_failed($message = '') { $this->dbase->connect_failed($message); }

    function free() { $this->dbase->free(); }

    public function next_record() { return $this->dbase->next_record(); }

    /* public: return all the results */
    function fetch_column($col) { return $this->dbase->fetch_column($col); }

    /* public: position in result set */
    function seek($pos = 0) { return $this->dbase->seek($pos); }

    /* public: table locking */
    function lock($table, $mode = "write") { return $this->dbase->lock($table, $mode); }

    function unlock() { return $this->dbase->unlock(); }

    function ping() { $this->dbase->ping(); }

    /** public: evaluate the result (size, width)
     * @return int
     */
    function affected_rows() { return $this->dbase->affected_rows(); }

    function num_rows() { return $this->dbase->unlock(); }

    function num_fields() { return $this->dbase->num_fields(); }

    function last_insert_id() { return $this->dbase->last_insert_id(); }

    function f($Name) { return $this->dbase->f($Name); }

    function record($Name = null) { return $this->dbase->record($Name); }

    function metadata($table = "", $full = false) { return $this->dbase->metadata($table, $full); }

    function table_names() { return $this->dbase->table_names(); }

    function haltmsg($msg) { $this->dbase->haltmsg($msg); }
}