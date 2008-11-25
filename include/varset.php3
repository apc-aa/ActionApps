<?php
/**
 * Class AA_Validate
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
                return "'" . q_pack_id($this->value) ."'";
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
    /** huh function
     *
     */
    function huh() {
        echo "$this->name($this->type) -> $this->value <br>\n";
    }
}

class Cvarset {
    var $vars;        // array of variables
    var $db;          // database handler
    var $just_print;  // debug option - just prints the query (not implemented for all methods!!!)

    /** Cvarset function
     *  constructor - also good for filling the varset
     * @param $arr
     */
    function Cvarset( $arr=null ) {
        $this->db         = null;
        $this->just_print = false;

        foreach ( (array)$arr as $varname => $value ) {
            if ( $varname ) {
                $this->add($varname, 'text', $value);
            }
        }
    }

    function setDebug() {
        $this->just_print = true;
    }

    /** clear function
     *  clears whole varset
     */
    function clear() {
        $this->vars="";
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
        switch ($this->db->num_rows()) {
            case 0: return $this->makeINSERT($tablename);
            case 1: return $this->makeUPDATE($tablename);
            default:
            // Error: there are several rows with the same key variables
            return "Error using makeINSERTorUPDATE: " . $this->db->num_rows(). " rows match the query";
        }
    }
    /** lastInsertId function
     * @param $tablename
     */
    function lastInsertId($tablename) {
        $this->_doQuery("SELECT LAST_INSERT_ID() AS lid FROM $tablename");
        $this->db->next_record();
        return $this->db->f("lid");
    }
    /** huh function
     * @param $text
     */
    function huh($txt="") {
        echo "Varset: $txt";
        foreach ( $this->vars as  $varname => $variable ) {
            $variable->huh();
        }
    }

    // Static //

    /** sqlin function
     *  Returns part of SQL command ised in WHERE, column = value, or column IN (...)
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
        } elseif ( count($values) == 0 ) {
            return "2=1";
        }
        return "$column IN (". join(',', $arr) .")";
    }
}

// it should be in oposite direction - require metabase in all scripts, which
// then will require varset, but for now we will use this approach

if (!class_exists('AA_Metabase')) {
    require_once AA_INC_PATH . "metabase.class.php3";
}
?>