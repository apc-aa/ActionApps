<?php

/**
 * Alerts admin: Send emails.
 *
 * Manual sending of alert emails (example to an address, example to a reader,
 * and fire-to-all-subscribers), plus the "last sent" overview. All of this is
 * rendered by ShowCollectionAddOns() (modules/alerts/send_emails.php), which is
 * plain Frm* form output and uses no tabledit. Replaces the legacy tabledit
 * `send_emails` table view (modedit.php?set_tview=send_emails). The alert email
 * template is chosen on the Settings page (modedit.php).
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
use AA\Util\InputVars;

// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../../include/init_page.php";
require_once __DIR__ . "/../../include/formutil.php";
require_once __DIR__ . "/../../include/varset.php";
require_once __DIR__ . "/util.php";
require_once __DIR__ . "/send_emails.php";

if (!IfSlPerm(PS_USERS)) {
    MsgPage(_m("You do not have permissions to access this page"));
    exit;
}

try {
    // top-level vars are global - ShowCollectionAddOns() reads them via `global`
    $example       = InputVars::arrayPOST('example');
    $fire          = InputVars::arrayPOST('fire');
    $emailid_alert = InputVars::intPOST('emailid_alert');
    $set_alert     = InputVars::boolPOST('set_alert');
    $Msg           = InputVars::stringGETPOST('Msg');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

set_collectionid();   // sets global $collectionid for the current module
$collectionid = $GLOBALS['collectionid'];

// choose which alert email template is sent for this collection (saved on alerts_collection)
if ($set_alert) {
    DB_AA::update('alerts_collection', [['emailid_alert', $emailid_alert, 'i']], [['id', $collectionid, 's']]);
}
$current_alert = (int) DB_AA::select1('emailid_alert', "SELECT emailid_alert FROM alerts_collection", [['id', $collectionid, 's']]);

$apage = new AA_Adminpageutil('admin', 'send_emails');
$apage->setTitle(_m("Send Emails"));
$apage->setForm();
$apage->printHead($err, $Msg);

// which alert email to send - all accessible templates + the current one
echo '<form method="post">';
FrmTabCaption(_m("Alert email to send"), [], true);
FrmInputSelect("emailid_alert", _m("alert email"), alertsEmailOptions("alerts alert", $current_alert), $current_alert);
FrmTabEnd(['set_alert' => ['type' => 'submit', 'value' => _m("Set")]]);
echo '</form>';

ShowCollectionAddOns();

$apage->printFoot();
