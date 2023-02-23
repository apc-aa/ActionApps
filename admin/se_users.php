<?php
/** expected $slice_id for edit slice
 *   optionaly $Msg to show under <h1>Headline</h1>
 *   (typicaly: Category update successful)
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

use AA\IO\DB\DB_AA;
use AA\Util\InputVars;

// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/se_users.php";

try {
    // array ( 2 => 16, )
    $UsrAdd = InputVars::stringGET('UsrAdd');
    $UsrDel = InputVars::stringGET('UsrDel'); // alpha
    // array ( 'Vyhledat' => 2, )
    $UsrSrch = InputVars::boolPOST('UsrSrch'); // alpha
    $GrpSrch = InputVars::boolPOST('GrpSrch'); // alpha
    // array ( 1 => 3, )
    $adduser = InputVars::boolGET('adduser');
    // array ( '' => 2, )
    $grp = InputVars::stringPOST('grp'); // empty
    // array ( 'ADMINISTRAT…' => 12, 'EDITOR' => 2, 'AUTHOR' => 2, )
    $role = InputVars::stringGET('role'); // alpha
    // array ( '' => 2, )
    $usr = InputVars::stringPOST('usr'); // empty
    $cancel = InputVars::boolPOST('cancel');
    $err = InputVars::arrayGETPOST('err');
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception $e) {}

if (!IfSlPerm(PS_USERS)) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php3", _m("You have not permissions to manage users"), "admin");
    exit;
}

/** function shows link only if condition is true
 * @param $cond
 * @param $url
 * @param $txt
 */
function IfLink( $cond, $url, $txt, $class='btn btn-secondary'): string {
    return '<td>'. ($cond ? "<a href=\"$url\" class='$class'>$txt</a>" : $txt) ."</td>\n";
}

/** PrintUser function
 * @param $usr
 * @param $usr_id
 * @param $editor_perm
 */
function PrintUser($perm, $usr_id, $editor_perm) {

    $username   = perm_username($usr_id);
    $usr_id_enc = rawurlencode($usr_id);

    // select role icon
    $role_img = 'rolex.gif';
    if ( !$perm ) {
        $role_img = 'blank.gif';
    } elseif ( IsPerm($perm,AA_Perm::ROLES["SUPER"]['id']) ) {
        $role_img = 'role4.gif';
    } elseif (IsPerm($perm,AA_Perm::ROLES["ADMINISTRATOR"]['id'] ) ) {
        $role_img = 'role3.gif';
    } elseif (IsPerm($perm,AA_Perm::ROLES["EDITOR"]['id'] ) ) {
        $role_img = 'role2.gif';
    } elseif (IsPerm($perm,AA_Perm::ROLES["AUTHOR"]['id'] ) ) {
        $role_img = 'role1.gif';
    }

    echo "<tr><td><img src=\"../images/$role_img\" width=50 height=25></td>\n";

    $go_url_arr = [
        'User'        => "um_uedit.php?usr_edit=1&selected_user=$usr_id_enc",
        'Group'       => "um_gedit.php?grp_edit=1&selected_group=$usr_id_enc",
        'Reader'      => "#",
        'ReaderGroup' => "index.php3?change_id=$usr_id_enc"
    ];
    $usrinfo = AA::$perm->getIDsInfo($usr_id);

    // add link to user settings for superadmins
    $usr_code = ( !IsSuperadmin() ? $usrinfo['name'] :
        '<a href="'. get_admin_url(  $go_url_arr[$usrinfo['type']] ) .'">'. $usrinfo['name'] .'</a>' );

    echo "<td>". $usr_code ."</td>\n";
    echo "<td>". $username ."</td>\n";
    echo "<td>". (($usrinfo['mail']) ?: "&nbsp;") ."</td>\n";
    echo "<td>". _mdelayed($usrinfo['type']) ."</td>\n";

    echo IfLink( CanChangeRole($perm, $editor_perm, AA_Perm::ROLES["AUTHOR"]['perm']),        get_url('', ['UsrAdd'=>$usr_id, 'role'=>"AUTHOR"],        true), _m("Author"));
    echo IfLink( CanChangeRole($perm, $editor_perm, AA_Perm::ROLES["EDITOR"]['perm']),        get_url('', ['UsrAdd'=>$usr_id, 'role'=>"EDITOR"],        true), _m("Editor"));
    echo IfLink( CanChangeRole($perm, $editor_perm, AA_Perm::ROLES["ADMINISTRATOR"]['perm']), get_url('', ['UsrAdd'=>$usr_id, 'role'=>"ADMINISTRATOR"], true), _m("Administrator"));
    echo IfLink( CanChangeRole($perm, $editor_perm, AA_Perm::ROLES["AUTHOR"]['perm']),        get_url('', ['UsrDel'=>$usr_id, 'role'=>"AUTHOR"],        true), _m("Revoke"), 'aa-button-delete');

    // show profile button also for groups
    $cnt = ($cnt = DB_AA::select1('cnt', 'SELECT count(*) as cnt FROM profile', [
        ['slice_id', $GLOBALS['slice_id'], 'l'],
        ['uid', $usr_id]
    ])) ? " <span class='aa-bin-cnt'>$cnt</span>" : '';

    echo "<td><button type=\"button\" name=\"uid\" onclick=\"document.location='". StateUrl("se_profile.php?uid=$usr_id_enc") ."'\">". _m("Profile") ."$cnt</button></td>\n";
    echo "</tr>\n";
}

$show_adduser = $adduser || $GrpSrch || $UsrSrch;    // show add user form?


$apage = new AA_Adminpageutil('sliceadmin',$show_adduser ? "addusers" : "users");
$apage->setTitle(_m("Admin - Permissions"));
$apage->setForm();
$apage->printHead($err, $Msg);

$continue=true;

if ( $show_adduser ) {
    AddUserForm($usr, $grp, $GrpSrch, $UsrSrch, $editor_perms);
    $continue = false;
} elseif ( $UsrAdd || $UsrDel) {
    ChangeRole($UsrAdd, $UsrDel, AA::$module_id, $editor_perms, $role); // in include/se_users.php
}

if ( $continue ) {
    FrmTabCaption(_m("Change current permisions"));

    $slice_users   = AA::$perm->getObjectsPerms(AA::$module_id, "slice");
    $aa_users      = AA::$perm->getObjectsPerms(AA_ID, "aa");   // higher than slice
    $profile_users = AA_Profile::getUsersForModule(AA::$module_id);  // sometimes could be set profile to group, which have no rights right now, but profile is set (and is counted for the user)

    // if conflicts slice perms and aa perms - solve it
    foreach ( $slice_users as $usr_id => $foo ) {
        if ( $aa_users[$usr_id] ) {
            $slice_users[$usr_id] = AA_Perm::joinSliceAndAAPerm($slice_users[$usr_id], $aa_users[$usr_id]);
        }
    }

    // no slice permission set, but aa perms yes
    foreach ($aa_users as $usr_id => $prm ) {
        if ( !$slice_users[$usr_id] ) {
            $slice_users[$usr_id] = $prm;
        }
    }

    // no slice nor aa perms, but profile is set
    foreach ($profile_users as $usr_id ) {
        if ( ($usr_id != '*') AND !$slice_users[$usr_id] ) {
            $slice_users[$usr_id] = '';  // no rights, just profile rules (applied to users in such group)
        }
    }

    foreach ($slice_users as $usr_id => $prm) {
        PrintUser($prm, $usr_id, $editor_perms);
    }

    $cnt = ($cnt = DB_AA::select1('cnt', 'SELECT count(*) as cnt FROM profile', [
        ['slice_id', AA::$module_id, 'l'],
        ['uid', '*']
    ])) ? " <span class='aa-bin-cnt'>$cnt</span>" : '';

    echo "<tr><td>&nbsp;</td>
              <td colspan=\"8\">". _m("Default user profile") ."</td>
              <td><button type=\"button\" name=\"uid\" onclick=\"document.location='". StateUrl("se_profile.php?uid=*") ."'\">". _m("Profile") ."$cnt</button></td>\n";
  echo "</tr>\n";

    echo "</table>
    </form></td></tr></table>";
  }

$apage->printFoot();
