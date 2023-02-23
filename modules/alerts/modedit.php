<?php
/**
 * This script provides the "Design", "Emails" and "Settings" pages in "Alerts Admin" menu
 * and the "User Manager" pages in Alerts. It mainly shows various TableViews
 * (see DOCUMENTATION: doc/tabledit.html, doc/tabledit_developer.html, doc/tableview.html).
 *
 * Params: $set_tview -- required, ID of the table view to be shown
 *
 * @package Alerts
 * @version $Id$
 * @author Jakub Adamek <jakubadamek@ecn.cz>, Econnect, December 2002
 * @copyright Copyright (C) 1999-2002 Association for Progressive Communications
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

require_once __DIR__."/../../include/config.php";
require_once __DIR__ . "/../../include/constants.php";
require_once __DIR__ . "/../../include/locsess.php";
require_once __DIR__ . "/../../include/tabledit.php";
require_once __DIR__ . "/../../include/tabledit_util.php";
require_once __DIR__ . "/../../include/tv_common.php";
require_once __DIR__ . "/../../include/tv_email.php";
require_once __DIR__ . "/../../include/util.php";
require_once __DIR__ . "/send_emails.php";
require_once __DIR__ . "/tableviews.php";


// creating new module
if ($_GET['no_slice_id']) {
    $set_tview                   = "modedit";
    $cmd[$set_tview]["show_new"] = 1;
    $no_slice_id                 = true;
}

if ($_POST['cmd']['modedit']['update']) {
    ProcessFormData("GetAlertsTableView", $_POST['val'], $_POST['cmd']);
    if ($_POST['cmd']['modedit']['update']['__new__']) {   // new alerts
        go_url(StateUrl(self_base() . "index.php3"));
        exit;
    }
}


const AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../../include/init_page.php";

$slice_id = AA::$module_id;

// ----------------------------------------------------------------------------------------

set_collectionid();

$sess->register("tview");
if ($set_tview) {
    $tview = $set_tview;
}

$tableview = GetAlertsTableView($tview);

if (!is_array($tableview)) {
    go_url(StateUrl(self_base()."index.php3?Msg=Bad table view ID: $tview"));
    exit;
}
if (! $tableview["cond"] )  {
    MsgPage(_m("You have not permissions to add slice"));
    exit;
}

$apage = new AA_Adminpageutil($tableview["mainmenu"], $tableview["submenu"]);
$apage->setModuleMenu('modules/alerts');
$apage->setTitle($tableview["caption"]);
$apage->addRequire(get_aa_url('tabledit.css?v='.AA_JS_VERSION, '', false ));
$apage->setForm();
$apage->printHead($err, $Msg);

// called before menu because of Item Manager
ProcessFormData("GetAlertsTableView", $val, $cmd);

echo "<TABLE width='100%'><TR><TD>";
if ($tableview["children"]) {
    echo "</TD><TD>";
    foreach ($tableview["children"] as $chviewid => $child) {
        echo "<span><B><a href='#$chviewid'>$child[header]</a></B></span> ";
    }
}
echo "</TD></TR></TABLE>";

$script = StateUrl("modedit.php");

// fixed bug when inner table is not updated to new module_id
if (!$cmd) {
    $cmd = [$tview => ['edit'=> [AA::$module_id=>1]]];
}

$tabledit = new tabledit($tview, $script, $cmd, $tableview, AA_INSTAL_PATH."images/", $sess, "GetAlertsTableView");

$err = $tabledit->view();

if ($err) {
    echo "<b>$err</b>";
}

if (!$err && $tview == "send_emails") {
    ShowCollectionAddOns();
}

if (!$err && $tview == "email_edit") {
    echo AA_Collection::getEmailAliasesHelpbox();
}

if (!$err && $tview == "acf") {
    showSelectionTable();
}

if ($no_slice_id) {
    echo "</BODY></HTML>";
} else {
    $apage->printFoot();
}
