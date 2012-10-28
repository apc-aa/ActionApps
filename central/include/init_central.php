<?php
/**
 * File contains definition of AA_Actionapps class - holding information about
 * one AA installation.
 *
 * Should be included to other scripts (as /admin/index.php3)
 *
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
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

// handle with PHP magic quotes - quote the variables if quoting is set off
function AddslashesDeep($value) {
    return is_array($value) ? array_map('AddslashesDeep', $value) : addslashes($value);
}

function StripslashesDeep($value) {
    return is_array($value) ? array_map('StripslashesDeep', $value) : stripslashes($value);
}

// global variables should be quoted (since old AA code rely on that fact),
// however the new code should use $_POST, which are NOT quoted

if (!get_magic_quotes_gpc()) {
    // Overrides GPC variables
    foreach ($_GET as $k => $v) {
        $kk = AddslashesDeep($v);
    }
    foreach ($_POST as $k => $v) {
        $kk = AddslashesDeep($v);
    }
    foreach ($_COOKIE as $k => $v) {
        $kk = AddslashesDeep($v);
    }
}

if ( get_magic_quotes_gpc() ) {
    $_POST   = StripslashesDeep($_POST);
    $_GET    = StripslashesDeep($_GET);
    $_COOKIE = StripslashesDeep($_COOKIE);
}



require_once dirname(__FILE__). "/../../include/config.php3";

define('AA_CENTRAL_PATH', AA_BASE_PATH. "central/include/");

require_once AA_INC_PATH.     "mgettext.php3";
require_once AA_INC_PATH.     "locauth.php3";
require_once AA_INC_PATH.     "request.class.php3";
require_once AA_CENTRAL_PATH. 'actionapps.class.php';
require_once AA_INC_PATH.     'scroller.php3';  // we need it, because there coud be scroller stored in session (in manager)

?>
