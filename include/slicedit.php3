<?php
/**
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
*/

use AA\IO\DB\DB_AA;

require_once __DIR__."/se_users.php3";
require_once __DIR__."/mail.php3";

// add mlx functions
require_once __DIR__."/mlx.php";
require_once __DIR__."/um_util.php3";

$SLICE_FIELDS_TEXT = [
    "id", "name", "owner", "created_by", "created_at",
   "type", "fulltext_format_top", "fulltext_format", "fulltext_format_bottom",
   "odd_row_format", "even_row_format", "compact_top", "compact_bottom",
   "category_top", "category_format", "category_bottom", "slice_url",
   "lang_file", "fulltext_remove", "compact_remove", "notify_sh_offer",
   "notify_sh_accept", "notify_sh_remove", "notify_holding_item_s",
   "notify_holding_item_b", "notify_holding_item_edit_s",
   "notify_holding_item_edit_b", "notify_active_item_edit_s",
   "notify_active_item_edit_b", "notify_active_item_s", "notify_active_item_b",
   "noitem_msg",
   "admin_format_top", "admin_format", "admin_format_bottom", "admin_remove",
   "fileman_dir","fileman_access","javascript","aditional",
   "mailman_field_lists", "auth_field_group", "reading_password"
];

$SLICE_FIELDS_NUM  = [
    "deleted", "export_to_all", "template",
   "even_odd_differ", "category_sort", "d_listlen",
   "email_sub_enable", "exclude_from_dir","permit_anonymous_post",
   "permit_anonymous_edit", "permit_offline_fill",
];

/** add_user_and_welcome function
 *  Assigns user privileges and sends a welcome email, if the email address is filled.
 *     Returns error description or empty string.
 * @param $welcome_id
 * @param $user_login
 * @param $slice_id
 * @param $role
 * @return if|mixed|string
 */
function add_user_and_welcome($welcome_id, $user_login, $slice_id, $role) {
    global $auth;

    // 1. Assign user privileges
    $userinfo = AA::$perm->findUserByLogin($user_login);
    if (!is_array($userinfo) || count ($userinfo) != 1) {
        return _m("User not found");
    }

    reset($userinfo);
    $GLOBALS["UsrAdd"] = key ($userinfo);
    $GLOBALS["role"]   = $role;
    ChangeRole(); // in include/se_users.php3

    // 2. Send a welcome email message
    $user = current($userinfo);
    if (!$user["mail"]) {
        return "";
    }

    if ( ($slice_name = DB_AA::select1('name', 'SELECT name FROM `slice`', [['id', $slice_id, 'l']])) === false) {
        return _m("Slice not found.");
    }
    $me = AA::$perm->getIDsInfo($auth->auth["uid"]);

    $aliases               = [];
    $aliases["_#SLICNAME"] = GetAliasDef( "f_t:$slice_name",      "id..............");
    $aliases["_#LOGIN___"] = GetAliasDef( "f_t:$user_login",      "id..............");
    $aliases["_#NAME____"] = GetAliasDef( "f_t:". $user["name"],  "id..............");
    $aliases["_#ROLE____"] = GetAliasDef( "f_t:$role",            "id..............");
    $aliases["_#ME_MAIL_"] = GetAliasDef( "f_t:". $me["mail"],    "id..............");
    $aliases["_#ME_NAME_"] = GetAliasDef( "f_t:". $me["cn"],      "id..............");

    $item = new AA_Item('', $aliases);
    if (AA_Mail::sendTemplate($welcome_id, [$me["mail"], $user["mail"]], $item) != 2) {
        return _m("Error mailing");
    }
}

if ($slice_id) {  // edit slice
    if (!IfSlPerm(PS_EDIT)) {
        MsgPage(StateUrl(self_base())."index.php3", _m("You have not permissions to edit this slice"));
        exit;
    }
} else {          // add slice
    if (!CheckPerms( $auth->auth["uid"], "aa", AA_ID, PS_ADD)) {
        MsgPage(StateUrl(self_base())."index.php3", _m("You have not permissions to add slice"));
        exit;
    }
}

$varset     = new CVarset();
$superadmin = IsSuperadmin();

// Add new editor / administrator from Wizard page
if ($user_firstname || $user_surname) {

    // following code (in do {}) is used also in um_uedit file
    do  {
        // Procces user data -------------------------------------------------------
        $userrecord = FillUserRecord($err, ($add_submit ? $user_login : 'nOnEwlOgiN'), $user_surname, $user_firstname, $user_password1, $user_password2,  $user_mail1, $user_mail2, $user_mail3);

        if ( count($err)) {
            break;
        }

        if ( $add_submit ) {      // -------------------- new user ------------------
            NewUserData($err, $user_login, $userrecord, $user_super, $perms_roles, $um_uedit_no_go_url);
        } else {                 // ----------------- update user ------------------
            ChangeUserData($err, $selected_user, $userrecord, $user_super, $perms_roles);
        }

        // Procces group data ------------------------------------------------------
        ChangeUserGroups($posted_groups, $sel_groups, $selected_user);

        // Procces module permissions ----------------------------------------------

        // Change module permissions if user wants
        ChangeUserModulePerms( $perm_mod, $selected_user, $perms_roles );

        // Add new modules for this user
        AddUserModulePerms( $new_module, $new_module_role, $selected_user, $perms_roles);

    } while (false);
}


// additional settings
AA_Slice::processModuleObject($slice_id);

if ( $add || $update ) {
    do {
        if ( !$owner ) {  // insert new owner
            ValidateInput("new_owner", _m("New Owner"), $new_owner, $err, true, "text");
            ValidateInput("new_owner_email", _m("New Owner's E-mail"), $new_owner_email, $err, true, "email");

            if ( count($err)) {
                break;
            }

            $owner = new_id();
            $varset->set("id", $owner, "unpacked");
            $varset->set("name", $new_owner, "text");
            $varset->set("email", $new_owner_email, "text");

            // create new owner
            if ( !$varset->doINSERT('slice_owner')) {
                $err["DB"] .= MsgErr("Can't add slice");
                break;
            }

            $varset->clear();
        }
        ValidateInput("name", _m("Title"), $name, $err, true, "text");
        ValidateInput("owner", _m("Owner"), $owner, $err, false, "id");
        ValidateInput("slice_url", _m("URL of .shtml page (often leave blank)"), $slice_url, $err, false, "url");
        ValidateInput("upload_url", _m("Upload URL"), $upload_url, $err, false, "url");
        ValidateInput("priority", _m("Priority (order in slice-menu)"), $priority, $err, false, "number");
        ValidateInput("permit_anonymous_post", _m("Allow anonymous posting of items"), $permit_anonymous_post, $err, false, "number");
        ValidateInput("permit_anonymous_edit", _m("Allow anonymous editing of items"), $permit_anonymous_edit, $err, false, "number");
        ValidateInput("permit_offline_fill", _m("Allow off-line item filling"), $permit_offline_fill, $err, false, "number");
        ValidateInput("lang_file", _m("Used Language File"), $lang_file, $err, true, "text");

        ValidateInput("d_listlen", _m("Listing length"), $d_listlen, $err, false, "number");
        //mimo change
        ValidateInput('mlxctrl', _m("Language Control Slice"), $mlxctrl, $err, false, "id");
        //
        ValidateInput("fileman_access", _m("File Manager Access"), $fileman_access, $err, false, "text");
        ValidateInput("fileman_dir", _m("File Manager Directory"), $fileman_dir, $err, false, "filename");

        if ($fileman_dir AND DB_AA::test('slice', [['fileman_dir',$fileman_dir], ['id', $slice_id, 'l<>']])) {
            $err[] = _m("This File Manager Directory is already used by another slice.");
        }

        if ( count($err)) {
            break;
        }
        $template           = ( $template            ? 1 : 0 );
        $deleted            = ( $deleted             ? 1 : 0 );
        $flag_allow_expired = ( $flag_allow_expired  ? 1 : 0 );

        if ( $update ) {
            $varset->clear();
            $varset->addkey("id", "unpacked", $slice_id);
            $varset->add("name", "quoted", $name);
            $varset->add("owner", "unpacked", $owner);
            //echo $owner; exit;  // problem was in VARCHAR of owner field in database. It must be BINARY
            $varset->add("slice_url", "quoted", $slice_url);
            $varset->add("priority", "number", $priority);
            if ( $superadmin ) {
                $varset->add("deleted", "number", $deleted);
            }
            $varset->add("lang_file", "quoted", $lang_file);

            if (!$varset->doUpdate('module', null, $slice_id)) {
                // not necessary - we have set the halt_on_error
                $err["DB"] = MsgErr("Can't change slice");
                break;
            }

            $varset->remove('priority');  // is not in slice table (which is OK)

            $varset->add("d_listlen", "number", $d_listlen);
            if ( $superadmin ) {
                $varset->add("template", "number", $template);
            }
            $varset->add("permit_anonymous_post", "number", $permit_anonymous_post);
            $varset->add("permit_anonymous_edit", "number", $permit_anonymous_edit);
            $varset->add("permit_offline_fill", "number", $permit_offline_fill);
            $varset->add("fileman_access", "text", $fileman_access);
            $varset->add("fileman_dir", "text", $fileman_dir);
            $varset->add("auth_field_group", "text", $auth_field_group);
            $varset->add("mailman_field_lists", "text", $mailman_field_lists);
            $varset->add("reading_password", "text", $reading_password);

            $slice_flag  = DB_AA::select1('flag', 'SELECT `flag` FROM `slice`', [['id', $slice_id, 'l']]);
            $slice_flag &= ~(SLICE_ALLOW_EXPIRED_CONTENT | SLICE_ALLOW_PENDING_CONTENT); // clear the bits
            $slice_flag |= $flag_allow_expired ? SLICE_ALLOW_EXPIRED_CONTENT : 0;
            $slice_flag |= $flag_allow_pending ? SLICE_ALLOW_PENDING_CONTENT : 0;
            $varset->add("flag", "number", $slice_flag);

            $varset->add('mlxctrl', "unpacked", $mlxctrl); //store 16bytes packed


            if (!$varset->doUpdate('slice', null, $slice_id)) {  // not necessary - we have set the halt_on_error
                $err["DB"] = MsgErr("Can't change slice");
                break;
            }

            $r_slice_view_url = ($slice_url=="" ? StateUrl("../slice.php3"). "&slice_id=$slice_id&encap=false"
                                                : stripslashes($slice_url));
        } else { // insert (add)

            $slice_id = new_id();
            $varset->set("id", $slice_id, "unpacked");
            $varset->set("created_by", $auth->auth["uid"], "text");
            $varset->set("created_at", now(), "text");
            $varset->set("name", $name, "quoted");
            $varset->set("owner", $owner, "unpacked");
            $varset->set("slice_url", $slice_url, "quoted");
            $varset->set("priority", $priority, "number");
            $varset->set("deleted", $deleted, "number");
            $varset->set("lang_file", $lang_file, "quoted");
            $varset->set("type","S","quoted");

            if ( !$varset->doINSERT('module') ) {
                $err["DB"] .= MsgErr("Can't add slice");
                break;
            }

            AA::$module_id = $slice_id;

            $varset->clear();

            // get template data
            $varset->addArray( $SLICE_FIELDS_TEXT, $SLICE_FIELDS_NUM );
            if (! ($record = DB_AA::select1('', 'SELECT * FROM slice', [['id', $set_template_id, 'l']]))) {
                $err["DB"] = MsgErr("Bad template id");
                break;
            }
            $varset->setFromArray($record);
            $varset->set("id", $slice_id, "unpacked");
            $varset->set("created_by", $auth->auth["uid"], "text");
            $varset->set("created_at", now(), "text");
            $varset->set("name", $name, "quoted");
            $varset->set("owner", $owner, "unpacked");
            $varset->set("slice_url", $slice_url, "quoted");
            $varset->set("deleted", $deleted, "number");
            $varset->set("lang_file", $lang_file, "quoted");
            $varset->set("d_listlen", $d_listlen, "number");
            $varset->set("template", $template, "number");
            $varset->set("permit_anonymous_post", $permit_anonymous_post, "number");
            $varset->set("permit_anonymous_edit", $permit_anonymous_edit, "number");
            $varset->set("permit_offline_fill", $permit_offline_fill, "number");
            $varset->set("fileman_access", $fileman_access, "text");
            $varset->set("fileman_dir", $fileman_dir, "quoted");
            $varset->add("auth_field_group", "text", $auth_field_group);
            $varset->add("mailman_field_lists", "text", $mailman_field_lists);
            $varset->add("reading_password", "text", $reading_password);

            $slice_flag = $flag_allow_expired ? SLICE_ALLOW_EXPIRED_CONTENT : 0;
            $varset->add("flag", "number", $slice_flag);

            //mimo
            $varset->add('mlxctrl', "quoted", $mlxctrl);

            // create new slice
            if ( !$varset->doINSERT('slice') ) {
                $err["DB"] .= MsgErr("Can't add slice");
                break;
            }

            // copy fields
            $fields = DB_AA::select([], 'SELECT * FROM field', [['slice_id', $set_template_id, 'l']]);
            foreach ($fields as $field) {
                $varset->clear();
                $varset->addArray( AA_Fields::FIELDS_TEXT, AA_Fields::FIELDS_NUM );
                $varset->setFromArray($field);
                $varset->set("slice_id", $slice_id, "unpacked" );
                if ( !$varset->doINSERT('field')) {
                    $err["DB"] .= MsgErr("Can't copy fields");
                    break;
                }
            }

            AddPermObject($slice_id, "slice");    // no special permission added - only superuser can access

            /* Added by Jakub on June 2002 to support Add slice Wizard */
            // Copy constants
            if ($wiz["constants"] == "copy") {
                if (!CopyConstants($slice_id)) {
                    $err[] = _m("Error when copying constants.");
                }
            }
            // Copy views
            if ($wiz["copyviews"] && $slice_id && $set_template_id) {
                if (!CopyTableRows( "view",
                                    "slice_id='".q_pack_id($set_template_id)."'",
                                    ["slice_id"=>q_pack_id($slice_id)],
                                    ["id"])) {
                    $err[] = _m("Error when copying views.");
                }
            }

            // Add new editor / administrator privileges from Wizard page
            if ($user_login) {
                $myerr = add_user_and_welcome($wiz["welcome"], $user_login, $slice_id, $user_role);
                if ($myerr != "") $err[] = _m("Internal error when changing user role.")." ($myerr)";
            }
            /* End of Wizard stuff */

            // create new upload directory
            Files::destinationDir(AA_Slice::getModule($slice_id));
        }
//        $slice->setSliceField('_upload_url.....', $upload_url);

        AA::Pagecache()->invalidateFor($slice_id);  // invalidate old cached values for this slice
    } while(false);

    if ( !count($err) ) {
        go_return_or_url(StateUrl(self_base() . "slicedit.php3"),0,0);
    }
}
