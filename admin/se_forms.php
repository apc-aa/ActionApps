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

use AA\Manager;

// const AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";

if (!IfSlPerm(PS_FORMS)) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php3", _m("You have not permissions to manage forms"), "admin");
    exit;
}

//$actions   = new AA\Manageractions;
//$actions->addAction(new  AA\Manageraction\Taskmanager_Execute('ExecuteTaskAction'));
//$actions->addAction(new  AA\Manageraction\Taskmanager_Delete('DeleteTaskAction'));

//$switches  = new AA\Manageractions;  // we do not need switches here

$apage = new AA_Adminpageutil('sliceadmin', 'forms');
// $apage->setTitle( _m("Admin - Object Edit") );

$apage->setTitle(_m('Manage %1', [AA_Form::name()]));
$apage->printWithManager(new Manager('form' . AA::$module_id, AA_Form::getManagerConf()));
