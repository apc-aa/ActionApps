<?php
/*
 * Session Management for PHP3
 *
 * Copyright (c) 1998-2000 NetUSE AG
 *                    Boris Erdmann, Kristian Koehntopp
 *
 * Derived from DB_MySQL.php by Sascha Schumann <sascha@schumann.cx>
 *
 * $Id$
 *
 */

namespace AA\IO\DB;

class DB_mSQL extends AbstractDB {
    var $Host = "";
    var $Database = "";

    var $Link_ID = 0;
    var $Query_ID = 0;
    var $Row;

    var $PConnect = 0;     ## Set to 1 to use persistent database connections

    /**
     * DB_msql constructor.
     * @param array $connection
     */
    function __construct(array $connection) {
        $this->Database = $connection['database'] ?? '';
        $this->Host     = $connection['host'] ?? '';
    }

    function connect() {
        // Not connected? Then connect?
        if (0 == $this->Link_ID) {
            // Check for local connect
            if (!$this->PConnect) {
                $this->Link_ID = empty($this->Host) ?
                    $this->Link_ID = msql_connect() :
                    $this->Link_ID = msql_connect($this->Host);
            } else {
                $this->Link_ID = empty($this->Host) ?
                    $this->Link_ID = msql_pconnect() :
                    $this->Link_ID = msql_pconnect($this->Host);
            }
        }

        // Still not connected? Raise error.
        if (0 == $this->Link_ID) {
            $this->connect_failed("connect ($this->Host) failed");
        }

        // Select current database
        if (!msql_select_db($this->Database, $this->Link_ID)) {
            $this->connect_failed("cannot use database " . $this->Database);
        }
    }

    function connect_failed($message = '') {
        $this->Halt_On_Error = "yes";
        $this->halt($message);
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

#   printf("Debug: query = %s<br>\n", $Query_String);

        $this->Query_ID = msql_query($Query_String, $this->Link_ID);
        $this->Row = 0;
        $this->Error = msql_error();
        if (!$this->Query_ID) {
            $this->halt("Invalid SQL: " . $Query_String);
        }

        return $this->Query_ID;
    }

    function next_record() {
        $this->Record = msql_fetch_array($this->Query_ID);
        ++$this->Row;
        $this->Error = msql_error();

        if (is_array($this->Record)) {
            return true;
        }
        msql_free_result($this->Query_ID);
        $this->Query_ID = 0;
        return false;
    }

    function seek($pos = 0) {
        $status = msql_data_seek($this->Query_ID, $pos);
        if ($status) {
            $this->Row = $pos;
        }
        return;
    }

    function metadata($table = "", $full = false) {
        $count = 0;
        $id = 0;
        $res = [];

        $this->connect();
        $id = @msql_list_fields($this->Database, $table);
        if ($id < 0) {
            $this->Error = msql_error();
            $this->halt("Metadata query failed.");
        }
        $count = msql_num_fields($id);

        for ($i = 0; $i < $count; $i++) {
            $res[$i]["table"] = msql_fieldtable($id, $i);
            $res[$i]["name"] = msql_fieldname($id, $i);
            $res[$i]["type"] = msql_fieldtype($id, $i);
            $res[$i]["len"] = msql_fieldlen($id, $i);
            $res[$i]["flags"] = msql_fieldflags($id, $i);
            $res["meta"][$res[$i]["name"]] = $i;
            $res["num_fields"] = $count;
        }

        msql_free_result($id);
        return $res;
    }

    function affected_rows() {
        return msql_affected_rows($this->Query_ID);
    }

    function num_rows() {
        return msql_num_rows($this->Query_ID);
    }

    function num_fields() {
        return msql_num_fields($this->Query_ID);
    }

    function f($Name) {
        return $this->Record[$Name];
    }
}
