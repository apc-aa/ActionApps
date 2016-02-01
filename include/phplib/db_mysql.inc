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

class DB_Sql {

    /* public: connection parameters */
    var $Host     = "";
    var $Database = "";
    var $User     = "";
    var $Password = "";

    /* public: configuration parameters */
    var $Halt_On_Error = "yes"; ## "yes" (halt with message), "no" (ignore errors quietly), "report" (ignore error, but spit a warning)

    /* public: result array and current row number */
    var $Record   = array();
    var $Row;

    /* public: current error number and error text */
    var $Errno    = 0;
    var $Error    = "";

    /* public: this is an api revision, not a CVS revision. */
    var $type     = "mysqli";
    var $revision = "0.1";

    /* private: link and query handles */
    var $Link_ID  = 0;
    var $Query_ID = 0;


    /* public: constructor */
    function __construct($query = "") {
        if ($query) {
            $this->query($query);
        }
    }

    /* public: some trivial reporting */
    function link_id() {
        return $this->Link_ID;
    }

    function quote($string) {
        return mysqli_real_escape_string($this->Link_ID, $string);
    }

    function query_id() {
        return $this->Query_ID;
    }

    /* public: connection management */
    function connect($Database = "", $Host = "", $User = "", $Password = "") {
        /* establish connection, select database */
        if ( 0 == $this->Link_ID ) {

            /* Handle defaults */
            if ("" == $Database) { $Database = $this->Database; }
            if ("" == $Host)     { $Host     = $this->Host;     }
            if ("" == $User)     { $User     = $this->User;     }
            if ("" == $Password) { $Password = $this->Password; }

            $method = ( defined('AA_USE_NON_PERSISTENT_CONNECT') AND AA_USE_NON_PERSISTENT_CONNECT ) ? '' : 'p:';
            $this->Link_ID = mysqli_connect($method.$Host, $User, $Password, $Database);

            /* Vérification de la connexion */
            if (@mysqli_connect_errno()) {
                $this->connect_failed("connect ($method$Host, $User, \$Password, $Database) failed - ". mysqli_connect_errno(). ' - ' . mysqli_connect_error());
                return 0;
            }

            // Jirkare hack
            if ( defined('DB_CHARACTER_SET') ) {
                /* change character set to utf8 */
                if (!mysqli_set_charset($this->Link_ID, DB_CHARACTER_SET)) {
                    $this->connect_failed('Error loading character set '. DB_CHARACTER_SET.': '.mysqli_error($this->Link_ID));
                }
            }
            if ( defined('DB_COLLATION_CONNECTION') ) {
                @mysqli_query($this->Link_ID, 'SET COLLATION_CONNECTION=\''. DB_COLLATION_CONNECTION.'\'');
            }
        }
        return $this->Link_ID;
    }

    function connect_failed($message) {
        $this->Halt_On_Error = "yes";
        $this->halt($message);
    }

    /* public: discard the query result */
    function free() {
        if ($this->Query_ID) {
            @mysqli_free_result($this->Query_ID);
        }
        $this->Query_ID = 0;
    }

    /* public: perform a query */
    function query($Query_String) {
        /* No empty queries, please, since PHP4 chokes on them. */
        if ($Query_String == "") {
            /* The empty query string is passed on from the constructor,
            * when calling the class without a query, e.g. in situations
            * like these: '$db_install = new DB_MySQLi_Subclass;'
            */
            return 0;
        }

        if ( (0 == $this->Link_ID) AND !$this->connect()) {
            return 0; /* we already complained in connect() about that. */
        };

        // New query, discard previous result.
        if ($this->Query_ID) {
            $this->free();
        }

        //$this->Query_ID = @mysqli_query($this->Link_ID,$Query_String, MYSQLI_USE_RESULT);
        $this->Query_ID = @mysqli_query($this->Link_ID,$Query_String);

        // solution for "MySQL server has gone away" Eroor - Try to reconnect
        if (2006 == ($this->Errno = @mysqli_errno($this->Link_ID))) {
            mysqli_close($this->Link_ID);
            $this->Link_ID = 0;
            if ($this->connect()) {
                $this->Query_ID = @mysqli_query($this->Link_ID,$Query_String);
            }
            $this->Errno = @mysqli_errno($this->Link_ID);
        }

        $this->Row   = 0;
        $this->Error = @mysqli_error($this->Link_ID);
        if (!$this->Query_ID) {
            $this->halt("Invalid SQL: ".$Query_String);
        }

        // Will return nada if it fails. That's fine.
        return $this->Query_ID;
    }




    /* public: walk result set */
    function next_record() {
        if (!$this->Query_ID) {
            $this->halt("next_record called with no query pending.");
            return 0;
        }

        $this->Row   += 1;
        if (is_null($this->Record = @mysqli_fetch_assoc($this->Query_ID))) {
            $this->Errno  = mysqli_errno($this->Link_ID);
            $this->Error  = mysqli_error($this->Link_ID);
            mysqli_free_result($this->Query_ID);
            $this->Query_ID = 0;
            return false;
        }
        $this->Errno  = 0;
        $this->Error  = '';
        return true;
    }

    /* public: return all the results */
    function fetch_table() {
        if (!$this->Query_ID) {
            $this->halt("next_record called with no query pending.");
            return array();
        }
        return mysqli_fetch_all($this->Query_ID, MYSQLI_ASSOC);
    }

    /* public: return all the results */
//    function fetch_column($col) {
//        $res = $this->fetch_table();
//        $ret = array();
//        foreach ($res as $v) {
//            $ret[] = $v[$col];
//        }
//        return $ret;
//    }

    /* public: return all the results */
    function fetch_column($col) {
        if (!$this->Query_ID) {
            $this->halt("next_record called with no query pending.");
            return array();
        }
        $ret = array();
        while (is_array($r = @mysqli_fetch_assoc($this->Query_ID))) {
            $ret[] = $r[$col];
        }
        return $ret;
    }


    /* public: position in result set */
    function seek($pos = 0) {
        $status = @mysqli_data_seek($this->Query_ID, $pos);
        if ($status) {
            $this->Row = $pos;
        } else {
            $this->halt("seek($pos) failed: result has ".$this->num_rows()." rows.");

            /* half assed attempt to save the day,
            * but do not consider this documented or even
            * desireable behaviour.
            */
            @mysqli_data_seek($this->Query_ID, $this->num_rows());
            $this->Row = $this->num_rows();
            return 0;
        }

        return 1;
    }

    /* public: table locking */
    function lock($table, $mode="write") {
        $this->connect();

        $query="lock tables ";
        if (is_array($table)) {
            while (list($key,$value)=each($table)) {
                if ($key=="read" && $key!=0) {
                    $query.="$value read, ";
                } else {
                    $query.="$value $mode, ";
                }
            }
            $query=substr($query,0,-2);
        } else {
            $query.="$table $mode";
        }
        $res = @mysqli_query($this->Link_ID, $query);
        if (!$res) {
            $this->halt("lock($table, $mode) failed.");
            return 0;
        }
        return $res;
    }

    function unlock() {
        $this->connect();

        $res = @mysqli_query($this->Link_ID, "unlock tables");
        if (!$res) {
            $this->halt("unlock() failed.");
            return 0;
        }
        return $res;
    }

    function ping() {
        if (0 == $this->Link_ID) {
            $this->connect();
            return;
        }

        // New query, discard previous result.
        if ($this->Query_ID) {
            $this->free();
        }

        $this->Query_ID = @mysqli_query($this->Link_ID, 'SELECT LAST_INSERT_ID()');  // any cheap query (maybe there is even quicker query)
        if (mysqli_errno($this->Link_ID) == 2006) {
            mysqli_close($this->Link_ID);
            $this->Link_ID = 0;
            $this->connect();
        }
    }

    /* public: evaluate the result (size, width) */
    function affected_rows() {
        return @mysqli_affected_rows($this->Link_ID);
    }

    function num_rows() {
        return @mysqli_num_rows($this->Query_ID);
    }

    function num_fields() {
        return @mysqli_num_fields($this->Query_ID);
    }

    function last_insert_id() {
        return @mysqli_insert_id($this->Link_ID);
    }

    /* public: shorthand notation */
    function nf() {
        return $this->num_rows();
    }

    function np() {
        print $this->num_rows();
    }

    function f($Name) {
        if (isset($this->Record[$Name])) {
            return $this->Record[$Name];
        }
    }

    function p($Name) {
        print $this->Record[$Name];
    }

    /* public: return table metadata */
    function metadata($table = "", $full = false) {
        $count = 0;
        $id    = 0;
        $res   = array();

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
        *   [0]["flags"]  field flags
        *
        * - full is true
        * $result[]:
        *   ["num_fields"] number of metadata records
        *   [0]["table"]  table name
        *   [0]["name"]   field name
        *   [0]["type"]   field type
        *   [0]["len"]    field length
        *   [0]["flags"]  field flags
        *   ["meta"][field name]  index of field named "field name"
     *   This last one could be used if you have a field name, but no index.
        *   Test:  if (isset($result['meta']['myfield'])) { ...
        */

        // if no $table specified, assume that we are working with a query
        // result
        if ($table) {
            $id = $this->query("SHOW FIELDS FROM $table");
            if (!$id) {
                $this->halt("Metadata query failed (table $table).".$this->LinkID);
                return false;
            }
        } else {
            $this->halt("No table specified.");
            return false;
        }

        $count = @mysqli_num_fields($id);

        for ($i=0; $i<$count; $i++) {
            $row = @mysqli_fetch_array($id);
            $res[$i]["table"] = $table;
            $res[$i]["name"]  = $row["Field"];
            $res[$i]["type"]  = $row["Type"];
            // $res[$i]["len"]   = @mysql_field_len   ($id, $i);
            // $res[$i]["flags"] = @mysql_field_flags ($id, $i);
        }

        if ($full) {
            $res["num_fields"] = $count;
            for ($i=0; $i<$count; $i++) {
                $res["meta"][$res[$i]["name"]] = $i;
            }
        }

        for ($i=0; $i<$count; $i++) {
            $res[$i]["group"] = $res[$i]["type"];
            $h     = $this->query("SHOW FIELDS FROM $table WHERE Field='".$res[$i]["name"]."'");
            $row   = @mysqli_fetch_array($h);
            $fattr = "";
            $pos   = strpos ( $row["Type"], "(" );
            if ( $pos > 0 ) {
                $ftype = substr ( $row["Type"], 0, $pos );
                $fsize = substr ( $row["Type"], $pos +1 );
                $pos = strpos ( $fsize, ") " );
                if ( $pos > 0 ) {
                    $fattr = substr ( $fsize, $pos +2, strlen ($fsize) -2 -$pos );
                    $fsize = substr ( $fsize, 0, $pos );
                } else {
                    $fsize = substr ( $fsize, 0, $pos -1 );
                }
            } else {
                $fsize = "";
                $ftype = $row["Type"];
            }

            $res[$i]["key"]     = $row["Key"];
            if ($row["Key"] == 'PRI') {
                $res[$i]["flags"] = 'primary_key';  // to be compatible with db_mysql
            }
            $res[$i]["chars"]   = $fsize;
            $res[$i]["type"]    = $ftype;
            $res[$i]["attr"]    = $fattr;   /* eg unsigned */
            $res[$i]["null"]    = $row["Null"];
            $res[$i]["extra"]   = $row["Extra"];
            $res[$i]["default"] = $row["Default"];

        }

        // free the result only if we were called on a table
        @mysqli_free_result($id);

        return $res;
    }

    function table_names() {
        $this->query("SHOW TABLES");
        $i=0;
        $return = array();
        while ($info=@mysqli_fetch_row($this->Query_ID)) {
            $return[$i]["table_name"]= $info[0];
            $return[$i]["tablespace_name"]=$this->Database;
            $return[$i]["database"]=$this->Database;
            $i++;
        }
        return $return;
    }

    /* private: error handling */
    function halt($msg) {
        $this->Error = @mysqli_error($this->Link_ID);
        $this->Errno = @mysqli_errno($this->Link_ID);
        if ($this->Halt_On_Error == "no") {
            return;
        }

        $this->haltmsg($msg);

        if ($this->Halt_On_Error != "report") {
            die("Session halted.");
        }
    }

    function haltmsg($msg) {
        printf("<p><b>Database error:</b> %s<br>\n", $msg);
        printf("<b>MySQL Error</b>: %s (%s)</p>\n",  $this->Errno, $this->Error);
    }
}

?>
