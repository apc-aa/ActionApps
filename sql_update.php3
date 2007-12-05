<?php
//$Id$
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
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



// script for MySQL database update

// this script updates the database to last structure, create all tables, ...
// can be used for upgrade from apc-aa v. >= 1.5 or for create new database

/**
 * Handle with PHP magic quotes - quote the variables if quoting is set off
 * @param mixed $value the variable or array to quote (add slashes)
 * @return mixed the quoted variables (with added slashes)
 */
function AddslashesDeep($value) {
    return is_array($value) ? array_map('AddslashesDeep', $value) : addslashes($value);
}

if (!get_magic_quotes_gpc()) {
    // Overrides GPC variables
    foreach ($_GET as $k => $v) {
        $kk = AddslashesDeep($v);
    }
    foreach ($_POST as $k => $v) {
        $kk = AddslashesDeep($v);
    }
    foreach ($_COOKIE as $k => $v) {
        $kk = AddslashesDeep($v);
    }
}

// need config.php3 to set db access, and phplib, and probably other stuff
define ('AA_INC_PATH', "./include/");

require_once AA_INC_PATH."config.php3";

require_once AA_INC_PATH."locsess.php3";   // DB_AA definition
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."constants.php3";
require_once AA_INC_PATH."formutil.php3";

//function Links_Category2SliceID($cid) definition
require_once AA_BASE_PATH."modules/links/util.php3";

// init used objects
$db          = new DB_AA;

// here put your code
//$SQL = "REPLACE INTO module SELECT * FROM bck_module";
//$db->query($SQL);
//echo "Done";
//exit;

$db2         = new DB_AA;
$err["Init"] = "";          // error array (Init - just for initializing variable

$AA_IMG_URL  = '/'. AA_BASE_DIR .'images/';
$AA_DOC_URL  = '/'. AA_BASE_DIR .'doc/';
$now         = now();
// AA_HTTP_DOMAIN         - also used in SQL queries
// ERROR_REPORTING_EMAIL  - also used in SQL queries

set_time_limit(360);

function IsPaired($field, $fld_array) {
    // copy all tables
    foreach ($fld_array as $fld_info) {
        if ( $fld_info['name'] == $field ) {
            return true;
        }
    }
    return false;
}

function safe_echo($txt) {
    echo htmlspecialchars($txt);
    echo "<br>";
}

function myquery($db, $SQL) {
    global $fire;
    if ($fire) {
        $db->query($SQL);
    }
}

// table definitions
$tablelist = array(   'active_sessions' => "(
                          sid varbinary(32) NOT NULL default '',
                          name varchar(32) NOT NULL default '',
                          val mediumtext,
                          `changed` varchar(14) NOT NULL default '',
                          PRIMARY KEY  (name,sid),
                          KEY `changed` (`changed`)
                      )",
                      'alerts_admin' => "(
                          id int(10) NOT NULL auto_increment,
                          last_mail_confirm int(10) NOT NULL default '0',
                          mail_confirm int(4) NOT NULL default '3',
                          delete_not_confirmed int(4) NOT NULL default '10',
                          last_delete int(10) NOT NULL default '0',
                          PRIMARY KEY  (id)
                      )",
                      'alerts_collection' => "(
                          id char(6) NOT NULL default '',
                          module_id varbinary(16) NOT NULL default '',
                          emailid_welcome int(11) default NULL,
                          emailid_alert int(11) default NULL,
                          slice_id varbinary(16) default NULL,
                          PRIMARY KEY  (id),
                          UNIQUE KEY module_id (module_id)
                      )",
                      'alerts_collection_filter' => "(
                          collectionid varbinary(6) NOT NULL default '',
                          filterid int(11) NOT NULL default '0',
                          myindex tinyint(4) NOT NULL default '0',
                          PRIMARY KEY  (collectionid,filterid)
                      )",
                      'alerts_collection_howoften' => "(
                          collectionid varbinary(6) NOT NULL default '',
                          howoften char(20) NOT NULL default '',
                          `last` int(10) NOT NULL default '0',
                          PRIMARY KEY  (collectionid,howoften)
                      )",
                      'alerts_filter' => "(
                          id int(11) NOT NULL auto_increment,
                          vid int(11) NOT NULL default '0',
                          conds text NOT NULL,
                          description text NOT NULL,
                          PRIMARY KEY  (id)
                      )",
                      'auth_group' => "(
                          username varchar(50) NOT NULL default '',
                          groups varchar(50) NOT NULL default '',
                          last_changed int(11) NOT NULL default '0',
                          PRIMARY KEY  (username,groups)
                      )",
                      'auth_log' => "(
                          result text NOT NULL,
                          created int(11) NOT NULL default '0',
                          PRIMARY KEY  (created)
                      )",
                      'auth_user' => "(
                          username varchar(50) NOT NULL default '',
                          passwd varchar(50) NOT NULL default '',
                          last_changed int(11) NOT NULL default '0',
                          PRIMARY KEY  (username)
                      )",
                      'change' => "(
                          `id` varbinary(32) NOT NULL default '                                ',
                          `resource_id` varbinary(32) NOT NULL default '                                ',
                          `type` char(20) default NULL,
                          `user` char(60) default NULL,
                          `time` bigint(20) NOT NULL default '0',
                          PRIMARY KEY  (`id`),
                          KEY `type_resource_time` (`type`,`resource_id`,`time`)
                      )",
                      'change_record' => "(
                          `id` bigint(20) NOT NULL auto_increment,
                          `change_id` varbinary(32) NOT NULL default '                                ',
                          `selector` varbinary(255) default NULL,
                          `priority` int(11) NOT NULL default '0',
                          `value` longtext NOT NULL,
                          `type` varchar(32) NOT NULL default '',
                          PRIMARY KEY  (`id`)
                      )",
                      'central_conf' => "(
                          `id` int(10) unsigned NOT NULL auto_increment,
                          `dns_conf` varbinary(255) NOT NULL default '',
                          `dns_serial` int(11) NOT NULL default '0',
                          `dns_web` varbinary(15) NOT NULL default '',
                          `dns_mx` varbinary(15) NOT NULL default '',
                          `dns_db` varbinary(15) NOT NULL default '',
                          `dns_prim` varbinary(255) NOT NULL default '',
                          `dns_sec` varbinary(255) NOT NULL default '',
                          `web_conf` varbinary(255) NOT NULL default '',
                          `web_path` varbinary(255) NOT NULL default '',
                          `db_server` varbinary(255) NOT NULL default '',
                          `db_name` varbinary(255) NOT NULL default '',
                          `db_user` varbinary(255) NOT NULL default '',
                          `db_pwd` varbinary(255) NOT NULL default '',
                          `AA_SITE_PATH` varbinary(255) NOT NULL default '',
                          `AA_BASE_DIR` varbinary(255) NOT NULL default '',
                          `AA_HTTP_DOMAIN` varbinary(255) NOT NULL default '',
                          `AA_ID` varbinary(32) NOT NULL default '',
                          `ORG_NAME` varbinary(255) NOT NULL default '',
                          `ERROR_REPORTING_EMAIL` varbinary(255) NOT NULL default '',
                          `ALERTS_EMAIL` varbinary(255) NOT NULL default '',
                          `IMG_UPLOAD_MAX_SIZE` bigint(20) NOT NULL default '0',
                          `IMG_UPLOAD_URL` varbinary(255) NOT NULL default '',
                          `IMG_UPLOAD_PATH` varbinary(255) NOT NULL default '',
                          `SCROLLER_LENGTH` int(11) NOT NULL default '0',
                          `FILEMAN_BASE_DIR` varbinary(255) NOT NULL default '',
                          `FILEMAN_BASE_URL` varbinary(255) NOT NULL default '',
                          `FILEMAN_UPLOAD_TIME_LIMIT` int(11) NOT NULL default '0',
                          `AA_ADMIN_USER` varbinary(30) NOT NULL default '',
                          `AA_ADMIN_PWD` varbinary(30) NOT NULL default '',
                          `status_code` smallint(5) NOT NULL default '0',
                          PRIMARY KEY  (`id`),
                          KEY `AA_ID` (`AA_ID`)
                      )",
                      'constant' => "(
                          id varbinary(16) NOT NULL default '',
                          group_id varbinary(16) NOT NULL default '',
                          name char(150) NOT NULL default '',
                          `value` char(255) NOT NULL default '',
                          class varbinary(16) default NULL,
                          pri smallint(5) NOT NULL default '100',
                          ancestors char(160) default NULL,
                          description char(250) default NULL,
                          short_id int(11) NOT NULL auto_increment,
                          PRIMARY KEY  (id),
                          KEY group_id (group_id),
                          KEY short_id (short_id)
                      )",
                      'constant_slice' => "(
                          slice_id varbinary(16) default NULL,
                          group_id varbinary(16) NOT NULL default '',
                          propagate tinyint(1) NOT NULL default '1',
                          levelcount tinyint(2) NOT NULL default '2',
                          horizontal tinyint(1) NOT NULL default '0',
                          hidevalue tinyint(1) NOT NULL default '0',
                          hierarch tinyint(1) NOT NULL default '0',
                          PRIMARY KEY  (group_id)
                      )",
                      'content' => "(
                          item_id varbinary(16) NOT NULL default '                ',
                          field_id varbinary(16) NOT NULL default '                ',
                          number bigint(20) default NULL,
                          `text` mediumtext,
                          flag smallint(6) default NULL,
                          KEY `text` (`text`(12)),
                          KEY item_id (item_id,field_id,`text`(16))
                     )",
                     'cron' => "(
                          id bigint(30) NOT NULL auto_increment,
                          minutes varchar(30) default NULL,
                          hours varchar(30) default NULL,
                          mday varchar(30) default NULL,
                          mon varchar(30) default NULL,
                          wday varchar(30) default NULL,
                          script varchar(100) default NULL,
                          params varchar(200) default NULL,
                          last_run bigint(30) default NULL,
                          PRIMARY KEY  (id)
                      )",
                      'db_sequence' => "(
                          seq_name varchar(127) NOT NULL default '',
                          nextid int(10) unsigned NOT NULL default '0',
                          PRIMARY KEY  (seq_name)
                      )",
                      'discussion' => "(
                          id varbinary(16) NOT NULL default '',
                          parent varbinary(16) NOT NULL default '',
                          item_id varbinary(16) NOT NULL default '',
                          `date` bigint(20) NOT NULL default '0',
                          `subject` text,
                          author varchar(255) default NULL,
                          e_mail varchar(80) default NULL,
                          body text,
                          state int(11) NOT NULL default '0',
                          flag int(11) NOT NULL default '0',
                          url_address varchar(255) default NULL,
                          url_description text,
                          remote_addr varchar(255) default NULL,
                          free1 text,
                          free2 text,
                          PRIMARY KEY  (id)
                      )",
                      'ef_categories' => "(
                          category varchar(255) NOT NULL default '',
                          category_name varchar(255) NOT NULL default '',
                          category_id varbinary(16) NOT NULL default '',
                          feed_id int(11) NOT NULL default '0',
                          target_category_id varbinary(16) NOT NULL default '',
                          approved int(11) NOT NULL default '0',
                          PRIMARY KEY  (category_id,feed_id)
                      )",
                      'ef_permissions' => "(
                          slice_id varbinary(16) NOT NULL default '',
                          node varchar(150) NOT NULL default '',
                          `user` varchar(50) NOT NULL default '',
                          PRIMARY KEY  (slice_id,node,user)
                      )",
                      'email' => "(
                          id int(11) NOT NULL auto_increment,
                          description varchar(255) NOT NULL default '',
                          `subject` text NOT NULL,
                          body text NOT NULL,
                          header_from text NOT NULL,
                          reply_to text NOT NULL,
                          errors_to text NOT NULL,
                          sender text NOT NULL,
                          lang char(2) NOT NULL default 'en',
                          owner_module_id varbinary(16) NOT NULL default '',
                          html smallint(1) NOT NULL default '1',
                          `type` varchar(20) NOT NULL default '',
                          PRIMARY KEY  (id)
                      )",
                      'email_auto_user' => "(
                          uid char(50) NOT NULL default '',
                          creation_time bigint(20) NOT NULL default '0',
                          last_change bigint(20) NOT NULL default '0',
                          clear_pw char(40) default NULL,
                          confirmed smallint(5) NOT NULL default '0',
                          confirm_key char(16) default NULL,
                          PRIMARY KEY  (uid)
                      )",
                      'email_notify' => "(
                          slice_id varbinary(16) NOT NULL default '',
                          uid char(60) NOT NULL default '',
                          `function` smallint(5) NOT NULL default '0',
                          PRIMARY KEY  (slice_id,uid,`function`)
                      )",
                      'event' => "(
                          id varbinary(32) NOT NULL default '' COMMENT 'record id',
                          `type` varchar(32) NOT NULL default '' COMMENT 'type of event',
                          class varchar(32) default NULL COMMENT 'used for event condition',
                          selector varchar(255) default NULL COMMENT 'used for event condition - mostly id of changed item, ...',
                          reaction varchar(50) NOT NULL default '' COMMENT 'name of php class which is invoked when the event come',
                          params text COMMENT 'parameters for reaction object',
                          PRIMARY KEY  (id),
                          KEY type_class (`type`,class),
                          KEY type_selector (`type`,selector(32))
                      )",
                      'external_feeds' => "(
                          feed_id int(11) NOT NULL auto_increment,
                          slice_id varbinary(16) NOT NULL default '',
                          node_name varchar(150) NOT NULL default '',
                          remote_slice_id varbinary(16) NOT NULL default '',
                          user_id varchar(200) NOT NULL default '',
                          newest_item varchar(40) NOT NULL default '',
                          remote_slice_name varchar(200) NOT NULL default '',
                          feed_mode varchar(10) NOT NULL default '',
                          PRIMARY KEY  (feed_id)
                      )",
                      'feedmap' => "(
                          from_slice_id varbinary(16) NOT NULL default '',
                          from_field_id varbinary(16) NOT NULL default '',
                          to_slice_id varbinary(16) NOT NULL default '',
                          to_field_id varbinary(16) NOT NULL default '',
                          flag int(11) default NULL,
                          `value` mediumtext,
                          from_field_name varchar(255) NOT NULL default '',
                          KEY from_slice_id (from_slice_id,to_slice_id)
                      )",
                      'feedperms' => "(
                          from_id varbinary(16) NOT NULL default '',
                          to_id varbinary(16) NOT NULL default '',
                          flag int(11) default NULL
                      )",
                      'feeds' => "(
                          from_id varbinary(16) NOT NULL default '',
                          to_id varbinary(16) NOT NULL default '',
                          category_id varbinary(16) default NULL,
                          all_categories smallint(5) default NULL,
                          to_approved smallint(5) default NULL,
                          to_category_id varbinary(16) default NULL,
                          KEY from_id (from_id)
                      )",
                      'field' => "(
                          id varbinary(16) NOT NULL default '',
                          `type` varchar(16) NOT NULL default '',
                          slice_id varbinary(16) NOT NULL default '',
                          name varchar(255) NOT NULL default '',
                          input_pri smallint(5) NOT NULL default '100',
                          input_help varchar(255) default NULL,
                          input_morehlp text,
                          input_default mediumtext,
                          required smallint(5) default NULL,
                          feed smallint(5) default NULL,
                          multiple smallint(5) default NULL,
                          input_show_func text default NULL,
                          content_id varbinary(16) default NULL,
                          search_pri smallint(5) NOT NULL default '100',
                          search_type varchar(16) default NULL,
                          search_help varchar(255) default NULL,
                          search_before text,
                          search_more_help text,
                          search_show smallint(5) default NULL,
                          search_ft_show smallint(5) default NULL,
                          search_ft_default smallint(5) default NULL,
                          alias1 varchar(10) default NULL,
                          alias1_func text default NULL,
                          alias1_help varchar(255) default NULL,
                          alias2 varchar(10) default NULL,
                          alias2_func text default NULL,
                          alias2_help varchar(255) default NULL,
                          alias3 varchar(10) default NULL,
                          alias3_func text default NULL,
                          alias3_help varchar(255) default NULL,
                          input_before text,
                          aditional text,
                          content_edit smallint(5) default NULL,
                          html_default smallint(5) default NULL,
                          html_show smallint(5) default NULL,
                          in_item_tbl varchar(16) default NULL,
                          input_validate varchar(255) NOT NULL default '',
                          input_insert_func varchar(255) NOT NULL default '',
                          input_show smallint(5) default NULL,
                          text_stored smallint(5) default '1',
                          PRIMARY KEY (slice_id,id)
                      )",
                      'groups' => "(
                          name varchar(32) NOT NULL default '',
                          description varchar(255) NOT NULL default '',
                          PRIMARY KEY  (name)
                      )",
                      'hit_archive' => "(
                          `id` int(11) NOT NULL,
                          `time` int(11) NOT NULL,
                          `hits` mediumint(9) NOT NULL default '1',
                          KEY `time` (`time`)
                      )",
                      'hit_long_id' => "(
                          `id` binary(16) NOT NULL,
                          `time` int(11) NOT NULL,
                          `agent` varchar(255) default NULL,
                          `info` varchar(255) default NULL,
                          KEY `time` (`time`)
                      )",
                      'hit_short_id' => "(
                          `id` int(11) NOT NULL,
                          `time` int(11) NOT NULL,
                          `agent` varchar(255) default NULL,
                          `info` varchar(255) default NULL,
                          KEY `time` (`time`)
                      )",
                      'item' => "(
                          id varbinary(16) NOT NULL default '                ',
                          short_id int(11) NOT NULL auto_increment,
                          slice_id varbinary(16) NOT NULL default '                ',
                          status_code smallint(5) NOT NULL default '0',
                          post_date bigint(20) NOT NULL default '0',
                          publish_date bigint(20) default NULL,
                          expiry_date bigint(20) default NULL,
                          highlight smallint(5) default NULL,
                          posted_by char(60) default NULL,
                          edited_by char(60) default NULL,
                          last_edit bigint(20) default NULL,
                          display_count int(11) NOT NULL default '0',
                          flags char(30) default NULL,
                          disc_count int(11) default '0',
                          disc_app int(11) default '0',
                          externally_fed char(150) NOT NULL default '',
                          moved2active int(10) NOT NULL default '0',
                          PRIMARY KEY  (`id`),
                          UNIQUE KEY `short_id` (`short_id`),
                          KEY `slice_id_2` (`slice_id`,`status_code`,`publish_date`),
                          KEY `expiry_date` (`expiry_date`),
                          KEY `publish_date` (`publish_date`)
                      )",
                      'jump' => "(
                          slice_id varbinary(16) NOT NULL default '',
                          destination varchar(255) default NULL,
                          dest_slice_id varbinary(16) default NULL,
                          PRIMARY KEY  (slice_id)
                      )",
                      'links' => "(
                          id varbinary(16) NOT NULL default '',
                          start_id int(10) NOT NULL default '0',
                          tree_start int(11) NOT NULL default '0',
                          select_start int(11) default NULL,
                          default_cat_tmpl char(60) NOT NULL default '',
                          link_tmpl char(60) NOT NULL default '',
                          PRIMARY KEY  (id)
                      )",
                      'links_cat_cat' => "(
                          category_id int(10) unsigned NOT NULL default '0',
                          what_id int(10) unsigned NOT NULL default '0',
                          base enum('n','y') NOT NULL default 'y',
                          state enum('hidden','highlight','visible') NOT NULL default 'visible',
                          proposal enum('n','y') NOT NULL default 'n',
                          priority float(10,2) default NULL,
                          proposal_delete enum('n','y') NOT NULL default 'n',
                          a_id int(10) unsigned NOT NULL auto_increment,
                          PRIMARY KEY  (a_id),
                          KEY what_id (what_id)
                      )",
                      'links_categories' => "(
                          id int(10) unsigned NOT NULL auto_increment,
                          name varchar(255) default NULL,
                          html_template varchar(255) default NULL,
                          deleted enum('n','y') NOT NULL default 'n',
                          path varchar(255) default NULL,
                          inc_file1 varchar(255) default NULL,
                          link_count mediumint(9) NOT NULL default '0',
                          inc_file2 varchar(255) default NULL,
                          banner_file varchar(255) default NULL,
                          description text,
                          additional text,
                          note text,
                          nolinks tinyint(4) NOT NULL default '0',
                          PRIMARY KEY  (id),
                          KEY path (path),
                          KEY id (id,path)
                      )",
                      'links_changes' => "(
                          changed_link_id int(10) unsigned NOT NULL default '0',
                          proposal_link_id int(10) unsigned NOT NULL default '0',
                          rejected enum('n','y') NOT NULL default 'n',
                          KEY proposal_link_id (proposal_link_id),
                          KEY rejected (rejected),
                          KEY changed_link_id (changed_link_id,rejected)
                      )",
                      'links_languages' => "(
                          id int(10) unsigned NOT NULL default '0',
                          name varchar(20) NOT NULL default '',
                          short_name varchar(5) NOT NULL default '',
                          PRIMARY KEY  (id),
                          KEY name (name)
                      )",
                      'links_link_cat' => "(
                          category_id int(10) unsigned NOT NULL default '0',
                          what_id int(10) unsigned NOT NULL default '0',
                          base enum('n','y') NOT NULL default 'y',
                          state enum('hidden','highlight','visible') NOT NULL default 'visible',
                          proposal enum('n','y') NOT NULL default 'n',
                          priority float(10,2) default NULL,
                          proposal_delete enum('n','y') NOT NULL default 'n',
                          a_id int(10) unsigned NOT NULL auto_increment,
                          PRIMARY KEY  (a_id),
                          KEY proposal (proposal,base,state),
                          KEY category_id (category_id,proposal,base,state),
                          KEY what_id (what_id,proposal,base,state)
                      )",
                      'links_link_lang' => "(
                          link_id int(10) unsigned NOT NULL default '0',
                          lang_id int(10) unsigned NOT NULL default '0',
                          KEY link_id (link_id,lang_id)
                      )",
                      'links_link_reg' => "(
                          link_id int(10) unsigned NOT NULL default '0',
                          region_id int(10) unsigned NOT NULL default '0',
                          KEY link_id (link_id,region_id)
                      )",
                      'links_links' => "(
                          id int(10) unsigned NOT NULL auto_increment,
                          name varchar(255) default NULL,
                          description text,
                          rate int(10) default NULL,
                          votes int(11) NOT NULL default '0',
                          plus_votes int(11) NOT NULL default '0',
                          created_by varchar(60) default NULL,
                          edited_by varchar(60) default NULL,
                          checked_by varchar(60) default NULL,
                          initiator varchar(255) default NULL,
                          url text NOT NULL,
                          created int(11) NOT NULL default '0',
                          last_edit int(11) NOT NULL default '0',
                          checked int(11) NOT NULL default '0',
                          voted int(11) NOT NULL default '0',
                          flag int(11) default NULL,
                          original_name varchar(255) default NULL,
                          `type` varchar(120) default NULL,
                          org_city varchar(255) default NULL,
                          org_post_code varchar(20) default NULL,
                          org_phone varchar(120) default NULL,
                          org_fax varchar(120) default NULL,
                          org_email varchar(120) default NULL,
                          org_street varchar(255) default NULL,
                          folder int(11) NOT NULL default '1',
                          note text,
                          validated int(11) NOT NULL default '0',
                          valid_codes text,
                          valid_rank int(11) NOT NULL default '0',
                          PRIMARY KEY  (id),
                          KEY checked (checked),
                          KEY `type` (`type`),
                          KEY validated (validated),
                          KEY valid_rank (valid_rank),
                          KEY name (name),
                          KEY id (id,folder),
                          KEY folder (folder,id)
                      )",
                      'links_regions' => "(
                          id int(10) unsigned NOT NULL default '0',
                          name varchar(60) NOT NULL default '',
                          `level` tinyint(4) NOT NULL default '1',
                          PRIMARY KEY  (id),
                          KEY name (name)
                      )",
                      'log' => "(
                          id int(11) NOT NULL auto_increment,
                          `time` bigint(20) NOT NULL default '0',
                          `user` varchar(60) default NULL,
                          `type` varchar(10) default NULL,
                          selector varchar(255) default NULL,
                          params varchar(128) default NULL,
                          PRIMARY KEY  (id),
                          KEY `time` (`time`),
                          KEY `type_time` (`type`,`time`)
                      )",
                      'membership' => "(
                          groupid int(11) NOT NULL default '0',
                          memberid varbinary(32) NOT NULL default '0',
                          last_mod timestamp(14) NOT NULL,
                          PRIMARY KEY  (groupid,memberid),
                          KEY memberid (memberid)
                      )",
                      'module' => "(
                          id varbinary(16) NOT NULL default '',
                          name char(100) NOT NULL default '',
                          deleted smallint(5) default NULL,
                          `type` char(16) default 'S',
                          slice_url char(255) default NULL,
                          lang_file char(50) default NULL,
                          created_at bigint(20) NOT NULL default '0',
                          created_by char(255) NOT NULL default '',
                          owner varbinary(16) NOT NULL default '',
                          app_id varbinary(16) default NULL,
                          priority smallint(6) NOT NULL default '0',
                          flag int(11) default '0',
                          PRIMARY KEY  (id)
                      )",
                      'mysql_auth_group' => "(
                          slice_id varbinary(16) NOT NULL default '',
                          groupparent varchar(30) NOT NULL default '',
                          groups varchar(30) NOT NULL default ''
                      )",
                      'mysql_auth_user' => "(
                          uid int(10) NOT NULL default '0',
                          username char(30) NOT NULL default '',
                          passwd char(30) NOT NULL default '',
                          PRIMARY KEY  (uid),
                          UNIQUE KEY username (username)
                      )",
                      'mysql_auth_user_group' => "(
                          username char(30) NOT NULL default '',
                          groups char(30) NOT NULL default '',
                          PRIMARY KEY  (username,groups)
                      )",
                      'mysql_auth_userinfo' => "(
                          slice_id varbinary(16) NOT NULL default '',
                          uid int(10) NOT NULL auto_increment,
                          first_name varchar(20) default NULL,
                          last_name varchar(30) default NULL,
                          organisation varchar(50) default NULL,
                          start_date bigint(20) default NULL,
                          renewal_date bigint(20) default NULL,
                          email varchar(50) default '',
                          membership_type varchar(50) default NULL,
                          status_code smallint(5) default '2',
                          todo varchar(250) default NULL,
                          PRIMARY KEY  (uid)
                      )",
                      'mysql_auth_userlog' => "(
                          uid int(10) NOT NULL default '0',
                          `time` int(10) NOT NULL default '0',
                          from_bin smallint(6) NOT NULL default '0',
                          to_bin smallint(6) NOT NULL default '0',
                          organisation varchar(50) default NULL,
                          membership_type varchar(50) default NULL
                      )",
                      'nodes' => "(
                          name varchar(150) NOT NULL default '',
                          server_url varchar(200) NOT NULL default '',
                          `password` varchar(50) NOT NULL default '',
                          PRIMARY KEY  (name)
                      )",
                      'offline' => "(
                          id varbinary(16) NOT NULL default '',
                          digest varbinary(32) NOT NULL default '',
                          flag int(11) default NULL,
                          PRIMARY KEY  (id),
                          KEY digest (digest)
                      )",
                      'object_float' => "(
                          `id` bigint(20) NOT NULL auto_increment,
                          `object_id` varbinary(32) NOT NULL default '                                ',
                          `property` varbinary(16) NOT NULL default '                ',
                          `priority` smallint(20) default NULL,
                          `value` double default NULL,
                          `flag` smallint(6) default NULL,
                          PRIMARY KEY  (`id`),
                          KEY `item_id` (`object_id`,`property`,`value`),
                          KEY `property` (`property`,`value`)
                      )",
                      'object_integer' => "(
                          `id` bigint(20) NOT NULL auto_increment,
                          `object_id` varbinary(32) NOT NULL default '                                ',
                          `property` varbinary(16) NOT NULL default '                ',
                          `priority` smallint(20) default NULL,
                          `value` bigint(20) default NULL,
                          `flag` smallint(6) default NULL,
                          PRIMARY KEY  (`id`),
                          KEY `item_id` (`object_id`,`property`,`value`),
                          KEY `property` (`property`,`value`)
                      )",
                      'object_text' => "(
                          `id` bigint(20) NOT NULL auto_increment,
                          `object_id` varbinary(32) NOT NULL default '                                ',
                          `property` varbinary(16) NOT NULL default '                ',
                          `priority` smallint(20) default NULL,
                          `value` longtext,
                          `flag` smallint(6) default NULL,
                          PRIMARY KEY  (`id`),
                          KEY `object_id` (`object_id`,`property`,`value`(16)),
                          KEY `property` (`property`,`value`(10))
                      )",
                      'pagecache' => "(
                          id varbinary(32) NOT NULL default '',
                          content longtext,
                          stored bigint(20) NOT NULL default '0',
                          flag int(11) default NULL,
                          PRIMARY KEY  (id),
                          KEY stored (stored)
                      )",
                      'pagecache_str2find' => "(
                          id bigint(20) NOT NULL auto_increment,
                          pagecache_id varbinary(32) NOT NULL default '',
                          str2find text NOT NULL,
                          PRIMARY KEY  (id),
                          KEY pagecache_id (pagecache_id),
                          KEY str2find (str2find(20))
                      )",
                      'perms' => "(
                          object_type char(30) NOT NULL default '',
                          objectid varbinary(32) NOT NULL default '',
                          userid varbinary(32) NOT NULL default '0',
                          perm varbinary(32) NOT NULL default '',
                          last_mod timestamp(14) NOT NULL,
                          PRIMARY KEY  (objectid,userid,object_type),
                          KEY userid (userid)
                      )",
                      'polls' => "(
                          id varbinary(16) NOT NULL default '',
                          pollID int(11) NOT NULL auto_increment,
                          status_code tinyint(4) NOT NULL default '1',
                          pollTitle varchar(100) NOT NULL default '',
                          startDate int(11) NOT NULL default '0',
                          endDate int(11) NOT NULL default '0',
                          defaults tinyint(1) default NULL,
                          Logging tinyint(1) default NULL,
                          IPLocking tinyint(1) default NULL,
                          IPLockTimeout int(4) default NULL,
                          setCookies tinyint(1) default NULL,
                          cookiesPrefix varchar(16) default NULL,
                          designID int(11) default NULL,
                          params text NOT NULL,
                          PRIMARY KEY  (pollID)
                      )",
                      'polls_data' => "(
                          pollID int(11) NOT NULL default '0',
                          optionText char(50) NOT NULL default '',
                          optionCount int(11) NOT NULL default '0',
                          voteID int(11) NOT NULL default '0'
                      )",
                      'polls_designs' => "(
                          designID int(11) NOT NULL auto_increment,
                          pollsModuleID varbinary(16) NOT NULL default '',
                          name text NOT NULL,
                          `comment` text NOT NULL,
                          resultBarFile text NOT NULL,
                          resultBarWidth int(4) NOT NULL default '0',
                          resultBarHeight int(4) NOT NULL default '0',
                          top text NOT NULL,
                          answer text NOT NULL,
                          bottom text NOT NULL,
                          params text NOT NULL,
                          PRIMARY KEY  (designID)
                      )",
                      'polls_ip_lock' => "(
                          pollID int(11) NOT NULL default '0',
                          voteID int(11) NOT NULL default '0',
                          votersIP char(16) NOT NULL default '',
                          `timeStamp` int(11) NOT NULL default '0'
                      )",
                      'polls_log' => "(
                          logID int(11) NOT NULL auto_increment,
                          pollID int(11) NOT NULL default '0',
                          voteID int(11) NOT NULL default '0',
                          votersIP varbinary(16) NOT NULL default '',
                          `timeStamp` int(11) NOT NULL default '0',
                          PRIMARY KEY  (logID)
                      )",
                      'post2shtml' => "(
                          id varbinary(32) NOT NULL default '',
                          vars text NOT NULL,
                          `time` int(11) NOT NULL default '0',
                          PRIMARY KEY  (id)
                      )",
                      'profile' => "(
                          id int(11) NOT NULL auto_increment,
                          slice_id varbinary(16) NOT NULL default '',
                          uid varchar(60) NOT NULL default '*',
                          property varchar(20) NOT NULL default '',
                          selector varchar(255) default NULL,
                          `value` text,
                          PRIMARY KEY  (id),
                          KEY slice_user_id (slice_id,uid)
                      )",
                      'relation' => "(
                          source_id varbinary(16) NOT NULL default '',
                          destination_id varbinary(32) NOT NULL default '',
                          flag int(11) default NULL,
                          KEY source_id (source_id),
                          KEY destination_id (destination_id)
                      )",
                      'rssfeeds' => "(
                          feed_id int(11) NOT NULL auto_increment,
                          name varchar(150) NOT NULL default '',
                          server_url varchar(200) NOT NULL default '',
                          slice_id varbinary(16) NOT NULL default '',
                          PRIMARY KEY  (feed_id)
                      )",
                      'searchlog' => "(
                          id int(11) NOT NULL auto_increment,
                          `date` int(14) default NULL,
                          `query` text,
                          found_count int(11) default NULL,
                          search_time int(11) default NULL,
                          `user` text,
                          additional1 text,
                          PRIMARY KEY  (id),
                          KEY date (date)
                      )",
                      'site' => "(
                          id varbinary(16) NOT NULL default '',
                          state_file varchar(255) NOT NULL default '',
                          structure longtext,
                          flag int(11) default NULL,
                          PRIMARY KEY  (id)
                      )",
                      'site_spot' => "(
                          id int(11) NOT NULL auto_increment,
                          spot_id int(11) NOT NULL default '0',
                          site_id varbinary(16) NOT NULL default '',
                          content longtext NOT NULL,
                          flag bigint(20) default NULL,
                          PRIMARY KEY  (id),
                          KEY spot (site_id,spot_id)
                      )",
                      'slice' => "(
                          id varbinary(16) NOT NULL default '',
                          name varchar(100) NOT NULL default '',
                          owner varchar(16) default NULL,
                          deleted smallint(5) default NULL,
                          created_by varchar(255) default NULL,
                          created_at bigint(20) default NULL,
                          export_to_all smallint(5) default NULL,
                          `type` varbinary(16) default NULL,
                          template smallint(5) default NULL,
                          fulltext_format_top longtext,
                          fulltext_format longtext,
                          fulltext_format_bottom longtext,
                          odd_row_format longtext,
                          even_row_format longtext,
                          even_odd_differ smallint(5) default NULL,
                          compact_top longtext,
                          compact_bottom longtext,
                          category_top longtext,
                          category_format longtext,
                          category_bottom longtext,
                          category_sort smallint(5) default NULL,
                          slice_url varchar(255) default NULL,
                          d_listlen smallint(5) default NULL,
                          lang_file varchar(50) default NULL,
                          fulltext_remove longtext,
                          compact_remove longtext,
                          email_sub_enable smallint(5) default NULL,
                          exclude_from_dir smallint(5) default NULL,
                          notify_sh_offer longtext,
                          notify_sh_accept longtext,
                          notify_sh_remove longtext,
                          notify_holding_item_s longtext,
                          notify_holding_item_b longtext,
                          notify_holding_item_edit_s longtext,
                          notify_holding_item_edit_b longtext,
                          notify_active_item_edit_s longtext,
                          notify_active_item_edit_b longtext,
                          notify_active_item_s longtext,
                          notify_active_item_b longtext,
                          noitem_msg longtext,
                          admin_format_top longtext,
                          admin_format longtext,
                          admin_format_bottom longtext,
                          admin_remove longtext,
                          admin_noitem_msg longtext,
                          permit_anonymous_post smallint(5) default NULL,
                          permit_anonymous_edit smallint(5) default NULL,
                          permit_offline_fill smallint(5) default NULL,
                          aditional longtext,
                          flag int(11) NOT NULL default '0',
                          vid int(11) default '0',
                          gb_direction tinyint(4) default NULL,
                          group_by varchar(16) default NULL,
                          gb_header tinyint(4) default NULL,
                          gb_case varchar(15) default NULL,
                          javascript longtext,
                          fileman_access varchar(20) default NULL,
                          fileman_dir varchar(50) default NULL,
                          auth_field_group varchar(16) NOT NULL default '',
                          mailman_field_lists varchar(16) NOT NULL default '',
                          reading_password varchar(100) NOT NULL default '',
                          mlxctrl varbinary(32) NOT NULL default '',
                          PRIMARY KEY  (id),
                          KEY type (type)
                      )",
                      'slice_owner' => "(
                          id varbinary(16) NOT NULL default '',
                          name char(80) NOT NULL default '',
                          email char(80) NOT NULL default '',
                          PRIMARY KEY  (id)
                      )",
                      'subscriptions' => "(
                          uid char(50) NOT NULL default '',
                          category char(16) default NULL,
                          content_type char(16) default NULL,
                          slice_owner varbinary(16) default NULL,
                          frequency smallint(5) NOT NULL default '0',
                          last_post bigint(20) NOT NULL default '0',
                          KEY uid (uid,frequency)
                      )",
                      'toexecute' => "(
                          id int(11) NOT NULL auto_increment,
                          created bigint(20) NOT NULL default '0',
                          execute_after bigint(20) NOT NULL default '0',
                          aa_user varchar(60) NOT NULL default '',
                          priority int(11) NOT NULL default '0',
                          selector varchar(255) NOT NULL default '',
                          object longtext NOT NULL,
                          params longtext NOT NULL,
                          PRIMARY KEY  (id),
                          KEY time (execute_after,priority),
                          KEY priority (priority),
                          KEY selector (selector)
                      )",
                      'users' => "(
                          id int(11) NOT NULL auto_increment,
                          `type` varbinary(10) NOT NULL default '',
                          `password` varbinary(255) NOT NULL default '',
                          `uid` varbinary(40) NOT NULL,
                          mail char(40) NOT NULL default '',
                          name char(80) NOT NULL default '',
                          description char(255) NOT NULL default '',
                          givenname char(40) NOT NULL default '',
                          sn char(40) NOT NULL default '',
                          last_mod timestamp(14) NOT NULL,
                          PRIMARY KEY  (id),
                          KEY type (type),
                          KEY mail (mail),
                          KEY name (name),
                          KEY sn (sn)
                      )",
                      'view' => "(
                          id int(10) unsigned NOT NULL auto_increment,
                          slice_id varbinary(16) NOT NULL default '',
                          name varchar(50) default NULL,
                          `type` varchar(10) default NULL,
                          `before` longtext,
                          even longtext,
                          odd longtext,
                          even_odd_differ tinyint(3) unsigned default NULL,
                          row_delimiter longtext,
                          `after` longtext,
                          remove_string longtext,
                          group_title longtext,
                          order1 varbinary(16) default NULL,
                          o1_direction tinyint(3) unsigned default NULL,
                          order2 varbinary(16) default NULL,
                          o2_direction tinyint(3) unsigned default NULL,
                          group_by1 varbinary(16) default NULL,
                          g1_direction tinyint(3) unsigned default NULL,
                          `gb_header` tinyint(4) default NULL,
                          group_by2 varbinary(16) default NULL,
                          g2_direction tinyint(3) unsigned default NULL,
                          cond1field varbinary(16) default NULL,
                          cond1op varbinary(10) default NULL,
                          cond1cond varchar(255) default NULL,
                          cond2field varbinary(16) default NULL,
                          cond2op varbinary(10) default NULL,
                          cond2cond varchar(255) default NULL,
                          cond3field varbinary(16) default NULL,
                          cond3op varbinary(10) default NULL,
                          cond3cond varchar(255) default NULL,
                          listlen int(10) unsigned default NULL,
                          scroller tinyint(3) unsigned default NULL,
                          selected_item tinyint(3) unsigned default NULL,
                          modification int(10) unsigned default NULL,
                          parameter varchar(255) default NULL,
                          img1 varchar(255) default NULL,
                          img2 varchar(255) default NULL,
                          img3 varchar(255) default NULL,
                          img4 varchar(255) default NULL,
                          flag int(10) unsigned default NULL,
                          aditional longtext,
                          aditional2 longtext,
                          aditional3 longtext,
                          aditional4 longtext,
                          aditional5 longtext,
                          aditional6 longtext,
                          noitem_msg longtext,
                          group_bottom longtext,
                          field1 varbinary(16) default NULL,
                          field2 varbinary(16) default NULL,
                          field3 varbinary(16) default NULL,
                          calendar_type varchar(100) default 'mon',
                          PRIMARY KEY  (id),
                          KEY slice_id (slice_id)
                      )",
                      'wizard_template' => "(
                          id tinyint(10) NOT NULL auto_increment,
                          dir char(100) NOT NULL default '',
                          description char(255) NOT NULL default '',
                          PRIMARY KEY  (id),
                          UNIQUE KEY dir (dir)
                      )",
                      'wizard_welcome' => "(
                          id int(11) NOT NULL auto_increment,
                          description varchar(200) NOT NULL default '',
                          email longtext,
                          `subject` varchar(255) NOT NULL default '',
                          mail_from varchar(255) NOT NULL default '_#ME_MAIL_',
                          PRIMARY KEY  (id)
                      )"
);


$SQL_constants[] = "DELETE FROM constant WHERE group_id IN ('lt_codepages', 'lt_languages', 'AA_Core_Bins....')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined000', 'lt_codepages', 'iso8859-1', 'iso8859-1', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined001', 'lt_codepages', 'iso8859-2', 'iso8859-2', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined002', 'lt_codepages', 'windows-1250', 'windows-1250', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined003', 'lt_codepages', 'windows-1253', 'windows-1253', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined004', 'lt_codepages', 'windows-1254', 'windows-1254', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined005', 'lt_codepages', 'koi8-r', 'koi8-r', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined006', 'lt_codepages', 'ISO-8859-8', 'ISO-8859-8', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined007', 'lt_codepages', 'windows-1258', 'windows-1258', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined008', 'lt_languages', 'Afrikaans', 'AF', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined009', 'lt_languages', 'Arabic', 'AR', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined010', 'lt_languages', 'Basque', 'EU', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined011', 'lt_languages', 'Byelorussian', 'BE', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined012', 'lt_languages', 'Bulgarian', 'BG', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined013', 'lt_languages', 'Catalan', 'CA', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined014', 'lt_languages', 'Chinese (ZH-CN)', 'ZH', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined015', 'lt_languages', 'Chinese', 'ZH-TW', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined016', 'lt_languages', 'Croatian', 'HR', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined017', 'lt_languages', 'Czech', 'CS', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined018', 'lt_languages', 'Danish', 'DA', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined019', 'lt_languages', 'Dutch', 'NL', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined020', 'lt_languages', 'English', 'EN-GB', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined021', 'lt_languages', 'English (EN-US)', 'EN', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined022', 'lt_languages', 'Estonian', 'ET', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined023', 'lt_languages', 'Faeroese', 'FO', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined024', 'lt_languages', 'Finnish', 'FI', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined025', 'lt_languages', 'French (FR-FR)', 'FR', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined026', 'lt_languages', 'French', 'FR-CA', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined027', 'lt_languages', 'German', 'DE', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined028', 'lt_languages', 'Greek', 'EL', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined029', 'lt_languages', 'Hebrew (IW)', 'HE', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined030', 'lt_languages', 'Hungarian', 'HU', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined031', 'lt_languages', 'Icelandic', 'IS', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined032', 'lt_languages', 'Indonesian (IN)', 'ID', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined033', 'lt_languages', 'Italian', 'IT', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined034', 'lt_languages', 'Japanese', 'JA', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined035', 'lt_languages', 'Korean', 'KO', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined036', 'lt_languages', 'Latvian', 'LV', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined037', 'lt_languages', 'Lithuanian', 'LT', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined038', 'lt_languages', 'Neutral', 'NEUTRAL', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined039', 'lt_languages', 'Norwegian', 'NO', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined040', 'lt_languages', 'Polish', 'PL', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined041', 'lt_languages', 'Portuguese', 'PT', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined042', 'lt_languages', 'Portuguese', 'PT-BR', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined043', 'lt_languages', 'Romanian', 'RO', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined044', 'lt_languages', 'Russian', 'RU', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined045', 'lt_languages', 'Serbian', 'SR', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined046', 'lt_languages', 'Slovak', 'SK', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined047', 'lt_languages', 'Slovenian', 'SL', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined048', 'lt_languages', 'Spanish (ES-ES)', 'ES', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined049', 'lt_languages', 'Swedish', 'SV', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined050', 'lt_languages', 'Thai', 'TH', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined051', 'lt_languages', 'Turkish', 'TR', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined052', 'lt_languages', 'Ukrainian', 'UK', '', '100')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined053', 'lt_languages', 'Vietnamese', 'VI', '', '100')";
$SQL_constants[] = "REPLACE INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined054', 'lt_groupNames', 'Code Pages', 'lt_codepages', '', '0')";
$SQL_constants[] = "REPLACE INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined055', 'lt_groupNames', 'Languages Shortcuts', 'lt_languages', '', '1000')";
$SQL_constants[] = "REPLACE INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined057', 'lt_groupNames', 'AA Core Bins', 'AA_Core_Bins....', '', '10000')";
$SQL_constants[] = "REPLACE INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined058', 'AA_Core_Bins....', 'Approved', '1', '', '100')";
$SQL_constants[] = "REPLACE INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined059', 'AA_Core_Bins....', 'Holding Bin', '2', '', '200')";
$SQL_constants[] = "REPLACE INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined060', 'AA_Core_Bins....', 'Trash Bin', '3', '', '300')";
$SQL_constants[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined061', 'lt_codepages', 'windows-1251', 'windows-1251', '', '100')";

$SQL_apc_categ[] = "DELETE FROM constant WHERE group_id = 'lt_apcCategories'";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined100', 'lt_apcCategories', 'Internet & ICT', 'Internet & ICT', '', '100')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined101', 'lt_apcCategories', 'Internet & ICT - Free software & Open Source', 'Internet & ICT - Free software & Open Source', '', '110')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined102', 'lt_apcCategories', 'Internet & ICT - Access', 'Internet & ICT - Access', '', '120')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined103', 'lt_apcCategories', 'Internet & ICT - Connectivity', 'Internet & ICT - Connectivity', '', '130')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined104', 'lt_apcCategories', 'Internet & ICT - Women and ICT', 'Internet & ICT - Women and ICT', '', '140')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined105', 'lt_apcCategories', 'Internet & ICT - Rights', 'Internet & ICT - Rights', '', '150')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined106', 'lt_apcCategories', 'Internet & ICT - Governance', 'Internet & ICT - Governance', '', '160')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined107', 'lt_apcCategories', 'Development', 'Development', '', '200')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined108', 'lt_apcCategories', 'Development - Resources', 'Development - Resources', '', '210')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined109', 'lt_apcCategories', 'Development - Structural adjustment', 'Development - Structural adjustment', '', '220')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined110', 'lt_apcCategories', 'Development - Sustainability', 'Development - Sustainability', '', '230')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined111', 'lt_apcCategories', 'News and media', 'News and media', '', '300')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined112', 'lt_apcCategories', 'News and media - Alternative', 'News and media - Alternative', '', '310')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined113', 'lt_apcCategories', 'News and media - Internet', 'News and media - Internet', '', '320')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined114', 'lt_apcCategories', 'News and media - Training', 'News and media - Training', '', '330')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined115', 'lt_apcCategories', 'News and media - Traditional', 'News and media - Traditional', '', '340')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined116', 'lt_apcCategories', 'Environment', 'Environment', '', '400')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined117', 'lt_apcCategories', 'Environment - Agriculture', 'Environment - Agriculture', '', '410')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined118', 'lt_apcCategories', 'Environment - Animal rights/protection', 'Environment - Animal rights/protection', '', '420')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined119', 'lt_apcCategories', 'Environment - Climate', 'Environment - Climate', '', '430')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined120', 'lt_apcCategories', 'Environment - Biodiversity/conservetion', 'Environment - Biodiversity/conservetion', '', '440')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined121', 'lt_apcCategories', 'Environment - Energy', 'Environment - Energy', '', '450')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined122', 'lt_apcCategories', 'Environment - Campaigns', 'Environment - Campaigns', '', '455')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined123', 'lt_apcCategories', 'Environment - Legislation', 'Environment - Legislation', '', '460')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined124', 'lt_apcCategories', 'Environment - Genetics', 'Environment - Genetics', '', '465')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined125', 'lt_apcCategories', 'Environment - Natural resources', 'Environment - Natural resources', '', '470')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined126', 'lt_apcCategories', 'Environment - Rural development', 'Environment - Rural development', '', '475')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined127', 'lt_apcCategories', 'Environment - Transport', 'Environment - Transport', '', '480')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined128', 'lt_apcCategories', 'Environment - Urban ecology', 'Environment - Urban ecology', '', '485')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined129', 'lt_apcCategories', 'Environment - Pollution & waste', 'Environment - Pollution & waste', '', '490')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined130', 'lt_apcCategories', 'NGOs', 'NGOs', '', '500')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined131', 'lt_apcCategories', 'NGOs - Fundraising', 'NGOs - Fundraising', '', '510')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined132', 'lt_apcCategories', 'NGOs - Funding agencies', 'NGOs - Funding agencies', '', '520')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined133', 'lt_apcCategories', 'NGOs - Grants/scholarships', 'NGOs - Grants/scholarships', '', '530')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined134', 'lt_apcCategories', 'NGOs - Jobs', 'NGOs - Jobs', '', '540')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined135', 'lt_apcCategories', 'NGOs - Management', 'NGOs - Management', '', '550')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined136', 'lt_apcCategories', 'NGOs - Volunteers', 'NGOs - Volunteers', '', '560')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined137', 'lt_apcCategories', 'Society', 'Society', '', '600')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined138', 'lt_apcCategories', 'Society - Charities', 'Society - Charities', '', '610')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined139', 'lt_apcCategories', 'Society - Community', 'Society - Community', '', '620')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined140', 'lt_apcCategories', 'Society - Crime & rehabilitation', 'Society - Crime & rehabilitation', '', '630')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined141', 'lt_apcCategories', 'Society - Disabilities', 'Society - Disabilities', '', '640')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined142', 'lt_apcCategories', 'Society - Drugs', 'Society - Drugs', '', '650')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined143', 'lt_apcCategories', 'Society - Ethical business', 'Society - Ethical business', '', '660')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined144', 'lt_apcCategories', 'Society - Health', 'Society - Health', '', '670')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined145', 'lt_apcCategories', 'Society - Law and legislation', 'Society - Law and legislation', '', '675')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined146', 'lt_apcCategories', 'Society - Migration', 'Society - Migration', '', '680')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined147', 'lt_apcCategories', 'Society - Sexuality', 'Society - Sexuality', '', '685')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined148', 'lt_apcCategories', 'Society - Social services and welfare', 'Society - Social services and welfare', '', '690')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined149', 'lt_apcCategories', 'Economy & Work', 'Economy & Work', '', '700')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined150', 'lt_apcCategories', 'Economy & Work - Informal Sector', 'Economy & Work - Informal Sector', '', '710')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined151', 'lt_apcCategories', 'Economy & Work - Labour', 'Economy & Work - Labour', '', '720')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined152', 'lt_apcCategories', 'Culture', 'Culture', '', '800')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined153', 'lt_apcCategories', 'Culture - Arts and literature', 'Culture - Arts and literature', '', '810')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined154', 'lt_apcCategories', 'Culture - Heritage', 'Culture - Heritage', '', '820')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined155', 'lt_apcCategories', 'Culture - Philosophy', 'Culture - Philosophy', '', '830')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined156', 'lt_apcCategories', 'Culture - Religion', 'Culture - Religion', '', '840')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined157', 'lt_apcCategories', 'Culture - Ethics', 'Culture - Ethics', '', '850')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined158', 'lt_apcCategories', 'Culture - Leisure', 'Culture - Leisure', '', '860')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined159', 'lt_apcCategories', 'Human rights', 'Human rights', '', '900')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined160', 'lt_apcCategories', 'Human rights - Consumer Protection', 'Human rights - Consumer Protection', '', '910')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined161', 'lt_apcCategories', 'Human rights - Democracy', 'Human rights - Democracy', '', '920')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined162', 'lt_apcCategories', 'Human rights - Minorities', 'Human rights - Minorities', '', '930')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined163', 'lt_apcCategories', 'Human rights - Peace', 'Human rights - Peace', '', '940')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined164', 'lt_apcCategories', 'Education', 'Education', '', '1000')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined165', 'lt_apcCategories', 'Education - Distance learning', 'Education - Distance learning', '', '1010')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined166', 'lt_apcCategories', 'Education - Non-formal education', 'Education - Non-formal education', '', '1020')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined167', 'lt_apcCategories', 'Education - Schools', 'Education - Schools', '', '1030')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined168', 'lt_apcCategories', 'Politics & Government', 'Politics & Government', '', '1100')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined169', 'lt_apcCategories', 'Politics & Government - Internet', 'Politics & Government - Internet', '', '1110')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined170', 'lt_apcCategories', 'Politics & Government - Local', 'Politics & Government - Local', '', '1120')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined171', 'lt_apcCategories', 'Politics & Government - Policies', 'Politics & Government - Policies', '', '1130')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined172', 'lt_apcCategories', 'Politics & Government - Administration', 'Politics & Government - Administration', '', '1140')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined173', 'lt_apcCategories', 'People', 'People', '', '1200')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined174', 'lt_apcCategories', 'People - Children', 'People - Children', '', '1210')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined175', 'lt_apcCategories', 'People - Adolescents/teenagers', 'People - Adolescents/teenagers', '', '1220')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined176', 'lt_apcCategories', 'People - Gender', 'People - Gender', '', '1230')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined177', 'lt_apcCategories', 'People - Older people', 'People - Older people', '', '1240')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined178', 'lt_apcCategories', 'People - Family', 'People - Family', '', '1250')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined179', 'lt_apcCategories', 'World', 'World', '', '1300')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined180', 'lt_apcCategories', 'World - Globalization', 'World - Globalization', '', '1310')";
$SQL_apc_categ[] = "INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined181', 'lt_apcCategories', 'World - Debt', 'World - Debt', '', '1320')";
$SQL_apc_categ[] = "REPLACE INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined056', 'lt_groupNames', 'APC-wide Categories', 'lt_apcCategories', '', '1000')";


$SQL_aacore[] = "DELETE FROM field WHERE slice_id='AA_Core_Fields..'";
$SQL_aacore[] = "REPLACE INTO slice_owner (id, name, email) VALUES ('AA_Core.........', 'Action Aplications System', '".ERROR_REPORTING_EMAIL."')";
$SQL_aacore[] = "REPLACE INTO slice (id, name, owner, deleted, created_by, created_at, export_to_all, type, template, fulltext_format_top, fulltext_format, fulltext_format_bottom, odd_row_format, even_row_format, even_odd_differ, compact_top, compact_bottom, category_top, category_format, category_bottom, category_sort, slice_url, d_listlen, lang_file, fulltext_remove, compact_remove, email_sub_enable, exclude_from_dir, notify_sh_offer, notify_sh_accept, notify_sh_remove, notify_holding_item_s, notify_holding_item_b, notify_holding_item_edit_s, notify_holding_item_edit_b, notify_active_item_edit_s, notify_active_item_edit_b, notify_active_item_s, notify_active_item_b, noitem_msg, admin_format_top, admin_format, admin_format_bottom, admin_remove, permit_anonymous_post, permit_offline_fill, aditional, flag, vid, gb_direction, group_by, gb_header, gb_case, javascript)
                             VALUES ('AA_Core_Fields..', 'ActionApps Core', 'AA_Core_Fields..', 0, '', $now, 0, 'AA_Core_Fields..', 0, '', '',       '',                     '',             '',              0,               '',          '',             '',           '',              '',              1,             '". AA_HTTP_DOMAIN ."', 10000, 'en_news_lang.php3', '()', '()', 1, 0, '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 0, 0, '', 0, 0, NULL, NULL, NULL, NULL,'')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'headline',         '', 'AA_Core_Fields..', 'Headline',            '100', 'Headline', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'abstract',         '', 'AA_Core_Fields..', 'Abstract',            '189', 'Abstract', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'txt:8', '', '100', '', '', '', '', '0', '1', '1', '_#UNDEFINE', 'f_t', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '1', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'full_text',        '', 'AA_Core_Fields..', 'Fulltext',            '300', 'Fulltext', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'txt:8', '', '100', '', '', '', '', '0', '1', '1', '_#UNDEFINE', 'f_t', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '1', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'hl_href',          '', 'AA_Core_Fields..', 'Headline URL',       '1655', 'Link for the headline (for external links)', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_f:link_only.......', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'link_only',        '', 'AA_Core_Fields..', 'External item',      '1755', 'Use External link instead of fulltext?', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'chb', '', '100', '', '', '', '', '0', '0', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'bool', 'boo', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'place',            '', 'AA_Core_Fields..', 'Locality',           '2155', 'Item locality', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source',           '', 'AA_Core_Fields..', 'Source',             '1955', 'Source of the item', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source_href',      '', 'AA_Core_Fields..', 'Source URL',         '2055', 'URL of the source', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_s:javascript: window.alert(\'No source url specified\')', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'lang_code',        '', 'AA_Core_Fields..', 'Language Code',      '1700', 'Code of used language', '${AA_DOC_URL}help.html', 'txt:EN', '0', '0', '0', 'sel:lt_languages', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'cp_code',          '', 'AA_Core_Fields..', 'Code Page',          '1800', 'Language Code Page', '${AA_DOC_URL}help.html', 'txt:iso8859-1', '0', '0', '0', 'sel:lt_codepages', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'category',         '', 'AA_Core_Fields..', 'Category',           '1000', 'Category', '${AA_DOC_URL}help.html', 'txt:', '0', '0', '0', 'sel:lt_apcCategories', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'img_src',          '', 'AA_Core_Fields..', 'Image URL',          '2055', 'URL of the image', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_i', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'img_width',        '', 'AA_Core_Fields..', 'Image width',        '2455', 'Width of image (like: 100, 50%)', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_w', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'img_height',       '', 'AA_Core_Fields..', 'Image height',       '2555', 'Height of image (like: 100, 50%)', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_g', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'e_posted_by',      '', 'AA_Core_Fields..', 'Author`s e-mail',    '2255', 'E-mail to author', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'email', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'created_by',       '', 'AA_Core_Fields..', 'Created By',         '2355', 'Identification of creator', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'nul', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'uid', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'edit_note',        '', 'AA_Core_Fields..', 'Editor`s note',      '2355', 'Here you can write your note (not displayed on the web)', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'txt', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'img_upload',       '', 'AA_Core_Fields..', 'Image upload',       '2222', 'Select Image for upload', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fil:image/*', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'fil', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source_desc',      '', 'AA_Core_Fields..', 'Source description',  '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source_addr',      '', 'AA_Core_Fields..', 'Source address',      '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source_city',      '', 'AA_Core_Fields..', 'Source city',         '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source_prov',      '', 'AA_Core_Fields..', 'Source province',     '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source_cntry',     '', 'AA_Core_Fields..', 'Source country',      '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'time',             '', 'AA_Core_Fields..', 'Time',                '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '0')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'con_name',         '', 'AA_Core_Fields..', 'Contact name',        '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'con_email',        '', 'AA_Core_Fields..', 'Contact e-mail',      '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'con_phone',        '', 'AA_Core_Fields..', 'Contact phone',       '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'con_fax',          '', 'AA_Core_Fields..', 'Contact fax',         '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'loc_name',         '', 'AA_Core_Fields..', 'Location name',       '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'loc_address',      '', 'AA_Core_Fields..', 'Location address',    '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'loc_city',         '', 'AA_Core_Fields..', 'Location city',       '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'loc_prov',         '', 'AA_Core_Fields..', 'Location province',   '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'loc_cntry',        '', 'AA_Core_Fields..', 'Location country',    '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'start_date',       '', 'AA_Core_Fields..', 'Start date',          '100', '', '${AA_DOC_URL}help.html', 'now', '1', '0', '0', 'dte:1:10:1', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_d:m/d/Y', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'date', 'dte', '1', '0')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'end_date',         '', 'AA_Core_Fields..', 'End date',            '100', '', '${AA_DOC_URL}help.html', 'now', '1', '0', '0', 'dte:1:10:1', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_d:m/d/Y', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'date', 'dte', '1', '0')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'keywords',         '', 'AA_Core_Fields..', 'Keywords',            '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'subtitle',         '', 'AA_Core_Fields..', 'Subtitle',            '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'year',             '', 'AA_Core_Fields..', 'Year',                '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'number',           '', 'AA_Core_Fields..', 'Number',              '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'number', 'num', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'page',             '', 'AA_Core_Fields..', 'Page',                '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'number', 'num', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'price',            '', 'AA_Core_Fields..', 'Price',               '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'number', 'num', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'organization',     '', 'AA_Core_Fields..', 'Organization',        '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'file',             '', 'AA_Core_Fields..', 'File upload',        '2222', 'Select file for upload', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fil:*/*', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'fil', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'text',             '', 'AA_Core_Fields..', 'Text',                '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'unspecified',      '', 'AA_Core_Fields..', 'Unspecified',         '100', '', '${AA_DOC_URL}help.html', 'txt', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'url',              '', 'AA_Core_Fields..', 'URL',                '2055', 'Internet URL address', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_i', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'switch',           '', 'AA_Core_Fields..', 'Switch',             '2055', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'chb', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_i', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'boo', '1', '0')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'password',         '', 'AA_Core_Fields..', 'Password',           '2055', 'Password which user must know if (s)he want to edit item on public site', '${AA_DOC_URL}help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_i', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'relation',         '', 'AA_Core_Fields..', 'Relation',           '2055', '', '', 'txt:', '0', '0', '1', 'mse:#sLiCe-4e6577735f454e5f746d706c2e2e2e2e:', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_v:vid=243&cmd[243]=x-243-_#this', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
// Jakub added auth_group and mail_lists on 6.3.2003
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('auth_group......', '', 'AA_Core_Fields..', 'Auth Group',         '350', 'Sets permissions for web sections', '${AA_DOC_URL}help.html', 'txt:', 0, 0, 0, 'sel:', '', 100, '', '', '', '', 1, 1, 1, '_#AUTGROUP', 'f_h:', 'Auth Group (membership type)', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1);";
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('mail_lists......', '', 'AA_Core_Fields..', 'Mailing Lists',      '1000', 'Select mailing lists which you read', '${AA_DOC_URL}help.html', 'txt:', 0, 0, 1, 'mch::3:1', '', 100, '', '', '', '', 1, 1, 1, '_#MAILLIST', 'f_h:;&nbsp', 'Mailing Lists', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1);";
// mimo added mlxctrl on 4.10.2004
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('mlxctrl', '', 'AA_Core_Fields..', 'MLX Control', '6000', '', 'http://mimo.gn.apc.org/mlx/', 'txt:', 1, 0, 1, 'fld', '', 100, '', '', '', '', 1, 1, 1, '', '', '', '', '', '', '', '', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 0, 1);";
// mimo added 2005-03-02
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'integer',  '', 'AA_Core_Fields..', 'Integer',     '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'number', 'num', '1', '0')";
// honzam added 2005-08-15 (based on Philip King and Antonin Slejska suggestions)
//                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       id            type slice_id            name           input_pri input_help -              input_default - -    multiple - -    search_pri - - search_before --- search_ft_default - alias1_func -                                              alias2 - -  alias3 - -  input_before ---  html_show - -      input_insert_func
//                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            input_morehlp,                  required       input_show_func   search_type search_more_help   alias1               alias1_help                                              alias2_func alias3_func aditional          in_item_tbl          input_show
//                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 feed             content_id     search_help search_show                                                                                      alias2_help alias3_help content_edit       input_validate        text_stored
//                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  search_ft_show                                                                                                           html_default
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'name',        '', 'AA_Core_Fields..', 'Name',            '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'qte', '1', '1')"; // name
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'phone',       '', 'AA_Core_Fields..', 'Phone',           '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'qte', '1', '1')"; // phone
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'fax',         '', 'AA_Core_Fields..', 'Fax',             '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'qte', '1', '1')"; // fax
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'address',     '', 'AA_Core_Fields..', 'Address',         '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'qte', '1', '1')"; // address
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'location',    '', 'AA_Core_Fields..', 'Location',        '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'qte', '1', '1')"; // location
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'city',        '', 'AA_Core_Fields..', 'City',            '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'qte', '1', '1')"; // city
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'country',     '', 'AA_Core_Fields..', 'Country',         '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'qte', '1', '1')"; // country
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'range',       '', 'AA_Core_Fields..', 'Range',           '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'qte', '1', '1')"; // range
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'real',        '', 'AA_Core_Fields..', 'Real number',     '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'qte', '1', '1')"; // real
// honzam added 2005-08-26 - computed fields templates
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'computed_num','', 'AA_Core_Fields..', 'Computed number', '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'nul', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'number', 'com', '1', '0')"; // computed_num
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'computed_txt','', 'AA_Core_Fields..', 'Computed text',   '100', '', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'nul', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'com', '1', '1')"; // computed_txt
// honzam added 2007-11-21 - _upload_url..... - slice field for setting the name of upload directory
$SQL_aacore[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( '_upload_url', '', 'AA_Core_Fields..', 'Upload URL',      '100', 'If you want to have your files stored in your domain, then you can create symbolic link from http://yourdomain.org/upload -> http://your.actionapps.org/IMG_UPLOAD_PATH and fill there \"http://yourdomain.org/upload\". The url stored in AA will be changed (The file is stored still on the same place).', '${AA_DOC_URL}help.html', 'txt', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '',           '',    '',                                                  '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text',   'qte', '1', '1')";


// News EN slice template
$SQL_templates[] = "DELETE FROM field WHERE slice_id='News_EN_tmpl....'";
$SQL_templates[] = "REPLACE INTO module (id, name, deleted, type, slice_url, lang_file, created_at, created_by, owner, flag) VALUES ('News_EN_tmpl....', 'News (EN) Template', 0, 'S', '', 'en_news_lang.php3', 975157733, '', 'AA_Core.........', 0)";
$SQL_templates[] = "REPLACE INTO slice (id, name, owner, deleted, created_by, created_at, export_to_all, type, template, fulltext_format_top, fulltext_format, fulltext_format_bottom, odd_row_format, even_row_format, even_odd_differ, compact_top, compact_bottom, category_top, category_format, category_bottom, category_sort, slice_url, d_listlen, lang_file, fulltext_remove, compact_remove, email_sub_enable, exclude_from_dir, notify_sh_offer, notify_sh_accept, notify_sh_remove, notify_holding_item_s, notify_holding_item_b, notify_holding_item_edit_s, notify_holding_item_edit_b, notify_active_item_edit_s, notify_active_item_edit_b, notify_active_item_s, notify_active_item_b, noitem_msg, admin_format_top, admin_format, admin_format_bottom, admin_remove, permit_anonymous_post, permit_offline_fill, aditional, flag, vid, gb_direction, group_by, gb_header, gb_case, javascript) VALUES( 'News_EN_tmpl....', 'News (EN) Template', 'AA_Core.........', '0', '', '$now', '0', 'News_EN_tmpl....', '1', '', '<BR><FONT SIZE=+2 COLOR=blue>_#HEADLINE</FONT> <BR><B>_#PUB_DATE</B> <BR><img src=\"_#IMAGESRC\" width=\"_#IMGWIDTH\" height=\"_#IMG_HGHT\">_#FULLTEXT ', '','<font face=Arial color=#808080 size=-2>_#PUB_DATE - </font><font color=#FF0000><strong><a href=_#HDLN_URL>_#HEADLINE</a></strong></font><font color=#808080 size=-1><br>_#PLACE###(_#LINK_SRC) - </font><font color=black size=-1>_#ABSTRACT<br></font><br>', '', '0', '<br>', '<br>', '', '<p>_#CATEGORY</p>', '', '1', '". AA_HTTP_DOMAIN ."', '10000', 'en_news_lang.php3', '()', '()', '1', '0', '', '', '', '', '', '', '', '', '', '', '', 'No item found', '<tr class=tablename><td width=30>&nbsp;</td><td>Click on Headline to Edit</td><td>Date</td></tr>', '<tr class=tabtxt><td width=30><input type=checkbox name=\"chb[x_#ITEM_ID#]\" value=\"1\"></td><td><a href=\"_#EDITITEM\">_#HEADLINE</a></td><td>_#PUB_DATE</td></tr>', '', '', '1', '1', '', '0', '0', NULL, NULL, NULL, NULL,'')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'abstract........', '', 'News_EN_tmpl....', 'Abstract', '150', 'Abstract', '${AA_DOC_URL}help.html', 'qte', '0', '0', '0', 'txt:8', '', '100', '', '', '', '', '0', '1', '1', '_#ABSTRACT', 'f_t', 'alias for abstract', '_#RSS_IT_D', 'f_r:256', 'Abstract for RSS', '', '', '', '', '', '0', '0', '1', '', 'text', 'qte', '1', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'category........', '', 'News_EN_tmpl....', 'Category', '500', 'Category', '${AA_DOC_URL}help.html', 'txt:', '0', '0', '0', 'sel:lt_apcCategories', '', '100', '', '', '', '', '1', '1', '1', '_#CATEGORY', 'f_h', 'alias for Item Category', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '0', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'cp_code.........', '', 'News_EN_tmpl....', 'Code Page', '1800', 'Language Code Page', '${AA_DOC_URL}help.html', 'txt:iso8859-1', '0', '0', '0', 'sel:lt_codepages', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '0', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'created_by......', '', 'News_EN_tmpl....', 'Author', '470', 'Identification of creator', '${AA_DOC_URL}help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#CREATED#', 'f_h', 'alias for Written By', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'edited_by.......', '', 'News_EN_tmpl....', 'Edited by', '5030', 'Identification of last editor', '${AA_DOC_URL}help.html', 'qte', '0', '0', '0', 'nul', '', '100', '', '', '', '', '0', '0', '0', '_#EDITEDBY', 'f_h', 'alias for Last edited By', '', '', '', '', '', '', '', '', '0', '0', '0', 'edited_by', 'text', 'uid', '0', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'edit_note.......', '', 'News_EN_tmpl....', 'Editor`s note', '2355', 'Here you can write your note (not displayed on the web)', '${AA_DOC_URL}help.html', 'qte', '0', '0', '0', 'txt', '', '100', '', '', '', '', '0', '0', '0', '_#EDITNOTE', 'f_h', 'alias for Editor`s note', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'expiry_date.....', '', 'News_EN_tmpl....', 'Expiry Date', '955', 'Date when the news expires', '${AA_DOC_URL}help.html', 'dte:2000', '1', '0', '0', 'dte:1:10:1', '', '100', '', '', '', '', '0', '0', '0', '_#EXP_DATE', 'f_d:m/d/Y', 'alias for Expiry Date', '', '', '', '', '', '', '', '', '0', '0', '0', 'expiry_date', 'date', 'dte', '1', '0')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'e_posted_by.....', '', 'News_EN_tmpl....', 'Author`s e-mail', '480', 'E-mail to author', '${AA_DOC_URL}help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#E_POSTED', 'f_h', 'alias for Author`s e-mail', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'email', 'qte', '1', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'full_text.......', '', 'News_EN_tmpl....', 'Fulltext', '200', 'Fulltext', '${AA_DOC_URL}help.html', 'qte', '0', '0', '0', 'txt:8', '', '100', '', '', '', '', '0', '1', '1', '_#FULLTEXT', 'f_t', 'alias for Fulltext<br>(HTML tags are striped or not depending on HTML formated item setting)', '', '', '', '', '', '', '', '', '0', '0', '1', '', 'text', 'qte', '1', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'headline........', '', 'News_EN_tmpl....', 'Headline', '100', 'Headline of the news', '${AA_DOC_URL}help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#HEADLINE', 'f_h', 'alias for Item Headline', '_#RSS_IT_T', 'f_r:100', 'item title, for RSS', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'highlight.......', '', 'News_EN_tmpl....', 'Highlight', '450', 'Interesting news - shown on homepage', '${AA_DOC_URL}help.html', 'qte', '0', '0', '0', 'chb', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', 'highlight', 'bool', 'boo', '1', '0')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'hl_href.........', '', 'News_EN_tmpl....', 'Headline URL', '400', 'Link for the headline (for external links)', '${AA_DOC_URL}help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#HDLN_URL', 'f_f:link_only.......', 'alias for News URL<br>(substituted by External news link URL(if External news is checked) or link to Fulltext)<div class=example><em>Example: </em>&lt;a href=_#HDLN_URL&gt;_#HEADLINE&lt;/a&gt;</div>', '_#RSS_IT_L', 'f_r:link_only.......', 'item link, for RSS', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'img_height......', '', 'News_EN_tmpl....', 'Image height', '2300', 'Height of image (like: 100, 50%)', '${AA_DOC_URL}help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#IMG_HGHT', 'f_g', 'alias for Image Height<br>(if no height defined, program tries to remove <em>height=</em> atribute from format string<div class=example><em>Example: </em>&lt;img src=\"_#IMAGESRC\" width=_#IMGWIDTH height=_#IMG_HGHT&gt;</div>', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'img_src.........', '', 'News_EN_tmpl....', 'Image URL', '2100', 'URL of the image', '${AA_DOC_URL}help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#IMAGESRC', 'f_i', 'alias for Image URL<br>(if there is no image url defined in database, default url is used instead (see NO_PICTURE_URL constant in en_*_lang.php3 file))<div class=example><em>Example: </em>&lt;img src=\"_#IMAGESRC\"&gt;</div>', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'img_width.......', '', 'News_EN_tmpl....', 'Image width', '2200', 'Width of image (like: 100, 50%)', '${AA_DOC_URL}help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#IMGWIDTH', 'f_w', 'alias for Image Width<br>(if no width defined, program tries to remove <em>width=</em> atribute from format string<div class=example><em>Example: </em>&lt;img src=\"_#IMAGESRC\" width=_#IMGWIDTH height=_#IMG_HGHT&gt;</div>', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'lang_code.......', '', 'News_EN_tmpl....', 'Language Code', '1700', 'Code of used language', '${AA_DOC_URL}help.html', 'txt:EN', '0', '0', '0', 'sel:lt_languages', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '0', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'last_edit.......', '', 'News_EN_tmpl....', 'Last Edit', '5040', 'Date of last edit', '${AA_DOC_URL}help.html', 'now:', '0', '0', '0', 'dte:1:10:1', '', '100', '', '', '', '', '0', '0', '0', '_#LASTEDIT', 'f_d:m/d/Y', 'alias for Last Edit', '', '', '', '', '', '', '', '', '0', '0', '0', 'last_edit', 'date', 'now', '0', '0')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'link_only.......', '', 'News_EN_tmpl....', 'External news', '300', 'Use External link instead of fulltext?', '${AA_DOC_URL}help.html', 'qte', '0', '0', '0', 'chb', '', '100', '', '', '', '', '0', '0', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'bool', 'boo', '1', '0')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'place...........', '', 'News_EN_tmpl....', 'Locality', '630', 'News locality', '${AA_DOC_URL}help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#PLACE###', 'f_h', 'alias for Locality', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'posted_by.......', '', 'News_EN_tmpl....', 'Posted by', '5035', 'Identification of author', '${AA_DOC_URL}help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#POSTEDBY', 'f_h', 'alias for Author', '', '', '', '', '', '', '', '', '0', '0', '0', 'posted_by', 'text', 'uid', '0', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'post_date.......', '', 'News_EN_tmpl....', 'Post Date', '5005', 'Date of posting this news', '${AA_DOC_URL}help.html',              'now:', '1', '0', '0', 'nul', '', '100', '', '', '', '', '0', '0', '0', '_#POSTDATE', 'f_d:m/d/Y', 'alias for Post Date', '', '', '', '', '', '', '', '', '0', '0', '0', 'post_date', 'date', 'now', '0', '0')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'publish_date....', '', 'News_EN_tmpl....', 'Publish Date', '900', 'Date when the news will be published', '${AA_DOC_URL}help.html', 'now:', '1', '0', '0', 'dte:1:10:1', '', '100', '', '', '', '', '0', '0', '0', '_#PUB_DATE', 'f_d:m/d/Y', 'alias for Publish Date', '', '', '', '', '', '', '', '', '0', '0', '0', 'publish_date', 'date', 'dte', '1', '0')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source..........', '', 'News_EN_tmpl....', 'Source', '600', 'Source of the news', '${AA_DOC_URL}help.html',                         'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#SOURCE##', 'f_h', 'alias for Source Name<br>(see _#LINK_SRC for text source link)', '_#SRC_URL#', 'f_l:source_href.....', 'alias for Source with URL<br>(if there is no source url defined in database, the source is displayed as link)', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'source_href.....', '', 'News_EN_tmpl....', 'Source URL', '610', 'URL of the source', '${AA_DOC_URL}help.html',                      'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#LINK_SRC', 'f_l', 'alias for Source Name with link.<br>(substituted by &lt;a href=\"_#SRC_URL#\"&gt;_#SOURCE##&lt;/a&gt; if Source URL defined, otherwise _#SOURCE## only)', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'status_code.....', '', 'News_EN_tmpl....', 'Status Code', '5020', 'Select in which bin should the news appear', '${AA_DOC_URL}help.html', 'qte:1', '1', '0', '0', 'sel:AA_Core_Bins....', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', 'status_code', 'number', 'num', '0', '0')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'slice_id........', '', 'News_EN_tmpl....', 'Slice', '5000', 'Internal field - do not change', '${AA_DOC_URL}help.html', 'qte:1', '1', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#SLICE_ID', 'f_n:slice_id........', 'alias for id of slice', '', '', '', '', '', '', '', '', '0', '0', '0', 'slice_id', '', 'nul', '0', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'display_count...', '', 'News_EN_tmpl....', 'Displayed Times', '5050', 'Internal field - do not change', '${AA_DOC_URL}help.html', 'qte:0', '1', '1', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#DISPL_NO', 'f_h', 'alias for number of displaying of this item', '', '', '', '', '', '', '', '', '0', '0', '0', 'display_count', '', 'nul', '0', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'disc_count......', '', 'News_EN_tmpl....', 'Comments Count', '5060', 'Internal field - do not change', '${AA_DOC_URL}help.html', 'qte:0', '1', '1', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#D_ALLCNT', 'f_h', 'alias for number of all discussion comments for this item', '', '', '', '', '', '', '', '', '0', '0', '0', 'disc_count', '', 'nul', '0', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'disc_app........', '', 'News_EN_tmpl....', 'Approved Comments Count', '5070', 'Internal field - do not change', '${AA_DOC_URL}help.html', 'qte:0', '1', '1', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#D_APPCNT', 'f_h', 'alias for number of approved discussion comments for this item', '', '', '', '', '', '', '', '', '0', '0', '0', 'disc_app', '', 'nul', '0', '1')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'id..............', '', 'News_EN_tmpl....', 'Long ID', '5080', 'Internal field - do not change', '${AA_DOC_URL}help.html', 'txt:', 0, 0, 0, 'nul', '', 0, '', '', '', '', 1, 1, 1, '_#ITEM_ID_', 'f_n:', 'alias for Long Item ID', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'id', '', 'nul', 0, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'short_id........', '', 'News_EN_tmpl....', 'Short ID', '5090', 'Internal field - do not change', '${AA_DOC_URL}help.html', 'txt:', 0, 0, 0, 'nul', '', 100, '', '', '', '', 1, 1, 1, '_#SITEM_ID', 'f_t:', 'alias for Short Item ID', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'short_id', '', 'nul', 0, 0)";

// Reader Management slice template
$SQL_templates[] = "DELETE FROM field WHERE slice_id='ReaderManagement'";
$SQL_templates[] = "REPLACE INTO module (id, name, deleted, type, slice_url, lang_file, created_at, created_by, owner, flag) VALUES ('ReaderManagement', 'Reader Management Minimal', 0, 'S', '', 'en_news_lang.php3', 1043151515, '', 'AA_Core.........', 0)";
$SQL_templates[] = "REPLACE INTO slice (id, name, owner, deleted, created_by, created_at, export_to_all, type, template, fulltext_format_top, fulltext_format, fulltext_format_bottom, odd_row_format, even_row_format, even_odd_differ, compact_top, compact_bottom, category_top, category_format, category_bottom, category_sort, slice_url, d_listlen, lang_file, fulltext_remove, compact_remove, email_sub_enable, exclude_from_dir, notify_sh_offer, notify_sh_accept, notify_sh_remove, notify_holding_item_s, notify_holding_item_b, notify_holding_item_edit_s, notify_holding_item_edit_b, notify_active_item_edit_s, notify_active_item_edit_b, notify_active_item_s, notify_active_item_b, noitem_msg, admin_format_top, admin_format, admin_format_bottom, admin_remove, permit_anonymous_post, permit_offline_fill, aditional, flag, vid, gb_direction, group_by, gb_header, gb_case, javascript, fileman_access, fileman_dir, auth_field_group, mailman_field_lists, permit_anonymous_edit) VALUES ('ReaderManagement', 'Reader Management Minimal', 'AA_Core.........', 0, '1', $now, 1, 'ReaderManagement', 1, '', '&nbsp;', '', '&nbsp;', '', 0, '', '', '', '', '', 0, '', 15, 'cz_news_lang.php3', '', '', 1, 0, '', '', '', '', '', '', '', '', '', '', '', ' ', '<table border=\"1\" bordercolor=\"white\" cellpadding=\"2\" cellspacing=\"0\">\r\n<tr align=\"center\">\r\n<td class=\"tabtit\">&nbsp;</td>\r\n<td class=\"tabtit\"><b>Username</b></td>\r\n<td class=\"tabtit\"><b>Email</b></td>\r\n<td class=\"tabtit\"><b>First</b></td>\r\n<td class=\"tabtit\"><b>Last</b></td>\r\n<td class=\"tabtit\"><b>Mail confirmed</b></td>\r\n</tr>', '<tr>\r\n<td><input type=checkbox name=\"chb[x_#ITEM_ID#]\" value=\"\"></td>\r\n<td class=\"tabtxt\">_#USERNAME</td>\r\n<td class=\"tabtxt\">_#EMAIL___</td>\r\n<td class=\"tabtxt\">_#FIRSTNAM</td>\r\n<td class=\"tabtxt\">_#LASTNAME</td>\r\n<td class=\"tabtxt\">_#MAILCONF</td>\r\n</tr>', '</table>', '', 2, 0, '', 0, 0, 2, '', 0, NULL, '', '0', '', '0', '0', 5);";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('con_email.......', '', 'ReaderManagement', 'Email', 200, 'Reader\'s e-mail, unique in the scope of this slice', '${AA_DOC_URL}help.html', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 1, 1, 1, '_#EMAIL___', 'f_c:!:<a href=\"_#EDITITEM\" class=iheadline>:</a>:&nbsp;::', 'Email', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'e-unique:con_email.......:1', 'qte:', 1, 1);";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('disc_app........', '', 'ReaderManagement', 'Approved Comments Count', 5070, 'Internal field - do not change', '', 'qte:0', 1, 1, 0, 'fld', '', 100, '', '', '', '', 0, 0, 0, '_#D_APPCNT', 'f_h', 'alias for number of approved discussion comments for this item', '', '', '', '', '', '', '', '', 0, 0, 0, 'disc_app', '', 'nul', 0, 1);";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('disc_count......', '', 'ReaderManagement', 'Comments Count', 5060, 'Internal field - do not change', '', 'txt:0', 1, 1, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#D_ALLCNT', 'f_h:', 'alias for number of all discussion comments for this item', '_#VIEW_165', 'f_v:vid=165&cmd[165]=x-165-_#short_id........', 'Zkraceny fulltex pohled pro diskuse', '', 'f_0:', '', '', '', 0, 0, 0, 'disc_count', 'text', 'qte', 0, 1);";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('display_count...', '', 'ReaderManagement', 'Displayed Times', 5050, 'Internal field - do not change', '', 'qte:0', 1, 1, 0, 'fld', '', 100, '', '', '', '', 0, 0, 0, '_#DISPL_NO', 'f_h', 'alias for number of displaying of this item', '', '', '', '', '', '', '', '', 0, 0, 0, 'display_count', '', 'nul', 0, 1);";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'id..............', '', 'ReaderManagement', 'Long ID', '5080', 'Internal field - do not change', '', 'txt:', 0, 0, 0, 'nul', '', 0, '', '', '', '', 1, 1, 1, '_#ITEM_ID_', 'f_n:', 'alias for Long Item ID', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'id', '', 'nul', 0, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'short_id........', '', 'ReaderManagement', 'Short ID', '5090', 'Internal field - do not change', '', 'txt:', 0, 0, 0, 'nul', '', 100, '', '', '', '', 1, 1, 1, '_#SITEM_ID', 'f_t:', 'alias for Short Item ID', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'short_id', '', 'nul', 0, 0)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('edited_by.......', '', 'ReaderManagement', 'Edited by', 5030, 'Identification of last editor', '', 'qte', 0, 0, 0, 'nul', '', 100, '', '', '', '', 0, 0, 0, '_#EDITEDBY', 'f_h', 'alias for Last edited By', '', '', '', '', '', '', '', '', 0, 0, 0, 'edited_by', 'text', 'uid', 0, 0);";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('edit_note.......', '', 'ReaderManagement', 'Remark', 1000, '', '', 'txt:', 0, 0, 0, 'txt:4', '', 100, '', '', '', '', 0, 0, 0, '_#REMARK__', 'f_c:!:::&nbsp;::', 'Remark', '', 'f_a:', '', '', 'f_a:', '', '', '', 0, 0, 0, '', 'text', 'qte:', 1, 1);";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('expiry_date.....', '', 'ReaderManagement', 'Expiry date', 3100, 'Membership expiration', '', 'dte:2000', 0, 0, 0, 'dte:1\'10\'1', '', 100, '', '', '', '', 0, 0, 0, '_#EXP_DATE', 'f_d:j. n. Y', 'alias pro Datum Expirace', '', 'f_a:', '', '', 'f_a:', '', '', '', 0, 0, 0, 'expiry_date', 'date:', 'qte:', 1, 0);";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('flags...........', '', 'ReaderManagement', 'Flags', 5075, 'Internal field - do not change', '${AA_DOC_URL}help.html', 'qte:0', 0, 0, 0, 'fld', '', 100, '', '', '', '', 0, 0, 0, '', '', '', '', '', '', '', '', '', '', '', 0, 0, 0, 'flags', 'number', 'qte', 0, 1);";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('headline........', '', 'ReaderManagement', 'Username', 100, 'Reader\'s User Name, unique in the scope of the complete AA installation', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 1, 1, 1, '_#USERNAME', 'f_c:!:<a href=\"_#EDITITEM\" class=iheadline>:</a>:&nbsp;::', 'Username', '', 'f_a:', '', '', 'f_a:', '', '', '', 0, 0, 0, '', 'unique:headline........:0', 'qte:', 1, 1);";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('highlight.......', '', 'ReaderManagement', 'Highlight', 5025, 'Interesting news - shown on homepage', '', 'qte', 0, 0, 0, 'chb', '', 100, '', '', '', '', 0, 0, 0, '', '', '', '', '', '', '', '', '', '', '', 0, 0, 0, 'highlight', 'bool', 'boo', 0, 0);";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('last_edit.......', '', 'ReaderManagement', 'Last Edit', 5040, 'Date of last edit', '', 'now:', 0, 0, 0, 'dte:1\'10\'1', '', 100, '', '', '', '', 0, 0, 0, '_#LASTEDIT', 'f_d:m/d/Y', 'alias for Last Edit', '', '', '', '', '', '', '', '', 0, 0, 0, 'last_edit', 'date', 'now', 0, 0);";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('password........', '', 'ReaderManagement', 'Password', 300, 'Your password. You must send it every time to confirm your changes.', '${AA_DOC_URL}help.html', 'txt:', 1, 0, 0, 'pwd:', '', 100, '', '', '', '', 0, 0, 0, '_#PASSWORD', 'f_c:!:*::&nbsp;::1', 'Password: Show * when set, nothing when not set', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'pwd:', 'pwd:', 1, 1);";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('posted_by.......', '', 'ReaderManagement', 'Posted by', 5000, 'Identification of author', '', 'qte', 0, 0, 0, 'fld', '', 100, '', '', '', '', 0, 0, 0, '_#POSTEDBY', 'f_h', 'alias for Author', '', '', '', '', '', '', '', '', 0, 0, 0, 'posted_by', 'text', 'uid', 0, 1);";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('post_date.......', '', 'ReaderManagement', 'Post Date', 5005, 'Date of posting this news', '', 'now:', 1, 0, 0, 'nul', '', 100, '', '', '', '', 0, 0, 0, '_#POSTDATE', 'f_d:m/d/Y', 'alias for Post Date', '', '', '', '', '', '', '', '', 0, 0, 0, 'post_date', 'date', 'now', 0, 0);";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('publish_date....', '', 'ReaderManagement', 'Start date', 3000, 'Membership start', '', 'now:', 0, 0, 0, 'dte:1:10:1', '', 100, '', '', '', '', 0, 0, 0, '_#PUB_DATE', 'f_d:j. n. Y', 'alias pro Datum Vystavení', '_#PUB_DAT#', 'f_d:j.n.y', 'alias pro Datum Vystavení pro admin stranky', '', 'f_a:', '', '', '', 0, 0, 0, 'publish_date', 'date:', 'qte:', 1, 0);";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('slice_id........', '', 'ReaderManagement', 'Slice', 5000, 'Internal field - do not change', '', 'qte:1', 1, 0, 0, 'fld', '', 100, '', '', '', '', 0, 0, 0, '_#SLICE_ID', 'f_n:slice_id', 'alias for id of slice', '', '', '', '', '', '', '', '', 0, 0, 0, 'slice_id', '', 'nul', 0, 0);";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('status_code.....', '', 'ReaderManagement', 'Status Code', 5020, 'Select in which bin should the news appear', '', 'qte:1', 1, 0, 0, 'sel:AA_Core_Bins....', '', 100, '', '', '', '', 0, 0, 0, '', '', '', '', '', '', '', '', '', '', '', 0, 0, 0, 'status_code', 'number', 'num', 0, 0);";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('switch..........', '', 'ReaderManagement', 'Email Confirmed', 600, 'Email is confirmed when the user clicks on the URL received in email', '${AA_DOC_URL}help.html', 'txt:', 0, 0, 0, 'chb', '', 100, '', '', '', '', 0, 0, 0, '_#MAILCONF', 'f_c:1:Yes::No::1', 'Email Confirmed', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text', 'boo:', 1, 1);";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('text...........1', '', 'ReaderManagement', 'First name', 400, '', '${AA_DOC_URL}help.html', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 1, 1, 1, '_#FIRSTNAM', 'f_c:!:::&nbsp;::', 'First name', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text', 'qte:', 1, 1);";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('text...........2', '', 'ReaderManagement', 'Last name', 500, '', '${AA_DOC_URL}help.html', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 1, 1, 1, '_#LASTNAME', 'f_c:!:::&nbsp;::', 'Last name', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text', 'qte:', 1, 1);";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('text...........3', '', 'ReaderManagement', 'Access Code', 700, 'Access code is used to confirm email and when you do not use HTTP Authentification', '${AA_DOC_URL}help.html', 'rnd:5:text...........3:0', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 1, 1, 1, '_#ACCECODE', 'f_h:', 'Access Code', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1);";

// Noticas - ES - template
$SQL_templates[] = "DELETE FROM field WHERE slice_id='noticias-es.....'";
$SQL_templates[] = "REPLACE INTO module (id, name, deleted, type, slice_url, lang_file, created_at, created_by, owner, flag) VALUES ('noticias-es.....', 'Noticias (ES) - Plantilla', 0, 'S', '', 'es_news_lang.php3', 1067835192, '', 'AA_Core.........', 0)";
$SQL_templates[] = "REPLACE INTO slice (id, name, owner, deleted, created_by, created_at, export_to_all, type, template, fulltext_format_top, fulltext_format, fulltext_format_bottom, odd_row_format, even_row_format, even_odd_differ, compact_top, compact_bottom, category_top, category_format, category_bottom, category_sort, slice_url, d_listlen, lang_file, fulltext_remove, compact_remove, email_sub_enable, exclude_from_dir, notify_sh_offer, notify_sh_accept, notify_sh_remove, notify_holding_item_s, notify_holding_item_b, notify_holding_item_edit_s, notify_holding_item_edit_b, notify_active_item_edit_s, notify_active_item_edit_b, notify_active_item_s, notify_active_item_b, noitem_msg, admin_format_top, admin_format, admin_format_bottom, admin_remove, permit_anonymous_post, permit_anonymous_edit, permit_offline_fill, aditional, flag, vid, gb_direction, group_by, gb_header, gb_case, javascript, fileman_access, fileman_dir, auth_field_group, mailman_field_lists, reading_password) VALUES ('noticias-es.....', 'Noticias (ES) - Plantilla', 'AA_Core.........', 0, '8', 1067835192, 0, 'noticias-es.....', 1, '', '<h2>_#TITULAR_</h2>\r\n<B>_#AUTOR___, _#LUGAR___</B> <BR>\r\n<img src=\"_#IMAGESRC\" width=\"_#IMGWIDTH\" height=\"_#IMG_HGHT\" align=\"right\">\r\n_#TEXTO___', '', '<div class=\"item\">_#FECHAPUB:\r\n<strong><a href=_#ENLACE__>_#TITULAR_</a>\r\n</strong>\r\n<br>_#LUGAR___ [_#FTE_URL_]<br>\r\n_#RESUMEN_\r\n</div>\r\n<br>', '', 0, '', '<br>', '', '<p>_#CATEGORi</p>', '', 0, '', 10000, 'es_news_lang.php3', '()', '[]', 1, 0, '', '', '', '', '', '', '', '', '', '', '', 'No se encontraron datos', '<tr class=tablename><td width=30>&nbsp;</td><td>Haga clic para editar</td><td>Fecha</td></tr>', '<tr class=tabtxt><td width=30><input type=checkbox name=\"chb[x_#ITEM_ID#]\" value=\"1\"></td><td><a href=\"_#EDITITEM\">_#TITULAR_</a></td><td>_#FECHAPUB</td></tr>', '', '', 2, 0, 2, '', 0, 0, 2, 'category........', 0, '', '', '0', '', '', '', '')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('abstract........', '', 'noticias-es.....', 'Resumen', 150, '', '', 'txt:', 0, 0, 0, 'txt:8', '', 100, '', '', '', '', 0, 1, 1, '_#RESUMEN_', 'f_a:80:full_text.......:1', 'resumen del item', '_#RSS_IT_D', 'f_r:80:full_text.......:1', 'resumen del item para RSS', '', 'f_0:', '', '', '', 0, 0, 1, '', 'text:', 'qte:', 1, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('category........', '', 'noticias-es.....', 'Categoría', 500, '', '', 'txt:', 0, 0, 0, 'sel:lt_apcCategories:', '', 100, '', '', '', '', 1, 1, 1, '_#CATEGORI', 'f_h:', 'categoria del item', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 0, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('cp_code.........', '', 'noticias-es.....', 'Página de códigos', 1800, '', '', 'txt:iso8859-1', 0, 0, 0, 'sel:lt_codepages:', '', 100, '', '', '', '', 0, 0, 0, '', 'f_0:', '', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 0, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('created_by......', '', 'noticias-es.....', 'Autor', 470, '', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#AUTOR___', 'f_h:', 'autor del item', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('disc_app........', '', 'noticias-es.....', 'Comentarios aprobados', 5070, 'Internal field - do not change', '${AA_DOC_URL}help.html', 'txt:0', 1, 1, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#D_APPCNT', 'f_h:', 'número de comentarios aprobados para este ítem', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'disc_app', 'text:', 'qte:', 0, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('disc_count......', '', 'noticias-es.....', 'Comentarios', 5060, 'Internal field - do not change', '${AA_DOC_URL}help.html', 'txt:0', 1, 1, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#D_ALLCNT', 'f_h:', 'número total de comentarios sobre este ítem', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'disc_count', 'text:', 'qte:', 0, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('display_count...', '', 'noticias-es.....', 'Visualizaciones', 5050, 'Internal field - do not change', '${AA_DOC_URL}help.html', 'txt:0', 1, 1, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#VISUALIZ', 'f_h:', 'número de veces que este ítem ha sido visitado', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'display_count', 'text:', 'qte:', 0, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'id..............', '', 'noticias-es.....', 'Long ID', '5080', 'Internal field - do not change', '${AA_DOC_URL}help.html', 'txt:', 0, 0, 0, 'nul', '', 0, '', '', '', '', 1, 1, 1, '_#ITEM_ID_', 'f_n:', 'alias for Long Item ID', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'id', '', 'nul', 0, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'short_id........', '', 'noticias-es.....', 'Short ID', '5090', 'Internal field - do not change', '${AA_DOC_URL}help.html', 'txt:', 0, 0, 0, 'nul', '', 100, '', '', '', '', 1, 1, 1, '_#SITEM_ID', 'f_t:', 'alias for Short Item ID', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'short_id', '', 'nul', 0, 0)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('edited_by.......', '', 'noticias-es.....', 'Editado por', 5030, '', '', 'txt:', 0, 0, 0, 'nul', '', 100, '', '', '', '', 0, 0, 0, '_#EDITADO_', 'f_h:', 'identificador del usuario que editó el ítem', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'edited_by', 'text:', 'uid:', 0, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('edit_note.......', '', 'noticias-es.....', 'Notas del editor', 2355, 'Estas notas no se publicarán en el sitio', '', 'txt:', 0, 0, 0, 'txt:', '', 100, '', '', '', '', 0, 0, 0, '_#EDITNOTE', 'f_h:', 'notas del editor', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('expiry_date.....', '', 'noticias-es.....', 'Fecha de caducidad', 955, 'Fecha en que el item expira (y se retira automáticamente del sitio)', '', 'dte:2000', 1, 0, 0, 'dte:1:10:1', '', 100, '', '', '', '', 0, 0, 0, '_#FECHACAD', 'f_d:d/m/Y', 'fecha de caducidad', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'expiry_date', 'date:', 'qte:', 1, 0)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('e_posted_by.....', '', 'noticias-es.....', 'e-mail autor', 480, '', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#E_AUTOR_', 'f_h:', 'correo electrónico del autor', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('full_text.......', '', 'noticias-es.....', 'Texto completo', 200, '', '', 'txt:', 0, 0, 0, 'txt:8', '', 100, '', '', '', '', 0, 1, 1, '_#TEXTO___', 'f_t:', 'texto completo del item', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 1, '', 'text:', 'qte:', 1, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('headline........', '', 'noticias-es.....', 'Titular', 100, '', '', 'txt:', 1, 0, 0, 'fld:', '', 100, '', '', '', '', 1, 1, 1, '_#TITULAR_', 'f_h:', 'titular del item', '_#RSS_IT_T', 'f_r:100', 'titular del item para RSS', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('highlight.......', '', 'noticias-es.....', 'Resaltar', 450, '', '', 'txt:', 0, 0, 0, 'chb', '', 100, '', '', '', '', 0, 0, 0, '', 'f_0:', '', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'highlight', 'bool:', 'boo:', 1, 0)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('hl_href.........', '', 'noticias-es.....', 'URL noticia externa', 400, '(para items externos) usar este URL para el enlace', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 1, 1, 1, '_#ENLACE__', 'f_f:link_only.......', 'enlace al texto completo del item (se sustituye por el URL externo si está marcado como externo)', '_#RSS_IT_L', 'f_r:link_only.......', 'enlace para RSS', '', 'f_0:', '', '', '', 0, 0, 0, '', 'url:', 'qte:', 1, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('img_height......', '', 'noticias-es.....', 'alto de la imagen', 2300, 'puede ser en pixeles (ej: 100) o porcentaje (ej: 50%)', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#IMG_HGHT', 'f_g:', 'alto de la imagen<br>(si no está definido, se intenta eliminar el atributo <em>height=</em> del dise?o<div class=example><em>Ejemplo: </em>&lt;img src=\"_#IMAGESRC\" width=\"_#IMGWIDTH\" height=\"_#IMG_HGHT\"&gt;</div>', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('img_src.........', '', 'noticias-es.....', 'URL de imagen', 2100, 'URL de una imágen previamente publicada', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#IMAGESRC', 'f_i:', 'URL de la imagen<br>Si no está definido se usa el URL por defecto (ver NO_PICTURE_URL en en_*_lang.php3)<div class=example><em>Ejemplo: </em>&lt;img src=\"_#IMAGESRC\"&gt;</div>', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'url:', 'qte:', 1, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('img_width.......', '', 'noticias-es.....', 'ancho de la imagen', 2200, 'puede ser en pixeles (ej: 100) o porcentaje (ej: 50%)', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#IMGWIDTH', 'f_w:', 'ancho de la imagen<br>(si no está definido, se intenta eliminar el atributo <em>width=</em> del dise?o<div class=example><em>Ejemplo: </em>&lt;img src=\"_#IMAGESRC\" width=\"_#IMGWIDTH\" height=\"_#IMG_HGHT\"&gt;</div>', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('lang_code.......', '', 'noticias-es.....', 'Idioma', 1700, '', '', 'txt:EN', 0, 0, 0, 'sel:lt_languages:', '', 100, '', '', '', '', 0, 0, 0, '', 'f_0:', '', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 0, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('last_edit.......', '', 'noticias-es.....', 'Ultima modificación', 5040, '', '', 'now:', 0, 0, 0, 'dte:1:10:1', '', 100, '', '', '', '', 0, 0, 0, '_#ULTIMA_E', 'f_d:d/m/Y', 'fecha de la última edición', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'last_edit', 'date:', 'now:', 0, 0)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('link_only.......', '', 'noticias-es.....', 'Noticia externa', 300, 'Usar un enlace externo en vez del texto completo', '', 'txt:', 0, 0, 0, 'chb', '', 100, '', '', '', '', 0, 0, 1, '', 'f_0:', '', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'bool:', 'boo:', 1, 0)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('place...........', '', 'noticias-es.....', 'Localidad', 630, '', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#LUGAR___', 'f_h:', 'localidad', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('posted_by.......', '', 'noticias-es.....', 'Publicado por', 5035, '', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#PUBLICAD', 'f_h:', 'identificador del usuario que publicó el ítem', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'posted_by', 'text:', 'uid:', 0, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('post_date.......', '', 'noticias-es.....', 'Fecha de envío', 5005, '', '', 'now:', 1, 0, 0, 'nul', '', 100, '', '', '', '', 0, 0, 0, '_#FECHAENV', 'f_d:d/m/Y', 'fecha en que fué enviado el ítem', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'post_date', 'date:', 'now:', 0, 0)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('publish_date....', '', 'noticias-es.....', 'Fecha de publicación', 900, 'Fecha en que el item debe aparecer publicado en el sitio', '', 'now:', 1, 0, 0, 'dte:1:10:1', '', 100, '', '', '', '', 0, 0, 0, '_#FECHAPUB', 'f_d:d/m/Y', 'fecha de publicación del item', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'publish_date', 'date:', 'qte:', 1, 0)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'status_code.....', '', 'noticias-es.....', 'Estado', '5020', 'Seleccione en qué carpeta se almacena el item', '${AA_DOC_URL}help.html', 'qte:1', '1', '0', '0', 'sel:AA_Core_Bins....', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', 'status_code', 'number', 'num', '0', '0')";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('slice_id........', '', 'noticias-es.....', 'Canal', 5000, 'Internal field - do not change', '/apc-aa/doc/help.html', 'txt:1', 1, 0, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#ID_CANAL', 'f_n:slice_id........', 'identificador interno del canal', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'slice_id', 'text:', 'qte:', 0, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('source..........', '', 'noticias-es.....', 'Fuente', 600, '', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 0, 0, 0, '_#FUENTE__', 'f_h:', 'fuente', '_#FTE_URL_', 'f_l:source_href.....', 'fuente mostrada como enlace al URL de la fuente (si está rellenado)', '', 'f_0:', '', '', '', 0, 0, 0, '', 'text:', 'qte:', 1, 1)";
$SQL_templates[] = "INSERT INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES ('source_href.....', '', 'noticias-es.....', 'URL de la fuente', 610, '', '', 'txt:', 0, 0, 0, 'fld:', '', 100, '', '', '', '', 1, 1, 1, '_#URL_FTE_', 'f_h:', 'URL de la fuente', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, '', 'url:', 'qte:', 1, 1)";

//$SQL_view_templates_delete[] = "DELETE FROM view WHERE slice_id='AA_Core_Fields..' AND name IN ('Discussion ...','Constant view ...','Javascript ...','rss','Calendar')";

$SQL_view_templates["discus"]     = "view SET slice_id='AA_Core_Fields..', name='Discussion ...', type='discus', `before`='<table bgcolor=#000000 cellspacing=0 cellpadding=1 border=0><tr><td><table width=100% bgcolor=#f5f0e7 cellspacing=0 cellpadding=0 border=0><tr><td colspan=8><big>Comments</big></td></tr>', even='<table  width=500 cellspacing=0 cellpadding=0 border=0><tr><td colspan=2><hr></td></tr><tr><td width=\"20%\"><b>Date:</b></td><td> _#DATE####</td></tr><tr><td><b>Comment:</b></td><td> _#SUBJECT#</td></tr><tr><td><b>Author:</b></td><td><A href=mailto:_#EMAIL###>_#AUTHOR##</a></td></tr><tr><td><b>WWW:</b></td><td><A href=_#WWW_URL#>_#WWW_DESC</a></td></tr><tr><td><b>IP:</b></td><td>_#IP_ADDR#</td></tr><tr><td colspan=2>&nbsp;</td></tr><tr><td colspan=2>_#BODY####</td></tr><tr><td colspan=2>&nbsp;</td></tr><tr><td colspan=2><a href=_#URLREPLY>Reply</a></td></tr></table><br>', odd='<tr><td width=\"10\">&nbsp;</td><td><font size=-1>_#CHECKBOX</font></td><td width=\"10\">&nbsp;</td><td align=center nowrap><SMALL>_#DATE####</SMALL></td><td width=\"20\">&nbsp;</td><td nowrap>_#AUTHOR## </td><td><table cellspacing=0 cellpadding=0 border=0><tr><td>_#TREEIMGS</td><td><img src=".$AA_IMG_URL."blank.gif width=2 height=21></td><td nowrap>_#SUBJECT#</td></tr></table></td><td width=\"20\">&nbsp;</td></tr>', even_odd_differ=1, after='</table></td></tr></table>_#BUTTONS#', remove_string='<SCRIPT Language=\"JavaScript\"><!--function checkData() { var text=\"\"; if(!document.f.d_subject.value) { text+=\"subject \" } if (text!=\"\") { alert(\"Please, fill the field: \" + text);  return false; } return true; } // --></SCRIPT><form name=f method=post action=\"/apc-aa/filldisc.php3\" onSubmit=\" return checkData()\"><p>Author<br><input type=text name=d_author > <p>Subject<br><input type=text name=d_subject value=\"_#SUBJECT#\"><p>E-mail<br><input type=text name=d_e_mail><p>Comment<br><textarea rows=\"5\" cols=\"40\" name=d_body ></textarea><p>WWW<br><input type=text name=d_url_address value=\"http://\"><p>WWW description<br><input type=text name=d_url_description><br><input type=submit value=Send align=center><input type=hidden name=d_parent value=\"_#DISC_ID#\"><input type=hidden name=d_item_id value=\"_#ITEM_ID#\"><input type=hidden name=url value=\"_#DISC_URL\"></FORM>', group_title=NULL, order1=NULL, o1_direction=0, order2=NULL, o2_direction=NULL, group_by1=NULL, g1_direction=NULL, group_by2=NULL, g2_direction=NULL, cond1field=NULL, cond1op=NULL, cond1cond=NULL, cond2field=NULL, cond2op=NULL, cond2cond=NULL, cond3field=NULL, cond3op=NULL, cond3cond=NULL, listlen=NULL, scroller=NULL, selected_item=0, modification=23, parameter=NULL, img1='<img src=${AA_IMG_URL}i.gif width=9 height=21>', img2='<img src=${AA_IMG_URL}l.gif width=9 height=21>', img3='<img src=${AA_IMG_URL}t.gif width=9 height=21>', img4='<img src=${AA_IMG_URL}blank.gif width=12 height=21>', flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='No item found', group_bottom=NULL, field1='', field2=NULL, field3=NULL, calendar_type='mon'";
$SQL_view_templates["const"]      = "view SET slice_id='AA_Core_Fields..', name='Constant view ...', type='const', `before`='<table border=0 cellpadding=0 cellspacing=0>', even='', odd='<tr><td>_#VALUE###</td></tr>', even_odd_differ=0, after='</table>', remove_string=NULL, group_title=NULL, order1='value', o1_direction=0, order2=NULL, o2_direction=NULL, group_by1=NULL, g1_direction=NULL, group_by2=NULL, g2_direction=NULL, cond1field=NULL, cond1op=NULL, cond1cond=NULL, cond2field=NULL, cond2op=NULL, cond2cond=NULL, cond3field=NULL, cond3op=NULL, cond3cond=NULL, listlen=10, scroller=NULL, selected_item=0, modification=NULL, parameter='lt_languages', img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='No item found', group_bottom=NULL, field1='', field2=NULL, field3=NULL, calendar_type='mon'";
$SQL_view_templates["javascript"] = "view SET slice_id='AA_Core_Fields..', name='Javascript ...', type='javascript', `before`='/* output of this script can be included to any page on any server by adding:&lt;script type=\"text/javascript\" src=\"". AA_BASE_PATH ."view.php3?vid=3\"&gt; &lt;/script&lt; or such.*/', even=NULL, odd='document.write(\"_#HEADLINE\");', even_odd_differ=NULL, after='// script end ', remove_string=NULL, group_title=NULL, order1='', o1_direction=0, order2='', o2_direction=0, group_by1=NULL, g1_direction=NULL, group_by2=NULL, g2_direction=NULL, cond1field='', cond1op='<', cond1cond='', cond2field='', cond2op='<', cond2cond='', cond3field='', cond3op='<', cond3cond='', listlen=8, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='No item found', group_bottom=NULL, field1='', field2=NULL, field3=NULL, calendar_type='mon'";
$SQL_view_templates["rss"]        = "view SET slice_id='AA_Core_Fields..', name='rss', type='rss', `before`='<!DOCTYPE rss PUBLIC \"-//Netscape Communications//DTD RSS 0.91//EN\" \"http://my.netscape.com/publish/formats/rss-0.91.dtd\"> <rss version=\"0.91\"> <channel>  <title>_#RSS_TITL</title>  <link>_#RSS_LINK</link>  <description>_#RSS_DESC</description>  <lastBuildDate>_#RSS_DATE</lastBuildDate> <language></language>', even=NULL, odd=' <item> <title>_#RSS_IT_T</title> <link>_#RSS_IT_L</link> <description>_#RSS_IT_D</description> </item>', even_odd_differ=NULL, after='</channel></rss>', remove_string=NULL, group_title=NULL, order1='publish_date....', o1_direction=0, order2='headline........', o2_direction=0, group_by1=NULL, g1_direction=NULL, group_by2=NULL, g2_direction=NULL, cond1field='source..........', cond1op='', cond1cond='', cond2field='', cond2op='<', cond2cond='', cond3field='', cond3op='<', cond3cond='', listlen=15, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional='NULL', aditional2='NULL', aditional3='NULL', aditional4='NULL', aditional5='NULL', aditional6='NULL', noitem_msg='<!DOCTYPE rss PUBLIC \"-//Netscape Communications//DTD RSS 0.91//EN\" \"http://my.netscape.com/publish/formats/rss-0.91.dtd\"> <rss version=\"0.91\"> <title>_#RSS_TITL</title>  <link>_#RSS_LINK</link>  <description>_#RSS_DESC</description>  <lastBuildDate>_#RSS_DATE</lastBuildDate> <language></language><channel></channel></rss>', group_bottom=NULL, field1=NULL, field2=NULL, field3=NULL, calendar_type='mon'";
$SQL_view_templates["calendar"]   = "view SET slice_id='AA_Core_Fields..', name='Calendar', type='calendar', `before`='<table border=1>\r\n<tr><td>Mon</td><td>Tue</td><td>Wen</td><td>Thu</td><td>Fri</td><td>Sat</td><td>Sun</td></tr>', even=NULL, odd='_#STARTDAT-_#END_DATE <b>_#HEADLINE</b>', even_odd_differ=1, after='</table>', remove_string='', group_title='<td><font size=+2><a href=\"calendar.shtml?vid=319&cmd[319]=c-1-_#CV_TST_2-2-_#CV_TST_1&month=_#CV_NUM_M&year=_#CV_NUM_Y&day=_#CV_NUM_D\"><b>_#CV_NUM_D</b></a></font></td>', order1='', o1_direction=0, order2='', o2_direction=0, group_by1=NULL, g1_direction=NULL, group_by2=NULL, g2_direction=NULL, cond1field='publish_date....', cond1op='<', cond1cond='', cond2field='', cond2op='<', cond2cond='', cond3field='', cond3op='<', cond3cond='', listlen=5, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional='<td><font size=+2>_#CV_NUM_D</font></td>', aditional2='', aditional3='bgcolor=\"_#COLOR___\"', aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='There are no events in this month.', group_bottom='', field1='start_date.....1', field2='end_date.......1', field3=NULL, calendar_type='mon_table'";
$SQL_view_templates['links']      = "view SET slice_id='AA_Core_Fields..', name='Links', type='links', `before`='<br>\r\n', even='', odd='<p><a href=\"_#L_URL___\" class=\"link\">_#L_NAME__ (_#L_O_NAME)</a><br>\r\n          _#L_DESCRI<br>\r\n          <a href=\"_#L_URL___\" class=\"link2\">_#L_URL___</a>\r\n     </p>\r\n', even_odd_differ=0, after='', remove_string='()', group_title='', order1='', o1_direction=0, order2=NULL, o2_direction=0, group_by1=NULL, g1_direction=0, group_by2=NULL, g2_direction=0, cond1field=NULL, cond1op='<', cond1cond=NULL, cond2field=NULL, cond2op='<', cond2cond=NULL, cond3field=NULL, cond3op='<', cond3cond=NULL, listlen=1000, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='<!-- no links in this category -->', group_bottom='', field1=NULL, field2=NULL, field3=NULL, calendar_type='mon'";
$SQL_view_templates['categories'] = "view SET slice_id='AA_Core_Fields..', name='Catategories', type='categories', `before`='     <br><b>_#C_PATH__</b><br><br>\r\n', even='', odd='<br>&#8226; <a href=\"?cat=_#CATEG_ID\" class=\"link\">_#C_NAME___#C_CROSS_</a>&nbsp;&nbsp;<b>(_#C_LCOUNT)</b>\r\n', even_odd_differ=0, after='', remove_string='', group_title='', order1='', o1_direction=0, order2='', o2_direction=0, group_by1='', g1_direction=0, group_by2='', g2_direction=0, cond1field='', cond1op='<', cond1cond='', cond2field='', cond2op='<', cond2cond='', cond3field='', cond3op='<', cond3cond='', listlen=1000, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='<!-- no categories in this category -->', group_bottom='', field1=NULL, field2=NULL, field3=NULL, calendar_type='mon'";
$SQL_view_templates['urls']       = "view SET slice_id='AA_Core_Fields..', name='URLs listing', type='urls', `before`='<!-- view used for listing URLs of items -->', even=NULL, odd='<a href=\"http://www.example.org/index.stm?x=_#SITEM_ID\">_#SITEM_ID</a><br>\r\n', even_odd_differ=0, after='', remove_string='', group_title='', order1='', o1_direction=0, order2='', o2_direction=0, group_by1='', g1_direction=0, group_by2='', g2_direction=0, cond1field='', cond1op='<', cond1cond='', cond2field='', cond2op='<', cond2cond='', cond3field='', cond3op='<', cond3cond='', listlen=100000, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='No item found', group_bottom=NULL, field1=NULL, field2=NULL, field3=NULL, calendar_type='mon'";
$SQL_view_templates['static']     ="view SET slice_id='AA_Core_Fields..', name='Static page', type='static', `before`=NULL, even=NULL, odd='<!-- Static page view is used for creating and viewing static pages like Contacts or About us.', even_odd_differ=NULL, after=NULL, remove_string=NULL, group_title=NULL, order1=NULL, o1_direction=NULL, order2=NULL, o2_direction=NULL, group_by1=NULL, g1_direction=NULL, group_by2=NULL, g2_direction=NULL, cond1field=NULL, cond1op=NULL, cond1cond=NULL, cond2field=NULL, cond2op=NULL, cond2cond=NULL, cond3field=NULL, cond3op=NULL, cond3cond=NULL, listlen=NULL, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg=NULL, group_bottom=NULL, field1=NULL, field2=NULL, field3=NULL, calendar_type='mon'";
$SQL_view_templates['full']       ="view SET slice_id='AA_Core_Fields..', name='Fulltext view', type='full', `before`='<!-- Fulltext view is for viewing long items. It shows only one selected item with abstract and fulltext. -->\r\n\r\n<!-- top of the page -->\r\n<br>', even=NULL, odd='<h2><b>_#HEADLINE</b></h2>\r\n_#PUB_DATE, _#AUTHOR__\r\n<br>\r\n_#FULLTEXT<br>\r\n<div align=\"right\"><a href=\"javascript:history.go(-1)\">Back</a></div>\r\n', even_odd_differ=NULL, after='', remove_string=NULL, group_title=NULL, order1=NULL, o1_direction=NULL, order2=NULL, o2_direction=NULL, group_by1=NULL, g1_direction=NULL, group_by2=NULL, g2_direction=NULL, cond1field='', cond1op='<', cond1cond='', cond2field='', cond2op='<', cond2cond='', cond3field='', cond3op='<', cond3cond='', listlen=NULL, scroller=NULL, selected_item=NULL, modification=NULL, parameter=NULL, img1=NULL, img2=NULL, img3=NULL, img4=NULL, flag=NULL, aditional=NULL, aditional2=NULL, aditional3=NULL, aditional4=NULL, aditional5=NULL, aditional6=NULL, noitem_msg='<p>No item found.</p>', group_bottom=NULL, field1=NULL, field2=NULL, field3=NULL, calendar_type='mon'";


// this is wrong! it deletes the priority field! Disabled for now.
/*
$SQL_update_modules[] = "REPLACE INTO module (id, name, deleted, type, slice_url, lang_file, created_at, created_by, owner, flag) SELECT id, name, deleted, 'S', slice_url, lang_file, created_at, created_by, owner, 0 FROM slice";
*/
$SQL_update_modules[] = "REPLACE INTO module  (id, name, deleted, type, slice_url, lang_file, created_at, created_by, owner, flag) VALUES ('SiteTemplate....', 'Site Template', 0, 'W', 'http://example.org/index.shtml', 'en_site_lang.php3', $now, '', '', 0)";
$SQL_update_modules[] = "REPLACE INTO site    (id, state_file, structure, flag) VALUES ('SiteTemplate....', 'template.php3', '".'O:8:"sitetree":2:{s:4:"tree";a:1:{i:1;O:4:"spot":8:{s:2:"id";s:1:"1";s:1:"n";s:5:"start";s:1:"c";N;s:1:"v";N;s:1:"p";s:1:"1";s:2:"po";a:1:{i:0;s:1:"1";}s:2:"ch";N;s:1:"f";i:0;}}s:8:"start_id";s:1:"1";}'."', 0)";

// Add the rows to cron only if no row with the script exists
$SQL_cron[] = array (
    "script" => 'modules/alerts/alerts.php3',
    "sql" => array (
        "INSERT INTO cron (minutes, hours, mday, mon, wday, script, params, last_run)
         VALUES ('0-60/5', '*', '*', '*', '*', 'modules/alerts/alerts.php3', 'howoften=instant', NULL)"),
    "script" => 'modules/alerts/alerts.php3');
$SQL_cron[] = array (
    "sql" => array (
        "INSERT INTO cron (minutes, hours, mday, mon, wday, script, params, last_run)
         VALUES ('8,23,38,53', '*', '*', '*', '*', 'admin/xmlclient.php3', '', NULL)"),
    "script" => 'admin/xmlclient.php3');
$SQL_cron[] = array (
    "sql" => array (
        "INSERT INTO cron (minutes, hours, mday, mon, wday, script, params, last_run)
         VALUES ('38',     '2', '*', '*', '2', 'misc/optimize.php3', 'key=".substr( DB_PASSWORD, 0, 5 )."', NULL)"),
    "script" => 'misc/optimize.php3');
$SQL_cron[] = array (
    "sql" => array (
        "INSERT INTO cron (minutes, hours, mday, mon, wday, script, params, last_run)
         VALUES ('1',     '0', '*', '*', '*', 'modules/mysql_auth/suspend.php3', '', NULL)"),
    "script" => 'modules/mysql_auth/suspend.php3');
$SQL_cron[] = array (
    "sql" => array (
        "INSERT INTO cron (minutes, hours, mday, mon, wday, script, params, last_run)
         VALUES ('35',     '*', '*', '*', '*', 'modules/links/linkcheck.php3', '', NULL)"),
    "script" => 'modules/links/linkcheck.php3');
$SQL_cron[] = array (
    "sql" => array (
        "INSERT INTO cron (minutes, hours, mday, mon, wday, script, params, last_run)
         VALUES ('0-60/2',     '*', '*', '*', '*', 'misc/toexecute.php3', '', NULL)"),
    "script" => 'misc/toexecute.php3');

$SQL_email_templates[] = "REPLACE INTO email (description, subject, body, header_from, reply_to, errors_to, sender, lang, html, type) VALUES ('Generic Alerts Welcome', 'Welcome to Econnect Alerts', 'Somebody requested to receive regularly new items from our web site \r\n<a href=\"http://www.ecn.cz\">www.ecn.cz</a>\r\n{switch({_#HOWOFTEN})instant:at the moment they are added\r\n:daily:once a day\r\n:weekly:once a week\r\n:monthly:once a month}.<br>\r\n<br>\r\nYou will not receive any emails until you confirm your subscription.\r\nTo confirm it or to change your personal info, please go to<br>\r\n<a href=\"_#COLLFORM\">_#COLLFORM</a>.<br><br>\r\nThank you for reading our alerts,<br>\r\nThe Econnect team\r\n', 'somebody@haha.cz', '', '', '', 'cz', 1, 'alerts welcome');";
$SQL_email_templates[] = "REPLACE INTO email (description, subject, body, header_from, reply_to, errors_to, sender, lang, html, type) VALUES ('Generic Alerts Alert', '{switch({_#HOWOFTEN})instant:News from Econnect::_#HOWOFTEN digest from Econnect}', '_#FILTERS_\r\n<br><hr>\r\nTo change your personal info, please go to<br>\r\n<a href=\"_#COLLFORM\">_#COLLFORM</a>.<br><br>\r\nThank you for reading our alerts,<br>\r\nThe Econnect team\r\n', 'econnect@team.cz', '', '', '', 'cz', 1, 'alerts alert');";
$SQL_email_templates[] = "REPLACE INTO email (description, subject, body, header_from, reply_to, errors_to, sender, lang, html, type) VALUES ('Generic Item Manager Welcome', 'Welcome, AA _#ROLE____', 'You have been assigned an Item Manager for the slice _#SLICNAME. Your username is _#LOGIN___. See <a href=\"http://apc-aa.sf.net/faq\">FAQ</a> for help.', '\"_#ME_NAME_\" <_#ME_MAIL_>', '', '', '', 'en', 1, 'slice wizard welcome');";


// ------------------- Links module setup ---------------------------------
$plinks_root_id = q_pack_id(Links_Category2SliceID(1));
$plinks_test_id = q_pack_id(Links_Category2SliceID(2));
$SQL_links_create[] = "REPLACE INTO module (id,                name,     deleted, type,   slice_url,                         lang_file,           created_at, created_by, owner, flag)
                                    VALUES ('$plinks_root_id', 'Links root', 0,  'Links', 'http://example.org/index.shtml', 'en_links_lang.php3', $now, '', '', 0)";
$SQL_links_create[] = "REPLACE INTO module (id,                name,     deleted, type,   slice_url,                         lang_file,           created_at, created_by, owner, flag)
                                    VALUES ('$plinks_test_id', 'Links example', 0,  'Links', 'http://example.org/index.shtml', 'en_links_lang.php3', $now, '', '', 0)";
$SQL_links_create[] = "REPLACE INTO links (id,            start_id, tree_start, select_start)
                                   VALUES ('$plinks_root_id', 1,        1,          1)";
$SQL_links_create[] = "REPLACE INTO links (id,            start_id, tree_start, select_start)
                                   VALUES ('$plinks_test_id', 2,        2,          2)";

$SQL_links_create[] = "REPLACE INTO links_categories (id, name, html_template, deleted, path, inc_file1, link_count)
                                              VALUES (1, 'Root', '',           'n',     '1',  '',        0)";
$SQL_links_create[] = "REPLACE INTO links_categories (id, name, html_template, deleted, path, inc_file1, link_count)
                                              VALUES (2, 'Example', '',        'n',     '1,2', '',       0)";

$SQL_links_create[] = "REPLACE INTO links_cat_cat (category_id, what_id, base, state, proposal, priority, proposal_delete, a_id)
                                           VALUES (1,           2,       'y',  'visible', 'n',  '5.00',   'n',             1)";

$SQL_links_create[] = "REPLACE INTO links_languages (id, name, short_name) VALUES (100, 'Czech', 'Cz')";
$SQL_links_create[] = "REPLACE INTO links_languages (id, name, short_name) VALUES (200, 'Deutsch', 'De')";
$SQL_links_create[] = "REPLACE INTO links_languages (id, name, short_name) VALUES (300, 'English', 'En')";
$SQL_links_create[] = "REPLACE INTO links_languages (id, name, short_name) VALUES (400, 'French', 'Fr')";
$SQL_links_create[] = "REPLACE INTO links_languages (id, name, short_name) VALUES (500, 'Hungarian', 'Hu')";
$SQL_links_create[] = "REPLACE INTO links_languages (id, name, short_name) VALUES (530, 'Italian', 'It')";
$SQL_links_create[] = "REPLACE INTO links_languages (id, name, short_name) VALUES (550, 'Japan', 'It')";
$SQL_links_create[] = "REPLACE INTO links_languages (id, name, short_name) VALUES (600, 'Portugal', 'Po')";
$SQL_links_create[] = "REPLACE INTO links_languages (id, name, short_name) VALUES (700, 'Slovak', 'Sl')";
$SQL_links_create[] = "REPLACE INTO links_languages (id, name, short_name) VALUES (800, 'Spanish', 'Sp')";
$SQL_links_create[] = "REPLACE INTO links_languages (id, name, short_name) VALUES (900, 'Romanian', 'Ro')";
$SQL_links_create[] = "REPLACE INTO links_languages (id, name, short_name) VALUES (950, 'Russian', 'Ru')";
$SQL_links_create[] = "REPLACE INTO links_languages (id, name, short_name) VALUES (999, 'Other', 'other')";

$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (1000, 'Africa', 1)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (1010, 'Kenya', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (1020, 'Nigeria', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (1030, 'Senegal', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (1040, 'South Africa', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (2000, 'Asia-Pacific', 1)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (2010, 'Australia', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (2020, 'Japan', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (2030, 'Philippines', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (2040, 'South Korea', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (3000, 'Central America', 1)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (3010, 'Nicaragua', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (4000, 'Europe', 1)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (4010, 'Bulgaria', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (4020, 'Czech Republic', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (4030, 'Germany', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (4040, 'Hungary', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (4050, 'Romania', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (4060, 'Slovakia', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (4070, 'Spain', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (4080, 'Ukraine', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (4090, 'United Kingdom', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (5000, 'North America', 1)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (5010, 'Canada', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (5020, 'Mexico', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (5030, 'USA', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (6000, 'South America', 1)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (6010, 'Argentina', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (6020, 'Brasil', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (6030, 'Colombia', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (6040, 'Ecuador', 2)";
$SQL_links_create[] = "REPLACE INTO links_regions (id, name, level) VALUES (6050, 'Uruguay', 2)";



// -------------------------------- Executive part -----------------------------
echo "<h2>update=".$update."</h2>";
if ( !$update AND !$restore AND !$restore_now) {
  echo '
  <!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN">
  <html>
  <head>
    <title>APC-AA database update script</title>
  </head>
  <body>

  <h1>APC-AA database update</h1>
  <p>This script is written to be not destructive. It creates temporary tables
     first, then copies data from old tables to the temporary ones (tmp_*) and
     after successfull copy it drops old tables and renames temporary ones to
     right names. Then it possibly updates common records (like default field
     definitions, APC-wide constants and templates).</p>
  <p><font color="red">However, it is strongly recommended backup your current
  database !!!</font><br><br>Something like:<br><code>mysqldump --lock-tables -u '.DB_USER.' -p --opt '.DB_NAME.' &gt; ./aadb/aadb.sql</code></p>

  <form name=f action="' .$_SERVER['PHP_SELF'] .'" method=post>
  <table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="#589868" align="center">
  <tr><td class=tabtit><b>&nbsp;APC-AA database update options</b>
  </td>
  </tr>
  <tr><td>
  <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="#A8C8B0">';
  FrmInputChBox("dbcreate", "Update DB structure", true, false, "", 1, false,
                "create or update database structure","");
  FrmInputChBox("copyold", "Copy current data", true, false, "", 1, false,
                "copy data from current database to the updated one","");
  FrmInputChBox("backup", "Backup current tables", true, false, "", 1, false,
                "left the current tables in database named like bck_xxx","");
  FrmInputChBox("replacecateg", "Refresh APC-wide categories", true, false, "", 1, false,
                "updates APC-wide (parent) categories","");
  FrmInputChBox("replaceconst", "Refresh other constants", true, false, "", 1, false,
                "updates Codepage constants, Language constants, Bin constants","");
  FrmInputChBox("newcore", "Redefine field defaults", true, false, "", 1, false,
                "Updates field templates, which is used when you adding new field to slice","");
  FrmInputChBox("templates", "Redefine slice templates", true, false, "", 1, false,
                "Updates only slice templates, which is in standard AA installation","");
  FrmInputChBox("view_templates", "Add new view templates", true, false, "", 1, false,
                "Templates for javascript, constans, discussions views, ... you can see in 'ActionApps Core' slice. If you haven't defined any view of some type, the templates are used as default values for new views.","");
  FrmInputChBox("view_templates_rewrite_existing", "Overwrite existing templates", false,false, "", 1, false,
                "Check this, if you want overwrite existing view templates with new ones. Default is not to overwrite (unchecked)");
  FrmInputChBox("addstatistic", "Add statistic fields (fix)", true, false, "", 1, false,
                "New fields (display_count, disc_count, disc_app) in v1.8 should be added to all slice definitions","");
  FrmInputChBox("additemidfields", "Add item id fields (fix)", true, false, "", 1, false,
                "Add 'id' and 'short_id' definitions to fields tabl for each slice, where the definition is missing. It allows searchbar in Item Manager to create filter also on Short id field, ...","");
  FrmInputChBox("fixmissingfields", "Fix missing fields fields", true, false, "", 1, false,
                "Add missing mandatory fields in slices (status_code.....)","");
  FrmInputChBox("update_modules", "Update modules table", true, false, "", 1, false,
                "AA version >2.1 supports management not only slices, but other modules too. Module table holds IDs of modules (just like slice IDs), which should be copied from module tables (table slice). The default site and poll module is also created/renewed with this option.","");
  FrmInputChBox("cron", "Add entries to Cron", true, false, "", 1, false,
                "Alerts, cross server networking and database optimization are run by cron.php3, their entries are added to table cron if not yet there.");
  FrmInputChBox("generic_emails", "Add generic email templates", true, false, "", 1, false,
                "These are examples of each email type.");
  FrmInputChBox("links_create", "Add Links module", true, false, "", 1, false,
                "Before you can use Links module, you have to prepare database for it (create root category). This option create root category if it is not set already.");
  FrmStaticText("", "<hr>", false, "", "", false );
  FrmInputChBox("fire", "Write to database", false, false, "", 1, false,
                "Check this for real work with writing to database","");
  FrmStaticText("", "<hr>", false, "", "", false );
  FrmStaticText("", "Before you write the data into database, please check if following data are correct. If not, please correct it in config.php3 file.<br>
                     <b>AA http domain:</b> ". AA_HTTP_DOMAIN ."<br>
                     <b>AA image path:</b> $AA_IMG_URL<br>
                     <b>AA doc path:</b> $AA_DOC_URL<br>
                     <b>Administrator's e-mail:</b> ".ERROR_REPORTING_EMAIL."<br>", false, "", "", false );
  FrmInputText("dbpw5", "5 characters of database password", "", 5, 5, false,
                "Fill in first five characters of the database password (see DB_PASSWORD in config.php3 file) - it is from security reasons");
  echo '
  </table></td></tr>
  <tr><td align="center">
    <input type=submit name=update value="Run Update">
    <input type=submit name=restore value="Restore Data from Backup Tables">
  </td></tr></table>
  </FORM>
  </body>
  </html>
  ';
  exit;
}


if ( substr( DB_PASSWORD, 0, 5 ) != $dbpw5 ) {
  echo '
    <!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN">
    <html>
    <head>
        <title>APC-AA database update script - bad password</title>
    </head>
    <body>

    <h1>APC AA</h1>

    <form name=f action="' .$_SERVER['PHP_SELF'] .'" >
    <table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="#589868" align="center">
    <tr><td class=tabtit><b>&nbsp;Bad password. Please fill "first five characters from aa database password (DB_PASSWORD in config.php3 file)".</b></td></tr>
    <tr><td align="center">
      <input type=hidden name=dbpw5 value="'.$dbpw5.'">
      <input type=submit name=xxxx value="Back">
    </td></tr></table>
    </FORM>
    </body>
    </html>';
  exit;
}


if ( $restore ) {
  echo '
    <!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN">
    <html>
    <head>
        <title>APC-AA database restore script</title>
    </head>
    <body>

    <h1>APC-AA database restore</h1>

    <p>This script DELETES all the current tables (slice, item, ...) and then renames all backup tables (bck_slice, bck_item, ...) to right names (slice, item, ...). So, there MUST be bck_* tables if you want to have some content in database.</p>
    <form name=f action="' .$_SERVER['PHP_SELF'] .'">
    <table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="#589868" align="center">
    <tr><td class=tabtit><b>&nbsp;Are you sure you want to restore tables?</b></td></tr>
    <tr><td align="center">
      <input type=hidden name=dbpw5 value="'.$dbpw5.'">
      <input type=submit name=restore_now value="Yes">
      <input type=submit name=xxxx value="No">
    </td></tr></table>
    </FORM>
    </body>
    </html>';
  exit;
}

if ( $restore_now ) {
  echo '
    <!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN">
    <html>
    <head>
        <title>APC-AA database restore script</title>
    </head>
    <body>

    <h1>APC-AA database restore</h1>

    <p>Resoring ...</p>';

  echo '<h2>Replace tables with bck_* tables</h2>';
  reset( $tablelist );
  $store_halt = $db->Halt_On_Error;
  $db->Halt_On_Error = "report";
  while ( list($t) = each( $tablelist ) ) {
    $SQL = "DROP TABLE IF EXISTS `$t`";
    safe_echo($SQL);
    myquery($db, $SQL);
    $SQL = "ALTER TABLE `bck_$t` RENAME `$t`";
    safe_echo($SQL);
    myquery($db, $SQL);
  }
  $db->Halt_On_Error = $store_halt;

  echo '<h2>Restore OK</h2>
        </body>
        </html>';

  exit;
}

echo '
  <!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN">
  <html>
  <head>
    <title>APC-AA database update script</title>
  </head>
  <body>

  <h1>APC-AA database update</h1>
  <p>Updating ...</p>';

if ( $dbcreate ) {
  // this script copies data from old tables to temp tables,
  // and then replaces the old tables with the temp tables.
  // if an old table is 'missing' it will cause an error,
  // so here, we create missing old tables.

  echo '<h2>Delete temporary tables if exists</h2>';
  reset( $tablelist );
  while ( list($t) = each( $tablelist ) ) {
    $SQL = "DROP TABLE IF EXISTS `tmp_$t`";
    safe_echo($SQL);
    myquery($db, $SQL);
  }

  echo '<h2>Creating temporary databases</h2>';
  reset( $tablelist );
  while ( list( $t, $def) = each( $tablelist ) ) {
    // remove COMMENTS (MySQL 3 do not support it
    $def = preg_replace("/COMMENT\s+\'[^']+\'/im", "", $def);

    $SQL = "  CREATE TABLE IF NOT EXISTS `tmp_$t` $def";
    safe_echo($SQL);
    myquery($db, $SQL );
  }

  echo '<h2>Creating new tables that do not exist in database</h2>';
  reset( $tablelist );
  while ( list( $t, $def ) = each( $tablelist ) ) {
    // remove COMMENTS (MySQL 3 do not support it
    $def = preg_replace("/COMMENT\s+\'[^']+\'/im", "", $def);

    $SQL = "  CREATE TABLE IF NOT EXISTS `$t` $def";
    safe_echo($SQL);
    myquery($db, $SQL );
  }
}

if ( $copyold ) {
  echo '<h2>Copying old values to new tables </h2>';
  reset( $tablelist );
  $store_halt = $db->Halt_On_Error;
  $db->Halt_On_Error = "report";

  while ( list($t) = each( $tablelist ) ) {   // copy all tables
    unset($old_info);
    unset($tmp_info);
    $old_info = $db->metadata( $t );
    $tmp_info = $db->metadata( "tmp_$t" );

    if ( isset( $old_info ) AND is_array($old_info) ) {
      $delim = "";
      $field_list = "";
      while ( list ( ,$fld ) = each ($tmp_info)) {  //construct field list
        if ( IsPaired($fld[name], $old_info) )
          $field_list .= $delim . '`'. $fld['name'] .'`';
         else
          $field_list .= $delim .( strstr($fld['flags'],"not_null") ? '" "':'""');
        $delim = ",";
      }
      if ( $field_list == "")
        $field_list = "*" ;
      $SQL = "INSERT INTO `tmp_$t` SELECT $field_list FROM `$t`";
      safe_echo($SQL);
      myquery($db, $SQL);
    }
  }
  $db->Halt_On_Error = $store_halt;
}

if ( $backup )
  echo '<h2>Backup old tables to bck_xxxx tables and use new tables instead</h2>';
 else
  echo '<h2>delete old tables and use new tables instead</h2>';

if ( $dbcreate ) {
  reset( $tablelist );
  $store_halt = $db->Halt_On_Error;
  $db->Halt_On_Error = "report";

  while ( list($t) = each( $tablelist ) ) {
    if ( $backup ) {
      $SQL = "DROP TABLE IF EXISTS `bck_$t`";
      safe_echo($SQL);
      myquery($db, $SQL);

      $SQL = "ALTER TABLE `$t` RENAME `bck_$t`";
      safe_echo($SQL);
      myquery($db, $SQL);
    }
    $SQL = "DROP TABLE IF EXISTS `$t`";
    safe_echo($SQL);
    myquery($db, $SQL);

    $SQL = "ALTER TABLE `tmp_$t` RENAME `$t`";
    safe_echo($SQL);
    myquery($db, $SQL);
  }
  $db->Halt_On_Error = $store_halt;
}

if ( $addstatistic ) {
  echo '<h2>Add statistic and discusion count field field for each slice</h2>';
  $SQL = "SELECT slice.id FROM slice LEFT JOIN field ON
          (slice.id=field.slice_id AND field.id IN ('display_count...', 'disc_count......', 'disc_app........'))
          WHERE field.id IS NULL";  // get only slices with not defined di... fields
  $db->query( $SQL );
  while ( $db->next_record() ) {
    if ( $db->f('id') == 'AA_Core_Fields..' )
      continue;
    $SQL = "REPLACE INTO field VALUES( 'display_count...', '', '". quote($db->f(id)) ."', 'Displayed Times', '5050', 'Internal field - do not change', '${AA_DOC_URL}help.html', 'qte:0', '1', '1', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#DISPL_NO', 'f_h', 'alias for number of displaying of this item', '', '', '', '', '', '', '', '', '0', '0', '0', 'display_count', '', 'nul', '0', '1')";
    safe_echo($SQL);
    myquery($db2, $SQL );
    $SQL = "REPLACE INTO field VALUES( 'disc_count......', '', '". quote($db->f(id)) ."', 'Comments Count', '5060', 'Internal field - do not change', '${AA_DOC_URL}help.html', 'qte:0', '1', '1', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#D_ALLCNT', 'f_h', 'alias for number of all discussion comments for this item', '', '', '', '', '', '', '', '', '0', '0', '0', 'disc_count', '', 'nul', '0', '1')";
    safe_echo($SQL);
    myquery($db2, $SQL );
    $SQL = "REPLACE INTO field VALUES( 'disc_app........', '', '". quote($db->f(id)) ."', 'Approved Comments Count', '5070', 'Internal field - do not change', '${AA_DOC_URL}help.html', 'qte:0', '1', '1', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#D_APPCNT', 'f_h', 'alias for number of approved discussion comments for this item', '', '', '', '', '', '', '', '', '0', '0', '0', 'disc_app', '', 'nul', '0', '1')";
    safe_echo($SQL);
    myquery($db2, $SQL );
  }
}

if ( $additemidfields ) {
  echo '<h2>Add short_id and id field definition to fields table (for all slices)</h2>';
  $SQL = "SELECT slice.id FROM slice LEFT JOIN field ON
          (slice.id=field.slice_id AND field.id IN ('id..............', 'short_id........'))
          WHERE field.id IS NULL";  // get only slices with not defined di... fields
  $db->query( $SQL );
  while ( $db->next_record() ) {
    if ( $db->f('id') == 'AA_Core_Fields..' )
      continue;

    $SQL = "REPLACE INTO field VALUES ('id..............', '', '". quote($db->f('id')) ."', 'Long ID', 5080, 'Internal field - do not change', '${AA_DOC_URL}help.html', 'txt:', 0, 0, 0, 'nul', '', 0, '', '', '', '', 1, 1, 1, '_#ITEM_ID_', 'f_n:', 'alias for Long Item ID', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'id', '', 'nul', 0, 1)";
    safe_echo($SQL);
    myquery($db2, $SQL );
    $SQL = "REPLACE INTO field VALUES ('short_id........', '', '". quote($db->f('id')) ."', 'Short ID', 5090, 'Internal field - do not change', '${AA_DOC_URL}help.html', 'txt:', 0, 0, 0, 'nul', '', 100, '', '', '', '', 1, 1, 1, '_#SITEM_ID', 'f_t:', 'alias for Short Item ID', '', 'f_0:', '', '', 'f_0:', '', '', '', 0, 0, 0, 'short_id', '', 'nul', 0, 0)";
    safe_echo($SQL);
    myquery($db2, $SQL );
  }
}


if ( $fixmissingfields ) {
  echo '<h2>Fixing missing mandatory fields in slices</h2>';
  $SQL = "SELECT slice.id FROM slice LEFT JOIN field ON
          (slice.id=field.slice_id AND field.id='status_code.....')
          WHERE field.id IS NULL";  // get only slices with not defined status_code field
  $db->query( $SQL );
  while ( $db->next_record() ) {
    if ( $db->f('id') == 'AA_Core_Fields..' )
      continue;
    $SQL = "REPLACE INTO field (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored) VALUES( 'status_code.....', '',\"";
    $SQL .= quote($db->f(id));
    $SQL .= "\", 'Status', '5020', '', '${AA_DOC_URL}help.html', 'qte:1', '1', '0', '0', 'sel:AA_Core_Bins....', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', 'status_code', 'number', 'num', '0', '0')";
    safe_echo($SQL);
    myquery($db2, $SQL );
  }
}

if ( $replacecateg ) {
  echo '<h2>Updating APC wide categories</h2>';
  reset( $SQL_apc_categ );
  while ( list( ,$SQL) = each( $SQL_apc_categ ) ) {
    safe_echo($SQL);
    myquery($db, $SQL );
  }
}

if ( $replaceconst ) {
  echo '<h2>Updating Constants</h2>';
  reset( $SQL_constants );
  while ( list( ,$SQL) = each( $SQL_constants ) ) {
    safe_echo($SQL);
    myquery($db, $SQL );
  }
}

if ( $newcore ) {
  echo '<h2>Updating Core field definitions</h2>';
  reset( $SQL_aacore );
  while ( list( ,$SQL) = each( $SQL_aacore ) ) {
    safe_echo($SQL);
    myquery($db, $SQL );
  }
}

if ( $templates ) {
  echo '<h2>Updating Slice templates</h2>';
  reset( $SQL_templates );
  while ( list( ,$SQL) = each( $SQL_templates ) ) {
    safe_echo($SQL);
    myquery($db, $SQL );
  }
}

if ( $view_templates ) {
    $SQL = "SELECT * FROM view WHERE slice_id='AA_Core_Fields..'";
    $select = GetTable2Array($SQL, "type");
//    print_r($select);
    if ($view_templates_rewrite_existing) {
        echo '<h2>Replacing existing View templates</h2>';
        foreach ($select as $key => $val) {
            if ( !$SQL_view_templates[$key] ) continue;
            $SQL = "UPDATE ".$SQL_view_templates[$key]." WHERE id='".$val['id']."'";
            safe_echo( $SQL );
            myquery($db, $SQL );
        }
    }
    echo '<h2>Adding new View templates</h2>';
    foreach ($SQL_view_templates as $key=>$val) {
        if ( !$select[$key] ) {
            $SQL = "INSERT ".$SQL_view_templates[$key];
            safe_echo($SQL);
            myquery($db, $SQL );
        }
    }
}

if ( $update_modules ) {
  echo '<h2>Updating Modules table</h2>';
  reset( $SQL_update_modules );
  while ( list( ,$SQL) = each( $SQL_update_modules ) ) {
    safe_echo($SQL);
    myquery($db, $SQL );
  }
}


if ( $cron ) {
  echo '<h2>Adding to Cron table</h2>';
  reset( $SQL_cron );
  while ( list( ,$cron_entry) = each( $SQL_cron ) ) {
    $db->query("SELECT * FROM cron WHERE script='$cron_entry[script]'");
    if (! $db->next_record()) {
        foreach ($cron_entry["sql"] as $sql) {
            safe_echo( $sql );
            myquery($db, $sql );
        }
    }
  }
}

if ( $generic_emails ) {
  echo '<h2>Add generic mail templates</h2>';
  reset ($SQL_email_templates);
  while (list (, $SQL) = each ($SQL_email_templates)) {
    safe_echo($SQL);
    myquery($db, $SQL );
  }
}

if ( $links_create ) {
  echo '<h2>Update Links module</h2>';
  $db->query("SELECT * FROM links");
  if (! $db->next_record()) {          // only if links are not installed
    reset ($SQL_links_create);
    while (list (, $SQL) = each ($SQL_links_create)) {
      safe_echo($SQL);
      myquery($db, $SQL );
    }
  }
}


echo '<h2>Update OK</h2>
      </body>
      </html>';

?>
