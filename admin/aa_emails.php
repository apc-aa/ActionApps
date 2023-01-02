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

const AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/varset.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/item.php";
require_once __DIR__ . "/../include/actions.php";

if ( !IsSuperadmin() ) {
    MsgPage(_m("You do not have permission to manage Emails"));
    exit;
}

$managed = AA::Metabase()->getManagerConf('email');
$managed->setColumns2show(['id','type','subject','description']);
$managed->setWidgetSetting('html', ['type'=>'bool']);
$managed->setWidgetSetting('type', ['enum'=> AA_Collection::get_email_types()]);
$managed->setWidgetSetting('lang', ['enum'=>AA_Langs::getNames()]);
$managed->setWidgetSetting('owner_module_id', ['enum'=>AA_Module::getUserModules()]);

$apage = new AA_Adminpageutil('aaadmin', 'email');
$apage->setTitle(_m("Manage Emails"));
$apage->printWithManager((new Manager('email', $managed))->setAllModules());
