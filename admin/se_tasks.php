<?php
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
    MsgPageMenu(StateUrl(self_base())."index.php3", _m("You have not permissions to manage tasks"), "admin");
    exit;
}

$module_id = $slice_id;

$manager_settings = AA_Plannedtask::getManagerConf(get_admin_url('se_tasks.php'));
$manager_settings['messages']['title'] = _m('Planned tasks');
$manager_settings['messages']['about'] = _m('You can plan the a task for specified time, or as the reaction to some event. The task will be probably sending e-mails using {mail...} expression, or recounting some fields after update by {recount...}');

$manager = new AA_Manager('task'.$module_id, $manager_settings);
$manager->performActions();

$aa_set = $manager->getSet();
//$aa_set->setModules($module_id);
//$aa_set->addCondition(new AA_Condition('aa_user',       '==', AuthGetUid()));

$zids  = AA_Object::querySet('AA_Plannedtask', $aa_set);

$manager->displayPage($zids, 'sliceadmin', 'tasks');
page_close();

