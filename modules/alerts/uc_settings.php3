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

require "uc_init_page.php3";
require $GLOBALS[AA_INC_PATH]."constants.php3";
require $GLOBALS[AA_INC_PATH]."tabledit.php3";
require $GLOBALS[AA_INC_PATH]."tv_common.php3";
require $GLOBALS[AA_INC_PATH]."util.php3";
require "uc_tableviews.php3";

if ($go_to_collection_form) {
    $db->query ("SELECT slice_url FROM alerts_collection AC
                 INNER JOIN module ON AC.moduleid = module.id
                 WHERE AC.id = $go_to_collection_form");
    $db->next_record();
    go_url ($GLOBALS[AA_BASE_URL]."post2shtml.php3?shtml_page=".$db->f("slice_url")."&uid=".$auth->auth ["uid"]);
}

// ----------------------------------------------------------------------------------------
/*
$user = AlertsUser ($alerts_session);
if ($signout) go_url (AA_INSTAL_URL."modules/alerts/uc_index.php3?show_email=$email&lang=$lang&ss=$ss");
if (!$user) go_url (AA_INSTAL_URL."modules/alerts/uc_index.php3?show_email=$email&Msg="._m("Your session has expired. Please login again."));
*/
require $GLOBALS["AA_INC_PATH"].get_mgettext_lang()."_news_lang.php3";

$tview = "au_edit";
$tableview = GetAlertsUCTableView($tview);

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo '<LINK rel=StyleSheet href="'.$AA_INSTAL_PATH.'/tabledit.css" type="text/css"  title="TableEditCSS">';
echo "<TITLE>".$tableview["title"]."</TITLE></HEAD>";

echo "<TABLE width='100%'><TR valign=center><TD>";
echo "<H1>" . $tableview["caption"] . "</H1>";
echo "</TD></TR></TABLE>";

PrintArray($err);
echo $Msg;
$script = $sess->url("uc_settings.php3");

$cmd[$tview]["edit"][$auth->auth["uid"]] = 1;
ProcessFormData ("GetAlertsUCTableView", $val, $cmd);
$tabledit = new tabledit ($tview, $script, $cmd, $tableview, $AA_INSTAL_PATH."images/", $sess, "GetAlertsUCTableView");
$err = $tabledit->view ();

if ($err) echo "<b>$err</b>";

echo '<FORM name="subscribe" action="'.$sess->url("uc_settings.php3").'" method="post"><h2>'
    . _m("Subscribe to").'</h2>';
    
$db->query ("SELECT AC.id, name FROM alerts_collection AC
             INNER JOIN module ON AC.moduleid = module.id
             ORDER BY name");
while ($db->next_record())
    $collections[$db->f("id")] = $db->f("name");

FrmSelectEasy ("go_to_collection_form", $collections);
echo '&nbsp;&nbsp;<INPUT TYPE="submit" VALUE="'._m("Go").'">';

echo "</FORM>";
echo "</BODY></HTML>";
page_close ();
?>
