<?php

/*
 * Oracle/OCI8 accessor based on Session Management for PHP3
 *
 * (C) Copyright 1999-2000 Stefan Sels phplib@sels.com
 *
 * based on DB_Oracle.php by Luis Francisco Gonzalez Hernandez
 * contains metadata() from DB_Oracle.php 1.10
 *
 * $Id$
 *
 */

namespace AA\IO\DB;

class DB_OCI8 extends AbstractDB {
    var $Debug = 0;
    var $sqoe = 1; // show query on error

    var $Host = "";
    var $Port = "1521";
    /* traditionally the full TNS name is placed in $Database; if having trouble with TNS resolution (and desiring a more legible configuration), place the host IP address in $Host and the Oracle SID in $Database as a shortcut - connect() will build a valid connection string using $full_connection_string */
    var $Database = "";
    var $User = "";
    var $Password = "";
    var $full_connection_string = "(DESCRIPTION=(ADDRESS_LIST=(ADDRESS=(PROTOCOL=TCP)(HOST=%s)(PORT=%s)))(CONNECT_DATA=(SID=%s)))";

    var $Link_ID = 0;
    var $Query_ID = 0;
    var $Row;
    var $Parse;
    var $ErrArray = [];
    var $autoCommit = 1; // Commit on successful query
    var $autoCount = 1; // Count num_rows on select

    var $share_connections = false;
    var $share_connection_name = "";
    // Defaults to the class name - set to another class name to share connections among different class extensions

    var $last_query_text = "";

    var $num_rows; // Used to store the total of rows returned by a SELECT statement.

    /**
     * DB_OCI8 constructor.
     * @param array $connection
     */
    function __construct(array $connection) {
        $this->Database = $connection['database'] ?? '';
        $this->Host     = $connection['host'] ?? '';
        $this->User     = $connection['user'] ?? '';
        $this->Password = $connection['password'] ?? '';
    }

    function connect() {
        if (0 == $this->Link_ID) {
            if ($this->Debug) {
                printf("<br>Connecting to $this->Database%s...<br>\n", (($this->Host) ? " ($this->Host)" : ""));
            }
            if ($this->share_connections) {
                if (!$this->share_connection_name) {
                    $this->share_connection_name = get_class($this) . "_Link_ID";
                } else {
                    $this->share_connection_name .= "_Link_ID";
                }
                global ${$this->share_connection_name};
                if (${$this->share_connection_name}) {
                    $this->Link_ID = ${$this->share_connection_name};
                    return true;
                }
            }
            $this->Link_ID = ociplogon($this->User, $this->Password, (($this->Host) ? sprintf($this->full_connection_string, $this->Host, $this->Port, $this->Database) : $this->Database));

            if (!$this->Link_ID) {
                $this->connect_failed();
                return false;
            }
            if ($this->share_connections) {
                ${$this->share_connection_name} = $this->Link_ID;
            }
            if ($this->Debug) {
                printf("<br>Obtained the Link_ID: $this->Link_ID<br>\n");
            }
        }
    }

    function connect_failed($message = '') {
        $this->Halt_On_Error = "yes";
        $this->halt(sprintf("connect ($this->User, \$Password, $this->Database%s) failed", (($this->Host) ? ", $this->Host" : "")));
    }

    function free() {
        if ($this->Parse) {
            if ($this->Debug) {
                printf("<br>Freeing the statement: $this->Parse<br>\n");
            }
            $result = @ocifreestatement($this->Parse);
            if (!$result) {
                $this->ErrArray = ocierror($this->Link_ID);
                if ($this->Debug) {
                    printf("<br>Error: %s<br>", $this->ErrArray["message"]);
                }
            }
        }
    }

    function query($Query_String) {
        $this->connect();
        $this->free();

        $this->Parse = ociparse($this->Link_ID, $Query_String);
        if (!$this->Parse) {
            $this->ErrArray = ocierror($this->Parse);
        } else {
            if ($this->autoCommit) {
                ociexecute($this->Parse, OCI_COMMIT_ON_SUCCESS);
            } else {
                ociexecute($this->Parse, OCI_DEFAULT);
            }
            if ($this->autoCount) {
                /* need to repeat the query to count the returned rows from a "select" statement. */
                if (preg_match("/SELECT/i", $Query_String)) {
                    /* On $this->num_rows I'm storing the returned rows of the query. */
                    $this->num_rows = ocifetchstatement($this->Parse, $aux);
                    ociexecute($this->Parse, OCI_DEFAULT);
                }
            }
            $this->ErrArray = ocierror($this->Parse);
        }

        $this->Row = 0;

        if ($this->Debug) {
            printf("Debug: query = %s<br>\n", $Query_String);
        }

        if ((1403 != $this->ErrArray["code"]) and (0 != $this->ErrArray["code"]) and $this->sqoe) {
            echo "<BR><FONT color=red><B>" . $this->ErrArray["message"] . "<BR>Query :\"$Query_String\"</B></FONT>";
        }
        $this->last_query_text = $Query_String;
        return $this->Parse;
    }

    function commit() {
        if ($this->autoCommit) {
            $this->halt("Nothing to commit because AUTO COMMIT is on.");
        }
        return (ocicommit($this->Link_ID));
    }

    function rollback() {
        if ($this->autoCommit) {
            $this->halt("Nothing to rollback because AUTO COMMIT is on.");
        }
        return (ocirollback($this->Link_ID));
    }

    /* This is requeried in some application. It emulates the mysql_insert_id() function. */
    /* Note: this function was copied from phpBB. */
    function insert_id($query_id = 0) {
        if (!$query_id) {
            $query_id = $this->Parse;
        }
        if ($query_id && $this->last_query_text != "") {
            if (preg_match("/^(INSERT{1}|^INSERT INTO{1})[[:space:]][\"]?([a-zA-Z0-9\_\-]+)[\"]?/i", $this->last_query_text[$query_id], $tablename)) {
                $query = "SELECT " . $tablename[2] . "_id_seq.CURRVAL FROM DUAL";
                $temp_q_id = @ociparse($this->db, $query);
                @ociexecute($temp_q_id, OCI_DEFAULT);
                @ocifetchinto($temp_q_id, $temp_result, OCI_ASSOC + OCI_RETURN_NULLS);
                if ($temp_result) {
                    return $temp_result["CURRVAL"];
                } else {
                    return false;
                }
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    function next_record() {
        /* IF clause added to prevent a error when tried to read an empty "$this->Parse". */
        if ($this->autoCount and ($this->num_rows() == $this->Row)) {
            return 0;
        }
        if (0 == ocifetchinto($this->Parse, $result, OCI_ASSOC + OCI_RETURN_NULLS)) {
            if ($this->Debug) {
                printf("<br>ID: %d, Rows: %d<br>\n", $this->Link_ID, $this->num_rows());
            }
            ++$this->Row;

            $errno = ocierror($this->Parse);
            if (1403 == $errno) { # 1043 means no more records found
                $this->ErrArray = false;
                $this->disconnect();
                $stat = 0;
            } else {
                $this->ErrArray = ocierror($this->Parse);
                if ($errno && ($this->Debug)) {
                    printf("<br>Error: %s, %s<br>",
                        $errno,
                        $this->ErrArray["message"]);
                }
                $stat = 0;
            }
        } else {
            $this->Record = [];
            $totalReg = ocinumcols($this->Parse);
            for ($ix = 1; $ix <= $totalReg; $ix++) {
                $col = strtoupper(ocicolumnname($this->Parse, $ix));
                $colreturn = strtolower($col);
                $this->Record[$colreturn] =
                    (is_object($result[$col])) ? $result[$col]->load() : $result[$col];
                if ($this->Debug) {
                    echo "<b>[$col]</b>:" . $result[$col] . "<br>\n";
                }
            }
            $stat = 1;
        }

        return $stat;
    }

    function seek($pos = 0) {
        $this->Row = $pos;
    }

    function metadata($table = "", $full = false) {
        $count = 0;
        $id = 0;
        $res = [];

        /*
         * Due to compatibility problems with Table we changed the behavior
         * of metadata();
         * depending on $full, metadata returns the following values:
         *
         * - full is false (default):
         * $result[]:
         *   [0]["table"]  table name
         *   [0]["name"]   field name
         *   [0]["type"]   field type
         *   [0]["len"]    field length
         *   [0]["flags"]  field flags ("NOT NULL", "INDEX")
         *   [0]["format"] precision and scale of number (eg. "10,2") or empty
         *   [0]["index"]  name of index (if has one)
         *   [0]["chars"]  number of chars (if any char-type)
         *
         * - full is true
         * $result[]:
         *   ["num_fields"] number of metadata records
         *   [0]["table"]  table name
         *   [0]["name"]   field name
         *   [0]["type"]   field type
         *   [0]["len"]    field length
         *   [0]["flags"]  field flags ("NOT NULL", "INDEX")
         *   [0]["format"] precision and scale of number (eg. "10,2") or empty
         *   [0]["index"]  name of index (if has one)
         *   [0]["chars"]  number of chars (if any char-type)
         *   ["meta"][field name]  index of field named "field name"
         *   The last one is used, if you have a field name, but no index.
         *   Test:  if (isset($result['meta']['myfield'])) {} ...
         */

        $this->connect();

        ## This is a RIGHT OUTER JOIN: "(+)", if you want to see, what
        ## this query results try the following:
        ## $table = new Table; $db = new my_DB_Sql; # you have to make
        ##                                          # your own class
        ## $table->show_results($db->query(see query vvvvvv))
        ##
        $this->query("SELECT T.table_name,T.column_name,T.data_type," .
            "T.data_length,T.data_precision,T.data_scale,T.nullable," .
            "T.char_col_decl_length,I.index_name" .
            " FROM ALL_TAB_COLUMNS T,ALL_IND_COLUMNS I" .
            " WHERE T.column_name=I.column_name (+)" .
            " AND T.table_name=I.table_name (+)" .
            " AND T.table_name=UPPER('$table') ORDER BY T.column_id");

        $i = 0;
        while ($this->next_record()) {
            $res[$i]["table"] = $this->Record["table_name"];
            $res[$i]["name"] = strtolower($this->Record["column_name"]);
            $res[$i]["type"] = $this->Record["data_type"];
            $res[$i]["len"] = $this->Record["data_length"];
            if ($this->Record["index_name"]) {
                $res[$i]["flags"] = "INDEX ";
            }
            $res[$i]["flags"] .= ($this->Record["nullable"] == 'N') ? '' : 'NOT NULL';
            $res[$i]["format"] = (int)$this->Record["data_precision"] . "," .
                (int)$this->Record["data_scale"];
            if ("0,0" == $res[$i]["format"]) {
                $res[$i]["format"] = '';
            }
            $res[$i]["index"] = $this->Record["index_name"];
            $res[$i]["chars"] = $this->Record["char_col_decl_length"];
            if ($full) {
                $j = $res[$i]["name"];
                $res["meta"][$j] = $i;
                $res["meta"][strtoupper($j)] = $i;
            }
            if ($full) {
                $res["meta"][$res[$i]["name"]] = $i;
            }
            $i++;
        }
        if ($full) {
            $res["num_fields"] = $i;
        }
#      $this->disconnect();
        return $res;
    }


    function affected_rows() {
        return ocirowcount($this->Parse);
    }

    function num_rows() {
        return $this->num_rows;
    }

    function num_fields() {
        return ocinumcols($this->Parse);
    }

    function f($Name) {
        return $this->Record[$Name];
    }

    function nextid($seqname) {
        $this->connect();

        $Query_ID = @ociparse($this->Link_ID, "SELECT $seqname.NEXTVAL FROM DUAL");

        if (!@ociexecute($Query_ID)) {
            $this->ErrArray = @ocierror($Query_ID);
            if (2289 == $this->ErrArray["code"]) {
                $Query_ID = ociparse($this->Link_ID, "CREATE SEQUENCE $seqname");
                if (!ociexecute($Query_ID)) {
                    $this->ErrArray = ocierror($Query_ID);
                    $this->halt("<BR> nextid() function - unable to create sequence<br>" . $this->ErrArray["message"]);
                } else {
                    $Query_ID = ociparse($this->Link_ID, "SELECT $seqname.NEXTVAL FROM DUAL");
                    ociexecute($Query_ID);
                }
            }
        }

        if (ocifetch($Query_ID)) {
            $next_id = ociresult($Query_ID, "NEXTVAL");
        } else {
            $next_id = 0;
        }
        ocifreestatement($Query_ID);
        return $next_id;
    }

    function disconnect() {
        if ($this->Debug) {
            printf("Disconnecting...<br>\n");
        }
        ocilogoff($this->Link_ID);
    }

    function halt($msg) {
        $this->Error = $this->ErrArray["message"];
        $this->Errno = $this->ErrArray["code"];
        parent::halt($msg);
    }

    function lock($table, $mode = "write") {
        $this->connect();
        if ($mode == "write") {
            $Parse = ociparse($this->Link_ID, "lock table $table in row exclusive mode");
            ociexecute($Parse);
        } else {
            $result = 1;
        }
        return $result;
    }

    function unlock() {
        return $this->query("commit");
    }

    function table_names() {
        $this->connect();
        $this->query("SELECT table_name,tablespace_name FROM user_tables");
        $i = 0;
        while ($this->next_record()) {
            $info[$i]["table_name"] = $this->Record["table_name"];
            $info[$i]["tablespace_name"] = $this->Record["tablespace_name"];
            $i++;
        }
        return $info;
    }

    function add_specialcharacters($query) {
        return str_replace("'", "''", $query);
    }

    function split_specialcharacters($query) {
        return str_replace("''", "'", $query);
    }

    /* This new function is needed to write a valid db dependant date string. */
    function now() {
        return "SYSDATE";
    }
}
