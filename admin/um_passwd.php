<?php
/**  um_passwd.php - change password for user
 *    expected $slice_id for edit slice
 *    optionally $Msg to show under <h1>Headline</h1> (typically: update successful)
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

// const  AA_REGISTER_GLOBALS = true;
use AA\Util\InputVars;

require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/varset.php";

require_once __DIR__ . "/../include/um_util.php";

try {
    $user_firstname = InputVars::stringSlashedPOST('user_firstname'); // alpha
    $user_mail1 = InputVars::stringSlashedPOST('user_mail1');
    $user_password1 = InputVars::stringSlashedPOST('user_password1'); // alpha
    $user_password2 = InputVars::stringSlashedPOST('user_password2'); // alpha
    $user_password_old = InputVars::stringSlashedPOST('user_password_old'); // alpha
    $user_surname = InputVars::stringSlashedPOST('user_surname'); // alpha
    $update = InputVars::boolPOST('update');
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception) {}


if ($cancel) {
    go_url( StateUrl(self_base() . "index.php"));
}

if (!IfSlPerm(PS_EDIT_SELF_USER_DATA) OR !AA::$perm->isUserEditable(AuthGetUid())) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You have not permissions to change user data"), "admin");
    exit;
}

$err = [];          // error array (Init - just for initializing variable
$varset      = new Cvarset();

$user_data = AA::$perm->getIDsInfo(AuthGetUid());
if ( $update ) {
    // Procces user data -------------------------------------------------------
    ValidateInput("user_password_old", _m("Current password"), $user_password_old, $err, true, "password");
    if ( !AA::$perm->authenticateUsername(AuthGetUname(), $user_password_old, '') ) {
        $err['Password'] = MsgErr(_m("Error in current password - pasword is not changed"));
    }
    $userrecord = FillUserRecord($err, 'nOnEwlOgiN', $user_surname, $user_firstname, $user_password1, $user_password2,  $user_mail1);

    if ( !count($err)) {
        ChangeUserData($err, AuthGetUid(), $userrecord, 'AA_NO_CHANGE');
    }

    if ( !count($err) ) {
        $Msg = MsgOk(_m("User data modified"));
    }
} else {
    // !update - get data
     if (is_array($user_data)) {
        $user_login     = $user_data['login'];
        $user_firstname = $user_data['givenname'];
        $user_surname   = $user_data['sn'];
        $user_password1 = "nOnEwpAsswD";    // unchanged password
        $user_password2 = "nOnEwpAsswD";    // unchanged password
        if ( is_array($user_data['mails'])) {
            $user_mail1 = $user_data['mails'][0];
            $user_mail2 = $user_data['mails'][1];
            $user_mail3 = $user_data['mails'][2];
        }
    }
}

$apage = new AA_Adminpageutil('userinfo');
$apage->setTitle(_m("Change user data"));
$apage->printHead($err, $Msg);


$form_buttons = [
    "update",
                       "cancel" => ["url"=>"index.php"]
];

FrmTabCaption(_m("Edit User"));
FrmStaticText(_m("Login name"), $user_data['login']);
FrmStaticText(_m("User Id"), $user_data['id']);
FrmInputPwd("user_password_old", _m("Current password"), $user_password_old);
FrmInputPwd("user_password1", _m("Password"), $user_password1);
FrmInputPwd("user_password2", _m("Retype password"), $user_password2);
FrmInputText("user_firstname", _m("First name"), $user_firstname, 50, 50, true);
FrmInputText("user_surname", _m("Surname"), $user_surname, 50, 50, true);
FrmInputEmail("user_mail1",    _m("E-mail")." 1",    $user_mail1, 50, 50, true);
//  FrmInputEmail("user_mail2", _m("E-mail")." 2",    $user_mail2, 50, 50, false);  // removed for compatibility with perm_sql.php
//  FrmInputEmail("user_mail3", _m("E-mail")." 3",    $user_mail3, 50, 50, false);
//  FrmInputChBox("user_super", _m("Superadmin account"), $user_super, false, "", 1, false); // can't be changed by user itself

FrmTabEnd($form_buttons);

$apage->printFoot();
