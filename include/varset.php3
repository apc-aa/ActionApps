<?php
/**
 * Class Cvariable, Cvarset
 *
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @package   UserInput
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
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

    /** Cvariable function
     *  constructor
     * @param $name
     * @param $type
     * @param $value
     * @param $iskey
     */
    function Cvariable($name, $type, $value, $iskey=false) {
        $this->name  = $name;
        $this->type  = $type;
        $this->value = $value;
        $this->iskey = $iskey;
    }
    /** getValue function
     *
     */
    function getValue() {
        return $this->value;
    }
    /** getSQLValue function
     *
     */
    function getSQLValue() {
        switch ( $this->type )
        {
            case "integer":
            case "number":
                // grrr: if $var=0 then $var=""!!!
                return ($this->value == "") ? "0" : $this->value;
            case "unpacked":
                return strlen($this->value) ? '0x' . quote($this->value) : "''";
            case "quoted":
                return "'" . $this->value ."'";
            case "null":
                return "NULL";
            case "float":
            case "date":
            case "text":
            default:
                return "'" . quote($this->value) ."'";
        }
    }
}

class Cvarset {
    var $vars;          // array of variables
    var $last_used_db;  // database handler
    var $just_print;    // debug option - just prints the query (not implemented for all methods!!!)

    /** Cvarset function
     *  constructor - also good for filling the varset
     * @param $arr
     */
    function __construct( $arr=array()) {
        $TRANS              = array('i'=>'number', 's'=>'text', 'q'=>'quoted', 'l'=>'unpacked');
        $this->vars         = array();
        $this->last_used_db = null;
        $this->just_print   = false;

        foreach ( $arr as $def ) {
            $this->add($def[0], isset($def[2]) ? $TRANS[$def[2]] : 'text', $def[1]);
        }
    }

    function setDebug() {
        $this->just_print = true;
    }

    /** clear function
     *  clears whole varset
     */
    function clear() {
        $this->vars = array();
    }

    /** Returns true, is the varset do not contain any variable */
    function isEmpty() {
        return count($this->vars) < 1;
    }

    /** get function
     *  get variable value
     * @param $varname
     */
    function get($varname) {
        $cv = $this->vars["$varname"];
        return ( $cv ? $cv->getValue() : false);
    }
    /** getSQLvalue function
     * @param $varname
     */
    function getSQLvalue($varname) {
        $cv = $this->vars[$varname];
        return ( $cv ? $cv->getSQLvalue() : false);
    }

    /** add function
     *  add variable to varset
     * @param $varname
     * @param $type
     * @param $value
     */
    function add($varname, $type="text", $value="") {
        $this->vars[$varname]= new Cvariable($varname, $type, $value);
    }

    /** addglobals function
     *  add global variables to varset (names in $arr)
     * @param $arr
     * @param $type
     */
    function addglobals($arr, $type="quoted") {
        if ( isset($arr) AND is_array($arr) ) {
            foreach ( $arr as $varname ) {
                $this->vars[$varname]= new Cvariable($varname, $type, $GLOBALS[$varname]);
            }
        }
    }

    /** addkey function
     *  add key variable to varset (see Cvariable)
     * @param $varname
     * @param $type
     * @param $value
     */
    function addkey($varname, $type="text", $value="") {
        $this->vars[$varname] = new Cvariable($varname, $type, $value, true);
    }

    /** remove function
     *  remove variable from varset
     * @param $varname
     */
    function remove($varname) {
        unset ($this->vars[$varname]);
    }

    /** set function
     *  set variable value
     * @param $varname
     * @param $value
     * @param $type
     */
    function set($varname, $value, $type="") {
        if ( $type=="" ) {
            $v    = $this->vars[$varname];
            $type = $v->type;
        }
        $this->add($varname, $type, $value);   // it must be assigned this way, because $v is just copy
    }

    /** ifnoset function
     *  if undefined - set
     * @param $varname
     * @param $value
     * @param $type
     */
    function ifnoset($varname, $value, $type="") {
        if ( !$this->get($varname) ) {
            $this->add($varname, ($type ? $type : "quoted"), $value);
        }
    }

    /** value function
     *  return variable value
     * @param $varname
     */
    function value($varname){
        $v = $this->vars["$varname"];
        return $v->value;
    }

    function getArray() {
        $ret = array();
        foreach ( $this->vars as  $varname => $variable ) {
            if (!$variable->iskey) {
                $ret[$varname] = $variable->getValue();
            }
        }
        return $ret;
    }

    /** setFromArray function
     *  set variables values due to array
     * @param $arr
     */
    function setFromArray($arr) {
        foreach ( $this->vars as $varname => $variable ) {
            $this->set($varname, $arr[$varname]);
        }
    }

    /** resetFromRecord function
     *  Fills varset with data grabed from database ($db->Record)
     * @param $record
     */
    function resetFromRecord($record) {
        $this->clear();
        foreach ( $record as $name => $value ) {
            if ( !is_numeric($name) ) {
                $this->add($name, 'text', $value);
            }
        }
    }

    /** addArray function
     *  Add text and number variables from arrays to varset
     * @param $text_fields
     * @param $num_fields
     */
    function addArray($text_fields, $num_fields="") {
        if (is_array($text_fields)) {
            foreach ($text_fields as $name) {
                $this->add($name, "text");
            }
        }
        if (is_array($num_fields)) {
            foreach ( $num_fields as $name) {
                $this->add($name, "number");
            }
        }
    }

    /** _doQuery function
     *  Private function: executes qiven query)
     * @param $SQL
     * @param $nohalt
     */
    function _doQuery($SQL, $nohalt=null) {
        if ($this->just_print) {
            huhl($SQL);
            return;
        }
        $this->last_used_db = getDB();
        if ( $nohalt=='nohalt' ) {
            $retval = $this->last_used_db->query_nohalt($SQL);
        } else {
            $retval = $this->last_used_db->tquery($SQL);
        }
        freeDB($this->last_used_db);
        return $retval;
    }

    /** _makeInsertReplace function
     * @param $command
     * @param $tablename
     */
    function _makeInsertReplace($command, $tablename) {
        $foo      = $tablename ? "$command INTO `$tablename`" : '';
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

    /** makeINSERT function
     *  Makes SQL INSERT clause from varset
     * @param $tablename
     */
    function makeINSERT($tablename = "") {
        return $this->_makeInsertReplace('INSERT', $tablename);
    }
    /** doInsert function
     * @param $tablename
     * @param $nohalt
     */
    function doInsert($tablename, $nohalt=null) {
        return $this->_doQuery($this->makeINSERT($tablename), $nohalt);
    }

    /** makeUPDATE function
     *  Makes SQL UPDATE clause from varset
     * @param $tablename
     */
    function makeUPDATE($tablename = "") {
        $updates = array();
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


    /** before doUpdate you can call saveHistory() to store changes */
    function saveHistory($tablename, $id) {
        $this->_doQuery($this->makeSELECT($tablename));
        if ($this->last_used_db->num_rows() != 1) {
            // Error: there are several rows with the same key variables
            return "Error using doUpdate: " . $this->last_used_db->num_rows(). " rows match the query";
        }
        $data = $this->last_used_db->Record;
        $packed = AA_Metabase::getPacked($tablename);
        foreach ($packed as $col) {
           $data[$col] = unpack_id($data[$col]);
        }
        AA_ChangesMonitor::singleton()->addHistory(AA_ChangesMonitor::getDiff($id, $data, $this->getArray()));
    }

    /** doUpdate function
     * @param $tablename
     * @param $nohalt
     */
    function doUpdate($tablename, $nohalt=null) {
        return $this->_doQuery($this->makeUPDATE($tablename), $nohalt);
    }

    /** doREPLACE function
     * @param $tablename
     * @param $nohalt
     */
    // be sure, you have defined key field
    function doREPLACE($tablename, $nohalt=null) {
        // we do no longer use REPLACE SQL command - it is not implemented in
        // some DB engines (it is not ANSI SQL) and even in MySQL it works bad
        // with autoincremented fields
        return $this->_doQuery($this->makeINSERTorUPDATE($tablename), $nohalt);
    }

    /** doTrueReplace function
     * @param $tablename
     * @param $nohalt
     */
    // be sure, you have defined key field
    function doTrueReplace($tablename, $nohalt=null) {
        // uses REPLACE SQL command - it is not implemented in some DB engines
        // (it is not ANSI SQL) and even in MySQL it works bad
        // with autoincremented fields
        return $this->_doQuery($this->_makeInsertReplace('REPLACE', $tablename), $nohalt);
    }

    /** doTruncate function - deletes all data from tabe
     * @param $tablename
     * @param $nohalt
     */
    function doTruncate($tablename, $nohalt=null) {
        return $this->_doQuery("TRUNCATE $tablename", $nohalt);
    }

    /** makeSELECT function
     * @param $tablename
     */
    function makeSELECT($tablename) {
        $where = $this->makeWHERE();
        return ($where ? "SELECT * FROM `$tablename` WHERE ".$where :
                         "SELECT * FROM `$tablename`");
    }
    /** makeDELETE function
     * @param $tablename
     * @param $where
     */
    function makeDELETE($tablename, $where=null) {
        if ( is_null($where) ) {
            $where = $this->makeWHERE();
        }
        return ($where ? "DELETE FROM `$tablename` WHERE ".$where : 'Error');
    }
    /** doDelete function
     * @param $tablename
     * @param $nohalt
     */
    function doDelete($tablename, $nohalt=null) {
        return $this->_doQuery($this->makeDELETE($tablename), $nohalt);
    }
    /** doDeleteWhere function
     * @param $tablename
     * @param $where
     * @param $nohalt
     */
    function doDeleteWhere($tablename, $where, $nohalt=null) {
        return $this->_doQuery($this->makeDELETE($tablename, $where), $nohalt);
    }
    /** makeWHERE function
     * @param $tablename
     */
    function makeWHERE($tablename="") {
        $where = "";
        foreach ( $this->vars as $varname => $variable) {
            if ($variable->iskey) {
                if ($where) {
                    $where .= " AND ";
                }
                if ($tablename) {
                    $varname = $tablename.".".$varname;
                }
                $where .= $varname ."=". $variable->getSQLValue();
            }
        }
        return $where;
    }

    /** makeINSERTorUPDATE function
     *  This function looks into the given table and if the row exists, it is
     *  updated, if not then inserted. Add always all key fields by addkey()
     *  to the varset before using this function.
     * @param $tablename
     */
    function makeINSERTorUPDATE($tablename) {
        $this->_doQuery($this->makeSELECT($tablename));
        switch ($this->last_used_db->num_rows()) {
            case 0: return $this->makeINSERT($tablename);
            case 1: return $this->makeUPDATE($tablename);
            default:
            // Error: there are several rows with the same key variables
            return "Error using makeINSERTorUPDATE: " . $this->last_used_db->num_rows(). " rows match the query";
        }
    }

    /** last_insert_id function
     * @param $tablename
     */
    function last_insert_id() {
        return $this->last_used_db->last_insert_id();
    }

    // Static //

    /** sqlin function
     *  Returns part of SQL command sed in WHERE, column = value, or column IN (...)
     * @param $column
     * @param $values
     */
    function sqlin($column, $values) {
        if (!is_array($values)) {
            $values = array($values);
        }
        $arr = array();
        foreach ((array)$values as $v) {
            if ($v!='') {
                $arr[] = "'".quote($v)."'";
            }
        }
        if (count($arr) == 1) {
            return "$column = ". $arr[0];
        } elseif ( count($arr) == 0 ) {
            return "2=1";
        }
        return "$column IN (". join(',', $arr) .")";
    }
}


/** is_field_type_numerical function
 * @param $field_type
 */
function is_field_type_numerical($field_type) {
    return in_array($field_type, array("float","double","decimal","int","timestamp"));
}

// -----------------------------------------------------------------------------
/** CopyTableRows function
 *  Copies rows within a table changing only given columns and omitting given columns.
 *   @author Jakub Adámek
 *   @return bool  true if all additions succeed, false otherwise
 *
 *   @param string $table    table name
 *   @param string $where    where condition (filter)
 *   @param array  $set_columns  array ($column_name => $value, ...) - fields the value of which will be changed
 *   @param array  $omit_columns [optional] array ($column_name, ...) - fields to be omitted
 *   @param array  $id_columns   [optional] array ($column_name, ...) - fields with the 16 byte ID to be generated for each row a new one
 */
function CopyTableRows($table, $where, $set_columns, $omit_columns = "", $id_columns = "") {
    if (!$omit_columns) {
        $omit_columns = array();
    }
    if (!$id_columns) {
        $id_columns = array();
    }

    $db     = getDB();
    $varset = new CVarset();
    $columns = $db->metadata($table);
    freeDB($db);

    $data = GetTable2Array("SELECT * FROM $table WHERE $where", "NoCoLuMn");

    if (!is_array($data)) {
        return true;
    }

    foreach ($data as $datarow) {
        $varset->Clear();

        // create the varset
        foreach ($columns as $col) {
            if (in_array($col["name"], $omit_columns)) {
                continue;
            }

            $type = is_field_type_numerical($col["type"]) ? "number" : "text";

            // look into $set_columns
            if (isset($set_columns[$col["name"]])) {
                $val = $set_columns[$col["name"]];
            }
            elseif (in_array($col["name"], $id_columns)) {
                $val = q_pack_id(new_id());
            }
            else {
                $val = $datarow[$col["name"]];
            }

            $varset->set($col["name"],$val,$type);
        }

        if (!tryQuery("INSERT INTO $table ".$varset->makeINSERT())) {
            return false;
        }
    }
    return true;
}

/** Tracks one field change
 *  Ussage:
 *     new AA_ChangeProposal(<item_id>, <field_id>, <normal_array_of_values>);
 *     $changes = new AA_ChangeProposal($this->getId(), $field_id, $content4id->getValuesArray($field_id));
 */
class AA_ChangeProposal {
    var $resource_id;
    var $selector;
    var $values;    // array of values

    /** AA_ChangeProposal function
     * @param $resource_id
     * @param $selector
     * @param $values
     */
    function __construct($resource_id, $selector, $values) {
        $this->resource_id = $resource_id;
        $this->selector    = $selector;
        $this->values      = $values;
    }

    /** getResourceId function */
    function getResourceId() {
        return($this->resource_id);
    }

    /** getSelector function */
    function getSelector() {
        return($this->selector);
    }

    /** getValues function */
    function getValues() {
        return($this->values);
    }
}

class AA_ChangesMonitor {

    private static $instance = null;

    /** singleton
     *  called as" $changes = AA_ChangesMonitor::singleton();
     *  This function makes sure, there is just ONE static instance if the class
     */
    public static function singleton(){
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** addProposal function
     * @param $change_proposals
     */
    function addProposal($change_proposals) {
        return $this->_add($change_proposals, 'p');
    }

    /** addHistory function
     * @param $change_proposals
     */
    function addHistory($change_proposals) {
        return $this->_add($change_proposals, 'h');
    }

    /** Prepares AA_ChangeProposal array from old values and new values
     *  Ussage: AA_ChangesMonitor::singleton()->addHistory(AA_ChangesMonitor::getDiff($id, $old, $new));
     */
    static function getDiff($id, $old, $new) {
        $changes = array();
        if (is_array($old) AND is_array($new)) {
            foreach ($new as $fid => $a) {
                if ($old[$fid] != $a) {
                    $changes[] = new AA_ChangeProposal($id, $fid, array($old[$fid]));
                }
            }
        }
        return $changes;
    }

    /** _add function
     * @param $change_proposals
     * @param $type
     */
    function _add($change_proposals, $type) {
        global $auth;

        if (!is_array($change_proposals)) {
            $change_proposals = array($change_proposals);
        }
        if (!is_object($change = reset($change_proposals))) {
            return true;
        }

        $change_id = new_id();
        $varset = new CVarset;
        $varset->addkey("id",       "text",   $change_id);
        $varset->add("time",        "number", now());
        $varset->add("user",        "text",   is_object($auth) ? $auth->auth["uid"] : '');
        $varset->add("type",        "text",   $type);
        $varset->add("resource_id", 'text',   $change->getResourceId());
        $varset->doInsert('change');

        foreach ($change_proposals as $change) {
            $priority = 0;
            foreach ( $change->getValues() as $value ) {
                $varset->clear();
                $varset->add("change_id", "text",   $change_id);
                $varset->add("selector",  "text",   $change->getSelector());
                $varset->add("priority",  "number", $priority++);
                $varset->add("type",      "text",   gettype($value));
                $varset->add("value",     "text",   $value);
                $varset->doInsert('change_record');
            }
        }
        return true;
    }
    /** deleteProposal
     * @param $change_id
     */
    function deleteProposal($change_id) {
        $varset = new CVarset;
        $varset->doDeleteWhere('change_record', "change_id = '".quote($change_id). "'");
        $varset->clear();
        $varset->addkey("id", "text", $change_id);
        $varset->doDelete('change');
    }

    /** list of fields changed during last edit - dash ('-') separated */
    function lastChanged($resource_id) {

        $chid = DB_AA::select1("SELECT id FROM `change` WHERE `change`.resource_id = \"".quote($resource_id)."\" AND type = 'h' ORDER BY time DESC", 'id');  // false if not found
        $ret     = '';
        if ($chid) {
            $changes_arr = $this->getProposalByID($chid);
            if (is_array($changes_arr[$resource_id])) {
                $ret = join('-',array_keys($changes_arr[$resource_id]));
            }
        }
        return $ret;
    }

    /** list of fields changed during last edit - dash ('-') separated */
    function lastChangeDate($resource_id, $selector) {
        $time = DB_AA::select1("SELECT time FROM `change_record` INNER JOIN `change` ON `change`.id = `change_record`.change_id WHERE `change`.resource_id = \"".quote($resource_id)."\" AND `change`.type = 'h' AND `change_record`.selector = \"".quote($selector)."\" ORDER BY `change`.time DESC", 'time');
        return $time ? $time : 0;
    }

    /** deleteProposalForSelector function
     * @param $resource_id
     * @param $selector
     */
    function deleteProposalForSelector($resource_id, $selector) {
        $changes_ids = GetTable2Array("SELECT DISTINCT change_id  FROM `change` LEFT JOIN `change_record` ON `change`.id = `change_record`.change_id
                                         WHERE `change`.resource_id = '".quote($resource_id)."' AND `change`.type = 'p' AND `change_record`.selector = '".quote($selector)."'", '', 'change_id');
        if ( is_array($changes_ids) ) {
            foreach( $changes_ids as $change_id ) {
                $this->deleteProposal($change_id);
            }
        }
    }

    /** getProposals function
     *  @return all proposals for given resource (like item_id)
     *  return value is array ordered by time of proposal
     * @param $resource_ids
     */
    function getProposals($resource_ids) {
        return $this->_get($resource_ids, 'p');
    }
    /** getHistory function
     * @param $resource_ids
     */
    function getHistory($resource_ids) {
        return $this->_get($resource_ids, 'h');
    }

    /** _get function
     * @return all proposals for given resource (like item_id)
     *  return value is array ordered by time of proposal
     * @param $resource_ids
     * @param $type
     */
    function _get($resource_ids, $type) {
        $garr = new AA_GeneralizedArray();
        if ( !is_array($resource_ids) OR (count($resource_ids)<1) ) {
            return array();
        }

        $ids4sql = sqlin("`change`.resource_id", $resource_ids);

        $sql = "SELECT `change`.resource_id, `change_record`.*
                                FROM `change` LEFT JOIN `change_record` ON `change`.id = `change_record`.change_id
                                WHERE $ids4sql
                                AND   `change`.type='$type'
                                ORDER BY `change`.resource_id, `change`.time, `change_record`.change_id, `change_record`.selector, `change_record`.priority";

        $changes = GetTable2Array($sql, '', 'aa_fields');

        if ( is_array($changes) ) {
            foreach($changes as $change) {
                if ( $change['type'] ) {
                    $value = $change['value'];
                    settype($value, $change['type']);
                    $garr->add($value, array($change['resource_id'], $change['change_id'], $change['selector']));
                }
            }
        }
        return $garr;
    }


    /** experimental display function - prints the table with all changes  */
    function display($resource_ids, $type='h') {
        $garr = new AA_GeneralizedArray();
        if ( !is_array($resource_ids) OR (count($resource_ids)<1) ) {
            return array();
        }

        $ids4sql = sqlin("`change`.resource_id", $resource_ids);

        $arr = DB_AA::select(array(), "SELECT `change`.time, `change`.user, `change_record`.selector, `change_record`.value, `change_record`.priority, `change_record`.type as chtype, `change_record`.change_id, `change`.resource_id, `change`.type
                                FROM `change` LEFT JOIN `change_record` ON `change`.id = `change_record`.change_id
                                WHERE $ids4sql
                                AND `change`.type='$type'
                                ORDER BY `change`.resource_id, `change`.time, `change_record`.change_id, `change_record`.selector, `change_record`.priority");

        echo "<table><tr><th>&nbsp;</th><th>field</th><th>value</th><th>priority</th><th>type</th><th>resource</th></tr>";
        $chid = '';
        foreach($arr as $change) {
            if ($chid != $change['change_id']) {
                echo "<tr><th colspan=6>". date('Y-m-d H:i:s', $change['time']). ' -'.perm_username( $change['user'])." <small>($change[type], uid:$change[user], res:$change[resource_id], change:$change[change_id])</small></th></tr>";
                $chid = $change['change_id'];
            }
            echo "<tr><td>&nbsp;</td><td>$change[selector]</td><td>$change[value]</td><td>$change[priority]</td><td>$change[chtype]</td><td>$change[resource_id]</td></tr>";
        }
        echo "</table>";



       //    print_r(array_keys(reset($arr)));
       //    array_unshift($arr, array_keys(reset($arr)));
       //    echo GetHtmlTable($arr, 'th');
    }


    /** getProposalByID function
     * @param $change_id
     */
    function getProposalByID($change_id) {
        $garr = new AA_GeneralizedArray();
        if ( !$change_id ) {
            return $garr;
        }
        $changes = GetTable2Array("SELECT `change_record`.*, `change`.resource_id
                                FROM `change` LEFT JOIN `change_record` ON `change`.id = `change_record`.change_id
                                WHERE `change`.id = '". quote($change_id)."'
                                ORDER BY `change_record`.selector, `change_record`.priority", '', 'aa_fields');

        if ( is_array($changes) ) {
            foreach($changes as $change) {
                if ( $change['type'] ) {
                    $value = $change['value'];
                    settype($value, $change['type']);
                    $garr->add($value, array($change['resource_id'], $change['selector']));
                }
            }
        }
        return $garr->getArray();
    }
}

// it should be in oposite direction - require metabase in all scripts, which
// then will require varset, but for now we will use this approach

if (!class_exists('AA_Metabase')) {
    require_once AA_INC_PATH . "metabase.class.php3";
}
?>