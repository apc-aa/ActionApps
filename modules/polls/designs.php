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

use AA\Manager;
use AA\Util\Langs;

// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../../include/init_page.php";
require_once __DIR__ . "/../../include/varset.php";
require_once __DIR__ . "/../../include/formutil.php";
require_once __DIR__ . "/../../include/item.php";
require_once __DIR__ . "/../../include/actions.php";

if (!IfSlPerm(PS_MODP_EDIT_DESIGN)) {
    MsgPage(_m("You do not have permission to manage Polls"));
    exit;
}

$managed = AA::Metabase()->getManagerConf('polls_design');
$managed->setColumns2show(['name','comment','id']);

$managed->setModule(AA::$module_id);
// $managed->setWidgetSetting('slice_id', ['enum'=>AA_Module::getUserModules(), 'name'=>_m('Owner')]);
$managed->setWidgetSetting('module_id', ['type'=>'hidden', 'name'=>_m('Owner')]);
$managed->setWidgetSetting('id',                          ['name'=>_m('ID')]);
$managed->setWidgetSetting('comment',                     ['name'=>_m('Comment')]);
$managed->setWidgetSetting('name',                        ['name'=>_m('Name')]);

$managed->setWidgetSetting('top',                         ['name'=>_m('Top HTML')]);
$managed->setWidgetSetting('answer',                      ['name'=>_m('Answer HTML')]);
$managed->setWidgetSetting('bottom',                      ['name'=>_m('Bottom HTML')]);

$apage = new AA_Adminpageutil('modadmin', 'design');
$apage->setTitle(_m("Polls Design"));

$manager = new Manager('polls_design', $managed);

$manager->setRestrictionConds([
    new AA_Condition('module_id', '==', pack_id(AA::$module_id))
]);

$apage->printWithManager($manager);
