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

// my change

// script for MySQL database update

// this script updates the database to last structure, create all tables, ...
// can be used for upgrade from apc-aa v. >= 1.5 or for create new database


ini_set('display_errors', 'On');
error_reporting(E_ERROR | E_WARNING | E_PARSE);


/**
 * Handle with PHP magic quotes - quote the variables if quoting is set off
 * @param mixed $value the variable or array to quote (add slashes)
 * @return mixed the quoted variables (with added slashes)
 */
function StripslashesDeep($value) {
    return is_array($value) ? array_map('StripslashesDeep', $value) : stripslashes($value);
}

if ( get_magic_quotes_gpc() ) {
    $_POST    = StripslashesDeep($_POST);
    $_GET     = StripslashesDeep($_GET);
    $_COOKIE  = StripslashesDeep($_COOKIE);
}

// need config.php3 to set db access, and phplib, and probably other stuff
define ('AA_INC_PATH', "../include/");

require_once AA_INC_PATH."config.php3";
require_once AA_INC_PATH."locsess.php3";   // DB_AA definition

/** Helper functions
 *  We are implementing all the functions here again, altrough you will be able
 *  to find them implemented in other parts of AA. The reason is, that
 *  we we want this script standalone - without any unnecessary dependencies
 */

if (!is_callable('getDB')) {
    $spareDBs = array();

    /** getDB function */
    function getDB() {
        global $spareDBs;
        if (!($db = array_pop($spareDBs))) {
            $db = new DB_AA;
        }
        return $db;
    }

    /** freeDB function */
    function freeDB($db) {
        global $spareDBs;
        array_push($spareDBs,$db);
    }
}


if (!is_callable('_m')) {
    function _m($id, $params = 0) {
        $retval = $id;
        if (is_array($params)) {
            $foo = "#$&*-";
            $retval = str_replace ('\%', $foo, $retval);
            for ($i = 0; $i < count ($params); $i ++) {
                $retval = str_replace ("%".($i+1), $params[$i], $retval);
            }
            $retval = str_replace ($foo, "%", $retval);
        }
        return $retval;
    }
}


/** GetTable2Array function
 *  function converts table from SQL query to array
 * @param $SQL
 * @param $key    - return array's key - 'NoCoLuMn' | '' | 'aa_first' | <database_column> | 'unpack:<database_column>'
 * @param $values - return array's val - 'aa_all' |
 *                                 'aa_mark' |
 *                                 'aa_fields' |
 *                                 <database_column> |
 *                                 'unpack:<database_column>' |
 *                                 true
 */
if (!is_callable('GetTable2Array')) {
    function GetTable2Array($SQL, $key="id", $values='aa_all') {
        $db = getDB();
        $db->tquery($SQL);
        while ($db->next_record()) {
            if ($values == 'aa_all') {
                $val = $db->Record;
            } elseif ($values == 'aa_mark') {
                $val = true;
            } elseif (substr($values,0,7) == 'unpack:') {
                $val = unpack_id128($db->f(substr($values,7)));
            } elseif (is_string($values) AND isset( $db->Record[$values] )) {
                $val = $db->Record[$values];
            } else {  // true or 'aa_fields'
                $val = DBFields($db);
            }

            if ( $key == 'aa_first' ) {
                freeDB($db);
                return $val;
            } elseif ( ($key == "NoCoLuMn") OR !$key ) {
                $arr[] = $val;
            } elseif ( substr($key,0,7) == 'unpack:' ) {
                $arr[unpack_id128($db->f(substr($key,7)))] = $val;
            } else {
                $arr[$db->f($key)] = $val;
            }
        }
        freeDB($db);
        return isset($arr) ? $arr : false;
    }
}

if (!is_callable('unpack_id')) {
    function unpack_id($packed_id){
        if ((string)$packed_id == "0") {
            return "0";
        }
        $foo = bin2hex($packed_id);  // unpack("H*", $str) does not work in PHP 4.0.3 so bin2hex used
        return (string)$foo;
    }
}

if (!is_callable('q_pack_id')) {
    function q_pack_id($unpacked_id){
        return quote(((string)$unpacked_id == "0" ? "0" : @pack("H*",trim($unpacked_id))));
    }
}

if (!is_callable('quote')) {
    function quote($str) {
      return addslashes($str);
    }
}


// do not reorder those requires because of metabase and varset dependency
require_once dirname(__FILE__)."/metabase.class.php3";
require_once dirname(__FILE__)."/varset.php3";
require_once dirname(__FILE__)."/update.optimize.class.php";

class AA_SQL_Updater {
    var $messages = array();

    function test() {
        $optimizers = $this->getOptimizers();
        $msg        = '<table>';
        $result     = true;
        foreach ($optimizers as $optimizer) {
            $msg .= '<tr><td>'. $optimizer->name() .'</td>';
            $res  = $optimizer->test();
            if (!$res) {
                $result = false;
            }
            $msg .= '<td>'. ($res ? 'OK' : 'Problem') .'</td>';
            $msg .= '<td>'. $optimizer->report(). '</td></tr>';
        }
        $msg .= "</table>";
        $this->message($msg);
        return $result;
    }

    function restore() {
        $optimizer = new AA_Optimize_Restore_Bck_Tables();
        $optimizer->repair();
        $this->message($optimizer->report());
        return true;
    }

    function update() {
        $optimizers = $this->getOptimizers();
        $msg        = '<table>';
        $result     = true;
        foreach ($optimizers as $optimizer) {
            $msg .= '<tr><td>'. $optimizer->name() .'</td>';

            if ( $optimizer->test() ) {
                $msg .= '<td>test passed - skipping</td></tr>';
            } else {
                $optimizer->clear_report();
                $res  = $optimizer->repair();
                if (!$res) {
                    $result = false;
                }
                $msg .= '<td>'. $optimizer->report(). '</td></tr>';
            }
        }
        $msg .= "</table>";
        $this->message($msg);
        return $result;
    }

    /** getOptimizers function
     *  Return names of all known AA classes, which begins with AA_Optimize
     */
    function getOptimizers() {
        $optimizers = array();

        // php4 returns classes all in lower case :-(
        $mask          = 'aa_optimize_';
        $mask_length   = strlen($mask);
        foreach (get_declared_classes() as $classname) {
            if ( substr(strtolower($classname),0,$mask_length) == $mask ) {
                $instance = new $classname();
                if ($instance->isType('sql_update')) {
                    // we need the optimizers sorted by its priority - that's why the $key
                    $optimizers[sprintf("%05s",$instance->priority()).$classname] = $instance;
                }
            }
        }
        ksort($optimizers);
        return $optimizers;
    }

    /** Message function
    * @param $text
    */
    function message($text) {
        $this->messages[] = $text;
    }

    /** Report function
    * @return messages separated by <br>
    */
    function report()       {
        return join('<br>', $this->messages);
    }

    /** Clear report function
    * unsets all current messages
    */
    function clear_report() {
        unset($this->messages);
        $this->messages = array();
    }

}


if (!$_GET['silent']) {
    echo '
      <!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN">
      <html>
      <head>
        <title>APC-AA database update script</title>
      </head>
      <body>
      <h1>ActionApps database update</h1>
      <p>This script is written to be not destructive. It creates temporary tables
         first, then copies data from old tables to the temporary ones (tmp_*) and
         after successfull copy it drops old tables and renames temporary ones to
         right names. Then it possibly updates common records (like default field
         definitions, module templates, constants and templates).</p>
      <p><font color="red">However, it is strongly recommended backup your current
      database !!!</font><br><br>Something like:<br><code>mysqldump --lock-tables -h '.DB_HOST.' -u '.DB_USER.' -p --opt '.DB_NAME.' &gt; ./'.DB_NAME.'_'.date('ymd').'.sql</code></p>

      <form name="f" action="' .$_SERVER['PHP_SELF'] .'">
      ';
}


$updater = new AA_SQL_Updater();

if ($_GET['update']) {
    if ( substr( DB_PASSWORD, 0, 5 ) != $_GET['dbpw5'] ) {
        echo 'Bad password. Please fill first five characters from aa database password in the field bellow';
    } else {
        if (!$_GET['fire']) {
            AA_Optimize::justPrint(true);
        }
        $status = $updater->update();
        echo ($status ? 'OK ' : 'Err ') . $updater->report();
    }
}
elseif ( $_GET['restore']) {
    if ( substr( DB_PASSWORD, 0, 5 ) != $_GET['dbpw5'] ) {
        echo 'Bad password. Please fill first five characters from aa database password in the field bellow';
    } else {
        if (!$_GET['fire']) {
            AA_Optimize::justPrint(true);
        }
        $status = $updater->restore();
        echo ($status ? 'OK ' : 'Err ') . $updater->report();
    }
}
elseif ( $_GET['dotest']) {
    $status = $updater->test();
    echo ($status ? 'OK ' : 'Err ') . $updater->report();
}


if (!$_GET['silent']) {
    echo '  For update or restore you need to know database password (see DB_PASSWORD in config.php3 file) - it is from security reasons. <br><br>
            Fill in first five characters of the password here

            <input type="text" name="dbpw5" size="5" maxsize="5" value="'.$_GET['dbpw5'].'"><br>
            Write to database <input type="checkbox" name="fire" value="1"'.($_GET['fire'] ? ' checked' : ''). '"><br>
            <small>Check this for real work with writing to database</small>
            <br><br>

            <input type="submit" name="dotest" value="Test">
            <input type="submit" name="update" value="Install / Update">
            <input type="submit" name="restore" value="Restore">
           </form>
          </body>
          </html>';
}
?>
