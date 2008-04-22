<?php

/** Shows a Table View, allowing to edit, delete, update fields of a table
   @param $set_tview -- required, name of the table view
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
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
*/

$require_default_lang = true;      // do not use module specific language file
                                   // (message for init_page.php3)
require_once "../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."date.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."tabledit.php3";
require_once menu_include();      //show navigation column depending on $show
require_once AA_INC_PATH."mgettext.php3";
require_once AA_BASE_PATH."modules/alerts/util.php3";

// ----------------------------------------------------------------------------------------

if (!IsSuperadmin()) {
    MsgPage ($sess->url(self_base()."index.php3"), _m("You have not permissions to add slice"), "standalone");
    exit;
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo "<title>"._m("AA finder")."</title></head>";
showMenu($aamenus, "aaadmin", "aafinder");
echo "<h1><b>" ._m("AA finder"). "</b></h1>";
PrintArray($err);
echo $Msg;

$db = new DB_AA;

if ($go_findview && $findview) {
    $fields = array (
        "id",
        "before",
        "even",
        "odd",
        "after",
        "group_title",
        "order1",
        "order2",
        "group_by1",
        "group_by2",
        "cond1field",
        "cond2field",
        "cond3field",
        "aditional",
        "aditional2",
        "aditional3",
        "aditional4",
        "aditional5",
        "aditional6",
        "group_bottom",
        "field1",
        "field2",
        "field3");

    $SQL = "SELECT view.id, view.type, view.slice_id, slice.name
        FROM view INNER JOIN slice ON view.slice_id = slice.id WHERE ";
    foreach ($fields as $field) {
        $SQL .= "view.$field LIKE \"%". magic_add($findview)."%\" OR ";
    }
    $SQL .= "0";
    $db->query($SQL);
    echo $db->num_rows()." matching views found:<br>";
    while ($db->next_record()) {
        $view = AA_Views::getView($db->f("id"));
        echo $view->jumpLink($db->f("id")." (".$db->f("name").") "). "<br>\n";
    }
}

if ($go_findslice && $findslice) {
    $fields = array (
        "name",
        "type",
        "id",
        "fulltext_format_top",
        "fulltext_format",
        "fulltext_format_bottom",
        "odd_row_format",
        "even_row_format",
        "compact_top",
        "compact_bottom",
        "category_top",
        "category_format",
        "category_bottom",
        "admin_format_top",
        "admin_format",
        "admin_format_bottom",
        "aditional",
        "javascript");

    $SQL = "SELECT slice.name, slice.id FROM slice WHERE ";
    foreach ($fields as $field) {
        $SQL .= "$field LIKE \"%". magic_add($findslice) ."%\" OR ";
    }
    $SQL .= "0";
    $db->query($SQL);
    echo $db->num_rows()." matching slices found:<br>";
    while ($db->next_record()) {
        echo $db->f("name")." "
                ."<a href=\"".$sess->url("se_fulltext.php3?change_id=".unpack_id128($db->f("id")))
                ."\">"._m("Jump")."</a><br>";
    }
}


if ($go_findfield && $findfield) {
    $fields = array (
        "id",
        "type",
        "slice_id",
        "name",
        "input_pri",
        "input_help",
        "input_morehlp",
        "input_default",
        "feed",
        "input_show_func",
        "alias1",
        "alias1_func",
        "alias1_help",
        "alias2",
        "alias2_func",
        "alias2_help",
        "alias3",
        "alias3_func",
        "alias3_help",
        "input_before",
        "aditional",
        "content_edit",
        "input_validate",
        "input_insert_func",
        "input_show",
        );

    $SQL = "SELECT slice_id, id FROM field WHERE ";
    foreach ($fields as $field) {
        $SQL .= "$field LIKE \"%". magic_add($findfield) ."%\" OR ";
    }
    $SQL .= "0";
    $db->query($SQL);
    echo $db->num_rows()." matching fields found:<br>";
    while ($db->next_record()) {
        echo $db->f("name")." "
                ."<a href=\"".$sess->url("se_inputform.php3?change_id=".unpack_id128($db->f("slice_id")). "&fid=".$db->f("id") )
                ."\">".$db->f("id"). ' ('. AA_Slices::getName(unpack_id128($db->f("slice_id"))). ")</a><br>";
    }
}

if ($go_finditem && $finditem) {
    $item = AA_Item::getItem($finditem);
    echo "<pre>";
    print_r($item);
    echo "</pre>";
}

// ------------------------------------------------------------------------------------------
// SHOW THE PAGE

FrmTabCaption(_m("AA finder"));
echo '<tr><td>';
echo '<form name="f_findview" action="'.$sess->url("aafinder.php3").'" method="post">';
echo '<b>'._m("Find all VIEWS containing in any field the string:").'</b><br>
    <input type="text" name="findview" value="'.$findview.'" size="30">&nbsp;&nbsp;
    <input type="submit" name="go_findview" value="'._m("Go!").'">';
echo '</form>';
echo '</td></tr><tr><td>';
echo '<form name="f_findslice" action="'.$sess->url("aafinder.php3").'" method="post">';
echo '<b>'._m("Find all SLICES containing in any field the string:").'</b><br>
    <input type="text" name="findslice" value="'.$findslice.'" size="30">&nbsp;&nbsp;
    <input type="submit" name="go_findslice" value="'._m("Go!").'">';
echo '</form>';
echo '</td></tr><tr><td>';
echo '<form name="f_findfield" action="'.$sess->url("aafinder.php3").'" method="post">';
echo '<b>'._m("Find all FIELDS containing in ites definition the string:").'</b><br>
    <input type="text" name="findfield" value="'.$findfield.'" size="30">&nbsp;&nbsp;
    <input type="submit" name="go_findfield" value="'._m("Go!").'">';
echo '</form>';
echo '</td></tr><tr><td>';
echo '<form name="f_finditem" action="'.$sess->url("aafinder.php3").'" method="post">';
echo '<b>'._m("Get all informations about the item").'</b><br>
    <input type="text" name="finditem" value="'.$finditem.'" size="30">&nbsp;&nbsp;
    <input type="submit" name="go_finditem" value="'._m("Go!").'">';
echo '</form></td></tr>';
FrmTabEnd();

HTMLPageEnd();
page_close ();
?>
