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

// @todo only_action option which prints on the output the result of the action
// Then it could be used as AJAX call for this action!

require_once "../include/init_page.php3";
require_once AA_INC_PATH . "varset.php3";
require_once AA_INC_PATH . "formutil.php3";
require_once AA_INC_PATH . "pagecache.php3";
require_once AA_INC_PATH . "item.php3";
require_once AA_INC_PATH . "manager.class.php3";
require_once AA_BASE_PATH. "central/include/actionapps.class.php";
require_once AA_BASE_PATH. "central/include/actions.php3";

/** Function corresponding with 'actions' (see below) - returns true if user
 *  has the permission for the action. (The function must be called right
 *  before we perform/display action in order we have all variables set (r_state)
 *
 * @param  string  $action action to be displayed (in selectbox) / performed
 * @return bool    true if user has the permission
 */

function CountItemsInBins() {
    $db = getDB();

    $ret['folder1'] = $ret['folder2'] = $ret['folder3'] = 0;
    $db->tquery("SELECT status_code, count(*) as cnt FROM central_conf
                 GROUP BY status_code");
                 while ( $db->next_record() ) {
                     $ret[ 'folder'. $db->f('status_code') ] = $db->f('cnt');
                 }
    return $ret;
}

if ( !IsSuperadmin() ) {
    MsgPage($sess->url(self_base())."index.php3", _m("You do not have permission to manage ActioApps instalations"));
    exit;
}

// we do not manage more "modules" here, so unique id is OK
$module_id = '43656e7472616c2d41412d61646d696e';
$metabase  = AA_Metabase::singleton();

$actions   = new AA_Manageractions;
$actions->addAction(new AA_Manageraction_Central_MoveItem('Activate', 1));
$actions->addAction(new AA_Manageraction_Central_MoveItem('Folder2',  2));
$actions->addAction(new AA_Manageraction_Central_MoveItem('Folder3',  3));
$actions->addAction(new AA_Manageraction_Central_Sqlupdate('Sqlupdate_Test',   'dotest'));
$actions->addAction(new AA_Manageraction_Central_Sqlupdate('Sqlupdate_Update', 'update'));
$actions->addAction(new AA_Manageraction_Central_Linkcheck('Linkcheck'));
$actions->addAction(new AA_Manageraction_Central_Optimize('Update_Db_Structure_Test',   'AA_Optimize_Update_Db_Structure', 'test'));
$actions->addAction(new AA_Manageraction_Central_Optimize('Update_Db_Structure_Repair', 'AA_Optimize_Update_Db_Structure', 'repair'));
$actions->addAction(new AA_Manageraction_Central_Optimize('Field_Duplicates_Test',      'AA_Optimize_Field_Duplicates', 'test'));
$actions->addAction(new AA_Manageraction_Central_Optimize('Field_Duplicates_Repair',    'AA_Optimize_Field_Duplicates', 'repair'));
$actions->addAction(new AA_Manageraction_Central_DeleteTrash('DeleteTrashAction',true));

$switches  = new AA_Manageractions;

// no problem to write tabs as one action, but we use 3
$switches->addAction(new AA_Manageraction_Central_Tab('Tab1', 'app'));
$switches->addAction(new AA_Manageraction_Central_Tab('Tab2', 'hold'));
$switches->addAction(new AA_Manageraction_Central_Tab('Tab3', 'trash'));
$switches->addAction(new AA_Manageraction_Central_DeleteTrash('DeleteTrash',false));

function GetCentralAliases() {
    // fields: array('id', 'dns_conf', 'dns_serial', 'dns_web', 'dns_mx', 'dns_db', 'dns_prim', 'dns_sec', 'web_conf', 'web_path', 'db_server', 'db_name', 'db_user', 'db_pwd', 'AA_SITE_PATH', 'AA_BASE_DIR', 'AA_HTTP_DOMAIN', 'AA_ID', 'ORG_NAME', 'ERROR_REPORTING_EMAIL', 'ALERTS_EMAIL', 'IMG_UPLOAD_MAX_SIZE', 'IMG_UPLOAD_URL', 'IMG_UPLOAD_PATH', 'SCROLLER_LENGTH', 'FILEMAN_BASE_DIR', 'FILEMAN_BASE_URL', 'FILEMAN_UPLOAD_TIME_LIMIT', 'AA_ADMIN_USER', 'AA_ADMIN_PWD', 'status_code'));
    $aliases["_#ORG_NAME"] = GetAliasDef( "f_h", "ORG_NAME",       'ORG_NAME');
    $aliases["_#AA_ID___"] = GetAliasDef( "f_h", "AA_ID",          'AA_ID');
    $aliases["_#ID______"] = GetAliasDef( "f_h", "id",             'id');
    $aliases["_#DB_SERVE"] = GetAliasDef( "f_h", "db_server",      'db_server');
    $aliases["_#DB_NAME_"] = GetAliasDef( "f_h", "db_name",        'db_name');
    $aliases["_#HTTP_DOM"] = GetAliasDef( "f_h", "AA_HTTP_DOMAIN", 'AA_HTTP_DOMAIN');
    $aliases["_#AA_BASE_"] = GetAliasDef( "f_h", "AA_BASE_DIR",    'AA_BASE_DIR');
    return $aliases;
}

$manager_settings = array(
     'module_id' => $module_id,
     'show'      =>  MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS,    // MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS
     'searchbar' => array(
         'fields'               => $metabase->getSearchArray('central_conf'),
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
         'format'               => array(    // optionaly to manager_vid you can set format array
             'compact_top'      => "<table border=0 cellspacing=0 cellpadding=5>",
             'category_sort'    => false,
             'category_format'  => "",
             'category_top'     => "",
             'category_bottom'  => "",
             'even_odd_differ'  => false,
             'even_row_format'  => "",
             'odd_row_format'   => '
                                    <tr class=tabtxt>
                                      <td width="30"><input type="checkbox" name="chb[_#ID______]" value=""></td>
                                      <td class=tabtxt><a href="'.$sess->url('tabledit.php3?cmd[centraledit][edit][_#ID______]=1').'"> _#ORG_NAME </a></td>
                                      <td class=tabtxt>_#AA_ID___</td>
                                      <td class=tabtxt>_#DB_SERVE - _#DB_NAME_</td>
                                      <td class=tabtxt>_#HTTP_DOM_#AA_BASE_</td>
                                    </tr>
                                    <tr class="tabtxt">
                                      <td>&nbsp;</td>
                                      <td class="tabtxt" colspan="4"><a href="{sessurl:?akce=Sqlupdate_Test&chb[_#ID______]=1}">'._m('sql_upadte TEST') .'</a> &nbsp; &nbsp; <a href="{sessurl:?akce=Sqlupdate_Update&chb[_#ID______]=1}">'._m('sql_upadte NOW!') .'</a></td>
                                    </tr>
                                   ',
             'compact_remove'   => "",
             'compact_bottom'   => "</table>",
             'id'               => $module_id ),
         'fields'               => $metabase->getSearchArray('central_conf'),
         'aliases'              => GetCentralAliases(),
         'get_content_funct'    => 'Central_GetAaContent'
                         ),
     'actions'   => $actions,
     'switches'  => $switches,
     'bin'       => 'app',
     'messages'  => array(
         'title'       => _m('ActionApps Central')
                         )
         );

$manager = new AA_Manager($manager_settings);
$profile = AA_Profile::getProfile($auth->auth["uid"], $module_id); // current user settings

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

// need for menu
$r_state['bin_cnt'] = CountItemsInBins();

$manager->printHtmlPageBegin(true);  // html, head, css, title, javascripts

require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "central", $manager->getBin(), $navbar != "0", $leftbar != "0");

$conds = $manager->getConds();
$sort  = $manager->getSort();

$BIN_CONDS   = array( 'app'    => AA_BIN_APPROVED,
                      'hold'   => AA_BIN_HOLDING,
                      'trash'  => AA_BIN_TRASH
                    );
$zids = Central_QueryZids($conds, $sort, $BIN_CONDS[$manager->getBin()]);

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
