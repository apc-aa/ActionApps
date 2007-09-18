<?php  //slice_id expected
/**
 *
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
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

require_once "../include/init_page.php3";
require_once AA_INC_PATH . "varset.php3";
require_once AA_INC_PATH . "view.php3";
require_once AA_INC_PATH . "pagecache.php3";
require_once AA_INC_PATH . "item.php3";
require_once AA_INC_PATH . "feeding.php3";
require_once AA_INC_PATH . "profile.class.php3";
require_once AA_INC_PATH . "itemfunc.php3";
require_once AA_INC_PATH . "notify.php3";
require_once AA_INC_PATH . "searchlib.php3";
require_once AA_INC_PATH . "formutil.php3";
require_once AA_INC_PATH . "sliceobj.php3";
require_once AA_INC_PATH . "msgpage.php3";
require_once AA_INC_PATH . "manager.class.php3";
require_once AA_INC_PATH . "actions.php3";

FetchSliceReadingPassword();

function CountItemsInBins() {
    global $p_slice_id;
    $db = getDB();
    $now = now('step');

    $ret['folder1'] = $ret['folder2'] = $ret['folder3'] = $ret['expired'] = $ret['pending'] = 0;
    $db->tquery("SELECT status_code, count(*) as cnt FROM item
                 WHERE slice_id = '$p_slice_id'
                 GROUP BY status_code");
                 while ( $db->next_record() ) {
                     $ret[ 'folder'. $db->f('status_code') ] = $db->f('cnt');
                 }

    $db->tquery("SELECT count(*) as cnt FROM item
                 WHERE slice_id = '$p_slice_id'
                   AND status_code=1
                   AND expiry_date <= '$now' ");
                   if ( $db->next_record() ) {
                       $ret['expired'] = $db->f('cnt');
                   }

    $db->tquery("SELECT count(*) as cnt FROM item
                 WHERE slice_id = '$p_slice_id'
                   AND status_code=1
                   AND publish_date > '$now' ");
                   if ( $db->next_record() ) {
                       $ret['pending'] = $db->f('cnt');
                   }

    $ret['app'] = $ret['folder1']-$ret['pending']-$ret['expired'];
    return $ret;
}

// id of the editted module (id in long form (32-digit hexadecimal number))
$module_id = $slice_id;
// module_id is the same as slice_id (slice_id was used before AA introduced
// modules. Now it is better to use module_id, because in other modules
// (like Links, ...) it is not so confusing

$p_module_id = q_pack_id($module_id); // packed to 16-digit as stored in database
$slice       = AA_Slices::getSlice($module_id);

$perm_edit_all  = IfSlPerm(PS_EDIT_ALL_ITEMS);
$perm_edit_self = IfSlPerm(PS_EDIT_SELF_ITEMS);

if ( !$perm_edit_all && !$perm_edit_self) {
    MsgPage($sess->url(self_base())."index.php3", _m("You do not have permission to edit items in the slice:").AA_Slices::getName($slice_id));
    exit;
}


$actions   = new AA_Manageractions;
$actions->addAction(new AA_Manageraction_Item_MoveItem('Activate', 1));
$actions->addAction(new AA_Manageraction_Item_MoveItem('Folder2',  2));
$actions->addAction(new AA_Manageraction_Item_MoveItem('Folder3',  3));

$new_action = new AA_Manageraction_Item_Feed('Feed', $slice->unpacked_id());
$new_action->setOpenUrl($sess->url("feed_to.php3"));
$actions->addAction($new_action);

// rXn=1 is foo parameter to make sure, we can use '&' to join items[] parameter (see open_url_add below)
// '&'    add items[] array to open_url url which will hold checked items
$actions->addAction(new AA_Manageraction_Item_Preview(      'Preview',       con_url($slice->getProperty('slice_url'),'rXn=1'), '&'));
$actions->addAction(new AA_Manageraction_Item_Modifycontent('ModifyContent', $sess->url("search_replace.php3"), '&'));
$actions->addAction(new AA_Manageraction_Item_Email(        'Email',         $sess->url("write_mail.php3"),     '&'));
$actions->addAction(new AA_Manageraction_Item_DeleteTrash(  'DeleteTrashAction',true));
$actions->addAction(new AA_Manageraction_Item_Move2slice(   'Move2slice',    $module_id));


$switches  = new AA_Manageractions;

// no problem to write tabs as one action, but we use 3
$switches->addAction(new AA_Manageraction_Item_Tab('Tab1a', 'app'));
$switches->addAction(new AA_Manageraction_Item_Tab('Tab1b', 'appb'));
$switches->addAction(new AA_Manageraction_Item_Tab('Tab1c', 'appc'));
$switches->addAction(new AA_Manageraction_Item_Tab('Tab2',  'hold'));
$switches->addAction(new AA_Manageraction_Item_Tab('Tab3',  'trash'));
$switches->addAction(new AA_Manageraction_Item_DeleteTrash('DeleteTrash',false));
//$switches->addAction(new AA_Manageraction_Item_Email('SendEmail'));


$manager_settings = array(
     'module_id' => $slice_id,
     'show'      =>  MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS,    // MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS
     'searchbar' => array(
         'fields'               => $slice->fields('search'),
         'search_row_count_min' => 1,
         'order_row_count_min'  => 1,
         'add_empty_search_row' => true,
         'function'             => false  // name of function for aditional action hooked on standard filter action
                         ),
     'scroller'  => array(
         'listlen'              => ($listlen ? $listlen : EDIT_ITEM_COUNT)
                         ),
     'itemview'  => array(
         'manager_vid'          => false,    // $slice_info['manager_vid'],      // id of view which controls the design
         'format'               => $slice->get_format_strings(),   // optionaly to manager_vid you can set format array
         'fields'               => $slice->fields('record'),
         'aliases'              => $slice->aliases(),
         'get_content_funct'    => 'GetItemContent'
                         ),
     'actions'   => $actions,
     'switches'  => $switches,
     'bin'       => 'app',
     'messages'  => array(
         'title'       => ($slice->type() == 'ReaderManagement') ?
                          _m('ActionApps - Reader Manager') :
                          _m('ActionApps - Item Manager')
                         )
         );

$manager = new AA_Manager($manager_settings);
$profile = new aaprofile($auth->auth["uid"], $module_id); // current user settings

// r_state array holds all configuration of Links Manager
// the configuration then could be Bookmarked
if ( !isset($r_state) OR $change_id OR ($r_state["module_id"] != $module_id)) {
    // we are here for the first time or we are switching to another slice
    unset($r_state);
    // set default admin interface settings from user's profile
    $r_state["module_id"]       = $module_id;
    $sess->register('r_state');

    $manager->setFromProfile($profile);
}

if ($r_state['manager']) {        // do not set state for the first time calling
    $manager->setFromState($r_state['manager']);
}

$manager->performActions();

$r_state['bin_cnt'] = CountItemsInBins();

$manager->printHtmlPageBegin(true);  // html, head, css, title, javascripts

require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "itemmanager", $manager->getBin(), $navbar != "0", $leftbar != "0");

$conds = $manager->getConds();
$sort  = $manager->getSort();

// authors have only permission to edit its own items
if (! $perm_edit_all ) {
      $conds[]=array( 'operator' => '=',
                      'value' => $auth->auth['uid'],
                      'posted_by.......' => 1 );
}

$BIN_CONDS   = array( 'app'    => AA_BIN_ACTIVE,
                      'appb'   => AA_BIN_PENDING,
                      'appc'   => AA_BIN_EXPIRED,
                      'hold'   => AA_BIN_HOLDING,
                      'trash'  => AA_BIN_TRASH
                    );

$zids = QueryZIDs( array($slice_id), $conds, $sort, $BIN_CONDS[$manager->getBin()]);

$manager->printSearchbarBegin();
$manager->printSearchbarEnd();   // close the searchbar form

$manager->printAndClearMessages();
PrintArray($r_err);
PrintArray($r_msg);
unset($r_err);
unset($r_msg);

$manager->printItems($zids);   // print links and actions
$r_state['manager'] = $manager->getState();

HtmlPageEnd();
page_close();
?>
