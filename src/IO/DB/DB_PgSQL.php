<?php
/*
 * Session Management for PHP3
 *
 * Copyright (c) 1998-2000 NetUSE AG
 *                    Boris Erdmann, Kristian Koehntopp
 *
 * $Id$
 *
 */

namespace AA\IO\DB;

class DB_PgSQL extends AbstractDB {
    var $Host = "";
    var $Database = "";
    var $Port = "";
    var $User = "";
    var $Password = "";

    var $Link_ID = 0;
    var $Query_ID = 0;
    var $Row = 0;

    var $Seq_Table = "db_sequence";

    var $Debug = 0;

    var $PConnect = 0; ## Set to 1 to use persistent database connections

    function ifadd($add, $me) {
        if ("" != $add) {
            return " " . $me . $add;
        }
    }

    /**
     * DB_Pgsql constructor.
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
            $cstr = "dbname=" . $this->Database .
                $this->ifadd($this->Host, "host=") .
                $this->ifadd($this->Port, "port=") .
                $this->ifadd($this->User, "user=") .
                $this->ifadd($this->Password, "password=");
            if (!$this->PConnect) {
                $this->Link_ID = pg_connect($cstr);
            } else {
                $this->Link_ID = pg_pconnect($cstr);
            }
            if (!$this->Link_ID) {
                $this->connect_failed("connect ($this->Host" . (($this->Port) ? ":$this->Port" : "") . ", $this->User, \$Password, $this->Database) failed");
            }
        }
    }

    function query($Query_String) {
        /* No empty queries, please, since PHP4 chokes on them. */
        if ($Query_String == "") /* The empty query string is passed on from the constructor,
       * when calling the class without a query, e.g. in situations
       * like these: '$db = new DB_Sql_Subclass;'
       */ {
            return 0;
        }

        $this->connect();

        if ($this->Debug) {
            printf("<br>Debug: query = %s<br>\n", $Query_String);
        }

        $this->Query_ID = pg_exec($this->Link_ID, $Query_String);
        $this->Row = 0;

        $this->Error = pg_errormessage($this->Link_ID);
        $this->Errno = ($this->Error == "") ? 0 : 1;
        if (!$this->Query_ID) {
            $this->halt("Invalid SQL: " . $Query_String);
        }

        return $this->Query_ID;
    }

    function next_record() {
        $this->Record = @pg_fetch_array($this->Query_ID, $this->Row++);

        $this->Error = pg_errormessage($this->Link_ID);
        $this->Errno = ($this->Error == "") ? 0 : 1;

        if (is_array($this->Record)) {
            return true;
        }
        pg_freeresult($this->Query_ID);
        $this->Query_ID = 0;
        return false;
    }

    function seek($pos = 1) {
        $this->Row = $pos;
    }

    function lock($table, $mode = "write") {
        if ($mode == "write") {
            $result = pg_exec($this->Link_ID, "lock table $table");
        } else {
            $result = 1;
        }
        return $result;
    }

    function unlock() {
        return pg_exec($this->Link_ID, "commit");
    }


    /* public: sequence numbers */
    function nextid($seq_name) {
        $this->connect();

        if ($this->lock($this->Seq_Table)) {
            /* get sequence number (locked) and increment */
            $q = sprintf("select nextid from %s where seq_name = '%s'",
                $this->Seq_Table,
                $seq_name);
            $id = @pg_exec($this->Link_ID, $q);
            $res = @pg_fetch_array($id, 0);

            /* No current value, make one */
            if (!is_array($res)) {
                $currentid = 0;
                $q = sprintf("insert into %s values('%s', %s)",
                    $this->Seq_Table,
                    $seq_name,
                    $currentid);
                $id = @pg_exec($this->Link_ID, $q);
            } else {
                $currentid = $res["nextid"];
            }
            $nextid = $currentid + 1;
            $q = sprintf("update %s set nextid = '%s' where seq_name = '%s'",
                $this->Seq_Table,
                $nextid,
                $seq_name);
            $id = @pg_exec($this->Link_ID, $q);
            $this->unlock();
        } else {
            $this->halt("cannot lock " . $this->Seq_Table . " - has it been created?");
            return 0;
        }
        return $nextid;
    }

    function metadata($table = "", $full = false) {
        $count = 0;
        $id = 0;
        $res = [];

        if ($table) {
            $this->connect();
            $id = pg_exec($this->Link_ID, "select * from $table");
            if ($id < 0) {
                $this->Error = pg_errormessage($id);
                $this->Errno = 1;
                $this->halt("Metadata query failed.");
            }
        } else {
            $id = $this->Query_ID;
            if (!$id) {
                $this->halt("No query specified.");
            }
        }

        $count = pg_numfields($id);

        for ($i = 0; $i < $count; $i++) {
            $res[$i]["table"] = $table;
            $res[$i]["name"] = pg_fieldname($id, $i);
            $res[$i]["type"] = pg_fieldtype($id, $i);
            $res[$i]["len"] = pg_fieldsize($id, $i);
            $res[$i]["flags"] = "";
        }

        if ($table) {
            pg_freeresult($id);
        }

        return $res;
    }

    function affected_rows() {
        return pg_cmdtuples($this->Query_ID);
    }

    function num_rows() {
        return pg_numrows($this->Query_ID);
    }

    function num_fields() {
        return pg_numfields($this->Query_ID);
    }

    function f($Name) {
        return $this->Record[$Name];
    }

    function table_names() {
        $this->query("select relname from pg_class where relkind = 'r' and not relname like 'pg_%'");
        $i = 0;
        while ($this->next_record()) {
            $return[$i]["table_name"] = $this->f(0);
            $return[$i]["tablespace_name"] = $this->Database;
            $return[$i]["database"] = $this->Database;
            $i++;
        }
        return $return;
    }
}

