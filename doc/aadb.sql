
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
# Table structure for table 'constant'

CREATE TABLE constant (
   group_id char(16) NOT NULL,
   name char(150) NOT NULL,
   value char(150) NOT NULL,
   class char(16),
   pri smallint(5) DEFAULT '100' NOT NULL,
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
# Table structure for table 'feedperms'

CREATE TABLE feedperms (
   from_id varchar(16) NOT NULL,
   to_id varchar(16) NOT NULL
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
   feed smallint(5),
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
   text_stored smallint(5) DEFAULT '0',
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
   slice_id char(16) NOT NULL,
   status_code smallint(5) DEFAULT '0' NOT NULL,
   post_date bigint(20) DEFAULT '0' NOT NULL,
   publish_date bigint(20),
   expiry_date bigint(20),
   highlight smallint(5),
   posted_by char(60),
   edited_by char(60),
   last_edit bigint(20),
   PRIMARY KEY (id),
   KEY id (id),
   UNIQUE id_2 (id)
);

# --------------------------------------------------------
# Table structure for table 'log'

CREATE TABLE log (
   id int(11) DEFAULT '0' NOT NULL auto_increment,
   time bigint(20) DEFAULT '0' NOT NULL,
   user varchar(60) NOT NULL,
   text varchar(255) NOT NULL,
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
# Table structure for table 'slice'

CREATE TABLE slice (
   id varchar(16) NOT NULL,
   name varchar(100) NOT NULL,
   owner varchar(16),
   grab_len smallint(5),
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
   notify_holding_item mediumtext,
   admin_format_top text,
   admin_format text,
   admin_format_bottom text,
   admin_remove text,
   permit_anonymous_post smallint(5),
   permit_offline_fill smallint(5),
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

# Dumping data for table 'constant'
#

INSERT INTO constant VALUES( 'lt_codepages', 'iso8859-1', 'iso8859-1', '', '100');
INSERT INTO constant VALUES( 'lt_codepages', 'iso8859-2', 'iso8859-2', '', '100');
INSERT INTO constant VALUES( 'lt_codepages', 'windows-1250', 'windows-1250', '', '100');
INSERT INTO constant VALUES( 'lt_codepages', 'windows-1253', 'windows-1253', '', '100');
INSERT INTO constant VALUES( 'lt_codepages', 'windows-1254', 'windows-1254', '', '100');
INSERT INTO constant VALUES( 'lt_codepages', 'koi8-r', 'koi8-r', '', '100');
INSERT INTO constant VALUES( 'lt_codepages', 'ISO-8859-8', 'ISO-8859-8', '', '100');
INSERT INTO constant VALUES( 'lt_codepages', 'windows-1258', 'windows-1258', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Afrikaans', 'AF', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Arabic', 'AR', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Basque', 'EU', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Byelorussian', 'BE', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Bulgarian', 'BG', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Catalan', 'CA', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Chinese (ZH-CN)', 'ZH', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Chinese', 'ZH-TW', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Croatian', 'HR', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Czech', 'CS', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Danish', 'DA', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Dutch', 'NL', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'English', 'EN-GB', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'English (EN-US)', 'EN', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Estonian', 'ET', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Faeroese', 'FO', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Finnish', 'FI', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'French (FR-FR)', 'FR', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'French', 'FR-CA', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'German', 'DE', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Greek', 'EL', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Hebrew (IW)', 'HE', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Hungarian', 'HU', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Icelandic', 'IS', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Indonesian (IN)', 'ID', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Italian', 'IT', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Japanese', 'JA', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Korean', 'KO', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Latvian', 'LV', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Lithuanian', 'LT', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Neutral', 'NEUTRAL', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Norwegian', 'NO', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Polish', 'PL', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Portuguese', 'PT', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Portuguese', 'PT-BR', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Romanian', 'RO', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Russian', 'RU', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Serbian', 'SR', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Slovak', 'SK', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Slovenian', 'SL', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Spanish (ES-ES)', 'ES', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Swedish', 'SV', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Thai', 'TH', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Turkish', 'TR', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Ukrainian', 'UK', '', '100');
INSERT INTO constant VALUES( 'lt_languages', 'Vietnamese', 'VI', '', '100');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Environment', 'Environment', '', '1000');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Environment - Transport', 'Environment - Transport', '', '1100');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Environment - Energy', 'Environment - Energy', '', '1200');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Environment - Forests', 'Environment - Forests', '', '1300');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Environment - Waste and Pollution', 'Environment - Waste and Pollution', '', '1400');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Environment - Nature Protection', 'Environment - Nature Protection', '', '1500');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Environment - Agriculture', 'Environment - Agriculture', '', '1600');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Environment - Animals', 'Environment - Animals', '', '1700');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Environment - Water', 'Environment - Water', '', '1800');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Nonprofits', 'Nonprofits', '', '2000');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Nonprofits - Fundraising', 'Nonprofits - Fundraising', '', '2100');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Nonprofits - Volunteers', 'Nonprofits - Volunteers', '', '2200');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Nonprofits - Open Society', 'Nonprofits - Open Society', '', '2300');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Nonprofits - Management', 'Nonprofits - Management', '', '2400');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Society', 'Society', '', '3000');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Society - Rights', 'Society - Rights', '', '3100');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Society - Media', 'Society - Media', '', '3200');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Society - Politics', 'Society - Politics', '', '3300');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Society - Government', 'Society - Government', '', '3400');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Society - Economy', 'Society - Economy', '', '3500');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Social area', 'Social area', '', '4000');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Social area - Drugs', 'Social area - Drugs', '', '4100');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Social area - Criminality', 'Social area - Criminality', '', '4200');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Social area - Charity', 'Social area - Charity', '', '4300');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Social area - Health', 'Social area - Health', '', '4400');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Social area - (Un)employment', 'Social area - (Un)employment', '', '4600');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Social area - Social Aid', 'Social area - Social Aid', '', '4700');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Culture', 'Culture', '', '5000');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Culture - Commemoration', 'Culture - Commemoration', '', '5100');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Culture - Art', 'Culture - Art', '', '5200');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Human and citizen`s rights', 'Human and citizen`s rights', '', '6000');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Human and citizen`s rights - Democracy', 'Human and citizen`s rights - Democracy', '', '6100');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Human and citizen`s rights - Consumer Protection', 'Human and citizen`s rights - Consumer Protection', '', '6200');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Human and citizen`s rights - Minorities', 'Human and citizen`s rights - Minorities', '', '6300');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Education - School system', 'Education - School system', '', '7200');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Education - Science', 'Education - Science', '', '7200');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Education', 'Education', '', '7000');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Religion', 'Religion', '', '8000');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Spare time', 'Spare time', '', '9000');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Spare time - Sport', 'Spare time - Sport', '', '9100');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Regions', 'Regions', '', '10000');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Regions - Self-government', 'Regions - Self-government', '', '10100');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Regions - Comunities', 'Regions - Comunities', '', '10200');
INSERT INTO constant VALUES( 'lt_apcCategories', 'Regions - Development', 'Regions - Development', '', '10300');
INSERT INTO constant VALUES( 'lt_apcCategories', 'People', 'People', '', '11000');
INSERT INTO constant VALUES( 'lt_apcCategories', 'People - Children', 'People - Children', '', '11100');
INSERT INTO constant VALUES( 'lt_apcCategories', 'People - Gender', 'People - Gender', '', '11200');
INSERT INTO constant VALUES( 'lt_apcCategories', 'People - Seniors', 'People - Seniors', '', '11300');
INSERT INTO constant VALUES( 'lt_apcCategories', 'People - Family', 'People - Family', '', '11400');
INSERT INTO constant VALUES( 'lt_apcCategories', 'People - Adults', 'People - Adults', '', '11500');
INSERT INTO constant VALUES( 'lt_apcCategories', 'World', 'World', '', '12000');
INSERT INTO constant VALUES( 'lt_apcCategories', 'World - Internacional Aid', 'World - Internacional Aid', '', '12100');
INSERT INTO constant VALUES( 'lt_groupNames', 'Code Pages', 'lt_codepages', '', '0');
INSERT INTO constant VALUES( 'lt_groupNames', 'Languages Shortcuts', 'lt_languages', '', '1000');
INSERT INTO constant VALUES( 'lt_groupNames', 'APC-wide Categories', 'lt_apcCategories', '', '1000');
INSERT INTO constant VALUES( 'lt_groupNames', 'AA Core Bins', 'AA_Core_Bins....', '', '10000');
INSERT INTO constant VALUES( 'AA_Core_Bins....', 'Approved', '1', '', '100');
INSERT INTO constant VALUES( 'AA_Core_Bins....', 'Holding Bin', '2', '', '200');
INSERT INTO constant VALUES( 'AA_Core_Bins....', 'Trash Bin', '3', '', '300');

INSERT INTO slice_owner VALUES( 'AA_Core.........', 'Action Aplications System', 'technical@ecn.cz');

# --------------------------------------------------------
# AA Core slice for internal use only (defines APC wide field types and its default values in process of  creation

INSERT INTO slice VALUES( 'AA_Core_Fields..', 'Action Aplication Core', 'AA_Core_Fields..', '200', '0', '', '975157733', '1', 'AA_Core_Fields..', '1', '', '', '','', '', '0', '', '', '', '', '', '1', '', 'http://aa.ecn.cz', '5000', '10000', 'en_news_lang.php3', '()', '()', '1', '0', '', '', '', '', '', '', '', '', '', '');

INSERT INTO field VALUES( 'headline', '', 'AA_Core_Fields..', 'Headline', '100', 'Headline', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'abstract', '', 'AA_Core_Fields..', 'Abstract', '189', 'Abstract', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'txt:8', '', '100', '', '', '', '', '0', '1', '1', '_#UNDEFINE', 'f_t', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '1', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'full_text', '', 'AA_Core_Fields..', 'Fulltext', '300', 'Fulltext', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'txt:8', '', '100', '', '', '', '', '0', '1', '1', '_#UNDEFINE', 'f_t', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '1', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'hl_href', '', 'AA_Core_Fields..', 'Headline URL', '1655', 'Link for the headline (for external links)', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_f', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1');
INSERT INTO field VALUES( 'link_only', '', 'AA_Core_Fields..', 'External item', '1755', 'Use External link instead of fulltext?', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'chb', '', '100', '', '', '', '', '0', '0', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'bool', 'boo', '1', '1');
INSERT INTO field VALUES( 'place', '', 'AA_Core_Fields..', 'Locality', '2155', 'Item locality', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'source', '', 'AA_Core_Fields..', 'Source', '1955', 'Source of the item', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'source_href', '', 'AA_Core_Fields..', 'Source URL', '2055', 'URL of the source', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_s', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1');
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
INSERT INTO field VALUES( 'redirect', '', 'AA_Core_Fields..', 'Show on URL', '2655', 'Show fulltext on another URL', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1');
INSERT INTO field VALUES( 'source_desc', '', 'AA_Core_Fields..', 'Source description', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'source_addr', '', 'AA_Core_Fields..', 'Source address', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'source_city', '', 'AA_Core_Fields..', 'Source city', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'source_prov', '', 'AA_Core_Fields..', 'Source province', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'source_cntry', '', 'AA_Core_Fields..', 'Source country', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'time', '', 'AA_Core_Fields..', 'Time', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'con_name', '', 'AA_Core_Fields..', 'Contact name', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'con_email', '', 'AA_Core_Fields..', 'Contact e-mail', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'con_phone', '', 'AA_Core_Fields..', 'Contact phone', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'con_fax', '', 'AA_Core_Fields..', 'Contact fax', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'loc_name', '', 'AA_Core_Fields..', 'Location name', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'loc_address', '', 'AA_Core_Fields..', 'Location address', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'loc_city', '', 'AA_Core_Fields..', 'Location city', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'loc_prov', '', 'AA_Core_Fields..', 'Location province', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'loc_cntry', '', 'AA_Core_Fields..', 'Location country', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'start_date', '', 'AA_Core_Fields..', 'Start date', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'now', '1', '0', '0', 'dte:1\'10\'1', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_d', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'date', 'dte', '1', '1');
INSERT INTO field VALUES( 'end_date', '', 'AA_Core_Fields..', 'End date', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'now', '1', '0', '0', 'dte:1\'10\'1', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_d', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'date', 'dte', '1', '1');
INSERT INTO field VALUES( 'keywords', '', 'AA_Core_Fields..', 'Keywords', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'subtitle', '', 'AA_Core_Fields..', 'Subtitle', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'year', '', 'AA_Core_Fields..', 'Year', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'number', '', 'AA_Core_Fields..', 'Number', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'number', 'num', '1', '1');
INSERT INTO field VALUES( 'page', '', 'AA_Core_Fields..', 'Page', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'number', 'num', '1', '1');
INSERT INTO field VALUES( 'price', '', 'AA_Core_Fields..', 'Price', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'number', 'num', '1', '1');
INSERT INTO field VALUES( 'organization', '', 'AA_Core_Fields..', 'Organization', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'file', '', 'AA_Core_Fields..', 'File', '2222', 'Select file for upload', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fil:*/*', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'fil', '1', '1');
INSERT INTO field VALUES( 'text', '', 'AA_Core_Fields..', 'Text', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'unspecified', '', 'AA_Core_Fields..', 'Unspecified', '100', '', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#UNDEFINE', 'f_h', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'url', '', 'AA_Core_Fields..', 'URL', '2055', 'Internet URL address', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#UNDEFINE', 'f_i', 'alias undefined - see Admin pages - Field setting', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1');


# --------------------------------------------------------
# Templete slices

INSERT INTO slice VALUES( 'News_EN_tmpl....', 'News (EN) Template', 'AA_Core.........', '200', '0', '', '975157733', '1', 'News_EN_tmpl....', '1', '', '<BR><FONT SIZE=+2 COLOR=blue>_#HEADLINE</FONT> <BR><B>_#PUB_DATE</B> <BR><img src=\"_#IMAGESRC\" width=\"_#IMGWIDTH\" height=\"_#IMG_HGHT\">_#FULLTEXT ', '','<font face=Arial color=#808080 size=-2>_#PUB_DATE - </font><font color=#FF0000><strong><a href=_#HDLN_URL>_#HEADLINE</a></strong></font><font color=#808080 size=-1><br>_#PLACE###(<a href="_#SRC_URL#">_#SOURCE##</a>) - </font><font color=black size=-1>_#ABSTRACT<br></font><br>', '', '0', '<br>', '<br>', '', '<p>_#CATEGORY</p>', '', '1', '', 'http://aa.ecn.cz', '5000', '10000', 'en_news_lang.php3', '()', '()', '1', '0', '', '', '', '', '', '<tr><td><input type=checkbox name="chb[x_#ITEM_ID#]" value=""></td><td class=ipostdate>_#PUB_DATE</td><td><a href="_#EDITITEM" class=iheadline>_#HEADLINE</a></td></tr>', '', '', '1', '1');

INSERT INTO field VALUES( 'category........', '', 'News_EN_tmpl....', 'Category', '1000', 'Category', 'http://aa.ecn.cz/aa/doc/help.html', 'txt:', '0', '0', '0', 'sel:lt_apcCategories', '', '100', '', '', '', '', '1', '1', '1', '_#HEADLINE', 'f_h', 'alias for Item Headline', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'cp_code.........', '', 'News_EN_tmpl....', 'Code Page', '1800', 'Language Code Page', 'http://aa.ecn.cz/aa/doc/help.html', 'txt:iso8859-1', '0', '0', '0', 'sel:lt_codepages', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'created_by......', '', 'News_EN_tmpl....', 'Created By', '2355', 'Identification of creator', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'nul', '', '100', '', '', '', '', '0', '0', '0', '_#CREATED#', 'f_h', 'alias for Written By', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'uid', '1', '1');
INSERT INTO field VALUES( 'edited_by.......', '', 'News_EN_tmpl....', 'Edited by', '1555', 'Identification of last editor', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'nul', '', '100', '', '', '', '', '0', '0', '0', '_#EDITEDBY', 'f_h', 'alias for Last edited By', '', '', '', '', '', '', '', '', '0', '0', '0', 'edited_by', 'text', 'uid', '1', '0');
INSERT INTO field VALUES( 'edit_note.......', '', 'News_EN_tmpl....', 'Editor`s note', '2355', 'There you can write your note (not displayed on the web)', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'txt', '', '100', '', '', '', '', '0', '0', '0', '_#EDITNOTE', 'f_h', 'alias for Editor`s note', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'expiry_date.....', '', 'News_EN_tmpl....', 'Expiry Date', '955', 'Date when the news expires', 'http://aa.ecn.cz/aa/doc/help.html', 'dte:2000', '1', '0', '0', 'dte:1\'10\'1', '', '100', '', '', '', '', '0', '0', '0', '_#EXP_DATE', 'f_d:expiry_date', 'alias for Expiry Date', '', '', '', '', '', '', '', '', '0', '0', '0', 'expiry_date', 'date', 'dte', '1', '0');
INSERT INTO field VALUES( 'e_posted_by.....', '', 'News_EN_tmpl....', 'Author`s e-mail', '2255', 'E-mail to author', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#E_POSTED', 'f_h', 'alias for Author`s e-mail', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'email', 'qte', '1', '1');
INSERT INTO field VALUES( 'full_text.......', '', 'News_EN_tmpl....', 'Fulltext', '300', 'Fulltext', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'txt:8', '', '100', '', '', '', '', '0', '1', '1', '_#FULLTEXT', 'f_t', 'alias for Fulltext<br>(HTML tags are striped or not depending on HTML formated item setting)', '', '', '', '', '', '', '', '', '0', '0', '1', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'headline........', '', 'News_EN_tmpl....', 'Headline', '100', 'Headline of the news', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '1', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#HEADLINE', 'f_h', 'alias for Item Headline', '_#HDLN_URL', 'f_f', 'alias for News URL<br>(substituted by External news link URL(if External news is checked) or link to Fulltext)<div class=example><em>Example: </em>&lt;a href=_#HDLN_URL&gt;_#HEADLINE&lt;/a&gt;</div>', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'highlight.......', '', 'News_EN_tmpl....', 'Highlight', '1454', 'Interesting news - shown on homepage', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'chb', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', 'highlight', 'bool', 'boo', '1', '0');
INSERT INTO field VALUES( 'hl_href.........', '', 'News_EN_tmpl....', 'Headline URL', '1655', 'Link for the headline (for external links)', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#HDLN_URL', 'f_f', 'alias for News URL<br>(substituted by External news link URL(if External news is checked) or link to Fulltext)<div class=example><em>Example: </em>&lt;a href=_#HDLN_URL&gt;_#HEADLINE&lt;/a&gt;</div>', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1');
INSERT INTO field VALUES( 'img_height......', '', 'News_EN_tmpl....', 'Image height', '2555', 'Height of image (like: 100, 50%)', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#IMG_HGHT', 'f_g', 'alias for Image Height<br>(if no height defined, program tries to remove <em>height=</em> atribute from format string<div class=example><em>Example: </em>&lt;img src=\"_#IMAGESRC\" width=_#IMGWIDTH height=_#IMG_HGHT&gt;</div>', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'img_src.........', '', 'News_EN_tmpl....', 'Image URL', '2055', 'URL of the image', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#IMAGESRC', 'f_i', 'alias for Image URL<br>(if there is no image url defined in database, default url is used instead (see NO_PICTURE_URL constant in en_*_lang.php3 file))<div class=example><em>Example: </em>&lt;img src=\"_#IMAGESRC\"&gt;</div>', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1');
INSERT INTO field VALUES( 'img_width.......', '', 'News_EN_tmpl....', 'Image width', '2455', 'Width of image (like: 100, 50%)', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#IMGWIDTH', 'f_w', 'alias for Image Width<br>(if no width defined, program tries to remove <em>width=</em> atribute from format string<div class=example><em>Example: </em>&lt;img src=\"_#IMAGESRC\" width=_#IMGWIDTH height=_#IMG_HGHT&gt;</div>', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'lang_code.......', '', 'News_EN_tmpl....', 'Language Code', '1700', 'Code of used language', 'http://aa.ecn.cz/aa/doc/help.html', 'txt:EN', '0', '0', '0', 'sel:lt_languages', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'last_edit.......', '', 'News_EN_tmpl....', 'Last Edit', '1600', 'Date of last edit', 'http://aa.ecn.cz/aa/doc/help.html', 'now:', '0', '0', '0', 'dte:1\'10\'1', '', '100', '', '', '', '', '0', '0', '0', '_#LASTEDIT', 'f_d:last_edit', 'alias for Last Edit', '', '', '', '', '', '', '', '', '0', '0', '0', 'last_edit', 'date', 'now', '1', '0');
INSERT INTO field VALUES( 'link_only.......', '', 'News_EN_tmpl....', 'External news', '1755', 'Use External link instead of fulltext?', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'chb', '', '100', '', '', '', '', '0', '0', '1', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'bool', 'boo', '1', '1');
INSERT INTO field VALUES( 'place...........', '', 'News_EN_tmpl....', 'Locality', '2155', 'News locality', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#PLACE###', 'f_h', 'alias for Locality', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'posted_by.......', '', 'News_EN_tmpl....', 'Posted by', '1555', 'Identification of author', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#POSTEDBY', 'f_h', 'alias for Author', '', '', '', '', '', '', '', '', '0', '0', '0', 'posted_by', 'text', 'qte', '1', '0');
INSERT INTO field VALUES( 'post_date.......', '', 'News_EN_tmpl....', 'Post Date', '754', 'Date of posting this news', 'http://aa.ecn.cz/aa/doc/help.html', 'now:', '1', '0', '0', 'nul', '', '100', '', '', '', '', '0', '0', '0', '_#POSTDATE', 'f_d:post_date', 'alias for Post Date', '', '', '', '', '', '', '', '', '0', '0', '0', 'post_date', 'date', 'now', '0', '0');
INSERT INTO field VALUES( 'publish_date....', '', 'News_EN_tmpl....', 'Publish Date', '930', 'Date when the news will be published', 'http://aa.ecn.cz/aa/doc/help.html', 'now:', '1', '0', '0', 'dte:1\'10\'1', '', '100', '', '', '', '', '0', '0', '0', '_#PUB_DATE', 'f_d:publish_date', 'alias for Publish Date', '', '', '', '', '', '', '', '', '0', '0', '0', 'publish_date', 'date', 'dte', '1', '0');
INSERT INTO field VALUES( 'redirect........', '', 'News_EN_tmpl....', 'Show on URL', '2655', 'Show fulltext on another URL', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1');
INSERT INTO field VALUES( 'source..........', '', 'News_EN_tmpl....', 'Source', '1955', 'Source of the news', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#SOURCE##', 'f_h', 'alias for Source Name<br>(see _#LINK_SRC for text source link)', '', '', '', '', '', '', '', '', '0', '0', '0', '', 'text', 'qte', '1', '1');
INSERT INTO field VALUES( 'source_href.....', '', 'News_EN_tmpl....', 'Source URL', '2055', 'URL of the source', 'http://aa.ecn.cz/aa/doc/help.html', 'qte', '0', '0', '0', 'fld', '', '100', '', '', '', '', '1', '1', '1', '_#SRC_URL#', 'f_s', 'alias for Source URL<br>(if there is no source url defined in database, default source url is displayed (see NO_SOURCE_URL constant in en_*_lang.php3 file))<br>Use _#LINK_SRC for text source link.<div class=example><em>Example: </em>&lt;a href\"_#SRC_URL#\"', '_#LINK_SRC', 'f_l', 'alias for Source Name with link.<br>(substituted by &lt;a href=\"_#SRC_URL#\"&gt;_#SOURCE##&lt;/a&gt; if Source URL defined, otherwise _#SOURCE## only)', '', '', '', '', '', '0', '0', '0', '', 'url', 'qte', '1', '1');
INSERT INTO field VALUES( 'status_code.....', '', 'News_EN_tmpl....', 'Status Code', '1005', 'Select in which bin should the news appear', 'http://aa.ecn.cz/aa/doc/help.html', 'qte:1', '1', '0', '0', 'sel:AA_Core_Bins....', '', '100', '', '', '', '', '0', '0', '0', '', '', '', '', '', '', '', '', '', '', '', '0', '0', '0', 'status_code', 'number', 'num', '1', '0');
INSERT INTO field VALUES( 'slice_id........', '', 'News_EN_tmpl....', 'Slice', '54', 'Internal field - do not change', 'http://aa.ecn.cz/aa/doc/help.html', 'qte:1', '1', '0', '0', 'fld', '', '100', '', '', '', '', '0', '0', '0', '_#SLICE_ID', 'f_n:slice_id', 'alias for id of slice', '', '', '', '', '', '', '', '', '0', '0', '0', 'slice_id', '', 'nul', '0', '0');
