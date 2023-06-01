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
 * @package   Include
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

// expected $slice_id for edit slice
// optionally $Msg to show under <h1>Headline</h1>
// (typically: Category update successful)

$editor_perms = AA::$perm->getModulePerms(AuthGetUid(), $slice_id);

/** CanChangeRole function
 *  decides whether current user can change role
 *  of specified user. Only allowed when $editor_perm (current user) is greater
 *  than $perm (user's role) and $perm_role (new user's role)
 * @param $user_perm
 * @param $editor_perm
 * @param $role_perm
 * @return bool
 */
function CanChangeRole($user_perm, $editor_perm, $role_perm) {
    return ((AA_Perm::compare($editor_perm, $user_perm)=="G") AND (AA_Perm::compare($editor_perm, $role_perm)=="G"));
}

/** ChangeRole function
 *
 */
function ChangeRole($UsrAdd, $UsrDel, $slice_id, $editor_perms, $role) {
    if ( $UsrAdd ) {
        if ( CanChangeRole( AA::$perm->getModulePerms($UsrAdd, $slice_id, false), $editor_perms, AA_Perm::ROLES[$role]['perm']) ) {
            AddPerm($UsrAdd, $slice_id, "slice", AA_Perm::ROLES[$role]['id']);
            AA::$perm->invalidateCache();
            AA::Pagecache()->invalidateFor($slice_id);  // invalidate old cached values
        }
    } elseif ( $UsrDel ) {
        if ( CanChangeRole(AA::$perm->getModulePerms($UsrDel, $slice_id, false), $editor_perms, AA_Perm::ROLES["AUTHOR"]['perm']) ) { // smallest permission
            DelPerm($UsrDel, $slice_id, "slice");
            AA::$perm->invalidateCache();
        }

        $profile = AA_Profile::getProfile($UsrDel, $slice_id);  // user settings
        $profile->delUserProfile();
        AA::Pagecache()->invalidateFor($slice_id);  // invalidate old cached values
    }
}

// variable $editor_perms should be set
// variable $slice_id should be set
/** PrintAddableUser function
 * @param $usr
 * @param $usr_id
 * @param $editor_role
 * @param $new_usr=true
 */
function PrintAddableUser($usr, $usr_id, $editor_role, $new_usr=true) {
    // $usr_id is DN in LDAP
    $username = perm_username($usr_id);
    $usr_id   = rawurlencode($usr_id);

    echo "<tr><td>". $usr['name'] ."</td>\n";
    echo "<td>$username</td>\n";
    echo "<td>". (($usr['mail']) ?: "&nbsp;") ."</td>\n";

    echo IfLink(($editor_role > AA_Perm::ROLES["AUTHOR"]['id']) && $new_usr, get_url('', ['UsrAdd'=>$usr_id, 'role'=>'AUTHOR'], true), _m("Author"));
    echo IfLink(($editor_role > AA_Perm::ROLES["EDITOR"]['id']) && $new_usr, get_url('', ['UsrAdd'=>$usr_id, 'role'=>'EDITOR'], true), _m("Editor"));
    echo IfLink(($editor_role > AA_Perm::ROLES["ADMINISTRATOR"]['id']) && $new_usr, get_url('', ['UsrAdd'=>$usr_id, 'role'=>'ADMINISTRATOR'], true), _m("Administrator"));
    echo "</tr>\n";
}

/**
 * @param string $usr
 * @param string $grp
 * @param bool   $GrpSrch
 * @param bool   $UsrSrch
 * @param        $editor_perms
 * @return void
 */
function AddUserForm(string $usr, string $grp, bool $GrpSrch, bool $UsrSrch, $editor_perms): void {
    $form_buttons = [
        "back" => [
            "type"  => "submit",
            "name"  => "back",
            "value" => _m("Back"),
        ],
    ];

    echo '<form method="post" action="' . StateUrl() . '">';
    echo getFrmTabCaption(_m("Search user or group"), '', 'data-aa-class=clasic');
    echo '
    <tr>
        <th width="30%">' . _m("Users") . '</th>
        <td width="40%"><input type="text" name="usr" value="' . safe($usr) . '"></td>
        <td width="30%"><input type="submit" name="UsrSrch" value="' . _m("Search") . '"></td>
    </tr>
    <tr>
        <th>' . _m("Groups") . '</th>
        <td><input type="text" name="grp" value="' . safe($grp) . '"></td>
        <td><input type="submit" name="GrpSrch" value="' . _m("Search") . '"></td>
    </tr>';
    if ($GrpSrch || $UsrSrch) {
        $addable = $GrpSrch ? AA::$perm->findGroups($grp) : AA::$perm->findUsernames($usr);

        FrmTabSeparator(_m("Assign new permissions"));
        // determine role of this user
        if (AA_Perm::compare($editor_perms, AA_Perm::ROLES["SUPER"]['perm']) != "L") {
            $curr_role = AA_Perm::ROLES["SUPER"]['id'];
        } elseif (AA_Perm::compare($editor_perms, AA_Perm::ROLES["ADMINISTRATOR"]['perm']) != "L") {
            $curr_role = AA_Perm::ROLES["ADMINISTRATOR"]['id'];
        } elseif (AA_Perm::compare($editor_perms, AA_Perm::ROLES["EDITOR"]['perm']) != "L") {
            $curr_role = AA_Perm::ROLES["EDITOR"]['id'];
        } elseif (AA_Perm::compare($editor_perms, AA_Perm::ROLES["AUTHOR"]['perm']) != "L") {
            $curr_role = AA_Perm::ROLES["AUTHOR"]['id'];
        } else {
            $curr_role = 0;
        }

        $slice_users = AA::$perm->getObjectsPerms(AA::$module_id, "slice");
        $aa_users    = AA::$perm->getObjectsPerms(AA_ID, "aa");   // higher than slice

        // add aa users too
        foreach ($aa_users as $usr_id => $foo) {
            if (!$slice_users[$usr_id]) {
                $slice_users[$usr_id] = $aa_users[$usr_id];
            }
        }

        $l_counter = 1;
        if (!is_array($addable)) {
            if ($addable == "too much") {
                echo "<tr><td>" . _m("Too many users or groups found.") . " " . _m("Try to be more specific.") . "</td>\n";
            } else {
                echo "<tr><td>" . _m("No user (group) found") . "</td>\n";
            }
        } else {
            foreach ($addable as $usr_id => $usr) {
                if (!$slice_users[$usr_id]) {                         // show only new users
                    PrintAddableUser($usr, $usr_id, $curr_role, true);
                } else {
                    PrintAddableUser($usr, $usr_id, $curr_role, false);
                }
                if ($l_counter++ >= MAX_ENTRIES_SHOWN) {
                    break;
                }
            }
        }

    }
    FrmTabEnd($form_buttons);
}
