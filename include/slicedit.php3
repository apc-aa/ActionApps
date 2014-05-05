<?php
/**
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
*/

require_once AA_INC_PATH."se_users.php3";
require_once AA_INC_PATH."mail.php3";

// add mlx functions
require_once AA_INC_PATH."mlx.php";
require_once AA_INC_PATH."um_util.php3";

/** add_user_and_welcome function
 *  Assigns user privileges and sends a welcome email, if the email address is filled.
 *     Returns error description or empty string.
 * @param $welcome_id
 * @param $user_login
 * @param $slice_id
 * @param $role
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

    $db = new DB_AA;
    $db->query("SELECT name FROM slice WHERE id = '".q_pack_id($slice_id)."'");
    if (!$db->next_record()) {
        return _m("Slice not found.");
    }
    $slice_name = $db->f("name");
    $me         = AA::$perm->getIDsInfo($auth->auth["uid"]);

    $aliases               = array();
    $aliases["_#SLICNAME"] = GetAliasDef( "f_t:$slice_name",      "id..............");
    $aliases["_#LOGIN___"] = GetAliasDef( "f_t:$user_login",      "id..............");
    $aliases["_#NAME____"] = GetAliasDef( "f_t:". $user["name"],  "id..............");
    $aliases["_#ROLE____"] = GetAliasDef( "f_t:$role",            "id..............");
    $aliases["_#ME_MAIL_"] = GetAliasDef( "f_t:". $me["mail"],    "id..............");
    $aliases["_#ME_NAME_"] = GetAliasDef( "f_t:". $me["cn"],      "id..............");

    $item = new AA_Item('', $aliases);
    if (AA_Mail::sendTemplate($welcome_id, array ($me["mail"], $user["mail"]), $item) != 2) {
        return _m("Error mailing");
    }
}

if ($slice_id) {  // edit slice
    if (!IfSlPerm(PS_EDIT)) {
        MsgPage($sess->url(self_base())."index.php3", _m("You have not permissions to edit this slice"), "standalone");
        exit;
    }
} else {          // add slice
    if (!CheckPerms( $auth->auth["uid"], "aa", AA_ID, PS_ADD)) {
        MsgPage($sess->url(self_base())."index.php3", _m("You have not permissions to add slice"), "standalone");
        exit;
    }
}

$db         = new DB_AA;
$varset     = new CVarset();
$superadmin = IsSuperadmin();

// Add new editor / administrator from Wizard page
if ($user_firstname || $user_surname) {

    // following code (in do {}) is used also in um_uedit file
    do  {
        // Procces user data -------------------------------------------------------
        $userrecord = FillUserRecord($err, ($add_submit ? $user_login : 'nOnEwlOgiN'), $user_surname, $user_firstname, $user_password1, $user_password2,  $user_mail1, $user_mail2, $user_mail3);

        if ( count($err) > 1) {
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

            if ( count($err) > 1) {
                break;
            }

            $owner = new_id();
            $varset->set("id", $owner, "unpacked");
            $varset->set("name", $new_owner, "text");
            $varset->set("email", $new_owner_email, "text");

            // create new owner
            if ( !$db->query("INSERT INTO slice_owner " . $varset->makeINSERT() )) {
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
        ValidateInput("d_listlen", _m("Listing length"), $d_listlen, $err, true, "number");
        ValidateInput("permit_anonymous_post", _m("Allow anonymous posting of items"), $permit_anonymous_post, $err, false, "number");
        ValidateInput("permit_anonymous_edit", _m("Allow anonymous editing of items"), $permit_anonymous_edit, $err, false, "number");
        ValidateInput("permit_offline_fill", _m("Allow off-line item filling"), $permit_offline_fill, $err, false, "number");
        ValidateInput("lang_file", _m("Used Language File"), $lang_file, $err, true, "text");
        //mimo change
        ValidateInput(MLX_SLICEDB_COLUMN, _m("Language Control Slice"), $mlxctrl, $err, false, "id");
        //
        ValidateInput("fileman_access", _m("File Manager Access"), $fileman_access, $err, false, "text");
        ValidateInput("fileman_dir", _m("File Manager Directory"), $fileman_dir, $err, false, "filename");

        if ($fileman_dir) {
            $db->query("SELECT id FROM slice WHERE fileman_dir='$fileman_dir' AND id <> '".q_pack_id($slice_id)."'");
            if ($db->num_rows()) $err[] = _m("This File Manager Directory is already used by another slice.");
        }

        if ( count($err) > 1) {
            break;
        }
        $template = ( $template ? 1 : 0 );
        $deleted  = ( $deleted  ? 1 : 0 );

        if ( $update ) {
            $varset->clear();
            $varset->add("name", "quoted", $name);
            $varset->add("owner", "unpacked", $owner);
            $varset->add("slice_url", "quoted", $slice_url);
            $varset->add("priority", "number", $priority);
            if ( $superadmin ) {
                $varset->add("deleted", "number", $deleted);
            }
            $varset->add("lang_file", "quoted", $lang_file);

            $SQL = "UPDATE module SET ". $varset->makeUPDATE() . " WHERE id='$p_slice_id'";
            if (!$db->query($SQL)) {
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

            //mlx
            //print("<br>$mlxctrl<br>");
            $varset->add(MLX_SLICEDB_COLUMN, "quoted", q_pack_id($mlxctrl)); //store 16bytes packed

            $SQL = "UPDATE slice SET ". $varset->makeUPDATE() . " WHERE id='$p_slice_id'";
            if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
                $err["DB"] = MsgErr("Can't change slice");
                break;
            }
            $r_slice_view_url = ($slice_url=="" ? $sess->url("../slice.php3"). "&slice_id=$slice_id&encap=false"
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

            if ( !$db->query("INSERT INTO module" . $varset->makeINSERT() )) {
                $err["DB"] .= MsgErr("Can't add slice");
                break;
            }

            $varset->clear();

            // get template data
            $varset->addArray( $SLICE_FIELDS_TEXT, $SLICE_FIELDS_NUM );
            $SQL = "SELECT * FROM slice WHERE id='". q_pack_id($set_template_id) ."'";
            $db->query($SQL);
            if ( !$db->next_record() ) {
                $err["DB"] = MsgErr("Bad template id");
                break;
            }
            $varset->setFromArray($db->Record);
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
            //mimo
            $varset->add(MLX_SLICEDB_COLUMN, "quoted", $mlxctrl);

            // create new slice
            if ( !$db->query("INSERT INTO slice" . $varset->makeINSERT() )) {
                $err["DB"] .= MsgErr("Can't add slice");
                break;
            }

            // copy fields
            $db2  = new DB_AA;
            $SQL = "SELECT * FROM field WHERE slice_id='". q_pack_id($set_template_id) ."'";
            $db->query($SQL);
            while ( $db->next_record() ) {
                $varset->clear();
                $varset->addArray( $FIELD_FIELDS_TEXT, $FIELD_FIELDS_NUM );
                $varset->setFromArray($db->Record);
                $varset->set("slice_id", $slice_id, "unpacked" );
                $SQL = "INSERT INTO field " . $varset->makeINSERT();
                if ( !$db2->query($SQL)) {
                    $err["DB"] .= MsgErr("Can't copy fields");
                    break;
                }
            }

            $sess->register('slice_id');

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
                                    array ("slice_id"=>q_pack_id($slice_id)),
                                    array ("id"))) {
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
            Files::destinationDir(AA_Slices::getSlice($slice_id));
        }
//        $slice->setSliceField('_upload_url.....', $upload_url);

        $GLOBALS['pagecache']->invalidateFor("slice_id=$slice_id");  // invalidate old cached values for this slice
    } while(false);

    if ( count($err) <= 1 ) {
        go_return_or_url($sess->url(self_base() . "slicedit.php3"),0,0);
    }
}

?>
