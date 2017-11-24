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

    $ret = array('folder1'=>0, 'folder2'=>0, 'folder3' => 0);
    $db->tquery("SELECT status_code, count(*) as cnt FROM central_conf
                 GROUP BY status_code");
                 while ( $db->next_record() ) {
                     $ret[ 'folder'. $db->f('status_code') ] = $db->f('cnt');
                 }
    return $ret;
}

if ( !IsSuperadmin() ) {
    MsgPage(StateUrl(self_base())."index.php3", _m("You do not have permission to manage ActioApps instalations"));
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

$manager_settings = $metabase->getManagerConf('central_conf', $actions, $switches);
$manager_settings['itemview']['format']['compact_top'] = '
                                          <table border=0 cellspacing=0 cellpadding=5>';
$manager_settings['itemview']['format']['odd_row_format'] = '
                                    <tr class=tabtxt>
                                      <td width="30"><input type="checkbox" name="chb[_#ID______]" value=""></td>
                                      <td class=tabtxt><a href="'.StateUrl('tabledit.php3?cmd[centraledit][edit][_#ID______]=1').'"> _#ORG_NAME </a></td>
                                      <td class=tabtxt>_#AA_ID___</td>
                                      <td class=tabtxt>_#DB_SERVE - _#DB_NAME_</td>
                                      <td class=tabtxt>_#AA_HTTP__#AA_BASE_</td>
                                    </tr>
                                    <tr class="tabtxt">
                                      <td>&nbsp;</td>
                                      <td class="tabtxt" colspan="4"><a href="{sessurl:?akce=Sqlupdate_Test&chb[_#ID______]=1}">'._m('sql_upadte TEST') .'</a> &nbsp; &nbsp; <a href="{sessurl:?akce=Sqlupdate_Update&chb[_#ID______]=1}">'._m('sql_upadte NOW!') .'</a></td>
                                    </tr>
                                   ';
$manager_settings['messages']['title'] = _m('ActionApps Central');

//         'get_content_funct'    => 'Central_GetAaContent'

$manager = new AA_Manager('central', $manager_settings);
$manager->performActions();

// need for menu
$r_state['bin_cnt'] = CountItemsInBins();
$conds = $manager->getConds();
$sort  = $manager->getSort();
$BIN_CONDS   = array( 'app'    => AA_BIN_APPROVED,
                      'hold'   => AA_BIN_HOLDING,
                      'trash'  => AA_BIN_TRASH
                    );
$zids = Central_QueryZids($conds, $sort, $BIN_CONDS[$manager->getBin()]);

require_once AA_INC_PATH."menu.php3";
$manager->displayPage($zids, 'central', $manager->getBin());

page_close();
?>
