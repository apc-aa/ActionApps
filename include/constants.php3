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

require_once "mgettext.php3";

#
# Used constants. Do not edit if you are not developer.
#

  # There we can mention $FIELD_TYPES, but they are not defined in this file,
  # but in database as special slice with id 'AA_Core_Fields..'

  # Field types - each field in slice is one of this type.
  # The types are defined APC wide for easy item interchanging between APC nodes
  # (on the other hand, new type can be added just by placing new fileld
  # in database table fields as for 'AA_Core_Fields..' slice).

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
* @global array $MODULES
*     "name" is a description of the module,
*     "hide_create_module" doesn't show the module in the Create Slice / Module page
*/
$MODULES = array( 'S' => array( 'table' => 'slice',
                                'name' => _m('Slice'),
                                'hide_create_module' => 1,
                                'directory' => "admin/",
                                'menu' => "include/menu.php3"),
                  'W' => array( 'table' => 'site',
                                'name' => 'Site',
                                'show_templates' => 1,  # show list of sites on 'create new' - used as templates
                                'directory' => "modules/site/",
                                'menu' => "modules/site/menu.php3",
                                'language_files' => array(
                                    'en_site_lang.php3' => 'en_site_lang.php3',
                                    'cz_site_lang.php3' => 'cz_site_lang.php3')),
                  'A' => array( 'table' => 'module', # this module doesn't have any special info yet
                                'name' => _m('MySQL Auth'),
                                'hide_create_module' => 1,
                                'directory' => "modules/mysql_auth/",
                                'menu' => "modules/mysql_auth/menu.php3"),
                  'J' => array( 'table' => 'jump',
                                'name' => _m('Jump inside AA control panel'),
                                'directory' => "modules/jump/",
                                'menu' => "menu.php3"),
            	  'P' => array ('table' => 'polls',
                  				'name' => _m('Polls for AA'),
                                'show_templates' => 1,
                   				'directory' => "modules/polls/",
                                'menu' => "modules/polls/menu.php3"));
$MODULES['Alerts'] = array ('table' => 'module',
                            'name' => _m('Alerts'),
                            'directory' => "modules/alerts/",
                            'menu' => "modules/alerts/menu.php3",
                            'letter' => 'A');  // letter is used for the modules
                                               // which indentificator is not 1
                                               // letter long (we need 1-letter
                                               // identification for some
                                               // javascripts in um_util.php3
$MODULES['Links'] =  array ('table' => 'links',
                            'name' => _m('Links'),
                            'show_templates' => 1,
                            'directory' => "modules/links/",
                            'menu' => "modules/links/menu.php3",
                            'letter' => 'L');

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
* @global array $LANGUAGE_FILES language files for slices (not for some modules, e.g. site)
*/
$LANGUAGE_FILES = array( "en_news_lang.php3" => "en_news_lang.php3",
                         "es_news_lang.php3" => "es_news_lang.php3",
                         "cz_news_lang.php3" => "cz_news_lang.php3",
                         "sk_news_lang.php3" => "sk_news_lang.php3",
                         "de_news_lang.php3" => "de_news_lang.php3",
                         "ro_news_lang.php3" => "ro_news_lang.php3",
                         "ja_news_lang.php3" => "ja_news_lang.php3");

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
* @global array $LANGUAGE_CHARSETS charsets to be used in HTML HEAD and otherwere
*/
$LANGUAGE_CHARSETS = array ("cz" => "windows-1250",
                            "en" => "iso-8859-1",
                            "es" => "iso-8859-1",
                            "de" => "iso-8859-1",
                            "ro" => "iso-8859-2",
                            "sk" => "windows-1250",
                            "ja" => "EUC-JP");

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
* @global array $LANGUAGE_NAMES
*/
$LANGUAGE_NAMES = array ("cz" => "Èeština",
                         "en" => "English",
                         "es" => "Espanol",
                         "de" => "Deutsch",
                         "ro" => "Romanian",
                         "sk" => "Slovenština",
                         "ja" => "Japanian");

/// number of items in editor window
define("EDIT_ITEM_COUNT", 20);

define("DEFAULT_FULLTEXT_HTML", '<BR><FONT SIZE=+2 COLOR=blue>_#HEADLINE</FONT>'
                               .'<BR><B>_#PUB_DATE</B> <BR>_#FULLTEXT');
define("DEFAULT_ODD_HTML",
     '<font face=Arial color=#808080 size=-2>_#PUB_DATE - </font>'
    .'<font color=red><strong><a href=_#HDLN_URL>_#HEADLINE</a></strong></font>'
    .'<font color=#808080 size=-1><br>_#PLACE###(<a href="_#SRC_URL#">_#SOURCE##</a>) - </font>'
    .'<font color=black size=-1>_#ABSTRACT<br></font><br>');
define("DEFAULT_EVEN_HTML", "");
define("DEFAULT_TOP_HTML", "<br>");
define("DEFAULT_BOTTOM_HTML", "<br>");
define("DEFAULT_CATEGORY_HTML", "<p>_#CATEGORY</p>");
define("DEFAULT_EVEN_ODD_DIFFER", false);
define("DEFAULT_CATEGORY_SORT", true);
define("DEFAULT_COMPACT_REMOVE", "()");
define("DEFAULT_FULLTEXT_REMOVE", "()");

define("ANONYMOUS_EDIT_NOT_ALLOWED", 0);
define("ANONYMOUS_EDIT_ALL", 1);
define("ANONYMOUS_EDIT_ONLY_ANONYMOUS", 2);
define("ANONYMOUS_EDIT_NOT_EDITED_IN_AA", 3);
define("ANONYMOUS_EDIT_PASSWORD", 4);
define("ANONYMOUS_EDIT_HTTP_AUTH", 5);

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
   "fileman_dir","fileman_access","javascript","aditional",
   "mailman_field_lists", "auth_field_group", "reading_password");

$SLICE_FIELDS_NUM  = array( "deleted", "export_to_all", "template",
   "even_odd_differ", "category_sort", "d_expiry_limit", "d_listlen",
   "email_sub_enable", "exclude_from_dir","permit_anonymous_post",
   "permit_anonymous_edit", "permit_offline_fill",);

$FIELD_FIELDS_TEXT = array(  "id", "type", "slice_id", "name",
  "input_help", "input_morehlp", "input_default",
  "input_show_func", "content_id", "search_type", "search_help",
  "search_before", "search_more_help", "alias1", "alias1_func", "alias1_help",
  "alias2", "alias2_func", "alias2_help", "alias3","alias3_func", "alias3_help",
  "input_before", "aditional", "input_validate", "input_insert_func", "in_item_tbl");

$FIELD_FIELDS_NUM = array( "input_pri", "required", "feed", "multiple",
  "search_pri", "search_show", "search_ft_show", "search_ft_default",
  "content_edit", "html_default", "html_show", "input_show", "text_stored");

/// @return array input show function types
function inputShowFuncTypes ()
{
    return array (
    "txt" => array( 'name' => _m("Text Area"), #textarea
                    'multiple' => false,
                    'paramformat' => 'fnc:param' ),
    "tpr" => array( 'name' => _m("Textarea with Presets"), #textarea with preset
                    'multiple' => false,
                    'paramformat' => 'fnc:const:param' ),
    "edt" => array( 'name' => _m("Rich Edit Text Area"), #rich text edit
                    'multiple' => false,
                    'paramformat' => 'fnc:param' ),
    "fld" => array( 'name' => _m("Text Field"), #textfield
                    'multiple' => false,
                    'paramformat' => 'fnc:param' ),
    "sel" => array( 'name' => _m("Select Box"), #selectbox
                    'multiple' => false,
                    'paramformat' => 'fnc:const:param' ),
    "pre" => array( 'name' => _m("Select Box with Presets"), #selectbox with preset
                    'multiple' => false,
                    'paramformat' => 'fnc:const:param' ),
    "rio" => array( 'name' => _m("Radio Button"), #radio button
                    'multiple' => false,
                    'paramformat' => 'fnc:const:param' ),
    "dte" => array( 'name' => _m("Date"), #date
                    'multiple' => false,
                    'paramformat' => 'fnc:param' ),
    "chb" => array( 'name' => _m("Check Box"), #check box
                    'multiple' => false,
                    'paramformat' => 'fnc' ),
    "mch" => array( 'name' => _m("Multiple Checkboxes"), #multiple checkbox
                    'multiple' => true,
                    'paramformat' => 'fnc:const:param' ),
    "mse" => array( 'name' => _m("Multiple Selectbox"), #multiple selectbox
                    'multiple' => true,
                    'paramformat' => 'fnc:const:param' ),
    "wi2" => array( 'name' => _m("Two Boxes"), #2 windows
                    'multiple' => true,
                    'paramformat' => 'fnc:const:param' ),
    "fil" => array( 'name' => _m("File Upload"), #file
                    'multiple' => false,
                    'paramformat' => 'fnc:param' ),
  # "isi" => array( 'name' => _m("Related Item Select Box"), #
  #                 'multiple' => true,
  #                 'paramformat' => 'fnc:const:param' ),
    "iso" => array( 'name' => _m("Related Item Window"),   #related items selectbox - outer
                    'multiple' => true,
                    'paramformat' => 'fnc:const:param' ),
    "nul" => array( 'name' => _m("Do not show"), #
                    'multiple' => false,
                    'paramformat' => 'fnc' ),
    "hco" => array( 'name' => _m("Hierachical constants"), #hierarchy constant
                    'multiple' => false,
                    'paramformat' => 'fnc:const:param'),
    "pwd" => array( 'name' => _m("Password and Change password"), #
                    'multiple' => false,
                    'paramformat' => 'fnc:param' ),
    "hid" => array( 'name' => _m("Hidden field"),       # hidden field (good for
                    'multiple' => false,                # javascript triggers)
                    'paramformat' => 'fnc'));
}

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

function inputFeedModes ()
{
  return array ( STATE_FEEDABLE => _m("Feed"),
                STATE_UNFEEDABLE => _m("Do not feed"),
                STATE_FEEDNOCHANGE => _m("Feed locked"),
                STATE_FEEDABLE_UPDATE => _m("Feed & update"),
                STATE_FEEDABLE_UPDATE_LOCKED => _m("Feed & update & lock")
              );
}

function getViewFields ()
{
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
                                             "values"=>array ("mon"=>_m("Month List"),"mon_table"=>_m("Month Table")));
    return $VIEW_FIELDS;
}

/** View types is an array. The basic format is
*       view_type => array (
*           "view_field (one from $VIEW_FIELDS, see above)" => "label", ...)
*
*   You can use extended format for view_field info:
*       view_field => array (
*           "label" => "field label",
*           "help" => "help text",
*           "input" => "overrides the input function from $VIEW_FIELDS")
*
*   See the "digest" view below for an example.
*/
function getViewTypes ()
{
    $VIEW_TYPES['list']  = array( "name" => _m("Item listing"),
                                  "before" => _m("Top HTML") ,
                                  "odd" => _m("Odd Rows") ,
                                  "even_odd_differ" => _m("Use different HTML code for even rows") ,
                                  "even" => _m("Even Rows") ,
                                  "after" => _m("Bottom HTML") ,
                                  "remove_string" => _m("Remove strings") ,
    // TODO                              "modification" => _m("Type") ,
    #                              "parameter" => _m("Parameter") ,
    #                              "img1" => _m("View image 1") ,
    #                              "img2" => _m("View image 2") ,
    #                              "img3" => _m("View image 3") ,
    #                              "img4" => _m("View image 4") ,
                                  "order1" => _m("Sort primary") ,
                                  "o1_direction" => " " ,
                                  "order2" => _m("Sort secondary") ,
                                  "o2_direction" => " " ,
                                  "group_by1" => _m("Group by") ,
                                  "g1_direction" => " " ,
    #                              "group_by2" => _m("Group by") ,
    #                              "g2_direction" => " " ,
                                  "group_title" => _m("Group title format") ,
                                  "group_bottom" => _m("Group bottom format") ,
    #                              "selected_item" => _m("HTML for Selected") ,
                                  "cond1field" => _m("Condition 1") ,
                                  "cond1op" => " " ,
                                  "cond1cond" => " " ,
                                  "cond2field" => _m("Condition 2") ,
                                  "cond2op" => " " ,
                                  "cond2cond" => " " ,
                                  "cond3field" => _m("Condition 3") ,
                                  "cond3op" => " " ,
                                  "cond3cond" => " " ,
                                  "listlen" => _m("Listing length") ,
                                  "noitem_msg" => _m("HTML code for \"No item found\" message") );
    #                              "flag" => _m("Flag") ,
    // TODO                              "scroller" => _m("Display page scroller") ,
    #                              "aditional" => _m("Additional") );

    $VIEW_TYPES['full'] = array( 'name' => _m("Fulltext view"),
                                  "before" => _m("Top HTML") ,
                                  "odd" => _m("Odd Rows") ,
                                  "after" => _m("Bottom HTML") ,
                                  "remove_string" => _m("Remove strings") ,
    // TODO                              "modification" => _m("Type") ,
                                  "cond1field" => _m("Condition 1") ,
                                  "cond1op" => " " ,
                                  "cond1cond" => " " ,
                                  "cond2field" => _m("Condition 2") ,
                                  "cond2op" => " " ,
                                  "cond2cond" => " " ,
                                  "cond3field" => _m("Condition 3") ,
                                  "cond3op" => " " ,
                                  "cond3cond" => " " ,
                                  "noitem_msg" => _m("HTML code for \"No item found\" message") );

    $VIEW_TYPES['discus'] = array( 'name' => _m("Discussion"),
                                  "before" => _m("Top HTML") ,
                                  "odd" => _m("HTML code for index view of the comment") ,
                                  "after" => _m("Bottom HTML") ,
                                  "aditional2" => _m("HTML code for \"Show selected\" button") ,
                                  "aditional3" => _m("HTML code for \"Show all\" button") ,
                                  "aditional4" => _m("HTML code for \"Add\" button") ,
                                  "even_odd_differ" => _m("Show images") ,
                                  "modification" => _m("Order by") ,
                                  "img1" => _m("View image 1") ,
                                  "img2" => _m("View image 2") ,
                                  "img3" => _m("View image 3") ,
                                  "img4" => _m("View image 4") ,
                                  "even" => _m("HTML code for fulltext view of the comment"),
                                  "aditional" => _m("HTML code for space before comment") ,
                                  "remove_string" => _m("HTML code of the form for posting comment")
                                  );

    // discussion to mail
    $VIEW_TYPES['disc2mail'] = array( 'name' => _m("Discussion To Mail"),
                                  "aditional" => _m("From: (email header)"),
                                  "aditional2" => _m("Reply-To:"),
                                  "aditional3" => _m("Errors-To:"),
                                  "aditional4" => _m("Sender:"),
                                  "aditional5" => _m("Mail Subject:"),
                                  "even" => _m("Mail Body:")
                                  );

    /*  TODO
    $VIEW_TYPES['seetoo'] = array( 'name' => _m("Related item"),
                                  "before" => _m("Top HTML") ,
                                  "odd" => _m("Odd Rows") ,
                                  "even_odd_differ" => _m("Use different HTML code for even rows") ,
                                  "even" => _m("Even Rows") ,
                                  "after" => _m("Bottom HTML") ,
                                  "modification" => _m("Type") ,
                                  "order1" => _m("Sort primary") ,
                                  "o1_direction" => " " ,
                                  "order2" => _m("Sort secondary") ,
                                  "o2_direction" => " " ,
                                  "selected_item" => _m("HTML for Selected") ,
                                  "listlen" => _m("Listing length") );
    */

    $VIEW_TYPES['const'] = array( 'name' => _m("View of Constants"),
                                  "before" => _m("Top HTML") ,
                                  "odd" => _m("Odd Rows") ,
                                  "even" => _m("Even Rows") ,
                                  "after" => _m("Bottom HTML") ,
    #                              "selected_item" => _m("HTML for Selected") ,
                                  "parameter" => _m("Constant Group") ,
                                  "order1" => _m("Sort primary") ,
                                  "listlen" => _m("Listing length") ,
                                  "even_odd_differ" => _m("Use different HTML code for even rows") ,
                                  "o1_direction" => " ");


    $VIEW_TYPES['rss'] = array( 'name' => _m("RSS exchange"),
                                  "before" => _m("Top HTML") ,
                                  "odd" => _m("Odd Rows") ,
                                  "after" => _m("Bottom HTML") ,
                                  "order1" => _m("Sort primary") ,
                                  "o1_direction" => " " ,
                                  "order2" => _m("Sort secondary") ,
                                  "o2_direction" => " " ,
                                  "cond1field" => _m("Condition 1") ,
                                  "cond1op" => " " ,
                                  "cond1cond" => " " ,
                                  "cond2field" => _m("Condition 2") ,
                                  "cond2op" => " " ,
                                  "cond2cond" => " " ,
                                  "cond3field" => _m("Condition 3") ,
                                  "cond3op" => " " ,
                                  "cond3cond" => " " ,
                                  "listlen" => _m("Listing length") ,
                                  "noitem_msg" => _m("HTML code for \"No item found\" message") );

    $VIEW_TYPES['static'] = array( 'name' => _m("Static page"),
                                   "odd" => _m("HTML code") );


    # for javascript list of items
    $VIEW_TYPES['script'] = array( 'name' => _m("Javascript item exchange"),
                                  "before" => _m("Top HTML") ,
                                  "odd" => _m("Odd Rows") ,
                                  "after" => _m("Bottom HTML") ,
                                  "order1" => _m("Sort primary") ,
                                  "o1_direction" => " " ,
                                  "order2" => _m("Sort secondary") ,
                                  "o2_direction" => " " ,
                                  "cond1field" => _m("Condition 1") ,
                                  "cond1op" => " " ,
                                  "cond1cond" => " " ,
                                  "cond2field" => _m("Condition 2") ,
                                  "cond2op" => " " ,
                                  "cond2cond" => " " ,
                                  "cond3field" => _m("Condition 3") ,
                                  "cond3op" => " " ,
                                  "cond3cond" => " " ,
                                  "listlen" => _m("Listing length") ,
                                  "noitem_msg" => _m("HTML code for \"No item found\" message") );

    $VIEW_TYPES['calendar'] = array ('name' => _m("Calendar"),
                                  "calendar_type" => _m("Calendar Type"),
                                  "before" => _m("Top HTML") ,
                                  "aditional3" => _m("Additional attribs to the TD event tag") ,
                                  "odd" => _m("Event format") ,
                                  "after" => _m("Bottom HTML") ,
                                  "remove_string" => _m("Remove strings") ,
                                  "order1" => _m("Sort primary") ,
                                  "o1_direction" => " " ,
                                  "order2" => _m("Sort secondary") ,
                                  "o2_direction" => " " ,
                                  "field1" => _m("Start date field"),
                                  "field2" => _m("End date field"),
                                  "group_title" => _m("Day cell top format") ,
                                  "group_bottom" => _m("Day cell bottom format") ,
                                  "even_odd_differ" => _m("Use other header for empty cells"),
                                  "aditional" => _m("Empty day cell top format"),
                                  "aditional2" => _m("Empty day cell bottom format"),
    #                              "selected_item" => _m("HTML for Selected") ,
                                  "cond1field" => _m("Condition 1") ,
                                  "cond1op" => " " ,
                                  "cond1cond" => " " ,
                                  "cond2field" => _m("Condition 2") ,
                                  "cond2op" => " " ,
                                  "cond2cond" => " " ,
                                  "cond3field" => _m("Condition 3") ,
                                  "cond3op" => " " ,
                                  "cond3cond" => " " ,
                                  "listlen" => _m("Listing length") ,
                                  "noitem_msg" => _m("HTML code for \"No item found\" message") );
    #                              "flag" => _m("Flag") ,
    // TODO                              "scroller" => _m("Display page scroller") ,
    #                              "aditional" => _m("Additional") );

    $VIEW_TYPES['digest']  = array( "name" => _m("Alerts Selection Set"),
                                  "aditional" => array (
                                      "label" => _m("Group by selections (some items
                                         may be shown several times)"),
                                      "input" => "chbox"),
                                  "function:digest_filters" => "",
                                  "aditional2" => array (
                                      "label" => _m("Fulltext URL"),
                                      "input" => "field",
                                      "help" => _m("Link to the .shtml page used
                                        to create headline links.")),
                                  "before" => _m("Top HTML") ,
                                  "odd" => _m("Odd Rows") ,
                                  "even_odd_differ" => _m("Use different HTML code for even rows") ,
                                  "even" => _m("Even Rows") ,
                                  "after" => _m("Bottom HTML") ,
                                  "remove_string" => _m("Remove strings") ,
                                  "order1" => _m("Sort primary") ,
                                  "o1_direction" => " " ,
                                  "order2" => _m("Sort secondary") ,
                                  "o2_direction" => " " ,
                                  "group_by1" => _m("Group by") ,
                                  "g1_direction" => " " ,
                                  "group_title" => _m("Group title format") ,
                                  "group_bottom" => _m("Group bottom format") ,
                                  "listlen" => _m("Max number of items"),
                                  "noitem_msg" => _m("HTML code for \"No item found\" message"));

    // View used for listing of ursl - mainly for listing items for index
    // servers (HtDig, MnogoSearch, ...)
    // The main difference from 'list' view is that the aliases are created just
    // from item table, so the memory usage is much smaller - you can list all
    // urls, even if there is a lot of items in the slice.
    $VIEW_TYPES['urls']  = array( "name" => _m("URL listing"),
                                  "before" => _m("Top HTML") ,
                                  "odd" => _m("Row HTML"),
                                  "after" => _m("Bottom HTML") ,
                                  "remove_string" => _m("Remove strings") ,
                                  "order1" => _m("Sort primary") ,
                                  "o1_direction" => " " ,
                                  "order2" => _m("Sort secondary") ,
                                  "o2_direction" => " " ,
                                  "cond1field" => _m("Condition 1") ,
                                  "cond1op" => " " ,
                                  "cond1cond" => " " ,
                                  "cond2field" => _m("Condition 2") ,
                                  "cond2op" => " " ,
                                  "cond2cond" => " " ,
                                  "cond3field" => _m("Condition 3") ,
                                  "cond3op" => " " ,
                                  "cond3cond" => " " ,
                                  "listlen" => _m("Listing length") ,
                                  "noitem_msg" => _m("HTML code for \"No item found\" message") );

    return $VIEW_TYPES;
}

function getViewTypesInfo() {
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
                                       'aditional2'=>array('default'=>'<input type=button name=sel_ids value="' ._m("Show selected"). '" onClick=showSelectedComments() class="discbuttons">'),
                                       'aditional3'=>array('default'=>'<input type=button name=all_ids value="' ._m("Show all"). '" onClick=showAllComments() class="discbuttons">'),
                                       'aditional4'=>array('default'=>'<input type=button name=add_disc value="' ._m("Add new"). '" onClick=showAddComments() class="discbuttons">'),
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

    $VIEW_TYPES_INFO['urls'] = array('aliases' => 'justids');

    $VIEW_TYPES_INFO['rss'] = array('aliases' => 'field');
    $VIEW_TYPES_INFO['calendar'] = array('aliases' => 'field',
        'aliases_additional' => array (
            '_#CV_TST_1' => array ('hlp'=>_m("Calendar: Time stamp at 0:00 of processed cell")),
            '_#CV_TST_2' => array ('hlp'=>_m("Calendar: Time stamp at 24:00 of processed cell")),
            '_#CV_NUM_D' => array ('hlp'=>_m("Calendar: Day in month of processed cell")),
            '_#CV_NUM_M' => array ('hlp'=>_m("Calendar: Month number of processed cell")),
            '_#CV_NUM_Y' => array ('hlp'=>_m("Calendar: Year number of processed cell"))));

    $VIEW_TYPES_INFO['static'] = array('aliases' => 'none');
    $VIEW_TYPES_INFO['script'] = array('aliases' => 'field');
    return $VIEW_TYPES_INFO;
}

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
// CountHit probability - how offen write logged hits to item table
define ("COUNTHIT_PROBABILITY", 100);

function getFilemanAccesses ()
{ return array (
    "0" => _m("Superadmin"),
//    "EDITOR" => _m("Slice Editor"),
    "ADMINISTRATOR" => _m("Slice Administrator"));
}
?>
