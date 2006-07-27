<?php
//$Id$
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
http://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

/** Cvarset - class for storing variables
 *          - simplifies database manipulation (by Cvarset class below)
 */

class Cvariable {
    var $name;
    var $type;
    var $value;
    /** Is it a key value? key values are used in UPDATE -> WHERE,
    *  INSERT -> VALUES.  See also makeINSERTorUPDATE. */
    var $iskey;

    // constructor
    function Cvariable($name, $type, $value, $iskey=false) {
        $this->name  = $name;
        $this->type  = $type;
        $this->value = $value;
        $this->iskey = $iskey;
    }

    function getValue() {
        return $this->value;
    }

    function getSQLValue() {
        switch ( $this->type )
        {
            case "number":
                // grrr: if $var=0 then $var=""!!!
                return ($this->value == "") ? "0" : $this->value;
            case "unpacked":
                return "'" . q_pack_id($this->value) ."'";
            case "quoted":
                return "'" . $this->value ."'";
            case "null":
                return "NULL";
            case "date":
            case "text":
            default:
                return "'" . quote($this->value) ."'";
        }
    }

    function huh() {
        echo "$this->name($this->type) -> $this->value <br>\n";
    }
}

class Cvarset {
    var $vars;  // array of variables
    var $db;    // database handler

    /** constructor - also good for filling the varset */
    function Cvarset( $arr=null ) {
        $this->db = null;
        foreach ( (array)$arr as $varname => $value ) {
            if ( $varname ) {
                $this->add($varname, 'text', $value);
            }
        }
    }

    /** clears whole varset */
    function clear() {
        $this->vars="";
    }

    /** get variable value */
    function get($varname) {
        $cv = $this->vars["$varname"];
        return ( $cv ? $cv->getValue() : false);
    }

    function getSQLvalue($varname) {
        $cv = $this->vars[$varname];
        return ( $cv ? $cv->getSQLvalue() : false);
    }

    /** add variable to varset */
    function add($varname, $type="text", $value="") {
        $this->vars[$varname]= new Cvariable($varname, $type, $value);
    }

    /** add global variables to varset (names in $arr) */
    function addglobals($arr, $type="quoted") {
        if ( isset($arr) AND is_array($arr) ) {
            foreach ( $arr as $varname ) {
                $this->vars[$varname]= new Cvariable($varname, $type, $GLOBALS[$varname]);
            }
        }
    }

    /** add key variable to varset (see Cvariable) */
    function addkey($varname, $type="text", $value="") {
        $this->vars[$varname] = new Cvariable($varname, $type, $value, true);
    }

    /** remove variable from varset */
    function remove($varname) {
        unset ($this->vars[$varname]);
    }

    /** set variable value */
    function set($varname, $value, $type="") {
        if ( $type=="" ) {
            $v    = $this->vars[$varname];
            $type = $v->type;
        }
        $this->add($varname, $type, $value);   // it must be assigned this way, because $v is just copy
    }

    /** if undefined - set */
    function ifnoset($varname, $value, $type="") {
        if ( !$this->get($varname) ) {
            $this->add($varname, ($type ? $type : "quoted"), $value);
        }
    }

    /** return variable value */
    function value($varname){
        $v = $this->vars["$varname"];
        return $v->value;
    }

    /** set variables values due to array */
    function setFromArray($arr) {
        foreach ( $this->vars as $varname => $variable ) {
            $this->set($varname, $arr[$varname]);
        }
    }

    /** Fills varset with data grabed from database ($db->Record) */
    function resetFromRecord($record) {
        $this->clear();
        foreach ( $record as $name => $value ) {
            if ( !is_numeric($name) ) {
                $this->add($name, 'text', $value);
            }
        }
    }

    /** Add text and number variables from arrays to varset */
    function addArray($text_fields, $num_fields="") {
        if ( isset($text_fields) AND is_array($text_fields)) {
            foreach ($text_fields as $name) {
                $this->add($name, "text");
            }
        }
        if ( isset($num_fields) AND is_array($num_fields)) {
            foreach ( $num_fields as $name) {
                $this->add($name, "number");
            }
        }
    }

    /** Private function: executes qiven query) */
    function _doQuery($SQL, $nohalt=null) {
        if ( is_null($this->db) ) {
            $this->db = getDB();
        }
        if ( $nohalt=='nohalt' ) {
            $retval = $this->db->query_nohalt($SQL);
        } else {
            $retval = $this->db->tquery($SQL);
        }
        return $retval;
    }

    /** Makes SQL INSERT clause from varset */
    function makeINSERT($tablename = "")
    {
        $foo      = $tablename ? "INSERT INTO `$tablename`" : '';
        $predznak = " ( ";
        foreach ( $this->vars as  $varname => $variable ) {
            $foo .= $predznak . "`$varname`";
            $predznak = ", ";
        }
        $predznak = " ) VALUES ( ";
        foreach ( $this->vars as  $varname => $variable ) {
            $foo .= $predznak . $variable->getSQLValue();
            $predznak = ", ";
        }
        return $foo . " ) " ;
    }

    function doInsert($tablename, $nohalt=null) {
        return $this->_doQuery($this->makeINSERT($tablename), $nohalt);
    }

    /** Makes SQL UPDATE clause from varset */
    function makeUPDATE($tablename = "") {
        foreach ( $this->vars as  $varname => $variable ) {
            if (!$variable->iskey) {
                $updates[] = "`$varname`" ."=". $variable->getSQLValue();
            }
        }
        if ($tablename) {
            $retval = "UPDATE `$tablename` SET";
        }
        $retval .= " " . join (", ", $updates);
        if ($where = $this->makeWHERE()) {     // assignment
            $retval .= " WHERE ".$where;
        }
        return $retval;
    }

    function doUpdate($tablename, $nohalt=null) {
        return $this->_doQuery($this->makeUPDATE($tablename), $nohalt);
    }

    // be sure, you have defined key field
    function doREPLACE($tablename, $nohalt=null) {
        // we do no longer use REPLACE SQL command - it is not implemented in
        // some DB engines (it is not ANSI SQL) nad even in MySQL it works bad
        // with autoincremented fields
        return $this->_doQuery($this->makeINSERTorUPDATE($tablename), $nohalt);
    }

    function makeSELECT($table) {
        $where = $this->makeWHERE();
        return ($where ? "SELECT * FROM `$table` WHERE ".$where :
                         "SELECT * FROM `$table`");
    }

    function makeDELETE($table, $where=null) {
        if ( is_null($where) ) {
            $where = $this->makeWHERE();
        }
        return ($where ? "DELETE FROM `$table` WHERE ".$where : 'Error');
    }

    function doDelete($tablename, $nohalt=null) {
        return $this->_doQuery($this->makeDELETE($tablename), $nohalt);
    }

    function doDeleteWhere($tablename, $where, $nohalt=null) {
        return $this->_doQuery($this->makeDELETE($tablename, $where), $nohalt);
    }

    function makeWHERE($table="") {
        $where = "";
        foreach ( $this->vars as $varname => $variable) {
            if ($variable->iskey) {
                if ($where) { $where .= " AND "; }
                if ($table) { $varname = $table.".".$varname; }
                $where .= $varname ."=". $variable->getSQLValue();
            }
        }
        return $where;
    }

    /** This function looks into the given table and if the row exists, it is
     *  updated, if not then inserted. Add always all key fields by addkey()
     *  to the varset before using this function.
     */
    function makeINSERTorUPDATE($table) {
        $this->_doQuery($this->makeSELECT($table));
        switch ($this->db->num_rows()) {
            case 0: return $this->makeINSERT($table);
            case 1: return $this->makeUPDATE($table);
            default:
            // Error: there are several rows with the same key variables
            return "Error using makeINSERTorUPDATE: " . $this->db->num_rows(). " rows match the query";
        }
    }

    function lastInsertId($table) {
        $this->_doQuery("SELECT LAST_INSERT_ID() AS lid FROM $table");
        $this->db->next_record();
        return $this->db->f("lid");
    }

    function huh($txt="") {
        echo "Varset: $txt";
        foreach ( $this->vars as  $varname => $variable ) {
            $variable->huh();
        }
    }
}

class AA_Metabase_Table {
    /** Name of the table */
    var $tablename;
    /** array of PRIMARY KEY columns */
    var $primary_key;
    /** array of INDEXES */
    var $index;
    /** array of table columns */
    var $column;
    /** array of table flags - like ENGINE=InnoDB, DEFAULT CHARSET=cp1250 */
    var $flags;

    /** Fills AA_Metabase_Table structure from the result of SQL command:
     *     SHOW CREATE TABLE $table_name
     */
    function setFromSql($tablename, $create_SQL) {
        $this->tablename = $tablename;
        foreach (explode("\n", $create_SQL) as $row) {
            $row = trim($row);
            // first row - CREATE TABLE - no need to grab anything from it
            if ( strpos($row, 'CREATE TABLE') === 0 ) {
                continue;
            }
            // field definition row - grab it
            if ( (strpos($row, 'KEY') === 0) OR
                 (strpos($row, 'UNIQUE KEY') === 0) OR
                 (strpos($row, 'PRIMARY KEY') === 0) ) {
                $this->_setIndexFromSql($row);
                continue;
            }
            if ( strpos($row, ')') === 0 ) {
                $this->_setFlagFromSql($row);
                continue;
            }
            // else urecognized row
            echo $row;
        }
    }
}

/** @todo convert to static class variables after move to PHP5 */
class AA_Metabase {
    var $tables;
    var $item_translations;

    function AA_Metabase() {
        $this->tables            = array('item' => array('id', 'short_id', 'slice_id', 'status_code', 'post_date', 'publish_date', 'expiry_date', 'highlight', 'posted_by', 'edited_by', 'last_edit', 'display_count', 'flags', 'disc_count', 'disc_app', 'externally_fed', 'moved2active'));
        $this->item_translations = array();
        foreach ($this->tables['item'] as $column) {
            $this->item_translations[AA_Fields::createFieldId($column)] = $column;
        }
    }

    function addTableFromSql($tablename, $create_SQL) {
        $this->tables[$tablename] = new AA_Metabase_Table;
        $this->tables[$tablename]->setFromSQL($tablename, $create_SQL);
    }

    function itemTableField($field_id) {
        return get_if($this->item_translations[$field_id], false);
    }

    function itemFields4Sql($fields2get) {
        $fields = array();
        if ( is_array($fields2get) ) {
            foreach ( $fields2get as $field_name ) {
                if ($this->item_translations[$field_name]) {
                    $fields[] = 'item.'. $this->item_translations[$field_name];
                }
            }
        }
        return ( count($fields) < 1 ) ? 'item.*' : join(',', $fields);
    }
}

?>