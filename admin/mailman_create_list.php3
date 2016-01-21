<?php
/**
 *  Mailman create list: Used only for Reader Management Slices using the
 *  mailman feature. Adds a constant to the constant group with mail list names
 *  and adds a row
 *
 *      createnew <listname> <listadmin-addr> <admin-password>
 *
 *  to the file ".mailman" in the $MAILMAN_SYNCHRO_DIR.
 *
 *  To avoid conflicts, uses the file lock (see file_lock.php3).
 *  A Unix shell script should be run regularly by cron and use the file-lock
 *  mechanism as well. The Unix script executes the commands and deletes the file.
 *
 *
 *  PHP versions 4 and 5
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
 * @package   UserInput
 * @version   $Id$
 * @author    Jakub Adamek <jakubadamek@ecn.cz>, February 2003
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999-2003 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/


require_once "../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."msgpage.php3";
require_once AA_INC_PATH."itemfunc.php3";
require_once AA_INC_PATH."file_lock.php3";

if ($cancel) {
  go_url( $sess->url(self_base() . "index.php3"));
}

if (!IfSlPerm(PS_FIELDS)) {
  MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change fields settings"), "admin");
  exit;
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo "<title>"._m("Admin - Create Mailman List")."</title>
</head>";

require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "sliceadmin", "mailman_create_list");

echo "<h1>"._m("Admin - Create Mailman List")."</b></h1>";

PrintArray($err);
echo $Msg;

$slice = AA_Slice::getModule($slice_id);
if (! $slice->getProperty("mailman_field_lists")) {
    echo _m('First set Mailman Lists Field in Slice Settings.');
    HtmlPageEnd();
    page_close();
    exit;
}

$db = getDB();
$db->query("SELECT field.input_show_func, field.name FROM field WHERE slice_id='".q_pack_id($slice_id)."' AND id='" .$slice->getProperty("mailman_field_lists")."'");
$db->next_record();
$field_name = $db->f("name");
list (,$groupid) = explode(":", $db->f("input_show_func"));
freeDB($db);

if ($create_list && $admin_email && $list_name && $admin_password) {
    add_mailman_list();
}
/** add_mailman_list function
 * Creates a new mailman list
 * @return none on error
 */
function add_mailman_list() {
    global $admin_email, $list_name, $admin_password, $groupid;

    $db = getDB();
    $db->query ("SELECT * FROM constant WHERE group_id='".addslashes($groupid)."' AND name='".$list_name."'");
    if ($db->next_record()) {
        echo _m("Error: This list name is already used.");
        freeDB($db);
        return;
    }
    $db->query ("SELECT MAX(pri) AS max_pri FROM constant WHERE group_id='".addslashes($groupid)."'");
    $db->next_record();
    $pri = $db->f("max_pri") + 100;
    if (! $db->query ("INSERT INTO constant (id, group_id, name, value, pri) VALUES ('".q_pack_id(new_id())."', '".addslashes($groupid)."', '$list_name', '$list_name', $pri)")) {
        echo "Internal Error with DB.";
        freeDB($db);
        return;
    }
    freeDB($db);

    global $MAILMAN_SYNCHRO_DIR;
    endslash ($MAILMAN_SYNCHRO_DIR);
    $filelock = new FileLock ($MAILMAN_SYNCHRO_DIR.".mailman_lock");
    if (! $filelock->Lock()) {
        echo "Internal Error when creating lock file.";
        return;
    }

    if (! ($fd = fopen ($MAILMAN_SYNCHRO_DIR.".mailman", "a"))) {
        echo "Internal Error when creating request file.";
        return;
    }
    fwrite ($fd, "createnew $list_name $admin_email $admin_password\n");
    fclose ($fd);

    if (! $filelock->Unlock()) {
        echo "Internal Error when deleting lock file.";
        return;
    }
    echo _m("The list was successfully created.");
}

$me = AA::$perm->getIDsInfo($auth->auth["uid"]);
if (! $admin_email) {
    $admin_email = $me["mail"];
}
/** caption function
 * @param $s
 * @return $s in <b> and spaces are replaced by "&nbsp;"
 */
function caption($s) {
    return "<b>".str_replace (" ","&nbsp;",$s)."&nbsp;*</b>";
}

echo '
<form name="mailman_create_list" method="post" action="'.$sess->url($_SERVER['PHP_SELF']).'"
    onsubmit="return this.list_name.value != \'\' && this.admin_email.value != \'\'
    && this.admin_password.value != \'\'">
<input type="hidden" name="slice_id" value="'.$slice_id.'">
<table width="440" border="0" cellspacing="0" cellpadding="5" bgcolor="'.COLOR_TABTITBG.'" align="center">
<tr><td class="tabtit" colspan="2"><b>'._m("List Settings").'</b></td></tr>
<tr><td class="tabtxt" colspan="2">'._m("The list will be added to mailman and also
    to the constant group for the field %1 selected as Mailman Lists Field in Slice Settings.", array ($field_name)).'<br><br>'
._m("All the fields are required.").'</td></tr>
<tr class="tabtxt"><td>'.caption(_m("List name")).'</td>
<td><input type="text" name="list_name" size="20" value="'.$list_name.'"></td></tr>
<tr class="tabtxt"><td>'.caption(_m("Admin email")).'</td>
<td><input type="text" name="admin_email" value="'.str_replace('"','&quot;',$admin_email).'" size="50"></td></tr>
<tr class="tabtxt"><td>'.caption(_m("Admin password")).'</td>
<td><input type="password" name="admin_password" size="20"></td></tr>';
echo '
<tr><td align="center" colspan="2">
    <input type="submit" name="create_list" value="'._m("Create").'">&nbsp;&nbsp;
</td></tr></table>
</form>';
HtmlPageEnd();
page_close();
?>
