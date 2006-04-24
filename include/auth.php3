<?php
/**
 * Auth feature (mod_auth_mysql) related functions:
 * Event handlers and maintenance.
 *
 * @package ReaderInput
 * @version $Id$
 * @author Jakub Adamek, Econnect
 * @copyright (c) 2002-3 Association for Progressive Communications
*/
/*
Copyright (C) 1999-2003 Association for Progressive Communications
http://www.apc.org/

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

//require_once "config.php3";
require_once $GLOBALS['AA_INC_PATH']."util.php3";

if (!is_object( $db )) $db = new DB_AA;

// status codes:
define("SC_ACTIVE", 1);
define("SC_HOLDING_BIN", 2);

// --------------------------------------------------------------------------
/** Updates the mysql_auth tables <em>auth_user</em> and <em>auth_group</em>.
*   @param array $item_ids non-quoted packed IDs
*/
function AuthDeleteReaders( $item_ids, $slice_id ) {
    global $db;
    $db->query ("SELECT type, auth_field_group FROM slice WHERE id='".q_pack_id( $slice_id )."'");
    $db->next_record();
    if ($db->f("type") != "ReaderManagement" || ! $db->f("auth_field_group")) {
        return;
    }
    $db->query ("
        SELECT text FROM content
        INNER JOIN item ON content.item_id = item.id
        WHERE field_id = '".FIELDID_USERNAME."'
        AND item.id IN ('".join_and_quote( "','", $item_ids)."')");
    while ($db->next_record()) {
        $usernames[] = $db->f("text");
    }
    $where = "WHERE username IN ('".join_and_quote( "','", $usernames)."')";
    $db->query("DELETE FROM auth_user ".$where);
    $db->query("DELETE FROM auth_group ".$where);
}

// --------------------------------------------------------------------------
/** Updates the mysql_auth tables <em>auth_user</em> and <em>auth_group</em>.
*   @param array $item_ids non-quoted packed IDs
*/
function AuthUpdateReaders( $item_ids, $slice_id ) {
    $sl_type             = sliceid2field($slice_id, 'type');
    $sl_auth_field_group = sliceid2field($slice_id, 'auth_field_group');
    if (($sl_type != "ReaderManagement") OR !$sl_auth_field_group) {
        return;
    }

    // This select follows the idea of QueryZIDs: it uses several times the
    // table content to place several fields on one row.
    $SQL     = AuthSelect($sl_auth_field_group) ." AND item.id IN ('".join_and_quote( "','", $item_ids )."')";
    $readers = GetTable2Array( $SQL, "NoCoLuMn");

    if ( is_array( $readers )) {
        foreach ($readers as $reader) {
            if (AuthIsActive($reader) AND $reader["groups"]) {
                AuthUpdateReader($reader["username"], $reader["password"], $reader["groups"]);
            } else {
                AuthDeleteReader($reader["username"]);
            }
        }
    }
    AuthMaintenance();
}

// --------------------------------------------------------------------------
/** Adds readers moved automatically from Pending to Active, deletes readers moved from
*   Active to Expired. Does some sanity checks also. Writes the results to
*   the table <em>auth_log</em>.
*
*   This function should be called once a day from cron.
*/
function AuthMaintenance() {
    $db = getDB();

    // Create the array $oldusers with user names
    $db->query("SELECT username FROM auth_user");
    while ($db->next_record()) {
        $oldusers[$db->f("username")] = 1;
    }

    $slices = GetTable2Array("SELECT id, auth_field_group FROM slice WHERE type='ReaderManagement'");

    // Work slice by slice
    foreach ($slices as $slice_id => $slice) {
        if (! $slice["auth_field_group"]) {
            continue;
        }
        // Get all reader data for this slice
        $SQL = AuthSelect($slice["auth_field_group"])." AND slice_id = '".addslashes($slice_id)."'";
        $db->query($SQL);
        while ($db->next_record()) {
            $olduser_exists = $oldusers[$db->f("username")];
            unset($oldusers[$db->f("username")]);

            // Add readers which should be in auth_user but are not
            // (perhaps moved recently from Pending to Active)
            if (AuthIsActive($db->Record) && $db->f("groups")) {
                if (! $olduser_exists) {
                    $result["readers added"] ++;
                    AuthUpdateReader($db->f("username"), $db->f("password"), $db->f("groups"));
                }
            }
            // Remove readers which are in auth_user but should not
            // (perhaps moved recently from Active to Expired)
            elseif ($olduser_exists) {
                $result["not active readers deleted"] ++;
                AuthDeleteReader($db->f("username"));
            }
        }
    }

    // Sanity checks:

    // Delete readers which are in no slice
    if (is_array( $oldusers ) && count( $oldusers )) {
        $result["not existing readers deleted"] = count( $oldusers );
        $usernames = array_keys($oldusers);
        $where     = "WHERE username IN ('".join_and_quote( "','", $usernames)."')";
        $db->query("DELETE FROM auth_user ".$where);
        $db->query("DELETE FROM auth_group ".$where);
    }

    // Delete readers with no groups
    $db->query("
        SELECT auth_user.username FROM auth_user LEFT JOIN auth_group
        ON auth_user.username = auth_group.username
        WHERE auth_group.username IS NULL");
    if ($db->num_rows()) {
        $result["Readers with no groups, deleted"] = $db->num_rows();
        while ($db->next_record()) {
            $usernames[] = $db->f("username");
        }
        $db->query("DELETE FROM auth_user WHERE username IN ('".join_and_quote("','", $usernames)."')");
    }

    // Delete groups with username not from auth_user
    $db->query ("
        SELECT auth_group.username FROM auth_user RIGHT JOIN auth_group
        ON auth_user.username = auth_group.username
        WHERE auth_user.username IS NULL");
    if ($db->num_rows()) {
        $result["Readers in auth_group but not in auth_user, deleted"] = $db->num_rows();
        while ($db->next_record()) {
            $usernames[] = $db->f("username");
        }
        $db->query("DELETE FROM auth_group WHERE username IN ('".join_and_quote("','", $usernames)."')");
    }

    if ($GLOBALS["log_auth_results"]) {
        // Log the results
        if (!is_array($result)) {
            $log = "Nothing changed.";
        } else {
            foreach ($result as $msg => $count) {
                if ($log) {
                    $log .= "\n";
                }
                $log .= $msg.": ".$count;
            }
        }
        $db->query("INSERT INTO auth_log (result, created) VALUES ('".addslashes($log)."', ".time().")");
    }
    freeDB($db);
}

// --------------------------------------------------------------------------

function AuthDeleteReader($username) {
    global $db;
    $db->query("DELETE FROM auth_user WHERE username='".addslashes($username)."'");
    AuthUpdateGroups($username);
}

// --------------------------------------------------------------------------

function AuthUpdateReader($username, $password, $groups) {
    global $db;
    $db->query("REPLACE INTO auth_user (username, passwd, last_changed)
        VALUES ('".addslashes($username)."', '".addslashes($password)."', ".time().")");
    AuthUpdateGroups($username, $groups);
}

// --------------------------------------------------------------------------

function AuthIsActive($reader) {
    return ($reader["status_code"] == SC_ACTIVE) AND ($reader["publish_date"] <= time()) AND ($reader["expiry_date"] >= time());
}

// --------------------------------------------------------------------------

function AuthUpdateGroups($username, $groups = "") {
    global $db;
    $username = addslashes($username);
    $db->query("DELETE FROM auth_group WHERE username='$username'");
    if ($groups) {
        foreach (explode(";", $groups) as $group) {
            $db->query("INSERT INTO auth_group (username, groups, last_changed) VALUES ('$username','".addslashes($group)."',".time().")");
        }
    }
}

// --------------------------------------------------------------------------

function AuthSelect($auth_field_group) {
    return "
    SELECT publish_date, expiry_date, status_code, groups.text AS groups,
           username.text AS username, password.text AS password
    FROM item, content AS groups, content AS username, content AS password
    WHERE groups.item_id = item.id
      AND groups.field_id = '".$auth_field_group."'
      AND username.item_id = item.id
      AND username.field_id = '".FIELDID_USERNAME."'
      AND password.item_id = item.id
      AND password.field_id = '".FIELDID_PASSWORD."'";
}

// --------------------------------------------------------------------------

/** Called from Event_ItemsAfterPropagateConstantChanges(). */
function AuthChangeGroups($constant_id, $oldvalue, $newvalue) {
    global $db_usernames;
    if (!is_object($db_usernames)) {
        $db_usernames = new DB_AA;
    }

    if (empty($newvalue) OR ($oldvalue == $newvalue)) {
        return;
    }
    $db_usernames->query("SELECT * FROM constant WHERE id='".q_pack_id ($constant_id)."'");
    if (!$db_usernames->next_record()) {
        return;
    }
    $group_id = $db_usernames->f("group_id");
    $db_usernames->query("
        SELECT username.text
        FROM slice INNER JOIN field ON slice.id=field.slice_id
        INNER JOIN item ON slice.id = item.slice_id
        INNER JOIN content ON item.id=content.item_id AND field.id = content.field_id
        INNER JOIN content AS username ON username.item_id=item.id
        WHERE slice.type = 'ReaderManagement'
        AND slice.auth_field_group = field.id
        AND (field.input_show_func LIKE '___:$group_id:%'
        OR  field.input_show_func LIKE '___:$group_id')
        AND content.text = '$newvalue'
        AND username.field_id='".FIELDID_USERNAME."'");

    $newvalue = stripslashes($newvalue);

    while ($db_usernames->next_record()) {
        AuthUpdateGroups($db_usernames->f("text"), $newvalue);
    }
}
?>