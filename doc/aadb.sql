# MySQL dump 5.13
#
# Host: localhost    Database: aadb
#--------------------------------------------------------
# Server version	3.22.22

#
# Table structure for table 'active_sessions'
#
CREATE TABLE active_sessions (
  sid varchar(32) DEFAULT '' NOT NULL,
  name varchar(32) DEFAULT '' NOT NULL,
  val text,
  changed varchar(14) DEFAULT '' NOT NULL,
  PRIMARY KEY (name,sid),
  KEY changed (changed)
);

#
# Table structure for table 'catbinds'
#
CREATE TABLE catbinds (
  slice_id varchar(16) DEFAULT '' NOT NULL,
  category_id varchar(16) DEFAULT '' NOT NULL,
  KEY slice_id (slice_id)
);

#
# Table structure for table 'categories'
#
CREATE TABLE categories (
  id varchar(16) DEFAULT '' NOT NULL,
  name varchar(255) DEFAULT '' NOT NULL,
  PRIMARY KEY (id)
);


#
# Table structure for table 'db_sequence'
#
CREATE TABLE db_sequence (
  seq_name varchar(127) DEFAULT '' NOT NULL,
  nextid int(10) unsigned DEFAULT '0' NOT NULL,
  PRIMARY KEY (seq_name)
);

#
# Table structure for table 'feedperms'
#
CREATE TABLE feedperms (
  from_id varchar(16) DEFAULT '' NOT NULL,
  to_id varchar(16) DEFAULT '' NOT NULL
);

#
# Table structure for table 'feeds'
#
CREATE TABLE feeds (
  from_id varchar(16) DEFAULT '' NOT NULL,
  to_id varchar(16) DEFAULT '' NOT NULL,
  category_id varchar(16),
  all_categories smallint(5),
  to_approved smallint(5),
  to_category_id varchar(16),
  KEY from_id (from_id)
);

#
# Table structure for table 'fulltexts'
#
CREATE TABLE fulltexts (
  ft_id varchar(16) DEFAULT '' NOT NULL,
  full_text mediumtext,
  PRIMARY KEY (ft_id),
  KEY id (ft_id),
  UNIQUE id_2 (ft_id)
);

#
# Table structure for table 'items'
#
CREATE TABLE items (
  id varchar(16) DEFAULT '' NOT NULL,
  master_id varchar(16) DEFAULT '' NOT NULL,
  slice_id varchar(16) DEFAULT '' NOT NULL,
  category_id varchar(16) DEFAULT '' NOT NULL,
  status_code smallint(5) unsigned DEFAULT '0' NOT NULL,
  language_code varchar(8) DEFAULT '' NOT NULL,
  cp_code varchar(32) DEFAULT '' NOT NULL,
  headline varchar(255) DEFAULT '' NOT NULL,
  hl_href varchar(255) DEFAULT '' NOT NULL,
  link_only smallint(5) unsigned DEFAULT '0' NOT NULL,
  post_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
  publish_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
  expiry_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
  abstract text,
  full_text_old mediumtext,
  img_src varchar(255) DEFAULT '',
  html_formatted smallint(5) unsigned DEFAULT '0' NOT NULL,
  source varchar(255) DEFAULT '',
  source_href varchar(255) DEFAULT '',
  redirect varchar(255) DEFAULT '',
  place varchar(255) DEFAULT '',
  highlight smallint(5) unsigned DEFAULT '0' NOT NULL,
  posted_by varchar(255) DEFAULT '',
  e_posted_by varchar(255) DEFAULT '',
  created_by varchar(60) DEFAULT '' NOT NULL,
  edited_by varchar(60),
  last_edit datetime,
  contact1 varchar(16) DEFAULT '',
  contact2 varchar(16) DEFAULT '',
  contact3 varchar(16) DEFAULT '',
  edit_note varchar(255) DEFAULT '',
  img_width varchar(32) DEFAULT '',
  img_height varchar(32) DEFAULT '',
  PRIMARY KEY (id),
  KEY slice_id (slice_id),
  KEY publish_date (publish_date)
);

#
# Table structure for table 'lt_cps'
#
CREATE TABLE lt_cps (
  code varchar(32) DEFAULT '' NOT NULL,
  w32cp varchar(64) DEFAULT '',
  PRIMARY KEY (code)
);

#
# Dumping data for table 'lt_cps'
#

INSERT INTO lt_cps VALUES( 'iso8859-1', '');
INSERT INTO lt_cps VALUES( 'iso8859-2', '');
INSERT INTO lt_cps VALUES( 'windows-1250', '');
INSERT INTO lt_cps VALUES( 'windows-1253', '');
INSERT INTO lt_cps VALUES( 'windows-1254', '');
INSERT INTO lt_cps VALUES( 'koi8-r', '');
INSERT INTO lt_cps VALUES( 'ISO-8859-8', '');
INSERT INTO lt_cps VALUES( 'Windows-1258', '');

#
# Table structure for table 'lt_langs'
#
CREATE TABLE lt_langs (
  code varchar(8) DEFAULT '' NOT NULL,
  name varchar(64) DEFAULT '' NOT NULL,
  altcode varchar(8) DEFAULT '',
  PRIMARY KEY (code)
);

#
# Dumping data for table 'lt_langs'
#

INSERT INTO lt_langs VALUES( 'AF', 'Afrikaans', '');
INSERT INTO lt_langs VALUES( 'AR', 'Arabic', '');
INSERT INTO lt_langs VALUES( 'EU', 'Basque', '');
INSERT INTO lt_langs VALUES( 'BE', 'Byelorussian', '');
INSERT INTO lt_langs VALUES( 'BG', 'Bulgarian', '');
INSERT INTO lt_langs VALUES( 'CA', 'Catalan', '');
INSERT INTO lt_langs VALUES( 'ZH', 'Chinese', 'ZH-CN');
INSERT INTO lt_langs VALUES( 'ZH-TW', 'Chinese', '');
INSERT INTO lt_langs VALUES( 'HR', 'Croatian', '');
INSERT INTO lt_langs VALUES( 'CS', 'Czech', '');
INSERT INTO lt_langs VALUES( 'DA', 'Danish', '');
INSERT INTO lt_langs VALUES( 'NL', 'Dutch', '');
INSERT INTO lt_langs VALUES( 'EN-GB', 'English', '');
INSERT INTO lt_langs VALUES( 'EN', 'English', 'EN-US');
INSERT INTO lt_langs VALUES( 'ET', 'Estonian', '');
INSERT INTO lt_langs VALUES( 'FO', 'Faeroese', '');
INSERT INTO lt_langs VALUES( 'FI', 'Finnish', '');
INSERT INTO lt_langs VALUES( 'FR', 'French', 'FR-FR');
INSERT INTO lt_langs VALUES( 'FR-CA', 'French', '');
INSERT INTO lt_langs VALUES( 'DE', 'German', '');
INSERT INTO lt_langs VALUES( 'EL', 'Greek', '');
INSERT INTO lt_langs VALUES( 'HE', 'Hebrew', 'IW');
INSERT INTO lt_langs VALUES( 'HU', 'Hungarian', '');
INSERT INTO lt_langs VALUES( 'IS', 'Icelandic', '');
INSERT INTO lt_langs VALUES( 'ID', 'Indonesian', 'IN');
INSERT INTO lt_langs VALUES( 'IT', 'Italian', '');
INSERT INTO lt_langs VALUES( 'JA', 'Japanese', '');
INSERT INTO lt_langs VALUES( 'KO', 'Korean', '');
INSERT INTO lt_langs VALUES( 'LV', 'Latvian', '');
INSERT INTO lt_langs VALUES( 'LT', 'Lithuanian', '');
INSERT INTO lt_langs VALUES( 'NEUTRAL', 'Neutral', '');
INSERT INTO lt_langs VALUES( 'NO', 'Norwegian', '');
INSERT INTO lt_langs VALUES( 'PL', 'Polish', '');
INSERT INTO lt_langs VALUES( 'PT', 'Portuguese', '');
INSERT INTO lt_langs VALUES( 'PT-BR', 'Portuguese', '');
INSERT INTO lt_langs VALUES( 'RO', 'Romanian', '');
INSERT INTO lt_langs VALUES( 'RU', 'Russian', '');
INSERT INTO lt_langs VALUES( 'SR', 'Serbian', '');
INSERT INTO lt_langs VALUES( 'SK', 'Slovak', '');
INSERT INTO lt_langs VALUES( 'SL', 'Slovenian', '');
INSERT INTO lt_langs VALUES( 'ES', 'Spanish', 'ES-ES');
INSERT INTO lt_langs VALUES( 'SV', 'Swedish', '');
INSERT INTO lt_langs VALUES( 'TH', 'Thai', '');
INSERT INTO lt_langs VALUES( 'TR', 'Turkish', '');
INSERT INTO lt_langs VALUES( 'UK', 'Ukrainian', '');
INSERT INTO lt_langs VALUES( 'VI', 'Vietnamese', '');

#
# Table structure for table 'slices'
#
CREATE TABLE slices (
  id varchar(16) DEFAULT '' NOT NULL,
  headline varchar(255) DEFAULT '' NOT NULL,
  short_name varchar(255) DEFAULT '' NOT NULL,
  d_language_code varchar(8) DEFAULT '' NOT NULL,
  d_cp_code varchar(32) DEFAULT '' NOT NULL,
  d_category_id varchar(16) DEFAULT '' NOT NULL,
  d_status_code smallint(5) unsigned DEFAULT '0' NOT NULL,
  d_expiry_limit smallint(5) unsigned DEFAULT '0' NOT NULL,
  d_expiry_date datetime,
  d_hl_href varchar(255) DEFAULT '' NOT NULL,
  d_source varchar(255) DEFAULT '',
  d_source_href varchar(255) DEFAULT '',
  d_redirect varchar(255) DEFAULT '',
  d_place varchar(255) DEFAULT '',
  d_listlen smallint(5) unsigned DEFAULT '0' NOT NULL,
  d_html_formatted smallint(5) unsigned DEFAULT '0' NOT NULL,
  grab_len smallint(5) unsigned DEFAULT '0' NOT NULL,
  post_enabled smallint(5) unsigned DEFAULT '0' NOT NULL,
  created_by varchar(60),
  created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
  res_persID varchar(60),
  deleted smallint(5) unsigned DEFAULT '0' NOT NULL,
  d_img_src varchar(255) DEFAULT '',
  d_img_width varchar(32) DEFAULT '',
  d_img_height varchar(32) DEFAULT '',
  d_posted_by varchar(255) DEFAULT '' NOT NULL,
  d_e_posted_by varchar(255) DEFAULT '' NOT NULL,
  fulltext_format mediumtext,
  odd_row_format mediumtext,
  even_row_format mediumtext,
  even_odd_differ smallint(5) unsigned DEFAULT '0' NOT NULL,
  compact_top varchar(255),
  compact_bottom varchar(255),
  category_sort smallint(5) unsigned DEFAULT '0' NOT NULL,
  category_format mediumtext,
  d_highlight smallint(5) unsigned DEFAULT '0',
  edit_fields varchar(40),
  needed_fields varchar(40),
  d_link_only smallint(5) unsigned DEFAULT '0',
  slice_url varchar(255),
  search_show varchar(15),
  search_default varchar(10),
  compact_remove varchar(255),
  fulltext_remove varchar(255),
  export_to_all smallint(5) unsigned DEFAULT '0' NOT NULL,
  config mediumtext,
  type varchar(20) DEFAULT '' NOT NULL,
  PRIMARY KEY (id)
);

#
# three optional tables.
# only used by perm_sql.php3
#

CREATE TABLE users (
  id             INT NOT NULL AUTO_INCREMENT,
  type           CHAR(10) NOT NULL,    # either 'group' or 'user'
  password       CHAR(30) NOT NULL,    # encrypted with id as salt
  uid            CHAR(40) NOT NULL,    # userid/username
  mail           CHAR(40) NOT NULL,    # email address
  name           CHAR(80) NOT NULL,    # overall name of person/group
  description    CHAR(255) NOT NULL,   # description of this person
  givenname      CHAR(40) NOT NULL,    # ie firstname like Sara
  sn             CHAR(40) NOT NULL,    # ie lastname like Jones
  last_mod       TIMESTAMP,		# when this record was last modified
  PRIMARY KEY(id),
  INDEX(type),
  INDEX(mail),
  INDEX(name),
  INDEX(sn)
);

CREATE TABLE membership (
  groupid        INT NOT NULL,          # foreign key is users.id
  memberid       INT NOT NULL,          # foreign key is users.id
  last_mod       TIMESTAMP,		# when this record was last modified
  PRIMARY KEY(groupid,memberid),
  INDEX(memberid)
);
  
CREATE TABLE perms (
  object_type CHAR(30) NOT NULL,        # typically either 'aa' or 'slice'
  objectid    CHAR(32) NOT NULL,        # from sliceid
  userid      INT NOT NULL,
  perm        CHAR(32) NOT NULL,
  last_mod       TIMESTAMP,		# when this record was last modified
  PRIMARY KEY (objectid, userid, object_type),
  INDEX(userid)
);
