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

require_once AA_BASE_PATH."service/update.optimize.class.php";

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


/** Generate metabase definition row */
class AA_Optimize_Generate_Metabase extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Generate metabase definition row");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("For programmers only - Generate metabace definition row from current database bo be placed in /service/metabase.class.php3 and /include/metabase.class.php3 scripts");
    }

    /** implemented actions within this class */
    function actions()      { return array('repair'); }

    /** Name function
    * @return bool
    */
    function repair() {
        $metabase  = new AA_Metabase;
        $metabase->loadFromDb();
        echo '$instance = unserialize(\''. str_replace("'", '\\\'', serialize($metabase)) .'\');';
        exit;
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


/** Testing if feeds table do not contain relations to non existant slices
 */
class AA_Optimize_Db_Inconsistency extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Check database consistency");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("Test content table for records without item table reference, test discussion for the same, ...");
    }

    /** Test function
    * tests for duplicate entries
    * @return bool
    */
    function test() {
        $ret = true;


        // test wrong destination slices
        $SQL = "SELECT slice_id FROM item LEFT JOIN slice ON item.slice_id=slice.id WHERE slice.id IS NULL";
        $err = GetTable2Array($SQL, '', "unpack:slice_id");
        if (is_array($err) AND count($err) > 0) {
            foreach ($err as $s_id) {
                $this->message( _m('Wrong slice id in item table: %1', array($s_id)));
            }
            $ret = false;
        }

        // test wrong destination slices
        $SQL = "SELECT item_id, text FROM content LEFT JOIN item ON content.item_id=item.id WHERE item.id IS NULL";
        $err = GetTable2Array($SQL, "unpack:item_id", 'text');
        if (is_array($err) AND count($err) > 0) {
            foreach ($err as $item_id => $text) {
                $this->message( _m('Wrong item id in content table: %1 -> %2', array($item_id, $text)));
            }
            $ret = false;
        }

        // test wrong source slices
        $SQL = "SELECT item_id, subject FROM discussion LEFT JOIN item ON discussion.item_id=item.id WHERE item.id IS NULL";
        $err = GetTable2Array($SQL, "unpack:item_id", 'subject');
        if (is_array($err) AND count($err) > 0) {
            foreach ($err as $item_id => $text) {
                $this->message( _m('Wrong item id in discussion table: %1 -> %2', array($item_id, $text)));
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

        // test wrong content records
        $SQL = "SELECT slice_id FROM item LEFT JOIN slice ON item.slice_id=slice.id WHERE slice.id IS NULL";
        $err = GetTable2Array($SQL, '', "unpack:slice_id");
        if (is_array($err) AND count($err) > 0) {
            foreach ($err as $s_id) {
                $SQL = 'DELETE FROM `item` WHERE `slice_id`=\''.q_pack_id($s_id).'\'';
                $db->query($SQL);
                $this->message( _m('Data for slice id %1 in item table deleted', array($s_id)));
            }
        }

        // test wrong content records
        $SQL = "SELECT item_id FROM content LEFT JOIN item ON content.item_id=item.id WHERE item.id IS NULL";
        $err = GetTable2Array($SQL, "", 'unpack:item_id');
        if (is_array($err) AND count($err) > 0) {
            foreach ($err as $item_id) {
                $SQL = 'DELETE FROM `content` WHERE `item_id`=\''.q_pack_id($item_id).'\'';
                $db->query($SQL);
                $this->message( _m('Data for item id %1 in content table deleted', array($item_id)));
            }
        }

        // test wrong source slices
        $SQL = "SELECT item_id FROM discussion LEFT JOIN item ON discussion.item_id=item.id WHERE item.id IS NULL";
        $err = GetTable2Array($SQL, '', "unpack:item_id");
        if (is_array($err) AND count($err) > 0) {
            foreach ($err as $item_id) {
                $SQL = 'DELETE FROM `discussion` WHERE `item_id`=\''.q_pack_id($item_id).'\'';
                $db->query($SQL);
                $this->message( _m('Data for item id %1 in discussion table deleted', array($item_id)));
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


/** Prints out the metabase row for include/metabase.class.php3 file
 *  (used by AA developers to update database definition tempate)"
 **/
class AA_Optimize_Generate_Metabase_Row extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Generate metabase PHP row");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("prints out the metabase row for include/metabase.class.php3 file (used by AA developers to update database definition tempate)");
    }

    /** implemented actions within this class */
    function actions()      { return array('repair'); }

    /** Main update function
     *  @return bool
     */
    function repair() {
        $metabase  = new AA_Metabase;
        $metabase->loadFromDb();
        echo '$instance = unserialize(\''. str_replace("'", '\\\'', serialize($metabase)) .'\');';
        exit;
    }
}

/** Set flag FLAG_TEXT_STORED for all content, where field is marked as text
 *  field, and reset it for numer fields
 **/
class AA_Optimize_Fix_Content_Column extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Set right content column for field");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("Set flag FLAG_TEXT_STORED for all content, where field is marked as text field, and reset it for numer fields");
    }

    /** implemented actions within this class */
    function actions()      { return array('test','repair'); }

    /** Test function
    * @return a message
    */
    function test() {
        $bad_rows = GetTable2Array("SELECT content.item_id, content.field_id, slice.id, slice.name FROM content INNER JOIN item ON content.item_id=item.id INNER JOIN slice ON item.slice_id=slice.id INNER JOIN field ON field.slice_id=slice.id WHERE content.field_id = field.id AND field.text_stored=1 AND (content.flag & 64) = 0",'');
        if (empty($bad_rows)) {
             $this->message(_m('No problem found, hurray'));
             return true;
        }
        $statistic = array();
        foreach ($bad_rows as $index => $row) {
            $statistic[$row['name'].' '.$row['field_id']]++;

            if ($index < 240) {
                $this->message(_m('slice %1: field_id: %2 item_id: %3', array(unpack_id($row['name'], $row['item_id']), $row['field_id'])));
            }
            if ($index == 240) {
                 $this->message(_m('and more...'));
            }
        }
        $this->message(_m('We found %1 inconsistent rows in content table', array(count($bad_rows))));
        foreach ($statistic as $field => $count) {
            $this->message("$field: $count problems");
        }
        // $this->message(_m('We found %1 inconsistent rows from %2 in pagecache_str2find', array($wrong_count, $row_count)));
        return false;
    }

    /** Main update function
     *  @return bool
     */
    function repair() {
        $db  = getDb();
        $bad_rows = GetTable2Array("SELECT content.item_id, content.field_id FROM content INNER JOIN item ON content.item_id=item.id INNER JOIN slice ON item.slice_id=slice.id INNER JOIN field ON field.slice_id=slice.id WHERE content.field_id = field.id AND field.text_stored=1 AND (content.flag & 64) = 0",'');
        foreach ($bad_rows as $index => $row) {
            $SQL = "UPDATE content SET flag = flag | 64 WHERE item_id = '".quote($row['item_id'])."' AND field_id = '".quote($row['field_id'])."'";
            $db->query($SQL);
            $this->message(_m('fixed (id-field): %1 - %2 (%3)', array(unpack_id($row['item_id']), $row['field_id'], $SQL)));
            if ($index > 240) {
                 $this->message(_m('and more...'));
            }
        }
        $this->message(_m('We fixed %1 inconsistent rows in content table', array(count($bad_rows))));
        // $this->message(_m('We found %1 inconsistent rows from %2 in pagecache_str2find', array($wrong_count, $row_count)));
        freeDb($db);
        return true;
    }
}


/** Delete discussion comments for not existing items
 */
class AA_Optimize_Item_Discussion extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Delete discussion comments for not existing items");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("");
    }

    /** Test function
    * tests for duplicate entries
    * @return bool
    */
    function test() {
        $SQL      = 'SELECT count(*) as disc_count, item_id FROM `discussion` LEFT JOIN item ON discussion.item_id = item.id WHERE item.short_id IS NULL GROUP BY discussion.item_id';
        $problems = GetTable2Array($SQL, "unpack:item_id", 'disc_count');
        if ($problems == false) {
            $this->message( _m('No problems found') );
            return true;
        }
        foreach ($problems as $item_id => $disc_count) {
            $this->message(_m('Problem for item %1 - %2 comments found', array($item_id, $disc_count)));
        }
        return false;
    }

    /** Repair the problem
    * @return bool
    */
    function repair() {
        $db       = getDb();
        $SQL      = 'SELECT count(*) as disc_count, item_id FROM `discussion` LEFT JOIN item ON discussion.item_id = item.id WHERE item.short_id IS NULL GROUP BY discussion.item_id';
        $problems = GetTable2Array($SQL, "", 'item_id');
        if (count((array)$problems) < 1) {
            $this->message( _m('No problems found') );
            return true;
        }
        $SQL = 'DELETE FROM `discussion` WHERE '. Cvarset::sqlin('item_id', $problems);
        $db->query($SQL);
        $SQL = "DELETE FROM `discussion` WHERE item_id = ''";
        $db->query($SQL);
        $this->message(_m('%1 problems solved', array(count($problems))));
        freeDb($db);
        return true;
    }
}

/**
 */
class AA_Optimize_Multivalue_Duplicates extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Multivalue Duplicates");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("Removes duplicate values in multivalue text fields");
    }

    /** Test function
    * tests for duplicate entries
    * @return bool
    */
    function test() {
        $ret = true;

        // test wrong destination slices
        $SQL      = "SELECT `item_id`, `field_id`, `text`, `number`, count(*) AS `cnt` FROM `content` WHERE (flag && 64) GROUP BY `item_id`, `field_id`, `text`, `number` HAVING `cnt` >1";
        $err_text = GetTable2Array($SQL, '', 'aa_fields');

        if (is_array($err_text) AND count($err_text) > 0) {
            $this->message( _m('%1 duplicates found in text fields', array(count($err_text))));
            foreach ($err_text as $wrong) {
                $this->message( _m('Duplicates (%4) in item %1 - field %2 - value %3', array(unpack_id($wrong['item_id']),$wrong['field_id'],$wrong['text'],$wrong['cnt'])));
            }
            $ret = false;
        }

        $SQL      = "SELECT `item_id`, `field_id`, `number`, count(*) AS `cnt` FROM `content` WHERE (flag && 64) = 0 GROUP BY `item_id`, `field_id`, `number` HAVING `cnt` >1";
        $err_num  = GetTable2Array($SQL, '', 'aa_fields');

        if (is_array($err_num) AND count($err_num) > 0) {
            $this->message( _m('%1 duplicates found in numeric fields', array(count($err_num))));
            foreach ($err_num as $wrong) {
                $this->message( _m('Duplicates (%4) in item %1 - field %2 - value %3', array(unpack_id($wrong['item_id']),$wrong['field_id'],$wrong['number'],$wrong['cnt'])));
            }
            $ret = false;
        }

        if ($ret ) {
            $this->message(_m('No duplicates found, hurray!'));
        }
        return $ret;
    }

    /** Name function
    * @return bool
    */
    function repair() {
        $db  = getDb();

        $ret = true;

        // test wrong destination slices
        $SQL      = "SELECT `item_id`, `field_id`, `text`, count(*) AS `cnt` FROM `content` WHERE (flag && 64) GROUP BY `item_id`, `field_id`, `text` HAVING `cnt` >1";
        $err_text = GetTable2Array($SQL, '', 'aa_fields');

        if (is_array($err_text) AND count($err_text) > 0) {
            $this->message( _m('%1 duplicates found in text fields', array(count($err_text))));
            foreach ($err_text as $wrong) {
                $SQL = "DELETE FROM `content` WHERE item_id='".quote($wrong['item_id'])."' AND field_id='".quote($wrong['field_id'])."' AND text='".quote($wrong['text'])."' LIMIT ".($wrong['cnt']-1);
                $db->query($SQL);
                $this->message($SQL);
            }
            $ret = false;
        }

        $SQL      = "SELECT `item_id`, `field_id`, `number`, count(*) AS `cnt` FROM `content` WHERE (flag && 64) = 0 GROUP BY `item_id`, `field_id`, `number` HAVING `cnt` >1";
        $err_num  = GetTable2Array($SQL, '', 'aa_fields');

        if (is_array($err_num) AND count($err_num) > 0) {
            $this->message( _m('%1 duplicates found in numeric fields', array(count($err_num))));
            foreach ($err_num as $wrong) {
                $SQL = "DELETE FROM `content` WHERE item_id='".quote($wrong['item_id'])."' AND field_id='".quote($wrong['field_id'])."' AND number='".quote($wrong['number'])."' LIMIT ".($wrong['cnt']-1);
                $db->query($SQL);
                $this->message($SQL);
            }
            $ret = false;
        }

        if ($ret ) {
            $this->message(_m('No duplicates found, hurray!'));
        }
        return $ret;
    }
}



/** Set Expiry date to maximum value for items in slices, where expiry_date.....
 *  field is not shown. It also sets the field's default to that value.
 */
class AA_Optimize_Set_Expirydate extends AA_Optimize {

    /** Name function
    * @return a message
    */
    function name() {
        return _m("Set Expiry date to maximum value");
    }

    /** Description function
    * @return a message
    */
    function description() {
        return _m("Set Expiry date to maximum value for items in slices, where expiry_date..... field is not shown. It also sets the field's default to that value.<br><b>Is it really what you want?</b>");
    }

    /** Test function
    * @return bool
    */
    function test() {

        $slices   = GetTable2Array("SELECT slice_id, input_default FROM `field` WHERE id='expiry_date.....' AND input_show=0", "unpack:slice_id", 'input_default');
        foreach ($slices as $sid => $default) {
            $this->message(_m('slice id: %1 (%2), default value for expiry_date.....: %3', array($sid, AA_Slices::getName($sid), $default)));
        }
        $this->message(_m('We found %1 hidden exipry_date..... fields', array(count($slices))));
        // $this->message(_m('We found %1 inconsistent rows from %2 in pagecache_str2find', array($wrong_count, $row_count)));
        return true;
    }

    /** Deletes the pagecache - the renaming and deleting is much, much quicker,
     *  than easy DELETE FROM ...
     * @return bool
     */
    function repair() {
        $db  = getDb();
        $slices   = GetTable2Array("SELECT slice_id, input_default FROM `field` WHERE id='expiry_date.....' AND input_show=0", "unpack:slice_id", 'input_default');
        foreach ($slices as $sid => $default) {
            $db->query("UPDATE item SET expiry_date=2145826800 WHERE slice_id='".q_pack_id($sid)."'");
            //huhl("UPDATE item SET expiry_date=2145826800 WHERE slice_id='".q_pack_id($sid)."'");
            $this->message(_m('items changed: %1 in slice: %2 (%3)', array($db->affected_rows(), $sid, AA_Slices::getName($sid))));

            $db->query("UPDATE field SET input_default='txt:2145826800' WHERE id='expiry_date.....' AND slice_id='".q_pack_id($sid)."'");
            //huhl("UPDATE field SET input_default='txt:2145826800' WHERE id='expiry_date.....' AND slice_id='".q_pack_id($sid)."'");
            $this->message(_m('field default changed for expiry_date..... in %1 (%2)', array($sid, AA_Slices::getName($sid))));
        }
        freeDb($db);
        return true;
    }
}
?>
