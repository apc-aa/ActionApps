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

/* Shows a Table View, allowing to edit, delete, update fields of a table
   Params:
       $set_tview -- required, name of the table view
*/

require "$directory_depth../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."tabledit.php3";
require $MODULES[$g_modules[$slice_id]['type']]['menu'];   //show navigation column depending on $show

$tableview_definitions = array (
    "emails" => "tv_email",
    "email" => "tv_email",
    "ww" => "tv_misc",
    "wt" => "tv_misc",
    "cron" => "tv_misc");
    
// ----------------------------------------------------------------------------------------

$sess->register("tview");
if ($set_tview) $tview = $set_tview;

require $GLOBALS[AA_INC_PATH]."tv_common.php3";

if (!$tableview_definitions [$tview]) { echo "This tableview is not in \$tableview_definitions: $tview. Bailing out."; exit; }
require $GLOBALS[AA_INC_PATH].$tableview_definitions [$tview].".php3";

$tableview = GetTableView($tview);

if (!is_array ($tableview)) { MsgPage ($sess->url(self_base()."index.php3"), "Bad Table view ID: ".$tview); exit; }
if (! $tableview["cond"] )  { MsgPage ($sess->url(self_base()."index.php3"), L_NO_PS_ADD, "standalone"); exit; }

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo '<LINK rel=StyleSheet href="'.$AA_INSTAL_PATH.'/tabledit.css" type="text/css"  title="TableEditCSS">';
echo "<TITLE>".$tableview["title"]."</TITLE></HEAD>";
showMenu ($aamenus, $tableview["mainmenu"], $tableview["submenu"]);
echo "<H1><B>" . $tableview["caption"] . "</B></H1>";
PrintArray($err);
echo $Msg;

$script = "tabledit.php3?AA_CP_Session=$AA_CP_Session";

$tabledit = new tabledit ($tview, $script, $cmd, $val, $tableview, $AA_INSTAL_PATH."images/", $sess, "GetTableView");
$err = $tabledit->view ($where);

if ($err) echo "<b>$err</b>";
HTMLPageEnd();
page_close ();
?>
