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

if (!defined("ALERTS_CONSTANTS_INCLUDED"))
    define("ALERTS_CONSTANTS_INCLUDED",1);
else return;
 
#
# Used constants. Do not edit if you are not developer.
#

  # There we can mention $FIELD_TYPES, but they are not defined in this file, 
  # but in database as special slice with id 'AA_Core_Fields..'
  
  # Field types - each field in slice is one of this type. 
  # The types are defined APC wide for easy item interchanging between APC nodes
  # (on the other hand, new type can be added just by placing new fileld 
  # in database table fields as for 'AA_Core_Fields..' slice).

// in the array MODULES "name" is a description of the module, "hide_create_module" doesn't show the module in the Create Slice / Module page

$MODULES = array( 'S' => array( 'table' => 'slice',
                                'name' => 'Slice',
                                'hide_create_module' => 1,
                                'directory' => $AA_INSTAL_PATH ."admin/"),
                  'W' => array( 'table' => 'site',
                                'name' => 'Site',
                                'directory' => $AA_INSTAL_PATH ."modules/site/"),
                  'A' => array( 'table' => 'module', # this module doesn't have any special info yet
                                'name' => 'MySQL Auth',
                                'hide_create_module' => 1,
                                'directory' => $AA_INSTAL_PATH ."modules/mysql_auth/"),
                  'J' => array( 'table' => 'jump',
                                'name' => 'Jump inside AA control panel',
                                'directory' => $AA_INSTAL_PATH ."modules/jump/"));
                  
# language files for slices (not for some modules, e.g. site)
$LANGUAGE_FILES = array( "en_news_lang.php3" => "en_news_lang.php3",
                         "es_news_lang.php3" => "es_news_lang.php3",
                         "cz_news_lang.php3" => "cz_news_lang.php3",
                         "sk_news_lang.php3" => "sk_news_lang.php3",
                         "de_news_lang.php3" => "de_news_lang.php3",
                         "ro_news_lang.php3" => "ro_news_lang.php3",
                         "ja_news_lang.php3" => "ja_news_lang.php3");

$LANGUAGE_CHARSETS = array ("cz" => "windows-1250",
                            "en" => "iso-8859-1",
                            "es" => "iso-8859-1",
                            "de" => "iso-8859-1",
                            "ro" => "iso-8859-2",
                            "sk" => "windows-1250",
                            "ja" => "EUC-JP");
                            
$LANGUAGE_NAMES = array ("cz" => "Èeština",
                         "en" => "English",
                         "es" => "Espanol",
                         "de" => "Deutsch",
                         "ro" => "Romanian",
                         "sk" => "Slovenština",
                         "ja" => "Japanian");
                            
                         
# MAX_NO_OF_ITEMS_4_GROUP is used with group_n slice.php3 parameter and 
# specifies how many items from the begining we have to search
define( 'MAX_NO_OF_ITEMS_4_GROUP', 1000 );
  
$SLICE_FIELDS_TEXT = array("id", "name", "owner", "created_by", "created_at",
   "type", "fulltext_format_top", "fulltext_format", "fulltext_format_bottom",
   "odd_row_format", "even_row_format", "compact_top", "compact_bottom",
   "category_top", "category_format", "category_bottom", "config", "slice_url",
   "lang_file", "fulltext_remove", "compact_remove", "notify_sh_offer", 
   "notify_sh_accept", "notify_sh_remove", "notify_holding_item_s", 
   "notify_holding_item_b", "notify_holding_item_edit_s", 
   "notify_holding_item_edit_b", "notify_active_item_edit_s", 
   "notify_active_item_edit_b", "notify_active_item_s", "notify_active_item_b",
   "noitem_msg", 
   "admin_format_top", "admin_format", "admin_format_bottom", "admin_remove",
   "fileman_dir","fileman_access","javascript","aditional");

$SLICE_FIELDS_NUM  = array( "deleted", "export_to_all", "template", 
   "even_odd_differ", "category_sort", "d_expiry_limit", "d_listlen",  
   "email_sub_enable", "exclude_from_dir","permit_anonymous_post","permit_offline_fill",);

$FIELD_FIELDS_TEXT = array(  "id", "type", "slice_id", "name", 
  "input_help", "input_morehlp", "input_default",
  "input_show_func", "content_id", "search_type", "search_help",
  "search_before", "search_more_help", "alias1", "alias1_func", "alias1_help",
  "alias2", "alias2_func", "alias2_help", "alias3","alias3_func", "alias3_help", 
  "input_before", "aditional", "input_validate", "input_insert_func", "in_item_tbl"); 

$FIELD_FIELDS_NUM = array( "input_pri", "required", "feed", "multiple",
  "search_pri", "search_show", "search_ft_show", "search_ft_default",
  "content_edit", "html_default", "html_show", "input_show", "text_stored"); 

  // array of default function description
$INPUT_DEFAULT_TYPES = array ("txt" => L_INPUT_DEFAULT_TXT,
                              "dte" => L_INPUT_DEFAULT_DTE, 
                              "uid" => L_INPUT_DEFAULT_UID,
                              "log" => L_INPUT_DEFAULT_LOG,
                              "now" => L_INPUT_DEFAULT_NOW,
			      "variable" =>L_INPUT_DEFAULT_VAR);

$INPUT_SHOW_FUNC_TYPES = array (
    "txt" => array( 'name' => L_INPUT_SHOW_TXT, #textarea
                    'multiple' => false,
                    'paramformat' => 'fnc:param' ),
    "tpr" => array( 'name' => L_INPUT_SHOW_TPR, #textarea with preset								
                    'multiple' => false,
                    'paramformat' => 'fnc:const:param' ),
    "edt" => array( 'name' => L_INPUT_SHOW_EDT, #rich text edit
                    'multiple' => false,
                    'paramformat' => 'fnc:param' ),
    "fld" => array( 'name' => L_INPUT_SHOW_FLD, #textfield
                    'multiple' => false,
                    'paramformat' => 'fnc:param' ),
    "sel" => array( 'name' => L_INPUT_SHOW_SEL, #selectbox
                    'multiple' => false,
                    'paramformat' => 'fnc:const:param' ),
    "pre" => array( 'name' => L_INPUT_SHOW_PRE, #selectbox with preset
                    'multiple' => false,
                    'paramformat' => 'fnc:const:param' ),
    "rio" => array( 'name' => L_INPUT_SHOW_RIO, #radio button
                    'multiple' => false,
                    'paramformat' => 'fnc:const:param' ),
    "dte" => array( 'name' => L_INPUT_SHOW_DTE, #date
                    'multiple' => false,
                    'paramformat' => 'fnc:param' ),
    "chb" => array( 'name' => L_INPUT_SHOW_CHB, #check box
                    'multiple' => false,
                    'paramformat' => 'fnc' ),
    "mch" => array( 'name' => L_INPUT_SHOW_MCH, #multiple checkbox
                    'multiple' => true,
                    'paramformat' => 'fnc:const:param' ),
    "mse" => array( 'name' => L_INPUT_SHOW_MSE, #multiple selectbox
                    'multiple' => true,
                    'paramformat' => 'fnc:const:param' ),
  # "wi2" => array( 'name' => L_INPUT_SHOW_WI2, #2 windows
  #                 'multiple' => true,
  #                 'paramformat' => 'fnc:const:param' ),
    "fil" => array( 'name' => L_INPUT_SHOW_FIL, #file
                    'multiple' => false,
                    'paramformat' => 'fnc:param' ),
  # "isi" => array( 'name' => L_INPUT_SHOW_ISI, #
  #                 'multiple' => true,
  #                 'paramformat' => 'fnc:const:param' ),
    "iso" => array( 'name' => L_INPUT_SHOW_ISO, #related items selectbox - outer
                    'multiple' => true,
                    'paramformat' => 'fnc:const:param' ),
    "nul" => array( 'name' => L_INPUT_SHOW_NUL, #
                    'multiple' => false,
                    'paramformat' => 'fnc' ),
    "hco" => array( 'name' => L_INPUT_SHOW_HCO, #hierarchy constant
                    'multiple' => false,
                    'paramformat' => 'fnc:const:param' ));
                              
$INPUT_VALIDATE_TYPES = array ("text" => L_INPUT_VALIDATE_TEXT,
                               "url" => L_INPUT_VALIDATE_URL, 
                               "e-mail" => L_INPUT_VALIDATE_EMAIL, 
                               "number" => L_INPUT_VALIDATE_NUMBER, 
                               "id" => L_INPUT_VALIDATE_ID, 
                               "date" => L_INPUT_VALIDATE_DATE, 
                               "bool" => L_INPUT_VALIDATE_BOOL,
			                         "user" => L_INPUT_VALIDATE_USER);	//added 03/01/02,setu

$INPUT_INSERT_TYPES = array ("qte" => L_INPUT_INSERT_QTE,
                             "boo" => L_INPUT_INSERT_BOO,
                             "fil" => L_INPUT_INSERT_FIL,
                             "uid" => L_INPUT_INSERT_UID, 
                             "log" => L_INPUT_INSERT_LOG, 
                             "ids" => L_INPUT_INSERT_IDS, 
                             "now" => L_INPUT_INSERT_NOW
                           /*"dte" => L_INPUT_INSERT_DTE, 
                             "cns" => L_INPUT_INSERT_CNS, 
                             "num" => L_INPUT_INSERT_NUM, 
                             "nul" => L_INPUT_INSERT_NUL*/);
                             
$LOG_EVENTS = array ( "0"   => LOG_EVENTS_UNDEFINED,
                      "1"   => LOG_EVENTS_,
                      "2"   => LOG_EVENTS_,
                      "3"   => LOG_EVENTS_,
                      "4"   => LOG_EVENTS_,
                      "5"   => LOG_EVENTS_,
                      "6"   => LOG_EVENTS_,
                      "7"   => LOG_EVENTS_,
                      "8"   => LOG_EVENTS_);

# content table flags
define( "FLAG_HTML", 1 );      # content is in HTML
define( "FLAG_FEED", 2 );      # item is fed
define( "FLAG_FREEZE", 4 );    # content can't be changed
define( "FLAG_OFFLINE", 8 );   # off-line filled
define( "FLAG_UPDATE", 16 );   # content should be updated if source is changed
                               #   (after feeding)
                               
# item table flags (numbers - just to be compatible with content table)
define( "ITEM_FLAG_FEED", 2 );      # item is fed
define( "ITEM_FLAG_OFFLINE", 8 );   # off-line filled or imported from file
define( "ITEM_FLAG_ANONYMOUS_EDITABLE", 32); # anonymously added and thus anonymously editable (reset on every use of itemedit.php3)

# states of feed field of field table
define( "STATE_FEEDABLE", 0 );
define( "STATE_UNFEEDABLE", 1 );
define( "STATE_FEEDNOCHANGE", 2 );
define( "STATE_FEEDABLE_UPDATE",3);
define( "STATE_FEEDABLE_UPDATE_LOCKED",4);

# relation table flags
define( "REL_FLAG_FEED", 2 );    # 2 - just to be compatible with content table

$INPUT_FEED_MODES = array ( STATE_FEEDABLE => L_STATE_FEEDABLE,
                            STATE_UNFEEDABLE => L_STATE_UNFEEDABLE,
                            STATE_FEEDNOCHANGE => L_STATE_FEEDNOCHANGE,
                            STATE_FEEDABLE_UPDATE => L_STATE_FEEDABLE_UPDATE,
                            STATE_FEEDABLE_UPDATE_LOCKED => L_STATE_FEEDABLE_UPDATE_LOCKED
                          );

# se_views.php3 - view field definition
/* Jakub added a special field "function:function_name" which calls function show_function_name() to show a special form part and store_function_name() to store form data. */

$VIEW_FIELDS["name"]            = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"field" );
$VIEW_FIELDS["before"]          = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"area" );
$VIEW_FIELDS["even"]            = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"area" );
$VIEW_FIELDS["even_odd_differ"] = array( "validate"=>"", "insert"=>"quoted", "type"=>"bool", "input"=>"chbox" );
$VIEW_FIELDS["odd"]             = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"area" );
$VIEW_FIELDS["after"]           = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"area" );
$VIEW_FIELDS["group_by1"]       = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"order" );
$VIEW_FIELDS["g1_direction"]    = array( "validate"=>"", "insert"=>"quoted", "type"=>"number", "input"=>"none" );
$VIEW_FIELDS["group_by2"]       = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"order" );
$VIEW_FIELDS["g2_direction"]    = array( "validate"=>"", "insert"=>"quoted", "type"=>"number", "input"=>"none" );
$VIEW_FIELDS["group_title"]     = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"area" );
$VIEW_FIELDS["group_bottom"]    = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"area" );
$VIEW_FIELDS["remove_string"]   = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"area" );
$VIEW_FIELDS["modification"]    = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"seltype" );
$VIEW_FIELDS["parameter"]       = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"selgrp" );
$VIEW_FIELDS["img1"]            = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"field" );
$VIEW_FIELDS["img2"]            = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"field" );
$VIEW_FIELDS["img3"]            = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"field" );
$VIEW_FIELDS["img4"]            = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"field" );
$VIEW_FIELDS["order1"]          = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"order" );
$VIEW_FIELDS["o1_direction"]    = array( "validate"=>"", "insert"=>"quoted", "type"=>"number", "input"=>"none" );
$VIEW_FIELDS["order2"]          = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"order" );
$VIEW_FIELDS["o2_direction"]    = array( "validate"=>"", "insert"=>"quoted", "type"=>"number", "input"=>"none" );
$VIEW_FIELDS["selected_item"]   = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"area" );
$VIEW_FIELDS["cond1field"]      = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"cond" );
$VIEW_FIELDS["cond1op"]         = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"none" );
$VIEW_FIELDS["cond1cond"]       = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"none" );
$VIEW_FIELDS["cond2field"]      = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"cond" );
$VIEW_FIELDS["cond2op"]         = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"none" );
$VIEW_FIELDS["cond2cond"]       = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"none" );
$VIEW_FIELDS["cond3field"]      = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"cond" );
$VIEW_FIELDS["cond3op"]         = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"none" );
$VIEW_FIELDS["cond3cond"]       = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"none" );
$VIEW_FIELDS["listlen"]         = array( "validate"=>"number", "insert"=>"quoted", "type"=>"text", "input"=>"field" );
$VIEW_FIELDS["flag"]            = array( "validate"=>"number", "insert"=>"quoted", "type"=>"text", "input"=>"field" );
$VIEW_FIELDS["scroller"]        = array( "validate"=>"", "insert"=>"quoted", "type"=>"bool", "input"=>"chbox" );
$VIEW_FIELDS["aditional"]       = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"area" );
$VIEW_FIELDS["aditional2"]      = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"area" );
$VIEW_FIELDS["aditional3"]      = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"area" );
$VIEW_FIELDS["aditional4"]      = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"area" );
$VIEW_FIELDS["aditional5"]      = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"area" );
$VIEW_FIELDS["aditional6"]      = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"area" );
$VIEW_FIELDS["noitem_msg"]      = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"area" );
$VIEW_FIELDS["field1"]          = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"selfld" );
$VIEW_FIELDS["field2"]          = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"selfld" );
$VIEW_FIELDS["field3"]          = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"selfld" );
$VIEW_FIELDS["calendar_type"]   = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"select", 
                                         "values"=>array ("mon"=>L_MONTH,"mon_table"=>L_MONTH_TABLE));

# se_views.php3 - view types
$VIEW_TYPES['list']  = array( "name" => L_COMPACT_VIEW,
                              "before" => L_V_BEFORE ,
                              "odd" => L_V_ODD ,
                              "even_odd_differ" => L_V_EVENODDDIF ,
                              "even" => L_V_EVEN ,
                              "after" => L_V_AFTER ,
                              "remove_string" => L_V_REMOVE_STRING ,
// TODO                              "modification" => L_V_MODIFICATION ,
#                              "parameter" => L_V_PARAMETER ,
#                              "img1" => L_V_IMG1 ,
#                              "img2" => L_V_IMG2 ,
#                              "img3" => L_V_IMG3 ,
#                              "img4" => L_V_IMG4 ,
                              "order1" => L_V_ORDER1 ,
                              "o1_direction" => L_V_ORDER1DIR ,
                              "order2" => L_V_ORDER2 ,
                              "o2_direction" => L_V_ORDER2DIR ,
                              "group_by1" => L_V_GROUP_BY1 ,
                              "g1_direction" => L_V_GROUP1DIR ,
#                              "group_by2" => L_V_GROUP_BY2 ,
#                              "g2_direction" => L_V_GROUP2DIR ,
                              "group_title" => L_V_GROUP ,
                              "group_bottom" => L_V_GROUP_BOTTOM ,
#                              "selected_item" => L_V_SELECTED ,
                              "cond1field" => L_V_COND1FLD ,
                              "cond1op" => L_V_COND1OP ,
                              "cond1cond" => L_V_COND1COND ,
                              "cond2field" => L_V_COND2FLD ,
                              "cond2op" => L_V_COND2OP ,
                              "cond2cond" => L_V_COND2COND ,
                              "cond3field" => L_V_COND3FLD ,
                              "cond3op" => L_V_COND3OP ,
                              "cond3cond" => L_V_COND3COND ,
                              "listlen" => L_V_LISTLEN ,
                              "noitem_msg" => L_V_NO_ITEM );
#                              "flag" => L_V_FLAG ,
// TODO                              "scroller" => L_V_SCROLLER ,
#                              "aditional" => L_V_ADITIONAL );

$VIEW_TYPES['full'] = array( 'name' => L_FULLTEXT_VIEW,
                              "before" => L_V_BEFORE ,
                              "odd" => L_V_ODD ,
                              "after" => L_V_AFTER ,
// TODO                              "modification" => L_V_MODIFICATION ,
                              "cond1field" => L_V_COND1FLD ,
                              "cond1op" => L_V_COND1OP ,
                              "cond1cond" => L_V_COND1COND ,
                              "cond2field" => L_V_COND2FLD ,
                              "cond2op" => L_V_COND2OP ,
                              "cond2cond" => L_V_COND2COND ,
                              "cond3field" => L_V_COND3FLD ,
                              "cond3op" => L_V_COND3OP ,
                              "cond3cond" => L_V_COND3COND ,
                              "noitem_msg" => L_V_NO_ITEM );

$VIEW_TYPES['discus'] = array( 'name' => L_DISCUSSION_VIEW,
                              "before" => L_V_BEFORE ,
                              "odd" => L_D_COMPACT ,
                              "after" => L_V_AFTER ,
                              "aditional2" => L_V_D_SEL_BUTTON ,
                              "aditional3" => L_V_D_ALL_BUTTON ,
                              "aditional4" => L_V_D_NEW_BUTTON ,
                              "even_odd_differ" => L_D_SHOWIMGS ,
                              "modification" => L_D_ORDER ,
                              "img1" => L_V_IMG1 ,
                              "img2" => L_V_IMG2 ,
                              "img3" => L_V_IMG3 ,
                              "img4" => L_V_IMG4 ,
                              "even" => L_D_FULLTEXT,
                              "aditional" => L_V_D_SPACE ,
                              "remove_string" => L_D_FORM
                              );

// discussion to mail 
$VIEW_TYPES['disc2mail'] = array( 'name' => L_DISCUSSION_2_MAIL,
                              "aditional" => L_V_MAIL_FROM,
                              "aditional2" => L_V_MAIL_REPLY_TO,
                              "aditional3" => L_V_MAIL_ERRORS_TO,
                              "aditional4" => L_V_MAIL_SENDER,
                              "aditional5" => L_V_MAIL_SUBJECT,
                              "even" => L_V_MAIL_BODY
                              );

/*  TODO
$VIEW_TYPES['seetoo'] = array( 'name' => L_RELATED_VIEW,
                              "before" => L_V_BEFORE ,
                              "odd" => L_V_ODD ,
                              "even_odd_differ" => L_V_EVENODDDIF ,
                              "even" => L_V_EVEN ,
                              "after" => L_V_AFTER ,
                              "modification" => L_V_MODIFICATION ,
                              "order1" => L_V_ORDER1 ,
                              "o1_direction" => L_V_ORDER1DIR ,
                              "order2" => L_V_ORDER2 ,
                              "o2_direction" => L_V_ORDER2DIR ,
                              "selected_item" => L_V_SELECTED ,
                              "listlen" => L_V_LISTLEN );
*/
                              
$VIEW_TYPES['const'] = array( 'name' => L_CONSTANT_VIEW,
                              "before" => L_V_BEFORE ,
                              "odd" => L_V_ODD ,
                              "even" => L_V_EVEN ,
                              "after" => L_V_AFTER ,
#                              "selected_item" => L_V_SELECTED ,
                              "parameter" => L_V_CONSTANT_GROUP ,
                              "order1" => L_V_ORDER1 ,
                              "listlen" => L_V_LISTLEN ,
                              "even_odd_differ" => L_V_EVENODDDIF ,
                              "o1_direction" => L_V_ORDER1DIR);


$VIEW_TYPES['rss'] = array( 'name' => L_RSS_VIEW,
                              "before" => L_V_BEFORE ,
                              "odd" => L_V_ODD ,
                              "after" => L_V_AFTER ,
                              "order1" => L_V_ORDER1 ,
                              "o1_direction" => L_V_ORDER1DIR ,
                              "order2" => L_V_ORDER2 ,
                              "o2_direction" => L_V_ORDER2DIR ,
                              "cond1field" => L_V_COND1FLD ,
                              "cond1op" => L_V_COND1OP ,
                              "cond1cond" => L_V_COND1COND ,
                              "cond2field" => L_V_COND2FLD ,
                              "cond2op" => L_V_COND2OP ,
                              "cond2cond" => L_V_COND2COND ,
                              "cond3field" => L_V_COND3FLD ,
                              "cond3op" => L_V_COND3OP ,
                              "cond3cond" => L_V_COND3COND ,
                              "listlen" => L_V_LISTLEN ,
                              "noitem_msg" => L_V_NO_ITEM );

$VIEW_TYPES['static'] = array( 'name' => L_STATIC_VIEW, 
                              "odd" => L_V_ODD );
                              

# for javascript list of items 
$VIEW_TYPES['script'] = array( 'name' => L_SCRIPT_VIEW,  
                              "before" => L_V_BEFORE ,
                              "odd" => L_V_ODD ,
                              "after" => L_V_AFTER ,
                              "order1" => L_V_ORDER1 ,
                              "o1_direction" => L_V_ORDER1DIR ,
                              "order2" => L_V_ORDER2 ,
                              "o2_direction" => L_V_ORDER2DIR ,
                              "cond1field" => L_V_COND1FLD ,
                              "cond1op" => L_V_COND1OP ,
                              "cond1cond" => L_V_COND1COND ,
                              "cond2field" => L_V_COND2FLD ,
                              "cond2op" => L_V_COND2OP ,
                              "cond2cond" => L_V_COND2COND ,
                              "cond3field" => L_V_COND3FLD ,
                              "cond3op" => L_V_COND3OP ,
                              "cond3cond" => L_V_COND3COND ,
                              "listlen" => L_V_LISTLEN ,
                              "noitem_msg" => L_V_NO_ITEM );
                              
$VIEW_TYPES['calendar'] = array ('name' => L_CALENDAR_VIEW,
                              "calendar_type" => L_V_CALENDAR_TYPE,
                              "before" => L_V_BEFORE ,
                              "aditional3" => L_V_EVENT_TD ,
                              "odd" => L_V_EVENT ,
                              "after" => L_V_AFTER ,
                              "remove_string" => L_V_REMOVE_STRING ,
                              "order1" => L_V_ORDER1 ,
                              "o1_direction" => L_V_ORDER1DIR ,
                              "order2" => L_V_ORDER2 ,
                              "o2_direction" => L_V_ORDER2DIR ,
                              "field1" => L_V_FROM_DATE,
                              "field2" => L_V_TO_DATE,
                              "group_title" => L_V_DAY ,
                              "group_bottom" => L_V_DAY_BOTTOM ,
                              "even_odd_differ" => L_V_EMPTY_DIFFER,
                              "aditional" => L_V_DAY_EMPTY,
                              "aditional2" => L_V_DAY_EMPTY_BOTTOM,
#                              "selected_item" => L_V_SELECTED ,
                              "cond1field" => L_V_COND1FLD ,
                              "cond1op" => L_V_COND1OP ,
                              "cond1cond" => L_V_COND1COND ,
                              "cond2field" => L_V_COND2FLD ,
                              "cond2op" => L_V_COND2OP ,
                              "cond2cond" => L_V_COND2COND ,
                              "cond3field" => L_V_COND3FLD ,
                              "cond3op" => L_V_COND3OP ,
                              "cond3cond" => L_V_COND3COND ,
                              "listlen" => L_V_LISTLEN ,
                              "noitem_msg" => L_V_NO_ITEM );
#                              "flag" => L_V_FLAG ,
// TODO                              "scroller" => L_V_SCROLLER ,
#                              "aditional" => L_V_ADITIONAL );

$VIEW_TYPES['digest']  = array( "name" => L_ALERTS_VIEW,
                              "before" => L_V_BEFORE ,
                              "odd" => L_V_ODD ,
                              "even_odd_differ" => L_V_EVENODDDIF ,
                              "even" => L_V_EVEN ,
                              "after" => L_V_AFTER ,
                              "remove_string" => L_V_REMOVE_STRING ,
                              "order1" => L_V_ORDER1 ,
                              "o1_direction" => L_V_ORDER1DIR ,
                              "order2" => L_V_ORDER2 ,
                              "o2_direction" => L_V_ORDER2DIR ,
                              "group_by1" => L_V_GROUP_BY1 ,
                              "g1_direction" => L_V_GROUP1DIR ,
                              "group_title" => L_V_GROUP ,
                              "group_bottom" => L_V_GROUP_BOTTOM ,
                              "listlen" => L_V_MAXLISTLEN,
                              "noitem_msg" => L_V_NO_ITEM,
                              "function:digest_filters" => "" );

# modification - options for modification field of views
# alias - which aliases to show
$VIEW_TYPES_INFO['list'] = array('modification'=>array('1'=>'search',
                                                       '2'=>'parameter',
                                                       '3'=>'statistic',
                                                       '4'=>'all in thread',
                                                       '5'=>'related',
                                                       '6'=>'keyword related'),
                                 'aliases' => 'field');
$VIEW_TYPES_INFO['full'] = array('modification'=>array ('11'=>'newest',
                                                        '12'=>'newest with condition',
                                                        '13'=>'oldest with condition',
                                                        '14'=>'id', '15'=>'parameter'),
                                 'aliases' => 'field');
$VIEW_TYPES_INFO['digest'] = array('aliases' => 'field');
$VIEW_TYPES_INFO['discus'] = array('modification'=>array('21'=>'timeorder', 
                                                         '22'=>'reverse timeorder', 
                                                         '23'=>'thread' ),
                                   'aditional' =>array('default'=>'<img src="'.$AA_INSTAL_PATH.'images/blank.gif" width=20 height=1 border="0">'),
                                   'aditional2'=>array('default'=>'<input type=button name=sel_ids value="' .L_D_SHOW_SELECTED. '" onClick=showSelectedComments() class="discbuttons">'),
                                   'aditional3'=>array('default'=>'<input type=button name=all_ids value="' .L_D_SHOW_ALL. '" onClick=showAllComments() class="discbuttons">'),
                                   'aditional4'=>array('default'=>'<input type=button name=add_disc value="' .L_D_ADD_NEW. '" onClick=showAddComments() class="discbuttons">'),
                                   'aliases' => 'discus');
$VIEW_TYPES_INFO['discus2mail'] = array ('aliases' => 'discus2mail');                                   
$VIEW_TYPES_INFO['seetoo'] = array('modification'=>array('31'=>'related', 
                                                         '32'=>'keyword with OR',
                                                         '33'=>'keyword with AND' ),
                                 'aliases' => 'field');
$VIEW_TYPES_INFO['const'] = array('aliases' => 'const',
                                  'order' => array('name'=>'name', 
                                                   'value'=>'value',
                                                   'pri'=>'priority'));
                                  
$VIEW_TYPES_INFO['rss'] = array('aliases' => 'field');
$VIEW_TYPES_INFO['calendar'] = array('aliases' => 'field',
    'aliases_additional' => array (
        '_#CV_TST_1' => array ('hlp'=>L_C_TIMESTAMP1),
        '_#CV_TST_2' => array ('hlp'=>L_C_TIMESTAMP2),
        '_#CV_NUM_D' => array ('hlp'=>L_C_NUMD),
        '_#CV_NUM_M' => array ('hlp'=>L_C_NUMM),
        '_#CV_NUM_Y' => array ('hlp'=>L_C_NUMY)));
        
$VIEW_TYPES_INFO['static'] = array('aliases' => 'none');
$VIEW_TYPES_INFO['script'] = array('aliases' => 'field');

# flag in the feedmap table 
define ("FEEDMAP_FLAG_MAP", 0);
define ("FEEDMAP_FLAG_VALUE", 1);
define ("FEEDMAP_FLAG_EMPTY", 2);
define ("FEEDMAP_FLAG_EXTMAP", 3);
define ("FEEDMAP_FLAG_JOIN", 4);
                      
define ("DISCUS_HTML_FORMAT", 1);              # discussion html format flag in slice table

// don't check whether these fields exist (in the conds[] array used by searchform):
$conds_not_field_names = array ("operator"=>1,"value"=>1,"discussion"=>1,"valuejoin"=>1);
// used in add slice wizard
define ("NOT_EMAIL_WELCOME", -1);

$FILEMAN_ACCESSES = array (
    "0" => L_SUPERADMIN,
//    "EDITOR" => L_EDITOR,
    "ADMINISTRATOR" => L_ADMINISTRATOR);
    
$ALERTS_DEFAULT_COLLECTION = "__default__";
$ALERTS_SUBSCRIPTION_COLLECTION = "__subscription__";

require $GLOBALS[AA_INC_PATH]."constants_param_wizard.php3";
?>