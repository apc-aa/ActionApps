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
https://www.apc.org/

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

// ----- input variables normalization - start --------------------------------

// This code handles with "magic quotes" and "register globals" PHP (<5.4) setting
// It make us sure, taht
//  1) in $_POST,$_GET,$_COOKIE,$_REQUEST variables the values are not quoted
//  2) the variables are imported in global scope and is quoted
// We are trying to remove any dependecy on the point 2) and use only $_* superglobals
function AddslashesDeep($value)   { return is_array($value) ? array_map('AddslashesDeep',   $value) : addslashes($value);   }

foreach ($_REQUEST as $k => $v) {
    $$k = AddslashesDeep($v);
}
// ----- input variables normalization - end ----------------------------------

require_once __DIR__."/../../include/config.php";
require_once __DIR__ . "/../../include/mgettext.php";
require_once __DIR__ . "/../../include/locauth.php";
require_once __DIR__."/actionapps.class.php";

if (!is_long_id(AA::$module_id = $_POST['change_id'] ?: $_GET['change_id'] ?: $_POST['module_id'] ?: $_GET['module_id'] ?: $_POST['slice_id'] ?: $_GET['slice_id'])) {
    AA::$module_id = '';
}
$slice_id = AA::$module_id;
