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
// set template id (changes language file => must be here):

// messages for init_page:
use AA\Util\InputVars;

die('We want to remove this file from the codebase. HM 2023-02-22. Please comment out line '.__LINE__.' in '.basename(__FILE__));

$no_slice_id          = true;
$require_default_lang = true;

// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
// the parts used by the slice wizard are in the included file
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/sliceadd.php";
require_once __DIR__ . "/../include/slicedit.php";

try {
    $add = InputVars::boolPOST('add');   // ??? from where it could come?
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception $e) {}

if ($cancel) {
    go_url( StateUrl(self_base() . "index.php3"));
}

$wizard = 1;

$set_template_id = $_POST['template_from_slice'] ? $_POST['template_id2'] : $_POST['template_id'];

$err = [];          // error array (Init - just for initializing variable

if ($add) {
    $err = SliceEditProcess($slice_id, $user_firstname, $user_surname, $err, $add_submit, $user_login, $user_password1, $user_password2, $user_mail1, $user_mail2, $user_mail3, $user_super, $um_uedit_no_go_url, $selected_user, $posted_groups, $sel_groups, $perm_mod, $new_module, $new_module_role, $add, $update, $owner, $new_owner, $new_owner_email, $name, $slice_url, $slice_type, $upload_url, $priority, $permit_anonymous_post, $permit_anonymous_edit, $permit_offline_fill, $lang_file, $d_listlen, $mlxctrl, $fileman_access, $fileman_dir, $template, $deleted, $flag_allow_expired, $auth_field_group, $mailman_field_lists, $reading_password, $flag_allow_pending, $set_template_id, $wiz, $user_role);
}



$apage = new AA_Adminpageutil();
$apage->setTitle(_m("Add Slice Wizard"));
$apage->setForm(['action'=>'slicedit.php']);
$apage->printHead($err, $Msg);

$display_views_constants = true;
SliceAddForm(true, true);

FrmTabCaption(_m("[Optional] Create New User"));

// User data ---------------------------------------------------
FrmInputRadio("user_role", _m("Level of Access"),
    ["EDITOR"=>_m("Editor"), "ADMINISTRATOR"=>_m("Slice Administrator")], "EDITOR");
FrmInputText("user_login", _m("Login name"), "", 50, 50, true);
FrmInputPwd("user_password1", _m("Password"), "");
FrmInputPwd("user_password2", _m("Retype password"), "");
FrmInputText("user_firstname", _m("First name"), "", 50, 50, true);
FrmInputText("user_surname", _m("Surname"), "", 50, 50, true);
FrmInputEmail("user_mail1", _m("E-mail")." 1", "", 50, 50);
echo '<input type="hidden" name="add_submit" value="1">
<input type="hidden" name="um_uedit_no_go_url" value="1">';

$email_welcomes = GetUserEmails("slice wizard welcome");
$email_welcomes[NOT_EMAIL_WELCOME] = _m("Do Not Email Welcome");

FrmInputSelect("wiz[welcome]", _m("Email Welcome"), $email_welcomes, NOT_EMAIL_WELCOME);

FrmTabEnd();

echo '<br><br>';

FrmTabCaption();
FrmTabEnd([
    "no_slice"=> [
        "value"=>_m("Go: Add Slice"),
                                    "type"=>"submit",
                                    "accesskey"=>"S"
    ],
                  "cancel"=> ["url"=>"um_uedit.php"]
]);

$apage->printFoot();
