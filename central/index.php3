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

// @todo only_action option which prints on the output the result of the action
// Then it could be used as AJAX call for this action!

use AA\Manager;
use AA\Manageraction\Central_DeleteTrash;
use AA\Manageraction\Central_Linkcheck;
use AA\Manageraction\Central_MoveItem;
use AA\Manageraction\Central_Optimize;
use AA\Manageraction\Central_Sqlupdate;
use AA\Manageraction\Central_Tab;
use AA\Manageraction\Manageractions;

// const  AA_REGISTER_GLOBALS = true;
use AA\Util\InputVars;

require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/varset.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/item.php";
require_once __DIR__."/../central/include/actionapps.class.php";

try {
    $err = InputVars::arrayGETPOST('err');
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception $e) {}


/**
 * @return int[]
 */
function CountItemsInBins() {
    return \AA\IO\DB\DB_AA::select(['folder'=>'cnt'], "SELECT CONCAT('folder', status_code) as folder, count(*) as cnt  FROM central_conf GROUP BY status_code");
}

if ( !IsSuperadmin() ) {
    MsgPage(_m("You do not have permission to manage ActionApps instalations"));
    exit;
}

// we do not manage more "modules" here, so unique id is OK
$module_id = '43656e7472616c2d41412d61646d696e';
$metabase  = AA::Metabase();

$actions   = new Manageractions;
$actions->addAction(new Central_MoveItem('Activate', 1));
$actions->addAction(new Central_MoveItem('Folder2',  2));
$actions->addAction(new Central_MoveItem('Folder3',  3));
$actions->addAction(new Central_Sqlupdate('Sqlupdate_Test',   'dotest'));
$actions->addAction(new Central_Sqlupdate('Sqlupdate_Update', 'update'));
$actions->addAction(new Central_Linkcheck('AA\Util\Linkcheck'));
$actions->addAction(new Central_Optimize('Update_Db_Structure_Test', 'AA\Optimize\UpdateDbStructure', 'test'));
$actions->addAction(new Central_Optimize('Update_Db_Structure_Repair', 'AA\Optimize\UpdateDbStructure', 'repair'));
$actions->addAction(new Central_Optimize('Field_Duplicates_Test', 'AA\Optimize\FieldDuplicates', 'test'));
$actions->addAction(new Central_Optimize('Field_Duplicates_Repair', 'AA\Optimize\FieldDuplicates', 'repair'));
$actions->addAction(new Central_DeleteTrash('DeleteTrashAction',true));

$switches  = new Manageractions;

// no problem to write tabs as one action, but we use 3
$switches->addAction(new Central_Tab('Tab1', 'app'));
$switches->addAction(new Central_Tab('Tab2', 'hold'));
$switches->addAction(new Central_Tab('Tab3', 'trash'));
$switches->addAction(new Central_DeleteTrash('DeleteTrash',false));

$managed = $metabase->getManagerConf('central_conf', $actions, $switches);
//$manager_settings['itemview']['format']['compact_top'] = '
//                                          <table>';
//$manager_settings['itemview']['format']['odd_row_format'] = '
//                                    <tr>
//                                      <td width="30"><input type="checkbox" name="chb[_#ID______]" value=""></td>
//                                      <td><a href="'.StateUrl('tabledit.php?cmd[centraledit][edit][_#ID______]=1').'"> {ifset:{_#ORG_NAME}:_#1:--} </a></td>
//                                      <td>_#AA_ID___</td>
//                                      <td>_#DB_SERVE - _#DB_NAME_</td>
//                                      <td>_#AA_HTTP__#AA_BASE_</td>
//                                    </tr>
//                                    <tr>
//                                      <td>&nbsp;</td>
//                                      <td colspan="4"><a href="{sessurl:?akce=Sqlupdate_Test&chb[_#ID______]=1}">'._m('sql_upadte TEST') .'</a> &nbsp; &nbsp; <a href="{sessurl:?akce=Sqlupdate_Update&chb[_#ID______]=1}">'._m('sql_upadte NOW!') .'</a></td>
//                                    </tr>
//                                   ';
// $manager_settings['itemview']['query_funct'] = 'Central_QueryZids';
$managed->setTitle(_m('ActionApps Central'));
//         'get_content_funct'    => 'Central_GetAaContent'

$managed->setColumns2show(['ORG_NAME','AA_HTTP_DOMAIN','AA_BASE_DIR']);
$manager = new Manager('central', $managed);
$manager->performActions();

// need for menu
$r_state['bin_cnt'] = CountItemsInBins();

$apage = new AA_Adminpageutil('central',$manager->getBin());
$apage->setForm();

$html = $manager->getDisplayPage();

// this allows to automatically load codemirror in the head (printHead is called after $form->getObjectEditHtml)
$apage->printAllPage($html, $err, $Msg);

