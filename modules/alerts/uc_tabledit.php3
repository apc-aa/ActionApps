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

require "uc_menu.php3";
require $GLOBALS[AA_INC_PATH]."constants.php3";
require $GLOBALS[AA_INC_PATH]."tabledit.php3";
require $GLOBALS[AA_INC_PATH]."tv_common.php3";
require $GLOBALS[AA_INC_PATH]."util.php3";
require "uc_tableviews.php3";

if ($go_to_collection_form) {
    $db->query("SELECT slice_url FROM alerts_collection AC
                 INNER JOIN module ON AC.moduleid = module.id
                 WHERE AC.id = $go_to_collection_form");
    $db->next_record();
    go_url ($GLOBALS[AA_BASE_URL]."post2shtml.php3?shtml_page=".$db->f("slice_url")."&uid=".$auth->auth ["uid"]);
}

if ($set_tview)
    $tview = $set_tview;
if (!$tview) $tview = "au_edit";    
$tableview = GetAlertsUCTableView($tview);

ProcessFormData ("GetAlertsUCTableView", $val, $cmd);

showMenu ($tableview["mainmenu"]);

PrintArray($Err);
echo $Msg; $Msg = "";
$script = $sess->url("uc_tabledit.php3");

$cmd[$tview]["edit"][$auth->auth["uid"]] = 1;
if ($err) echo "<b>$err</b>";
if ($Msg) echo $Msg;
$tabledit = new tabledit ($tview, $script, $cmd, $tableview, $AA_INSTAL_PATH."images/", $sess, "GetAlertsUCTableView");

$where = "";
if ($tview == "auc") 
    $where = "userid = ".$auth->auth["uid"];
$err = $tabledit->view ($where);

if ($err) echo "<b>$err</b>";

EndMenuPage();
?>
