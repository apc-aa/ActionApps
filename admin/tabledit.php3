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

require_once $directory_depth."../include/init_page.php3";
require_once $GLOBALS["AA_INC_PATH"]."tabledit.php3";
require_once menu_include();   //show navigation column depending on $show
    
// ----------------------------------------------------------------------------------------

$sess->register("tview");
if ($set_tview) $tview = $set_tview;

require_once $GLOBALS["AA_INC_PATH"]."tv_common.php3";
require_once $GLOBALS["AA_INC_PATH"]."tv_misc.php3";

if ($tview{0} == "a") {
    $func = "GetTableView";
    require_once $GLOBALS["AA_INC_PATH"]."tableviews.php3";
}

else    
    $func = "GetMiscTableView";

$tableview = $func($tview);

if (!is_array ($tableview)) { MsgPage ($sess->url(self_base()."index.php3"), "Bad Table view ID: ".$tview); exit; }
if (! $tableview["cond"] )  { MsgPage ($sess->url(self_base()."index.php3"), _m("You have not permissions to add slice"), "standalone"); exit; }

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo '<LINK rel=StyleSheet href="'.$AA_INSTAL_PATH.'tabledit.css" type="text/css"  title="TableEditCSS">';
echo "<TITLE>".$tableview["title"]."</TITLE></HEAD>";
showMenu ($aamenus, $tableview["mainmenu"], $tableview["submenu"]);
echo "<H1><B>" . $tableview["caption"] . "</B></H1>";

ProcessFormData ($func, $val, $cmd);

PrintArray($Err);
echo $Msg;

$script = $sess->url("tabledit.php3");

$tabledit = new tabledit ($tview, $script, $cmd, $tableview, $AA_INSTAL_PATH."images/", $sess, $func);
$err = $tabledit->view ($where);
if ($err) echo "<b>$err</b>";

if (!$err && $tview == "email_edit")
    ShowEmailAliases();
    
HTMLPageEnd();
page_close ();
?>
