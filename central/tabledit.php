<?php
/**
 *   Shows a Table View, allowing to edit, delete, update fields of a table
 *     Params:
 *         $set_tview -- required, name of the table view
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
 * @version   $Id$
 * @author    Jakub Adamek
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

// (c) Econnect, Jakub Adamek, December 2002
// DOCUMENTATION: doc/tabledit.html, doc/tabledit_developer.html, doc/tableview.html

const AA_REGISTER_GLOBALS = true;
require_once __DIR__."/../include/init_page.php3";
require_once __DIR__."/../include/tabledit.php3";

// ----------------------------------------------------------------------------------------

function GetCentalEditTableView() {
    return AA::Metabase()->getTableditConf('central_conf');
}

if ( !IsSuperadmin() ) {
    MsgPage(StateUrl(self_base()."index.php3"), _m("You have not permissions to this page"));
    exit;
}

$sess->register("tview");
$tview = 'centraledit';

//require_once __DIR__."/../include/tv_common.php3";
//require_once __DIR__."/../include/tv_misc.php3";

$tableview = GetCentalEditTableView();

ProcessFormData('GetCentalEditTableView', $val, $cmd);

$apage = new AA_Adminpageutil('central', 'addaa');
$apage->setTitle(_m("ActionApps Central - Edit"));
$apage->addRequire(get_aa_url('tabledit.css?v='.AA_JS_VERSION, '', false ));
$apage->setForm();
$apage->printHead($err, $Msg);

$script = StateUrl("tabledit.php");

$tabledit = new tabledit($tview, $script, $cmd, $tableview, AA_INSTAL_PATH."images/", $sess, $func);
$err      = $tabledit->view($where);
if ($err) {
    echo "<b>$err</b>";
}

$apage->printFoot();
