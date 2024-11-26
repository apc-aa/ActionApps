<?php  //slice_id expected
/**
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
 * along with this program (LICENSE); if not, write tao the Free Software
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
use AA\Manageraction\Item_Tab;
use AA\Manageraction\Manageractions;
use AA\Manageraction\Polls_DeleteTrash;
use AA\Manageraction\Polls_MoveItem;

// const  AA_REGISTER_GLOBALS = true;
use AA\Util\InputVars;

require_once __DIR__ . "/../../include/init_page.php";
require_once __DIR__ . "/../../include/varset.php";
require_once __DIR__ . "/../../include/formutil.php";

require_once __DIR__ . "/../../include/item.php";
require_once __DIR__ . "/../../include/actions.php";
require_once __DIR__ . "/../../modules/polls/include/util.php";

try {
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception) {}


if ( !IfSlPerm(PS_EDIT_ALL_ITEMS) ) {
    MsgPage(_m("You do not have permission to manage Polls"));
    exit;
}

$actions   = new Manageractions;
$actions->addAction(new Polls_MoveItem('Activate', 1));
$actions->addAction(new Polls_MoveItem('Folder2',  2));
$actions->addAction(new Polls_MoveItem('Folder3',  3));
$actions->addAction(new Polls_DeleteTrash('DeleteTrashAction',true));

$switches  = new Manageractions;

// no problem to write tabs as one action, but we use 3
$switches->addAction(new Item_Tab('Folder1a', 'app'));      // we use the same action as for Items!
$switches->addAction(new Item_Tab('Folder1b', 'appb'));     // we use the same action as for Items!
$switches->addAction(new Item_Tab('Folder1c', 'appc'));     // we use the same action as for Items!
$switches->addAction(new Item_Tab('Folder2',  'folder2'));  // we use the same action as for Items!
$switches->addAction(new Item_Tab('Folder3',  'folder3'));  // we use the same action as for Items!
$switches->addAction(new Polls_DeleteTrash('DeleteTrash',false));


// id of the editted module (id in long form (32-digit hexadecimal number))
$module_id = AA::$module_id;
// module_id is the same as slice_id (slice_id was used before AA introduced
// modules. Now it is better to use module_id, because in other modules
// (like Links, ...) it is not so confusing

$p_module_id = q_pack_id($module_id); // packed to 16-digit as stored in database

$managed  = AA::Metabase()->getManagerConf('polls', $actions, $switches, ['edit_url' => get_aa_url('modules/polls/polledit.php?poll_id=_#id')]);

// function - we need translate _m() on use (not at include time)

//$manager_settings['itemview']['aliases'] = new AA_Aliases(new AA_Aliasgenerator_Polls);
//
//$manager_settings['itemview']['format']['compact_top'] = '
//                                      <table class="aa-table">
//                                        <tr class="aa-tbl-header"><th width="30">&nbsp;</th><th>'. _m('ID'). '</th><th>'. _m('Poll Question'). '</th><th>'. _m('Publish Date'). '</th><th>'. _m('Expiry Date'). '</th></tr>
//                                      ';
//$manager_settings['itemview']['format']['odd_row_format'] = '<tr><td width="30"><input type="checkbox" name="chb[x_#POLL_ID_]" value=""></td><td>_#POLL_ID_</td><td><a href="_#EDITPOLL">_#QUESTION</a></td><td>_#PUB_DATE</td><td>_#EXP_DATE</td></tr>
//                                      ';

$manager = new Manager('poll'.$module_id, $managed);   // no special meaning of the manager_id, just some ID
$manager->performActions();

$aa_set = $manager->getSet();

// exact match - no SQL parsing (we added this because SQL Syntax parser in AA have problems with packed ids)
$aa_set->addCondition(new AA_Condition('module_id', '==', $p_module_id));

// there is also one poll which acts as template - managed from Polls Admin
// (and not from the Polls Manager page) - it has status_code=0,
// so it is filtered out automatically

$now = now();
$bin_sets['app'] = clone($aa_set);
$bin_sets['app']->addCondition(new AA_Condition('status_code', '==', '1'));
$bin_sets['app']->addCondition(new AA_Condition('expiry_date', '>=', $now));
$bin_sets['app']->addCondition(new AA_Condition('publish_date', '<=', $now));

$bin_sets['appb'] = clone($aa_set);
$bin_sets['appb']->addCondition(new AA_Condition('status_code', '==', '1'));
$bin_sets['appb']->addCondition(new AA_Condition('publish_date', '>', $now));

$bin_sets['appc'] = clone($aa_set);
$bin_sets['appc']->addCondition(new AA_Condition('status_code', '==', '1'));
$bin_sets['appc']->addCondition(new AA_Condition('expiry_date', '<', $now));

$bin_sets['folder2'] = clone($aa_set);
$bin_sets['folder2']->setBins(AA_BIN_HOLDING);

$bin_sets['folder3'] = clone($aa_set);
$bin_sets['folder3']->setBins(AA_BIN_TRASH);

$r_state['bin_cnt']['app']     = AA_Metabase::queryCount(['table' => 'polls'], $bin_sets['app']);
$r_state['bin_cnt']['appb']    = AA_Metabase::queryCount(['table' => 'polls'], $bin_sets['appb']);
$r_state['bin_cnt']['appc']    = AA_Metabase::queryCount(['table' => 'polls'], $bin_sets['appc']);
$r_state['bin_cnt']['folder2'] = AA_Metabase::queryCount(['table' => 'polls'], $bin_sets['folder2']);
$r_state['bin_cnt']['folder3'] = AA_Metabase::queryCount(['table' => 'polls'], $bin_sets['folder3']);

$zids  = AA::Metabase()->queryZids(['table'=>'polls'], $bin_sets[$manager->getBin()]);

//$manager_settings['itemview']['query_funct'] = 'Central_QueryZids';
//$manager_settings['messages']['title'] = _m('ActionApps Central');
//         'get_content_funct'    => 'Central_GetAaContent'

$apage = new AA_Adminpageutil('pollsmanager',$manager->getBin());
$apage->setForm();

$html = $manager->getDisplayPage($zids);


// this allows to automatically load codemirror in the head (printHead is called after $form->getObjectEditHtml)
$apage->printAllPage($html, $err, $Msg);

