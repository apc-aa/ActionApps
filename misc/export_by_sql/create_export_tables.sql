# phpMyAdmin MySQL-Dump
# version 2.3.2
# http://www.phpmyadmin.net/ (download page)
#
# Poèítaè: localhost
# Vygenerováno: Støeda . února 2003, 03:12
# Verze MySQL: 3.23.47
# Verze PHP: 4.1.1
# Databáze : `aadb`
# --------------------------------------------------------

#
# Struktura tabulky `constant`
#

DROP TABLE IF EXISTS export_constant;
CREATE TABLE export_constant (
  id varchar(16) NOT NULL default '',
  group_id varchar(16) NOT NULL default '',
  name varchar(150) NOT NULL default '',
  value varchar(255) NOT NULL default '',
  class varchar(16) default NULL,
  pri smallint(5) NOT NULL default '100',
  ancestors varchar(160) default NULL,
  description varchar(250) default NULL,
  short_id int(11) NOT NULL auto_increment,
  PRIMARY KEY  (id),
  KEY group_id (group_id),
  KEY short_id (short_id)
) TYPE=MyISAM;
# --------------------------------------------------------

#
# Struktura tabulky `constant_slice`
#

DROP TABLE IF EXISTS export_constant_slice;
CREATE TABLE export_constant_slice (
  slice_id char(16) default NULL,
  group_id char(16) NOT NULL default '',
  propagate tinyint(1) NOT NULL default '1',
  levelcount tinyint(2) NOT NULL default '2',
  horizontal tinyint(1) NOT NULL default '0',
  hidevalue tinyint(1) NOT NULL default '0',
  hierarch tinyint(1) NOT NULL default '0',
  PRIMARY KEY  (group_id)
) TYPE=MyISAM;
# --------------------------------------------------------

#
# Struktura tabulky `content`
#

DROP TABLE IF EXISTS export_content;
CREATE TABLE export_content (
  item_id varchar(16) NOT NULL default '',
  field_id varchar(16) NOT NULL default '',
  number bigint(20) default NULL,
  text mediumtext,
  flag smallint(6) default NULL,
  KEY item_id (item_id,field_id,text(16)),
  KEY text (text(10))
) TYPE=MyISAM;
# --------------------------------------------------------

#
# Struktura tabulky `field`
#

DROP TABLE IF EXISTS export_field;
CREATE TABLE export_field (
  id varchar(16) NOT NULL default '',
  type varchar(16) NOT NULL default '',
  slice_id varchar(16) NOT NULL default '',
  name varchar(255) NOT NULL default '',
  input_pri smallint(5) NOT NULL default '100',
  input_help varchar(255) default NULL,
  input_morehlp text,
  input_default mediumtext,
  required smallint(5) default NULL,
  feed smallint(5) default NULL,
  multiple smallint(5) default NULL,
  input_show_func varchar(255) default NULL,
  content_id varchar(16) default NULL,
  search_pri smallint(5) NOT NULL default '100',
  search_type varchar(16) default NULL,
  search_help varchar(255) default NULL,
  search_before text,
  search_more_help text,
  search_show smallint(5) default NULL,
  search_ft_show smallint(5) default NULL,
  search_ft_default smallint(5) default NULL,
  alias1 varchar(10) default NULL,
  alias1_func varchar(255) default NULL,
  alias1_help varchar(255) default NULL,
  alias2 varchar(10) default NULL,
  alias2_func varchar(255) default NULL,
  alias2_help varchar(255) default NULL,
  alias3 varchar(10) default NULL,
  alias3_func varchar(255) default NULL,
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
  KEY slice_id (slice_id,id)
) TYPE=MyISAM;
# --------------------------------------------------------

#
# Struktura tabulky `item`
#

DROP TABLE IF EXISTS export_item;
CREATE TABLE export_item (
  id char(16) NOT NULL default '',
  short_id int(11) NOT NULL auto_increment,
  slice_id char(16) NOT NULL default '',
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
  PRIMARY KEY  (id),
  KEY short_id (short_id),
  KEY slice_id_2 (slice_id,status_code,publish_date),
  KEY expiry_date (expiry_date)
) TYPE=MyISAM;
# --------------------------------------------------------

#
# Struktura tabulky `slice`
#

DROP TABLE IF EXISTS export_slice;
CREATE TABLE export_slice (
  id varchar(16) NOT NULL default '',
  name varchar(100) NOT NULL default '',
  owner varchar(16) default NULL,
  deleted smallint(5) default NULL,
  created_by varchar(255) default NULL,
  created_at bigint(20) default NULL,
  export_to_all smallint(5) default NULL,
  type varchar(16) default NULL,
  template smallint(5) default NULL,
  fulltext_format_top text,
  fulltext_format text,
  fulltext_format_bottom text,
  odd_row_format text,
  even_row_format text,
  even_odd_differ smallint(5) default NULL,
  compact_top text,
  compact_bottom text,
  category_top text,
  category_format text,
  category_bottom text,
  category_sort smallint(5) default NULL,
  config text NOT NULL,
  slice_url varchar(255) default NULL,
  d_expiry_limit smallint(5) default NULL,
  d_listlen smallint(5) default NULL,
  lang_file varchar(50) default NULL,
  fulltext_remove text,
  compact_remove text,
  email_sub_enable smallint(5) default NULL,
  exclude_from_dir smallint(5) default NULL,
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
  noitem_msg mediumtext,
  admin_format_top text,
  admin_format text,
  admin_format_bottom text,
  admin_remove text,
  permit_anonymous_post smallint(5) default NULL,
  permit_offline_fill smallint(5) default NULL,
  aditional text,
  flag int(11) NOT NULL default '0',
  vid int(11) default '0',
  gb_direction tinyint(4) default NULL,
  group_by varchar(16) default NULL,
  gb_header tinyint(4) default NULL,
  gb_case varchar(15) default NULL,
  javascript text,
  fileman_access varchar(20) default NULL,
  fileman_dir varchar(50) default NULL,
  PRIMARY KEY  (id)
) TYPE=MyISAM;
# --------------------------------------------------------

#
# Struktura tabulky `view`
#

DROP TABLE IF EXISTS export_view;
CREATE TABLE export_view (
  id int(10) unsigned NOT NULL auto_increment,
  slice_id varchar(16) NOT NULL default '',
  name varchar(50) default NULL,
  type varchar(10) default NULL,
  before text,
  even text,
  odd text,
  even_odd_differ tinyint(3) unsigned default NULL,
  after text,
  remove_string text,
  group_title text,
  order1 varchar(16) default NULL,
  o1_direction tinyint(3) unsigned default NULL,
  order2 varchar(16) default NULL,
  o2_direction tinyint(3) unsigned default NULL,
  group_by1 varchar(16) default NULL,
  g1_direction tinyint(3) unsigned default NULL,
  group_by2 varchar(16) default NULL,
  g2_direction tinyint(3) unsigned default NULL,
  cond1field varchar(16) default NULL,
  cond1op varchar(10) default NULL,
  cond1cond varchar(255) default NULL,
  cond2field varchar(16) default NULL,
  cond2op varchar(10) default NULL,
  cond2cond varchar(255) default NULL,
  cond3field varchar(16) default NULL,
  cond3op varchar(10) default NULL,
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
  aditional text,
  aditional2 text,
  aditional3 text,
  aditional4 text,
  aditional5 text,
  aditional6 text,
  noitem_msg text,
  group_bottom text,
  field1 varchar(16) default NULL,
  field2 varchar(16) default NULL,
  field3 varchar(16) default NULL,
  calendar_type varchar(100) default 'mon',
  PRIMARY KEY  (id),
  KEY slice_id (slice_id)
) TYPE=MyISAM;

DROP TABLE IF EXISTS export_module;
CREATE TABLE export_module (
  id char(16) NOT NULL default '',
  name char(100) NOT NULL default '',
  deleted smallint(5) default NULL,
  type char(16) default 'S',
  slice_url char(255) default NULL,
  lang_file char(50) default NULL,
  created_at bigint(20) NOT NULL default '0',
  created_by char(255) NOT NULL default '',
  owner char(16) NOT NULL default '',
  flag int(11) default '0',
  PRIMARY KEY  (id)
) TYPE=MyISAM;
