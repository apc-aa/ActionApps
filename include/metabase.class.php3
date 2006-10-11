<?php

// table definitions
$this->tables['active_sessions'] = new AA_Metabase_Table('active_sessions',
    array(
        array('sid', 'varbinary', 32, 'NOT NULL', ''),
        array('name', 'varchar', 32, 'NOT NULL', ''),
        array('val', 'text', '', '', ''),
        array('changed', 'varchar', 14, 'NOT NULL', '')
         ),
    array('name', 'sid'),
    array('changed' => array(array('changed')))
    );

$this->tables['alerts_admin'] = new AA_Metabase_Table('alerts_admin',
    array(
        array('id', 'int', 10, 'NOT NULL', 'auto_increment'),
        array('last_mail_confirm', 'int', 10, 'NOT NULL', '0'),
        array('mail_confirm', 'int', 4, 'NOT NULL', '3'),
        array('delete_not_confirmed', 'int', 4, 'NOT NULL', '10'),
        array('last_delete', 'int', 10, 'NOT NULL', '0')
        ),
    array('id')
    );

$this->tables['alerts_collection'] = new AA_Metabase_Table('alerts_collection',
    array(
        array('id', 'char', 6, 'NOT NULL', ''),
        array('module_id', 'varbinary', 16, 'NOT NULL', ''),
        array('emailid_welcome', 'int', 11, '', 'NULL'),
        array('emailid_alert', 'int', 11, '', 'NULL'),
        array('slice_id', 'varbinary', 16, '', 'NULL')
        ),
    array('id'),
    array('module_id' => array(array('module_id'),'UNIQUE'))
    );

$this->tables['alerts_collection_filter'] = new AA_Metabase_Table('alerts_collection_filter',
    array(
        array('collectionid', 'varbinary', 6, 'NOT NULL', ''),
        array('filterid', 'int', 11, 'NOT NULL', '0'),
        array('myindex', 'tinyint', 4, 'NOT NULL', '0')
        ),
    array('collectionid','filterid')
    );
$this->tables['alerts_collection_howoften'] = new AA_Metabase_Table('alerts_collection_howoften',
    array(
        array('collectionid', 'varbinary', 6, 'NOT NULL', ''),
        array('howoften', 'char', 20, 'NOT NULL', ''),
        array('last', 'int', 10, 'NOT NULL', '0')
        ),
    array('collectionid','howoften')
    );
$this->tables['alerts_filter'] = new AA_Metabase_Table('alerts_filter',
    array(
        array('id', 'int', 11, 'NOT NULL', 'auto_increment'),
        array('vid', 'int', 11, 'NOT NULL', '0'),
        array('conds', 'text', '', 'NOT NULL', ''),
        array('description', 'text', '', 'NOT NULL', '')
        ),
    array('id')
    );

$this->tables['auth_group'] = new AA_Metabase_Table('auth_group',
    array(
        array('username', 'varchar', 50, 'NOT NULL', ''),
        array('groups', 'varchar', 50, 'NOT NULL', ''),
        array('last_changed', 'int', 11, 'NOT NULL', '0')
        ),
    array('username','groups')
    );

$this->tables['auth_log'] = new AA_Metabase_Table('auth_log',
    array(
        array('result', 'text', '', 'NOT NULL', ''),
        array('created', 'int', 11, 'NOT NULL', '0')
        ),
    array('created')
    );

$this->tables['auth_user'] = new AA_Metabase_Table('auth_user',
    array(
        array('username', 'varchar', 50, 'NOT NULL', ''),
        array('passwd', 'varchar', 50, 'NOT NULL', ''),
        array('last_changed', 'int', 11, 'NOT NULL', '0')
        ),
    array('username')
    );

$this->tables['constant'] = new AA_Metabase_Table('constant',
    array(
        array('id', 'varbinary', 16, 'NOT NULL', ''),
        array('group_id', 'varbinary', 16, 'NOT NULL', ''),
        array('name', 'char', 150, 'NOT NULL', ''),
        array('value', 'char', 255, 'NOT NULL', ''),
        array('class', 'varbinary', 16, '', 'NULL'),
        array('pri', 'smallint', 5, 'NOT NULL', '100'),
        array('ancestors', 'char', 160, '', 'NULL'),
        array('description', 'char', 250, '', 'NULL'),
        array('short_id', 'int', 11, 'NOT NULL', 'auto_increment')
        ),
    array('id'),
    array('group_id' => array(array('group_id')),
          'short_id' => array(array('short_id'))
         )
    );

$this->tables['constant_slice'] = new AA_Metabase_Table('constant_slice',
    array(
        array('slice_id', 'varbinary', 16, '', 'NULL'),
        array('group_id', 'varbinary', 16, 'NOT NULL', ''),
        array('propagate', 'tinyint', 1, 'NOT NULL', '1'),
        array('levelcount', 'tinyint', 2, 'NOT NULL', '2'),
        array('horizontal', 'tinyint', 1, 'NOT NULL', '0'),
        array('hidevalue', 'tinyint', 1, 'NOT NULL', '0'),
        array('hierarch', 'tinyint', 1, 'NOT NULL', '0')
        ),
    array('group_id')
    );

$this->tables['content'] = new AA_Metabase_Table('content',
    array(
        array('item_id', 'varbinary', 16, 'NOT NULL', ''),
        array('field_id', 'varbinary', 16, 'NOT NULL', ''),
        array('number', 'bigint', 20, '', 'NULL'),
        array('text', 'mediumtext', '', '', ''),
        array('flag', 'smallint', 6, '', 'NULL')
        ),
    array(),
    array('text' => array(array(array('text'),10)),
          'item_id' => array(array('item_id', 'field_id', array('text',16)))
         )
    );

$this->tables['cron'] = new AA_Metabase_Table('cron',
    array(
        array('id', 'bigint', 30, 'NOT NULL', 'auto_increment'),
        array('minutes', 'varchar', 30, '', 'NULL'),
        array('hours', 'varchar', 30, '', 'NULL'),
        array('mday', 'varchar', 30, '', 'NULL'),
        array('mon', 'varchar', 30, '', 'NULL'),
        array('wday', 'varchar', 30, '', 'NULL'),
        array('script', 'varchar', 100, '', 'NULL'),
        array('params', 'varchar', 200, '', 'NULL'),
        array('last_run', 'bigint', 30, '', 'NULL')
        ),
    array('id')
    );

$this->tables['db_sequence'] = new AA_Metabase_Table('db_sequence',
    array(
        array('seq_name', 'varchar', 127, 'NOT NULL', ''),
        array('nextid', 'unsigned int', 10, 'NOT NULL', '0')
        ),
    array('seq_name')
    );

$this->tables['discussion'] = new AA_Metabase_Table('discussion',
    array(
        array('id', 'varbinary', 16, 'NOT NULL', ''),
        array('parent', 'varbinary', 16, 'NOT NULL', ''),
        array('item_id', 'varbinary', 16, 'NOT NULL', ''),
        array('date', 'bigint', 20, 'NOT NULL', '0'),
        array('subject', 'text', '', '', ''),
        array('author', 'varchar', 255, '', 'NULL'),
        array('e_mail', 'varchar', 80, '', 'NULL'),
        array('body', 'text', '', '', ''),
        array('state', 'int', 11, 'NOT NULL', '0'),
        array('flag', 'int', 11, 'NOT NULL', '0'),
        array('url_address', 'varchar', 255, '', 'NULL'),
        array('url_description', 'text', '', '', ''),
        array('remote_addr', 'varchar', 255, '', 'NULL'),
        array('free1', 'text', '', '', ''),
        array('free2', 'text', '', '', '')
        ),
    array('id')
    );

$this->tables['ef_categories'] = new AA_Metabase_Table('ef_categories',
    array(
        array('category', 'varchar', 255, 'NOT NULL', ''),
        array('category_name', 'varchar', 255, 'NOT NULL', ''),
        array('category_id', 'varbinary', 16, 'NOT NULL', ''),
        array('feed_id', 'int', 11, 'NOT NULL', '0'),
        array('target_category_id', 'varbinary', 16, 'NOT NULL', ''),
        array('approved', 'int', 11, 'NOT NULL', '0')
        ),
    array('category_id','feed_id')
    );

$this->tables['ef_permissions'] = new AA_Metabase_Table('ef_permissions',
    array(
        array('slice_id', 'varbinary', 16, 'NOT NULL', ''),
        array('node', 'varchar', 150, 'NOT NULL', ''),
        array('user', 'varchar', 50, 'NOT NULL', '')
        ),
    array('slice_id','node','user')
    );

$this->tables['email'] = new AA_Metabase_Table('email',
    array(
        array('id', 'int', 11, 'NOT NULL', 'auto_increment'),
        array('description', 'varchar', 255, 'NOT NULL', ''),
        array('subject', 'text', '', 'NOT NULL', ''),
        array('body', 'text', '', 'NOT NULL', ''),
        array('header_from', 'text', '', 'NOT NULL', ''),
        array('reply_to', 'text', '', 'NOT NULL', ''),
        array('errors_to', 'text', '', 'NOT NULL', ''),
        array('sender', 'text', '', 'NOT NULL', ''),
        array('lang', 'char', 2, 'NOT NULL', 'en'),
        array('owner_module_id', 'varbinary', 16, 'NOT NULL', ''),
        array('html', 'smallint', 1, 'NOT NULL', '1'),
        array('type', 'varchar', 20, 'NOT NULL', '')
        ),
    array('id')
    );

$this->tables['email_auto_user'] = new AA_Metabase_Table('email_auto_user',
    array(
        array('uid', 'char', 50, 'NOT NULL', ''),
        array('creation_time', 'bigint', 20, 'NOT NULL', '0'),
        array('last_change', 'bigint', 20, 'NOT NULL', '0'),
        array('clear_pw', 'char', 40, '', 'NULL'),
        array('confirmed', 'smallint', 5, 'NOT NULL', '0'),
        array('confirm_key', 'char', 16, '', 'NULL')
        ),
    array('uid')
    );

$this->tables['email_notify'] = new AA_Metabase_Table('email_notify',
    array(
        array('slice_id', 'varbinary', 16, 'NOT NULL', ''),
        array('uid', 'char', 60, 'NOT NULL', ''),
        array('function', 'smallint', 5, 'NOT NULL', '0')
        ),
    array('slice_id','uid','function')
    );

$this->tables['event'] = new AA_Metabase_Table('event',
    array(
        array('id', 'varbinary', 32, 'NOT NULL', '', 'record id'),
        array('type', 'varchar', 32, 'NOT NULL', '', 'type of event'),
        array('class', 'varchar', 32, '', 'NULL', 'used for event condition'),
        array('selector', 'varchar', 255, '', 'NULL', 'used for event condition - mostly id of changed item, ...'),
        array('reaction', 'varchar', 50, 'NOT NULL', '', 'name of php class which is invoked when the event come'),
        array('params', 'text', '', '', '', 'parameters for reaction object')
        ),
    array('id'),
    array('type_class' => array(array('type','class')),
          'type_selector' => array(array('type', array('selector',32)))
         )
    );

$this->tables['external_feeds'] = new AA_Metabase_Table('external_feeds',
    array(
        array('feed_id', 'int', 11, 'NOT NULL', 'auto_increment'),
        array('slice_id', 'varbinary', 16, 'NOT NULL', ''),
        array('node_name', 'varchar', 150, 'NOT NULL', ''),
        array('remote_slice_id', 'varbinary', 16, 'NOT NULL', ''),
        array('user_id', 'varchar', 200, 'NOT NULL', ''),
        array('newest_item', 'varchar', 40, 'NOT NULL', ''),
        array('remote_slice_name', 'varchar', 200, 'NOT NULL', ''),
        array('feed_mode', 'varchar', 10, 'NOT NULL', '')
        ),
    array('feed_id')
    );

$this->tables['feedmap'] = new AA_Metabase_Table('feedmap',
    array(
        array('from_slice_id', 'varbinary', 16, 'NOT NULL', ''),
        array('from_field_id', 'varbinary', 16, 'NOT NULL', ''),
        array('to_slice_id', 'varbinary', 16, 'NOT NULL', ''),
        array('to_field_id', 'varbinary', 16, 'NOT NULL', ''),
        array('flag', 'int', 11, '', 'NULL'),
        array('value', 'mediumtext', '', '', ''),
        array('from_field_name', 'varchar', 255, 'NOT NULL', '')
        ),
    array(),
    array('from_slice_id'=>array(array('from_slice_id','to_slice_id')))
    );

$this->tables['feedperms'] = new AA_Metabase_Table('feedperms',
    array(
        array('from_id', 'varbinary', 16, 'NOT NULL', ''),
        array('to_id', 'varbinary', 16, 'NOT NULL', ''),
        array('flag', 'int', 11, '', 'NULL')
        )
    );

$this->tables['feeds'] = new AA_Metabase_Table('feeds',
    array(
        array('from_id', 'varbinary', 16, 'NOT NULL', ''),
        array('to_id', 'varbinary', 16, 'NOT NULL', ''),
        array('category_id', 'varbinary', 16, '', 'NULL'),
        array('all_categories', 'smallint', 5, '', 'NULL'),
        array('to_approved', 'smallint', 5, '', 'NULL'),
        array('to_category_id', 'varbinary', 16, '', 'NULL')
        ),
    array(),
    array('from_id'=>array(array('from_id')))
    );

$this->tables['field'] = new AA_Metabase_Table('field',
    array(
        array('id', 'varbinary', 16, 'NOT NULL', ''),
        array('type', 'varchar', 16, 'NOT NULL', ''),
        array('slice_id', 'varbinary', 16, 'NOT NULL', ''),
        array('name', 'varchar', 255, 'NOT NULL', ''),
        array('input_pri', 'smallint', 5, 'NOT NULL', '100'),
        array('input_help', 'varchar', 255, '', 'NULL'),
        array('input_morehlp', 'text', '', '', ''),
        array('input_default', 'mediumtext', '', '', ''),
        array('required', 'smallint', 5, '', 'NULL'),
        array('feed', 'smallint', 5, '', 'NULL'),
        array('multiple', 'smallint', 5, '', 'NULL'),
        array('input_show_func', 'varchar', 255, '', 'NULL'),
        array('content_id', 'varbinary', 16, '', 'NULL'),
        array('search_pri', 'smallint', 5, 'NOT NULL', '100'),
        array('search_type', 'varchar', 16, '', 'NULL'),
        array('search_help', 'varchar', 255, '', 'NULL'),
        array('search_before', 'text', '', '', ''),
        array('search_more_help', 'text', '', '', ''),
        array('search_show', 'smallint', 5, '', 'NULL'),
        array('search_ft_show', 'smallint', 5, '', 'NULL'),
        array('search_ft_default', 'smallint', 5, '', 'NULL'),
        array('alias1', 'varchar', 10, '', 'NULL'),
        array('alias1_func', 'varchar', 255, '', 'NULL'),
        array('alias1_help', 'varchar', 255, '', 'NULL'),
        array('alias2', 'varchar', 10, '', 'NULL'),
        array('alias2_func', 'varchar', 255, '', 'NULL'),
        array('alias2_help', 'varchar', 255, '', 'NULL'),
        array('alias3', 'varchar', 10, '', 'NULL'),
        array('alias3_func', 'varchar', 255, '', 'NULL'),
        array('alias3_help', 'varchar', 255, '', 'NULL'),
        array('input_before', 'text', '', '', ''),
        array('aditional', 'text', '', '', ''),
        array('content_edit', 'smallint', 5, '', 'NULL'),
        array('html_default', 'smallint', 5, '', 'NULL'),
        array('html_show', 'smallint', 5, '', 'NULL'),
        array('in_item_tbl', 'varchar', 16, '', 'NULL'),
        array('input_validate', 'varchar', 255, 'NOT NULL', ''),
        array('input_insert_func', 'varchar', 255, 'NOT NULL', ''),
        array('input_show', 'smallint', 5, '', 'NULL'),
        array('text_stored', 'smallint', 5, '', 1)
        ),
    array(),
    array('slice_id'=>array(array('slice_id','id')))
    );

$this->tables['groups'] = new AA_Metabase_Table('groups',
    array(
        array('name', 'varchar', 32, 'NOT NULL', ''),
        array('description', 'varchar', 255, 'NOT NULL', '')
        ),
    array('name')
    );

$this->tables['item'] = new AA_Metabase_Table('item',
    array(
        array('id', 'varbinary', 16, 'NOT NULL', ''),
        array('short_id', 'int', 11, 'NOT NULL', 'auto_increment'),
        array('slice_id', 'varbinary', 16, 'NOT NULL', ''),
        array('status_code', 'smallint', 5, 'NOT NULL', '0'),
        array('post_date', 'bigint', 20, 'NOT NULL', '0'),
        array('publish_date', 'bigint', 20, '', 'NULL'),
        array('expiry_date', 'bigint', 20, '', 'NULL'),
        array('highlight', 'smallint', 5, '', 'NULL'),
        array('posted_by', 'char', 60, '', 'NULL'),
        array('edited_by', 'char', 60, '', 'NULL'),
        array('last_edit', 'bigint', 20, '', 'NULL'),
        array('display_count', 'int', 11, 'NOT NULL', '0'),
        array('flags', 'char', 30, '', 'NULL'),
        array('disc_count', 'int', 11, '', '0'),
        array('disc_app', 'int', 11, '', '0'),
        array('externally_fed', 'char', 150, 'NOT NULL', ''),
        array('moved2active', 'int', 10, 'NOT NULL', '0')
        ),
    array('id'),
    array('short_id' => array(array('short_id')),
          'slice_id_2' => array(array('slice_id', 'status_code', 'publish_date')),
          'expiry_date' => array(array('expiry_date'))
         )
    );

$this->tables['jump'] = new AA_Metabase_Table('jump',
    array(
        array('slice_id', 'varbinary', 16, 'NOT NULL', ''),
        array('destination', 'varchar', 255, '', 'NULL'),
        array('dest_slice_id', 'varbinary', 16, '', 'NULL')
        ),
    array('slice_id')
    );

$this->tables['links'] = new AA_Metabase_Table('links',
    array(
        array('id', 'varbinary', 16, 'NOT NULL', ''),
        array('start_id', 'int', 10, 'NOT NULL', '0'),
        array('tree_start', 'int', 11, 'NOT NULL', '0'),
        array('select_start', 'int', 11, '', 'NULL'),
        array('default_cat_tmpl', 'char', 60, 'NOT NULL', ''),
        array('link_tmpl', 'char', 60, 'NOT NULL', ''),
        ),
    array('id')
    );

$this->tables['links_cat_cat'] = new AA_Metabase_Table('links_cat_cat',
    array(
        array('category_id', 'unsigned int', 10, 'NOT NULL', '0'),
        array('what_id', 'int(10) unsigned NOT NULL default '0',
        array('base', 'enum('n','y') NOT NULL default 'y',
        array('state', 'enum('hidden','highlight','visible') NOT NULL default 'visible',
        array('proposal', 'enum('n','y') NOT NULL default 'n',
        array('priority', 'float(10,2) default NULL,
        array('proposal_delete, 'enum('n','y') NOT NULL default 'n',
        array('a_id', 'int(10) unsigned NOT NULL auto_increment,
        ),
    array('a_id'),
                          KEY what_id (what_id)
    );
$this->tables['links_categories'] = new AA_Metabase_Table('links_categories',
    array(
                          id int(10) unsigned NOT NULL auto_increment,
        array('name', 'varchar', 255, '', 'NULL'),
        array('html_template', 'varchar', 255, '', 'NULL'),
                          deleted enum('n','y') NOT NULL default 'n',
        array('path', 'varchar', 255, '', 'NULL'),
        array('inc_file1', 'varchar', 255, '', 'NULL'),
        array('link_count', 'mediumint', 9, 'NOT NULL', '0'),
        array('inc_file2', 'varchar', 255, '', 'NULL'),
        array('banner_file', 'varchar', 255, '', 'NULL'),
        array('description', 'text', '', '', ''),
        array('additional', 'text', '', '', ''),
        array('note', 'text', '', '', ''),
        array('nolinks', 'tinyint', 4, 'NOT NULL', '0'),
        ),
    array('id'),
                          KEY path (path),
                          KEY id (id','path)
    );
$this->tables['links_changes'] = new AA_Metabase_Table('links_changes',
    array(
                          changed_link_id int(10) unsigned NOT NULL default '0',
                          proposal_link_id int(10) unsigned NOT NULL default '0',
                          rejected enum('n','y') NOT NULL default 'n',
                          KEY proposal_link_id (proposal_link_id),
                          KEY rejected (rejected),
                          KEY changed_link_id (changed_link_id','rejected)
    );
$this->tables['links_languages'] = new AA_Metabase_Table('links_languages',
    array(
                          id int(10) unsigned NOT NULL default '0',
        array('name', 'varchar', 20, 'NOT NULL', ''),
        array('short_name', 'varchar', 5, 'NOT NULL', ''),
        ),
    array('id'),
                          KEY name (name)
    );
$this->tables['links_link_cat'] = new AA_Metabase_Table('links_link_cat',
    array(
                          category_id int(10) unsigned NOT NULL default '0',
                          what_id int(10) unsigned NOT NULL default '0',
                          base enum('n','y') NOT NULL default 'y',
                          state enum('hidden','highlight','visible') NOT NULL default 'visible',
                          proposal enum('n','y') NOT NULL default 'n',
                          priority float(10,2) default NULL,
                          proposal_delete enum('n','y') NOT NULL default 'n',
                          a_id int(10) unsigned NOT NULL auto_increment,
        ),
    array('a_id'),
                          KEY proposal (proposal','base','state),
                          KEY category_id (category_id','proposal','base','state),
                          KEY what_id (what_id','proposal','base','state)
    );
$this->tables['links_link_lang'] = new AA_Metabase_Table('links_link_lang',
    array(
                          link_id int(10) unsigned NOT NULL default '0',
                          lang_id int(10) unsigned NOT NULL default '0',
                          KEY link_id (link_id','lang_id)
    );
$this->tables['links_link_reg'] = new AA_Metabase_Table('links_link_reg',
    array(
                          link_id int(10) unsigned NOT NULL default '0',
                          region_id int(10) unsigned NOT NULL default '0',
                          KEY link_id (link_id','region_id)
    );
$this->tables['links_links'] = new AA_Metabase_Table('links_links',
    array(
                          id int(10) unsigned NOT NULL auto_increment,
        array('name', 'varchar', 255, '', 'NULL'),
        array('description', 'text', '', '', ''),
        array('rate', 'int', 10, '', 'NULL'),
        array('votes', 'int', 11, 'NOT NULL', '0'),
        array('plus_votes', 'int', 11, 'NOT NULL', '0'),
        array('created_by', 'varchar', 60, '', 'NULL'),
        array('edited_by', 'varchar', 60, '', 'NULL'),
        array('checked_by', 'varchar', 60, '', 'NULL'),
        array('initiator', 'varchar', 255, '', 'NULL'),
                          url text NOT NULL,
        array('created', 'int', 11, 'NOT NULL', '0'),
        array('last_edit', 'int', 11, 'NOT NULL', '0'),
        array('checked', 'int', 11, 'NOT NULL', '0'),
        array('voted', 'int', 11, 'NOT NULL', '0'),
        array('flag', 'int', 11, '', 'NULL'),
        array('original_name', 'varchar', 255, '', 'NULL'),
        array('type', 'varchar', 120, '', 'NULL'),
        array('org_city', 'varchar', 255, '', 'NULL'),
        array('org_post_code', 'varchar', 20, '', 'NULL'),
        array('org_phone', 'varchar', 120, '', 'NULL'),
        array('org_fax', 'varchar', 120, '', 'NULL'),
        array('org_email', 'varchar', 120, '', 'NULL'),
        array('org_street', 'varchar', 255, '', 'NULL'),
        array('folder', 'int', 11, 'NOT NULL', '1'),
        array('note', 'text', '', '', ''),
        array('validated', 'int', 11, 'NOT NULL', '0'),
        array('valid_codes', 'text', '', '', ''),
        array('valid_rank', 'int', 11, 'NOT NULL', '0'),
        ),
    array('id'),
                          KEY checked (checked),
                          KEY type (type),
                          KEY validated (validated),
                          KEY valid_rank (valid_rank),
                          KEY name (name),
                          KEY id (id','folder),
                          KEY folder (folder','id)
    );
$this->tables['links_regions'] = new AA_Metabase_Table('links_regions',
    array(
                          id int(10) unsigned NOT NULL default '0',
        array('name', 'varchar', 60, 'NOT NULL', ''),
        array('level', 'tinyint', 4, 'NOT NULL', '1'),
        ),
    array('id'),
                          KEY name (name)
    );
$this->tables['log'] = new AA_Metabase_Table('log',
    array(
        array('id', 'int', 11, 'NOT NULL', 'auto_increment'),
        array('time', 'bigint', 20, 'NOT NULL', '0'),
        array('user', 'varchar', 60, 'NOT NULL', ''),
        array('type', 'varchar', 10, 'NOT NULL', ''),
        array('selector', 'varchar', 255, '', 'NULL'),
        array('params', 'varchar', 128, '', 'NULL'),
        ),
    array('id'),
    array('time'=>array(array('time')))
    );
$this->tables['membership'] = new AA_Metabase_Table('membership',
    array(
        array('groupid', 'int', 11, 'NOT NULL', '0'),
        array('memberid', 'varbinary', 32, 'NOT NULL', '0'),
        array('last_mod', 'timestamp', 14, 'NOT NULL', ''),
        ),
    array('groupid','memberid'),
    array('memberid'=>array(array('memberid')))
    );
$this->tables['module'] = new AA_Metabase_Table('module',
    array(
        array('id', 'varbinary', 16, 'NOT NULL', ''),
        array('name', 'char', 100, 'NOT NULL', ''),
        array('deleted', 'smallint', 5, '', 'NULL'),
        array('type', 'char', 16, '', ''S''),
        array('slice_url', 'char', 255, '', 'NULL'),
        array('lang_file', 'char', 50, '', 'NULL'),
        array('created_at', 'bigint', 20, 'NOT NULL', '0'),
        array('created_by', 'char', 255, 'NOT NULL', ''),
        array('owner', 'varbinary', 16, 'NOT NULL', ''),
        array('app_id', 'varbinary', 16, '', 'NULL'),
        array('priority', 'smallint', 6, 'NOT NULL', '0'),
        array('flag', 'int', 11, '', '0'),
        ),
    array('id')
    );
$this->tables['mysql_auth_group'] = new AA_Metabase_Table('mysql_auth_group',
    array(
        array('slice_id', 'varbinary', 16, 'NOT NULL', ''),
        array('groupparent', 'varchar', 30, 'NOT NULL', ''),
        array('groups', 'varchar', 30, 'NOT NULL', ''),
    );
$this->tables['mysql_auth_user'] = new AA_Metabase_Table('mysql_auth_user',
    array(
        array('uid', 'int', 10, 'NOT NULL', '0'),
        array('username', 'char', 30, 'NOT NULL', ''),
        array('passwd', 'char', 30, 'NOT NULL', '')
        ),
    array('uid'),
    array('username' => array(array('username'),'UNIQUE'))
    );
$this->tables['mysql_auth_user_group'] = new AA_Metabase_Table('mysql_auth_user_group',
    array(
        array('username', 'char', 30, 'NOT NULL', ''),
        array('groups', 'char', 30, 'NOT NULL', '')
        ),
    array('username','groups')
    );
$this->tables['mysql_auth_userinfo'] = new AA_Metabase_Table('mysql_auth_userinfo',
    array(
        array('slice_id', 'varbinary', 16, 'NOT NULL', ''),
        array('uid', 'int', 10, 'NOT NULL', 'auto_increment'),
        array('first_name', 'varchar', 20, '', 'NULL'),
        array('last_name', 'varchar', 30, '', 'NULL'),
        array('organisation', 'varchar', 50, '', 'NULL'),
        array('start_date', 'bigint', 20, '', 'NULL'),
        array('renewal_date', 'bigint', 20, '', 'NULL'),
        array('email', 'varchar', 50, '', ''''),
        array('membership_type', 'varchar', 50, '', 'NULL'),
        array('status_code', 'smallint', 5, '', ''2''),
        array('todo', 'varchar', 250, '', 'NULL')
        ),
    array('uid')
    );
$this->tables['mysql_auth_userlog'] = new AA_Metabase_Table('mysql_auth_userlog',
    array(
        array('uid', 'int', 10, 'NOT NULL', '0'),
        array('time', 'int', 10, 'NOT NULL', '0'),
        array('from_bin', 'smallint', 6, 'NOT NULL', '0'),
        array('to_bin', 'smallint', 6, 'NOT NULL', '0'),
        array('organisation', 'varchar', 50, '', 'NULL'),
        array('membership_type', 'varchar', 50, '', 'NULL')
        ),
    );
$this->tables['nodes'] = new AA_Metabase_Table('nodes',
    array(
        array('name', 'varchar', 150, 'NOT NULL', ''),
        array('server_url', 'varchar', 200, 'NOT NULL', ''),
        array('password', 'varchar', 50, 'NOT NULL', '')
        ),
    array('name')
    );
$this->tables['offline'] = new AA_Metabase_Table('offline',
    array(
        array('id', 'varbinary', 16, 'NOT NULL', ''),
        array('digest', 'varbinary', 32, 'NOT NULL', ''),
        array('flag', 'int', 11, '', 'NULL')
        ),
    array('id'),
    array('digest' => array(array('digest')))
    );
$this->tables['object_float'] = new AA_Metabase_Table('object_float',
    array(
        array('id', 'bigint', 20, 'NOT NULL', 'auto_increment'),
        array('object_id', 'varbinary', 32, 'NOT NULL', ''),
        array('property', 'varbinary', 16, 'NOT NULL', ''),
        array('priority', 'smallint', 20, '', 'NULL'),
        array('float', 'double', '', '', 'NULL'),
        array('flag', 'smallint', 6, '', 'NULL')
        ),
    array('id'),
    array('item_id' => array(array('object_id','property', 'float')),
          'float' => array(array('float'))
         )
      );
$this->tables['object_integer'] = new AA_Metabase_Table('object_integer',
    array(
        array('id', 'bigint', 20, 'NOT NULL', 'auto_increment'),
        array('object_id', 'varbinary', 32, 'NOT NULL', ''),
        array('property', 'varbinary', 16, 'NOT NULL', ''),
        array('priority', 'smallint', 20, '', 'NULL'),
        array('integer', 'bigint', 20, '', 'NULL'),
        array('flag', 'smallint', 6, '', 'NULL'),
        ),
    array('id'),
    array('item_id' => array(array('object_id','property', 'integer')),
          'integer' => array(array('integer'))
         )
      );
$this->tables['object_text'] = new AA_Metabase_Table('object_text',
    array(
        array('id', 'bigint', 20, 'NOT NULL', 'auto_increment'),
        array('object_id', 'varbinary', 32, 'NOT NULL', ''),
        array('property', 'varbinary', 16, 'NOT NULL', ''),
        array('priority', 'smallint', 20, '', 'NULL'),
        array('text', 'longtext', '', '', ''),
        array('flag', 'smallint', 6, '', 'NULL')
        ),
    array('id'),
    array('item_id' => array(array('object_id','property', array('text', 16))),
          'text' => array(array(array('text',16)))
         )
      );
$this->tables['pagecache'] = new AA_Metabase_Table('pagecache',
    array(
        array('id', 'varbinary', 32, 'NOT NULL', ''),
        array('content', 'longtext', '', '', ''),
        array('stored', 'bigint', 20, 'NOT NULL', '0'),
        array('flag', 'int', 11, '', 'NULL')
        ),
    array('id'),
    array('stored' => array(array('stored')))
    );
$this->tables['pagecache_str2find'] = new AA_Metabase_Table('pagecache_str2find',
    array(
        array('id', 'bigint', 20, 'NOT NULL', 'auto_increment'),
        array('pagecache_id', 'varbinary', 32, 'NOT NULL', ''),
        array('str2find', 'text', '', 'NOT NULL', '')
        ),
    array('id'),
    array('pagecache_id' => array(array('pagecache_id')),
          'str2find' => array(array(array('str2find',20)))
         )
    );
$this->tables['perms'] = new AA_Metabase_Table('perms',
    array(
        array('object_type', 'char', 30, 'NOT NULL', ''),
        array('objectid', 'varbinary', 32, 'NOT NULL', ''),
        array('userid', 'varbinary', 32, 'NOT NULL', '0'),
        array('perm', 'varbinary', 32, 'NOT NULL', ''),
        array('last_mod', 'timestamp', 14, 'NOT NULL', ''),
        ),
    array('objectid','userid','object_type'),
    array('userid' => array(array('userid')))
    );
$this->tables['polls'] = new AA_Metabase_Table('polls',
    array(
        array('id', 'varbinary', 16, 'NOT NULL', ''),
        array('pollID', 'int', 11, 'NOT NULL', 'auto_increment'),
        array('status_code', 'tinyint', 4, 'NOT NULL', '1'),
        array('pollTitle', 'varchar', 100, 'NOT NULL', ''),
        array('startDate', 'int', 11, 'NOT NULL', '0'),
        array('endDate', 'int', 11, 'NOT NULL', '0'),
        array('defaults', 'tinyint', 1, '', 'NULL'),
        array('Logging', 'tinyint', 1, '', 'NULL'),
        array('IPLocking', 'tinyint', 1, '', 'NULL'),
        array('IPLockTimeout', 'int', 4, '', 'NULL'),
        array('setCookies', 'tinyint', 1, '', 'NULL'),
        array('cookiesPrefix', 'varchar', 16, '', 'NULL'),
        array('designID', 'int', 11, '', 'NULL'),
        array('params', 'text', '', 'NOT NULL', ''),
        ),
    array('pollID')
    );
$this->tables['polls_data'] = new AA_Metabase_Table('polls_data',
    array(
        array('pollID', 'int', 11, 'NOT NULL', '0'),
        array('optionText', 'char', 50, 'NOT NULL', ''),
        array('optionCount', 'int', 11, 'NOT NULL', '0'),
        array('voteID', 'int', 11, 'NOT NULL', '0'),
    );
$this->tables['polls_designs'] = new AA_Metabase_Table('polls_designs',
    array(
        array('designID', 'int', 11, 'NOT NULL', 'auto_increment'),
        array('pollsModuleID', 'varbinary', 16, 'NOT NULL', ''),
        array('name', 'text', '', 'NOT NULL', ''),
        array('comment', 'text', '', 'NOT NULL', ''),
        array('resultBarFile', 'text', '', 'NOT NULL', ''),
        array('resultBarWidth', 'int', 4, 'NOT NULL', '0'),
        array('resultBarHeight', 'int', 4, 'NOT NULL', '0'),
        array('top', 'text', '', 'NOT NULL', ''),
        array('answer', 'text', '', 'NOT NULL', ''),
        array('bottom', 'text', '', 'NOT NULL', ''),
        array('params', 'text', '', 'NOT NULL', ''),
        ),
    array('designID')
    );
$this->tables['polls_ip_lock'] = new AA_Metabase_Table('polls_ip_lock',
    array(
        array('pollID', 'int', 11, 'NOT NULL', '0'),
        array('voteID', 'int', 11, 'NOT NULL', '0'),
        array('votersIP', 'char', 16, 'NOT NULL', ''),
        array('timeStamp', 'int', 11, 'NOT NULL', '0'),
    );
$this->tables['polls_log'] = new AA_Metabase_Table('polls_log',
    array(
        array('logID', 'int', 11, 'NOT NULL', 'auto_increment'),
        array('pollID', 'int', 11, 'NOT NULL', '0'),
        array('voteID', 'int', 11, 'NOT NULL', '0'),
        array('votersIP', 'varbinary', 16, 'NOT NULL', ''),
        array('timeStamp', 'int', 11, 'NOT NULL', '0'),
        ),
    array('logID')
    );
$this->tables['post2shtml'] = new AA_Metabase_Table('post2shtml',
    array(
        array('id', 'varbinary', 32, 'NOT NULL', ''),
        array('vars', 'text', '', 'NOT NULL', ''),
        array('time', 'int', 11, 'NOT NULL', '0'),
        ),
    array('id')
    );
$this->tables['profile'] = new AA_Metabase_Table('profile',
    array(
        array('id', 'int', 11, 'NOT NULL', 'auto_increment'),
        array('slice_id', 'varbinary', 16, 'NOT NULL', ''),
        array('uid', 'varchar', 60, 'NOT NULL', '*'),
        array('property', 'varchar', 20, 'NOT NULL', ''),
        array('selector', 'varchar', 255, '', 'NULL'),
        array('value', 'text', '', '', ''),
        ),
    array('id'),
    array('slice_user_id' => array(array('slice_id', 'uid')))
    );
$this->tables['relation'] = new AA_Metabase_Table('relation',
    array(
        array('source_id', 'varbinary', 16, 'NOT NULL', ''),
        array('destination_id', 'varbinary', 32, 'NOT NULL', ''),
        array('flag', 'int', 11, '', 'NULL')
        ),
    array(),
    array('source_id' => array(array('source_id')),
          'destination_id' => array(array(array('destination_id',20)))
         )
    );
$this->tables['rssfeeds'] = new AA_Metabase_Table('rssfeeds',
    array(
        array('feed_id', 'int', 11, 'NOT NULL', 'auto_increment'),
        array('name', 'varchar', 150, 'NOT NULL', ''),
        array('server_url', 'varchar', 200, 'NOT NULL', ''),
        array('slice_id', 'varbinary', 16, 'NOT NULL', ''),
        ),
    array('feed_id')
    );
$this->tables['searchlog'] = new AA_Metabase_Table('searchlog',
    array(
        array('id', 'int', 11, 'NOT NULL', 'auto_increment'),
        array('date', 'int', 14, '', 'NULL'),
        array('query', 'text', '', '', ''),
        array('found_count', 'int', 11, '', 'NULL'),
        array('search_time', 'int', 11, '', 'NULL'),
        array('user', 'text', '', '', ''),
        array('additional1', 'text', '', '', ''),
        ),
    array('id'),
    array('date' => array(array('date')))
    );
$this->tables['site'] = new AA_Metabase_Table('site',
    array(
        array('id', 'varbinary', 16, 'NOT NULL', ''),
        array('state_file', 'varchar', 255, 'NOT NULL', ''),
        array('structure', 'longtext', '', '', ''),
        array('flag', 'int', 11, '', 'NULL'),
        ),
    array('id')
    );
$this->tables['site_spot'] = new AA_Metabase_Table('site_spot',
    array(
        array('id', 'int', 11, 'NOT NULL', 'auto_increment'),
        array('spot_id', 'int', 11, 'NOT NULL', '0'),
        array('site_id', 'varbinary', 16, 'NOT NULL', ''),
        array('content', 'longtext', '', 'NOT NULL', ''),
        array('flag', 'bigint', 20, '', 'NULL'),
        ),
    array('id'),
    array('spot' => array(array('site_id','spot_id')))
    );
$this->tables['slice'] = new AA_Metabase_Table('slice',
    array(
        array('id', 'varbinary', 16, 'NOT NULL', ''),
        array('name', 'varchar', 100, 'NOT NULL', ''),
        array('owner', 'varchar', 16, '', 'NULL'),
        array('deleted', 'smallint', 5, '', 'NULL'),
        array('created_by', 'varchar', 255, '', 'NULL'),
        array('created_at', 'bigint', 20, '', 'NULL'),
        array('export_to_all', 'smallint', 5, '', 'NULL'),
        array('type', 'varbinary', 16, '', 'NULL'),
        array('template', 'smallint', 5, '', 'NULL'),
        array('fulltext_format_top', 'longtext', '', '', ''),
        array('fulltext_format', 'longtext', '', '', ''),
        array('fulltext_format_bottom', 'longtext', '', '', ''),
        array('odd_row_format', 'longtext', '', '', ''),
        array('even_row_format', 'longtext', '', '', ''),
        array('even_odd_differ', 'smallint', 5, '', 'NULL'),
        array('compact_top', 'longtext', '', '', ''),
        array('compact_bottom', 'longtext', '', '', ''),
        array('category_top', 'longtext', '', '', ''),
        array('category_format', 'longtext', '', '', ''),
        array('category_bottom', 'longtext', '', '', ''),
        array('category_sort', 'smallint', 5, '', 'NULL'),
        array('slice_url', 'varchar', 255, '', 'NULL'),
        array('d_listlen', 'smallint', 5, '', 'NULL'),
        array('lang_file', 'varchar', 50, '', 'NULL'),
        array('fulltext_remove', 'longtext', '', '', ''),
        array('compact_remove', 'longtext', '', '', ''),
        array('email_sub_enable', 'smallint', 5, '', 'NULL'),
        array('exclude_from_dir', 'smallint', 5, '', 'NULL'),
        array('notify_sh_offer', 'longtext', '', '', ''),
        array('notify_sh_accept', 'longtext', '', '', ''),
        array('notify_sh_remove', 'longtext', '', '', ''),
        array('notify_holding_item_s', 'longtext', '', '', ''),
        array('notify_holding_item_b', 'longtext', '', '', ''),
        array('notify_holding_item_edit_s', 'longtext', '', '', ''),
        array('notify_holding_item_edit_b', 'longtext', '', '', ''),
        array('notify_active_item_edit_s', 'longtext', '', '', ''),
        array('notify_active_item_edit_b', 'longtext', '', '', ''),
        array('notify_active_item_s', 'longtext', '', '', ''),
        array('notify_active_item_b', 'longtext', '', '', ''),
        array('noitem_msg', 'longtext', '', '', ''),
        array('admin_format_top', 'longtext', '', '', ''),
        array('admin_format', 'longtext', '', '', ''),
        array('admin_format_bottom', 'longtext', '', '', ''),
        array('admin_remove', 'longtext', '', '', ''),
        array('admin_noitem_msg', 'longtext', '', '', ''),
        array('permit_anonymous_post', 'smallint', 5, '', 'NULL'),
        array('permit_anonymous_edit', 'smallint', 5, '', 'NULL'),
        array('permit_offline_fill', 'smallint', 5, '', 'NULL'),
        array('aditional', 'longtext', '', '', ''),
        array('flag', 'int', 11, 'NOT NULL', '0'),
        array('vid', 'int', 11, '', '0'),
        array('gb_direction', 'tinyint', 4, '', 'NULL'),
        array('group_by', 'varchar', 16, '', 'NULL'),
        array('gb_header', 'tinyint', 4, '', 'NULL'),
        array('gb_case', 'varchar', 15, '', 'NULL'),
        array('javascript', 'longtext', '', '', ''),
        array('fileman_access', 'varchar', 20, '', 'NULL'),
        array('fileman_dir', 'varchar', 50, '', 'NULL'),
        array('auth_field_group', 'varchar', 16, 'NOT NULL', ''),
        array('mailman_field_lists', 'varchar', 16, 'NOT NULL', ''),
        array('reading_password', 'varchar', 100, 'NOT NULL', ''),
        array('mlxctrl', 'varbinary', 32, 'NOT NULL', ''),
        ),
    array('id'),
    array('type' => array(array('type')))
    );
$this->tables['slice_owner'] = new AA_Metabase_Table('slice_owner',
    array(
        array('id', 'varbinary', 16, 'NOT NULL', ''),
        array('name', 'char', 80, 'NOT NULL', ''),
        array('email', 'char', 80, 'NOT NULL', ''),
        ),
    array('id')
    );
$this->tables['subscriptions'] = new AA_Metabase_Table('subscriptions',
    array(
        array('uid', 'char', 50, 'NOT NULL', ''),
        array('category', 'char', 16, '', 'NULL'),
        array('content_type', 'char', 16, '', 'NULL'),
        array('slice_owner', 'varbinary', 16, '', 'NULL'),
        array('frequency', 'smallint', 5, 'NOT NULL', '0'),
        array('last_post', 'bigint', 20, 'NOT NULL', '0')
        ),
    array(),
    array('uid' => array(array('uid','frequency')))
    );
$this->tables['toexecute'] = new AA_Metabase_Table('toexecute',
    array(
        array('id', 'int', 11, 'NOT NULL', 'auto_increment'),
        array('created', 'bigint', 20, 'NOT NULL', '0'),
        array('execute_after', 'bigint', 20, 'NOT NULL', '0'),
        array('aa_user', 'varchar', 60, 'NOT NULL', ''),
        array('priority', 'int', 11, 'NOT NULL', '0'),
        array('selector', 'varchar', 255, 'NOT NULL', ''),
        array('object', 'longtext', '', 'NOT NULL', ''),
        array('params', 'longtext', '', 'NOT NULL', ''),
        ),
    array('id'),
    array('time' => array(array('execute_after','priority')),
          'priority' => array(array('priority')),
          'selector' => array(array('selector'))
         )
    );
$this->tables['users'] = new AA_Metabase_Table('users',
    array(
        array('id', 'int', 11, 'NOT NULL', 'auto_increment'),
        array('type', 'varbinary', 10, 'NOT NULL', ''),
        array('password', 'varbinary', 30, 'NOT NULL', ''),
        array('uid', 'varbinary', 40, 'NOT NULL', ''),
        array('mail', 'char', 40, 'NOT NULL', ''),
        array('name', 'char', 80, 'NOT NULL', ''),
        array('description', 'char', 255, 'NOT NULL', ''),
        array('givenname', 'char', 40, 'NOT NULL', ''),
        array('sn', 'char', 40, 'NOT NULL', ''),
        array('last_mod', 'timestamp', 14, 'NOT NULL', ''),
        ),
    array('id'),
    array('type' => array(array('type')),
          'mail' => array(array('mail')),
          'name' => array(array('name')),
          'sn' => array(array('sn'))
    );
$this->tables['view'] = new AA_Metabase_Table('view',
    array(
        array('id', 'unsigned int', 10, 'NOT NULL', 'auto_increment'),
        array('slice_id', 'varbinary', 16, 'NOT NULL', ''),
        array('name', 'varchar', 50, '', 'NULL'),
        array('type', 'varchar', 10, '', 'NULL'),
        array('before', 'longtext', '', '', ''),
        array('even', 'longtext', '', '', ''),
        array('odd', 'longtext', '', '', ''),
        array('even_odd_differ', 'unsigned tinyint', 3, '', 'NULL'),
        array('row_delimiter', 'longtext', '', '', ''),
        array('after', 'longtext', '', '', ''),
        array('remove_string', 'longtext', '', '', ''),
        array('group_title', 'longtext', '', '', ''),
        array('order1', 'varbinary', 16, '', 'NULL'),
        array('o1_direction', 'unsigned tinyint', 3, '', 'NULL'),
        array('order2', 'varbinary', 16, '', 'NULL'),
        array('o2_direction', 'unsigned tinyint', 3, '', 'NULL'),
        array('group_by1', 'varbinary', 16, '', 'NULL'),
        array('g1_direction', 'unsigned tinyint', 3, '', 'NULL'),
        array('group_by2', 'varbinary', 16, '', 'NULL'),
        array('g2_direction', 'unsigned tinyint', 3, '', 'NULL'),
        array('cond1field', 'varbinary', 16, '', 'NULL'),
        array('cond1op', 'varbinary', 10, '', 'NULL'),
        array('cond1cond', 'varchar', 255, '', 'NULL'),
        array('cond2field', 'varbinary', 16, '', 'NULL'),
        array('cond2op', 'varbinary', 10, '', 'NULL'),
        array('cond2cond', 'varchar', 255, '', 'NULL'),
        array('cond3field', 'varbinary', 16, '', 'NULL'),
        array('cond3op', 'varbinary', 10, '', 'NULL'),
        array('cond3cond', 'varchar', 255, '', 'NULL'),
        array('listlen', 'unsigned int', 10, '', 'NULL'),
        array('scroller', 'unsigned tinyint', 3, '', 'NULL'),
        array('selected_item', 'unsigned tinyint', 3, '', 'NULL'),
        array('modification', 'unsigned int', 10, '', 'NULL'),
        array('parameter', 'varchar', 255, '', 'NULL'),
        array('img1', 'varchar', 255, '', 'NULL'),
        array('img2', 'varchar', 255, '', 'NULL'),
        array('img3', 'varchar', 255, '', 'NULL'),
        array('img4', 'varchar', 255, '', 'NULL'),
        array('flag', 'unsigned int', 10, '', 'NULL'),
        array('aditional', 'longtext', '', '', ''),
        array('aditional2', 'longtext', '', '', ''),
        array('aditional3', 'longtext', '', '', ''),
        array('aditional4', 'longtext', '', '', ''),
        array('aditional5', 'longtext', '', '', ''),
        array('aditional6', 'longtext', '', '', ''),
        array('noitem_msg', 'longtext', '', '', ''),
        array('group_bottom', 'longtext', '', '', ''),
        array('field1', 'varbinary', 16, '', 'NULL'),
        array('field2', 'varbinary', 16, '', 'NULL'),
        array('field3', 'varbinary', 16, '', 'NULL'),
        array('calendar_type', 'varchar', 100, '', ''mon'')
    array('id'),
    array('slice_id' => array(array('slice_id')))
    );
$this->tables['wizard_template'] = new AA_Metabase_Table('wizard_template',
    array(
        array('id', 'tinyint', 10, 'NOT NULL', 'auto_increment'),
        array('dir', 'char', 100, 'NOT NULL', ''),
        array('description', 'char', 255, 'NOT NULL', ''),
    array('id'),
    array('dir' => array(array('dir'),'UNIQUE'))
    );
$this->tables['wizard_welcome'] = new AA_Metabase_Table('wizard_welcome',
    array(
        array('id', 'int', 11, 'NOT NULL', 'auto_increment'),
        array('description', 'varchar', 200, 'NOT NULL', ''),
        array('email', 'longtext', '', '', ''),
        array('subject', 'varchar', 255, 'NOT NULL', ''),
        array('mail_from', 'varchar', 255, 'NOT NULL', '_#ME_MAIL_'),
    array('id')
    );


?>
