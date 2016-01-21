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
 * @package   Include
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

// expected $slice_id for edit slice
// optionaly $Msg to show under <h1>Headline</h1>
// (typicaly: Category update successful)

$editor_perms = AA::$perm->getModulePerms($auth->auth["uid"], $slice_id);

/** CanChangeRole function
 *  decides whether current user can change role
 *  of specified user. Only allowed when $editor_perm (current user) is greater
 *  than $perm (user's role) and $perm_role (new user's role)
 * @param $user_perm
 * @param $editor_perm
 * @param $role_perm
 */
function CanChangeRole($user_perm, $editor_perm, $role_perm) {
    return ((AA_Perm::compare($editor_perm, $user_perm)=="G") AND (AA_Perm::compare($editor_perm, $role_perm)=="G"));
}

/** ChangeRole function
 *
 */
function ChangeRole() {
    global $UsrAdd, $UsrDel, $slice_id, $editor_perms, $role, $perms_roles;

    if ( $UsrAdd ) {
        if ( CanChangeRole( AA::$perm->getModulePerms($UsrAdd, $slice_id, false), $editor_perms, $perms_roles[$role]['perm']) ) {
            AddPerm($UsrAdd, $slice_id, "slice", $perms_roles[$role]['id']);
            $GLOBALS['pagecache']->invalidateFor("slice_id=$slice_id");  // invalidate old cached values
        }
    } elseif( $UsrDel ) {
        if ( CanChangeRole(AA::$perm->getModulePerms($UsrDel, $slice_id, false), $editor_perms, $perms_roles["AUTHOR"]['perm']) ) { // smallest permission
            DelPerm($UsrDel, $slice_id, "slice");
        }

        $profile = AA_Profile::getProfile($UsrDel, $slice_id);  // user settings
        $profile->delUserProfile();
        $GLOBALS['pagecache']->invalidateFor("slice_id=$slice_id");  // invalidate old cached values
    }
}
?>
