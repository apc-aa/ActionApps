<?php

/**
 * Class Cvariable, Cvarset
 *
 *
 * PHP version 7.2+
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
 * @link      https://www.apc.org/ APC
 *
*/

use AA\IO\DB\DB_AA;
use AA\Util\ChangesMonitor;

/** Cvarset - class for storing variables
 *          - simplifies database manipulation (by Cvarset class below)
 */

class Cvariable
{
    public $name;
    public $type;
    public $value;
    /** Is it a key value? key values are used in UPDATE -> WHERE,
    *  INSERT -> VALUES.  See also makeINSERTorUPDATE. */
    public $iskey;

    /** Cvariable function
     *  constructor
     * @param string $name
     * @param string $type - integer | number | unpacked | quoted | null | float | date | text
     * @param string $value
     * @param bool $iskey
     */
    public function __construct($name, $type, $value, $iskey = false)
    {
        $this->name  = $name;
        $this->type  = $type;
        $this->value = $value;
        $this->iskey = $iskey;
    }

    /** getValue function
     *
     */
    public function getValue()
    {
        return ($this->type === 'quoted') ? stripslashes($this->value) : $this->value;
    }

    /** getSQLValue function
     *
     * @param AA_MbC $columns
     *
     * @return float|int|string
     */
    public function getSQLValue($column = null)
    {
        $ret = '';
        $quote = true;

        switch ($this->type) {
            case "integer":
            case "number":
                // grrr: if $var=0 then $var=""!!!
                $ret = ($this->value == "") ? 0 : $this->value;
                break;
            case "unpacked":
                if (is_long_ids($this->value)) {   // one id, or several concatenated (constant.ancestors)
                    $ret = '0x' . $this->value;
                    $quote = false;
                } else {
                    huhe('There should be unpacked long id: ' . $this->value . "(in $this->name)");
                }
                break;
            case "quoted":
                $ret = $this->value;
                break;
            case "null":
                $ret = "NULL";
                $quote = false;
                break;
            case "float":
            case "date":
            case "text":
            default:
                $ret = quote($this->value);
        }

        if ($column) {
            switch ($column->getFieldType()) {
                case 'int':    return (int) $ret;
                case 'float':  return (float) $ret;
            }
        }
        // text
        return $quote ? "'$ret'" : $ret;
    }
}

class Cvarset
{
    /** @var array Cvariable */
    private array $vars = [];          // array of variables
    private bool $just_print = false;    // debug option - just prints the query (not implemented for all methods!!!)

    /** Cvarset function
     *  constructor - also good for filling the varset
     * @param $arr
     */
    public function __construct($arr = [])
    {
        $TRANS              = ['i' => 'number', 's' => 'text', 'q' => 'quoted', 'l' => 'unpacked'];

        foreach ($arr as $def) {
            $this->add($def[0], isset($def[2]) ? $TRANS[$def[2]] : 'text', $def[1]);
        }
    }

    public function setDebug(): static
    {
        $this->just_print = true;
        return $this;
    }

    /** clear function
     *  clears whole varset
     */
    public function clear(): static
    {
        $this->vars = [];
        return $this;
    }

    /** Returns true, is the varset do not contain any variable */
    public function isEmpty(): bool
    {
        return empty($this->vars);
    }

    /** get variable value
     *
     * @param  string  $varname
     */
    public function get(string $varname): string|false
    {
        $cv = $this->vars[$varname];
        return $cv ? $cv->getValue() : false;
    }

    /** getSQLvalue function
     * @param $varname
     * @return bool
     */
    public function getSQLvalue($varname)
    {
        $cv = $this->vars[$varname];
        return ($cv ? $cv->getSQLvalue() : false);
    }

    /** add function
     *  add variable to varset
     * @param string $varname
     * @param string $type - integer | number | unpacked | quoted | null | float | date | text
     * @param string $value
     * @param bool   $iskey
     */
    public function add($varname, $type = "text", $value = "", $iskey = false): void
    {
        $this->vars[$varname] = new Cvariable($varname, $type, $value, $iskey);
    }

    /** addglobals function
     *  add global variables to varset (names in $arr)
     * @param string[] $arr
     * @param string   $type
     */
    public function addglobals(array $arr, $type = "quoted"): void
    {
        foreach ($arr as $varname) {
            $this->vars[$varname] = new Cvariable($varname, $type, $GLOBALS[$varname]);
        }
    }

    /** addkey function
     *  add key variable to varset (see Cvariable)
     * @param string $varname
     * @param string $type - integer | number | unpacked | quoted | null | float | date | text
     * @param string $value
     */
    public function addkey($varname, $type = "text", $value = ""): void
    {
        $this->vars[$varname] = new Cvariable($varname, $type, $value, true);
    }

    /** remove function
     *  remove variable from varset
     * @param string $varname
     */
    public function remove($varname): void
    {
        unset($this->vars[$varname]);
    }

    /**  set variable value
     * @param string $varname
     * @param string $value
     * @param string $type - integer | number | unpacked | quoted | null | float | date | text
     */
    public function set($varname, $value, $type = ""): void
    {
        if ($type == "") {
            $v    = $this->vars[$varname];
            $type = $v->type;
        }
        $this->add($varname, $type, $value);   // it must be assigned this way, because $v is just copy
    }

    /**
     *  return variable value
     * @param string $varname
     */
    public function value($varname)
    {
        return $this->vars[(string) $varname]->value;
    }

    /** get key=>value array of variables and values */
    public function getArray(): array
    {
        $ret = [];
        foreach ($this->vars as $varname => $variable) {
            if (!$variable->iskey) {
                $ret[$varname] = $variable->getValue();
            }
        }
        return $ret;
    }

    /** setFromArray function
     *  set variables values due to array
     * @param array $arr
     */
    public function setFromArray($arr): void
    {
        foreach (array_keys($this->vars) as $varname) {
            $this->set($varname, $arr[$varname]);
        }
    }

    /** resetFromRecord function
     *  Fills varset with data grabbed from database ($db->record())
     * @param $record
     */
    public function resetFromRecord($record): void
    {
        $this->clear();
        foreach ($record as $name => $value) {
            if (!ctype_digit((string) $name)) {
                $this->add($name, 'text', $value);
            }
        }
    }

    /** addArray function
     *  Add text and number variables from arrays to varset
     * @param string[] $text_fields
     * @param string[] $num_fields
     */
    public function addArray(array $text_fields, array $num_fields = []): void
    {
        foreach ($text_fields as $name) {
            $this->add($name, "text");
        }
        foreach ($num_fields as $name) {
            $this->add($name, "number");
        }
    }

    /** _doQuery function
     *  Private function: executes qiven query)
     *
     * @param $SQL
     * @param $nohalt     'nohalt' - do not halt on database error
     *
     * @return bool|int   false on error, int on success.
     *                    If INSERT - returns last inserted id or PHP_INT_MAX if not provided
     *                    If SELECT - returns number of returned rows
     *
     */
    public function _doQuery($SQL, $nohalt = null): bool|int
    {
        if ($this->just_print) {
            huhl($SQL);
            return true;
        }
        $db = getDB();
        if ($nohalt === 'nohalt') {
            $retval = $db->query_halt($SQL, 'no');
        } else {
            $retval = $db->query($SQL);
        }
        if ($retval) {
            if (str_starts_with($SQL, 'INSERT')) {
                $retval = $db->last_insert_id() ?: PHP_INT_MAX;
            } elseif (str_starts_with($SQL, 'SELECT')) {
                $retval = $db->num_rows();   // this is quick - no DB roundtrip
            }
        }
        freeDB($db);
        return $retval;
    }

    /** _makeInsertReplace function
     * @param $command
     * @param $tablename
     * @return string
     */
    public function _makeInsertReplace($command, $tablename): string
    {
        if (!($cols = AA::Metabase()->getColumns($tablename))) {
            return '';
        }
        $arr  = [];

        foreach ($cols as $colname => $coldef) {
            if (isset($this->vars[$colname])) {
                $arr[$colname] = $this->vars[$colname]->getSQLValue($coldef);
            }
        }

        if (!$arr) {
            return '';
        }
        return "$command INTO `$tablename` (`" . implode('`,`', array_keys($arr)) . "`) VALUES (" . implode(',', array_values($arr)) . ")";
    }

    //function _makeInsertReplace($command, $tablename) {
    //    $foo      = $tablename ? "$command INTO `$tablename`" : '';
    //    $predznak = " ( ";
    //    foreach ( $this->vars as  $varname => $variable ) {
    //        $foo .= $predznak . "`$varname`";
    //        $predznak = ", ";
    //    }
    //    $predznak = " ) VALUES ( ";
    //    foreach ( $this->vars as  $varname => $variable ) {
    //        $foo .= $predznak . $variable->getSQLValue();
    //        $predznak = ", ";
    //    }
    //
    //    return $foo . " ) " ;
    //}


    /** makeINSERT function
     *  Makes SQL INSERT clause from varset
     * @param $tablename
     * @return string
     */
    public function makeINSERT($tablename): string
    {
        return $this->_makeInsertReplace('INSERT', $tablename);
    }

    /** doInsert function
     * @param $tablename
     * @param $nohalt     'nohalt' - do not halt on database error
     * @return bool|int   0|false on error, int on success. If INSERT, returns last inserted id or PHP_INT_MAX if not provided
     */
    public function doInsert($tablename, $nohalt = null): bool|int
    {
        return $this->_doQuery($this->makeINSERT($tablename), $nohalt);
    }

    /** makeUPDATE function
     *  Makes SQL UPDATE clause from varset
     * @param $tablename
     * @return string
     */
    public function makeUPDATE($tablename = ""): string
    {
        $updates = [];
        foreach ($this->vars as $varname => $variable) {
            if (!$variable->iskey) {
                $updates[] = "`$varname`" . "=" . $variable->getSQLValue();
            }
        }
        if ($tablename) {
            $retval = "UPDATE `$tablename` SET";
        }
        $retval .= " " . implode(", ", $updates);
        if ($where = $this->makeWHERE()) {     // assignment
            $retval .= " WHERE " . $where;
        }
        return $retval;
    }


    /** before doUpdate you can call saveHistory() to store changes */
    private function saveHistory($tablename, $id): string
    {
        $data = DB_AA::select([], $this->makeSELECT($tablename));
        if (count($data) !== 1) {
            // Error: there are several rows with the same key variables
            return "Error using doUpdate: " . count($data) . " rows match the query";
        }
        $data = $data[0]; // we have only one row
        $new  = $this->getArray();
        foreach (AA_Metabase::getPacked($tablename) as $col) {
            $data[$col] = unpack_id($data[$col]);
            // an id that is not 32 hex (old data) is sent packed - compare it unpacked too
            if (isset($new[$col]) && !is_long_ids($new[$col])) {
                $new[$col] = unpack_id($new[$col]);
            }
        }
        ChangesMonitor::singleton()->addHistory(ChangesMonitor::getDiff($id, $data, $new));
        return '';
    }

    /** doUpdate function
     * @param        $tablename
     * @param        $nohalt
     * @param string $history_key 16 character long resource id - if presented, history is written to database
     */
    public function doUpdate($tablename, $nohalt = null, $history_key = ''): bool|int
    {
        if ($history_key) {
            $this->saveHistory($tablename, substr($history_key, 0, 32));
        }
        return $this->_doQuery($this->makeUPDATE($tablename), $nohalt);
    }

    /** doREPLACE function
     * @param $tablename
     * @param $nohalt
     * @return int|void
     */
    // be sure, you have defined key field
    public function doREPLACE($tablename, $nohalt = null)
    {
        // we do no longer use REPLACE SQL command - it is not implemented in
        // some DB engines (it is not ANSI SQL) and even in MySQL it works bad
        // with autoincremented fields
        return $this->_doQuery($this->makeINSERTorUPDATE($tablename), $nohalt);
    }

    /** doTrueReplace function
     * @param $tablename
     * @param $nohalt
     * @return int|void
     */
    // be sure, you have defined key field
    public function doTrueReplace($tablename, $nohalt = null)
    {
        // uses REPLACE SQL command - it is not implemented in some DB engines
        // (it is not ANSI SQL) and even in MySQL it works bad
        // with autoincremented fields
        return $this->_doQuery($this->_makeInsertReplace('REPLACE', $tablename), $nohalt);
    }

    /** doTruncate function - deletes all data from tabe
     * @param $tablename
     * @param $nohalt
     * @return int|void
     */
    public function doTruncate($tablename, $nohalt = null)
    {
        return $this->_doQuery("TRUNCATE $tablename", $nohalt);
    }

    /** makeSELECT function
     * @param $tablename
     * @return string
     */
    public function makeSELECT($tablename): string
    {
        $where = $this->makeWHERE();
        return ($where ? "SELECT * FROM `$tablename` WHERE " . $where
                         : "SELECT * FROM `$tablename`");
    }

    /** makeDELETE function
     * @param $tablename
     * @param $where
     * @return string
     */
    public function makeDELETE($tablename, $where = null): string
    {
        if (is_null($where)) {
            $where = $this->makeWHERE();
        }
        return ($where ? "DELETE FROM `$tablename` WHERE " . $where : 'Error');
    }

    /** doDelete function
     * @param $tablename
     * @param $nohalt
     * @return int|void
     */
    public function doDelete($tablename, $nohalt = null)
    {
        return $this->_doQuery($this->makeDELETE($tablename), $nohalt);
    }

    /** doDeleteWhere function
     * @param $tablename
     * @param $where
     * @param $nohalt
     * @return int|void
     */
    public function doDeleteWhere($tablename, $where, $nohalt = null)
    {
        return $this->_doQuery($this->makeDELETE($tablename, $where), $nohalt);
    }

    /** makeWHERE function
     * @param $tablename
     * @return string
     */
    public function makeWHERE($tablename = ""): string
    {
        $where = "";
        foreach ($this->vars as $varname => $variable) {
            if ($variable->iskey) {
                if ($where) {
                    $where .= " AND ";
                }
                if ($tablename) {
                    $varname = $tablename . "." . $varname;
                }
                $where .= $varname . "=" . $variable->getSQLValue();
            }
        }
        return $where;
    }

    /** makeINSERTorUPDATE function
     *  This function looks into the given table and if the row exists, it is
     *  updated, if not then inserted. Add always all key fields by addkey()
     *  to the varset before using this function.
     * @param $tablename
     * @return string
     */
    public function makeINSERTorUPDATE($tablename): string
    {
        $where = $this->makeWHERE();
        $SQL = "SELECT count(*) as cnt FROM `$tablename`" . ($where ? " WHERE $where" : "");
        $rows = DB_AA::select1('cnt', $SQL);

        if ($rows > 1) {
            throw new RuntimeException("Error using makeINSERTorUPDATE: $rows rows match the query for $tablename");
        }

        return match ($rows) {
            0       => $this->makeINSERT($tablename),
            1       => $this->makeUPDATE($tablename),
        };
    }

    // Static //

    /** sqlin function
     *  Returns part of SQL command sed in WHERE, column = value, or column IN (...)
     * @param $column
     * @param $values
     * @return string
     */
    public static function sqlin($column, $values): string
    {
        if (!is_array($values)) {
            $values = [$values];
        }
        $arr = [];
        foreach ((array) $values as $v) {
            if ($v != '') {
                $arr[] = "'" . quote($v) . "'";
            }
        }
        if (count($arr) === 1) {
            return "$column = " . $arr[0];
        }

        if (count($arr) === 0) {
            return "2=1";
        }
        return "$column IN (" . implode(',', $arr) . ")";
    }
}


/** is_field_type_numerical function
 * @param $field_type
 * @return bool
 */
function is_field_type_numerical($field_type): bool
{
    return in_array($field_type, ["float","double","decimal","int","timestamp"]);
}

// -----------------------------------------------------------------------------
/** CopyTableRows function
 *  Copies rows within a table changing only given columns and omitting given columns.
 *   @author Jakub Adámek
 *   @return bool  true if all additions succeed, false otherwise
 *
 *   @param string $table    table name
 *   @param string $where    where condition (filter)
 *   @param array  $set_columns  array ($column_name => $value, ...) - fields the value of which will be changed;
 *                               RAW values (pack_id(), not q_pack_id()) - they are escaped here
 *   @param array  $omit_columns [optional] array ($column_name, ...) - fields to be omitted
 *   @param array  $id_columns   [optional] array ($column_name, ...) - fields with the 16 byte ID to be generated for each row a new one
 */
function CopyTableRows(string $table, $where, $set_columns, $omit_columns = "", $id_columns = ""): bool
{
    if (!$omit_columns) {
        $omit_columns = [];
    }
    if (!$id_columns) {
        $id_columns = [];
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
            } elseif (in_array($col["name"], $id_columns)) {
                $val  = new_id();
                $type = 'unpacked';   // sent as a 0x… literal, so no byte of the id needs escaping
            } else {
                $val = $datarow[$col["name"]];
            }

            $varset->set($col["name"], $val, $type);
        }

        if (!$varset->doINSERT($table)) {
            return false;
        }
    }
    return true;
}


// it should be in oposite direction - require metabase in all scripts, which
// then will require varset, but for now we will use this approach

if (!class_exists('AA_Metabase')) {
    require_once __DIR__ . "/metabase.class.php";
}
