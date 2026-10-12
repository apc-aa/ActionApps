<?php

/**
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/
// expected $del - unpacked id of slice to delete

use AA\Central\ResponderPolicy;
use AA\Util\FormToken;
use AA\Util\InputVars;

require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/feeding.php";

require_once __DIR__ . "/../include/modutils.php";

try {
    // array ( '1f5e8011cce…' => 1, )
    $del = InputVars::idPOST('del');
    // array ( 'Vymazat vyb…' => 1, )
    $delete = InputVars::boolPOST('delete');
    // array ( )
    $deletearr = InputVars::arrayPOST('deletearr');
    // array ( 'todelete' => 1, )
    $slices2show = InputVars::stringPOST('slices2show'); // alpha
    $cancel = InputVars::boolPOST('cancel');
    $token  = InputVars::stringPOST('token');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}


if ($cancel) {
    go_url(StateUrl(self_base() . "index.php"));
}

$deletearr = $del ? [$del] : (array) $deletearr;
if (!count($deletearr)) {
    MsgPage(_m("You don't have permissions to delete slice."));
    exit;
}

if (!IsSuperadmin()) {
    MsgPage(_m("You don't have permissions to delete slice."));
    exit;
}
// a deletion is for good: only from the form of slicedel.php, never from a link or a page elsewhere
if (ResponderPolicy::isForeignBrowserRequest($_SERVER) || !FormToken::checkPost(MODULE_DELETE_TOKEN, $token, $_SERVER)) {
    MsgPage(_m('The form has expired; reload the page and try again.'), get_admin_url('slicedel.php'));
}

$err = [];      // error array (Init - just for initializing variable
//AA::$debug = 127;

if (!AA_Module::deleteModules($deletearr)) {
    MsgPage(_m("No such module."), get_admin_url('slicedel.php'));
}
//echo AA::$dbg->duration_stat();
//exit;

Session::page_close();                                // to save session variables

// There is a bug in here, that typically if you go SliceAdmin->delete->AA->delete it
// will delete your current slice, and leave you nowhere to go to, you have to login again (mitra)
go_url(get_admin_url("slicedel.php", ['Msg' => _m("Slice successfully deleted, tables are optimized")]));
