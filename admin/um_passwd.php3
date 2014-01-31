<?php
/**  um_passwd.php3 - change password for user
 *    expected $slice_id for edit slice
 *    optionaly $Msg to show under <h1>Hedline</h1> (typicaly: update successful)
 *
 *
 * PHP versions 4 and 5
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
 * @link      http://www.apc.org/ APC
 *
*/

require_once "../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."msgpage.php3";
require_once AA_INC_PATH."um_util.php3";

if ($cancel) {
    go_url( $sess->url(self_base() . "index.php3"));
}

if (!IfSlPerm(PS_EDIT_SELF_USER_DATA) OR IsUserReader($auth->auth["uid"])) {
    MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change user data"), "admin");
    exit;
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset      = new Cvarset();
$p_slice_id  = q_pack_id($slice_id);

$user_data = GetIDsInfo($auth->auth["uid"]);
if ( $update ) {
    // Procces user data -------------------------------------------------------
    ValidateInput("user_password_old", _m("Current password"), $user_password_old, $err, true, "password");
    if ( !AA::$perm->authenticateUsername($auth->auth["uname"], $user_password_old) ) {
        $err['Password'] = MsgErr(_m("Error in current password - pasword is not changed"));
    }
    $userrecord = FillUserRecord($err, 'nOnEwlOgiN', $user_surname, $user_firstname, $user_password1, $user_password2,  $user_mail1, $user_mail2, $user_mail3);

    if ( count($err) <= 1) {
        ChangeUserData($err, $auth->auth["uid"], $userrecord, 'AA_NO_CHANGE', $perms_roles);
    }

    if ( count($err) <= 1 ) {
        $Msg = MsgOK(_m("User data modified"));
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

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<title><?php echo _m("Change user data");?></title>
</head>

<?php
require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "userinfo");

echo "<h1><b>" . _m("Change user data") . "</b></h1>&nbsp;&nbsp;";
PrintArray($err);
echo $Msg;

$form_buttons = array ("update",
                       "cancel" => array("url"=>"index.php3"));

?>
<form name="f" method="post" action="<?php echo $sess->url($_SERVER['PHP_SELF']) ?>">
<?php

FrmTabCaption(_m("Edit User"));
FrmStaticText( _m("Login name"), $user_data['login']);
FrmStaticText( _m("User Id"),    $user_data['id']);
FrmInputPwd("user_password_old", _m("Current password"), $user_password_old, 50, 50, true);
FrmInputPwd("user_password1", _m("Password"),       $user_password1, 50, 50, true);
FrmInputPwd("user_password2", _m("Retype password"),$user_password2, 50, 50, true);
FrmInputText("user_firstname",_m("First name"),     $user_firstname, 50, 50, true);
FrmInputText("user_surname",  _m("Surname"),        $user_surname, 50, 50, true);
FrmInputText("user_mail1",    _m("E-mail")." 1",    $user_mail1, 50, 50, true);
//  FrmInputText("user_mail2", _m("E-mail")." 2",    $user_mail2, 50, 50, false);  // removed for compatibility with perm_sql.php3
//  FrmInputText("user_mail3", _m("E-mail")." 3",    $user_mail3, 50, 50, false);
//  FrmInputChBox("user_super", _m("Superadmin account"), $user_super, false, "", 1, false); // can't be changed by user itself

FrmTabEnd($form_buttons, $sess, $slice_id);
?>
</form>
<?php
HtmlPageEnd();
page_close()
?>
