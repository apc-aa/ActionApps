<?php

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;
use AA_Metabase;
use AA_Module_Site;
use function _m;
use function freeDB;
use function getDB;

/** Fix user login problem, constants editiong problem, ...
 *  Replaces binary fields by varbinary and removes trailing zeros
 *  Needed for MySQL > 5.0.17
 */
class DbBinaryTrailingZeros extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Fix user login problem, constants editing problem, ...");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Replaces binary fields by varbinary and removes trailing zeros. Needed for MySQL > 5.0.17");
    }

    /** implemented actions within this class */
    public static function actions(): array { return ['repair','test']; }

    /** Test function
     * @return true
     */
    public function test(): bool {
        $metabase = AA::Metabase();
        
        $tablenames = $metabase->getTableNames();

        foreach ($tablenames as $tablename) {
            //echo "<br><br>$tablename";
            if (substr($tablename, 0, 4) == 'bck_') {
                continue;
            }
            if ( !($cols = $metabase->getColumns($tablename))) {
                continue;
            }
            foreach ($cols as $colname => $coldef) {
                if (strpos($coldef->c[1],'char(')===0) {
//                        echo "<br> &nbsp; $tablename . $colname ";
                    $this->_fixCol($tablename,$colname);
                }
                if (($coldef->getFieldType() == 'text') and !$coldef->isBinary()) {
                   // echo "<br> &nbsp; $colname ". (AA_Metabase::isPacked($tablename, $colname) ? 'P' : '.'). ($coldef->isBinary() ? 'B' : '.'). ($coldef->isKey() ? 'K' : '.');
                }
            }
        }
        return true;
    }
    
    /** Helper _fixTable function */
    function _fixCol($table, $field) {
        $db = getDb();
        $SQL = "UPDATE `$table` SET `$field`=TRIM(TRAILING '\0' FROM `$field`)";
        $this->message($SQL);
        //$db->query($SQL);
        freeDb($db);
    }
    
    

    /** Repair function
     * repairs tables
     * @return true
     */
    public function repair(): bool {
        $this->_fixTable('change', 'id', "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('change', 'resource_id', "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('change_record', 'change_id', "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('change_record', 'selector', "varbinary(255) default NULL");
        $this->_fixTable('central_conf', 'dns_conf', "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf', 'dns_web', "varbinary(15)  NOT NULL default ''");
        $this->_fixTable('central_conf', 'dns_mx', "varbinary(15)  NOT NULL default ''");
        $this->_fixTable('central_conf', 'dns_db', "varbinary(15)  NOT NULL default ''");
        $this->_fixTable('central_conf', 'dns_prim', "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf', 'dns_sec', "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf', 'web_conf', "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf', 'web_path', "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf', 'db_server', "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf', 'db_name', "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf', 'db_user', "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf', 'db_pwd', "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf', 'AA_SITE_PATH', "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf', 'AA_BASE_DIR', "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf', 'AA_HTTP_DOMAIN', "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf', 'AA_ID', "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('central_conf', 'ORG_NAME', "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf', 'ERROR_REPORTING_EMAIL', "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf', 'ALERTS_EMAIL', "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf', 'IMG_UPLOAD_URL', "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf', 'IMG_UPLOAD_PATH', "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf', 'FILEMAN_BASE_DIR', "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf', 'FILEMAN_BASE_URL', "varbinary(255) NOT NULL default ''");
        $this->_fixTable('central_conf', 'AA_ADMIN_USER', "varbinary(30)  NOT NULL default ''");
        $this->_fixTable('central_conf', 'AA_ADMIN_PWD', "varbinary(30)  NOT NULL default ''");
        $this->_fixTable('content', 'item_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('content', 'field_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('discussion', 'id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('discussion', 'parent', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('discussion', 'item_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('ef_categories', 'category_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('ef_categories', 'target_category_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('ef_permissions', 'slice_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('email', 'owner_module_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('external_feeds', 'slice_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('external_feeds', 'remote_slice_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('event', 'id', "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('feedmap', 'from_slice_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('feedmap', 'from_field_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('feedmap', 'to_slice_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('feedmap', 'to_field_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('feedperms', 'from_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('feedperms', 'to_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('feeds', 'from_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('feeds', 'to_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('feeds', 'category_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('feeds', 'to_category_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('field', 'id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('field', 'slice_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('field', 'content_id', "varbinary(16)  default NULL");
        $this->_fixTable('jump', 'slice_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('jump', 'dest_slice_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('object_float', 'object_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('object_float', 'property', "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('object_integer', 'object_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('object_integer', 'property', "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('object_text', 'object_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('object_text', 'property', "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('pagecache', 'id', "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('pagecache_str2find', 'pagecache_id', "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('polls', 'id', "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('polls', 'module_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('polls', 'design_id', "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('polls', 'aftervote_design_id', "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('polls_answer', 'id', "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('polls_answer', 'poll_id', "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('polls_design', 'id', "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('polls_ip_lock', 'poll_id', "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('polls_log', 'answer_id', "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('polls_design', 'module_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('polls_ip_lock', 'voters_ip', "varbinary(16)  NOT NULL");
        $this->_fixTable('polls_log', 'voters_ip', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('post2shtml', 'id', "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('profile', 'slice_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('relation', 'source_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('relation', 'destination_id', "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('rssfeeds', 'slice_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('site', 'id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('site_spot', 'site_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('slice', 'id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('slice', 'type', "varbinary(16)  default NULL");
        $this->_fixTable('slice', 'mlxctrl', "varbinary(32)  NOT NULL default ''");
        $this->_fixTable('view', 'slice_id', "varbinary(16)  NOT NULL default ''");
        $this->_fixTable('view', 'order1', "varbinary(16)  default NULL");
        $this->_fixTable('view', 'order2', "varbinary(16)  default NULL");
        $this->_fixTable('view', 'group_by1', "varbinary(16)  default NULL");
        $this->_fixTable('view', 'group_by2', "varbinary(16)  default NULL");
        $this->_fixTable('view', 'cond1field', "varbinary(16)  default NULL");
        $this->_fixTable('view', 'cond1op', "varbinary(10)  default NULL");
        $this->_fixTable('view', 'cond2field', "varbinary(16)  default NULL");
        $this->_fixTable('view', 'cond2op', "varbinary(10)  default NULL");
        $this->_fixTable('view', 'cond3field', "varbinary(16)  default NULL");
        $this->_fixTable('view', 'cond3op', "varbinary(10)  default NULL");
        $this->_fixTable('view', 'field1', "varbinary(16)  default NULL");
        $this->_fixTable('view', 'field2', "varbinary(16)  default NULL");
        $this->_fixTable('view', 'field3', "varbinary(16)  default NULL");


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
        $db = getDb();
        $SQL = "ALTER TABLE `$table` CHANGE `$field` `$field` $definition";
        $db->query($SQL);
        $SQL = "UPDATE `$table` SET $field=TRIM(TRAILING '\0' FROM $field)";
        $db->query($SQL);
        freeDb($db);
    }
}
