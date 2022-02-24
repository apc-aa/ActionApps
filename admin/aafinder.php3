<?php

/** Shows a Table View, allowing to edit, delete, update fields of a table
   @param $set_tview -- required, name of the table view
 * PHP version 7.2+
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
 * @link      https://www.apc.org/ APC
*/

use AA\IO\DB\DB_AA;
use AA\Util\ChangesMonitor;
use AA\Util\InputVars;

$require_default_lang = true;      // do not use module specific language file
                                   // (message for init_page.php3)
//const AA_REGISTER_GLOBALS = true;
require_once __DIR__."/../include/init_page.php3";
require_once __DIR__."/../include/formutil.php3";
require_once __DIR__."/../include/date.php3";
require_once __DIR__."/../include/varset.php3";
require_once __DIR__."/../include/tabledit.php3";
//require_once menu_include();      //show navigation column depending on $show
require_once __DIR__."/../include/mgettext.php3";
require_once __DIR__."/../modules/alerts/util.php3";

try {
    $searchinview = InputVars::boolGET('searchinview');
    $searchinslice = InputVars::boolGET('searchinslice');
    $searchinfield = InputVars::boolGET('searchinfield');
    $searchinobject = InputVars::boolGET('searchinobject');
    $searchinitems = InputVars::boolGET('searchinitems');
    $searchinhistory = InputVars::boolGET('searchinhistory');
    $searchindiscus = InputVars::boolGET('searchindiscus');
    $searchinspot = InputVars::boolGET('searchinspot');
    $search = InputVars::stringGET('search'); // empty
    $replace = InputVars::stringGET('replace'); // empty
    $go_search = InputVars::boolGET('go_search'); // alpha // convert submit to bool
    $findinseo = InputVars::boolGET('findinseo');
    $findinview = InputVars::boolGET('findinview');
    $findinslice = InputVars::boolGET('findinslice');
    $findinfield = InputVars::boolGET('findinfield');
    $findindiscus = InputVars::boolGET('findindiscus');
    $findinspot = InputVars::boolGET('findinspot');
    $findtext = InputVars::stringGETPOST('findtext'); // -
    // array ( 'Jeď!' => 2, 1 => 2, )
    $go_findtext = InputVars::boolGET('go_findtext'); // convert submit to bool
    $showhistory = InputVars::idGET('showhistory');
    $finditem = InputVars::stringGET('finditem'); // alpha, empty
    // array ( 'Jeď!' => 3, )
    $go_finditem = InputVars::boolGET('go_finditem'); // convert submit to bool
    // array ( 'Replace!' => 6, )
    $go_replace = InputVars::boolGET('go_replace'); // convert submit to bool
    $finditem_edit = InputVars::stringGET('finditem_edit'); // alpha
    // array ( 'item' => 1, )
    $finditem_edit_op = InputVars::stringGET('finditem_edit_op'); // alpha
    // array ( 'Jeď!' => 1, )
    $go_finditem_edit = InputVars::boolGET('go_finditem_edit'); // convert submit to bool
    $err = InputVars::arrayGETPOST('err');
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception $e) {}

if (!IsSuperadmin()) {
    MsgPageMenu(StateUrl(self_base())."index.php3", _m("You don't have permissions to do search."), "admin");
    exit;
}
// ----------------------------------------------------------------------------------------


function AafinderFieldLink($field_id, $slice_id): string {
    return a_href(get_admin_url("se_inputform.php3?change_id=$slice_id&fid=$field_id"), "$field_id"). ' ('.AA_Slice::getModuleName($slice_id).')';
}

function AdminLink($table, $row): string
{
    switch ($table) {
        case 'content':
        case 'discussion':
        case 'item':
            return AafinderItemLink($row['iid'], $row['sid']);
        case 'view':
            return AafinderViewLink($row['iid']);
        case 'slice':
            return AafinderSliceLink($row['iid']);
        case 'field':
            return AafinderFieldLink($row['iid'], $row['sid']);
        case 'site_spot':
            return AafinderSiteLink($row['iid'], $row['sid']);
        case 'object_text':
        case 'object_integer':
        case 'object_float':
            return AafinderObjectLink($row['iid']);
    }
    return '';
}

function AafinderObjectLink($object_id): string {
    if ($obj = AA_Object::load($object_id)) {
        print_r($obj)    ;
        return a_href(get_admin_url('oedit.php3', [
            'module_id' => $obj->getOwnerId(),
            'oid'       => $object_id,
            'otype'     => $obj->getObjectType(),
            'ret_url'   => get_aa_url('modules/site/aliases.php3',['module_id'=>$obj->getOwnerId()],false)
        ]), "$object_id");
    }
    return '';
}

function AafinderItemLink($item_id, $slice_id=''): string {
    if (!$slice_id AND ($item = AA_Items::getItem(new zids($item_id, 'l')))) {
        $slice_id = $item->getSliceID();
    } else {
        return '';
    }
    return a_href(get_admin_url("itemedit.php3?id=$item_id&change_id=$slice_id&edit=1"), "$item_id"). ' ('. AA_Slice::getModuleName($slice_id) .') &nbsp; '. ChangesMonitor::getHistoryLink($item_id);
}

function AafinderSliceLink($slice_id): string {
    return a_href(get_admin_url("index.php3?change_id=$slice_id"), "$slice_id"). ' ('. AA_Module::getModuleName($slice_id) .')  &nbsp; '. ChangesMonitor::getHistoryLink($slice_id);
}

function AafinderSiteLink($spot_id, $module_id): string {
    if ( ($module = AA_Module_Site::getModule($module_id)) AND ($tree = $module->getTree()) ) {
        $spot_code =  ($tree->get('flag', $spot_id) & AA_Module_Site::FLAG_DISABLE) ? "<s>$spot_id</s>" : "<b>$spot_id</b>";
    } else {
        $spot_code = "??? $spot_id";
    }
    return a_href(get_aa_url("modules/site/index.php3?slice_id=$module_id&module_id=$module_id&spot_id=$spot_id&go_sid=$spot_id"), $spot_code). ' ('. AA_Module_Site::getModuleName($module_id) .') &nbsp; '. ChangesMonitor::getHistoryLink("S.$spot_id");
}

function AafinderViewLink($view_id): string {
    $view = AA_Views::getViewNumeric($view_id);
    return $view->jumpLink($view_id." -  ".$view->f("name")).' ('. AA_Module::getModuleName($view->getSliceId()) .') &nbsp; '. ChangesMonitor::getHistoryLink("V.$view_id");
}

function query_search(array $where, string $phrase): string {
    $where[] = ['slice.id', 'item.slice_id', 'j'];
    $where[] = ['content.item_id', 'item.id', 'j'];

    $arr = DB_AA::select([], 'SELECT distinct slice.name as slice_name, LOWER(HEX(slice.id)) as slice_id, LOWER(HEX(item.id)) as iid, item.short_id, item.status_code, item.post_date, item.last_edit, item.edited_by from slice, item, content', $where, ['status_code','slice_name','short_id']);

    $output = count($arr)." "._m('Show results with string')."  <b><i>$phrase</i></b><br>";

    $items = [[_m('Edit'), _m('Slice'), 'short_id', 'status_code', 'publish_date', 'last_edit', 'edited_by']];
    foreach ($arr as $f) {
        $slice_id   = $f['slice_id'];
        $slice_name = AA_Slice::getModuleName($slice_id);
        $long_id    = $f['iid'];

        $items[]    = ["<a href=\"itemedit.php3?id=$long_id&edit=1&encap=false&slice_id=$slice_id\">"._m('Edit')."</a>", $slice_name, $f['short_id'], $f['status_code'], date('Y-m-d H:i',$f['post_date']), date('Y-m-d H:i',$f['last_edit']),$f['edited_by']];
    }

    return $output. GetHtmlTable($items, 'th'). '<br><br>';
}


function PrintIdsInfo($l_or_s_id) {
    if (is_short_id($l_or_s_id)) {
        if (DB_AA::test('view', [['id', $l_or_s_id, 'i']])) {
            echo '<h3>View</h3><pre>';
            echo AafinderViewLink($l_or_s_id) . "<br>";
            echo "</pre>";
        }
        return;
    }
    if (!is_long_id($l_or_s_id)) {
        return;
    }
    if ($res = DB_AA::select([], 'SELECT `id`, FROM_UNIXTIME(`time`) as Date, `user`, `type`, `selector`, `params` FROM `log`', [['selector', $l_or_s_id, 'RLIKE']])) {
        echo GetHtmlTable($res, 'key', _m('Log'));
    }

    if (DB_AA::test('slice', [['id', $l_or_s_id, 'l']])) {
        echo '<h3>'. _m('Slice') .'</h3><pre>';
        echo AafinderSliceLink($l_or_s_id). "<br>";
        echo "</pre>";
    } elseif (DB_AA::test('module', [['id', $l_or_s_id, 'l']])) {
        echo '<h3>'. _m('Module') .'</h3><pre>';
        echo AafinderSliceLink($l_or_s_id). "<br>";
        echo "</pre>";
    }
    if ($rec = DB_AA::select([], 'SELECT * FROM `object_text`', [['object_id', $l_or_s_id]])) {
        echo '<h3>'. _m('Object') .'</h3><pre>';
        print_r($rec);
        print_r(DB_AA::select([], 'SELECT * FROM `object_integer`', [['object_id', $l_or_s_id]]));
        print_r(DB_AA::select([], 'SELECT * FROM `object_float`', [['object_id', $l_or_s_id]]));
        echo "</pre>";
    }
}



function PrintItemInfo($item_id) {
    $zid = new zids($item_id);
    $item = AA_Items::getItem($zid);
    PrintIdsInfo($item_id);
    if (!$item) {
        return;
    }
    $long_id = $item->getItemID();
    $sid     = $item->getSliceID();
    echo "<br>Item ID: $long_id (". $item->getval('short_id........') .") | <a href=\"".StateUrl("itemedit.php3?id=$long_id&edit=1&encap=false&change_id=$sid")."\" target=\"_blank\">"._m('Edit')."</a>". ' | '. ChangesMonitor::getHistoryLink($item_id);
    echo "<br>Item slice: $sid (". AA_Slice::getModuleName($sid). ')';
    $format = '_#HEADLINE';
    echo "<br>_#HEADLINE: ". $item->unalias($format);
    echo "<br>Fed to: ".     join(', ', WhereFed($item->getItemID()));
    echo "<br>Fed from: ".   join(', ', FromFed($item->getItemID()));

    $long_id = $zid->longids(0);
    if ($long_id) {
        if ($res = DB_AA::select([], 'SELECT * FROM `item`', [['id',$long_id, 'l']])) {
            $res[0]['id']            = unpack_id($res[0]['id']);
            $res[0]['slice_id']      = unpack_id($res[0]['slice_id']);
            $res[0]['post_date']    .= '<br><small>('.date('j.n.Y H:i:s', $res[0]['post_date']) .'</small>)';
            $res[0]['publish_date'] .= '<br><small>('.date('j.n.Y H:i:s', $res[0]['publish_date']) .'</small>)';
            $res[0]['expiry_date']  .= '<br><small>('.date('j.n.Y H:i:s', $res[0]['expiry_date']) .'</small>)';
            $res[0]['last_edit']    .= '<br><small>('.date('j.n.Y H:i:s', $res[0]['last_edit']) .'</small>)';
            $res[0]['moved2active'] .= '<br><small>('.date('j.n.Y H:i:s', $res[0]['moved2active']) .'</small>)';
            echo GetHtmlTable($res, 'key', _m('Item table record for the item'));
        }

        $slice = AA_Slice::getModule($sid);

        if ($res = DB_AA::select([], 'SELECT "" as FieldName, `field_id`, `number`,`text`, `flag` FROM `content`', [['item_id',$long_id, 'l']])) {              // empty FieldName - will be filled later
            array_walk($res, function (&$v, $k) use ($slice) {
                $v['FieldName'] =  ($f=$slice->getField($v['field_id'])) ? $f->getName() : '-- invalid --';
                if (is_long_id($v['text']) and ($hdln = AA::Stringexpander()->unalias("{item:$v[text]:_#HEADLINE}"))) {
                    $v['text'] .= "<br><small>($hdln)</small>";
                }
            });
            echo GetHtmlTable($res, 'key', _m('Content table records for the item'));
        }

        echo '<h3>'. _m('History') .'</h3>';

        echo 'show '. ChangesMonitor::getHistoryLink($item_id);

        if ($res = DB_AA::select([], 'SELECT LOWER(HEX(`item_id`)) as Item, "" as Status, "" as PubDate, "" as ExpDate, "" as FieldName, `field_id`, `number`,`text`, `flag` FROM `content`', [['text',$long_id]])) {
            array_walk($res, function (&$v, $k) {
                if (is_long_id($iid = $v['Item'])) {
                    $item = AA_Items::getItem(new zids($iid, 'l'));
                    $v['Item'] .= ' / ' . AA_Module::getModuleName($item->getSliceID());
                    if ($hdln = AA::Stringexpander()->unalias("{item:$iid:_#HEADLINE}")) {
                        $v['Item'] .= "<br><small>($hdln)</small>";
                    }
                    $v['Status'] = $item->getStatusCode();
                    $v['PubDate'] = date('j.n.Y H:i:s', $item->getval('publish_date....'));
                    $v['ExpDate'] = date('j.n.Y H:i:s', $item->getval('expiry_date.....'));

                    $islice = AA_Slice::getModule($item->getSliceID());
                    $v['FieldName'] = ($f=$islice->getField($v['field_id'])) ? $f->getName() : '-- invalid --';
                }
            });
            echo GetHtmlTable($res, 'key', _m('Referenced items'));
        }

        if ($res = DB_AA::select([], 'SELECT `id`, FROM_UNIXTIME(`time`) as Date, `user`, `type`, `selector`, `params` FROM `log`', [['selector',$long_id,'RLIKE']])) {
            echo GetHtmlTable($res, 'key', _m('Log'));
        }


        //echo "<pre>";
        //print_r($changes->getHistory(array($long_id)));
        //echo "</pre>";

        //ChangesMonitor::singleton()->display(array($long_id));
      //  echo '<h3>'. _m('Proposals') .'</h3><pre>';
      //  print_r($changes->getProposals(array($long_id)));
      //  echo "</pre>";
    }

    echo '<h3>'. _m('AA_Item structure') .'</h3><pre>';
    print_r($item->getItemContent());
    echo "</pre>";

    echo '<br><br>';
}


if (!IsSuperadmin()) {
    MsgPage(StateUrl(self_base()."index.php3"), _m("You have not permissions to add slice"));
    exit;
}


$apage = new AA_Adminpageutil('aaadmin','aafinder');
$apage->setTitle(_m("AA finder"));
$apage->setForm();   // do not use form around code
$apage->printHead($err, $Msg);

is_object( $db ) || ($db = getDB());

function PrintSeoSearch($q): void {
    if (in_array(guesstype(trim($q)), ['s', 'l'])) {
        PrintItemInfo(trim($q));
    } elseif (preg_match_all("/([0-9a-f]{32})/", $q, $ids)) {
        $ids = array_unique($ids[0]);
        foreach ($ids as $id) {
            PrintItemInfo($id);
            echo '<hr>';
        }
    } else {
        print query_search([ ["content.text", $q], ['content.field_id', 'seo.............'] ], $q);
    }
}

if (strlen($findtext)) {

    if ($findinseo) {
        PrintSeoSearch($findtext);
    }

    if ($findinview) {
        $fields = [
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
            'field3'
        ];

        $SQL = "SELECT view.id, view.type, view.slice_id, slice.name FROM view INNER JOIN slice ON view.slice_id = slice.id WHERE ";
        foreach ($fields as $field) {
            $SQL .= "view.$field LIKE \"%". addcslashes(quote($findtext),'_%')."%\" OR ";
        }
        $SQL .= "0";
        $db->query($SQL);
        echo "<b>views</b> <small>(".$db->num_rows()." matching found)</small><br>";
        while ($db->next_record()) {
            echo AafinderViewLink($db->f("id")). "<br>\n";
        }
        echo '<br><br>';
    }

    if ($findinslice) {
        $fields = [
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
        ];

        $SQL = 'SELECT slice.name, slice.id FROM slice LEFT JOIN email_notify ON email_notify.slice_id = slice.id WHERE ';
        foreach ($fields as $field) {
            $SQL .= "$field LIKE \"%". addcslashes(quote($findtext),'_%') ."%\" OR ";
        }
        $SQL .= "0";
        $db->query($SQL);
        echo "<b>slices</b> <small>(".$db->num_rows()." matching found)</small><br>";
        while ($db->next_record()) {
            echo $db->f("name")." "
                    ."<a href=\"".StateUrl("se_fulltext.php3?change_id=".unpack_id($db->f("id")))
                    ."\">"._m("Jump")."</a><br>";
        }
        echo '<br><br>';
    }

    if ($findinfield) {
        $fields = [
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
        ];

        $SQL = "SELECT slice_id, id, name FROM field WHERE ";
        foreach ($fields as $field) {
            $SQL .= "$field LIKE \"%". addcslashes(quote($findtext),'_%') ."%\" OR ";
        }
        $SQL .= "0";
        $db->query($SQL);
        echo "<b>fields</b> <small>(".$db->num_rows()." matching found)</small><br>";
        while ($db->next_record()) {
            echo $db->f("name")." ".AafinderFieldLink($db->f("id"), unpack_id($db->f("slice_id"))). "<br>";
        }
        echo '<br><br>';
    }

    if ($findinspot) {
        $like_text = addcslashes(quote($findtext),'_%');

        $fields = [
            'content'
        ];

        $SQL = "SELECT site_id, spot_id FROM site_spot WHERE ";
        foreach ($fields as $field) {
            $SQL .= "$field LIKE \"%$like_text%\" OR ";
        }
        $SQL .= "0";
        $db->query($SQL);
        echo "<b>site spots</b> <small>(".$db->num_rows()." matching found)</small><br>";
        while ($db->next_record()) {
            echo AafinderSiteLink($db->f("spot_id"), unpack_id($db->f("site_id"))). "<br>";
        }
        echo '<br><br>';


        // ------
        $zids =       AA_Object::querySet('AA_Aliasfunc', new AA_Set(null, new AA_Condition('alias',   'CONTAIN', $findtext)));
        $zids->union( AA_Object::querySet('AA_Aliasfunc', new AA_Set(null, new AA_Condition('code',    'CONTAIN', $findtext))) );
        $zids->union( AA_Object::querySet('AA_Aliasfunc', new AA_Set(null, new AA_Condition('desc',    'CONTAIN', $findtext))) );
        $zids->union( AA_Object::querySet('AA_Aliasfunc', new AA_Set(null, new AA_Condition('ussage',  'CONTAIN', $findtext))) );
        $zids->union( AA_Object::querySet('AA_Aliasfunc', new AA_Set(null, new AA_Condition('aa_name', 'CONTAIN', $findtext))) );

        if (!$zids->isEmpty()) {
            $manager_settings = AA_Aliasfunc::getManagerConf(get_aa_url('modules/site/aliases.php3',[],false));  // we should provide: ['module_id'=>$obj->getOwnerId()]
            $manager_settings['show'] = MGR_ITEMS;
            $manager = new AA_Manager('aafindaliases'.AA::$module_id, $manager_settings);
            $manager->performActions();
            $manager->display($zids);
        }
    }

    if ($findindiscus) {
        $fields = [
            'subject',
            'author',
            'e_mail',
            'body',
            'url_address',
            'url_description',
            'remote_addr',
            'free1',
            'free2'
        ];

        $SQL = "SELECT slice_id, discussion.* FROM discussion, item WHERE discussion.item_id = item.id AND (";
        foreach ($fields as $field) {
            $SQL .= "discussion.$field LIKE \"%". addcslashes(quote($findtext),'_%') ."%\" OR ";
        }
        $SQL .= "0) ORDER BY date";
        $db->query($SQL);
        echo "<b>comments</b> <small>(".$db->num_rows()." matching found)</small><br>";
        $head = '';
        while ($db->next_record()) {
            if (!$head) {
                echo ($head = '<table><tr><td>'. join('</td><td>', array_keys($db->record())).'</td></tr>');
            }
            $print = $db->record();
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
}

if ($go_finditem && $finditem) {
    PrintSeoSearch($finditem);
}

if ($go_finditem_edit && $finditem_edit && $finditem_edit_op) {
    switch ($finditem_edit_op) {
        case 'LIKE':
            $qstring =  '%' . addcslashes(quote($finditem_edit),'_%') . '%';
            print query_search([['content.text', $qstring, 'LIKE']], "LIKE $qstring");
            break;
        case '=':
            print query_search([['content.text', $finditem_edit]], $finditem_edit);
            break;
        case 'item':
            print query_search([['item.short_id', $finditem_edit, 'i']], $finditem_edit);
            break;
        case 'seo':
            print query_search([['content.text', $finditem_edit], ['content.field_id', 'seo.............']], $finditem_edit);
            break;
    }
}

if ( strlen($go_search) OR strlen($go_replace) ) {
    $row = [];
    if ( strlen($search) OR ( strlen($go_replace) AND (strlen($replace)>5) ) ) {
        $metabase = AA::Metabase();

        $table2search = [];
        if ($searchinview) {
            $table2search['view'] = ['id' => '`id`', 'slice_id' => 'LOWER(HEX(`slice_id`))'];
        }
        if ($searchinslice) {
            $table2search['slice'] = ['id' => 'LOWER(HEX(`id`))'];
        }
        if ($searchinfield) {
            $table2search['field'] = ['id' => '`id`', 'slice_id' => 'LOWER(HEX(`slice_id`))'];
        }
        if ($searchinspot) {
            $table2search['site_spot'] = ['id' => '`spot_id`', 'slice_id' => 'LOWER(HEX(`site_id`))'];
        }
        if ($searchinitems) {
            $table2search['content'] = ['id' => 'LOWER(HEX(`item_id`))'];
        }
        if ($searchinobject) {
            $table2search['object_text'] = ['id' => '`object_id`'];
        }
        if ($searchinhistory) {
            $table2search['change_record'] = ['id' => '`change_id`'];
        }
        if ($searchindiscus) {
            $table2search['discussion'] = ['id' => 'LOWER(HEX(`item_id`))'];
            $table2search['object_text'] = ['id' => 'object_id'];
            $table2search['object_integer'] = ['id' => 'object_id'];
            $table2search['object_float'] = ['id' => 'object_id'];
        }

        PrintSeoSearch($search);

        echo '<table>';
        foreach ($table2search as $tbl => $tblinfo) {
            $fields = array_keys(array_filter($metabase->getSearchArray($tbl), function ($col) {
                return ($col['operators'] == 'text');
            }));

            $fields = array_diff($fields, array_merge(AA_Metabase::getPacked($tbl), [AA_Metabase::getModuleField($tbl)]));

            // huhl($metabase->getSearchArray($tbl),AA_Metabase::getPacked($tbl),AA_Metabase::getModuleField($tbl), );

            $addfields = $tblinfo['id'] ? (', ' . $tblinfo['id']) . ' as iid' : '';
            $addfields .= $tblinfo['slice_id'] ? (', ' . $tblinfo['slice_id']) . ' as sid' : '';

            foreach ($fields as $fld) {
                $row = [];

                if (strlen($go_search)) {
                    $SQL = "SELECT `$fld` as txt, INSTR(`$fld`,\"" . quote($search) . "\") as pos $addfields FROM `$tbl` WHERE INSTR(`$fld`,\"" . quote($search) . "\")>0";
                    $txt = DB_AA::select([], $SQL);
                    if (!$txt) {
                        continue;
                    }
                    $row[0] = "<b>$tbl.$fld</b>";
                    $row[1] = count($txt);
                    FrmTabRow($row);
                    $row = [];
                    foreach ($txt as $t) {
                        $code = aa_substr($t['txt'], 0, $t['pos']-1). 'AA_$@MArK%'. aa_substr($t['txt'], $t['pos']-1);
                        if (strlen($code) > 210) {
                            if ($t['pos'] > 40) {

                                $code = aa_substr($code, 0, 40) . '...' . aa_substr($code, max($t['pos'] - 107, 43), 177) . '...';
                            } else {
                                $code = aa_substr($code, 0, 210) . '...';
                            }
                        }
                        $code = str_replace('AA_$@MArK%', '<span style="background-color:#DDD">', safe($code)) . '</span>';
                        $row[0] = "<small>" . AdminLink($tbl, $t) . "</small>";
                        $row[1] = "<small> $code</small>";
                        FrmTabRow($row);
                    }
                } elseif (strlen($go_replace) AND strlen($search) AND (strlen($replace) > 5)) {
                    $replace_str = str_replace('{#AAA}', '', $replace);
                    $SQL = "UPDATE `$tbl` SET `$fld` = REPLACE(`$fld`, \"" . quote($search) . "\", \"" . quote($replace_str) . "\")";
                    $rep = DB_AA::sql($SQL);
                    $row[0] = $rep ? "<b>$tbl.$fld</b>" : "$tbl.$fld";
                    $row[1] = "($rep replaces)";
                    FrmTabRow($row);
                } else {
                    // just to not make damage in id fields, ...
                    $row[0] = "Search phrase must be at least 1 character and replace phrase must be 6 character long at least";
                    FrmTabRow($row);
                }
            }
        }
    } else {
        $row[0] = "Search phrase must be at least 1 character and replace phrase must be 6 character long at least";
        FrmTabRow($row);
    }
    echo '</table>';
}

if ($showhistory) {
    echo '<h3>'. _m('History') .'</h3>';
    ChangesMonitor::singleton()->display([$showhistory]);
    echo '<br><br>';

}



// ------------------------------------------------------------------------------------------
// SHOW THE PAGE


FrmTabCaption(_m("Manage"));
echo '<tr><td>';
echo '<form name="f_finduser" action="'.StateUrl("um_uedit.php3").'">';
echo '<b>'._m("Manage User:").'</b><br>
    <input type="text" name="usr" value="" size=120>&nbsp;&nbsp;<input type="hidden" name="UsrSrch" value="1">
    <input type="submit" name="go_finduser" value="'._m("Go!").'">' .StateHidden();
echo '</form>';
echo '</td></tr><tr><td>';
echo '<form name="f_findgroup" action="'.StateUrl("um_gedit.php3").'">';
echo '<b>'._m("Manage Group:").'</b><br>
    <input type="text" name="grp" value="" size=120>&nbsp;&nbsp;<input type="hidden" name="GrpSrch" value="1">
    <input type="submit" name="go_findgroup" value="'._m("Go!").'">' .StateHidden();

echo '</form>';
echo '</td></tr>';
FrmTabSeparator(_m("Search"));
echo '<tr><td>';
echo '<form name="f_findtext" action="">';
echo '<b>'._m('Find in').'</b>
      <label><input type="checkbox" name="findinseo"    value="1" '. ((!$findtext OR $findinseo)? 'checked':'').'>'._m("ID & SEO").'</label>&nbsp;&nbsp;
      <label><input type="checkbox" name="findinview"   value="1" '. ((!$findtext OR $findinview)? 'checked':'').'>'._m("Views").'</label>&nbsp;&nbsp;
      <label><input type="checkbox" name="findinslice"  value="1" '. ((!$findtext OR $findinslice)? 'checked':'').'>'._m("Slices").'</label>&nbsp;&nbsp;
      <label><input type="checkbox" name="findinfield"  value="1" '. ((!$findtext OR $findinfield)? 'checked':'').'>'._m("Fields").'</label>&nbsp;&nbsp;
      <label><input type="checkbox" name="findinspot"   value="1" '. ((!$findtext OR $findinspot)? 'checked':'').'>'._m("Site spots").'</label>&nbsp;&nbsp;
      <label><input type="checkbox" name="findindiscus" value="1" '. ((                      $findindiscus)? 'checked':'').'>'._m("Discussion comments").'</label>&nbsp;&nbsp;
    <br>
    <input type="text" name="findtext" value="'.safe($findtext).'" size=120>&nbsp;&nbsp;
      <input type="submit" name="go_findtext" value="'._m("Go!").'">' .StateHidden();
echo '</form>';
echo '</td></tr><tr><td>';

echo '<form name="f_replace" action="">';
echo '<b>'._m('Search and Replace in').'</b>
      <label><input type="checkbox" name="searchinview"    value="1" '. ((!$search OR $searchinview)? 'checked':'').'>'._m("Views").'</label>&nbsp;&nbsp;
      <label><input type="checkbox" name="searchinslice"   value="1" '. ((!$search OR $searchinslice)? 'checked':'').'>'._m("Slices").'</label>&nbsp;&nbsp;
      <label><input type="checkbox" name="searchinfield"   value="1" '. ((!$search OR $searchinfield)? 'checked':'').'>'._m("Fields").'</label>&nbsp;&nbsp;
      <label><input type="checkbox" name="searchinspot"    value="1" '. ((!$search OR $searchinspot)? 'checked':'').'>'._m("Site spots").'</label>&nbsp;&nbsp;
      <label><input type="checkbox" name="searchinobject"  value="1" '. ((!$search OR $searchinobject)? 'checked':'').'>'._m("Objects").'</label>&nbsp;&nbsp;
      <label><input type="checkbox" name="searchinitems"   value="1" '. ((                    $searchinitems)? 'checked':'').'>'._m("Items").'</label>&nbsp;&nbsp;
      <label><input type="checkbox" name="searchinhistory" value="1" '. ((                    $searchinhistory)? 'checked':'').'>'._m("History").'</label>&nbsp;&nbsp;
      <label><input type="checkbox" name="searchindiscus"  value="1" '. ((                    $searchindiscus)? 'checked':'').'>'._m("Discussion comments").'</label>&nbsp;&nbsp;
    <br>
    <textarea name="search" placeholder="'._m('minimum 2 chracters for replace function').'" rows=6 style="width:40%">'.safe($search).'</textarea>&nbsp;&nbsp;
    <textarea name="replace" placeholder="'._m('minimum 6 chracters for replace function (or use {#AAA} value which will be replaced by empty string)').'" rows=6 style="width:40%">'.safe($replace).'</textarea>&nbsp;&nbsp;
    <input type="submit" name="go_search" value="'._m("Search").'">
    <input type="submit" name="go_replace" value="'._m("Replace!").'">'.StateHidden();

echo '</form>';
echo '</td></tr><tr><td>';


echo '<form name="f_finditem" action="">';
echo '<b>'._m("Get all informations about the ITEM").'</b><br>
    <input type="text" name="finditem" value="'.safe($finditem).'" size=120>&nbsp;&nbsp;
    <input type="submit" name="go_finditem" value="'._m("Go!").'">' .StateHidden();
echo '</form>';
echo '</td></tr><tr><td>';

echo '<form name="f_finditem_edit" action="">';
echo '<b>'._m("Shorcut to edit ITEM").'</b><br>
    <input type="text" name="finditem_edit" value="'.safe($finditem_edit).'" size=120>&nbsp;&nbsp;
    <select name="finditem_edit_op">
    <option value="LIKE">'._m('contains').'</option>
    <option value="=">'._m('is').'</option>
    <option value="item">'._m('Item number').'</option>
    <option value="seo">'._m('seo............. =').'</option>
    </select>
    <input type="submit" name="go_finditem_edit" value="'._m("Go!").'">'.StateHidden();
echo '</form></td></tr>';

FrmTabEnd();

$apage->printFoot();
