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
 * @package   Maintain
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
*/

/** @todo this class should be abstract after we switch to PHP5 */
class AA_Optimize {
    var $messages = array();
    function name()         {}
    function description()  {}
    function test()         {}
    function repair()       {}

    /** implemented actions within this class */
    function actions()      { return array('test','repair'); }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, array()); }

    /** Message function
    * @param $text
    */
    function message($text) {
        $this->messages[] = $text;
    }

    /** Report function
    * @return messages separated by <br>
    */
    function report()       {
        return join('<br>', $this->messages);
    }

    /** Clear report function
    * unsets all current messages
    */
    function clear_report() {
        unset($this->messages);
        $this->messages = array();
    }
}

/** Testing if relation table contain records, where values in both columns are
 *  identical (which was bug fixed in Jan 2006)
 */
class AA_Optimize_Category_Sort2group_By extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Convert slice.category_sort to slice.group_by");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("In older version of AA we used just category fields for grouping items. Now it is universal, so boolean category_sort is not enough. We use newer group_by field for quite long time s most probably all your slices are already conevrted.");
    }

    /** Test function
    * @return bool
    */
    function test() {
        $SQL         = "SELECT name FROM slice WHERE category_sort>0 AND ((group_by IS NULL) OR (group_by=''))";
        $slice_names = GetTable2Array($SQL, '', 'name');
        if ($slice_names AND (count($slice_names) > 0)) {
            $this->message( _m('%1 slices are not converted', array(count($slice_names))). '<br> &nbsp; '. join('<br> &nbsp; ',$slice_names));
            return false;
        }
        $this->message(_m('All slices are already converted'));
        return true;
    }

    /** Repair function
    * runs a series of SQL commands
    * @return bool
    */
    function repair() {
        $db     = getDb();
        $SQL    = "SELECT id FROM slice WHERE category_sort>0 AND ((group_by IS NULL) OR (group_by=''))";
        $slices = GetTable2Array($SQL, '', 'id');
        foreach ($slices as $p_slice_id) {
            $q_slice_id = quote($p_slice_id);
            $SQL        = "SELECT id FROM field WHERE id LIKE 'category.......%' AND slice_id='$q_slice_id'";
            $cat_field  = GetTable2Array($SQL, "aa_first", 'id');
            if ($cat_field) {
                // number 2 represents 'a' - ascending (because gb_direction in number)
                $SQL = "UPDATE slice SET group_by='". quote($cat_field) ."', gb_direction=2, gb_header=0 WHERE id='$q_slice_id'";
            } else {
                $SQL = "UPDATE slice SET group_by='', gb_direction=0, gb_header=0 WHERE id='$q_slice_id'";
            }
            $db->query($SQL);   // correct it
        }
        // fix all category_sort
        $SQL = "UPDATE slice SET category_sort=0";
        $db->query($SQL);
        freeDb($db);
        return true;
    }
}



/** Testing if relation table contain records, where values in both columns are
 *  identical (which was bug fixed in Jan 2006)
 */
class AA_Optimize_Db_Relation_Dups extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Relation table duplicate records");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("Testing if relation table contain records, where values in both columns are identical (which was bug fixed in Jan 2006)");
    }

    /** Test function
    * tests for duplicate entries
    * @return bool
    */
    function test() {
        $SQL       = 'SELECT count(*) as err_count FROM `relation` WHERE `source_id`=`destination_id`';
        $err_count = GetTable2Array($SQL, "aa_first", 'err_count');
        if ($err_count > 0) {
            $this->message( _m('%1 duplicates found', array($err_count)) );
            return false;
        }
        $this->message(_m('No duplicates found'));
        return true;
    }

    /** Name function
    * @return bool
    */
    function repair() {
        $db  = getDb();
        $SQL = 'DELETE FROM `relation` WHERE `source_id`=`destination_id`';
        $db->query($SQL);
        freeDb($db);
        return true;
    }
}


/** Testing if feeds table do not contain relations to non existant slices
 */
class AA_Optimize_Db_Feed_Inconsistency extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Feeds table inconsistent records");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("Testing if feeds table do not contain relations to non existant slices (after slice deletion)");
    }

    /** Test function
    * tests for duplicate entries
    * @return bool
    */
    function test() {
        $ret = true;

        // test wrong destination slices
        $SQL = "SELECT from_id,to_id FROM feeds LEFT JOIN slice ON feeds.to_id=slice.id
                WHERE slice.id IS NULL";
        $err = GetTable2Array($SQL, "unpack:from_id", 'unpack:to_id');
        if (is_array($err) AND count($err) > 0) {
            foreach ($err as $from_id => $to_id) {
                $this->message( _m('Wrong destination slice id: %1 -> %2', array(AA_Slices::getName($from_id), $to_id)));
            }
            $ret = false;
        }

        // test wrong source slices
        $SQL = "SELECT from_id,to_id FROM feeds LEFT JOIN slice ON feeds.from_id=slice.id
                WHERE slice.id IS NULL";
        $err = GetTable2Array($SQL, "unpack:from_id", 'unpack:to_id');
        if (is_array($err) AND count($err) > 0) {
            foreach ($err as $from_id => $to_id) {
                $this->message( _m('Wrong source slice id: %1 -> %2', array($from_id, AA_Slices::getName($to_id))));
            }
            $ret = false;
        }
        if ($ret ) {
            $this->message(_m('No wrong references found, hurray!'));
        }
        return $ret;
    }

    /** Name function
    * @return bool
    */
    function repair() {
        $db  = getDb();

        // test wrong destination slices
        $SQL = "SELECT to_id FROM feeds LEFT JOIN slice ON feeds.to_id=slice.id WHERE slice.id IS NULL";
        $err = GetTable2Array($SQL, '', 'unpack:to_id');

        if (is_array($err) AND count($err)>0 ) {
            foreach ($err as $wrong_slice_id) {
                $SQL = 'DELETE FROM `feeds` WHERE `to_id`=\''.q_pack_id($wrong_slice_id).'\'';
                $db->query($SQL);
            }
        }

        // test wrong source slices
        $SQL = "SELECT from_id FROM feeds LEFT JOIN slice ON feeds.from_id=slice.id WHERE slice.id IS NULL";
        $err = GetTable2Array($SQL, '', 'unpack:from_id');

        if (is_array($err) AND count($err)>0 ) {
            foreach ($err as $wrong_slice_id) {
                $SQL = 'DELETE FROM `feeds` WHERE `from_id`=\''.q_pack_id($wrong_slice_id).'\'';
                $db->query($SQL);
            }
        }

        freeDb($db);
        return true;
    }
}



/** Fix user login problem, constants editiong problem, ...
 *  Replaces binary fields by varbinary and removes trailing zeros
 *  Needed for MySQL > 5.0.17
 */
class AA_Optimize_Db_Binary_Traing_Zeros extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Fix user login problem, constants editiong problem, ...");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("Replaces binary fields by varbinary and removes trailing zeros. Needed for MySQL > 5.0.17");
    }

    /** implemented actions within this class */
    function actions()      { return array('repair'); }

    /** Test function
    * @return true
    */
    function test() {
        return true;
    }

    /** Repair function
    * repairs tables
    * @return true
    */
    function repair() {
        $this->_fixTable('active_sessions','sid',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('change','id',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('change','resource_id',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('change_record','change_id',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('change_record','selector',"varbinary(255) default NULL");
        $this->_fixTable('central_conf','dns_conf',"varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf','dns_web',"varbinary(15) NOT NULL default ''");
        $this->_fixTable('central_conf','dns_mx',"varbinary(15) NOT NULL default ''");
        $this->_fixTable('central_conf','dns_db',"varbinary(15) NOT NULL default ''");
        $this->_fixTable('central_conf','dns_prim',"varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf','dns_sec',"varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf','web_conf',"varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf','web_path',"varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf','db_server',"varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf','db_name',"varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf','db_user',"varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf','db_pwd',"varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf','AA_SITE_PATH',"varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf','AA_BASE_DIR',"varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf','AA_HTTP_DOMAIN',"varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf','AA_ID',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('central_conf','ORG_NAME',"varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf','ERROR_REPORTING_EMAIL',"varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf','ALERTS_EMAIL',"varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf','IMG_UPLOAD_URL',"varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf','IMG_UPLOAD_PATH',"varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf','FILEMAN_BASE_DIR',"varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf','FILEMAN_BASE_URL',"varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf','AA_ADMIN_USER',"varbinary(30) NOT NULL default ''");
        $this->_fixTable('central_conf','AA_ADMIN_PWD',"varbinary(30) NOT NULL default ''");
        $this->_fixTable('content','item_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('content','field_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('discussion','id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('discussion','parent',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('discussion','item_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('ef_categories','category_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('ef_categories','target_category_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('ef_permissions','slice_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('email','owner_module_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('external_feeds','slice_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('external_feeds','remote_slice_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('event','id',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('feedmap','from_slice_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('feedmap','from_field_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('feedmap','to_slice_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('feedmap','to_field_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('feedperms','from_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('feedperms','to_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('feeds','from_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('feeds','to_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('feeds','category_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('feeds','to_category_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('field','id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('field','slice_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('field','content_id',"varbinary(16) default NULL");
        $this->_fixTable('jump','slice_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('jump','dest_slice_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('mysql_auth_group','slice_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('mysql_auth_userinfo','slice_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('object_float','object_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('object_float','property',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('object_integer','object_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('object_integer','property',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('object_text','object_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('object_text','property',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('pagecache','id',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('pagecache_str2find','pagecache_id',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('polls','id',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('polls','module_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('polls','design_id',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('polls','aftervote_design_id',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('polls_answer','id',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('polls_answer','poll_id',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('polls_design','id',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('polls_ip_lock','poll_id',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('polls_log','answer_id',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('polls_design','module_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('polls_ip_lock','voters_ip',"varbinary(16) NOT NULL");
        $this->_fixTable('polls_log','voters_ip',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('post2shtml','id',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('profile','slice_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('relation','source_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('relation','destination_id',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('rssfeeds','slice_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('site','id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('site_spot','site_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('slice','id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('slice','type',"varbinary(16) default NOT");
        $this->_fixTable('slice','mlxctrl',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('view','slice_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('view','order1',"varbinary(16) default NULL");
        $this->_fixTable('view','order2',"varbinary(16) default NULL");
        $this->_fixTable('view','group_by1',"varbinary(16) default NULL");
        $this->_fixTable('view','group_by2',"varbinary(16) default NULL");
        $this->_fixTable('view','cond1field',"varbinary(16) default NULL");
        $this->_fixTable('view','cond1op',"varbinary(10) default NULL");
        $this->_fixTable('view','cond2field',"varbinary(16) default NULL");
        $this->_fixTable('view','cond2op',"varbinary(10) default NULL");
        $this->_fixTable('view','cond3field',"varbinary(16) default NULL");
        $this->_fixTable('view','cond3op',"varbinary(10) default NULL");
        $this->_fixTable('view','field1',"varbinary(16) default NULL");
        $this->_fixTable('view','field2',"varbinary(16) default NULL");
        $this->_fixTable('view','field3',"varbinary(16) default NULL");


/*
        $this->_fixTable('alerts_collection','module_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('alerts_collection','slice_id',"varbinary(16) default NULL");
        $this->_fixTable('alerts_collection_filter','collectionid',"varbinary(6) NOT NULL default ''");
        $this->_fixTable('alerts_collection_howoften','collectionid',"varbinary(6) NOT NULL default ''");
        $this->_fixTable('constant','id','varbinary(16) NOT NULL default \'\'');
        $this->_fixTable('constant','group_id','varbinary(16) NOT NULL default \'\'');
        $this->_fixTable('constant','class','varbinary(16) default NULL');
        $this->_fixTable('constant_slice','slice_id',"varbinary(16) default NULL");
        $this->_fixTable('constant_slice','group_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('email_notify','slice_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('item','id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('item','slice_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('links','id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('membership','memberid','varbinary(32) NOT NULL');
        $this->_fixTable('module','id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('module','owner',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('module','app_id',"varbinary(16) default NULL");
        $this->_fixTable('offline','id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('offline','digest',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('perms','objectid',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('perms','userid',"varbinary(32) NOT NULL default '0'");
        $this->_fixTable('perms','perm',"varbinary(32) NOT NULL default ''");
        $this->_fixTable('slice_owner','id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('subscriptions','slice_owner',"varbinary(16) default NULL");
        $this->_fixTable('users','type',"varbinary(10) NOT NULL default ''");
        $this->_fixTable('users','password',"varbinary(30) NOT NULL default ''");
        $this->_fixTable('users','uid',"varbinary(40) NOT NULL default ''");
*/
        return true;
    }

    /** Helper _fixTable function */
    function _fixTable($table, $field, $definition) {
        $db  = getDb();
        $SQL = "ALTER TABLE `$table` CHANGE `$field` `$field` $definition";
        $this->message($SQL);
        $db->query($SQL);
        $SQL = "UPDATE `$table` SET $field=TRIM(TRAILING '\0' FROM $field)";
        $this->message($SQL);
        $db->query($SQL);
        freeDb($db);
    }
}

/** There was change in Reader management functionality in AA v2.8.1 */
class AA_Optimize_Readers_Login2id extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Convert Readers login to reader id");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("There was change in Reader management functionality in AA v2.8.1, so readers are not internaly identified by its login, but by reader ID (item ID of reader in Reader slice). This is much more powerfull - you can create relations just as in normal slice. It works well without any change. The only problem is, if you set any slice to be editable by users from Reader slice. In that case the fields edited_by........ and posted_by........ are filled by readers login instead of reader id. You can fix it by \"Repair\".");
    }

    /** Test function
    * @return bool
    */
    function test() {
        $this->clear_report();
        $ret = true;  // which means OK

        // get all readers in array: id => arrary( name => ...)
        $readers         = FindReaderUsers('');
        $posted_by_found = $this->_test_field($readers, 'posted_by');
        if (count($posted_by_found) > 0) {
            $this->message(_m('%1 login names from reader slice found as records in item.posted_by which is wrong (There should be reader ID from AA v2.8.1). "Repair" will correct it.', array(count($posted_by_found))));
            $ret = false;
        }
        $edited_by_found = $this->_test_field($readers, 'edited_by');
        if (count($edited_by_found) > 0) {
            $this->message(_m('%1 login names from reader slice found as records in item.edited_by which is wrong (There should be reader ID from AA v2.8.1). "Repair" will correct it.', array(count($edited_by_found))));
            $ret = false;
        }
        return $ret;
    }

    /** test if we can find an item which was edited by reader and is identified
     *  by login name (instead of item_id)
     *  @return array of such users
     */
    function _test_field(&$readers, $item_field) {
        // get posted_by, edit_by, ... array:  posted_by => 1
        $SQL     = "SELECT DISTINCT $item_field FROM item";
        $editors = GetTable2Array($SQL, $item_field, 'aa_mark');
        $ret     = array();
        foreach ( $readers as $r_id => $reader ) {
            if ($reader['name'] AND isset($editors[$reader['name']])) {
                $ret[$r_id] = $reader['name'];
            }
        }
        return $ret;
    }

    /** Repair function
    * @return bool
    */
    function repair() {
        $this->clear_report();

        // get all readers in array: id => arrary( name => ...)
        $readers = FindReaderUsers('');
        $posted_by_found = $this->_test_field($readers, 'posted_by');
        $edited_by_found = $this->_test_field($readers, 'edited_by');
        $db = getDb();
        if (count($posted_by_found) > 0) {
            foreach ($posted_by_found as $r_id => $r_login ) {
                $SQL = "UPDATE item SET posted_by = '$r_id' WHERE posted_by = '$r_login'";
                $db->query($SQL);
                $this->message(_m('Column item.posted_by updated for %1 (id: %2).', array($r_login, $r_id)));
            }
        }
        if (count($edited_by_found) > 0) {
            foreach ($edited_by_found as $r_id => $r_login ) {
                $SQL = "UPDATE item SET edited_by = '$r_id' WHERE edited_by = '$r_login'";
                $db->query($SQL);
                $this->message(_m('Column item.edited_by updated for %1 (id: %2).', array($r_login, $r_id)));
            }
        }
        return true;
    }
}

/** There was change in Reader management functionality in AA v2.8.1 */
class AA_Optimize_Database_Structure extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Checks if all tables have right columns and indexes");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("We are time to time add new table or collumn to existing table in order we can support new features. This option will update the datastructure to the last one. No data will be lost.");
    }

    /** Name function
    * @return bool
    */
    function test() {
        $this->clear_report();
        $ret = true;  // which means OK

        $db = getDb();
        foreach ( $db->table_names() as $table ) {
            $table_name = $table['table_name'];
            $db->query("SHOW CREATE TABLE $table_name" );
            if (!$db->next_record()) {
                continue;
            }
            $table_SQL = $db->f('Create Table');

        }
        freeDb($db);
        return $ret;

    }

    /** Repair function
    * @return bool
    */
    function repair() {
        $this->clear_report();

        // get all readers in array: id => arrary( name => ...)
        $readers         = FindReaderUsers('');
        $posted_by_found = $this->_test_field($readers, 'posted_by');
        $db              = getDb();
        if (count($posted_by_found) > 0) {
            foreach ($posted_by_found as $r_id => $r_login ) {
                $SQL = "UPDATE item SET posted_by = '$r_id' WHERE posted_by = '$r_login'";
                $db->query($SQL);
                $this->message(_m('Column item.posted_by updated for %1 (id: %2).', array($r_login, $r_id)));
            }
        }
        if (count($edited_by_found) > 0) {
            foreach ($edited_by_found as $r_id => $r_login ) {
                $SQL = "UPDATE item SET edited_by = '$r_id' WHERE edited_by = '$r_login'";
                $db->query($SQL);
                $this->message(_m('Column item.edited_by updated for %1 (id: %2).', array($r_login, $r_id)));
            }
        }
        return true;
    }
}


/** Whole pagecache will be invalidated and deleted */
class AA_Optimize_Clear_Pagecache extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Clear Pagecache");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("Whole pagecache will be invalidated and deleted");
    }

    /** implemented actions within this class */
    function actions()      { return array('repair'); }

    /** Test function
    * @return bool
    */
    function test() {
        $this->message(_m('There is nothing to test.'));
        return true;
    }

    /** Deletes the pagecache - the renaming and deleting is much, much quicker,
     *  than easy DELETE FROM ...
     * @return bool
     */
    function repair() {
        $db  = getDb();
        $db->query('CREATE TABLE IF NOT EXISTS pagecache_new LIKE pagecache');
        $this->message(_m('Table pagecache_new created'));
        $db->query('CREATE TABLE IF NOT EXISTS pagecache_str2find_new LIKE pagecache_str2find');
        $this->message(_m('Table pagecache_str2find_new created'));
        $db->query('RENAME TABLE pagecache_str2find TO pagecache_str2find_bak, pagecache TO pagecache_bak');
        $this->message(_m('Renamed tables pagecache_* to pagecache_*_bak'));
        $db->query('RENAME TABLE pagecache_str2find_new TO pagecache_str2find, pagecache_new TO pagecache');
        $this->message(_m('Renamed tables pagecache_*_new to pagecache_*'));
        $db->query('DROP TABLE pagecache_str2find_bak, pagecache_bak');
        $this->message(_m('Old pagecache_*_bak tables dropped'));
        freeDb($db);
        return true;
    }
}

/** Fix inconcistency in pagecache
 *  Delete not existant keys in pagecache_str2find table
 */
class AA_Optimize_Fix_Pagecache extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Fix inconcistency in pagecache");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("Delete not existant keys in pagecache_str2find table");
    }

    /** Test function
    * @return bool
    */
    function test() {
        $row_count   = GetTable2Array("SELECT count(*) as count FROM pagecache_str2find", "aa_first", 'count');
        // $wrong_count = GetTable2Array("SELECT count(*) as count FROM pagecache_str2find LEFT JOIN pagecache ON pagecache_str2find.pagecache_id = pagecache.id WHERE pagecache.stored IS NULL", "aa_first", 'count');
        $bad_rows    = GetTable2Array("SELECT * FROM pagecache_str2find LEFT JOIN pagecache ON pagecache_str2find.pagecache_id = pagecache.id WHERE pagecache.stored IS NULL", "");
        if (!is_array($bad_rows)) {
            $bad_rows = array();
        }
        foreach ($bad_rows as $row) {
            $this->message(_m('id: %1, pagecache_id: %2, str2find: %3', array($row['id'], $row['pagecache_id'], $row['str2find'])));
        }
        $this->message(_m('We found %1 inconsistent rows from %2 in pagecache_str2find', array(count($bad_rows), $row_count)));
        // $this->message(_m('We found %1 inconsistent rows from %2 in pagecache_str2find', array($wrong_count, $row_count)));
        return true;
    }

    /** Deletes the pagecache - the renaming and deleting is much, much quicker,
     *  than easy DELETE FROM ...
     * @return bool
     */
    function repair() {
        $db  = getDb();
        $db->query('DELETE pagecache_str2find FROM pagecache_str2find LEFT JOIN pagecache ON pagecache_str2find.pagecache_id = pagecache.id WHERE pagecache.stored IS NULL');
        $this->message(_m('Inconsistent rows in pagecache_str2find removed'));
        freeDb($db);
        return true;
    }
}

/** Whole pagecache will be invalidated and deleted */
class AA_Optimize_Copy_Content extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Copy Content Table");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("Copy data for all items newer than short_id=1941629 from content table to content2 table. Used for recovery content table on Ecn server. Not usefull for any other users, I think.");
    }

    /** implemented actions within this class */
    function actions()      { return array('repair'); }

    /** Test function
    * @return a message
    */
    function test() {
        $this->message(_m('There is nothing to test.'));
        return true;
    }

    /** Deletes the pagecache - the renaming and deleting is much, much quicker,
     *  than easy DELETE FROM ...
     * @return bool
     */
    function repair() {
        $db  = getDb();

        $SQL = "INSERT INTO content2 SELECT content.* FROM content
                LEFT JOIN item on content.item_id=item.id
                WHERE item.short_id>1941629";

        /** Situation was:
         *     Content table was corrupted, so we replace i from backup. The last item in backup was short_id=1941629;
         *     After one day we found, that we restore the table from backup by wrong way, so it is corrupted for UTF slices
         *     So we decided to import old backup of content table to content2 table, and copy theer new items from content table
         *
         *
         *  First of all we insert new content, which is missing in content2 table
         *  INSERT INTO content2 SELECT content.* FROM content LEFT JOIN item on content.item_id=item.id WHERE item.short_id>1941629;
         *
         *  Then we switch from backup conten2 to content
         *  RENAME TABLE content TO contentblb, content2 TO content;
         *
         *  And now we update all content of the item, which was updated after the first switch (one day before)
         *  DELETE FROM content USING content, item WHERE content.item_id=item.id AND item.last_edit>1165360279 AND item.last_edit<1165500000 AND item.last_edit<>item.post_date AND item.short_id<1941629;
         *  INSERT INTO content SELECT contentblb.* FROM contentblb LEFT JOIN item on contentblb.item_id=item.id WHERE item.last_edit>1165360279 AND item.last_edit<1165500000 AND item.last_edit<>item.post_date AND item.short_id<1941629;
         *
         *
         *  $db->query($SQL);
        */
        $this->message(_m('Coppied'));

        freeDb($db);
        return true;
    }
}

/** Fix field table duplicate keys */
class AA_Optimize_Field_Duplicates extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Fix field definitions duplicates");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("There should be only one slice_id - field_id pair in all slices, but sometimes there are more than one (mainly because of error in former sql_update.php3 script, where more than one display_count... fields were added).");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, array('sql_update')); }

    /** Test function
    * @return bool
    */
    function test() {
        $duplicates = $this->_check_table();

        if (count($duplicates)==0) {
            $this->message(_m('No duplicates found'));
            return true;
        }
        foreach ($duplicates as $dup) {
            $this->message(_m('Duplicate in slice - field: %1 - %2', array(unpack_id($dup[0]), $dup[1])));
        }
        return false;
    }

    function repair() {
        $varset = new Cvarset;
        // $varset->setDebug();

        $duplicates = $this->_check_table();
        if (count($duplicates)==0) {
            $this->message(_m('No duplicates found'));
            return true;
        }
        $fixed = array();
        foreach ($duplicates as $dup) {
            if ( $fixed[$dup[0].$dup[1]] ) {
                // already fixed
                continue;
            }
            $fixed[$dup[0].$dup[1]] = true;

            $varset->doDeleteWhere('field', "slice_id='".quote($dup[0])."' AND id='".quote($dup[1])."'");
            $varset->resetFromRecord($dup[2]);
            $varset->doInsert('field');
            $this->message(_m('Field %2 in slice %1 fixed', array(unpack_id($dup[0]), $dup[1])));
        }
        return true;
    }

    function _check_table() {
        $fields = GetTable2Array("SELECT slice_id, id FROM field ORDER BY slice_id, id", '');

        $field_table = array();
        $duplicates  = array();
        foreach ($fields as $field) {
            $sid = $field['slice_id'];
            $fid = $field['id'];
            if (!isset($field_table[$sid])) {
                $field_table[$sid] = array();
            }
            if ( isset($field_table[$sid][$fid])) {
                $duplicates[] = array($sid, $fid, $field_table[$sid][$fid]);
            } else {
                $field_table[$sid][$fid] = $field;
            }
        }
        return $duplicates;
    }
}


/** Add tables for new polls module */
class AA_Optimize_Add_Polls extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Add Polls tables");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("Create tables for new Polls module and adds first - template polls. It removes all current polls!");
    }

    /** implemented actions within this class */
    function actions()      { return array('repair'); }

    /** Test function
    * @return a message
    */
    function test() {
        $this->message(_m('There is nothing to test.'));
        return true;
    }

    /** Deletes the pagecache - the renaming and deleting is much, much quicker,
     *  than easy DELETE FROM ...
     * @return bool
     */
    function repair() {
        $db  = getDb();

        $metabase = AA_Metabase::singleton();
        $db->query("DROP TABLE IF EXISTS `polls`");
        $db->query($metabase->getCreateSql('polls'));

        $db->query("DROP TABLE IF EXISTS `polls_answer`");
        $db->query($metabase->getCreateSql('polls_answer'));

        $db->query("DROP TABLE IF EXISTS `polls_design`");
        $db->query($metabase->getCreateSql('polls_design'));

        $db->query("DROP TABLE IF EXISTS `polls_ip_lock`");
        $db->query($metabase->getCreateSql('polls_ip_lock'));

        $db->query("DROP TABLE IF EXISTS `polls_log`");
        $db->query($metabase->getCreateSql('polls_log'));

        $db->query("REPLACE INTO module (id, name, deleted, type, slice_url, lang_file, created_at, created_by, owner, flag) VALUES ('PollsTemplate...', 'Polls Template', 0, 'P', '', 'en_polls_lang.php3', 1043151515, '', 'AA_Core.........', 0)");
        $db->query("REPLACE INTO `polls` (`id`, `module_id`, `status_code`, `headline`, `publish_date`, `expiry_date`, `locked`, `logging`, `ip_locking`, `ip_lock_timeout`, `set_cookies`, `cookies_prefix`, `design_id`, `aftervote_design_id`, `params`) VALUES('506f6c6c73467273744578616d706c65', 'PollsTemplate...', 1, 'Do you like ActionApps', 1194217200, 1500000000, 0, 0, 0, 0, 0, 'AA_POLLS', '506f6c6c7344657369676e4578616d70', '', '')");
        $db->query("REPLACE INTO `polls_answer` (`id`, `poll_id`, `answer`, `votes`, `priority`) VALUES('506f6c6c73416e73776572312e2e2e2e', '506f6c6c73467273744578616d706c65', 'Yes', 0, 1)");
        $db->query("REPLACE INTO `polls_answer` (`id`, `poll_id`, `answer`, `votes`, `priority`) VALUES('506f6c6c73416e73776572322e2e2e2e', '506f6c6c73467273744578616d706c65', 'No', 0, 2)");
        $db->query("REPLACE INTO `polls_answer` (`id`, `poll_id`, `answer`, `votes`, `priority`) VALUES('506f6c6c73416e73776572332e2e2e2e', '506f6c6c73467273744578616d706c65', 'So-so', 0, 3)");
        $db->query("REPLACE INTO `polls_design` (`id`, `module_id`, `name`, `comment`, `top`, `answer`, `bottom`) VALUES('506f6c6c7344657369676e4578616d70', 'PollsTemplate...', 'Click for vote', 'Theme for more than 2 answers, vote for it by clicking on image', '<div id=\"poll__#POLL_ID_\" class=\"aa_poll\">\r\n<table width=100%>\r\n<tr><td colspan=\"2\"><b>_#POLLQUES</b></td></tr>', '<tr>\r\n  <td width=40%>_#ANSWER__</td>\r\n  <td><a href=\"?poll_id=_#POLL_ID_&vote_id=_#ANS_ID__\"><div style=\"height:10px; width:{poll_share:500}px;background-color:#f00\">   </div>(_#ANS_PERC %)</a></td>\r\n</tr>', '</table>\r\n</div>')");

        $this->message(_m('Polls module created'));

        freeDb($db);
        return true;
    }
}


/** Updates the database structure for AA. It checks all the tables in current
 *  system and compare it with the newest database structure. The new table
 *  is created as tmp_*, then the content from old table is copied and if
 *  everything is OK, then the old table is renamed to bck_* and tmp_*
 *  is renamed to new table
 **/
class AA_Optimize_Update_Db_Structure extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Update database structure");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("[experimental] "). _m("Updates the database structure for AA. It cheks all the tables in current system and compare it with the newest database structure. The new table is created as tmp_*, then the content from old table is copied and if everything is OK, then the old table is renamed to bck_* and tmp_* is renamed to new table. (new version based on the metabase structure)");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, array('sql_update')); }

    /** Test function
    * @return a message
    */
    function test() {
        $template_metabase = AA_Metabase::singleton();
        $this_metabase     = new AA_Metabase;
        $this_metabase->loadFromDb();
        $diffs     = $template_metabase->compare($this_metabase);
        $different = false;
        foreach($diffs as $tablename => $diff) {
            if ($diff['equal']) {
                //huhl($diff);
                $this->message(_m('Tables %1 are identical.', array($tablename)));
            } else {
                $this->message(_m('Tables %1 are different: <br>Template:<br>%2<br>Current:<br>%3', array($tablename, $diff['table1'], $diff['table2'])));
                $different = true;
            }
        }
        return !$different;
    }

    /** Main update function
     *  @return bool
     */
    function repair() {
        $db                = getDb();
        $template_metabase = AA_Metabase::singleton();
        $this_metabase     = new AA_Metabase;
        $this_metabase->loadFromDb();
        $diffs     = $template_metabase->compare($this_metabase);
        $different = false;
        foreach($diffs as $tablename => $diff) {
            if ($diff['equal']) {
                $this->message(_m('Tables %1 are identical. Skipping.', array($tablename)));
                continue;
            }
            // create temporary table
            $this->message(_m('Deleting temporary table tmp_%1, if exist.', array($tablename)));
            $db->query("DROP TABLE IF EXISTS `tmp_$tablename`");
            $this->message(_m('Creating temporary table tmp_%1.', array($tablename)));
            $db->query($template_metabase->getCreateSql($tablename, 'tmp_'));

            // create new tables that do not exist in database
            // (we need it for next data copy, else if ends up with db error)
            $this->message(_m('Creating "old" data table %1 if not exists.', array($tablename)));
            $db->query($template_metabase->getCreateSql($tablename));


            // copy old data to tmp table
            $this->message(_m('copying old values to new table %1 -> tmp_%1', array($tablename)));
            $store_halt = $db->Halt_On_Error;
            $db->Halt_On_Error = "report";

            $tmp_columns = $template_metabase->getColumnNames($tablename);
            $old_columns = $this_metabase->getColumnNames($tablename);

            $matches = array_intersect($tmp_columns, $old_columns);
            if ( count($matches) > 1 ) {
                $field_list = '`'. join('`, `', $matches) .'`';
                $db->query("INSERT INTO `tmp_$tablename` ($field_list) SELECT $field_list FROM `$tablename`");
            }

            // backup table and use the new one
            $this->message(_m('backup old table %1 -> bck_%1 and use new tables instead tmp_%1 -> %1', array($tablename)));
            $db->query("DROP TABLE IF EXISTS `bck_$tablename`");
            $db->query("ALTER TABLE `$tablename` RENAME `bck_$tablename");
            $db->query("ALTER TABLE `tmp_$tablename` RENAME `$tablename");
            $db->Halt_On_Error = $store_halt;
            $this->message(_m('%1 done.', array($tablename)));
        }
    }
}

/** Recreates the "ActionApps Core" slice fields (delete and insert). The fields
 *  form the "ActionApps Core" slice is used as template fields for other slices
 **/
class AA_Optimize_Redefine_Field_Templates extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Redefine field templates");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("Updates field templates (in ActionApps Core slice), which is used when you adding new field to slice");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, array('sql_update')); }

    /** Main update function
     *  @return bool
     */
    function repair() {
        
        $now         = now();
        $AA_IMG_URL  = '/'. AA_BASE_DIR .'images/';
        $AA_DOC_URL  = '/'. AA_BASE_DIR .'doc/';

        $db  = getDb();
        
        $this->message(_m('Deleting all fields form "ActionApps Core" slice'));
        $db->query("DELETE FROM field WHERE slice_id='AA_Core_Fields..'");

        $this->message(_m('Make sure slice owner Action Aplications System exist and reset to defaults'));
        $db->query("REPLACE INTO slice_owner (id, name, email) VALUES ('AA_Core.........', 'Action Aplications System', '".ERROR_REPORTING_EMAIL."')");

        $this->message(_m('Make sure "ActionApps Core" slice exists and reset to defaults'));
        $db->query("REPLACE INTO slice (id, name, owner, deleted, created_by, created_at, export_to_all, type, template, fulltext_format_top, fulltext_format, fulltext_format_bottom, odd_row_format, even_row_format, even_odd_differ, compact_top, compact_bottom, category_top, category_format, category_bottom, category_sort, slice_url, d_listlen, lang_file, fulltext_remove, compact_remove, email_sub_enable, exclude_from_dir, notify_sh_offer, notify_sh_accept, notify_sh_remove, notify_holding_item_s, notify_holding_item_b, notify_holding_item_edit_s, notify_holding_item_edit_b, notify_active_item_edit_s, notify_active_item_edit_b, notify_active_item_s, notify_active_item_b, noitem_msg, admin_format_top, admin_format, admin_format_bottom, admin_remove, permit_anonymous_post, permit_offline_fill, aditional, flag, vid, gb_direction, group_by, gb_header, gb_case, javascript) VALUES ('AA_Core_Fields..', 'ActionApps Core', 'AA_Core_Fields..', 0, '', $now, 0, 'AA_Core_Fields..', 0, '', '',       '',                     '',             '',              0,               '',          '',             '',           '',              '',              1,             '". AA_HTTP_DOMAIN ."', 10000, 'en_news_lang.php3', '()', '()', 1, 0, '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 0, 0, '', 0, 0, NULL, NULL, NULL, NULL,'')");

        $this->message(_m('Recreate field definitions'));
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'headline',         '', 'AA_Core_Fields..', 'Headline',            '100', 'Headline', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'abstract',         '', 'AA_Core_Fields..', 'Abstract',            '189', 'Abstract', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'txt:8', '', '100', '', '', '', '', '0', '1', '1', '_#UNDEFINE', 'f_t', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '1', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'full_text',        '', 'AA_Core_Fields..', 'Fulltext',            '300', 'Fulltext', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'txt:8', '', '100', '', '', '', '', '0', '1', '1', '_#UNDEFINE', 'f_t', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '1', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'hl_href',          '', 'AA_Core_Fields..', 'Headline URL',       '1655', 'Link for the headline (for external links)', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_f:link_only.......', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'link_only',        '', 'AA_Core_Fields..', 'External item',      '1755', 'Use External link instead of fulltext?', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'chb', '', '100', '', '', '', '', '0', '0', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'bool', 'boo', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'place',            '', 'AA_Core_Fields..', 'Locality',           '2155', 'Item locality', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source',           '', 'AA_Core_Fields..', 'Source',             '1955', 'Source of the item', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source_href',      '', 'AA_Core_Fields..', 'Source URL',         '2055', 'URL of the source', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_s:javascript: window.alert(\'No source url specified\')', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'lang_code',        '', 'AA_Core_Fields..', 'Language Code',      '1700', 'Code of used language', '${AA_DOC_URL}help.html', 'txt:EN', '0', '0', '0', 'sel:lt_languages', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'cp_code',          '', 'AA_Core_Fields..', 'Code Page',          '1800', 'Language Code Page', '${AA_DOC_URL}help.html', 'txt:iso8859-1', '0', '0', '0', 'sel:lt_codepages', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'category',         '', 'AA_Core_Fields..', 'Category',           '1000', 'Category', '${AA_DOC_URL}help.html', 'txt:', '0', '0', '0', 'sel:lt_apcCategories', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'img_src',          '', 'AA_Core_Fields..', 'Image URL',          '2055', 'URL of the image', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_i', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'img_width',        '', 'AA_Core_Fields..', 'Image width',        '2455', 'Width of image (like: 100, 50%)', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_w', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'img_height',       '', 'AA_Core_Fields..', 'Image height',       '2555', 'Height of image (like: 100, 50%)', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_g', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'e_posted_by',      '', 'AA_Core_Fields..', 'Author`s e-mail',    '2255', 'E-mail to author', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'email', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'created_by',       '', 'AA_Core_Fields..', 'Created By',         '2355', 'Identification of creator', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'nul', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'uid', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'edit_note',        '', 'AA_Core_Fields..', 'Editor`s note',      '2355', 'Here you can write your note (not displayed on the web)', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'txt', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'img_upload',       '', 'AA_Core_Fields..', 'Image upload',       '2222', 'Select Image for upload', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fil:image/*', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'fil', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source_desc',      '', 'AA_Core_Fields..', 'Source description',  '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source_addr',      '', 'AA_Core_Fields..', 'Source address',      '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source_city',      '', 'AA_Core_Fields..', 'Source city',         '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source_prov',      '', 'AA_Core_Fields..', 'Source province',     '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source_cntry',     '', 'AA_Core_Fields..', 'Source country',      '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'time',             '', 'AA_Core_Fields..', 'Time',                '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '0')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'con_name',         '', 'AA_Core_Fields..', 'Contact name',        '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'con_email',        '', 'AA_Core_Fields..', 'Contact e-mail',      '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'con_phone',        '', 'AA_Core_Fields..', 'Contact phone',       '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'con_fax',          '', 'AA_Core_Fields..', 'Contact fax',         '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'loc_name',         '', 'AA_Core_Fields..', 'Location name',       '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'loc_address',      '', 'AA_Core_Fields..', 'Location address',    '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'loc_city',         '', 'AA_Core_Fields..', 'Location city',       '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'loc_prov',         '', 'AA_Core_Fields..', 'Location province',   '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'loc_cntry',        '', 'AA_Core_Fields..', 'Location country',    '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'start_date',       '', 'AA_Core_Fields..', 'Start date',          '100', '', '${AA_DOC_URL}help.html', 'now', '1', '0', '0', 'dte:1:10:1', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_d:m/d/Y', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'date', 'dte', '1', '0')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'end_date',         '', 'AA_Core_Fields..', 'End date',            '100', '', '${AA_DOC_URL}help.html', 'now', '1', '0', '0', 'dte:1:10:1', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_d:m/d/Y', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'date', 'dte', '1', '0')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'keywords',         '', 'AA_Core_Fields..', 'Keywords',            '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'subtitle',         '', 'AA_Core_Fields..', 'Subtitle',            '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'year',             '', 'AA_Core_Fields..', 'Year',                '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'number',           '', 'AA_Core_Fields..', 'Number',              '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'number', 'num', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'page',             '', 'AA_Core_Fields..', 'Page',                '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'number', 'num', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'price',            '', 'AA_Core_Fields..', 'Price',               '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'number', 'num', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'organization',     '', 'AA_Core_Fields..', 'Organization',        '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'file',             '', 'AA_Core_Fields..', 'File upload',        '2222', 'Select file for upload', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fil:*/*', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'fil', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'text',             '', 'AA_Core_Fields..', 'Text',                '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'unspecified',      '', 'AA_Core_Fields..', 'Unspecified',         '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'url',              '', 'AA_Core_Fields..', 'URL',                '2055', 'Internet URL address', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_i', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'switch',           '', 'AA_Core_Fields..', 'Switch',             '2055', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'chb', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_i', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'boo', '1', '0')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'password',         '', 'AA_Core_Fields..', 'Password',           '2055', 'Password which user must know if (s)he want to edit item on public site', '${AA_DOC_URL}help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_i', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'relation',         '', 'AA_Core_Fields..', 'Relation',           '2055', '', '', 'txt:', '0', '0', '1', 'mse:#sLiCe-4e6577735f454e5f746d706c2e2e2e2e:', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_v:vid=243&cmd[243]=x-243-_#this', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')");
        // Jakub added auth_group and mail_lists on 6.3.2003
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('auth_group......', '', 'AA_Core_Fields..', 'Auth Group',         '350', 'Sets permissions for web sections', '${AA_DOC_URL}help.html', 'txt:', 0, 0, 0, 'sel:', '', 100, '', '', '', '', 1, 1, 1, '_#AUTGROUP', 'f_h:', 'Auth Group (membership type)', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1);");
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('mail_lists......', '', 'AA_Core_Fields..', 'Mailing Lists',      '1000', 'Select mailing lists which you read', '${AA_DOC_URL}help.html', 'txt:', 0, 0, 1, 'mch::3:1', '', 100, '', '', '', '', 1, 1, 1, '_#MAILLIST', 'f_h:;&nbsp', 'Mailing Lists', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1);");
        // mimo added mlxctrl on 4.10.2004
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('mlxctrl', '', 'AA_Core_Fields..', 'MLX Control', '6000', '', 'http://mimo.gn.apc.org/mlx/', 'txt:', 1, 0, 1, 'fld', '', 100, '', '', '', '', 1, 1, 1, '', '', '', '', '', '', '', '', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 0, 1);");
        // mimo added 2005-03-02
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'integer',  '', 'AA_Core_Fields..', 'Integer',     '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'number', 'num', '1', '0')");
        // honzam added 2005-08-15 (based on Philip King and Antonin Slejska suggestions)
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'name',        '', 'AA_Core_Fields..', 'Name',            '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'qte', '1', '1')"); // name
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'phone',       '', 'AA_Core_Fields..', 'Phone',           '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'qte', '1', '1')"); // phone
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'fax',         '', 'AA_Core_Fields..', 'Fax',             '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'qte', '1', '1')"); // fax
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'address',     '', 'AA_Core_Fields..', 'Address',         '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'qte', '1', '1')"); // address
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'location',    '', 'AA_Core_Fields..', 'Location',        '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'qte', '1', '1')"); // location
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'city',        '', 'AA_Core_Fields..', 'City',            '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'qte', '1', '1')"); // city
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'country',     '', 'AA_Core_Fields..', 'Country',         '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'qte', '1', '1')"); // country
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'range',       '', 'AA_Core_Fields..', 'Range',           '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'qte', '1', '1')"); // range
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'real',        '', 'AA_Core_Fields..', 'Real number',     '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'qte', '1', '1')"); // real
        // honzam added 2005-08-26 - computed fields templates
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'computed_num','', 'AA_Core_Fields..', 'Computed number', '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'nul', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'number', 'com', '1', '0')"); // computed_num
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'computed_txt','', 'AA_Core_Fields..', 'Computed text',   '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'nul', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'com', '1', '1')"); // computed_txt
        // honzam added 2007-11-21 - _upload_url..... - slice field for setting the name of upload directory
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( '_upload_url', '', 'AA_Core_Fields..', 'Upload URL',      '100', 'If you want to have your files stored in your domain, then you can create symbolic link from http://yourdomain.org/upload -> http://your.actionapps.org/IMG_UPLOAD_PATH and fill there \"http://yourdomain.org/upload\". The url stored in AA will be changed (The file is stored still on the same place).', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '',           '',    '',                                                  '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'qte', '1', '1')");
        // honzam added 2008-07-15 - _upload_url..... - slice field for setting the name of upload directory
        $db->query("INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'seo',         '', 'AA_Core_Fields..', 'SEO',             '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '',           '',    '',                                                  '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'qte', '1', '1')");
        
        $this->message(_m('Redefine field defaults - done.'));
    }
}




/** Restore Data from Backup Tables
 *  This script DELETES all the current tables (slice, item, ...) where we have
 *  bck_table and renames all backup tables (bck_slice, bck_item, ...) to right
 *  names (slice, item, ...).
 **/
class AA_Optimize_Restore_Bck_Tables extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Restore data from backup tables");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("[experimental] "). _m("Deletes all the current tables (slice, item, ...) where we have bck_table and renames all backup tables (bck_slice, bck_item, ...) to right names (slice, item, ...).");
    }

    /** checks if the this Optimize class belongs to specified type (like "sql_update") */
    function isType($type)  { return in_array($type, array('sql_update')); }

    /** implemented actions within this class */
    function actions()      { return array('repair'); }

    /** Test function
    * @return a message
    */
    function test() {
        $this->message(_m('There is nothing to test.'));
        return true;
    }

    /** Main update function
     *  @return bool
     */
    function repair() {
        $db         = getDb();

        $metabase   = AA_Metabase::singleton();
        $tablenames = $metabase->getTableNames();
        $store_halt = $db->Halt_On_Error;
        $db->Halt_On_Error = "report";
        foreach($tablenames as $tablename) {
            // checks if the backup table exist
            $db->query("SHOW TABLES LIKE 'bck_$tablename'");
            if ( !$db->next_record() ) {
                // we do not have bck table
                $this->message(_m('There is no bck_%1 table - %1 not restored.', array($tablename)));
                continue;
            }
            $this->message(_m('Replace table bck_%1 -> %1', array($tablename)));
            $db->query("DROP TABLE IF EXISTS `$tablename`");
            $db->query("ALTER TABLE `bck_$tablename` RENAME `$tablename");
        }
        $db->Halt_On_Error = $store_halt;
    }
}


/** Creates upload directory for current slice (if not already created) **/
class AA_Optimize_Create_Upload_Dir extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Create upload directory for current slice");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("see IMG_UPLOAD_PATH parameter in config.php3 file");
    }

    /** implemented actions within this class */
    function actions()      { return array('repair'); }

    /** Test function
    * @return a message
    */
    function test() {
        $this->message(_m('There is nothing to test.'));
        return true;
    }

    /** Main update function
     *  @return bool
     */
    function repair() {
        if ($path = Files::destinationDir(AA_Slices::getSlice($GLOBALS['slice_id']))) {
            $this->message(_m('OK, %1 created', array($path)));
            return true;
        }
        $this->message(Files::lastErr());
        return false;
    }
}

?>
