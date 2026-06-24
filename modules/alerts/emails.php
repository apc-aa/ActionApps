<?php
/**
 * Alerts admin: Email templates.
 *
 * Permission-scoped manager for the `email` table, restricted to the current
 * Alerts module (owner_module_id == AA::$module_id). Replaces the legacy
 * tabledit `email` / `email_edit` table views (modedit.php?set_tview=email).
 * Unlike the superadmin-only admin/aa_emails.php, this page is reachable by an
 * Alerts module admin and shows only the emails belonging to that module.
 *
 * @package Alerts
 */
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

use AA\IO\DB\DB_AA;
use AA\Manager;
use AA\Util\InputVars;

// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../../include/init_page.php";
require_once __DIR__ . "/../../include/varset.php";
require_once __DIR__ . "/../../include/formutil.php";
require_once __DIR__ . "/../../include/item.php";
require_once __DIR__ . "/../../include/actions.php";
require_once __DIR__ . "/../../include/mail.php";

if (!IfSlPerm(PS_USERS)) {
    MsgPage(_m("You do not have permission to manage Emails"));
    exit;
}

try {
    $id = InputVars::stringGET('id');   // set when editing a single email (Manager reads it too)
} catch (Exception $e) { AA_Adminpageutil::pageParameterError($e); }

$managed = GetMailManaged();   // owner_module_id = the user's modules (as in admin/aa_emails.php)
// Owner column - resolve owner_module_id to the owning module/slice name.
// {modulefield:} resolves other modules fine, but returns empty for the *current*
// module (cached as a type-specific object whose name lookup is broken - see the
// "this will not work" note on AA_Module_Alerts::SETTING_TABLE), so special-case it.
$cur_name  = (string) DB_AA::select1('name', 'SELECT `name` FROM `module`', [['id', AA::$module_id, 'l']]);
$owner_fmt = '{switch({owner_module_id})' . AA::$module_id . ':' . safe($cur_name) . '::{modulefield:{owner_module_id}:name}}';
$managed->addColumn('owner_module_id', _m('Owner'), $owner_fmt);
$managed->setColumns2show(['id', 'type', 'subject', 'description', 'owner_module_id']);

$apage = new AA_Adminpageutil('admin', 'email');
$apage->setTitle(_m("Email templates"));
if ($id) {
    $apage->addHelpbox('', AA_Collection::getEmailAliasesHelpbox());
}

$manager = new Manager('alerts_email', $managed);
$manager->setAllModules();   // list across all the user's slices, not only the current module
// Scope to the user's accessible slices, matching GetUserEmails() (which feeds the
// welcome/alert selects on modedit/send). Superadmin (GetUserSlices()==='all') sees all.
$myslices = GetUserSlices();
if (is_array($myslices)) {
    $slice_ids = array_map('pack_id', array_keys($myslices));
    $manager->setRestrictionConds([
        new AA_Condition('owner_module_id', '==', $slice_ids ?: ['__none__'])
    ]);
}

$apage->printWithManager($manager);
