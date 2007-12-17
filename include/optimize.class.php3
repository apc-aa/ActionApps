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
        $this->_fixTable('constant','id','varbinary(16) NOT NULL default \'\'');
        $this->_fixTable('constant','group_id','varbinary(16) NOT NULL default \'\'');
        $this->_fixTable('constant','class','varbinary(16) default NULL');
        $this->_fixTable('alerts_collection','module_id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('alerts_collection','slice_id',"varbinary(16) default NULL");
        $this->_fixTable('alerts_collection_filter','collectionid',"varbinary(6) NOT NULL default ''");
        $this->_fixTable('alerts_collection_howoften','collectionid',"varbinary(6) NOT NULL default ''");
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
        $db->query('CREATE TABLE pagecache_new LIKE pagecache');
        $this->message(_m('Table pagecache_new created'));
        $db->query('CREATE TABLE pagecache_str2find_new LIKE pagecache_str2find');
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
        $fields = GetTable2Array("SELECT * FROM field ORDER BY slice_id, id", '');

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


/** Updates the database structure for AA. It cheks all the tables in current
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
        return _m("Updates the database structure for AA. It cheks all the tables in current system and compare it with the newest database structure. The new table is created as tmp_*, then the content from old table is copied and if everything is OK, then the old table is renamed to bck_* and tmp_* is renamed to new table");
    }

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
                $db->query("INSERT INTO `tmp_$tablename` SELECT $field_list FROM `$tablename`");
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
        return _m("Deletes all the current tables (slice, item, ...) where we have bck_table and renames all backup tables (bck_slice, bck_item, ...) to right names (slice, item, ...).");
    }

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

?>
