DROP TABLE IF EXISTS active_sessions;
DROP TABLE IF EXISTS constant       ;
DROP TABLE IF EXISTS content        ;
DROP TABLE IF EXISTS db_sequence    ;
DROP TABLE IF EXISTS email_auto_user;
DROP TABLE IF EXISTS email_notify   ;
DROP TABLE IF EXISTS feedperms      ;
DROP TABLE IF EXISTS feeds          ;
DROP TABLE IF EXISTS field          ;
DROP TABLE IF EXISTS groups         ;
DROP TABLE IF EXISTS item           ;
DROP TABLE IF EXISTS log            ;
DROP TABLE IF EXISTS membership     ;
DROP TABLE IF EXISTS perms          ;
DROP TABLE IF EXISTS slice          ;
DROP TABLE IF EXISTS slice_owner    ;
DROP TABLE IF EXISTS subscriptions  ;
DROP TABLE IF EXISTS users          ;
DROP TABLE IF EXISTS offline        ;
DROP TABLE IF EXISTS feedmap        ;
DROP TABLE IF EXISTS relation       ;
DROP TABLE IF EXISTS pagecache      ;
DROP TABLE IF EXISTS profile        ;
DROP TABLE IF EXISTS view           ;
DROP TABLE IF EXISTS discussion     ;
DROP TABLE IF EXISTS nodes          ;
DROP TABLE IF EXISTS external_feeds ;
DROP TABLE IF EXISTS ef_categories  ;
DROP TABLE IF EXISTS ef_permissions ;

# 11/26/01 - added profile table
#          - added notify_holding_item_s, notify_holding_item_b, 
#            notify_holding_item_edit_s, notify_holding_item_edit_b, 
#            notify_active_item_edit_s, notify_active_item_edit_b,
#            notify_active_item_s, notify_active_item_b, noitem_msg
#            into slice table
#          - added aditional2, aditional3, aditional4, aditional5, aditional6,
#            noitem_msg into profile table
# 11/13/01 - changed external_feeds.newest_item from bigint to varchar(40)
# 09/26/01 - added "nodes" table
#          - added "external_feeds" table
#          - added "ef_categories" table
#          - added "ef_permissions" table
#          - added "from_field_name" to "feedmap" table
# 08/16/01 - added "discussion" table
#          - added "disc_count" and "disc_app" fields to item table
#          - added "vid" in slice table (for discussion view id)
#          - type of selected_item in view table changed to text
# 06/21/01 - added "value mediumtext" to feedmap table
# 06/01/01 - added display_count, short_id and flags to item table
#          - longer value in constant table (150 -> 255)
# 05/30/01 - new sql_update.php3 script updating current database instalation
# 05/13/01 - added view table
# 0x/0x/01 - add feedmap table
#          - add relation table
#          - add flag in feedperms
#          - add offline table, relation table, aditional and flag field in slice table
#          - grab_len and redirect removed

# --------------------------------------------------------
# Table structure for table 'active_sessions'

CREATE TABLE active_sessions (
   sid varchar(32) NOT NULL,
   name varchar(32) NOT NULL,
   val text,
   changed varchar(14) NOT NULL,
   PRIMARY KEY (name, sid),
   KEY changed (changed)
);

# --------------------------------------------------------
# Table structure for table 'offline' 
# This table holds information about items, which is off-line filled
# (such items have no identificating id before feed, so it must be created for
# them; The identification of off-line filled items are done with digest - md5
# of whole item content (prvent from multiple uploading of the same item))

CREATE TABLE offline (
   id char(16) NOT NULL,
   digest char(32) NOT NULL,
   flag int(11),
   PRIMARY KEY (id),
   KEY digest (digest)
);

# --------------------------------------------------------
# Table structure for table 'constant'

CREATE TABLE constant (
   id char(16) NOT NULL,
   group_id char(16) NOT NULL,
   name char(150) NOT NULL,
   value char(255) NOT NULL,
   class char(16),
   pri smallint(5) DEFAULT '100' NOT NULL,
   PRIMARY KEY (id),
   KEY group_id (group_id)
);

# --------------------------------------------------------
# Table structure for table 'content'

CREATE TABLE content (
   item_id varchar(16) NOT NULL,
   field_id varchar(16) NOT NULL,
   number bigint(20),
   text mediumtext,
   flag smallint(6),
   KEY slice_id (item_id, field_id)
);


# --------------------------------------------------------
# Table structure for table 'db_sequence'

CREATE TABLE db_sequence (
   seq_name varchar(127) NOT NULL,
   nextid int(10) unsigned DEFAULT '0' NOT NULL,
   PRIMARY KEY (seq_name)
);


# --------------------------------------------------------
# Table structure for table 'discussion'

CREATE TABLE discussion (
   id varchar(16) NOT NULL,
   parent varchar(16) NOT NULL,
   item_id varchar(16) NOT NULL,
   date bigint(20) NOT NULL,
   subject text,
   author varchar(60),
   e_mail varchar(255),
   body text,
   state int(11) NOT NULL,
   flag int(11) NOT NULL,
   free1 text,
   free2 text,
   url_address varchar(255),
   url_description varchar(255),
   remote_addr varchar(255),
   PRIMARY KEY (id),
   UNIQUE id (id)
);


# --------------------------------------------------------
# Table structure for table 'ef_categories'

CREATE TABLE ef_categories (
   category varchar(255) NOT NULL,
   category_name varchar(255) NOT NULL,
   category_id varchar(16) NOT NULL,
   feed_id int(11) NOT NULL,
   target_category_id varchar(16) NOT NULL,
   approved int(11) NOT NULL,
   PRIMARY KEY (category_id, feed_id)
);


# --------------------------------------------------------
# Table structure for table 'ef_permissions'

CREATE TABLE ef_permissions (
   slice_id varchar(16) NOT NULL,
   node varchar(150) NOT NULL,
   user varchar(50) NOT NULL,
   PRIMARY KEY (slice_id, node, user)
);


# --------------------------------------------------------
# Table structure for table 'email_auto_user'

CREATE TABLE email_auto_user (
   uid char(50) NOT NULL,
   creation_time bigint(20) DEFAULT '0' NOT NULL,
   last_change bigint(20) DEFAULT '0' NOT NULL,
   clear_pw char(40),
   confirmed smallint(5) DEFAULT '0' NOT NULL,
   confirm_key char(16),
   PRIMARY KEY (uid)
);


# --------------------------------------------------------
# Table structure for table 'email_notify'

CREATE TABLE email_notify (
   slice_id char(16) NOT NULL,
   uid char(60) NOT NULL,
   function smallint(5) DEFAULT '0' NOT NULL,
   PRIMARY KEY (slice_id, uid, function),
   KEY slice_id (slice_id)
);


# --------------------------------------------------------
# Table structure for table 'external_feeds'

CREATE TABLE external_feeds (
   feed_id int(11) NOT NULL auto_increment,
   slice_id varchar(16) NOT NULL,
   node_name varchar(150) NOT NULL,
   remote_slice_id varchar(16) NOT NULL,
   user_id varchar(200) NOT NULL,
   newest_item varchar(40) NOT NULL,
   remote_slice_name varchar(200) NOT NULL,
   PRIMARY KEY (feed_id)
);


# --------------------------------------------------------
#
# Table structure for table 'feedmap'

CREATE TABLE feedmap (
   from_slice_id varchar(16) NOT NULL,
   from_field_id varchar(16) NOT NULL,
   to_slice_id varchar(16) NOT NULL,
   to_field_id varchar(16) NOT NULL,
   flag int(11),
   value mediumtext,
   from_field_name varchar(255) NOT NULL,
   KEY from_slice_id (from_slice_id, to_slice_id)
);


# --------------------------------------------------------
# Table structure for table 'feedperms'

CREATE TABLE feedperms (
   from_id varchar(16) NOT NULL,
   to_id varchar(16) NOT NULL,
   flag int(11)
);


# --------------------------------------------------------
# Table structure for table 'feeds'

CREATE TABLE feeds (
   from_id varchar(16) NOT NULL,
   to_id varchar(16) NOT NULL,
   category_id varchar(16),
   all_categories smallint(5),
   to_approved smallint(5),
   to_category_id varchar(16),
   KEY from_id (from_id)
);


# --------------------------------------------------------
# Table structure for table 'field'

CREATE TABLE field (
   id varchar(16) NOT NULL,
   type varchar(16) NOT NULL,
   slice_id varchar(16) NOT NULL,
   name varchar(255) NOT NULL,
   input_pri smallint(5) DEFAULT '100' NOT NULL,
   input_help varchar(255),
   input_morehlp text,
   input_default mediumtext,
   required smallint(5),
   feed smallint(5),              # three state Feedable=0/Unfeedable=1/Feedable-Unchangeable=2
   multiple smallint(5),
   input_show_func varchar(255),
   content_id varchar(16),
   search_pri smallint(5) DEFAULT '100' NOT NULL,
   search_type varchar(16),
   search_help varchar(255),
   search_before text,
   search_more_help text,
   search_show smallint(5),
   search_ft_show smallint(5),
   search_ft_default smallint(5),
   alias1 varchar(10),
   alias1_func varchar(255),
   alias1_help varchar(255),
   alias2 varchar(10),
   alias2_func varchar(255),
   alias2_help varchar(255),
   alias3 varchar(10),
   alias3_func varchar(255),
   alias3_help varchar(255),
   input_before text,
   aditional text,
   content_edit smallint(5),
   html_default smallint(5),
   html_show smallint(5),
   in_item_tbl varchar(16),
   input_validate varchar(16) NOT NULL,
   input_insert_func varchar(255) NOT NULL,
   input_show smallint(5),
   text_stored smallint(5) DEFAULT '1',
   KEY slice_id (slice_id, id)
);

# --------------------------------------------------------
# Table structure for table 'groups'

CREATE TABLE groups (
   name varchar(32) NOT NULL,
   description varchar(255) NOT NULL,
   PRIMARY KEY (name)
);

# --------------------------------------------------------
# Table structure for table 'item'

CREATE TABLE item (
   id char(16) NOT NULL,
   short_id int(11) NOT NULL auto_increment,     # used for short url link
   slice_id char(16) NOT NULL,
   status_code smallint(5) DEFAULT '0' NOT NULL,
   post_date bigint(20) DEFAULT '0' NOT NULL,
   publish_date bigint(20),
   expiry_date bigint(20),
   highlight smallint(5),
   posted_by char(60),
   edited_by char(60),
   last_edit bigint(20),
   display_count int(11) DEFAULT '0' NOT NULL,   # log information of how many times it was displayed
   flags char(30),                               # item flags for future ussage 
   disc_count int(11) DEFAULT '0',               # number of discuss comments for this item
   disc_app int(11) DEFAULT '0',                 # number of approved discuss comments
   externally_fed char(150),
   PRIMARY KEY (id),
   KEY short_id (short_id)
);

# --------------------------------------------------------
# Table structure for table 'log'

CREATE TABLE log (
   id int(11) DEFAULT '0' NOT NULL auto_increment,
   time bigint(20) DEFAULT '0' NOT NULL,
   user char(60) NOT NULL,
   type char(10) NOT NULL,
   params char(128),
   PRIMARY KEY (id),
   KEY time (time)
);


# --------------------------------------------------------
# Table structure for table 'membership'

CREATE TABLE membership (
   groupid int(11) DEFAULT '0' NOT NULL,
   memberid int(11) DEFAULT '0' NOT NULL,
   last_mod timestamp(14),
   PRIMARY KEY (groupid, memberid),
   KEY memberid (memberid)
);

# --------------------------------------------------------
# Table structure for table 'nodes'

CREATE TABLE nodes (
   name varchar(150) NOT NULL,
   server_url varchar(200) NOT NULL,
   password varchar(50) NOT NULL,
   PRIMARY KEY (name)
);


# --------------------------------------------------------
# Table structure for table 'offline'



# --------------------------------------------------------
#
# Table structure for table 'pagecache'

CREATE TABLE pagecache (
   id varchar(32) NOT NULL,
   str2find text,
   content mediumtext,
   stored bigint(20) NOT NULL,
   flag int(11),
   PRIMARY KEY (id),
   KEY stored (stored)
);

# --------------------------------------------------------
# Table structure for table 'perms'

CREATE TABLE perms (
   object_type char(30) NOT NULL,
   objectid char(32) NOT NULL,
   userid int(11) DEFAULT '0' NOT NULL,
   perm char(32) NOT NULL,
   last_mod timestamp(14),
   PRIMARY KEY (objectid, userid, object_type),
   KEY userid (userid)
);


# --------------------------------------------------------
# Table structure for table 'profile'
# Table used for storing user profiles (user preferences in admin interface)

CREATE TABLE profile (
   id int(11) NOT NULL auto_increment,
   slice_id varchar(16) NOT NULL,
   uid varchar(60) DEFAULT '*' NOT NULL,   # user id (number for SQL permission system, uid=... string for LDAP permissions. '*' stands for default user setting
   property varchar(20) NOT NULL,          # one of: listlen, admin_search, admin_order, hide, hide&fill, fill, predefine
   selector varchar(255),                  # field_id if needed
   value text,                             # value of property
   PRIMARY KEY (id),
   KEY slice_user_id (slice_id, uid)
);


# --------------------------------------------------------
# Table structure for table 'relation'
# Table used for storing relations between items (could hold feeding info,
# discussion threads, list of related items ...)

CREATE TABLE relation (
   source_id char(16) NOT NULL,
   destination_id char(32) NOT NULL,
   flag int(11),
   KEY source_id (source_id),
   KEY destination_id (destination_id)
);

# --------------------------------------------------------
# Table structure for table 'slice'

CREATE TABLE slice (
   id varchar(16) NOT NULL,
   name varchar(100) NOT NULL,
   owner varchar(16),
   deleted smallint(5),
   created_by varchar(255),
   created_at bigint(20),
   export_to_all smallint(5),
   type varchar(16),
   template smallint(5),
   fulltext_format_top text,
   fulltext_format text,
   fulltext_format_bottom text,
   odd_row_format text,
   even_row_format text,
   even_odd_differ smallint(5),
   compact_top text,
   compact_bottom text,
   category_top text,
   category_format text,
   category_bottom text,
   category_sort smallint(5),
   config text NOT NULL,
   slice_url varchar(255),
   d_expiry_limit smallint(5),
   d_listlen smallint(5),
   lang_file varchar(50),
   fulltext_remove text,
   compact_remove text,
   email_sub_enable smallint(5),
   exclude_from_dir smallint(5),
   notify_sh_offer mediumtext,
   notify_sh_accept mediumtext,
   notify_sh_remove mediumtext,
   notify_holding_item_s mediumtext,
   notify_holding_item_b mediumtext,
   notify_holding_item_edit_s mediumtext,
   notify_holding_item_edit_b mediumtext,
   notify_active_item_edit_s mediumtext,
   notify_active_item_edit_b mediumtext,
   notify_active_item_s mediumtext,
   notify_active_item_b mediumtext,
   noitem_msg mediumtext,                # html text shown if no item found
   admin_format_top text,
   admin_format text,
   admin_format_bottom text,
   admin_remove text,
   permit_anonymous_post smallint(5),  # 0 - forbidden, 1 - allowed to approved, 2 - allowed to holding bin
   permit_offline_fill smallint(5),    # 0 - forbidden, 1 - allowed to approved, 2 - allowed to holding bin
   aditional text,
   flag int DEFAULT '0' NOT NULL,    
   vid int DEFAULT '0',
   PRIMARY KEY (id)
);


# --------------------------------------------------------
# Table structure for table 'slice_owner'

CREATE TABLE slice_owner (
   id char(16) NOT NULL,
   name char(80) NOT NULL,
   email char(80) NOT NULL,
   PRIMARY KEY (id)
);


# --------------------------------------------------------
# Table structure for table 'subscriptions'

CREATE TABLE subscriptions (
   uid char(50) NOT NULL,
   category char(16),
   content_type char(16),
   slice_owner char(16),
   frequency smallint(5) DEFAULT '0' NOT NULL,
   last_post bigint(20) DEFAULT '0' NOT NULL,
   KEY uid (uid, frequency)
);

# --------------------------------------------------------
# Table structure for table 'users'

CREATE TABLE users (
   id int(11) DEFAULT '0' NOT NULL auto_increment,
   type char(10) NOT NULL,
   password char(30) NOT NULL,
   uid char(40) NOT NULL,
   mail char(40) NOT NULL,
   name char(80) NOT NULL,
   description char(255) NOT NULL,
   givenname char(40) NOT NULL,
   sn char(40) NOT NULL,
   last_mod timestamp(14),
   PRIMARY KEY (id),
   KEY type (type),
   KEY mail (mail),
   KEY name (name),
   KEY sn (sn)
);

# --------------------------------------------------------
# Table structure for table 'view'

CREATE TABLE view (
   id int(10) unsigned NOT NULL auto_increment,
   slice_id varchar(16) NOT NULL,
   name varchar(50),                # name of view
   type varchar(10),                # type of view (fulltext, digest, rss, ...)
   before text,
   even text,
   odd text,
   even_odd_differ tinyint(3) unsigned,
   after text,
   remove_string text,
   group_title text,
   order1 varchar(16),
   o1_direction tinyint(3) unsigned,
   order2 varchar(16),
   o2_direction tinyint(3) unsigned,
   group_by1 varchar(16),
   g1_direction tinyint(3) unsigned,
   group_by2 varchar(16),
   g2_direction tinyint(3) unsigned,
   cond1field varchar(16),
   cond1op varchar(10),
   cond1cond varchar(255),
   cond2field varchar(16),
   cond2op varchar(10),
   cond2cond varchar(255),
   cond3field varchar(16),
   cond3op varchar(10),
   cond3cond varchar(255),
   listlen int(10) unsigned,
   scroller tinyint(3) unsigned,
   selected_item text,
   modification int(10) unsigned,
   parameter varchar(255),
   img1 varchar(255),
   img2 varchar(255),
   img3 varchar(255),
   img4 varchar(255),
   flag int(10) unsigned,
   aditional text,
   aditional2 text,
   aditional3 text,
   aditional4 text,
   aditional5 text,
   aditional6 text,
   noitem_msg text,                # html text shown if no item found
   PRIMARY KEY (id),
   KEY slice_id (slice_id)
);

# Dumping data for table 'constant'
#

INSERT INTO constant VALUES( 'AA-predefined000', 'lt_codepages', 'iso8859-1', 'iso8859-1', '', '100');
INSERT INTO constant VALUES( 'AA-predefined001', 'lt_codepages', 'iso8859-2', 'iso8859-2', '', '100');
INSERT INTO constant VALUES( 'AA-predefined002', 'lt_codepages', 'windows-1250', 'windows-1250', '', '100');
INSERT INTO constant VALUES( 'AA-predefined003', 'lt_codepages', 'windows-1253', 'windows-1253', '', '100');
INSERT INTO constant VALUES( 'AA-predefined004', 'lt_codepages', 'windows-1254', 'windows-1254', '', '100');
INSERT INTO constant VALUES( 'AA-predefined005', 'lt_codepages', 'koi8-r', 'koi8-r', '', '100');
INSERT INTO constant VALUES( 'AA-predefined006', 'lt_codepages', 'ISO-8859-8', 'ISO-8859-8', '', '100');
INSERT INTO constant VALUES( 'AA-predefined007', 'lt_codepages', 'windows-1258', 'windows-1258', '', '100');
INSERT INTO constant VALUES( 'AA-predefined008', 'lt_languages', 'Afrikaans', 'AF', '', '100');
INSERT INTO constant VALUES( 'AA-predefined009', 'lt_languages', 'Arabic', 'AR', '', '100');
INSERT INTO constant VALUES( 'AA-predefined010', 'lt_languages', 'Basque', 'EU', '', '100');
INSERT INTO constant VALUES( 'AA-predefined011', 'lt_languages', 'Byelorussian', 'BE', '', '100');
INSERT INTO constant VALUES( 'AA-predefined012', 'lt_languages', 'Bulgarian', 'BG', '', '100');
INSERT INTO constant VALUES( 'AA-predefined013', 'lt_languages', 'Catalan', 'CA', '', '100');
INSERT INTO constant VALUES( 'AA-predefined014', 'lt_languages', 'Chinese (ZH-CN)', 'ZH', '', '100');
INSERT INTO constant VALUES( 'AA-predefined015', 'lt_languages', 'Chinese', 'ZH-TW', '', '100');
INSERT INTO constant VALUES( 'AA-predefined016', 'lt_languages', 'Croatian', 'HR', '', '100');
INSERT INTO constant VALUES( 'AA-predefined017', 'lt_languages', 'Czech', 'CS', '', '100');
INSERT INTO constant VALUES( 'AA-predefined018', 'lt_languages', 'Danish', 'DA', '', '100');
INSERT INTO constant VALUES( 'AA-predefined019', 'lt_languages', 'Dutch', 'NL', '', '100');
INSERT INTO constant VALUES( 'AA-predefined020', 'lt_languages', 'English', 'EN-GB', '', '100');
INSERT INTO constant VALUES( 'AA-predefined021', 'lt_languages', 'English (EN-US)', 'EN', '', '100');
INSERT INTO constant VALUES( 'AA-predefined022', 'lt_languages', 'Estonian', 'ET', '', '100');
INSERT INTO constant VALUES( 'AA-predefined023', 'lt_languages', 'Faeroese', 'FO', '', '100');
INSERT INTO constant VALUES( 'AA-predefined024', 'lt_languages', 'Finnish', 'FI', '', '100');
INSERT INTO constant VALUES( 'AA-predefined025', 'lt_languages', 'French (FR-FR)', 'FR', '', '100');
INSERT INTO constant VALUES( 'AA-predefined026', 'lt_languages', 'French', 'FR-CA', '', '100');
INSERT INTO constant VALUES( 'AA-predefined027', 'lt_languages', 'German', 'DE', '', '100');
INSERT INTO constant VALUES( 'AA-predefined028', 'lt_languages', 'Greek', 'EL', '', '100');
INSERT INTO constant VALUES( 'AA-predefined029', 'lt_languages', 'Hebrew (IW)', 'HE', '', '100');
INSERT INTO constant VALUES( 'AA-predefined030', 'lt_languages', 'Hungarian', 'HU', '', '100');
INSERT INTO constant VALUES( 'AA-predefined031', 'lt_languages', 'Icelandic', 'IS', '', '100');
INSERT INTO constant VALUES( 'AA-predefined032', 'lt_languages', 'Indonesian (IN)', 'ID', '', '100');
INSERT INTO constant VALUES( 'AA-predefined033', 'lt_languages', 'Italian', 'IT', '', '100');
INSERT INTO constant VALUES( 'AA-predefined034', 'lt_languages', 'Japanese', 'JA', '', '100');
INSERT INTO constant VALUES( 'AA-predefined035', 'lt_languages', 'Korean', 'KO', '', '100');
INSERT INTO constant VALUES( 'AA-predefined036', 'lt_languages', 'Latvian', 'LV', '', '100');
INSERT INTO constant VALUES( 'AA-predefined037', 'lt_languages', 'Lithuanian', 'LT', '', '100');
INSERT INTO constant VALUES( 'AA-predefined038', 'lt_languages', 'Neutral', 'NEUTRAL', '', '100');
INSERT INTO constant VALUES( 'AA-predefined039', 'lt_languages', 'Norwegian', 'NO', '', '100');
INSERT INTO constant VALUES( 'AA-predefined040', 'lt_languages', 'Polish', 'PL', '', '100');
INSERT INTO constant VALUES( 'AA-predefined041', 'lt_languages', 'Portuguese', 'PT', '', '100');
INSERT INTO constant VALUES( 'AA-predefined042', 'lt_languages', 'Portuguese', 'PT-BR', '', '100');
INSERT INTO constant VALUES( 'AA-predefined043', 'lt_languages', 'Romanian', 'RO', '', '100');
INSERT INTO constant VALUES( 'AA-predefined044', 'lt_languages', 'Russian', 'RU', '', '100');
INSERT INTO constant VALUES( 'AA-predefined045', 'lt_languages', 'Serbian', 'SR', '', '100');
INSERT INTO constant VALUES( 'AA-predefined046', 'lt_languages', 'Slovak', 'SK', '', '100');
INSERT INTO constant VALUES( 'AA-predefined047', 'lt_languages', 'Slovenian', 'SL', '', '100');
INSERT INTO constant VALUES( 'AA-predefined048', 'lt_languages', 'Spanish (ES-ES)', 'ES', '', '100');
INSERT INTO constant VALUES( 'AA-predefined049', 'lt_languages', 'Swedish', 'SV', '', '100');
INSERT INTO constant VALUES( 'AA-predefined050', 'lt_languages', 'Thai', 'TH', '', '100');
INSERT INTO constant VALUES( 'AA-predefined051', 'lt_languages', 'Turkish', 'TR', '', '100');
INSERT INTO constant VALUES( 'AA-predefined052', 'lt_languages', 'Ukrainian', 'UK', '', '100');
INSERT INTO constant VALUES( 'AA-predefined053', 'lt_languages', 'Vietnamese', 'VI', '', '100');
INSERT INTO constant VALUES( 'AA-predefined054', 'lt_groupNames', 'Code Pages', 'lt_codepages', '', '0');
INSERT INTO constant VALUES( 'AA-predefined055', 'lt_groupNames', 'Languages Shortcuts', 'lt_languages', '', '1000');
INSERT INTO constant VALUES( 'AA-predefined056', 'lt_groupNames', 'APC-wide Categories', 'lt_apcCategories', '', '1000');
INSERT INTO constant VALUES( 'AA-predefined057', 'lt_groupNames', 'AA Core Bins', 'AA_Core_Bins....', '', '10000');
INSERT INTO constant VALUES( 'AA-predefined058', 'AA_Core_Bins....', 'Approved', '1', '', '100');
INSERT INTO constant VALUES( 'AA-predefined059', 'AA_Core_Bins....', 'Holding Bin', '2', '', '200');
INSERT INTO constant VALUES( 'AA-predefined060', 'AA_Core_Bins....', 'Trash Bin', '3', '', '300');

INSERT INTO constant VALUES( 'AA-predefined100', 'lt_apcCategories', 'Internet & ICT', 'Internet & ICT', '', '1000');
INSERT INTO constant VALUES( 'AA-predefined101', 'lt_apcCategories', 'Internet & ICT - Free software & Open Source', 'Internet & ICT - Free software & Open Source', '', '1100');
INSERT INTO constant VALUES( 'AA-predefined102', 'lt_apcCategories', 'Internet & ICT - Access', 'Internet & ICT - Access', '', '1200');
INSERT INTO constant VALUES( 'AA-predefined103', 'lt_apcCategories', 'Internet & ICT - Connectivity', 'Internet & ICT - Connectivity', '', '1300');
INSERT INTO constant VALUES( 'AA-predefined104', 'lt_apcCategories', 'Internet & ICT - Women and ICT', 'Internet & ICT - Women and ICT', '', '1400');
INSERT INTO constant VALUES( 'AA-predefined105', 'lt_apcCategories', 'Internet & ICT - Rights', 'Internet & ICT - Rights', '', '1500');
INSERT INTO constant VALUES( 'AA-predefined106', 'lt_apcCategories', 'Internet & ICT - Governance', 'Internet & ICT - Governance', '', '1600');
INSERT INTO constant VALUES( 'AA-predefined107', 'lt_apcCategories', 'Development', 'Development', '', '2000');
INSERT INTO constant VALUES( 'AA-predefined108', 'lt_apcCategories', 'Development - Resources', 'Development - Resources', '', '2100');
INSERT INTO constant VALUES( 'AA-predefined109', 'lt_apcCategories', 'Development - Structural adjustment', 'Development - Structural adjustment', '', '2200');
INSERT INTO constant VALUES( 'AA-predefined110', 'lt_apcCategories', 'Development - Sustainability', 'Development - Sustainability', '', '2300');
INSERT INTO constant VALUES( 'AA-predefined111', 'lt_apcCategories', 'News and media', 'News and media', '', '3000');
INSERT INTO constant VALUES( 'AA-predefined112', 'lt_apcCategories', 'News and media - Alternative', 'News and media - Alternative', '', '3100');
INSERT INTO constant VALUES( 'AA-predefined113', 'lt_apcCategories', 'News and media - Internet', 'News and media - Internet', '', '3200');
INSERT INTO constant VALUES( 'AA-predefined114', 'lt_apcCategories', 'News and media - Training', 'News and media - Training', '', '3300');
INSERT INTO constant VALUES( 'AA-predefined115', 'lt_apcCategories', 'News and media - Traditional', 'News and media - Traditional', '', '3400');
INSERT INTO constant VALUES( 'AA-predefined116', 'lt_apcCategories', 'Environment', 'Environment', '', '4000');
INSERT INTO constant VALUES( 'AA-predefined117', 'lt_apcCategories', 'Environment - Agriculture', 'Environment - Agriculture', '', '4100');
INSERT INTO constant VALUES( 'AA-predefined118', 'lt_apcCategories', 'Environment - Animal rights/protection', 'Environment - Animal rights/protection', '', '4200');
INSERT INTO constant VALUES( 'AA-predefined119', 'lt_apcCategories', 'Environment - Climate', 'Environment - Climate', '', '4300');
INSERT INTO constant VALUES( 'AA-predefined120', 'lt_apcCategories', 'Environment - Biodiversity/conservetion', 'Environment - Biodiversity/conservetion', '', '4400');
INSERT INTO constant VALUES( 'AA-predefined121', 'lt_apcCategories', 'Environment - Energy', 'Environment - Energy', '', '4500');
INSERT INTO constant VALUES( 'AA-predefined122', 'lt_apcCategories', 'Environment - Campaigns', 'Environment - Campaigns', '', '4550');
INSERT INTO constant VALUES( 'AA-predefined123', 'lt_apcCategories', 'Environment - Legislation', 'Environment - Legislation', '', '4600');
INSERT INTO constant VALUES( 'AA-predefined124', 'lt_apcCategories', 'Environment - Genetics', 'Environment - Genetics', '', '4650');
INSERT INTO constant VALUES( 'AA-predefined125', 'lt_apcCategories', 'Environment - Natural resources', 'Environment - Natural resources', '', '4700');
INSERT INTO constant VALUES( 'AA-predefined126', 'lt_apcCategories', 'Environment - Rural development', 'Environment - Rural development', '', '5750');
INSERT INTO constant VALUES( 'AA-predefined127', 'lt_apcCategories', 'Environment - Transport', 'Environment - Transport', '', '4800');
INSERT INTO constant VALUES( 'AA-predefined128', 'lt_apcCategories', 'Environment - Urban ecology', 'Environment - Urban ecology', '', '4850');
INSERT INTO constant VALUES( 'AA-predefined129', 'lt_apcCategories', 'Environment - Pollution & waste', 'Environment - Pollution & waste', '', '4900');
INSERT INTO constant VALUES( 'AA-predefined130', 'lt_apcCategories', 'NGOs', 'NGOs', '', '5000');
INSERT INTO constant VALUES( 'AA-predefined131', 'lt_apcCategories', 'NGOs - Fundraising', 'NGOs - Fundraising', '', '5100');
INSERT INTO constant VALUES( 'AA-predefined132', 'lt_apcCategories', 'NGOs - Funding agencies', 'NGOs - Funding agencies', '', '5200');
INSERT INTO constant VALUES( 'AA-predefined133', 'lt_apcCategories', 'NGOs - Grants/scholarships', 'NGOs - Grants/scholarships', '', '5300');
INSERT INTO constant VALUES( 'AA-predefined134', 'lt_apcCategories', 'NGOs - Jobs', 'NGOs - Jobs', '', '5400');
INSERT INTO constant VALUES( 'AA-predefined135', 'lt_apcCategories', 'NGOs - Management', 'NGOs - Management', '', '5500');
INSERT INTO constant VALUES( 'AA-predefined136', 'lt_apcCategories', 'NGOs - Volunteers', 'NGOs - Volunteers', '', '5600');
INSERT INTO constant VALUES( 'AA-predefined137', 'lt_apcCategories', 'Society', 'Society', '', '6000');
INSERT INTO constant VALUES( 'AA-predefined138', 'lt_apcCategories', 'Society - Charities', 'Society - Charities', '', '6100');
INSERT INTO constant VALUES( 'AA-predefined139', 'lt_apcCategories', 'Society - Community', 'Society - Community', '', '6200');
INSERT INTO constant VALUES( 'AA-predefined140', 'lt_apcCategories', 'Society - Crime & rehabilitation', 'Society - Crime & rehabilitation', '', '6300');
INSERT INTO constant VALUES( 'AA-predefined141', 'lt_apcCategories', 'Society - Disabilities', 'Society - Disabilities', '', '6400');
INSERT INTO constant VALUES( 'AA-predefined142', 'lt_apcCategories', 'Society - Drugs', 'Society - Drugs', '', '6500');
INSERT INTO constant VALUES( 'AA-predefined143', 'lt_apcCategories', 'Society - Ethical business', 'Society - Ethical business', '', '6600');
INSERT INTO constant VALUES( 'AA-predefined144', 'lt_apcCategories', 'Society - Health', 'Society - Health', '', '6700');
INSERT INTO constant VALUES( 'AA-predefined145', 'lt_apcCategories', 'Society - Law and legislation', 'Society - Law and legislation', '', '6750');
INSERT INTO constant VALUES( 'AA-predefined146', 'lt_apcCategories', 'Society - Migration', 'Society - Migration', '', '6800');
INSERT INTO constant VALUES( 'AA-predefined147', 'lt_apcCategories', 'Society - Sexuality', 'Society - Sexuality', '', '6850');
INSERT INTO constant VALUES( 'AA-predefined148', 'lt_apcCategories', 'Society - Social services and welfare', 'Society - Social services and welfare', '', '6900');
INSERT INTO constant VALUES( 'AA-predefined149', 'lt_apcCategories', 'Economy & Work', 'Economy & Work', '', '7000');
INSERT INTO constant VALUES( 'AA-predefined150', 'lt_apcCategories', 'Economy & Work - Informal Sector', 'Economy & Work - Informal Sector', '', '7100');
INSERT INTO constant VALUES( 'AA-predefined151', 'lt_apcCategories', 'Economy & Work - Labour', 'Economy & Work - Labour', '', '7200');
INSERT INTO constant VALUES( 'AA-predefined152', 'lt_apcCategories', 'Culture', 'Culture', '', '8000');
INSERT INTO constant VALUES( 'AA-predefined153', 'lt_apcCategories', 'Culture - Arts and literature', 'Culture - Arts and literature', '', '8100');
INSERT INTO constant VALUES( 'AA-predefined154', 'lt_apcCategories', 'Culture - Heritage', 'Culture - Heritage', '', '8200');
INSERT INTO constant VALUES( 'AA-predefined155', 'lt_apcCategories', 'Culture - Philosophy', 'Culture - Philosophy', '', '8300');
INSERT INTO constant VALUES( 'AA-predefined156', 'lt_apcCategories', 'Culture - Religion', 'Culture - Religion', '', '8400');
INSERT INTO constant VALUES( 'AA-predefined157', 'lt_apcCategories', 'Culture - Ethics', 'Culture - Ethics', '', '8500');
INSERT INTO constant VALUES( 'AA-predefined158', 'lt_apcCategories', 'Culture - Leisure', 'Culture - Leisure', '', '8600');
INSERT INTO constant VALUES( 'AA-predefined159', 'lt_apcCategories', 'Human rights', 'Human rights', '', '9000');
INSERT INTO constant VALUES( 'AA-predefined160', 'lt_apcCategories', 'Human rights - Consumer Protection', 'Human rights - Consumer Protection', '', '9100');
INSERT INTO constant VALUES( 'AA-predefined161', 'lt_apcCategories', 'Human rights - Democracy', 'Human rights - Democracy', '', '9200');
INSERT INTO constant VALUES( 'AA-predefined162', 'lt_apcCategories', 'Human rights - Minorities', 'Human rights - Minorities', '', '9300');
INSERT INTO constant VALUES( 'AA-predefined163', 'lt_apcCategories', 'Human rights - Peace', 'Human rights - Peace', '', '9400');
INSERT INTO constant VALUES( 'AA-predefined164', 'lt_apcCategories', 'Education', 'Education', '', '10000');
INSERT INTO constant VALUES( 'AA-predefined165', 'lt_apcCategories', 'Education - Distance learning', 'Education - Distance learning', '', '10100');
INSERT INTO constant VALUES( 'AA-predefined166', 'lt_apcCategories', 'Education - Non-formal education', 'Education - Non-formal education', '', '10200');
INSERT INTO constant VALUES( 'AA-predefined167', 'lt_apcCategories', 'Education - Schools', 'Education - Schools', '', '10300');
INSERT INTO constant VALUES( 'AA-predefined168', 'lt_apcCategories', 'Politics & Government', 'Politics & Government', '', '11000');
INSERT INTO constant VALUES( 'AA-predefined169', 'lt_apcCategories', 'Politics & Government - Internet', 'Politics & Government - Internet', '', '11100');
INSERT INTO constant VALUES( 'AA-predefined170', 'lt_apcCategories', 'Politics & Government - Local', 'Politics & Government - Local', '', '11200');
INSERT INTO constant VALUES( 'AA-predefined171', 'lt_apcCategories', 'Politics & Government - Policies', 'Politics & Government - Policies', '', '11300');
INSERT INTO constant VALUES( 'AA-predefined172', 'lt_apcCategories', 'Politics & Government - Administration', 'Politics & Government - Administration', '', '11400');
INSERT INTO constant VALUES( 'AA-predefined173', 'lt_apcCategories', 'People', 'People', '', '12000');
INSERT INTO constant VALUES( 'AA-predefined174', 'lt_apcCategories', 'People - Children', 'People - Children', '', '12100');
INSERT INTO constant VALUES( 'AA-predefined175', 'lt_apcCategories', 'People - Adolescents/teenagers', 'People - Adolescents/teenagers', '', '12200');
INSERT INTO constant VALUES( 'AA-predefined176', 'lt_apcCategories', 'People - Gender', 'People - Gender', '', '12300');
INSERT INTO constant VALUES( 'AA-predefined177', 'lt_apcCategories', 'People - Older people', 'People - Older people', '', '12400');
INSERT INTO constant VALUES( 'AA-predefined178', 'lt_apcCategories', 'People - Family', 'People - Family', '', '12500');
INSERT INTO constant VALUES( 'AA-predefined179', 'lt_apcCategories', 'World', 'World', '', '13000');
INSERT INTO constant VALUES( 'AA-predefined180', 'lt_apcCategories', 'World - Globalization', 'World - Globalization', '', '13100');
INSERT INTO constant VALUES( 'AA-predefined181', 'lt_apcCategories', 'World - Debt', 'World - Debt', '', '13200');


INSERT INTO slice_owner VALUES( 'AA_Core.........', 'Action Aplications System', 'technical@ecn.cz');

# --------------------------------------------------------
# AA Core slice for internal use only (defines APC wide field types and its default values in process of  creation

INSERT INTO slice VALUES( 'AA_Core_Fields..', 'Action Aplication Core', 'AA_Core_Fields..', '0', '', '975157733', '1', 'AA_Core_Fields..', '0', '', '', '','', '', '0', '', '', '', '', '', '1', '', 'http://aa.ecn.cz', '5000', '10000', 'en_news_lang.php3', '()', '()', '1', '0', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '0', '0');

INSERT INTO field VALUES( 'headline', '', 'AA_Core_Fields..', 'Headline', '100', 'Headline', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'abstract', '', 'AA_Core_Fields..', 'Abstract', '189', 'Abstract', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'txt:8', '', '100', '', '', '', '', '0', '1', '1', '_#UNDEFINE', 'f_t', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '1', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'full_text', '', 'AA_Core_Fields..', 'Fulltext', '300', 'Fulltext', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'txt:8', '', '100', '', '', '', '', '0', '1', '1', '_#UNDEFINE', 'f_t', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '1', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'hl_href', '', 'AA_Core_Fields..', 'Headline URL', '1655', 'Link for the headline (for external links)', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_f:link_only.......', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1');
INSERT INTO field VALUES( 'link_only', '', 'AA_Core_Fields..', 'External item', '1755', 'Use External link instead of fulltext?', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'chb', '', '100', '', '', '', '', '0', '0', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'bool', 'boo', '1', '1');
INSERT INTO field VALUES( 'place', '', 'AA_Core_Fields..', 'Locality', '2155', 'Item locality', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'source', '', 'AA_Core_Fields..', 'Source', '1955', 'Source of the item', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'source_href', '', 'AA_Core_Fields..', 'Source URL', '2055', 'URL of the source', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_s:javascript: window.alert(\'No source url specified\')', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1');
INSERT INTO field VALUES( 'lang_code', '', 'AA_Core_Fields..', 'Language Code', '1700', 'Code of used language', 'http://aa.ecn.cz/aa/doc/help.html', 'txt:EN', '0', '0', '0', 'sel:lt_languages', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'cp_code', '', 'AA_Core_Fields..', 'Code Page', '1800', 'Language Code Page', 'http://aa.ecn.cz/aa/doc/help.html', 'txt:iso8859-1', '0', '0', '0', 'sel:lt_codepages', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'category', '', 'AA_Core_Fields..', 'Category', '1000', 'Category', 'http://aa.ecn.cz/aa/doc/help.html', 'txt:', '0', '0', '0', 'sel:lt_apcCategories', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'img_src', '', 'AA_Core_Fields..', 'Image URL', '2055', 'URL of the image', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_i', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1');
INSERT INTO field VALUES( 'img_width', '', 'AA_Core_Fields..', 'Image width', '2455', 'Width of image (like: 100, 50%)', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_w', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'img_height', '', 'AA_Core_Fields..', 'Image height', '2555', 'Height of image (like: 100, 50%)', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_g', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'e_posted_by', '', 'AA_Core_Fields..', 'Author`s e-mail', '2255', 'E-mail to author', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'email', 'qte', '1', '1');
INSERT INTO field VALUES( 'created_by', '', 'AA_Core_Fields..', 'Created By', '2355', 'Identification of creator', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'nul', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'uid', '1', '1');
INSERT INTO field VALUES( 'edit_note', '', 'AA_Core_Fields..', 'Editor`s note', '2355', 'There you can write your note (not displayed on the web)', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'txt', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'img_upload', '', 'AA_Core_Fields..', 'Image upload', '2222', 'Select Image for upload', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fil:image/*', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'fil', '1', '1');
INSERT INTO field VALUES( 'lang_code', '', 'AA_Core_Fields..', 'Language Code', '1700', 'Code of used language', 'http://aa.ecn.cz/aa/doc/help.html', 'txt:EN', '0', '0', '0', 'sel:lt_languages', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'source_desc', '', 'AA_Core_Fields..', 'Source description', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'source_addr', '', 'AA_Core_Fields..', 'Source address', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'source_city', '', 'AA_Core_Fields..', 'Source city', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'source_prov', '', 'AA_Core_Fields..', 'Source province', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'source_cntry', '', 'AA_Core_Fields..', 'Source country', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'time', '', 'AA_Core_Fields..', 'Time', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '0');
INSERT INTO field VALUES( 'con_name', '', 'AA_Core_Fields..', 'Contact name', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'con_email', '', 'AA_Core_Fields..', 'Contact e-mail', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'con_phone', '', 'AA_Core_Fields..', 'Contact phone', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'con_fax', '', 'AA_Core_Fields..', 'Contact fax', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'loc_name', '', 'AA_Core_Fields..', 'Location name', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'loc_address', '', 'AA_Core_Fields..', 'Location address', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'loc_city', '', 'AA_Core_Fields..', 'Location city', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'loc_prov', '', 'AA_Core_Fields..', 'Location province', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'loc_cntry', '', 'AA_Core_Fields..', 'Location country', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'start_date', '', 'AA_Core_Fields..', 'Start date', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'now', '1', '0', '0', 'dte:1:10:1', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_d:m/d/Y', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'date', 'dte', '1', '0');
INSERT INTO field VALUES( 'end_date', '', 'AA_Core_Fields..', 'End date', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'now', '1', '0', '0', 'dte:1:10:1', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_d:m/d/Y', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'date', 'dte', '1', '0');
INSERT INTO field VALUES( 'keywords', '', 'AA_Core_Fields..', 'Keywords', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'subtitle', '', 'AA_Core_Fields..', 'Subtitle', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'year', '', 'AA_Core_Fields..', 'Year', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'number', '', 'AA_Core_Fields..', 'Number', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'number', 'num', '1', '1');
INSERT INTO field VALUES( 'page', '', 'AA_Core_Fields..', 'Page', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'number', 'num', '1', '1');
INSERT INTO field VALUES( 'price', '', 'AA_Core_Fields..', 'Price', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'number', 'num', '1', '1');
INSERT INTO field VALUES( 'organization', '', 'AA_Core_Fields..', 'Organization', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'file', '', 'AA_Core_Fields..', 'File upload', '2222', 'Select file for upload', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fil:*/*', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'fil', '1', '1');
INSERT INTO field VALUES( 'text', '', 'AA_Core_Fields..', 'Text', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'unspecified', '', 'AA_Core_Fields..', 'Unspecified', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'url', '', 'AA_Core_Fields..', 'URL', '2055', 'Internet URL address', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_i', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1');


# --------------------------------------------------------
# Templete slices

INSERT INTO slice VALUES( 'News_EN_tmpl....', 'News (EN) Template', 'AA_Core.........', '0', '', '975157733', '1', 'News_EN_tmpl....', '1', '', '<BR><FONT SIZE=+2 COLOR=blue>_#HEADLINE</FONT> <BR><B>_#PUB_DATE</B> <BR><img src=\"_#IMAGESRC\" width=\"_#IMGWIDTH\" height=\"_#IMG_HGHT\">_#FULLTEXT ', '','<font face=Arial color=#808080 size=-2>_#PUB_DATE - </font><font color=#FF0000><strong><a href=_#HDLN_URL>_#HEADLINE</a></strong></font><font color=#808080 size=-1><br>_#PLACE###(_#LINK_SRC) - </font><font color=black size=-1>_#ABSTRACT<br></font><br>', '', '0', '<br>', '<br>', '', '<p>_#CATEGORY</p>', '', '1', '', 'http://aa.ecn.cz', '5000', '10000', 'en_news_lang.php3', '()', '()', '1', '0', '', '', '', '', '', '', '', '', '', '', '', 'No item found', '<tr class=tablename><td width=30>&nbsp;</td><td>Click on Headline to Edit</td><td>Date</td></tr>', '<tr class=tabtxt><td width=30><input type=checkbox name="chb[x_#ITEM_ID#]" value=""></td><td><a href="_#EDITITEM">_#HEADLINE</a></td><td>_#PUB_DATE</td></tr>', '', '', '1', '1', '', '0', '0');

INSERT INTO field VALUES( 'abstract........', '', 'News_EN_tmpl....', 'Abstract', '150', 'Abstract', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'txt:8', '', '100', '', '', '', '', '0', '1', '1', '_#ABSTRACT', 'f_t', 'alias for abstract', '_#RSS_IT_D', 'f_r:256', 'Abstract for RSS', '', '', '', '', '', '0', '0', '1', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'category........', '', 'News_EN_tmpl....', 'Category', '500', 'Category', 'http://aa.ecn.cz/aa/doc/help.html', 'txt:', '0', '0', '0', 'sel:lt_apcCategories', '', '100', '', '', '', '', '1', '1', '1', '_#CATEGORY', 'f_h', 'alias for Item Category', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '0', '1');
INSERT INTO field VALUES( 'cp_code.........', '', 'News_EN_tmpl....', 'Code Page', '1800', 'Language Code Page', 'http://aa.ecn.cz/aa/doc/help.html', 'txt:iso8859-1', '0', '0', '0', 'sel:lt_codepages', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '0', '1');
INSERT INTO field VALUES( 'created_by......', '', 'News_EN_tmpl....', 'Author', '470', 'Identification of creator', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#CREATED#', 'f_h', 'alias for Written By', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'edited_by.......', '', 'News_EN_tmpl....', 'Edited by', '5030', 'Identification of last editor', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'nul', '', '100', '', '', '', '', '0', '0', '0', '_#EDITEDBY', 'f_h', 'alias for Last edited By', '', '', '', '', '', '', '', '', '0', '0', '0', 'edited_by', 'text', 'uid', '0', '1');
INSERT INTO field VALUES( 'edit_note.......', '', 'News_EN_tmpl....', 'Editor`s note', '2355', 'There you can write your note (not displayed on the web)', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'txt', '', '100', '', '', '', '', '0', '0', '0', '_#EDITNOTE', 'f_h', 'alias for Editor`s note', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'expiry_date.....', '', 'News_EN_tmpl....', 'Expiry Date', '955', 'Date when the news expires', 'http://aa.ecn.cz/aa/doc/help.html', 'dte:2000', '1', '0', '0', 'dte:1:10:1', '', '100', '', '', '', '', '0', '0', '0', '_#EXP_DATE', 'f_d:m/d/Y', 'alias for Expiry Date', '', '', '', '', '', '', '', '', '0', '0', '0', 'expiry_date', 'date', 'dte', '1', '0');
INSERT INTO field VALUES( 'e_posted_by.....', '', 'News_EN_tmpl....', 'Author`s e-mail', '480', 'E-mail to author', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#E_POSTED', 'f_h', 'alias for Author`s e-mail', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'email', 'qte', '1', '1');
INSERT INTO field VALUES( 'full_text.......', '', 'News_EN_tmpl....', 'Fulltext', '200', 'Fulltext', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'txt:8', '', '100', '', '', '', '', '0', '1', '1', '_#FULLTEXT', 'f_t', 'alias for Fulltext<br>(HTML tags are striped or not depending on HTML formated item setting)', '', '', '', '', '', '', '', '', '0', '0', '1', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'headline........', '', 'News_EN_tmpl....', 'Headline', '100', 'Headline of the news', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#HEADLINE', 'f_h', 'alias for Item Headline', '_#RSS_IT_T', 'f_r:100', 'item title, for RSS', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'highlight.......', '', 'News_EN_tmpl....', 'Highlight', '450', 'Interesting news - shown on homepage', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'chb', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', 'highlight', 'bool', 'boo', '1', '0');
INSERT INTO field VALUES( 'hl_href.........', '', 'News_EN_tmpl....', 'Headline URL', '400', 'Link for the headline (for external links)', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#HDLN_URL', 'f_f:link_only.......', 'alias for News URL<br>(substituted by External news link URL(if External news is checked) or link to Fulltext)<div class=example><em>Example: </em>&lt;a href=_#HDLN_URL&gt;_#HEADLINE&lt;/a&gt;</div>', '_#RSS_IT_L', 'f_r:link_only.......', 'item link, for RSS', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1');
INSERT INTO field VALUES( 'img_height......', '', 'News_EN_tmpl....', 'Image height', '2300', 'Height of image (like: 100, 50%)', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#IMG_HGHT', 'f_g', 'alias for Image Height<br>(if no height defined, program tries to remove <em>height=</em> atribute from format string<div class=example><em>Example: </em>&lt;img src=\"_#IMAGESRC\" width=_#IMGWIDTH height=_#IMG_HGHT&gt;</div>', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'img_src.........', '', 'News_EN_tmpl....', 'Image URL', '2100', 'URL of the image', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#IMAGESRC', 'f_i', 'alias for Image URL<br>(if there is no image url defined in database, default url is used instead (see NO_PICTURE_URL constant in en_*_lang.php3 file))<div class=example><em>Example: </em>&lt;img src=\"_#IMAGESRC\"&gt;</div>', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1');
INSERT INTO field VALUES( 'img_width.......', '', 'News_EN_tmpl....', 'Image width', '2200', 'Width of image (like: 100, 50%)', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#IMGWIDTH', 'f_w', 'alias for Image Width<br>(if no width defined, program tries to remove <em>width=</em> atribute from format string<div class=example><em>Example: </em>&lt;img src=\"_#IMAGESRC\" width=_#IMGWIDTH height=_#IMG_HGHT&gt;</div>', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'lang_code.......', '', 'News_EN_tmpl....', 'Language Code', '1700', 'Code of used language', 'http://aa.ecn.cz/aa/doc/help.html', 'txt:EN', '0', '0', '0', 'sel:lt_languages', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '0', '1');
INSERT INTO field VALUES( 'last_edit.......', '', 'News_EN_tmpl....', 'Last Edit', '5040', 'Date of last edit', 'http://aa.ecn.cz/aa/doc/help.html', 'now:', '0', '0', '0', 'dte:1:10:1', '', '100', '', '', '', '', '0', '0', '0', '_#LASTEDIT', 'f_d:m/d/Y', 'alias for Last Edit', '', '', '', '', '', '', '', '', '0', '0', '0', 'last_edit', 'date', 'now', '0', '0');
INSERT INTO field VALUES( 'link_only.......', '', 'News_EN_tmpl....', 'External news', '300', 'Use External link instead of fulltext?', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'chb', '', '100', '', '', '', '', '0', '0', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'bool', 'boo', '1', '0');
INSERT INTO field VALUES( 'place...........', '', 'News_EN_tmpl....', 'Locality', '630', 'News locality', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#PLACE###', 'f_h', 'alias for Locality', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'posted_by.......', '', 'News_EN_tmpl....', 'Posted by', '5035', 'Identification of author', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#POSTEDBY', 'f_h', 'alias for Author', '', '', '', '', '', '', '', '', '0', '0', '0', 'posted_by', 'text', 'qte', '0', '1');
INSERT INTO field VALUES( 'post_date.......', '', 'News_EN_tmpl....', 'Post Date', '5005', 'Date of posting this news', 'http://aa.ecn.cz/aa/doc/help.html', 'now:', '1', '0', '0', 'nul', '', '100', '', '', '', '', '0', '0', '0', '_#POSTDATE', 'f_d:m/d/Y', 'alias for Post Date', '', '', '', '', '', '', '', '', '0', '0', '0', 'post_date', 'date', 'now', '0', '0');
INSERT INTO field VALUES( 'publish_date....', '', 'News_EN_tmpl....', 'Publish Date', '900', 'Date when the news will be published', 'http://aa.ecn.cz/aa/doc/help.html', 'now:', '1', '0', '0', 'dte:1:10:1', '', '100', '', '', '', '', '0', '0', '0', '_#PUB_DATE', 'f_d:m/d/Y', 'alias for Publish Date', '', '', '', '', '', '', '', '', '0', '0', '0', 'publish_date', 'date', 'dte', '1', '0');
INSERT INTO field VALUES( 'source..........', '', 'News_EN_tmpl....', 'Source', '600', 'Source of the news', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#SOURCE##', 'f_h', 'alias for Source Name<br>(see _#LINK_SRC for text source link)', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'source_href.....', '', 'News_EN_tmpl....', 'Source URL', '610', 'URL of the source', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#SRC_URL#', 'f_s:javascript: window.alert(\'No source url specified\')', 'alias for Source URL<br>(if there is no source url defined in database, default source url is displayed (see ALIAS definition on field setting page))<br>Use _#LINK_SRC for text source link.<div class=example><em>Example: </em>&lt;a href\"_#SRC_URL#\"', '_#LINK_SRC', 'f_l', 'alias for Source Name with link.<br>(substituted by &lt;a href=\"_#SRC_URL#\"&gt;_#SOURCE##&lt;/a&gt; if Source URL defined, otherwise _#SOURCE## only)', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1');
INSERT INTO field VALUES( 'status_code.....', '', 'News_EN_tmpl....', 'Status Code', '5020', 'Select in which bin should the news appear', 'http://aa.ecn.cz/aa/doc/help.html', 'qte:1', '1', '0', '0', 'sel:AA_Core_Bins....', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', 'status_code', 'number', 'num', '0', '0');
INSERT INTO field VALUES( 'slice_id........', '', 'News_EN_tmpl....', 'Slice', '5000', 'Internal field - do not change', 'http://aa.ecn.cz/aa/doc/help.html', 'qte:1', '1', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#SLICE_ID', 'f_n:slice_id........', 'alias for id of slice', '', '', '', '', '', '', '', '', '0', '0', '0', 'slice_id', '', 'nul', '0', '1');
INSERT INTO field VALUES( 'display_count...', '', 'News_EN_tmpl....', 'Displayed Times', '5050', 'Internal field - do not change', 'http://aa.ecn.cz/aa/doc/help.html', 'qte:0', '1', '1', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#DISPL_NO', 'f_h', 'alias for number of displaying of this item', '', '', '', '', '', '', '', '', '0', '0', '0', 'display_count', '', 'nul', '0', '1');
INSERT INTO field VALUES( 'disc_count......', '', 'News_EN_tmpl....', 'Comments Count', '5060', 'Internal field - do not change', 'http://aa.ecn.cz/aa/doc/help.html', 'qte:0', '1', '1', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#D_ALLCNT', 'f_h', 'alias for number of all discussion comments for this item', '', '', '', '', '', '', '', '', '0', '0', '0', 'disc_count', '', 'nul', '0', '1');
INSERT INTO field VALUES( 'disc_app........', '', 'News_EN_tmpl....', 'Approved Comments Count', '5070', 'Internal field - do not change', 'http://aa.ecn.cz/aa/doc/help.html', 'qte:0', '1', '1', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#D_APPCNT', 'f_h', 'alias for number of approved discussion comments for this item', '', '', '', '', '', '', '', '', '0', '0', '0', 'disc_app', '', 'nul', '0', '1');

# --------------------------------------------------------
# Templete views

INSERT INTO view VALUES ( '', 'AA_Core_Fields..', 'Discussion ...', 'discus', '<table bgcolor=#000000 cellspacing=0 cellpadding=1 border=0>
<tr><td>
    <table width=100% bgcolor=#f5f0e7 cellspacing=0 cellpadding=0 border=0>
<tr><td colspan=8><big>Názory</big></td></tr>
', '<table  width=500 cellspacing=0 cellpadding=0 border=0>
<tr><td colspan=2><hr></td></tr>
<tr><td width=20%><b>Date:</b></td><td> _#DATE####</td></tr>
<tr><td><b>Subject:</b></td><td> _#SUBJECT#</td></tr>
<tr><td><b>Author:</b></td><td><A href=mailto:_#EMAIL###>_#AUTHOR##</a>
</td></tr>
<tr><td><b>WWW:</b></td><td><A href=_#WWW_URL#>_#WWW_DESC</a>
</td></tr>
<tr><td><b>IP:</b></td><td>_#IP_ADDR#</td></tr>
<tr><td colspan=2>&nbsp;</td></tr>
<tr><td colspan=2>_#BODY####</td></tr>
<tr><td colspan=2>&nbsp;</td></tr>

<tr><td colspan=2><a href=_#URLREPLY>Reply</a>
</td></tr>
</table>
<br>', '<tr>
<td width=\"10\">&nbsp;</td>
<td><font size=-1>_#CHECKBOX</font></td>
<td width=\"10\">&nbsp;</td>
<td align=center nowrap><SMALL>_#DATE####</SMALL></td>
<td width=\"20\">&nbsp;</td>
<td nowrap>_#AUTHOR## </td>
<td><table cellspacing=0 cellpadding=0 border=0><tr><td>_#TREEIMGS</td>
<td><img src=http://localhost/apc-aa/images/blank.gif width=2 height=21></td>
<td nowrap>_#SUBJECT#</td>
</tr></table></td>
<td width=\"20\">&nbsp;</td>
</tr>', '1', '</table>
</td></tr></table>
_#BUTTONS#', '<SCRIPT Language=\"JavaScript\">
<!--
function checkData() {
 var text=\"\"
 if(!document.f.d_subject.value) 
   text+=\"subject \"
 if(!document.f.d_author.value) 
   text+=\"author\"
 if (text!=\"\") {
    alert(\"Fill the field: \" + text);
    return false
 }
 return true
 }
 // -->
</SCRIPT>

<form name=f method=post action=\"/apc-aa/filldisc.php3\" onSubmit=\" return checkData()\">

<p>Name<br><input type=text name=d_author >
 <p>Subject<br><input type=text name=d_subject value=\"_#SUBJECT#\">
<p>E-mail<br><input type=text name=d_e_mail>
<p>Text<br><textarea rows=\"5\" cols=\"40\" name=d_body ></textarea>
<p>WWW<br><input type=text name=d_url_address value=\"http://\">
<p>WWW description<br><input type=text name=d_url_description>

<br><input type=submit value=Send align=center>
<input type=hidden name=d_parent value=\"_#DISC_ID#\">
<input type=hidden name=d_item_id value=\"_#ITEM_ID#\">
<input type=hidden name=url value=\"_#DISC_URL\">
</FORM>', NULL, NULL, '0', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '0', '23', NULL, '<img src=\"http://aa.ecn.cz/aaa/images/i.gif\" width=9 height=21>', '<img src=\"http://aa.ecn.cz/aaa/images/l.gif\" width=9 height=21>', '<img src=\"http://aa.ecn.cz/aaa/images/t.gif\" width=9 height=21>', '<img src=\"http://aa.ecn.cz/aaa/images/blank.gif\" width=12 height=21>', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'No item found');

INSERT INTO view VALUES ( '', 'AA_Core_Fields..', 'Constant view ...', 'const', '<table border=0 cellpadding=0 cellspacing=0>', '', '<tr><td>_#VALUE###</td></tr>', '0', '</table>', NULL, NULL, 'value', '0', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '10', NULL, '0', NULL, 'lt_languages', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'No item found');

INSERT INTO view VALUES ( '', 'AA_Core_Fields..', 'Javascript ...', 'script', '/* output of this script can be included to any page on any server by adding:&lt;script type=\"text/javascript\" src=\"http://work.ecn.cz/apc-aa/view.php3?vid=3\"&gt; &lt;/script&lt; or such.*/', NULL, 'document.write(\"_#HEADLINE\");', NULL, '// script end ', NULL, NULL, '', '0', '', '0', NULL, NULL, NULL, NULL, '', '<', '', '', '<', '', '', '<', '', '8', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'No item found');

INSERT INTO view  
( slice_id, name, type, before, odd, after, order1, o1_direction, 
  order2, o2_direction, cond1field, cond1op, cond1cond, cond2field,
cond2op, cond2cond, cond3field, cond3op, cond3cond, listlen ) VALUES (
'AA_Core_Fields..',  'rss',  'rss',  
'<!DOCTYPE rss PUBLIC \"-//Netscape Communications//DTD RSS 0.91//EN\"
            \"http://my.netscape.com/publish/formats/rss-0.91.dtd\">
<rss version=\"0.91\">
  <channel>
    <title>_#RSS_TITL</title>
    <link>_#RSS_LINK</link>
    <description>_#RSS_DESC</description>
    <lastBuildDate>_#RSS_DATE</lastBuildDate>
    <language></language>

',  '    <item>
      <title>_#RSS_IT_T</title>
      <link>_#RSS_IT_L</link>
      <description>_#RSS_IT_D</description>
    </item>
',  '</channel>
</rss>
',  'publish_date....',  '0',  'headline........',  '0',
'source..........',  
'>',  '',  '',  '<',  '',  '',  '<',  '',  '15', NULL, NULL, NULL, NULL, NULL, 'No item found' ) ;
