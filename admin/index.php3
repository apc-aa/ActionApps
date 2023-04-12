<?php  //slice_id expected
/**
 *
 *
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
 *
*/

use AA\Manager;
use AA\Manageraction\Item_DeleteTrash;
use AA\Manageraction\Item_Duplicate;
use AA\Manageraction\Item_Email;
use AA\Manageraction\Item_Export;
use AA\Manageraction\Item_Feed;
use AA\Manageraction\Item_Gobookmark;
use AA\Manageraction\Item_Modifycontent;
use AA\Manageraction\Item_Move2Slice;
use AA\Manageraction\Item_MoveItem;
use AA\Manageraction\Item_Preview;
use AA\Manageraction\Item_Tab;
use AA\Manageraction\Manageractions;
use AA\Util\InputVars;

// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/varset.php";
require_once __DIR__ . "/../include/view.php";
require_once __DIR__ . "/../include/item.php";
require_once __DIR__ . "/../include/feeding.php";
require_once __DIR__ . "/../include/itemfunc.php";
require_once __DIR__ . "/../include/notify.php";
require_once __DIR__ . "/../include/searchlib.php";
require_once __DIR__ . "/../include/formutil.php";

require_once __DIR__ . "/../include/actions.php";

try {
    // array ( 'app' => 82, )
    $Tab = InputVars::stringGET('Tab'); // alpha
    // array ( 1 => 1, )
    $Tab2 = InputVars::boolGET('Tab2');
    // array ( 'Folder3' => 2, )
    // $akce = InputVars::stringPOST('akce'); // alnum // not necessary
    // array ( '' => 2, )
    // $akce_param = InputVars::stringPOST('akce_param'); // empty // not necessary
    // array ( 1 => 49, )
    $cancel = InputVars::boolGET('cancel');
    // array ( '4085d9fe61e…' => 1, )
    $change_id = InputVars::idGET('change_id');
    // array ( )
    $chb = InputVars::arrayPOST('chb');
    // array ( 99999 => 1, )
    $listlen = InputVars::intGET('listlen');
    // array ( 'Přihlásit' => 12, )
    $submit = InputVars::boolPOST('submit');
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception $e) {}



// Allow edit current slice without slice_pwd
AA_Credentials::singleton()->loadFromSlice(AA::$module_id);

// id of the editted module (id in long form (32-digit hexadecimal number))
$module_id = AA::$module_id;
// module_id is the same as slice_id (slice_id was used before AA introduced
// modules. Now it is better to use module_id, because in other modules
// (like Links, ...) it is not so confusing

$slice       = AA_Slice::getModule($module_id);

$perm_edit_all  = IfSlPerm(PS_EDIT_ALL_ITEMS);
$perm_edit_self = IfSlPerm(PS_EDIT_SELF_ITEMS);

if ( !$perm_edit_all && !$perm_edit_self) {
    MsgPage(_m("You do not have permission to edit items in the slice:") . AA_Slice::getModuleName(AA::$module_id));
    exit;
}


//$url_param = [
//    'Msg' => MsgOk(_m("User successfully added to permission system")),
//    'UsrSrch' => _m("Search"),
//    'usr'     => $user_login
//];
//huhl(get_url(StateUrl(self_base() . "se_users.php"), $url_param));



$actions   = new Manageractions;
$actions->addAction(new Item_MoveItem('Activate', 1));
$actions->addAction(new Item_MoveItem('Folder2',  2));
$actions->addAction(new Item_MoveItem('Folder3',  3));

$new_action = new Item_Feed('Feed', $slice->getId());
$new_action->setOpenUrl(StateUrl("feed_to.php"));
$actions->addAction($new_action);

// rXn=1 is foo parameter to make sure, we can use '&' to join items[] parameter (see open_url_add below)
// '&'    add items[] array to open_url url which will hold checked items
$actions->addAction(new Item_Preview(      'Preview',       con_url($slice->getProperty('slice_url'),'rXn=1'), '&'));
$actions->addAction(new Item_Modifycontent('ModifyContent', StateUrl("search_replace.php"), '&'));
$actions->addAction(new Item_Email(        'Email',         StateUrl("write_mail.php"),     '&'));
$actions->addAction(new Item_DeleteTrash(  'DeleteTrashAction',true));
$actions->addAction(new Item_Duplicate(    'Duplicate'));
$actions->addAction(new Item_Move2Slice(   'Move2slice',    $module_id));
$actions->addAction(new Item_Export(       'Export2File'));


$switches  = new Manageractions;

// no problem to write tabs as one action, but we use 3
$switches->addAction(new Item_Tab('Tab1a', 'app'));
$switches->addAction(new Item_Tab('Tab1b', 'appb'));
$switches->addAction(new Item_Tab('Tab1c', 'appc'));
$switches->addAction(new Item_Tab('Tab2',  'hold'));
$switches->addAction(new Item_Tab('Tab3',  'trash'));
$switches->addAction(new Item_DeleteTrash('DeleteTrash',false));
$switches->addAction(new Item_Gobookmark(   'GoBookmark'));
//$switches->addAction(new  AA\Manageraction\Item_Email('SendEmail'));


$profile       = AA_Profile::getProfile(AuthGetUid(), $module_id); // current user settings
$show_settings = MGR_ALL;
if ($profile->getProperty('ui_manager_hide', 'mgr_actions'))       { $show_settings -= MGR_ACTIONS; }
if ($profile->getProperty('ui_manager_hide', 'mgr_sb_searchrows')) { $show_settings -= MGR_SB_SEARCHROWS; }
if ($profile->getProperty('ui_manager_hide', 'mgr_sb_orderrows'))  { $show_settings -= MGR_SB_ORDERROWS; }
if ($profile->getProperty('ui_manager_hide', 'mgr_sb_bookmarks'))  { $show_settings -= MGR_SB_BOOKMARKS; }

$manager_settings = [
     'module_id'   => $module_id,
     'show'        => $show_settings,    // MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS
     'use_profile' => true,
     'searchbar'   => [
         'fields'               => $slice->getSearchfields(),
         'search_row_count_min' => 1,
         'order_row_count_min'  => 1,
         'add_empty_search_row' => true,
         'function'             => false  // name of function for aditional action hooked on standard filter action
     ],
     'scroller'    => [
         'listlen'              => ($listlen ?: EDIT_ITEM_COUNT)
     ],
     'itemview'    => [
         'manager_vid'          => false,    // $slice_info['manager_vid'],      // id of view which controls the design
         'format'               => $slice->get_format_strings(),   // optionally to manager_vid you can set format array
         'fields'               => $slice->getFields()->getRecordArray(),
         'aliases'              => new AA_Aliases(new AA_Aliasgenerator_Slice($module_id)),
         'get_content_funct'    => 'GetItemContent'
     ],
     'actions'     => $actions,
     'switches'    => $switches,
     'bin'         => 'app'
];

$managed = (new \AA\Managed())->setSettings($manager_settings);
$managed->setTitle($slice->getName(). ' - '. (($slice->type() == 'ReaderManagement') ? _m('ActionApps - Reader Manager') : _m('ActionApps - Item Manager')));
$manager = new Manager('item'.$module_id, $managed);

if ( $change_id OR ($r_state["module_id"] != $module_id)) {
    // we are here for the first time, or we are switching to another slice
    unset($r_state);
    // set default admin interface settings from user's profile
    $r_state["module_id"]       = $module_id;
    $sess->register('r_state');

    $manager->setFromProfile($profile);
}

$manager->performActions();

$r_state['bin_cnt'] = $slice->countItemsInBins();

// just for menu
$bookmarks = $manager->getBookmarkNames();

$aa_set = $manager->getSet();  // do not use $set variable name, since it confuses set[] url command in view

$perm_set_id = $profile->getProperty('admin_perm');

// permissions could be defined in user profiles through Item Set conditions
if ($perm_set_id) {
    $profile_set = AA_Object::load($perm_set_id, 'AA_Set');     /** @var AA_Set $profile_set */
    if ( is_null($profile_set)) {
        // we want to proceed next $perm_edit_all condition
        $perm_set_id = 0;
    } else {
        $conds_string = $profile_set->getCondsAsString();
        // the conditions could use some aliases with user_id say: d-organization....-=-{user:_#ORGANIZA}
        $conds_string = AA::Stringexpander()->unalias($conds_string);
        $aa_set->addCondsFromString($conds_string);
    }
}

// authors have only permission to edit its own items
if (!$perm_edit_all AND !$perm_set_id) {
    $aa_set->addCondition(new AA_Condition('posted_by.......', '==', AuthGetUid()));
}

$apage = new AA_Adminpageutil('itemmanager',$manager->getBin());
$apage->setForm();
if ($css_add = $profile->getProperty('ui_manager', 'css_add')) {
    $apage->addRequire($css_add);
}

$html = $manager->getDisplayPage($aa_set->query());

// this allows to automatically load codemirror in the head (printHead is called after $form->getObjectEditHtml)
$apage->printAllPage($html, $err, $Msg);

