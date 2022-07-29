<?php
/** se_fulltext.php - assigns html format for fulltext view
 *   expected $slice_id for edit slice
 *   optionaly $Msg to show under <h1>Hedline</h1> (typicaly: update successful)
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

const AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/varset.php";
require_once __DIR__ . "/../include/item.php";     // GetAliasesFromField funct def
require_once __DIR__ . "/../include/msgpage.php";

if ($cancel) {
    go_url( StateUrl(self_base() . "index.php3"));
}

if (!IfSlPerm(PS_FORMS)) {
    MsgPageMenu(StateUrl(self_base())."index.php3", _m("You have not permissions to manage forms"), "admin");
    exit;
}

$module_id = $slice_id;

//$actions   = new AA_Manageractions;
//$actions->addAction(new AA_Manageraction_Taskmanager_Execute('ExecuteTaskAction'));
//$actions->addAction(new AA_Manageraction_Taskmanager_Delete('DeleteTaskAction'));

//$switches  = new AA_Manageractions;  // we do not need switches here

$manager_settings = AA_Form::getManagerConf(get_admin_url('se_forms.php'));
//$manager_settings['itemview']['aliases'] = GetPollsAliases();

//$manager_settings['itemview']['format']['compact_top'] =
//                                         '<table border="0" cellpadding="5" cellspacing="0">
//                                            <tbody><tr>
//                                              <th width="30">&nbsp;</th>
//                                              <th>name</th>
//                                              <th>id</th>
//                                            </tr>';
//$manager_settings['itemview']['format']['odd_row_format'] =
//                                           '<tr>
//                                              <td width="30"><input name="chb[x_#ID______]" value="" type="checkbox"></td>
//                                              <td>_#NAME____</td>
//                                              <td>_#ID______</td>
//                                            </tr>';

$manager = new AA_Manager('form'.$module_id, $manager_settings);
$manager->performActions();

$aa_set = $manager->getSet();
$aa_set->setModules($module_id);
//$aa_set->addCondition(new AA_Condition('aa_user',       '==', AuthGetUid()));

$zids  = AA_Object::querySet('AA_Form', $aa_set);

$manager->displayPage($zids, 'sliceadmin', 'forms');
page_close();

