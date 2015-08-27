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
require_once AA_INC_PATH . "slice.class.php3";
require_once AA_INC_PATH . "msgpage.php3";
require_once AA_INC_PATH . "manager.class.php3";
require_once AA_INC_PATH . "actions.php3";

function CountItemsInBins() {
    global $p_slice_id;
    $db = getDB();
    $now = now('step');
    $ret = array('folder1'=>0, 'folder2'=>0, 'folder3'=>0, 'expired'=>0, 'pending'=>0);

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
                   AND publish_date > '$now'
                   AND expiry_date > '$now' ");
                   if ( $db->next_record() ) {
                       $ret['pending'] = $db->f('cnt');
                   }

    $ret['app'] = $ret['folder1']-$ret['pending']-$ret['expired'];
    return $ret;
}

// Allow edit current slice without slice_pwd
$credentials = AA_Credentials::singleton();
$credentials->loadFromSlice($slice_id);

// id of the editted module (id in long form (32-digit hexadecimal number))
$module_id = $slice_id;
// module_id is the same as slice_id (slice_id was used before AA introduced
// modules. Now it is better to use module_id, because in other modules
// (like Links, ...) it is not so confusing

$p_module_id = q_pack_id($module_id); // packed to 16-digit as stored in database
$slice       = AA_Slice::getModule($module_id);

$perm_edit_all  = IfSlPerm(PS_EDIT_ALL_ITEMS);
$perm_edit_self = IfSlPerm(PS_EDIT_SELF_ITEMS);

if ( !$perm_edit_all && !$perm_edit_self) {
    MsgPage($sess->url(self_base())."index.php3", _m("You do not have permission to edit items in the slice:").AA_Slice::getModuleName($slice_id));
    exit;
}


$actions   = new AA_Manageractions;
$actions->addAction(new AA_Manageraction_Item_MoveItem('Activate', 1));
$actions->addAction(new AA_Manageraction_Item_MoveItem('Folder2',  2));
$actions->addAction(new AA_Manageraction_Item_MoveItem('Folder3',  3));

$new_action = new AA_Manageraction_Item_Feed('Feed', $slice->getId());
$new_action->setOpenUrl($sess->url("feed_to.php3"));
$actions->addAction($new_action);

// rXn=1 is foo parameter to make sure, we can use '&' to join items[] parameter (see open_url_add below)
// '&'    add items[] array to open_url url which will hold checked items
$actions->addAction(new AA_Manageraction_Item_Preview(      'Preview',       con_url($slice->getProperty('slice_url'),'rXn=1'), '&'));
$actions->addAction(new AA_Manageraction_Item_Modifycontent('ModifyContent', $sess->url("search_replace.php3"), '&'));
$actions->addAction(new AA_Manageraction_Item_Email(        'Email',         $sess->url("write_mail.php3"),     '&'));
$actions->addAction(new AA_Manageraction_Item_DeleteTrash(  'DeleteTrashAction',true));
$actions->addAction(new AA_Manageraction_Item_Duplicate(    'Duplicate'));
$actions->addAction(new AA_Manageraction_Item_Move2slice(   'Move2slice',    $module_id));
$actions->addAction(new AA_Manageraction_Item_Export(       'Export2File'));


$switches  = new AA_Manageractions;

// no problem to write tabs as one action, but we use 3
$switches->addAction(new AA_Manageraction_Item_Tab('Tab1a', 'app'));
$switches->addAction(new AA_Manageraction_Item_Tab('Tab1b', 'appb'));
$switches->addAction(new AA_Manageraction_Item_Tab('Tab1c', 'appc'));
$switches->addAction(new AA_Manageraction_Item_Tab('Tab2',  'hold'));
$switches->addAction(new AA_Manageraction_Item_Tab('Tab3',  'trash'));
$switches->addAction(new AA_Manageraction_Item_DeleteTrash('DeleteTrash',false));
$switches->addAction(new AA_Manageraction_Item_GoBookmark(   'GoBookmark'));
//$switches->addAction(new AA_Manageraction_Item_Email('SendEmail'));


$profile       = AA_Profile::getProfile($auth->auth["uid"], $module_id); // current user settings
$show_settings = MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS | MGR_SB_ALLTEXT | MGR_SB_ALLNUM;
if ($profile->getProperty('ui_manager_hide', 'mgr_actions'))       { $show_settings -= MGR_ACTIONS; }
if ($profile->getProperty('ui_manager_hide', 'mgr_sb_searchrows')) { $show_settings -= MGR_SB_SEARCHROWS; }
if ($profile->getProperty('ui_manager_hide', 'mgr_sb_orderrows'))  { $show_settings -= MGR_SB_ORDERROWS; }
if ($profile->getProperty('ui_manager_hide', 'mgr_sb_bookmarks'))  { $show_settings -= MGR_SB_BOOKMARKS; }

$manager_settings = array(
     'module_id' => $slice_id,
     'show'      => $show_settings,    // MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS
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
         'title'       => $slice->name(). ' - '. (($slice->type() == 'ReaderManagement') ? _m('ActionApps - Reader Manager') : _m('ActionApps - Item Manager'))
                         )
         );

$manager = new AA_Manager('item'.$module_id, $manager_settings);

if ( $change_id OR ($r_state["module_id"] != $module_id)) {
    // we are here for the first time or we are switching to another slice
    unset($r_state);
    // set default admin interface settings from user's profile
    $r_state["module_id"]       = $module_id;
    $sess->register('r_state');

    $manager->setFromProfile($profile);
}

$manager->performActions();

$r_state['bin_cnt'] = CountItemsInBins();

// just for menu
$bookmarks = $manager->getBookmarkNames();

$aa_set = $manager->getSet();  // do not use $set variable name, since it confuses set[] url command in view

$perm_set_id = $profile->getProperty('admin_perm');

// permissions could be diffined in user profiles through Item Set conditions
if ($perm_set_id) {
    $profile_set = AA_Object::load($perm_set_id, 'AA_Set');
    if ( is_null($profile_set)) {
        // we want to proceed next $perm_edit_all condition
        $perm_set_id = 0;
    } else {
        $conds_string = $profile_set->getCondsAsString();
        // the conditions could use some aliases with user_id say: d-organization....-=-{user:_#ORGANIZA}
        $conds_string = AA_Stringexpand::unalias($conds_string);
        $aa_set->addCondsFromString($conds_string);
    }
}

// authors have only permission to edit its own items
if (!$perm_edit_all AND !$perm_set_id) {
    $aa_set->addCondition(new AA_Condition('posted_by.......', '=', $auth->auth['uid']));
}

$BIN_CONDS   = array( 'app'    => AA_BIN_ACTIVE,
                      'appb'   => AA_BIN_PENDING,
                      'appc'   => AA_BIN_EXPIRED,
                      'hold'   => AA_BIN_HOLDING,
                      'trash'  => AA_BIN_TRASH
                    );

$zids = QueryZIDs( array($slice_id), $aa_set->getConds(), $aa_set->getSort(), $BIN_CONDS[$manager->getBin()]);

require_once AA_INC_PATH."menu.php3";

$manager->displayPage($zids, 'itemmanager', $manager->getBin(), $profile->getProperty('ui_manager', 'css_add'));

page_close();
?>
