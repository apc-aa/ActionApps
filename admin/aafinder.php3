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

function AafinderFieldLink($field_id, $slice_id) {
    return a_href( get_admin_url("se_inputform.php3?change_id=$slice_id&fid=$field_id",'',true), "$field_id"). ' ('.AA_Slice::getModuleName($slice_id).')';
}

function AafinderItemLink($item_id, $slice_id) {
    return a_href( get_admin_url("itemedit.php3?slice_id=$slice_id&id=$item_id&edit=1",'',true), "$item_id<br>(". AA_Slice::getModuleName($slice_id) .")");
}

function AafinderSliceLink($slice_id) {
    return a_href( get_admin_url("index.php3?change_id=$slice_id",'',true), "$slice_id<br>(". AA_Module::getModuleName($slice_id) .")");
}

function AafinderSiteLink($spot_id, $slice_id) {
    return a_href( get_aa_url("modules/site/index.php3?slice_id=$slice_id&spot_id=$spot_id&go_sid=$spot_id"), "$spot_id (". AA_Modules::getModuleProperty($slice_id,'name') .")");
}

if (!IsSuperadmin()) {
    MsgPage($sess->url(self_base()."index.php3"), _m("You have not permissions to add slice"));
    exit;
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo "<title>"._m("AA finder")."</title></head>";
showMenu($aamenus, "aaadmin", "aafinder");
echo "<h1><b>" ._m("AA finder"). "</b></h1>";
PrintArray($err);
echo $Msg;

is_object( $db ) || ($db = getDB());

if (strlen($_GET['findtext']) AND $_GET['findinview']) {
    $fields = array (
        'id',
        'before',
        'even',
        'row_delimiter',
        'odd',
        'after',
        'group_title',
        'order1',
        'order2',
        'group_by1',
        'group_by2',
        'cond1field',
        'cond2field',
        'cond3field',
        'aditional',
        'aditional2',
        'aditional3',
        'aditional4',
        'aditional5',
        'aditional6',
        'group_bottom',
        'field1',
        'field2',
        'field3');

    $SQL = "SELECT view.id, view.type, view.slice_id, slice.name
        FROM view INNER JOIN slice ON view.slice_id = slice.id WHERE ";
    foreach ($fields as $field) {
        $SQL .= "view.$field LIKE \"%". addcslashes(quote($_GET['findtext']),'_%')."%\" OR ";
    }
    $SQL .= "0";
    $db->query($SQL);
    echo "<b>views</b> <small>(".$db->num_rows()." matching found)</small><br>";
    while ($db->next_record()) {
        $view = AA_Views::getView($db->f("id"));
        echo $view->jumpLink($db->f("id")." -  ".$view->f("name")." (".$db->f("name").") "). "<br>\n";
    }
    echo '<br><br>';
}

if (strlen($_GET['findtext']) AND $_GET['findinslice']) {
    $fields = array (
        'slice.name',
        'slice.type',
        'slice.id',
        'slice.fulltext_format_top',
        'slice.fulltext_format',
        'slice.fulltext_format_bottom',
        'slice.odd_row_format',
        'slice.even_row_format',
        'slice.compact_top',
        'slice.compact_bottom',
        'slice.category_top',
        'slice.category_format',
        'slice.category_bottom',
        'slice.admin_format_top',
        'slice.admin_format',
        'slice.admin_format_bottom',
        'slice.aditional',
        'slice.javascript',
        'email_notify.uid'
        );

    $SQL = 'SELECT slice.name, slice.id FROM slice LEFT JOIN email_notify ON email_notify.slice_id = slice.id WHERE ';
    foreach ($fields as $field) {
        $SQL .= "$field LIKE \"%". addcslashes(quote($_GET['findtext']),'_%') ."%\" OR ";
    }
    $SQL .= "0";
    $db->query($SQL);
    echo "<b>slices</b> <small>(".$db->num_rows()." matching found)</small><br>";
    while ($db->next_record()) {
        echo $db->f("name")." "
                ."<a href=\"".$sess->url("se_fulltext.php3?change_id=".unpack_id($db->f("id")))
                ."\">"._m("Jump")."</a><br>";
    }
    echo '<br><br>';
}


if (strlen($_GET['findtext']) AND $_GET['findinfield']) {
    $fields = array (
        'id',
        'type',
        'slice_id',
        'name',
        'input_pri',
        'input_help',
        'input_morehlp',
        'input_default',
        'feed',
        'input_show_func',
        'alias1',
        'alias1_func',
        'alias1_help',
        'alias2',
        'alias2_func',
        'alias2_help',
        'alias3',
        'alias3_func',
        'alias3_help',
        'input_before',
        'aditional',
        'content_edit',
        'input_validate',
        'input_insert_func',
        'input_show'
        );

    $SQL = "SELECT slice_id, id, name FROM field WHERE ";
    foreach ($fields as $field) {
        $SQL .= "$field LIKE \"%". addcslashes(quote($_GET['findtext']),'_%') ."%\" OR ";
    }
    $SQL .= "0";
    $db->query($SQL);
    echo "<b>fields</b> <small>(".$db->num_rows()." matching found)</small><br>";
    while ($db->next_record()) {
        echo $db->f("name")." ".AafinderFieldLink($db->f("id"), unpack_id($db->f("slice_id"))). "<br>";
    }
    echo '<br><br>';
}


if (strlen($_GET['findtext']) AND $_GET['findinspot']) {
    $fields = array (
        'content'
        );

    $SQL = "SELECT site_id, spot_id FROM site_spot WHERE ";
    foreach ($fields as $field) {
        $SQL .= "$field LIKE \"%". addcslashes(quote($_GET['findtext']),'_%') ."%\" OR ";
    }
    $SQL .= "0";
    $db->query($SQL);
    echo "<b>site spots</b> <small>(".$db->num_rows()." matching found)</small><br>";
    while ($db->next_record()) {
        echo AafinderSiteLink($db->f("spot_id"), unpack_id($db->f("site_id"))). "<br>";
    }
    echo '<br><br>';
}

if (strlen($_GET['findtext']) AND $_GET['findindiscus']) {
    $fields = array (
        'subject',
        'author',
        'e_mail',
        'body',
        'url_address',
        'url_description',
        'remote_addr',
        'free1',
        'free2'
        );

    $SQL = "SELECT slice_id, discussion.* FROM discussion, item WHERE discussion.item_id = item.id AND (";
    foreach ($fields as $field) {
        $SQL .= "discussion.$field LIKE \"%". addcslashes(quote($_GET['findtext']),'_%') ."%\" OR ";
    }
    $SQL .= "0) ORDER BY date";
    $db->query($SQL);
    echo "<b>comments</b> <small>(".$db->num_rows()." matching found)</small><br>";
    while ($db->next_record()) {
        if (!$head) {
            echo ($head = '<table><tr><td>'. join('</td><td>', array_keys($db->Record)).'</td></tr>');
        }
        $print = $db->Record;
        $print['slice_id'] = unpack_id($print['slice_id']);
        $print['id'] = unpack_id($print['id']);
        $print['parent']  = unpack_id($print['parent']);
        $print['item_id'] = AafinderItemLink(unpack_id($print['item_id']), $print['slice_id']);
        $print['date'] = date('Y-m-d H:i:s', $print['date']);
        echo '<tr><td>'. join('</td><td>', $print).'</td></tr>';
    }
    echo '</table>';
    echo '<br><br>';
}

if ($_GET['go_finditem'] && $_GET['finditem']) {
    $zid = new zids($_GET['finditem']);
    $item = AA_Items::getItem($zid);
    if ($item) {
        $long_id = $item->getItemID();
        $sid     = $item->getSliceID();
        echo "<br>Item ID: $long_id (". $item->getval('short_id........') .") | <a href=\"itemedit.php3?id=$long_id&edit=1&encap=false&slice_id=$sid&$sess->name=$sess->id\" target=\"_blank\">"._m('Edit')."</a>";
        echo "<br>Item slice: $sid (". AA_Slice::getModuleName($sid). ')';
        $format = '_#HEADLINE';
        echo "<br>_#HEADLINE: ". $item->unalias($format);
        echo "<br>Fed to: ".     join(', ', WhereFed($item->getItemID()));
        echo "<br>Fed from: ".   join(', ', FromFed($item->getItemID()));
        echo '<h3>'. _m('AA_Item structure') .'</h3><pre>';
        print_r($item->content4id);
        echo "</pre>";
    }

    $long_id = $zid->longids(0);
    if ($long_id) {
        echo '<h3>'. _m('item table record for the item') .'</h3><pre>';
        print_r(GetTable2Array('SELECT * FROM item WHERE id = \''.q_pack_id($long_id).'\'', '', 'aa_fields'));
        echo "</pre>";

        echo '<h3>'. _m('content table records for the item') .'</h3><pre>';
        print_r(GetTable2Array('SELECT * FROM content WHERE item_id = \''.q_pack_id($long_id).'\'', '', 'aa_fields'));
        echo "</pre>";

        if ($sdata = DB_AA::select1('SELECT * FROM slice', '', array(array('id',$long_id, 'l')))) {
            echo '<h3>'. _m('Slice') .'</h3><pre>';
            echo AafinderSliceLink($long_id). "<br>";
            echo "</pre>";
        }
        if ($sdata = DB_AA::select1('SELECT * FROM module', '', array(array('id',$long_id, 'l')))) {
            echo '<h3>'. _m('Module') .'</h3><pre>';
            echo AafinderSliceLink($long_id). "<br>";
            echo "</pre>";
        }
        if ($rec = GetTable2Array('SELECT * FROM object_text WHERE object_id = \''.quote($long_id).'\'', '', 'aa_fields')) {
            echo '<h3>'. _m('Object') .'</h3><pre>';
            print_r($rec);
            print_r(GetTable2Array('SELECT * FROM object_integer WHERE object_id = \''.quote($long_id).'\'', '', 'aa_fields'));
            print_r(GetTable2Array('SELECT * FROM object_float   WHERE object_id = \''.quote($long_id).'\'', '', 'aa_fields'));
            echo "</pre>";
        }

        $changes = AA_ChangesMonitor::singleton();
        echo '<h3>'. _m('History') .'</h3>';
        //echo "<pre>";
        //print_r($changes->getHistory(array($long_id)));
        //echo "</pre>";

        $changes->display(array($long_id));
      //  echo '<h3>'. _m('Proposals') .'</h3><pre>';
      //  print_r($changes->getProposals(array($long_id)));
      //  echo "</pre>";
    }
    echo '<br><br>';
}

if ($_GET['go_finditem_edit'] && $_GET['finditem_edit'] && $_GET['finditem_edit_op']) {

    function query_search ($where,$sess) {
        $db = getDB();
        $sql="SELECT distinct slice.name as slice_name, slice.id as slice_id, item.* from slice JOIN item ON slice.id=item.slice_id JOIN content ON content.item_id=item.id WHERE $where ORDER BY slice_name, short_id";
        $db->query($sql);

        $num_rows = $db->num_rows();

        $output .= $num_rows." "._m('Show results with string')."  <b><i>$where</i></b><br>";

        $items = array(array(_m('Show'), _m('Edit'), _m('Slice'), 'short_id', 'status_code', 'publish_date', 'last_edit', 'edited_by' ));
        while ($db->next_record()) {
            $slice_name = $db->f('slice_name');
            $slice_id   = (string)bin2hex($db->f('slice_id'));
            $short_id   = $db->f('short_id');
            $long_id    = (string)bin2hex($db->f('id'));

            $items[]    = array("<a href=\"http://".$_SERVER['SERVER_NAME']."/".AA_BASE_DIR.$sess->url('slice.php3')."&slice_id=".$slice_id."&nocache=1\" target=\"_blank\" >"._m('Show')."</a>", "<a href=\"itemedit.php3?id=$long_id&edit=1&encap=false&slice_id=$slice_id&$sess->name=$sess->id\" target=\"_blank\">"._m('Edit')."</a>", $slice_name, $short_id, $db->f('status_code'), date('Y-m-d H:i',$db->f('post_date')), date('Y-m-d H:i',$db->f('last_edit')),$db->f('edited_by'));
        }

        return GetHtmlTable($items, 'th'). '<br><br>';
    }

    switch ($_GET['finditem_edit_op']) {
        case 'LIKE': print query_search("content.text LIKE '%". quote($_GET['finditem_edit'])."%'",$sess);break;
        case '=':    print query_search("content.text = '". quote($_GET['finditem_edit'])."'",$sess);break;
        case 'item': print query_search("item.short_id = '". quote($_GET['finditem_edit'])."'",$sess);break;
        case 'seo':  print query_search("content.text = '". quote($_GET['finditem_edit'])."' AND content.field_id = 'seo.............'",$sess);break;
    }
}

// ------------------------------------------------------------------------------------------
// SHOW THE PAGE


FrmTabCaption(_m("Manage"));
echo '<tr><td>';
echo '<form name="f_finduser" action="'.$sess->url("um_uedit.php3").'">';
echo '<b>'._m("Manage User:").'</b><br>
    <input type="text" name="usr" value="" size=60>&nbsp;&nbsp;<input type="hidden" name="UsrSrch" value="1">
    <input type="submit" name="go_finduser" value="'._m("Go!").'">' .$sess->get_hidden_session();
echo '</form>';
echo '</td></tr><tr><td>';
echo '<form name="f_findgroup" action="'.$sess->url("um_gedit.php3").'">';
echo '<b>'._m("Manage Group:").'</b><br>
    <input type="text" name="grp" value="" size=60>&nbsp;&nbsp;<input type="hidden" name="GrpSrch" value="1">
    <input type="submit" name="go_findgroup" value="'._m("Go!").'">' .$sess->get_hidden_session();
echo '</form>';
echo '</td></tr>';
FrmTabSeparator(_m("Search"));
echo '<tr><td>';
echo '<form name="f_findtext" action="">';
echo '<b>'._m('Find in').'</b>
      <label><input type="checkbox" name="findinview"   value="1" '. ((!$_GET['findtext'] OR $_GET['findinview'  ])? 'checked':'').'>'._m("Views").'</label>&nbsp;&nbsp;
      <label><input type="checkbox" name="findinslice"  value="1" '. ((!$_GET['findtext'] OR $_GET['findinslice' ])? 'checked':'').'>'._m("Slices").'</label>&nbsp;&nbsp;
      <label><input type="checkbox" name="findinfield"  value="1" '. ((!$_GET['findtext'] OR $_GET['findinfield' ])? 'checked':'').'>'._m("Fields").'</label>&nbsp;&nbsp;
      <label><input type="checkbox" name="findinspot"   value="1" '. ((!$_GET['findtext'] OR $_GET['findinspot'  ])? 'checked':'').'>'._m("Site spots").'</label>&nbsp;&nbsp;
      <label><input type="checkbox" name="findindiscus" value="1" '. ((                      $_GET['findindiscus'])? 'checked':'').'>'._m("Discussion comments").'</label>&nbsp;&nbsp;
    <br>
    <input type="text" name="findtext" value="'.safe($_GET['findtext']).'" size=60>&nbsp;&nbsp;
      <input type="submit" name="go_findtext" value="'._m("Go!").'">' .$sess->get_hidden_session();
echo '</form>';
echo '</td></tr><tr><td>';

echo '<form name="f_finditem" action="">';
echo '<b>'._m("Get all informations about the ITEM").'</b><br>
    <input type="text" name="finditem" value="'.safe($_GET['finditem']).'" size=60>&nbsp;&nbsp;
    <input type="submit" name="go_finditem" value="'._m("Go!").'">' .$sess->get_hidden_session();
echo '</form>';

echo '</td></tr><tr><td>';
echo '<form name="f_finditem_edit" action="">';
echo '<b>'._m("Shorcut to edit ITEM").'</b><br>
    <input type="text" name="finditem_edit" value="'.safe($_GET['finditem_edit']).'" size=60>&nbsp;&nbsp;
    <select name="finditem_edit_op">
    <option value="LIKE">'._m('contains').'</option>
    <option value="=">'._m('is').'</option>
    <option value="item">'._m('Item number').'</option>
    <option value="seo">'._m('seo............. =').'</option>
    </select>
    <input type="submit" name="go_finditem_edit" value="'._m("Go!").'">'.$sess->get_hidden_session();
echo '</form></td></tr>';

FrmTabEnd();

HTMLPageEnd();
page_close ();
?>
