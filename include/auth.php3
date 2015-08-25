<?php
/**
 * Auth feature (mod_auth_mysql) related functions:
 * Event handlers and maintenance.
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
 * @package   ReaderInput
 * @version   $Id$
 * @author    Jakub Adamek, Econnect
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 2002-3 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

require_once AA_INC_PATH."util.php3";

is_object( $db ) || ($db = getDB());

class AA_Mysqlauth {

    // --------------------------------------------------------------------------
    /** AA_Mysqlauth::deleteReaders function
    *   Updates the mysql_auth tables <em>auth_user</em> and <em>auth_group</em>.
    *   @param array $item_ids non-quoted packed IDs
    *   @param $slice_id
    */
    function deleteReaders( $item_ids, $slice_id ) {
        $db = getDB();
        $db->query ("SELECT type, auth_field_group FROM slice WHERE id='".q_pack_id( $slice_id )."'");
        $db->next_record();
        if ($db->f("type") != "ReaderManagement" || ! $db->f("auth_field_group")) {
            freeDB($db);
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
        freeDB($db);
    }

    // --------------------------------------------------------------------------
    /** AA_Mysqlauth::updateReaders function
    *   Updates the mysql_auth tables <em>auth_user</em> and <em>auth_group</em>.
    *   @param array $item_ids non-quoted packed IDs
    *   @param $slice_id
    */
    function updateReaders( $item_ids, $slice_id ) {
        $sl_type             = AA_Slice::getModule($slice_id)->getProperty('type');
        $sl_auth_field_group = AA_Slice::getModule($slice_id)->getProperty('auth_field_group');
        if (($sl_type != "ReaderManagement") OR !$sl_auth_field_group) {
            return;
        }

        // This select follows the idea of QueryZIDs: it uses several times the
        // table content to place several fields on one row.
        $zids    = new zids($item_ids, 'p');
        $readers = AA_Mysqlauth::getReadersData($slice_id, $zids);

        if ( is_array( $readers )) {
            foreach ($readers as $reader) {
                $reader_obj = new ItemContent($reader);
                if (AA_Mysqlauth::isActive($reader_obj) AND $reader_obj->is_set($sl_auth_field_group)) {
                    AA_Mysqlauth::updateReader($reader_obj->getValue(FIELDID_USERNAME), $reader_obj->getValue(FIELDID_PASSWORD), $reader_obj->getValues($sl_auth_field_group));
                } else {
                    AA_Mysqlauth::deleteReader($reader_obj->getValue(FIELDID_USERNAME));
                }
            }
        }
        AA_Mysqlauth::maintenance();
    }
    /** AA_Mysqlauth::getReadersData function
     * @param $slice_id
     * @param $restrict_zids = false
     */
    function getReadersData($slice_id, $restrict_zids=false) {
        $auth_field_group = AA_Slice::getModule($slice_id)->getProperty('auth_field_group');
        $zids  = QueryZIDs( array($slice_id), '', '', 'ALL', 0, $restrict_zids);
        return GetItemContent($zids, false, true, array('id..............','status_code.....','slice_id........', 'publish_date....', 'expiry_date.....', $auth_field_group, FIELDID_USERNAME, FIELDID_PASSWORD));
    }

    // --------------------------------------------------------------------------
    /** AA_Mysqlauth::maintenance function
    *   Adds readers moved automatically from Pending to Active, deletes readers moved from
    *   Active to Expired. Does some sanity checks also. Writes the results to
    *   the table <em>auth_log</em>.
    *
    *   This function should be called once a day from cron.
    */
    function maintenance() {
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

            $readers = AA_Mysqlauth::getReadersData(unpack_id($slice_id));

            if ( is_array( $readers )) {
                foreach ($readers as $reader) {
                    $reader_obj = new ItemContent($reader);

                    $olduser_exists = $oldusers[$reader_obj->getValue(FIELDID_USERNAME)];
                    unset($oldusers[$reader_obj->getValue(FIELDID_USERNAME)]);

                    // Add readers which should be in auth_user but are not
                    // (perhaps moved recently from Pending to Active)
                    if (AA_Mysqlauth::isActive($reader_obj) AND $reader_obj->is_set($slice["auth_field_group"])) {
                        if (! $olduser_exists) {
                            $result["readers added"]++;
                            AA_Mysqlauth::updateReader($reader_obj->getValue(FIELDID_USERNAME), $reader_obj->getValue(FIELDID_PASSWORD), $reader_obj->getValues($slice["auth_field_group"]));
                        }
                    }
                    // Remove readers which are in auth_user but should not
                    // (perhaps moved recently from Active to Expired)
                    elseif ($olduser_exists) {
                        $result["not active readers deleted"]++;
                        AA_Mysqlauth::deleteReader($reader_obj->getValue(FIELDID_USERNAME));
                    }
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
    /** AA_Mysqlauth::deleteReader function
     * @param $username
     */
    function deleteReader($username) {
        global $db;
        $db->query("DELETE FROM auth_user WHERE username='".addslashes($username)."'");
        AA_Mysqlauth::updateGroups($username);
    }

    // --------------------------------------------------------------------------
    /** AA_Mysqlauth::updateReader function
     * @param $username
     * @param $password
     * @param $groups
     */
    function updateReader($username, $password, $groups) {
        global $db;
        $db->query("REPLACE INTO auth_user (username, passwd, last_changed)
            VALUES ('".addslashes($username)."', '".addslashes($password)."', ".time().")");
        AA_Mysqlauth::updateGroups($username, $groups);
    }

    // --------------------------------------------------------------------------
    /** AA_Mysqlauth::isActive function
     * @param $reader
     */
    function isActive($reader) {
        return ($reader->getValue('status_code.....') == SC_ACTIVE) AND
               ($reader->getValue('publish_date....') <= time()) AND
               ($reader->getValue('expiry_date.....') >= time());
    }


    // --------------------------------------------------------------------------

    /** AA_Mysqlauth::updateGroups function
    *   Writes user's groups to the database.
    *   You can specify multiple groups for the user in two ways:
    *      - pass $groups as array( 0 => array('value' => ...),
    *      - separate groups in $proups string by semicolon ';'
    *   @param $username
    *   @param $groups
    */
    function updateGroups($username, $groups = array()) {
        global $db;
        $username = addslashes($username);
        $db->query("DELETE FROM auth_group WHERE username='$username'");

        $final_groups = array();
        foreach( (array)$groups as $group_string ) {
            $final_groups = array_merge($final_groups, explode(";", $group_string['value']));
        }
        foreach (array_unique($final_groups) as $group) {
            if ( $group ) {
                $db->query("INSERT INTO auth_group (username, groups, last_changed) VALUES ('$username','".addslashes($group)."',".time().")");
            }
        }
    }

    // --------------------------------------------------------------------------
    /** AA_Mysqlauth::select function
     * @param $auth_field_group
     * @return string
     */
    function select($auth_field_group) {
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

    /** AA_Mysqlauth::changeGroups function
     * Called from Event_ItemsAfterPropagateConstantChanges().
     * @param $constant_id
     * @param $oldvalue
     * @param $newvalue
     */
    function changeGroups($constant_id, $oldvalue, $newvalue) {
        if (empty($newvalue) OR ($oldvalue == $newvalue)) {
            return;
        }
        if (false === ($group_id = DB_AA::select1('SELECT group_id FROM `constant`', 'group_id', array(array('id', $constant_id, 'l'))))) {
            return;
        }

        $usernames = DB_AA::select('text',
            "SELECT username.text as text FROM slice
            INNER JOIN field ON slice.id=field.slice_id
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

        foreach ($usernames as $name) {
            AA_Mysqlauth::updateGroups($name, $newvalue);
        }
    }
}
?>