<?php

namespace AA\IO\DB;

use AA;
// use AA_Log;
use PDO;
use PDOException;
use PDOStatement;

class DB_PDO
{
    /* public: connection parameters */
    protected $Host       = "";
    protected $Port       = "";
    protected $Database   = "";
    protected $User       = "";
    protected $Password   = "";
    protected $Server     = "mysql";
    protected $charset    = '';
    protected $collation  = '';
    protected $persistent = '';

    /* public: configuration parameters */
    protected $Auto_Free = 0;     ## Set to 1 for automatic mysqli_free_result()
    protected $Debug = 0;     ## Set to 1 for debugging messages.

    /* public: result array and current row number */
    protected $Record   = [];
    protected $Row;

    protected $Halt_On_Error = 'yes'; // "yes" (halt with message), "no" (ignore errors quietly), "report" (ignore error, but spit a warning)
    protected $Errno  = 0;
    protected $Error  = "";
    protected $ErrCode = 0;

    /* public: this is an api revision, not a CVS revision. */
    protected $type = "pdo";
    protected $revision = "1.0";

    /* private: link and query handles */
    protected PDO|false          $dbh = false;

    protected PDOStatement|false $sth = false;

    public static int $_instances_no = 0;
    public int $dbg_id;


    public function quote($str): false|string
    {
        return !$this->connect() ? false : $this->dbh->quote($str);
    }

    /** Quotes table name or column name */
    public function quote_identifier(string $str): string
    {
        $char = ($this->Server === 'mysql') ? '`' : '"';   // in original was used just '"' character (which is SQL standard and could be OK)
        return $char . implode("$char.$char", explode(".", $str)) . $char;
    }

    /** just for debug purposes */
    public function getType()
    {
        return get_class_name($this);
    }

    /**
     * @deprecated Used just as compatibility layer for external scripts calling $db->Record['name']
     * @param $name
     */
    public function __get($name)
    {
        if ($name === 'Record') {
            return $this->record();
        }
        return null;
    }



    /**
     * DB_PDO constructor.
     * @param array $connection
     */
    public function __construct(array $connection)
    {

        // in reality, we switched all the databases to PDO
        if (in_array($connection['type'], ['db_mysql', 'db_mysqli'])) {
            $connection['type'] = 'db_pdo:mysql';
        }
        [$type,$driver] = explode(':', $connection['type'] . ':');  // $driver used in  PDO: db_pdo:mysql, ...
        $connection['driver'] = $driver;

        $this->Database   = $connection['database']   ?? '';
        $this->Host       = $connection['host']       ?? '';
        $this->User       = $connection['user']       ?? '';
        $this->Password   = $connection['password']   ?? '';
        $this->Port       = $connection['port']       ?? '';
        $this->Server     = $connection['driver']     ?? 'mysql';
        $this->charset    = $connection['charset']    ?? '';
        $this->collation  = $connection['collation']  ?? '';
        $this->persistent = $connection['persistent'] ?? false;
        $this->dbg_id     = ++self::$_instances_no;
    }

    public function beginTransaction(): bool
    {
        if (!$this->connect()) {
            return false;
        }
        return $this->dbh->beginTransaction();
    }

    public function prepare($query = ""): false|PDOStatement
    {
        if (!$this->connect()) {
            return false;
        }
        $this->sth = $this->dbh->prepare($query);
        return $this->sth;
    }

    public function commit(): bool
    {
        return $this->dbh->commit();
    }

    public function rollback(): bool
    {
        return $this->dbh->rollback();
    }

    public function execute(array $param = []): bool
    {
        if (!$this->connect()) {
            return false;
        }
        if (!array_filter(array_keys($param), 'is_string')) { // this is NOT an associative array, so we cannot bindValue or bindParams
            if (!$status = $this->sth->execute($param)) {
                if ($this->Debug) {
                    var_dump($param);
                }
                $this->halt(array_search(null, $param) ? "Null Parameter" : "Cannot Execute");
            }
        } else {
            foreach ($param as $key => &$val) {
                if ($val === null) {
                    $this->sth->bindValue($key, $val, PDO::PARAM_NULL);
                } else {
                    $this->sth->bindParam($key, $val);
                }
            }
            if (!$status = $this->sth->execute()) {
                $this->halt(array_search(null, $param) ? "Null Parameter" : "Cannot Execute");
            }
        }
        // $this->getErrorInfo();
        return $status;
    }

    public function last_insert_id(): false|string
    {
        return $this->dbh->lastInsertId();
    }

    /* public: connection management */
    public function connect(): PDO|false
    {
        $Database = $this->Database;
        $Host     = $this->Host;
        $User     = $this->User;
        $Password = $this->Password;
        $Port     = $this->Port;

        /* establish connection, select database */
        if (!$this->dbh) {

            try {
                switch ($this->Server) {
                    case "sqlite":
                        if ($this->Debug) {
                            echo "Opening $Database<br>\n";
                        }
                        $this->dbh = new PDO("$this->Server:$Database", null, null, [PDO::ATTR_PERSISTENT => true]);
                        break;
                    case "pgsql":
                        $this->dbh = new PDO("$this->Server:host=$Host;dbname=$Database;port=5432", $User);
                        // $this->dbh = new PDO("$this->Server:host=$Host;dbname=$Database;port=5432", $User, $Password);
                        break;
                    default:
                        $Port = $Port ? ";port=$Port" : '';
                        if ($this->Debug) {
                            echo "Connecting to $Host$Port as $User<br>\n";
                        }
                        $dsn = "$this->Server:host=$Host$Port;dbname=$Database";
                        if ($this->charset) {
                            $dsn .= ";charset=$this->charset";
                        }
                        $options = [];
                        if ($this->persistent) {
                            $options[PDO::ATTR_PERSISTENT] = true;
                        }
                        if ($this->collation && $this->charset) {
                            $key = (int) (defined('Pdo\Mysql::ATTR_INIT_COMMAND') ? constant('Pdo\Mysql::ATTR_INIT_COMMAND') : constant('PDO::MYSQL_ATTR_INIT_COMMAND'));
                            $options[$key] = "SET NAMES '$this->charset' COLLATE '$this->collation'";
                        }
                        //huhl($dsn, $User, $Password, $options);
                        $this->dbh = new PDO($dsn, $User, $Password, $options);
                }
            } catch (PDOException $e) {
                $this->halt($e->getMessage());
            }
            if (!$this->dbh) {
                $this->halt("connect($Host$Port, $User, \$Password) failed.");


                return 0;
            }

            switch ($this->Server) {
                case "mysql":
                    $this->dbh->query("SET SESSION sql_mode='ANSI'");
                    break;
                case "sqlite":
                    if ($this->Debug) {
                        echo "Creating sqlite functions...";
                    }
                    $this->dbh->sqliteCreateFunction('now', function (): string {
                        return date('Y-m-d H:i:s');
                    }, 0);
                    $this->dbh->sqliteCreateFunction('year', function (): string {
                        return date('Y');
                    }, 0);
                    $this->dbh->sqliteCreateFunction('month', function (): string {
                        return date('n');
                    }, 0);
                    $this->dbh->sqliteCreateFunction('day', function (): string {
                        return date('j');
                    }, 0);
                    $this->dbh->sqliteCreateFunction('hour', function (): string {
                        return date('G');
                    }, 0);
                    $this->dbh->sqliteCreateFunction('minute', function (): string {
                        return date('i');
                    }, 0);
                    $this->dbh->sqliteCreateFunction('second', function (): string {
                        return date('s');
                    }, 0);
                    $this->dbh->sqliteCreateFunction('unix_timestamp', function ($string = '') {
                        return strtotime($string);
                    });
                    $this->dbh->sqliteCreateFunction('compress', 'gzcompress');
                    $this->dbh->sqliteCreateFunction('uncompress', 'gzuncompress');
                    $this->dbh->sqliteCreateFunction('length', 'strlen', 1);
                    $this->dbh->sqliteCreateFunction('substring', function ($string, $from, $length): string {
                        return substr($string, $from - 1, $length);
                    }, 3);
                    $this->dbh->sqliteCreateFunction('md5', 'md5', 1);
                    $this->dbh->sqliteCreateFunction('pow', 'pow', 2);
                    $this->dbh->sqliteCreateFunction('rand', '_sql_rand');
                    $this->dbh->sqliteCreateFunction('if', function ($condition, $expr1, $expr2 = null) {
                        return $condition ? $expr1 : $expr2;
                    });
                    $this->dbh->sqliteCreateFunction('concat', function (...$args): string {
                        return implode('', $args);
                    });
                    $this->dbh->sqliteCreateFunction('concat_ws', function (...$args): string {
                        $sep = array_shift($args);
                        return implode($sep, $args);
                    });
                    $this->dbh->sqliteCreateFunction('greatest', '_sql_greatest');
                    $this->dbh->sqliteCreateFunction('least', '_sql_least');
                    $this->dbh->sqliteCreateFunction('regexp', function ($pattern, $value) {
                        #mb_internal_encoding('UTF-8'); // multibyte character encoding
                        #mb_regex_encoding('UTF-8');    // should be set at the application level
                        return (mb_ereg($pattern, $value)) ? 1 : 0;
                    });
                    break;
            }

        }
        return $this->dbh;
    }

    /* public: discard the query result */
    public function free(): void
    {
        $this->sth->closeCursor();
    }

    public function query($Query_String): bool
    {
        AA::$debug & 16 && AA::$dbg->tracestart('Query', $Query_String);
        $ret = $this->query_internal($Query_String);
        AA::$debug & 16 && AA::$dbg->traceend('Query', (stripos($Query_String, "SELECT") === 0) ? $this->num_rows() : $this->affected_rows());
        return $ret;
    }

    /* public: perform a query */
    private function query_internal($Query_String): bool
    {

        /* No empty queries, please, since PHP4 chokes on them. */
        if ($Query_String == "") {
            /* The empty query string is passed on from the constructor,
             * when calling the class without a query, e.g. in situations
             * like these: '$db = new DB_Sql_Subclass;'
             */
            if ($this->Debug) {
                echo "no query specified.";
            }
            return false;
        }

        if (!$this->connect()) {
            if ($this->Debug) {
                echo "Complain Again!!";
            }
            return false; /* we already complained in connect() about that. */
        }

        if ($this->Debug) {
            echo "Still Connected, ";
            printf("Debug: query = %s<br>\n", $Query_String);
        }

        try {
            $this->sth = $this->dbh->query($Query_String, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->halt("SQL Query Failed: $Query_String " . $e->getMessage());
            return false;
        }
        #if ($this->sth->rowCount()) {
        #    $result = $this->sth->setFetchMode(PDO::FETCH_BOTH); /wasn't required, must be default.
        #}
        $this->Row = 0;
        // $this->getErrorInfo();  // commented out - rewritten in halt() - HM - 2026-09-01
        if (!$this->sth) {
            $this->halt("SQL Query Failed: " . $Query_String);
            return false;
        }

        return true;
    }

    /** Get the FIRST column of the pending result as a flat array.
     *
     *  Taken by position, not by name: every query that reaches here selects its
     *  id column first - the sole caller is GetZidsFromSQL() (include/searchlib.php),
     *  and the one multi-column query, AA_Object::querySet()
     *  (include/statestore.php), appends its extra sort column *after* the id.
     *  Any new query fed to this method MUST keep that order.
     *
     *  Fetching by position also avoids building one associative array per row -
     *  measured 507 MB for a 1.2M row id list - only to discard it again.
     */
    public function fetch_first_column(): array
    {
        if (!$this->sth) {
            $this->halt("fetch_first_column called with no query pending.");
            return [];
        }
        return $this->sth->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    //    /* public get all records */
    //    function fetch_all($cast=false,$num=false) {
    //        if (!$this->sth) {
    //            $this->halt("fetchAll called with no query pending.");
    //            return 0;
    //        }
    //        if ($cast) $meta = $this->metadata();
    //        if ($this->sth->columnCount()>1) {
    //            // Array of records (rows)
    //            if ($cast) {
    //                # perhaps this should be changed: see...
    //                # https://phpdelusions.net/pdo#returntypes
    //                # https://phpdelusions.net/pdo#emulation
    //                foreach ($this->sth->fetchAll(PDO::FETCH_NUM) as $row) {
    //                    $count=0;
    //                    foreach($row as $key=>$val) {
    //                        if ($num) $name=$count; else
    //                            $name = $meta[$key]["name"];
    //                        $type = $meta[$key]["type"];
    //                        if ( preg_match("/int$/i",$type)) $type = "int";
    //                        switch($type) {
    //                            case "date":
    //                            case "datetime":
    //                                $casted_row[$name] = (strtotime($val)/86400)+25569.4167;
    //                                break;
    //                            case "int":
    //                            case "long":
    //                            case "longlong":
    //                            case "newdecimal":
    //                            case "float":
    //                            case "double":
    //                                $casted_row[$name] = $val + 0;
    //                                break;
    //                            default:
    //                                $casted_row[$name] = $val;
    //                        }
    //                        $count++;
    //                    }
    //                    $data[] = $casted_row;
    //                }
    //            } else
    //                $data = $this->sth->fetchAll(PDO::FETCH_ASSOC);
    //            if (!$data) $data = array();  // if no records return empty array/
    //        } else {
    //            // Array of values
    //            foreach ($this->sth->fetchAll(PDO::FETCH_NUM) as $row) $data[]=$row[0];
    //        }
    //        return $data;  // Always return an array,
    //    }

    /* public: walk result set */
    public function next_record(): bool
    {
        if (!$this->sth) {
            $this->halt("next_record called with no query pending.");
            return false;
        }

        if ($this->Debug) {
            echo ".";
        }

        $this->Record = $this->sth->fetch(PDO::FETCH_ASSOC);
        ++$this->Row;
        // $this->getErrorInfo();  // commented out - rewritten in halt() - HM - 2026-09-01

        $stat = is_array($this->Record);

        if (!$stat && $this->Auto_Free) {
            $this->free();
        }
        return $stat;
    }

    /* public: table locking */
    public function lock($table, $mode = "write"): bool
    {
        $query = "lock tables ";
        if (is_array($table)) {
            foreach ($table as $key => $value) {
                if (!is_int($key)) {
                    // texts key are "read", "read local", "write", "low priority write"
                    $query .= "$value $key, ";
                } else {
                    $query .= "$value $mode, ";
                }
            }
            $query = substr($query, 0, -2);
        } else {
            $query .= "`$table` $mode";
        }
        if (!$this->query($query)) {
            $this->halt("lock() failed.");
            return false;
        }
        return true;
    }

    public function unlock(): bool
    {
        if (!$this->query("unlock tables")) {
            $this->halt("unlock() failed.");
        }
        return true;
    }

    /* public: evaluate the result (size, width) */
    public function affected_rows(): int
    {
        return $this->sth->rowCount();
    }

    public function num_rows(): int
    {
        return $this->sth->rowCount();
    }

    public function num_fields(): int
    {
        return $this->sth->columnCount();
    }

    public function f($Name)
    {
        return $this->Record[$Name] ?? null;
    }

    /** Value of the FIRST column of the current record - the id, by the same
     *  invariant fetch_first_column() documents. next_record() fills $Record
     *  with PDO::FETCH_ASSOC, which keeps the SELECT order, so the first entry
     *  is the first selected column.
     */
    public function first_col()
    {
        return $this->Record ? reset($this->Record) : null;
    }

    /** based on my test mysqli contains string[], while db_pdo:mysql returns columns in type based on table column type */
    public function record($Name = null)
    {
        return  $Name ? $this->Record[$Name] : $this->Record;
    }

    /* public: return table metadata */
    public function metadata(string $table = ""): array|false
    {
        $count = 0;
        $res = [];
        $stmt = false;
        $hasdef = false;
        $attnums = [];

        // if no $table specified, assume that we are working with a query
        // result
        if (!$table) {
            $stmt = $this->sth;
            if (!$stmt) {
                $this->halt("No table specified.");
                return false;
            }
            ##################################################################################
            ## This section causes it to prefer pdo metadata functions over DESCRIBE table.
            ## Unfortunately, it's broken, as it can't tell the difference between TEXT & BLOB
            ## If your database server does not support DESCRIBE, you may turn this back on.
            ## Also does not return default values or auto_increment data.
            ## pdo_type returned was always 2 (PDO::PARAM_STR) in my testing.
            ## PHP MANUAL STATES "getColumnMeta() Warning: This function is EXPERIMENTAL.
            ## The behaviour of this function, its name, and surrounding documentation may
            ## change without notice in a future release of PHP.
            ## This function should be used at your own risk."
            /*
                } else {
                  $q = "SELECT * FROM ".$this->qi($table)." LIMIT 1";
                  $stmt = $this->dbh->query($q);
            */
            ##################################################################################
        }
        if ($stmt) {
            if (!@$stmt->columnCount()) {
                $row = $stmt->fetchAll();
            }
            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $res[$i] = $stmt->getColumnMeta($i);
                if (array_key_exists("native_type", $res[$i]) && !array_key_exists("type", $res[$i])) {
                    $res[$i]["type"] = strtolower($res[$i]["native_type"]);
                }
                if (!array_key_exists("type", $res[$i])) {
                    switch (@$res[$i]["pdo_type"]) {
                        case PDO::PARAM_INT:
                            $res[$i]["type"] = "int";
                            break;    # 1
                        case PDO::PARAM_STR:
                            $res[$i]["type"] = "string";
                            break;    # 2
                        case PDO::PARAM_LOB:
                            $res[$i]["type"] = "blob";
                            break;    # 3
                    }
                }
                if (in_array("blob", $res[$i]["flags"]) && $res[$i]["pdo_type"] == PDO::PARAM_STR) {
                    $res[$i]["type"] = "text";
                }
                $res[$i]["default"] = "";        // pdo metadata does not return default value.
                $res[$i]["extra"] = "";        // or auto_increment?
                $res[$i]["comment"] = "";
                $res[$i]["key"] = "";
                if (in_array("primary_key", $res[$i]["flags"], true)) {
                    $res[$i]["key"] = "PRI";
                }
                if (in_array("multiple_key", $res[$i]["flags"], true)) {
                    $res[$i]["key"] = "MUL";
                }
                if (in_array("unique_key", $res[$i]["flags"], true)) {
                    $res[$i]["key"] = "UNI";
                }
                $res[$i]["null"] = in_array("not_null", $res[$i]["flags"], true) ? "NO" : "YES";
                $len = @$res[$i]["len"] ? $res[$i]["len"] : $res[$i]["length"];
                $res[$i]["chars"] = $len / 3;    ## this / 3 might only be for utf-8;
                $count++;
            }
        } else {
            $this->connect();
            $q = match ($this->Server) {
                "pgsql" => "SELECT a.attname AS name, t.typname AS type, a.attlen AS len, a.atttypmod, a.attnotnull AS notnull, a.atthasdef, a.attnum
                              FROM pg_class c,  pg_attribute a, pg_type t
                              WHERE relkind in ('r', 'v') AND (c.relname='$table' or c.relname = lower('$table')) AND a.attname not like '....%' AND a.attnum > 0 AND a.atttypid = t.oid AND a.attrelid = c.oid ORDER BY a.attnum",
                "sqlite" => "PRAGMA table_info($table)",
                "mssql" => "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, COLLATION_NAME, CHARACTER_SET_NAME, IS_NULLABLE, COLUMN_DEFAULT
                              FROM INFORMATION_SCHEMA.COLUMNS
                              WHERE TABLE_SCHEMA=" . $this->quote($this->Database) . " AND TABLE_NAME=" . $this->quote($table),
                "mysql" => "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, COLLATION_NAME, EXTRA, COLUMN_COMMENT, COLUMN_KEY, PRIVILEGES, COLUMN_TYPE, CHARACTER_SET_NAME, IS_NULLABLE, COLUMN_DEFAULT
                              FROM INFORMATION_SCHEMA.COLUMNS
                              WHERE TABLE_SCHEMA=" . $this->quote($this->Database) . " AND TABLE_NAME=" . $this->quote($table),
                default => "DESCRIBE " . $this->quote_identifier($table),
            };
            try {
                if (!$stmt = $this->dbh->query($q)) {
                    $this->halt("Metadata query failed.");
                    return false;
                }
            } catch (PDOException $e) {
                $this->halt($e->getMessage());
            }
            #  $res["num_fields"] = $stmt->rowCount();
            $i = 0;
            #echo "<h1>$table</h1>";
            foreach ($stmt as $row) {
                #var_dump($row);
                $res[$i]["table"] = $table;
                if (array_key_exists('DATA_TYPE', $row)) {
                    $type = $row['DATA_TYPE'];
                }    // information_schema sql standard
                if (array_key_exists('IS_NULLABLE', $row)) {
                    $null = $row['IS_NULLABLE'];
                }  // information_schema sql standard
                if (array_key_exists('COLUMN_TYPE', $row)) {
                    $type = $row['COLUMN_TYPE'];
                }    // information_schema mysql
                if (array_key_exists('type', $row)) {
                    $type = $row['type'];
                }        // sqlite & pgsql
                if (array_key_exists('Type', $row)) {
                    $type = $row['Type'];
                }        // mysql describe
                $fattr = "";
                $pos = strpos($type, "(");
                if ($pos > 0) {
                    $ftype = substr($type, 0, $pos);
                    $fsize = substr($type, $pos + 1);
                    $pos = strpos($fsize, ") ");
                    if ($pos > 0) {
                        $fattr = substr($fsize, $pos + 2, strlen($fsize) - 2 - $pos);
                        $fsize = substr($fsize, 0, $pos);
                    } else {
                        $fsize = substr($fsize, 0, $pos - 1);
                    }
                } else {
                    $fsize = "";
                    $ftype = $type;
                }
                $res[$i]["chars"] = $fsize;
                if (array_key_exists("atttypmod", $row) && $row["atttypmod"] > 0) {
                    $res[$i]["chars"] = $row["atttypmod"];
                } //pgsql
                if (array_key_exists("attnum", $row)) {
                    $attnums[$row["attnum"]] = $i;
                } //pgsql
                $res[$i]["len"] = $fsize;
                $res[$i]["type"] = $ftype;
                $res[$i]["attr"] = $fattr;   /* eg unsigned */
                if (array_key_exists("notnull", $row)) {
                    $null = $row["notnull"] ? "NO" : "YES";
                    // sqlite & pgsql
                } elseif (array_key_exists("Null", $row)) {
                    $null = $row["Null"];        // MySQL DESCRIBE
                }
                $res[$i]["null"] = $null;
                if (array_key_exists("atthasdef", $row) && $row["atthasdef"]) {
                    $hasdef = true;
                }
                // mysql
                if (array_key_exists('Field', $row)) {
                    $res[$i]['name'] = $row['Field'];
                }
                if (array_key_exists("Default", $row)) {
                    $res[$i]["default"] = $row["Default"];
                }
                if (array_key_exists("Key", $row)) {
                    $res[$i]["key"] = $row["Key"];
                }
                $res[$i]["extra"] = array_key_exists('Extra', $row) ? $row["Extra"] : "";
                // sqlite
                if (array_key_exists('name', $row)) {
                    $res[$i]['name'] = $row['name'];
                }
                if (array_key_exists("pk", $row)) {
                    $res[$i]["key"] = $row["pk"];
                }
                if (array_key_exists("dflt_value", $row)) {
                    $res[$i]["default"] = $row["dflt_value"];
                }
                // Information_Schema values...
                if (array_key_exists('COLUMN_NAME', $row)) {
                    $res[$i]['name'] = $row['COLUMN_NAME'];
                }
                if (array_key_exists('DATA_TYPE', $row)) {
                    $res[$i]['type'] = $row['DATA_TYPE'];
                }
                if (array_key_exists('CHARACTER_MAXIMUM_LENGTH', $row)) {
                    $res[$i]['len'] = $row['CHARACTER_MAXIMUM_LENGTH'];
                }
                if (array_key_exists('COLUMN_DEFAULT', $row)) {
                    $res[$i]['default'] = $row['COLUMN_DEFAULT'];
                }
                if (array_key_exists('COLLATION_NAME', $row)) {
                    $res[$i]['collation'] = $row['COLLATION_NAME'];
                }
                if (array_key_exists('CHARACTER_SET_NAME', $row)) {
                    $res[$i]['charset'] = $row['CHARACTER_SET_NAME'];
                }
                if (array_key_exists('EXTRA', $row)) {
                    $res[$i]['extra'] = $row['EXTRA'];
                }            // mysql only
                if (array_key_exists('COLUMN_KEY', $row)) {
                    $res[$i]['key'] = $row['COLUMN_KEY'];
                }            // mysql only
                if (array_key_exists('PRIVILEGES', $row)) {
                    $res[$i]['priv'] = $row['PRIVILEGES'];
                }    // mysql only
                if (array_key_exists('COLUMN_COMMENT', $row)) {
                    $res[$i]['comment'] = $row['COLUMN_COMMENT'];
                } // mysql only
                $i++;
            }
            if ($hasdef) { # postgres
                $stmt = $this->dbh->query("SELECT d.adnum as num,  d.adsrc as def from pg_attrdef d,  pg_class c
                   WHERE d.adrelid=c.oid and c.relname='$table' order by d.adnum");
                foreach ($stmt as $row) {
                    $num = $attnums[$row["num"]];
                    $def = $row["def"];
                    $res[$num]["default"] = $def;
                    if (str_starts_with($def, 'nextval(')) {
                        $res[$num]["extra"] = "auto_increment";
                    }
                }
            }
        }
        return $res;
    }

    /* public: find available table names */
    public function table_names(): array
    {
        $this->connect();
        $i = 0;
        $res = [];
        $q = match ($this->Server) {
            "sybase" => "SELECT name FROM sysobjects WHERE type='U'",
            "sqlite" => "SELECT name FROM sqlite_master WHERE type = 'table'",
            "mysql"  => "SHOW TABLES",
            "pgsql"  => "SELECT c.relname FROM pg_catalog.pg_class c LEFT JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                          WHERE c.relkind IN ('r','') AND n.nspname NOT IN ('pg_catalog', 'pg_toast') AND pg_catalog.pg_table_is_visible(c.oid);",
            "oracle" => "SELECT table_name FROM tabs",
            default  => "SELECT table_name FROM information_schema.tables "
                          . "WHERE table_type = 'BASE TABLE' AND table_schema NOT IN ('pg_catalog', 'information_schema')",
        };
        if ($stmt = $this->dbh->query($q)) {
            foreach ($stmt as $row) {
                $res[$i]["table_name"] = $row[0];
                $res[$i]["tablespace_name"] = $this->Database;
                $res[$i]["database"] = $this->Database;
                $i++;
            }
        }
        return $res;
    }

    public function primary_key($table)
    {
        $this->connect();
        switch ($this->Server) {
            case "pgsql":
                $q = "SELECT 	a.attname AS column_name, ic.relname AS index_name,
            i.indisunique AS unique_key, i.indisprimary AS primary_key
            FROM pg_class bc,  pg_class ic,  pg_index i,  pg_attribute a
        WHERE bc.oid = i.indrelid AND ic.oid = i.indexrelid
        AND i.indisprimary
        AND (i.indkey[0] = a.attnum OR i.indkey[1] = a.attnum OR i.indkey[2] = a.attnum OR i.indkey[3] = a.attnum
          OR i.indkey[4] = a.attnum OR i.indkey[5] = a.attnum OR i.indkey[6] = a.attnum OR i.indkey[7] = a.attnum)
        AND a.attrelid = bc.oid AND bc.relname = " . $this->quote($table);
                break;
            case "sqlite":
                return "rowid";
            default:
                $q = "SELECT column_name FROM information_schema.columns "
                    . "WHERE column_key = 'PRI' AND table_name=" . $this->quote($table);
        }
        if ($stmt = $this->dbh->query($q)) {
            return $stmt->fetchColumn();
        }
        return false;
    }

    private function getErrorInfo(): void
    {
        if (is_object($this->dbh)) {
            $e = $this->dbh->errorInfo();
            $this->Error = $e[2];
            $this->Errno = $e[1];
            $this->ErrCode = $e[0];
        }
    }

    /* private: error handling */
    public function halt($msg): void
    {
        $this->getErrorInfo();
        if ($this->Debug) {
            echo $this->Server . "(pdo) error(" . $this->ErrCode . "):" . $this->Errno . " " . $this->Error . $msg;
        }
        if (function_exists("EventLog")) {
            EventLog($this->Server . "(pdo) error(" . $this->ErrCode . "):" . $this->Errno . " " . $this->Error, $msg, "Error");
        }
        if ($this->get_halt() === 'no') {
            return;
        }
        // if you want to display special error page, then define DB_ERROR_PAGE
        // in config.php file. You can use following variables on that page
        // (in case you will use php page):
        // $_POST['Err'], $_POST['ErrMsg'] and $_POST['Msg'] variables
        // --- Disabled -- posting them to the error page grabbed its content into a
        // variable and printed it on the current page. It worked, but an external css
        // linked from that page was then not found, which is unexpected. So, you can't
        // use the variables on that page. Honza, 2007-12-05
        if (defined('DB_ERROR_PAGE') && $this->get_halt() === "yes") {
            ob_end_clean();
            // sending variables disabled - see the comment above
            if (headers_sent()) {
                echo sprintf('<script>window.location = "%s";</script>', DB_ERROR_PAGE);
            } else {
                header("Location: " . DB_ERROR_PAGE, true, 302);
            }
        }

        $this->haltmsg($msg);
        if ($this->get_halt() !== 'report') {
            die("Session halted.");
        }
    }

    public function haltmsg($msg): void
    {
        printf("<p><b>Database error:</b> %s<br>\n", safe($msg));
        printf("<b>%s Error</b>: %s (%s)</p>\n", get_class_name($this), $this->Errno, $this->Error);
        // AA::$dbg->log(AA_Log::backtrack());
    }

    public function set_halt(string $mode): bool
    {
        if (!in_array($mode, ['yes','report','no'])) {
            return false;
        }
        $this->Halt_On_Error = $mode;
        return true;
    }

    public function get_halt(): string
    {
        return $this->Halt_On_Error;
    }


    public function query_halt(string $Query_String, string $mode): bool
    {
        $store = $this->Halt_On_Error;
        $this->set_halt($mode);
        $ret = $this->query($Query_String);
        $this->set_halt($store);
        return $ret;
    }
}
