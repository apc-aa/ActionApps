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

// (c) Econnect, Jakub Adamek, December 2002
// DOCUMENTATION: doc/tabledit.html, doc/tabledit_developer.html, doc/tableview.html

/* Shows a Table View, allowing to edit, delete, update fields of a table
   Params:
       $set_tview -- required, name of the table view
*/

require "../../include/config.php3";
require $GLOBALS[AA_INC_PATH]."constants.php3";
require $GLOBALS[AA_INC_PATH]."locsess.php3";
require $GLOBALS[AA_INC_PATH]."tabledit.php3";
require $GLOBALS[AA_INC_PATH]."tv_common.php3";
require $GLOBALS[AA_INC_PATH]."util.php3";
require "tableviews.php3";

if ($cmd["modedit"]["update"]) 
    ProcessFormData ("GetAlertsTableView", $val, $cmd);

$directory_depth = "../";
require "$directory_depth../include/init_page.php3";
if (!$new_module)
    require $MODULES[$g_modules[$slice_id]['type']]['menu'];   

// ----------------------------------------------------------------------------------------

set_collectionid();

$sess->register("tview");
if ($set_tview) $tview = $set_tview;

$tableview = GetAlertsTableView($tview);

if (!is_array ($tableview)) { MsgPage ($sess->url(self_base()."index.php3"), "Bad Table view ID: ".$tview); exit; }
if (! $tableview["cond"] )  { MsgPage ($sess->url(self_base()."index.php3"), L_NO_PS_ADD, "standalone"); exit; }

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo '<LINK rel=StyleSheet href="'.$AA_INSTAL_PATH.'/tabledit.css" type="text/css"  title="TableEditCSS">';
echo "<TITLE>".$tableview["title"]."</TITLE></HEAD>";

// called before menu because of Item Manager
ProcessFormData ("GetAlertsTableView", $val, $cmd);
if (!$new_module)
    showMenu ($aamenus, $tableview["mainmenu"], $tableview["submenu"]);
echo "<TABLE width='100%'><TR valign=center><TD>";
echo "<H1>" . $tableview["caption"] . "</H1>";
if ($tableview["children"]) {
    echo "</TD><TD>";
    reset ($tableview["children"]);
    while (list ($chviewid, $child) = each ($tableview["children"])) 
        echo "<FONT class='tabtxt'><B><a href='#$chviewid'>$child[header]</a></B></FONT> ";
}
echo "</TD></TR></TABLE>";

PrintArray($err);
echo $Msg;
$script = "tabledit.php3?AA_CP_Session=$AA_CP_Session";

$tabledit = new tabledit ($tview, $script, $cmd, $tableview, $AA_INSTAL_PATH."images/", $sess, "GetAlertsTableView");
$err = $tabledit->view ();

if (!$err && $tview == "acf") {
    require "design.php3";
    ShowCollectionAddOns();
}

if ($err) echo "<b>$err</b>";
if ($new_module)
    echo "</BODY></HTML>";
else HTMLPageEnd();
page_close ();
?>
