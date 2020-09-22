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
 * @package   Maintain
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
*/

require_once __DIR__."/../service/update.optimize.class.php";

/** Testing if relation table contain records, where values in both columns are
 *  identical (which was bug fixed in Jan 2006)
 */
class AA_Optimize_Category_Sort2group_By extends AA_Optimize {

    /** Name function
    * @return string - name
    */
    public function name(): string {
        return _m("Convert slice.category_sort to slice.group_by");
    }

    /** Description function
    * @return string - description
    */
    public function description(): string {
        return _m("In older version of AA we used just category fields for grouping items. Now it is universal, so boolean category_sort is not enough. We use newer group_by field for quite long time s most probably all your slices are already conevrted.");
    }

    /** Test function
    * @return bool
    */
    public function test() : bool {
        $SQL         = "SELECT name FROM slice WHERE category_sort>0 AND ((group_by IS NULL) OR (group_by=''))";
        $slice_names = GetTable2Array($SQL, '', 'name');
        if ($slice_names AND (count($slice_names) > 0)) {
            $this->message( _m('%1 slices are not converted', [count($slice_names)]). '<br> &nbsp; '. join('<br> &nbsp; ',$slice_names));
            return false;
        }
        $this->message(_m('All slices are already converted'));
        return true;
    }

    /** Repair function
    * runs a series of SQL commands
    * @return bool
    */
    public function repair() : bool {
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
    * @return string - name
    */
    public function name(): string {
        return _m("Relation table duplicate records");
    }

    /** Description function
    * @return string - description
    */
    public function description(): string {
        return _m("Testing if relation table contain records, where values in both columns are identical (which was bug fixed in Jan 2006)");
    }

    /** Test function
    * tests for duplicate entries
    * @return bool
    */
    public function test() : bool {
        $SQL       = 'SELECT count(*) as err_count FROM `relation` WHERE `source_id`=`destination_id`';
        $err_count = GetTable2Array($SQL, "aa_first", 'err_count');
        if ($err_count > 0) {
            $this->message( _m('%1 duplicates found', [$err_count]) );
            return false;
        }
        $this->message(_m('No duplicates found'));
        return true;
    }

    /** Name function
    * @return bool
    */
    public function repair() : bool {
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
    * @return string - a name
    */
    public function name(): string {
        return _m("Feeds table inconsistent records");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("Testing if feeds table do not contain relations to non existant slices (after slice deletion)");
    }

    /** Test function
    * tests for duplicate entries
    * @return bool
    */
    public function test() : bool {
        $ret = true;

        // test wrong destination slices
        $SQL = "SELECT from_id,to_id FROM feeds LEFT JOIN slice ON feeds.to_id=slice.id
                WHERE slice.id IS NULL";
        $err = GetTable2Array($SQL, "unpack:from_id", 'unpack:to_id');
        if (is_array($err) AND count($err) > 0) {
            foreach ($err as $from_id => $to_id) {
                $this->message( _m('Wrong destination slice id: %1 -> %2', [AA_Slice::getModuleName($from_id), $to_id]));
            }
            $ret = false;
        }

        // test wrong source slices
        $SQL = "SELECT from_id,to_id FROM feeds LEFT JOIN slice ON feeds.from_id=slice.id
                WHERE slice.id IS NULL";
        $err = GetTable2Array($SQL, "unpack:from_id", 'unpack:to_id');
        if (is_array($err) AND count($err) > 0) {
            foreach ($err as $from_id => $to_id) {
                $this->message( _m('Wrong source slice id: %1 -> %2', [$from_id, AA_Slice::getModuleName($to_id)]));
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
    public function repair() : bool {
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
    * @return string - a name
    */
    public function name(): string {
        return _m("Check database consistency");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("Test content table for records without item table reference, test discussion for the same, ...");
    }

    /** Test function
    * tests for duplicate entries
    * @return bool
    */
    public function test() : bool {
        $ret = true;


        // test wrong destination slices
        $SQL = "SELECT slice_id FROM item LEFT JOIN slice ON item.slice_id=slice.id WHERE slice.id IS NULL";
        $err = GetTable2Array($SQL, '', "unpack:slice_id");
        if (is_array($err) AND count($err) > 0) {
            foreach ($err as $s_id) {
                $this->message( _m('Wrong slice id in item table: %1', [$s_id]));
            }
            $ret = false;
        }

        // test wrong destination slices
        $SQL = "SELECT item_id, text FROM content LEFT JOIN item ON content.item_id=item.id WHERE item.id IS NULL";
        $err = GetTable2Array($SQL, "unpack:item_id", 'text');
        if (is_array($err) AND count($err) > 0) {
            foreach ($err as $item_id => $text) {
                $this->message( _m('Wrong item id in content table: %1 -> %2', [$item_id, $text]));
            }
            $ret = false;
        }

        // test wrong source slices
        $SQL = "SELECT item_id, subject FROM discussion LEFT JOIN item ON discussion.item_id=item.id WHERE item.id IS NULL";
        $err = GetTable2Array($SQL, "unpack:item_id", 'subject');
        if (is_array($err) AND count($err) > 0) {
            foreach ($err as $item_id => $text) {
                $this->message( _m('Wrong item id in discussion table: %1 -> %2', [$item_id, $text]));
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
    public function repair() : bool {
        $db  = getDb();

        // test wrong content records
        $SQL = "SELECT slice_id FROM item LEFT JOIN slice ON item.slice_id=slice.id WHERE slice.id IS NULL";
        $err = GetTable2Array($SQL, '', "unpack:slice_id");
        if (is_array($err) AND count($err) > 0) {
            foreach ($err as $s_id) {
                $SQL = 'DELETE FROM `item` WHERE `slice_id`=\''.q_pack_id($s_id).'\'';
                $db->query($SQL);
                $this->message( _m('Data for slice id %1 in item table deleted', [$s_id]));
            }
        }

        // test wrong content records
        $SQL = "SELECT item_id FROM content LEFT JOIN item ON content.item_id=item.id WHERE item.id IS NULL";
        $err = GetTable2Array($SQL, "", 'unpack:item_id');
        if (is_array($err) AND count($err) > 0) {
            foreach ($err as $item_id) {
                $SQL = 'DELETE FROM `content` WHERE `item_id`=\''.q_pack_id($item_id).'\'';
                $db->query($SQL);
                $this->message( _m('Data for item id %1 in content table deleted', [$item_id]));
            }
        }

        // test wrong source slices
        $SQL = "SELECT item_id FROM discussion LEFT JOIN item ON discussion.item_id=item.id WHERE item.id IS NULL";
        $err = GetTable2Array($SQL, '', "unpack:item_id");
        if (is_array($err) AND count($err) > 0) {
            foreach ($err as $item_id) {
                $SQL = 'DELETE FROM `discussion` WHERE `item_id`=\''.q_pack_id($item_id).'\'';
                $db->query($SQL);
                $this->message( _m('Data for item id %1 in discussion table deleted', [$item_id]));
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
class AA_Optimize_Db_Binary_Trailing_Zeros extends AA_Optimize {

    /** Name function
    * @return string - a name
    */
    public function name(): string {
        return _m("Fix user login problem, constants editing problem, ...");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("Replaces binary fields by varbinary and removes trailing zeros. Needed for MySQL > 5.0.17");
    }

    /** implemented actions within this class */
    function actions()      { return ['repair']; }

    /** Test function
    * @return true
    */
    public function test() : bool {
        return true;
    }

    /** Repair function
    * repairs tables
    * @return true
    */
    public function repair() : bool {
        $this->_fixTable('change',            'id',                    "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('change',            'resource_id',           "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('change_record',     'change_id',             "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('change_record',     'selector',              "varbinary(255) default NULL");
        $this->_fixTable('central_conf',      'dns_conf',              "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf',      'dns_web',               "varbinary(15)  NOT NULL default ''");
        $this->_fixTable('central_conf',      'dns_mx',                "varbinary(15)  NOT NULL default ''");
        $this->_fixTable('central_conf',      'dns_db',                "varbinary(15)  NOT NULL default ''");
        $this->_fixTable('central_conf',      'dns_prim',              "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf',      'dns_sec',               "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf',      'web_conf',              "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf',      'web_path',              "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf',      'db_server',             "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf',      'db_name',               "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf',      'db_user',               "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf',      'db_pwd',                "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf',      'AA_SITE_PATH',          "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf',      'AA_BASE_DIR',           "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf',      'AA_HTTP_DOMAIN',        "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf',      'AA_ID',                 "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('central_conf',      'ORG_NAME',              "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf',      'ERROR_REPORTING_EMAIL', "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf',      'ALERTS_EMAIL',          "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf',      'IMG_UPLOAD_URL',        "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf',      'IMG_UPLOAD_PATH',       "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf',      'FILEMAN_BASE_DIR',      "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf',      'FILEMAN_BASE_URL',      "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf',      'AA_ADMIN_USER',         "varbinary(30)  NOT NULL default ''");
        $this->_fixTable('central_conf',      'AA_ADMIN_PWD',          "varbinary(30)  NOT NULL default ''");
        $this->_fixTable('content',           'item_id',               "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('content',           'field_id',              "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('discussion',        'id',                    "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('discussion',        'parent',                "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('discussion',        'item_id',               "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('ef_categories',     'category_id',           "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('ef_categories',     'target_category_id',    "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('ef_permissions',    'slice_id',              "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('email',             'owner_module_id',       "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('external_feeds',    'slice_id',              "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('external_feeds',    'remote_slice_id',       "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('event',             'id',                    "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('feedmap',           'from_slice_id',         "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('feedmap',           'from_field_id',         "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('feedmap',           'to_slice_id',           "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('feedmap',           'to_field_id',           "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('feedperms',         'from_id',               "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('feedperms',         'to_id',                 "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('feeds',             'from_id',               "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('feeds',             'to_id',                 "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('feeds',             'category_id',           "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('feeds',             'to_category_id',        "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('field',             'id',                    "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('field',             'slice_id',              "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('field',             'content_id',            "varbinary(16)  default NULL");
        $this->_fixTable('jump',              'slice_id',              "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('jump',              'dest_slice_id',         "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('object_float',      'object_id',             "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('object_float',      'property',              "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('object_integer',    'object_id',             "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('object_integer',    'property',              "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('object_text',       'object_id',             "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('object_text',       'property',              "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('pagecache',         'id',                    "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('pagecache_str2find','pagecache_id',          "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('polls',             'id',                    "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('polls',             'module_id',             "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('polls',             'design_id',             "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('polls',             'aftervote_design_id',   "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('polls_answer',      'id',                    "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('polls_answer',      'poll_id',               "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('polls_design',      'id',                    "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('polls_ip_lock',     'poll_id',               "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('polls_log',         'answer_id',             "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('polls_design',      'module_id',             "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('polls_ip_lock',     'voters_ip',             "varbinary(16)  NOT NULL");
        $this->_fixTable('polls_log',         'voters_ip',             "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('post2shtml',        'id',                    "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('profile',           'slice_id',              "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('relation',          'source_id',             "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('relation',          'destination_id',        "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('rssfeeds',          'slice_id',              "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('site',              'id',                    "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('site_spot',         'site_id',               "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('slice',             'id',                    "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('slice',             'type',                  "varbinary(16)  default NULL");
        $this->_fixTable('slice',             'mlxctrl',               "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('view',              'slice_id',              "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('view',              'order1',                "varbinary(16)  default NULL");
        $this->_fixTable('view',              'order2',                "varbinary(16)  default NULL");
        $this->_fixTable('view',              'group_by1',             "varbinary(16)  default NULL");
        $this->_fixTable('view',              'group_by2',             "varbinary(16)  default NULL");
        $this->_fixTable('view',              'cond1field',            "varbinary(16)  default NULL");
        $this->_fixTable('view',              'cond1op',               "varbinary(10)  default NULL");
        $this->_fixTable('view',              'cond2field',            "varbinary(16)  default NULL");
        $this->_fixTable('view',              'cond2op',               "varbinary(10)  default NULL");
        $this->_fixTable('view',              'cond3field',            "varbinary(16)  default NULL");
        $this->_fixTable('view',              'cond3op',               "varbinary(10)  default NULL");
        $this->_fixTable('view',              'field1',                "varbinary(16)  default NULL");
        $this->_fixTable('view',              'field2',                "varbinary(16)  default NULL");
        $this->_fixTable('view',              'field3',                "varbinary(16)  default NULL");


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
        $this->_fixTable('membership','memberid','varbinary(32)  NOT NULL');
        $this->_fixTable('module','id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('module','owner',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('module','app_id',"varbinary(16) default NULL");
        $this->_fixTable('offline','id',"varbinary(16) NOT NULL default ''");
        $this->_fixTable('offline','digest',"varbinary(32)  NOT NULL default ''");
        $this->_fixTable('perms','objectid',"varbinary(32)  NOT NULL default ''");
        $this->_fixTable('perms','userid',"varbinary(32)  NOT NULL default '0'");
        $this->_fixTable('perms','perm',"varbinary(32)  NOT NULL default ''");
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
    * @return string - a name
    */
    public function name(): string {
        return _m("Convert Readers login to reader id");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("There was change in Reader management functionality in AA v2.8.1, so readers are not internaly identified by its login, but by reader ID (item ID of reader in Reader slice). This is much more powerfull - you can create relations just as in normal slice. It works well without any change. The only problem is, if you set any slice to be editable by users from Reader slice. In that case the fields edited_by........ and posted_by........ are filled by readers login instead of reader id. You can fix it by \"Repair\".");
    }

    /** Test function
    * @return bool
    */
    public function test() : bool {
        $this->clear_report();
        $ret = true;  // which means OK

        // get all readers in array: id => arrary( name => ...)
        $perm_reader     = new AA_Permsystem_Reader();
        $readers         = $perm_reader->findUsernames('');
        $posted_by_found = $this->_test_field($readers, 'posted_by');
        if (count($posted_by_found) > 0) {
            $this->message(_m('%1 login names from reader slice found as records in item.posted_by which is wrong (There should be reader ID from AA v2.8.1). "Repair" will correct it.', [count($posted_by_found)]));
            $ret = false;
        }
        $edited_by_found = $this->_test_field($readers, 'edited_by');
        if (count($edited_by_found) > 0) {
            $this->message(_m('%1 login names from reader slice found as records in item.edited_by which is wrong (There should be reader ID from AA v2.8.1). "Repair" will correct it.', [count($edited_by_found)]));
            $ret = false;
        }
        return $ret;
    }

    /** test if we can find an item which was edited by reader and is identified
     *  by login name (instead of item_id)
     *  @return array of such users
     */
    function _test_field($readers, $item_field) {
        // get posted_by, edit_by, ... array:  posted_by => 1
        $SQL     = "SELECT DISTINCT $item_field FROM item";
        $editors = GetTable2Array($SQL, $item_field, 'aa_mark');
        $ret     = [];
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
    public function repair() : bool {
        $this->clear_report();

        // get all readers in array: id => arrary( name => ...)
        $perm_reader     = new AA_Permsystem_Reader();
        $readers         = $perm_reader->findUsernames('');
        $posted_by_found = $this->_test_field($readers, 'posted_by');
        $edited_by_found = $this->_test_field($readers, 'edited_by');
        $db = getDb();
        if (count($posted_by_found) > 0) {
            foreach ($posted_by_found as $r_id => $r_login ) {
                $SQL = "UPDATE item SET posted_by = '$r_id' WHERE posted_by = '$r_login'";
                $db->query($SQL);
                $this->message(_m('Column item.posted_by updated for %1 (id: %2).', [$r_login, $r_id]));
            }
        }
        if (count($edited_by_found) > 0) {
            foreach ($edited_by_found as $r_id => $r_login ) {
                $SQL = "UPDATE item SET edited_by = '$r_id' WHERE edited_by = '$r_login'";
                $db->query($SQL);
                $this->message(_m('Column item.edited_by updated for %1 (id: %2).', [$r_login, $r_id]));
            }
        }
        return true;
    }
}

/** There was change in Reader management functionality in AA v2.8.1 */
class AA_Optimize_Database_Structure extends AA_Optimize {

    /** Name function
    * @return string - a name
    */
    public function name(): string {
        return _m("Checks if all tables have right columns and indexes");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("We are time to time add new table or column to existing table in order we can support new features. This option will update the datastructure to the last one. No data will be lost.");
    }

    /** Name function
    * @return bool
    */
    public function test() : bool {
        $this->clear_report();
        $ret = true;  // which means OK

        $db = getDb();
        foreach ( $db->table_names() as $table ) {
            $table_name = $table['table_name'];
            $db->query("SHOW CREATE TABLE $table_name" );
            if (!$db->next_record()) {
                continue;
            }
        }
        freeDb($db);
        return $ret;

    }

    /** Repair function
    * @return bool
    */
    public function repair() : bool { return true; }
}


/** Whole pagecache will be invalidated and deleted */
class AA_Optimize_Clear_Pagecache extends AA_Optimize {

    /** Name function
    * @return string - a name
    */
    public function name(): string {
        return _m("Clear Pagecache");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("Whole pagecache will be invalidated and deleted");
    }

    /** implemented actions within this class */
    function actions()      { return ['repair']; }

    /** Test function
    * @return bool
    */
    public function test() : bool {
        $this->message(_m('There is nothing to test.'));
        return true;
    }

    /** Deletes the pagecache - the renaming and deleting is much, much quicker,
     *  than easy DELETE FROM ...
     * @return bool
     */
    public function repair() : bool {
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
    * @return string - a name
    */
    public function name(): string {
        return _m("Fix inconcistency in pagecache");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("Delete not existant keys in pagecache_str2find table");
    }

    /** Test function
    * @return bool
    */
    public function test() : bool {
        $row_count   = GetTable2Array("SELECT count(*) as count FROM pagecache_str2find", "aa_first", 'count');
        // $wrong_count = GetTable2Array("SELECT count(*) as count FROM pagecache_str2find LEFT JOIN pagecache ON pagecache_str2find.pagecache_id = pagecache.id WHERE pagecache.stored IS NULL", "aa_first", 'count');
        $bad_rows    = GetTable2Array("SELECT * FROM pagecache_str2find LEFT JOIN pagecache ON pagecache_str2find.pagecache_id = pagecache.id WHERE pagecache.stored IS NULL", "");
        if (!is_array($bad_rows)) {
            $bad_rows = [];
        }
        foreach ($bad_rows as $row) {
            $this->message(_m('id: %1, pagecache_id: %2, str2find: %3', [$row['id'], $row['pagecache_id'], $row['str2find']]));
        }
        $this->message(_m('We found %1 inconsistent rows from %2 in pagecache_str2find', [count($bad_rows), $row_count]));
        // $this->message(_m('We found %1 inconsistent rows from %2 in pagecache_str2find', array($wrong_count, $row_count)));
        return true;
    }

    /** Deletes the pagecache - the renaming and deleting is much, much quicker,
     *  than easy DELETE FROM ...
     * @return bool
     */
    public function repair() : bool {
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
    * @return string - a name
    */
    public function name(): string {
        return _m("Copy Content Table");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("Copy data for all items newer than short_id=1941629 from content table to content2 table. Used for recovery content table on Ecn server. Not usefull for any other users, I think.");
    }

    /** implemented actions within this class */
    function actions()      { return ['repair']; }

    /** Test function
    * @return string - a message
    */
    public function test() : bool {
        $this->message(_m('There is nothing to test.'));
        return true;
    }

    /** Deletes the pagecache - the renaming and deleting is much, much quicker,
     *  than easy DELETE FROM ...
     * @return bool
     */
    public function repair() : bool {
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
    * @return string - a name
    */
    public function name(): string {
        return _m("Create upload directory for current slice");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("see IMG_UPLOAD_PATH parameter in config.php3 file");
    }

    /** implemented actions within this class */
    function actions()      { return ['repair']; }

    /** Test function
    * @return string - a message
    */
    public function test() : bool {
        $this->message(_m('There is nothing to test.'));
        return true;
    }

    /** Main update function
     *  @return bool
     */
    public function repair() : bool {
        if ($path = Files::destinationDir(AA_Slice::getModule($GLOBALS['slice_id']))) {
            $this->message(_m('OK, %1 created', [$path]));
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
    * @return string - a name
    */
    public function name(): string {
        return _m("Generate metabase PHP row");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("prints out the metabase row for include/metabase.class.php3 file (used by AA developers to update database definition tempate)");
    }

    /** implemented actions within this class */
    function actions()      { return ['repair']; }

    /** Main update function
     *  @return bool
     */
    public function repair() : bool {
        $metabase  = new AA_Metabase;
        $metabase->loadFromDb();
        echo '$instance = unserialize(\''. str_replace("'", '\\\'', serialize($metabase)) .'\');';
        exit;
    }
}

/** Prints out the JS library definition array used in include/stringexpand.php for update to last version of libs on jsDeliver CDN
 *  (used by AA developers to update js libs in AA)"
 **/
class AA_Optimize_Generate_Jslibs_Array extends AA_Optimize {

    /** Name function
     * @return string message
     */
    public function name(): string {
        return _m("Generate JS Library definition array");
    }

    /** Description function
     * @return string message
     */
    public function description(): string {
        return _m("Prints out the JS library definition array used in include/stringexpand.php for update to last version of libs on jsDeliver CDN (used by AA developers to update js libs in AA)");
    }

    /** implemented actions within this class */
    function actions()      { return ['repair']; }

    /** Main update function
     *  @return bool
     */
    public function repair() : bool {
        $libupdater = new \AA\Util\LibUpdater();
        $this->message($libupdater->getUpdatedsystemDefinitions());
        return true;
    }
}


/** Set flag FLAG_TEXT_STORED for all content, where field is marked as text
 *  field, and reset it for numer fields
 **/
class AA_Optimize_Fix_Content_Column extends AA_Optimize {

    /** Name function
    * @return string - a name
    */
    public function name(): string {
        return _m("Set right content column for field");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("Set flag FLAG_TEXT_STORED for all content, where field is marked as text field, and reset it for numer fields");
    }

    /** implemented actions within this class */
    function actions()      { return ['test','repair']; }

    /** Test function
    * @return string - a message
    */
    public function test() : bool {
        $bad_rows = GetTable2Array("SELECT content.item_id, content.field_id, slice.id, slice.name FROM content INNER JOIN item ON content.item_id=item.id INNER JOIN slice ON item.slice_id=slice.id INNER JOIN field ON field.slice_id=slice.id WHERE content.field_id = field.id AND field.text_stored=1 AND (content.flag & 64) = 0",'');
        if (empty($bad_rows)) {
             $this->message(_m('No problem found, hurray'));
             return true;
        }
        $statistic = [];
        foreach ($bad_rows as $index => $row) {
            $statistic[$row['name'].' '.$row['field_id']]++;

            if ($index < 240) {
                $this->message(_m('slice %1: field_id: %2 item_id: %3', [unpack_id($row['name'], $row['item_id']), $row['field_id']]));
            }
            if ($index == 240) {
                 $this->message(_m('and more...'));
            }
        }
        $this->message(_m('We found %1 inconsistent rows in content table', [count($bad_rows)]));
        foreach ($statistic as $field => $count) {
            $this->message("$field: $count problems");
        }
        // $this->message(_m('We found %1 inconsistent rows from %2 in pagecache_str2find', array($wrong_count, $row_count)));
        return false;
    }

    /** Main update function
     *  @return bool
     */
    public function repair() : bool {
        $db  = getDb();
        $bad_rows = GetTable2Array("SELECT content.item_id, content.field_id FROM content INNER JOIN item ON content.item_id=item.id INNER JOIN slice ON item.slice_id=slice.id INNER JOIN field ON field.slice_id=slice.id WHERE content.field_id = field.id AND field.text_stored=1 AND (content.flag & 64) = 0",'');
        foreach ($bad_rows as $index => $row) {
            $SQL = "UPDATE content SET flag = flag | 64 WHERE item_id = '".quote($row['item_id'])."' AND field_id = '".quote($row['field_id'])."'";
            $db->query($SQL);
            $this->message(_m('fixed (id-field): %1 - %2 (%3)', [unpack_id($row['item_id']), $row['field_id'], $SQL]));
            if ($index > 240) {
                 $this->message(_m('and more...'));
            }
        }
        $this->message(_m('We fixed %1 inconsistent rows in content table', [count($bad_rows)]));
        // $this->message(_m('We found %1 inconsistent rows from %2 in pagecache_str2find', array($wrong_count, $row_count)));
        freeDb($db);
        return true;
    }
}


/** Delete discussion comments for not existing items
 */
class AA_Optimize_Item_Discussion extends AA_Optimize {

    /** Name function
    * @return string - a name
    */
    public function name(): string {
        return _m("Delete discussion comments for not existing items");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("");
    }

    /** Test function
    * tests for duplicate entries
    * @return bool
    */
    public function test() : bool {
        $SQL      = 'SELECT count(*) as disc_count, item_id FROM `discussion` LEFT JOIN item ON discussion.item_id = item.id WHERE item.short_id IS NULL GROUP BY discussion.item_id';
        $problems = GetTable2Array($SQL, "unpack:item_id", 'disc_count');
        if ($problems == false) {
            $this->message( _m('No problems found') );
            return true;
        }
        foreach ($problems as $item_id => $disc_count) {
            $this->message(_m('Problem for item %1 - %2 comments found', [$item_id, $disc_count]));
        }
        return false;
    }

    /** Repair the problem
    * @return bool
    */
    public function repair() : bool {
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
        $this->message(_m('%1 problems solved', [count($problems)]));
        freeDb($db);
        return true;
    }
}

/**
 */
class AA_Optimize_Multivalue_Duplicates extends AA_Optimize {

    /** Name function
    * @return string - a name
    */
    public function name(): string {
        return _m("Multivalue Duplicates");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("Removes duplicate values in multivalue text fields");
    }

    /** Test function
    * tests for duplicate entries
    * @return bool
    */
    public function test() : bool {
        $ret = true;

        $SQL      = "SELECT `item_id`, `field_id`, `text`, `number`, count(*) AS `cnt` FROM `content` WHERE (flag & 64) GROUP BY `item_id`, `field_id`, `text`, `number` HAVING `cnt` >1";
        $err_text = GetTable2Array($SQL, '', 'aa_fields');

        if (is_array($err_text) AND count($err_text) > 0) {
            $this->message( _m('%1 duplicates found in text fields', [count($err_text)]));
            foreach ($err_text as $wrong) {
                $this->message( _m('Duplicates (%4) in item %1 - field %2 - value %3', [unpack_id($wrong['item_id']),$wrong['field_id'],$wrong['text'],$wrong['cnt']]));
            }
            $ret = false;
        }

        $SQL      = "SELECT `item_id`, `field_id`, `number`, count(*) AS `cnt` FROM `content` WHERE (flag & 64) = 0 GROUP BY `item_id`, `field_id`, `number` HAVING `cnt` >1";
        $err_num  = GetTable2Array($SQL, '', 'aa_fields');

        if (is_array($err_num) AND count($err_num) > 0) {
            $this->message( _m('%1 duplicates found in numeric fields', [count($err_num)]));
            foreach ($err_num as $wrong) {
                $this->message( _m('Duplicates (%4) in item %1 - field %2 - value %3 - numeric', [unpack_id($wrong['item_id']),$wrong['field_id'],$wrong['number'],$wrong['cnt']]));
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
    public function repair() : bool {
        $db  = getDb();

        $ret = true;

        $SQL      = "SELECT `item_id`, `field_id`, `text`, count(*) AS `cnt` FROM `content` WHERE (flag & 64) GROUP BY `item_id`, `field_id`, `text` HAVING `cnt` >1";
        $err_text = GetTable2Array($SQL, '', 'aa_fields');

        if (is_array($err_text) AND count($err_text) > 0) {
            $this->message( _m('%1 duplicates found in text fields', [count($err_text)]));
            foreach ($err_text as $wrong) {
                $SQL = "DELETE FROM `content` WHERE item_id='".quote($wrong['item_id'])."' AND field_id='".quote($wrong['field_id'])."' AND text='".quote($wrong['text'])."' LIMIT ".($wrong['cnt']-1);
                $db->query($SQL);
                $this->message($SQL);
            }
            $ret = false;
        }

        $SQL      = "SELECT `item_id`, `field_id`, `number`, count(*) AS `cnt` FROM `content` WHERE (flag & 64) = 0 GROUP BY `item_id`, `field_id`, `number` HAVING `cnt` >1";
        $err_num  = GetTable2Array($SQL, '', 'aa_fields');

        if (is_array($err_num) AND count($err_num) > 0) {
            $this->message( _m('%1 duplicates found in numeric fields', [count($err_num)]));
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


/** Testing if relation between slices are OK (without x or y chars on the begining)
 */
class AA_Optimize_Db_Relation_Startsxy extends AA_Optimize {

    /** Name function
    * @return string - a name
    */
    public function name(): string {
        return _m("Slices relation started by x or y records");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("Testing if relation between slices are OK (without x or y chars on the begining)");
    }

    /** Test function
    * tests for x or y starts
    * @return bool
    */
    public function test() : bool {
        $SQL       = 'SELECT count(*) as err_count FROM `content`,field WHERE (`text` LIKE \'x%\' OR `text` LIKE \'y%\') AND content.field_id = field.id AND LENGTH(`text`)=33 AND field.input_show_func LIKE \'%#sLiCe-%\'';
        $err_count = GetTable2Array($SQL, "aa_first", 'err_count');
        if ($err_count > 0) {
            $this->message( _m('%1 bad relation records found', [$err_count]) );
            return false;
        }
        $this->message(_m('No bad relation records found'));
        return true;
    }

    /** Name function
    * @return bool
    */
    public function repair() : bool {
        $db       = getDb();
        $SQL       = 'SELECT `content`.item_id, `content`.`text` FROM `content`,field WHERE (`text` LIKE \'x%\' OR `text` LIKE \'y%\') AND content.field_id = field.id AND LENGTH(`text`)=33 AND field.input_show_func LIKE \'%#sLiCe-%\'';
        $err_text = GetTable2Array($SQL, "", 'aa_fields');
        if (is_array($err_text) AND count($err_text) > 0) {
            $this->message( _m('%1 bad relation records found', [count($err_text)]));
            foreach ($err_text as $wrong) {
                $SQL = "repair value: ".quote(substr($wrong['text'],1))." with item_id:".quote($wrong['item_id'])."<br>";
                $this->message($SQL);
                $SQL = "UPDATE `content` SET `text` = '".quote(substr($wrong['text'],1))."' WHERE item_id = '".quote($wrong['item_id'])."' AND `text` = '".quote($wrong['text'],1)."'";
                $db->query($SQL);
                #$this->message($SQL);
            }
        }

        freeDb($db);
        return true;
    }
}

/** Set Expiry date to maximum value for items in slices, where expiry_date.....
 *  field is not shown. It also sets the field's default to that value.
 */
class AA_Optimize_Set_Expirydate extends AA_Optimize {

    /** Name function
    * @return string - a name
    */
    public function name(): string {
        return _m("Set Expiry date to maximum value");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("Set Expiry date to maximum value for items in slices, where expiry_date..... field is not shown. It also sets the field's default to that value.<br><b>Is it really what you want?</b>");
    }

    /** Test function
    * @return bool
    */
    public function test() : bool {

        $slices   = GetTable2Array("SELECT slice_id, input_default FROM `field` WHERE id='expiry_date.....' AND input_show=0", "unpack:slice_id", 'input_default');
        foreach ($slices as $sid => $default) {
            $this->message([_m('Slice ID')=>$sid, _m('Slice Name')=>AA_Slice::getModuleName($sid), _m('default value for expiry_date.....')=>$default]);
        }
        $this->message(_m('We found %1 hidden exipry_date..... fields', [count($slices)]));
        // $this->message(_m('We found %1 inconsistent rows from %2 in pagecache_str2find', array($wrong_count, $row_count)));
        return true;
    }

    /** Deletes the pagecache - the renaming and deleting is much, much quicker,
     *  than easy DELETE FROM ...
     * @return bool
     */
    public function repair() : bool {
        $db  = getDb();
        $slices   = GetTable2Array("SELECT slice_id, input_default FROM `field` WHERE id='expiry_date.....' AND input_show=0", "unpack:slice_id", 'input_default');
        foreach ($slices as $sid => $default) {
            $db->query("UPDATE item SET expiry_date=2145826800 WHERE slice_id='".q_pack_id($sid)."'");
            //huhl("UPDATE item SET expiry_date=2145826800 WHERE slice_id='".q_pack_id($sid)."'");
            $this->message(_m('items changed: %1 in slice: %2 (%3)', [$db->affected_rows(), $sid, AA_Slice::getModuleName($sid)]));

            $db->query("UPDATE field SET input_default='never:' WHERE id='expiry_date.....' AND slice_id='".q_pack_id($sid)."'");
            //huhl("UPDATE field SET input_default='txt:2145826800' WHERE id='expiry_date.....' AND slice_id='".q_pack_id($sid)."'");
            $this->message(_m('field default changed for expiry_date..... in %1 (%2)', [$sid, AA_Slice::getModuleName($sid)]));
        }
        freeDb($db);
        return true;
    }
}

/**
 *
 */
class AA_Optimize_Convert2innodb extends AA_Optimize {

    /** Name function
    * @return string - a name
    */
    public function name(): string {
        return _m("Convert database engine to InnoDB");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("Converts older MyISAM database storage engine to InnoDB for all tables");
    }

    /** Test function
    * @return bool
    */
    public function test() : bool {
        //$metabase = AA::Metabase();
        //$tables   = $metabase->getTableNames();
        $engines  = GetTable2Array("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = '".DB_NAME."' AND ENGINE  IS NOT NULL AND ENGINE<>'InnoDB'", "TABLE_NAME", 'ENGINE');
        $count    = 0;
        foreach ($engines as $tablename => $engine) {
            if (substr($tablename,0,4) == 'bck_') {
                continue;
            }
            $this->message(_m('engine for %1 slice is %2', [$tablename, $engine]));
            $count++;
        }
        $this->message(_m('We found %1 tables which needs to be converted', [$count]));
        return true;
    }

    /** Deletes the pagecache - the renaming and deleting is much, much quicker,
     *  than easy DELETE FROM ...
     * @return bool
     */
    public function repair() : bool {
        //$tables   = $metabase->getTableNames();
        $engines  = GetTable2Array("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = '".DB_NAME."' AND ENGINE  IS NOT NULL AND ENGINE<>'InnoDB'", "TABLE_NAME", 'ENGINE');
        $converted = 0;
        foreach ($engines as $tablename => $engine) {
            if (substr($tablename,0,4) == 'bck_') {
                continue;
            }
            $this->message(_m('Convert table %1 (%2) to InnoDB', [$tablename, $engine]));
            $this->query("ALTER TABLE `$tablename` ENGINE=InnoDB");
            $converted++;
        }
        $this->message(_m('Converted %1 tables', [$converted]));
        return true;
    }
}

/** Change module ID to new one
 *  Used sometimes if you clone database or copy slices to another database
 *  for another client and do not want to use the same ids )
 */
class AA_Optimize_Change_Module_Id extends AA_Optimize {

    /** Name function
    * @return string - a name
    */
    public function name(): string {
        return _m("Change current module ID to new one");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("Used sometimes if you clone database or copy slices to another database for another client and do not want to use the same slice/site ids. You probably need to do it for all slices and site module.<br>IT CHANGES CURRENT SLICE ID!");
    }

    /** implemented actions within this class */
    function actions()      { return ['repair']; }

    /** Test function
    * @return true
    */
    public function test() : bool {
        $this->message(_m('Nothing to test here. Just be careful - IT CHANGES CURRENT SLICE ID without asking!'));
        return true;
    }

    /** Repair function
    * repairs tables
    * @return true
    */
    public function repair() : bool {
        $module_fields = AA_Metabase::getModuleFields();

        // additional fields to change
        $module_fields['object_text'] = 'value';

        $current_id   = AA::$module_id;
        $p_current_id = xpack_id($current_id);

        $new_id       = new_id();
        $p_new_id     = xpack_id($new_id);

        $this->message(_m('Going to change module_id %1 -> %2 (%3 -> %4)', [$current_id, $new_id, $p_current_id, $p_new_id]));
        $converted = 0;
        foreach($module_fields as $table => $field) {
            if (AA_Metabase::isPacked($table, $field)) {
                $SQL = "UPDATE `$table` SET $field=$p_new_id WHERE $field=$p_current_id";
            } else {
                $SQL = "UPDATE `$table` SET $field='$new_id' WHERE $field='$current_id'";
            }
            $this->message(_m('Changing ID for table %1 (%2)', [$table, $field]));
            $this->message($SQL);
            $this->query($SQL);
            $converted++;
        }
       //$this->message(_m('Changing ID for table %1 (%2)', array($table, $field)));
       //$SQL = "UPDATE `object_text` SET value='$new_id' WHERE value='$current_id'";
       //$this->message($SQL);
       //$this->query($SQL);
       //$converted++;

        $this->message(_m('Converted %1 tables', [$converted]));
        return true;
    }
}


/** List Checkbox fields marked as required
 *
 */
class AA_Optimize_Required_Checboxes extends AA_Optimize {

    /** Name function
    * @return string - a name
     */
    public function name(): string {
        return _m("List Checkbox fields marked as required");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("AA are now more strict about Required field, so checkboxes marked as Required must be filled (=checked). This could be problem if you acidentaly have it set in history. This test just list all of such fields, in order you can check it.");
    }

    /** implemented actions within this class */
    function actions()      { return ['test']; }

    /** Test function
     * @return bool
     */
    public function test() : bool {
        //$metabase = AA::Metabase();
        if ($fields = DB_AA::select([], 'SELECT `id`, LOWER(HEX(`slice_id`)) AS uslice_id, `name`, `input_show` FROM `field`', [['required', 1, 'i'], ['input_show_func', 'chb:%', 'LIKE']])) {
            $this->message(_m('We found required checkbox %1 fields:', [count($fields)]));
            foreach ($fields as $field) {
                $this->message(_m('%1 - %2 (%3) Show: %4', [$field['id'], $field['name'], AA_Slice::getModuleName($field['uslice_id']), $field['input_show']]));
            }
        } else {
            $this->message(_m('OK - There is no required checkbox field'));
        }
        return true;
    }
}

/**
 *
 */
class AA_Optimize_Seo_Duplicates extends AA_Optimize {

    /** Name function
     * @return string message
     */
    public function name(): string {
        return _m("SEO name duplicates");
    }

    /** Description function
     * @return string message
     */
    public function description(): string {
        return _m("Check items for duplicate SEO name inside sitemodule");
    }

    /** implemented actions within this class */
    function actions()      { return ['test']; }

    /** Test function
     * @return bool
     */
    public function test() : bool {
        AA_Module::getUserModules();
        if ($fields = DB_AA::select([], 'SELECT `id`, LOWER(HEX(`slice_id`)) AS uslice_id, `name`, `input_show` FROM `field`', [['required', 1, 'i'], ['input_show_func', 'chb:%', 'LIKE']])) {
            $this->message(_m('We found required checkbox %1 fields:', [count($fields)]));
            foreach ($fields as $field) {
                $this->message(_m('%1 - %2 (%3) Show: %4', [$field['id'], $field['name'], AA_Slice::getModuleName($field['uslice_id']), $field['input_show']]));
            }
        } else {
            $this->message(_m('OK - There is no required checkbox field'));
        }
        return true;
    }
}

/** Fix older format of date widget parameters
 *
 */
class AA_Optimize_Fix_Datewidget extends AA_Optimize {

    /** Name function
    * @return string - a name
     */
    public function name(): string {
        return _m("Fix older format of date widget parameters");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("AA used to use ' as parameter delimiter in some early stages instead of : - like date widget 1'10'1. This fixes it to current 1:10:1. Functionaly it is equal.");
    }

    /** Test function
     * @return bool
     */
    public function test() : bool
    {
        //$metabase = AA::Metabase();
        if ($fields = DB_AA::select([], 'SELECT `id`, LOWER(HEX(`slice_id`)) AS uslice_id, `name`, `input_show_func` FROM `field`', [['input_show_func', "dte:%'%", 'LIKE']])) {
            $this->message(_m('We found %1 old delimiters:', [count($fields)]));
            foreach ($fields as $field) {
                $this->message(_m('%1 - %2 (%3) Params: %4', [$field['id'], $field['name'], AA_Slice::getModuleName($field['uslice_id']), $field['input_show_func']]));
            }
        } else {
            $this->message(_m('OK - There is no old delimiters'));
        }
        return true;
    }

    /** Repair function
     * @return bool
     */
    public function repair() : bool
    {
        //$metabase = AA::Metabase();
        $changed = DB_AA::sql("UPDATE field SET input_show_func=REPLACE(input_show_func, \"'\", ':')", [['input_show_func', "dte:%'%", 'LIKE']]);
        $this->message(_m('Fixed %1 fields', [$changed]));
        return true;
    }

}

/** Remove _#UNDEFINE alias and default help.html for more help text in field
 *
 */
class AA_Optimize_Remove_Undefine_Alias extends AA_Optimize {

    /** Name function
    * @return string - a name
     */
    public function name(): string {
        return _m("Remove _#UNDEFINE alias and default help.html for more help text in field");
    }

    /** Description function
    * @return string - a description
    */
    public function description(): string {
        return _m("AA used to use _#UNDEFINE alias and some preset help text for field, which make litle sense for real fields in real project. The help text and alias weill be removed.");
    }

    /** Test function
     * @return bool
     */
    public function test() : bool
    {
        //$metabase = AA::Metabase();
        if ($fields = DB_AA::select([], 'SELECT `id`, LOWER(HEX(`slice_id`)) AS uslice_id, `name`, `alias1` FROM `field`', [['alias1', "_#UNDEFINE"]])) {
            $this->message(_m('We found %1 old _#UNDEFINE aliases:', [count($fields)]));
            foreach ($fields as $field) {
                $this->message(_m('%1 - %2 (%3) Params: %4', [$field['id'], $field['name'], AA_Slice::getModuleName($field['uslice_id']), $field['alias1']]));
            }
        } else {
            $this->message(_m('OK - There is no _#UNDEFINE alias'));
        }

        if ($fields = DB_AA::select([], 'SELECT `id`, LOWER(HEX(`slice_id`)) AS uslice_id, `name`, `input_morehlp` FROM `field`', [['input_morehlp', "%/help.html", 'LIKE']])) {
            $this->message(_m('We found %1 old help.html help link:', [count($fields)]));
            foreach ($fields as $field) {
                $this->message(_m('%1 - %2 (%3) Params: %4', [$field['id'], $field['name'], AA_Slice::getModuleName($field['uslice_id']), $field['input_morehlp']]));
            }
        } else {
            $this->message(_m('OK - There is no old help.html help link'));
        }
        return true;
    }

    /** Repair function
     * @return bool
     */
    public function repair() : bool
    {
        //$metabase = AA::Metabase();
        $changed = DB_AA::sql("UPDATE field SET alias1='', alias1_help=''", [['alias1', "_#UNDEFINE"]]);
        $this->message(_m('Removed %1 _#UNDEFINE aliases', [$changed]));

        $changed = DB_AA::sql("UPDATE field SET input_morehlp=''", [['input_morehlp', "%/help.html", 'LIKE']]);
        $this->message(_m('Removed %1 old help links', [$changed]));
        return true;
    }

}


