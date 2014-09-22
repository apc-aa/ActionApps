<?php
/**
 *   Shows a Table View, allowing to edit, delete, update fields of a table
 *     Params:
 *         $set_tview -- required, name of the table view
 *
 * PHP versions 4 and 5
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
 * @link      http://www.apc.org/ APC
 *
*/

// (c) Econnect, Jakub Adamek, December 2002
// DOCUMENTATION: doc/tabledit.html, doc/tabledit_developer.html, doc/tableview.html

require_once dirname(__FILE__). "/../include/init_page.php3";
require_once AA_INC_PATH."tabledit.php3";
require_once menu_include();   //show navigation column depending on $show

// ----------------------------------------------------------------------------------------

$sess->register("tview");
if ($set_tview) {
    $tview = $set_tview;
}

require_once AA_INC_PATH."tv_common.php3";
require_once AA_INC_PATH."tv_misc.php3";

// is tableview defined in special file (tableviews.php3)?
if ( ($tview{0} == "a") OR ( substr($tview,0,5) =='polls') ) {
    $func = "GetTableView";
    require_once AA_INC_PATH."tableviews.php3";
} else {
    $func = "GetMiscTableView";
}

$tableview = $func($tview);

if (!is_array($tableview)) {
    go_url ($sess->url(self_base()."index.php3?slice_id=$slice_id&Msg=Bad table view ID: $tview"));
    exit;
}
if (! $tableview["cond"] ) {
    MsgPage ($sess->url(self_base()."index.php3"), _m("You have not permissions to this page"));
    exit;
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo '<link rel=StyleSheet href="'.AA_INSTAL_PATH.'tabledit.css" type="text/css"  title="TableEditCSS">';
echo "<title>".$tableview["title"]."</title></head>";
showMenu ($aamenus, $tableview["mainmenu"], $tableview["submenu"]);
echo "<h1><b>" . $tableview["caption"] . "</b></h1>";

ProcessFormData($func, $val, $cmd);

PrintArray($Err);
echo $Msg;

$script = $sess->url("tabledit.php3");

$tabledit = new tabledit($tview, $script, $cmd, $tableview, AA_INSTAL_PATH."images/", $sess, $func);
$err      = $tabledit->view($where);
if ($err) {
    echo "<b>$err</b>";
}

if (!$err && $tview == "email_edit") {
    ShowEmailAliases();
}

HTMLPageEnd();
page_close ();
?>
