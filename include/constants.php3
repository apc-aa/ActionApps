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
 
#
# Used constants. Do not edit if you are not developer.
#

  // list of text fields in items table (used in feeding.php3 for inserting into database)
$ITEM_FIELDS_TEXT = array("id", "master_id", "slice_id", "category_id", "language_code", "cp_code", "headline", "hl_href", "post_date", "publish_date", "expiry_date", "abstract", "img_src", "source", "source_href", "place", "posted_by", "e_posted_by", "created_by", "edited_by", "last_edit", "contact1", "contact2", "contact3", "edit_note", "img_width", "img_height");
$ITEM_FIELDS_NUM  = array("status_code", "link_only", "html_formatted", "highlight" );

  // list of fields in packed array of shown fields in itemedit.php3
$ITEMEDIT_FIELDS = array( abstract=>L_ABSTRACT, html_formatted=>L_HTML_FORMATTED, full_text=>L_FULL_TEXT, highlight=>L_HIGHLIGHT, hl_href=>L_HL_HREF, link_only=>L_LINK_ONLY, place=>L_PLACE, source=>L_SOURCE, source_href=>L_SOURCE_HREF, status_code=>L_STATUS_CODE, language_code=>L_LANGUAGE_CODE, cp_code=>L_CP_CODE, category_id=>L_CATEGORY_ID, img_src=>L_IMG_SRC, img_width=>L_IMG_WIDTH, img_height=>L_IMG_HEIGHT, posted_by=>L_POSTED_BY, e_posted_by=>L_E_POSTED_BY, publish_date=>L_PUBLISH_DATE, expiry_date=>L_EXPIRY_DATE, edit_note=>L_EDIT_NOTE, img_upload=>L_IMG_UPLOAD);

  // list of fields in packed array of shown fields in big_srch.php3
$SHOWN_SEARCH_FIELDS = array( slice=>L_SRCH_SLICE, category=>L_SRCH_CATEGORY, author=>L_SRCH_AUTHOR, language=>L_SRCH_LANGUAGE, from=>L_SRCH_FROM, to=>L_SRCH_TO, headline=>L_SRCH_HEADLINE, abstract=>L_SRCH_ABSTRACT, full_text=>L_SRCH_FULL_TEXT, edit_note=>L_SRCH_EDIT_NOTE);
  // list of fields in packed array of default values in big_srch.php3
$DEFAULT_SEARCH_IN = array( headline=>L_SRCH_HEADLINE, abstract=>L_SRCH_ABSTRACT, full_text=>L_SRCH_FULL_TEXT, edit_note=>L_SRCH_EDIT_NOTE);


// - new --

  # There we can mention $FIELD_TYPES, but they are not defined in this file, 
  # but in database as slecial slice with id 'AA_Core_Fields..'
  
  # Field types - each field in slice is one of this type. 
  # The types are defined APC wide for easy item interchanging between APC nodes
  # (on the other hand, new type can be added just by placing new fileld 
  # in database table fields as for 'AA_Core_Fields..' slice).

# ---  

$LANGUAGE_FILES = array( "en_news_lang.php3" => "en_news_lang.php3",
                         "es_news_lang.php3" => "es_news_lang.php3",
                         "cz_news_lang.php3" => "cz_news_lang.php3",
                         "sk_news_lang.php3" => "sk_news_lang.php3",
                         "de_news_lang.php3" => "de_news_lang.php3");
  
$SLICE_FIELDS_TEXT = array("id", "name", "owner", "created_by", "created_at",
   "type", "fulltext_format_top", "fulltext_format", "fulltext_format_bottom",
   "odd_row_format", "even_row_format", "compact_top", "compact_bottom",
   "category_top", "category_format", "category_bottom", "config", "slice_url",
   "lang_file", "fulltext_remove", "compact_remove", "notify_sh_offer", 
   "notify_sh_accept", "notify_sh_remove", "notify_holding_item",
   "admin_format_top", "admin_format", "admin_format_bottom", "admin_remove");

$SLICE_FIELDS_NUM  = array( "deleted", "export_to_all", "template", 
   "even_odd_differ", "category_sort", "d_expiry_limit", "d_listlen",  
   "email_sub_enable", "exclude_from_dir");

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
                              "now" => L_INPUT_DEFAULT_NOW);
  
$INPUT_SHOW_FUNC_TYPES = array ("txt" => L_INPUT_SHOW_TXT,
                                "fld" => L_INPUT_SHOW_FLD, 
                                "sel" => L_INPUT_SHOW_SEL, 
                                "pre" => L_INPUT_SHOW_PRE, 
                                "rio" => L_INPUT_SHOW_RIO, 
                                "dte" => L_INPUT_SHOW_DTE, 
                                "chb" => L_INPUT_SHOW_CHB, 
                                "mch" => L_INPUT_SHOW_MCH,
                                "mse" => L_INPUT_SHOW_MSE,
#                               "wi2" => L_INPUT_SHOW_WI2,
                                "fil" => L_INPUT_SHOW_FIL,
#                               "isi" => L_INPUT_SHOW_ISI,
                                "iso" => L_INPUT_SHOW_ISO,
                                "nul" => L_INPUT_SHOW_NUL);
                              
$INPUT_VALIDATE_TYPES = array ("text" => L_INPUT_VALIDATE_TEXT,
                               "url" => L_INPUT_VALIDATE_URL, 
                               "e-mail" => L_INPUT_VALIDATE_EMAIL, 
                               "number" => L_INPUT_VALIDATE_NUMBER, 
                               "id" => L_INPUT_VALIDATE_ID, 
                               "date" => L_INPUT_VALIDATE_DATE, 
                               "bool" => L_INPUT_VALIDATE_BOOL);

$INPUT_INSERT_TYPES = array ("qte" => L_INPUT_INSERT_QTE,
                             "boo" => L_INPUT_INSERT_BOO,
                             "fil" => L_INPUT_INSERT_FIL,
                             "uid" => L_INPUT_INSERT_UID, 
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
$VIEW_FIELDS["name"]            = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"field" );
$VIEW_FIELDS["before"]          = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"area" );
$VIEW_FIELDS["even"]            = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"area" );
$VIEW_FIELDS["even_odd_differ"] = array( "validate"=>"", "insert"=>"quoted", "type"=>"bool", "input"=>"chbox" );
$VIEW_FIELDS["odd"]             = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"area" );
$VIEW_FIELDS["after"]           = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"area" );
$VIEW_FIELDS["group_by1"]       = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"order" );
$VIEW_FIELDS["g1_direction"]    = array( "validate"=>"", "insert"=>"quoted", "type"=>"bool", "input"=>"none" );
$VIEW_FIELDS["group_by2"]       = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"order" );
$VIEW_FIELDS["g2_direction"]    = array( "validate"=>"", "insert"=>"quoted", "type"=>"bool", "input"=>"none" );
$VIEW_FIELDS["group_title"]     = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"area" );
$VIEW_FIELDS["remove_string"]   = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"area" );
$VIEW_FIELDS["modification"]    = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"seltype" );
$VIEW_FIELDS["parameter"]       = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"selgrp" );
$VIEW_FIELDS["img1"]            = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"field" );
$VIEW_FIELDS["img2"]            = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"field" );
$VIEW_FIELDS["img3"]            = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"field" );
$VIEW_FIELDS["img4"]            = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"field" );
$VIEW_FIELDS["order1"]          = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"order" );
$VIEW_FIELDS["o1_direction"]    = array( "validate"=>"", "insert"=>"quoted", "type"=>"bool", "input"=>"none" );
$VIEW_FIELDS["order2"]          = array( "validate"=>"text", "insert"=>"quoted", "type"=>"text", "input"=>"order" );
$VIEW_FIELDS["o2_direction"]    = array( "validate"=>"", "insert"=>"quoted", "type"=>"bool", "input"=>"none" );
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
                              "listlen" => L_V_LISTLEN );
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
                              "cond3cond" => L_V_COND3COND);

$VIEW_TYPES['digest'] = array( 'name' => L_DIGEST_VIEW,
                              "before" => L_V_BEFORE ,
                              "odd" => L_V_ODD ,
                              "after" => L_V_AFTER);


$VIEW_TYPES['discus'] = array( 'name' => L_DISCUSSION_VIEW,
                              "before" => L_V_BEFORE ,
                              "odd" => L_D_COMPACT ,
                              "after" => L_V_AFTER ,
                              "even_odd_differ" => L_D_SHOWIMGS ,
                              "modification" => L_D_ORDER ,
                              "img1" => L_V_IMG1 ,
                              "img2" => L_V_IMG2 ,
                              "img3" => L_V_IMG3 ,
                              "img4" => L_V_IMG4 ,
                              "even" => L_D_FULLTEXT,
                              "remove_string" => L_D_FORM
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
                              "listlen" => L_V_LISTLEN );

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
                              "listlen" => L_V_LISTLEN );

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
                                 'aliases' => 'field');
$VIEW_TYPES_INFO['seetoo'] = array('modification'=>array('31'=>'related', 
                                                         '32'=>'keyword with OR',
                                                         '33'=>'keyword with AND' ),
                                 'aliases' => 'field');
$VIEW_TYPES_INFO['const'] = array('aliases' => 'const',
                                  'order' => array('name'=>'name', 
                                                   'value'=>'value',
                                                   'pri'=>'priority'));
                                  
$VIEW_TYPES_INFO['rss'] = array('aliases' => 'field');
$VIEW_TYPES_INFO['static'] = array('aliases' => 'none');
$VIEW_TYPES_INFO['script'] = array('aliases' => 'field');

# flag in the feedmap table 
define ("FEEDMAP_FLAG_MAP", 0);
define ("FEEDMAP_FLAG_VALUE", 1);
define ("FEEDMAP_FLAG_EMPTY", 2);
define ("FEEDMAP_FLAG_EXTMAP", 3);
                      
define ("DISCUS_HTML_FORMAT", 1);              # discussion html format flag in slice table

require $GLOBALS[AA_INC_PATH]."constants_param_wizard.php3";

/*
$Log$
Revision 1.21  2001/11/26 16:34:09  honzam
Linked new lang files

Revision 1.20  2001/11/07 16:30:38  udosw
Included German language files -- UdoSW

Revision 1.19  2001/10/24 18:44:10  honzam
new parameter wizard for function aliases and input type parameters

Revision 1.18  2001/10/08 17:03:35  honzam
Language constants fixes

Revision 1.17  2001/09/27 15:50:57  honzam
New related stories support, Aliases for view and mail displaying

Revision 1.16  2001/09/12 06:19:00  madebeer
Added ability to generate RSS views.
Added f_q to item.php3, to grab 'blurbs' from another slice using aliases

Revision 1.15  2001/07/09 09:28:45  honzam
New supported User defined alias functions in include/usr_aliasfnc.php3 file

Revision 1.14  2001/06/21 14:15:44  honzam
feeding improved - field value redefine possibility in se_mapping.php3

Revision 1.13  2001/06/12 16:07:22  honzam
new feeding modes -  "Feed & update" and "Feed & update & lock"

Revision 1.12  2001/06/03 16:00:49  honzam
multiple categories (multiple values at all) for item now works

Revision 1.11  2001/05/18 13:55:04  honzam
New View feature, new and improved search function (QueryIDs)

Revision 1.10  2001/05/10 10:01:43  honzam
New spanish language files, removed <form enctype parameter where not needed, better number validation

Revision 1.9  2001/04/17 21:32:08  honzam
New conditional alias. Fixed bug of not displayed top/bottom HTML code in fulltext and category

Revision 1.8  2001/03/30 11:54:35  honzam
offline filling bug and others small bugs fixed

Revision 1.7  2001/03/20 16:10:37  honzam
Standardized content management for items - filler, itemedit, offline, feeding
Better feeding support

Revision 1.6  2001/02/26 17:22:30  honzam
color profiles, itemmanager interface changes

Revision 1.5  2001/02/25 08:50:38  madebeer
removed some insert functions

Revision 1.4  2001/01/23 23:58:03  honzam
Aliases setings support, bug in permissions fixed (can't login not super user), help texts for aliases page

Revision 1.2  2001/01/08 13:31:58  honzam
Small bugfixes

*/
?>
