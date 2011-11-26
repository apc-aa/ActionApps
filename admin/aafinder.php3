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
        "row_delimiter",
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
                ."<a href=\"".$sess->url("se_fulltext.php3?change_id=".unpack_id($db->f("id")))
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
                ."<a href=\"".$sess->url("se_inputform.php3?change_id=".unpack_id($db->f("slice_id")). "&fid=".$db->f("id") )
                ."\">".$db->f("id"). ' ('. AA_Slices::getName(unpack_id($db->f("slice_id"))). ")</a><br>";
    }
}

if ($go_finditem && $finditem) {
    $zid = new zids($finditem);
    $item = AA_Items::getItem($zid);
    if ($item) {
        $long_id = $item->getItemID();
        $sid     = $item->getSliceID();
        echo "<br>Item ID: $long_id (". $item->getval('short_id........') .") | <a href=\"itemedit.php3?id=$long_id&edit=1&encap=false&slice_id=$sid&$sess->name=$sess->id\" target=\"_blank\">"._m('Edit')."</a>";
        echo "<br>Item slice: $sid (". AA_Slices::getName($sid). ')';
        $format = '_#HEADLINE';
        echo "<br>_#HEADLINE: ". $item->unalias($format);
    }
    echo "<pre>";
    echo '<h3>'. _m('AA_Item structure') .'</h3><pre>';
    print_r($item->content4id);
    echo "</pre>";

    $long_id = $zid->longids(0);
    if ($long_id) {
        echo '<h3>'. _m('item table record for the item') .'</h3><pre>';
        print_r(GetTable2Array('SELECT * FROM item WHERE id = \''.q_pack_id($long_id).'\'', '', 'aa_fields'));
        echo "</pre>";

        echo '<h3>'. _m('content table records for the item') .'</h3><pre>';
        print_r(GetTable2Array('SELECT * FROM content WHERE item_id = \''.q_pack_id($long_id).'\'', '', 'aa_fields'));
        echo "</pre>";
    }
}

if ($go_finditem_edit && $finditem_edit && $finditem_edit_op) {

    function query_search ($field, $op, $value,$sess) {
        $db = new DB_AA;
        $sql="SELECT distinct  slice.name as slice_name, slice.id as slice_id, item.short_id as short_id, item.id as long_id from slice JOIN item ON slice.id=item.slice_id JOIN content ON content.item_id=item.id WHERE ".$field." ".$op."'$value'";
        $db->query($sql);

        $num_rows = $db->num_rows();

        $output.= $num_rows." "._m('Show results with string')."  <b><i>$value</i></b><br>";

        $output.= "<ul id=\"list\">";

        while ($db->next_record()) {
            $slice_name = $db->f('slice_name');
            $slice_id = (string)bin2hex($db->f('slice_id'));
            $short_id = $db->f('short_id');
            $long_id = (string)bin2hex($db->f('long_id'));
            $output.= "<li class=\"node\"> <b>"._m('Slice').":</b> ".$slice_name.". <b>Item=</b>".$short_id."  <a href=\"http://".$_SERVER['SERVER_NAME']."/".AA_BASE_DIR.$sess->url('slice.php3')."&slice_id=".$slice_id."&nocache=1\" target=\"_blank\" >"._m('Show')."</a> | <a href=\"itemedit.php3?id=$long_id&edit=1&encap=false&slice_id=$slice_id&$sess->name=$sess->id\" target=\"_blank\">"._m('Edit')."</a></li>";
        }

        $output.= "</ul>";

        return $output;

    }

    switch ($finditem_edit_op) {
        case 'LIKE': $field = "content.text";
                     print query_search ($field, 'LIKE','%'.$finditem_edit.'%',$sess);break;
        case '=':    $field = "content.text";
                     print query_search ($field, '=',$finditem_edit,$sess);break;
        case 'item': $field = "item.short_id";
                     print query_search ($field, '=',$finditem_edit,$sess);break;
    }

}

// ------------------------------------------------------------------------------------------
// SHOW THE PAGE


FrmTabCaption(_m("Manage"));
echo '<tr><td>';
echo '<form name="f_finduser" action="'.$sess->url("um_uedit.php3").'" method="post">';
echo '<b>'._m("Manage User:").'</b><br>
    <input type="text" name="usr" value="" size="30">&nbsp;&nbsp;<input type="hidden" name="UsrSrch" value="1">
    <input type="submit" name="go_finduser" value="'._m("Go!").'">';
echo '</form>';
echo '</td></tr><tr><td>';
echo '<form name="f_findgroup" action="'.$sess->url("um_gedit.php3").'" method="post">';
echo '<b>'._m("Manage Group:").'</b><br>
    <input type="text" name="grp" value="" size="30">&nbsp;&nbsp;<input type="hidden" name="GrpSrch" value="1">
    <input type="submit" name="go_findgroup" value="'._m("Go!").'">';
echo '</form>';
echo '</td></tr>';
FrmTabSeparator(_m("Find"));
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
echo '<b>'._m("Get all informations about the ITEM").'</b><br>
    <input type="text" name="finditem" value="'.$finditem.'" size="30">&nbsp;&nbsp;
    <input type="submit" name="go_finditem" value="'._m("Go!").'">';
echo '</form></td></tr>';
echo '<tr><td>';
echo '<form name="f_finditem_edit" action="'.$sess->url("aafinder.php3").'" method="post">';
echo '<b>'._m("Shorcut to edit ITEM").'</b><br>
    <input type="text" name="finditem_edit" value="'.$finditem_edit.'" size="30">&nbsp;&nbsp;
    <select name="finditem_edit_op" value="">
    <option value="LIKE">Contiene</option>
    <option value="=">Frase exacta</option>
    <option value="item">Número de item</option>
    </select>
    <input type="submit" name="go_finditem_edit" value="'._m("Go!").'">';
echo '</form></td></tr>';



FrmTabEnd();

HTMLPageEnd();
page_close ();
?>
