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

require_once "../../include/config.php3";
require_once AA_INC_PATH."constants.php3";
require_once AA_INC_PATH."locsess.php3";
require_once AA_INC_PATH."tabledit.php3";
require_once AA_INC_PATH."tv_common.php3";
require_once AA_INC_PATH."util.php3";
require_once "send_emails.php3";
require_once "tableviews.php3";

if ($cmd["modedit"]["update"]) {
    ProcessFormData("GetAlertsTableView", $val, $cmd);
}

require_once dirname(__FILE__). "/../../include/init_page.php3";

if (!$no_slice_id) {
    require_once menu_include();
}

// ----------------------------------------------------------------------------------------

set_collectionid();

$sess->register("tview");
if ($set_tview) {
    $tview = $set_tview;
}

$tableview = GetAlertsTableView($tview);

if (!is_array($tableview)) {
    go_url($sess->url(self_base()."index.php3?slice_id=$slice_id&Msg=Bad table view ID: $tview"));
    exit;
}
if (! $tableview["cond"] )  {
    MsgPage($sess->url(self_base()."index.php3"), _m("You have not permissions to add slice"));
    exit;
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo '<LINK rel=StyleSheet href="'.AA_INSTAL_PATH.'/tabledit.css" type="text/css"  title="TableEditCSS">';
echo "<TITLE>".$tableview["title"]."</TITLE></HEAD>";

// called before menu because of Item Manager
ProcessFormData("GetAlertsTableView", $val, $cmd);
if (!$no_slice_id) {
    showMenu($aamenus, $tableview["mainmenu"], $tableview["submenu"]);
}
echo "<TABLE width='100%'><TR valign=center><TD>";
echo "<H1>" . $tableview["caption"] . "</H1>";
if ($tableview["children"]) {
    echo "</TD><TD>";
    foreach ($tableview["children"] as $chviewid => $child) {
        echo "<FONT class='tabtxt'><B><a href='#$chviewid'>$child[header]</a></B></FONT> ";
    }
}
echo "</TD></TR></TABLE>";

PrintArray($err);
echo $Msg;
$script = $sess->url("tabledit.php3");

$tabledit = new tabledit($tview, $script, $cmd, $tableview, AA_INSTAL_PATH."images/", $sess, "GetAlertsTableView");
$err = $tabledit->view();
if ($err) {
    echo "<b>$err</b>";
}

if (!$err && $tview == "send_emails") {
    ShowCollectionAddOns();
}

if (!$err && $tview == "email_edit") {
    ShowEmailAliases();
}

if (!$err && $tview == "acf") {
    ShowSelectionTable();
}

if ($no_slice_id) {
    echo "</BODY></HTML>";
} else {
    HTMLPageEnd();
}
page_close();
?>
