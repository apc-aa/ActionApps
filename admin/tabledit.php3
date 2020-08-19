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

require_once __DIR__."/../include/init_page.php3";
require_once __DIR__."/../include/tabledit.php3";
//require_once menu_include();   //show navigation column depending on $show

// ----------------------------------------------------------------------------------------

$sess->register("tview");
if ($set_tview) {
    $tview = $set_tview;
}

require_once __DIR__."/../include/tv_common.php3";
require_once __DIR__."/../include/tv_misc.php3";

// is tableview defined in special file (tableviews.php3)?
if ( ($tview{0} == "a") OR ( substr($tview,0,5) =='polls') ) {
    $func = "GetTableView";
    require_once __DIR__."/../include/tableviews.php3";
} else {
    $func = "GetMiscTableView";
}

$tableview = $func($tview);

if (!is_array($tableview)) {
    go_url (StateUrl(self_base()."index.php3?slice_id=$slice_id&Msg=Bad table view ID: $tview"));
    exit;
}
if (! $tableview["cond"] ) {
    MsgPage (StateUrl(self_base()."index.php3"), _m("You have not permissions to this page"));
    exit;
}

$apage = new AA_Adminpageutil($tableview["mainmenu"],  $tableview["submenu"]);
$apage->setTitle($tableview["title"]);
if ($tableview["modulemenu"]) {
    $apage->setModuleMenu($tableview["modulemenu"]);
}
$apage->setForm();
$apage->addRequire(get_aa_url('tabledit.css?v='.AA_JS_VERSION, '', false ));
//$apage->addRequire(get_aa_url('javascript/js_lib.min.js?v='.AA_JS_VERSION, '', false ));
$apage->printHead($err, $Msg);

ProcessFormData($func, $val, $cmd);

$script = StateUrl("tabledit.php3");

$tabledit = new tabledit($tview, $script, $cmd, $tableview, AA_INSTAL_PATH."images/", $sess, $func);
$err      = $tabledit->view($where);
if ($err) {
    echo "<b>$err</b>";
}

if (!$err && $tview == "email_edit") {
    ShowEmailAliases();
}

$apage->printFoot();
