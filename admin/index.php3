<?php  #slice_id expected
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

require_once "../include/init_page.php3";
require_once $GLOBALS["AA_INC_PATH"] . "varset.php3";
require_once $GLOBALS["AA_INC_PATH"] . "view.php3";
require_once $GLOBALS["AA_INC_PATH"] . "pagecache.php3";
require_once $GLOBALS["AA_INC_PATH"] . "item.php3";
require_once $GLOBALS["AA_INC_PATH"] . "feeding.php3";
require_once $GLOBALS["AA_INC_PATH"] . "profile.class.php3";
require_once $GLOBALS["AA_INC_PATH"] . "itemfunc.php3";
require_once $GLOBALS["AA_INC_PATH"] . "notify.php3";
require_once $GLOBALS["AA_INC_PATH"] . "searchlib.php3";
require_once $GLOBALS["AA_INC_PATH"] . "formutil.php3";
require_once $GLOBALS["AA_INC_PATH"] . "sliceobj.php3";
require_once $GLOBALS["AA_INC_PATH"] . "msgpage.php3";
require_once $GLOBALS["AA_INC_PATH"] . "manager.class.php3";
require_once $GLOBALS["AA_INC_PATH"] . "actions.php3";

FetchSliceReadingPassword();

/** Function corresponding with 'actions' (see below) - returns true if user
 *  has the permission for the action. (The function must be called right
 *  before we perform/display action in order we have all variables set (r_state)
 *
 * @param  string  $action action to be displayed (in selectbox) / performed
 * @return bool    true if user has the permission
 */

function IsActionPerm($action) {
    global $slice;
    $display_actions = ($GLOBALS['r_state']['action_selected'] != "0");
    $subtree = $GLOBALS['r_state']['show_subtree'];
    $current_bin = $GLOBALS['r_state']['bin'];

    switch($action) {
        case 'Activate':    return  $display_actions &&
                                    IfSlPerm(PS_ITEMS2ACT) &&
                                    ($current_bin != 'app') &&
                                    ($current_bin != 'appb') &&
                                    ($current_bin != 'appc');
        case 'Folder2':     return  $display_actions &&            // Folder2 is Holding bin - prepared for more than three bins
                                    IfSlPerm(PS_ITEMS2HOLD) &&
                                    ($current_bin != 'hold');
        case 'Folder3':     return  $display_actions &&            // Folder3 is Trash
                                    IfSlPerm(PS_ITEMS2TRASH) &&
                                    ($current_bin != 'trash');
        case 'Feed':        return  ($GLOBALS['r_state']['feed_selected'] != "0");
        case 'Preview':     return  ($GLOBALS['r_state']['view_selected'] != "0");
        case 'FillField':   return  IfSlPerm(PS_EDIT_ALL_ITEMS);
        case 'Email':       return  ($slice->type() == 'ReaderManagement');
        //--  switches      ------
        case 'DeleteTrash': return  IfSlPerm(PS_DELETE_ITEMS);
        case 'Delete':      return  IfSlPerm(PS_DELETE_ITEMS);
        case 'Tab':         return  true;
        case 'GoBookmark':  return  true;
        case 'SendEmail':   return  ($slice->type() == 'ReaderManagement');
    }
    return false;
}

function CountItemsInBins() {
    global $p_slice_id;
    $db = getDB();
    $now = now('step');

    $ret['folder1'] = $ret['folder2'] = $ret['folder3'] = $ret['expired'] = $ret['pending'] = 0;
    $db->tquery("SELECT status_code, count(*) as cnt FROM item
                 WHERE slice_id = '$p_slice_id'
                 GROUP BY status_code");
    while( $db->next_record() )
        $ret[ 'folder'. $db->f('status_code') ] = $db->f('cnt');

    $db->tquery("SELECT count(*) as cnt FROM item
                 WHERE slice_id = '$p_slice_id'
                   AND status_code=1
                   AND expiry_date <= '$now' ");
    if( $db->next_record() )
        $ret['expired'] = $db->f('cnt');

    $db->tquery("SELECT count(*) as cnt FROM item
                 WHERE slice_id = '$p_slice_id'
                   AND status_code=1
                   AND publish_date > '$now' ");
    if( $db->next_record() )
        $ret['pending'] = $db->f('cnt');

    $ret['app'] = $ret['folder1']-$ret['pending']-$ret['expired'];
    return $ret;
}

// id of the editted module (id in long form (32-digit hexadecimal number))
$module_id = $slice_id;
// module_id is the same as slice_id (slice_id was used before AA introduced
// modules. Now it is better to use module_id, because in other modules
// (like Links, ...) it is not so confusing

$p_module_id = q_pack_id($module_id); # packed to 16-digit as stored in database
$slice = new slice($module_id);
$bin_def = array( 'app'    => array('cond'=>'ACTIVE'),
                  'appb'   => array('cond'=>'PENDING'),
                  'appc'   => array('cond'=>'EXPIRED'),
                  'hold'   => array('cond'=>'HOLDING'),
                  'trash'  => array('cond'=>'TRASH')
                );

$perm_edit_all  = IfSlPerm(PS_EDIT_ALL_ITEMS);
$perm_edit_self = IfSlPerm(PS_EDIT_SELF_ITEMS);

if( !$perm_edit_all && !$perm_edit_self) {
  MsgPage($sess->url(self_base())."index.php3", _m("You do not have permission to edit items in the slice:").sliceid2name($slice_id));
  exit;
}

$manager_settings = array(
     'show'     =>  MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS,    // MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS
     'searchbar' => array(
         'fields'               => $slice->fields('search'),
         'search_row_count_min' => 1,
         'order_row_count_min'  => 1,
         'add_empty_search_row' => true,
         'function'             => false  // name of function for aditional action hooked on standard filter action
                         ),
     'scroller'  => array(
         'listlen'              => ($listlen ? $listlen : EDIT_ITEM_COUNT),
         'slice_id'             => $slice_id
                         ),
     'itemview'  => array(
         'manager_vid'          => false,    // $slice_info['manager_vid'],      // id of view which controls the design
         'format'               => $slice->get_format_strings(),   // optionaly to manager_vid you can set format array
         'fields'               => $slice->fields('record'),
         'aliases'              => $slice->aliases(),
         'get_content_funct'    => 'GetItemContent'
                         ),
     'actions_perm_function' => 'IsActionPerm',
     'actions'   => array(
         'Activate'    => array('function'   => 'Item_MoveItem',
                                'func_param' => 1,
                                'name'       => _m('Move to Active'),
                               ),
         'Folder2'     => array('function'   => 'Item_MoveItem',
                                'func_param' => 2,
                                'name'       => _m('Move to Holding bin'),
                               ),
         'Folder3'     => array('function'   => 'Item_MoveItem',
                                'func_param' => 3,
                                'name'       => _m('Move to Trash'),
                               ),
         'Feed'        => array('function'   => 'Item_Feed',
                                'func_param' => &$slice,
                                'name'       => _m('Export'),
                                'open_url'   => $sess->url("feed_to.php3"),
                               ),
                          // no function - this function just opens preview
         'Preview'     => array('name'       => _m('Preview'),
                                'open_url'   => con_url($slice->getfield('slice_url'),'rXn=1'), // rXn=1 is foo parameter to make sure, we can use '&' to join items[] parameter (see open_url_add below)
                                'open_url_add' => '&'    // add items[] array to open_url url which will hold checked items
                               ),
         'FillField'   => array('name'       => _m('Modify content'),
                                'open_url'   => $sess->url("search_replace.php3"),
                                'open_url_add' => '&'    // add items[] array to open_url url which will hold checked items
                               ),
         'Email'       => array('name'       => _m('Send email'),
                                'open_url'   => $sess->url("write_mail.php3"),
                                'open_url_add' => '&'    // add items[] array to open_url url which will hold checked items
                               )
                         ),
     'switches'  => array(
         'DeleteTrash' => array('function'   => 'Item_DeleteTrash'),
         'Delete'      => array('function'   => 'Item_DeleteTrash'),
         'Tab'         => array('function'   => 'Item_Tab'),
         'GoBookmark'  => array('function'   => 'Item_GoBookmark')
                         ),
     'messages'  => array(
         'title'       => ($slice->type() == 'ReaderManagement') ?
                          _m('ActionApps - Reader Manager') :
                          _m('ActionApps - Item Manager')
                         )
         );

$manager = new manager($manager_settings);
$profile = new aaprofile($auth->auth["uid"], $module_id); // current user settings

// r_state array holds all configuration of Links Manager
// the configuration then could be Bookmarked
if ( !isset($r_state) OR $change_id OR ($r_state["module_id"] != $module_id)) {
    // we are here for the first time or we are switching to another slice
    unset($r_state);
    // set default admin interface settings from user's profile
    $r_state["module_id"]       = $module_id;
    $r_state['bin']             = 'app';
    $sess->register('r_state');

    $manager->setFromProfile($profile);
}

if ($r_state['manager']) {        // do not set state for the first time calling
    $manager->setFromState($r_state['manager']);
}

$manager->performActions();

$r_state['bin_cnt'] = CountItemsInBins();

$manager->printHtmlPageBegin(true);  // html, head, css, title, javascripts

require_once $GLOBALS["AA_INC_PATH"]."menu.php3";
showMenu($aamenus, "itemmanager", $r_state['bin'], $navbar != "0", $leftbar != "0");

$conds = $manager->getConds();
$sort  = $manager->getSort();

// authors have only permission to edit its own items
if (! $perm_edit_all )
  $conds[]=array( 'operator' => '=',
                  'value' => $auth->auth['uid'],
                  'posted_by.......' => 1 );


$zids=QueryZIDs($slice->fields('record'), $slice_id, $conds, $sort, "", $bin_def[$r_state['bin']]['cond']);

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
