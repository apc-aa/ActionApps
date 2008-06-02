<?php
/**
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

require_once AA_INC_PATH."mgettext.php3";

//
// Used constants. Do not edit if you are not developer.
//

/** GetFieldDef function
 * Field definition shortcut (used in constants.php3 for CONSTANT_FILEDS)
 * @param $name
 * @param $field
 * @param $operators = 'text'
 * @param $table = false
 * @param $search_pri = false
 * @param $order_pri = false
 * @return array
 */
function GetFieldDef( $name, $field, $operators='text', $table=false, $search_pri=false, $order_pri=false) {
    $ret = array('name' => $name, 'field' => $field, 'operators' => $operators);
    if ( $table ) {
        $ret['table']      = $table;
    }
    if ( $search_pri ) {
        $ret['search_pri'] = $search_pri;  // searchbar priority (false = "do not show in searchbar")
    }
    if ( $order_pri ) {
        $ret['order_pri']  = $order_pri;   // orderbar  priority (false = "do not show in orderbar")
    }
    return $ret;
}

/** GetAliasDef function
 * Alias definition shortcut
 * @param $fce
 * @param $field = ''
 * @param $hlp = ''
 */
function GetAliasDef( $fce, $field='', $hlp='') {
    return array('fce' => $fce, 'param' => $field, 'hlp' => $hlp);
}


class AA_Alias {
    var $alias;
    var $funct;
    var $field_id;
    var $parameters;
    var $hlp;
    /** AA_Alias function
     * @param $alias
     * @param $field_id
     * @param $funct
     * @param $parameters = null
     * @param $hlp = ''
     */
    function AA_Alias($alias, $field_id, $funct, $parameters=null, $hlp='') {
        $this->alias       = $alias;
        $this->funct       = $funct;
        $this->field_id    = $field_id;
        $this->parameters  = empty($parameters) ? array() : $parameters;
        $this->hlp         = $hlp;
    }
    /** getArray function
     * @return array
     */
    function getArray() {
        $fce = ParamImplode(array_merge(array($this->funct),$this->parameters));
        return array('fce' => $fce, 'param' => $this->field_id, 'hlp' => $this->hlp);
    }
    /** getAlias function
     * @return $this->alias
     */
    function getAlias() {
        return $this->alias;
    }
}

class AA_Aliases {
    var $aliases;
    /** AA_Aliases function
     *
     */
    function AA_Aliases() {
        $this->aliases = array();
    }
    /** addAlias function
     * @param $alias (by link)
     */
    function addAlias(&$alias) {
        $this->aliases[] = $alias;
    }
    /** addTextAlias
     * @param $alias_name
     * @param $text
     */
    function addTextAlias($alias_name, $text) {
        $this->addAlias(new AA_Alias($alias_name, "id..............", 'f_t', array($text, 'asis')));
    }
    /** getArray function
     *
     */
    function getArray() {
        $ret = array();
        foreach ($this->aliases as $alias) {
            $ret[$alias->getAlias()] = $alias->getArray();
        }
        return $ret;
    }
}


  // There we can mention $FIELD_TYPES, but they are not defined in this file,
  // but in database as special slice with id 'AA_Core_Fields..'

  // Field types - each field in slice is one of this type.
  // The types are defined APC wide for easy item interchanging between APC nodes
  // (on the other hand, new type can be added just by placing new fileld
  // in database table fields as for 'AA_Core_Fields..' slice).

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
                                'show_templates' => 1,  // show list of sites on 'create new' - used as templates
                                'directory' => "modules/site/",
                                'menu' => "modules/site/menu.php3",
                                'language_files' => array(
                                    'en-utf8_site_lang.php3' => 'en-utf8_site_lang.php3',
                                    'en_site_lang.php3' => 'en_site_lang.php3',
                                    'es_site_lang.php3' => 'es_site_lang.php3',
                                    'cz_site_lang.php3' => 'cz_site_lang.php3',
                                    'cz-utf8_site_lang.php3' => 'cz-utf8_site_lang.php3'
                                    )),
                  'A' => array( 'table' => 'module', // this module doesn't have any special info yet
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
                                'menu' => "modules/polls/include/menu.php3",
                                'language_files' => array(
                                    "en_polls_lang.php3" => "en_polls_lang.php3",
                                    "cz_polls_lang.php3" => "cz_polls_lang.php3",
                                    "cz-utf8_polls_lang.php3" => "cz-utf8_polls_lang.php3",
                                    )),

                 );


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
$LANGUAGE_FILES = array( "en_news_lang.php3"      => "en_news_lang.php3",
                         "es_news_lang.php3"      => "es_news_lang.php3",
                         "cz_news_lang.php3"      => "cz_news_lang.php3",
                         "cz-utf8_news_lang.php3" => "cz-utf8_news_lang.php3",
                         "sk_news_lang.php3"      => "sk_news_lang.php3",
                         "de_news_lang.php3"      => "de_news_lang.php3",
                         "ro_news_lang.php3"      => "ro_news_lang.php3",
                         "ru_news_lang.php3"      => "ru_news_lang.php3",
                         "vn_news_lang.php3"      => "vn_news_lang.php3",
                         "ja_news_lang.php3"      => "ja_news_lang.php3",
                         "hr_news_lang.php3"      => "hr_news_lang.php3",
                         "fr_news_lang.php3"      => "fr_news_lang.php3",
                         "hu_news_lang.php3"      => "hu_news_lang.php3",
                         "bg_news_lang.php3"      => "bg_news_lang.php3",
                         "en-utf8_news_lang.php3" => "en-utf8_news_lang.php3",
                        );

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
* @global array $LANGUAGE_CHARSETS charsets to be used in HTML HEAD and otherwere
*/
$LANGUAGE_CHARSETS = array ("cz-utf8" => "utf-8",
                            "cz"      => "windows-1250",
                            "en"      => "iso-8859-1",
                            "es"      => "iso-8859-1",
                            "de"      => "iso-8859-1",
                            "ro"      => "iso-8859-2",
                            "hu"      => "iso-8859-2",
                            "ru"      => "windows-1251",
                            "vn"      => "utf-8",
                            "sk"      => "windows-1250",
                            "ja"      => "EUC-JP",
                            "hr"      => "windows-1250",
                            "fr"      => "iso-8859-1",
                            "bg"      => "windows-1251",
                            "en-utf8" => "utf-8"
                            );

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
* @global array $LANGUAGE_NAMES
*/
$LANGUAGE_NAMES = array ("cz-utf8" => "Cestina (Unicode)",
                         "cz"      => "Cestina",
                         "en"      => "English",
                         "es"      => "Español",
                         "de"      => "Deutsch",
                         "hu"      => "Magyar",
                         "ro"      => "Romanian",
                         "ru"      => "Russian",
                         "vn"      => "Vietnamese",
                         "sk"      => "Slovenèina",
                         "ja"      => "Japanian",
                         "hr"      => "Hrvatski",
                         "fr"      => "Français",
                         "bg"      => "Bulgarian",
                         "en-utf8" => "English Unicode"
                         );

/** Standard Reader Management field IDs defined in Reader Minimal Template */
define ("FIELDID_USERNAME",      "headline........");
define ("FIELDID_PASSWORD",      "password........");
define ("FIELDID_EMAIL",         "con_email.......");
define ("FIELDID_FIRST_NAME",    "text...........1");
define ("FIELDID_LAST_NAME",     "text...........2");
define ("FIELDID_MAIL_CONFIRMED","switch..........");
define ("FIELDID_ACCESS_CODE",   "text...........3");
define ("FIELDID_HOWOFTEN",      "alerts1");
define ("FIELDID_FILTERS",       "alerts2");

/** Translate sort codes from views to slice
 *  (we use numbers in views from historical reason)
 *  '0'=>_m("Ascending"), '1' => _m("Descending"), '2' => _m("Ascending by Priority"), '3' => _m("Descending by Priority")
 */
$VIEW_SORT_DIRECTIONS = array( 0 => 'a', 1 => 'd', 2 => '1', 3 => '9' );

/** Number of items in editor window */
define("EDIT_ITEM_COUNT", 20);

/** Constant used in QueryZids() - defines time steps in query (seconds). We do
 *  not want to ask database with current timestamp, because then each query is
 *  completely different and MySQL can't use its cache.
 */
define("QUERY_DATE_STEP", 1000);


define("DEFAULT_FULLTEXT_HTML", '<br><font size="+2" color="blue">_#HEADLINE</FONT>'
                               .'<br><b>_#PUB_DATE</b> <br>_#FULLTEXT');
define("DEFAULT_ODD_HTML",
     '<font face="Arial" color="#808080" size="-2">_#PUB_DATE - </font>'
    .'<font color="red"><strong><a href="_#HDLN_URL">_#HEADLINE</a></strong></font>'
    .'<font color="#808080" size="-1"><br>_#PLACE###(<a href="_#SRC_URL#">_#SOURCE##</a>) - </font>'
    .'<font color="black" size="-1">_#ABSTRACT<br></font><br>');
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

// MAX_NO_OF_ITEMS_4_GROUP is used with group_n slice.php3 parameter and
// specifies how many items from the begining we have to search
define( 'MAX_NO_OF_ITEMS_4_GROUP', 1000 );

define('NO_PICTURE_URL', AA_INSTAL_URL.'images/blank.gif');

$SLICE_FIELDS_TEXT = array("id", "name", "owner", "created_by", "created_at",
   "type", "fulltext_format_top", "fulltext_format", "fulltext_format_bottom",
   "odd_row_format", "even_row_format", "compact_top", "compact_bottom",
   "category_top", "category_format", "category_bottom", "slice_url",
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
   "even_odd_differ", "category_sort", "d_listlen",
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


function GetConstantFields() {  // function - we need translate _m() on use (not at include time)
    return array(
        'const_short_id'    => GetFieldDef( _m('Short Id'),    'constant.short_id',   'numeric'),
        'const_name'        => GetFieldDef( _m('Name'),        'constant.name',       'text'),
        'const_value'       => GetFieldDef( _m('Value'),       'constant.value',      'text'),
        'const_pri'         => GetFieldDef( _m('Priority'),    'constant.pri',        'numeric'),
        'const_group'       => GetFieldDef( _m('Group'),       'constant.group',      'text'),
        'const_class'       => GetFieldDef( _m('Class'),       'constant.class',      'text'),
    //  'const_counter'     => GetFieldDef( _m('Counter'),     '',                    'numeric'),
        'const_id'          => GetFieldDef( _m('Id'),          'constant.id',         'id'),
        'const_description' => GetFieldDef( _m('Description'), 'constant.description','text'),
        'const_level'       => GetFieldDef( _m('Level'),       'constant.level',      'numeric'));
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

/** content table flags */
define( "FLAG_HTML",         1 );   // content is in HTML
define( "FLAG_FEED",         2 );   // item is fed
define( "FLAG_FREEZE",       4 );   // content can't be changed
define( "FLAG_OFFLINE",      8 );   // off-line filled
define( "FLAG_UPDATE",      16 );   // content should be updated if source is changed (after feeding)
define( "FLAG_TEXT_STORED", 64 );   // value is stored in the text field (and not number field of content table) (added 05/15/2004)

/** item table flags (numbers - just to be compatible with content table) */
define( "ITEM_FLAG_FEED",                2 );   // item is fed
define( "ITEM_FLAG_OFFLINE",             8 );   // off-line filled or imported from file
define( "ITEM_FLAG_ANONYMOUS_EDITABLE", 32);    // anonymously added and thus anonymously editable (reset on every use of itemedit.php3)

/** states of feed field of field table */
define( "STATE_FEEDABLE",              0 );
define( "STATE_UNFEEDABLE",            1 );
define( "STATE_FEEDNOCHANGE",          2 );
define( "STATE_FEEDABLE_UPDATE",       3);
define( "STATE_FEEDABLE_UPDATE_LOCKED",4);

/** relation table flags */
define( "REL_FLAG_FEED", 2 );    // 2 - just to be compatible with content table

/** view table flags */
define( "VIEW_FLAG_COMMENTS", 1 );    // display HTML comments before and after the view
/** inputFeedModes function
 * @return array
 */
function inputFeedModes() {
  return array( STATE_FEEDABLE               => _m("Feed"),
                STATE_UNFEEDABLE             => _m("Do not feed"),
                STATE_FEEDNOCHANGE           => _m("Feed locked"),
                STATE_FEEDABLE_UPDATE        => _m("Feed & update"),
                STATE_FEEDABLE_UPDATE_LOCKED => _m("Feed & update & lock")
              );
}
/** GetViewFieldDef function
 * @param $validate
 * @param $insert
 * @param $type
 * @param $input
 * @param $value = false
 * @return array
 */
function GetViewFieldDef( $validate, $insert, $type, $input, $value=false ) {
    $ret = array( "validate"=>$validate, "insert"=>$insert, "type"=>$type, "input"=>$input );
    if ( $value ) {
        $ret['value'] =  $value;
    }
    return $ret;
}
/** getViewFields function
 * @return array
 */
function getViewFields() {
    // se_views.php3 - view field definition
    /* Jakub added a special field "function:function_name" which calls function show_function_name() to show a special form part and store_function_name() to store form data. */
                                                 // $validate, $insert,  $type,   $input,  $value
    $VIEW_FIELDS["name"]            = GetViewFieldDef("text",  "quoted", "text",  "field"  );
    $VIEW_FIELDS["before"]          = GetViewFieldDef("text",  "quoted", "text",  "area"   );
    $VIEW_FIELDS["even"]            = GetViewFieldDef("text",  "quoted", "text",  "area"   );
    $VIEW_FIELDS["even_odd_differ"] = GetViewFieldDef("",      "quoted", "bool",  "chbox"  );
    $VIEW_FIELDS["odd"]             = GetViewFieldDef("text",  "quoted", "text",  "areabig");
    $VIEW_FIELDS["row_delimiter"]   = GetViewFieldDef("text",  "quoted", "text",  "area");
    $VIEW_FIELDS["after"]           = GetViewFieldDef("text",  "quoted", "text",  "area"   );
    $VIEW_FIELDS["group_by1"]       = GetViewFieldDef("text",  "quoted", "text",  "group"  );
    $VIEW_FIELDS["g1_direction"]    = GetViewFieldDef("",      "quoted", "number","none"   );
    $VIEW_FIELDS["gb_header"]       = GetViewFieldDef("",      "quoted", "number","none"   );
    $VIEW_FIELDS["group_by2"]       = GetViewFieldDef("text",  "quoted", "text",  "order"  );
    $VIEW_FIELDS["g2_direction"]    = GetViewFieldDef("",      "quoted", "number","none"   );
    $VIEW_FIELDS["group_title"]     = GetViewFieldDef("text",  "quoted", "text",  "area"   );
    $VIEW_FIELDS["group_bottom"]    = GetViewFieldDef("text",  "quoted", "text",  "area"   );
    $VIEW_FIELDS["remove_string"]   = GetViewFieldDef("text",  "quoted", "text",  "area"   );
    $VIEW_FIELDS["modification"]    = GetViewFieldDef("text",  "quoted", "text",  "seltype");
    $VIEW_FIELDS["parameter"]       = GetViewFieldDef("text",  "quoted", "text",  "selgrp" );
    $VIEW_FIELDS["img1"]            = GetViewFieldDef("text",  "quoted", "text",  "field"  );
    $VIEW_FIELDS["img2"]            = GetViewFieldDef("text",  "quoted", "text",  "field"  );
    $VIEW_FIELDS["img3"]            = GetViewFieldDef("text",  "quoted", "text",  "field"  );
    $VIEW_FIELDS["img4"]            = GetViewFieldDef("text",  "quoted", "text",  "field"  );
    $VIEW_FIELDS["order1"]          = GetViewFieldDef("text",  "quoted", "text",  "order"  );
    $VIEW_FIELDS["o1_direction"]    = GetViewFieldDef("",      "quoted", "number","none"   );
    $VIEW_FIELDS["order2"]          = GetViewFieldDef("text",  "quoted", "text",  "order"  );
    $VIEW_FIELDS["o2_direction"]    = GetViewFieldDef("",      "quoted", "number","none"   );
    $VIEW_FIELDS["selected_item"]   = GetViewFieldDef("text",  "quoted", "text",   "area"  );
    $VIEW_FIELDS["cond1field"]      = GetViewFieldDef("text",  "quoted", "text",   "cond"  );
    $VIEW_FIELDS["cond1op"]         = GetViewFieldDef("text",  "quoted", "text",   "none"  );
    $VIEW_FIELDS["cond1cond"]       = GetViewFieldDef("text",  "quoted", "text",   "none"  );
    $VIEW_FIELDS["cond2field"]      = GetViewFieldDef("text",  "quoted", "text",   "cond"  );
    $VIEW_FIELDS["cond2op"]         = GetViewFieldDef("text",  "quoted", "text",   "none"  );
    $VIEW_FIELDS["cond2cond"]       = GetViewFieldDef("text",  "quoted", "text",   "none"  );
    $VIEW_FIELDS["cond3field"]      = GetViewFieldDef("text",  "quoted", "text",   "cond"  );
    $VIEW_FIELDS["cond3op"]         = GetViewFieldDef("text",  "quoted", "text",   "none"  );
    $VIEW_FIELDS["cond3cond"]       = GetViewFieldDef("text",  "quoted", "text",   "none"  );
    $VIEW_FIELDS["listlen"]         = GetViewFieldDef("number","quoted", "text",   "field" );
    $VIEW_FIELDS["flag"]            = GetViewFieldDef("",      "quoted", "bool",   "chbox" );
    $VIEW_FIELDS["scroller"]        = GetViewFieldDef("",      "quoted", "bool",   "chbox" );
    $VIEW_FIELDS["aditional"]       = GetViewFieldDef("text",  "quoted", "text",   "area"  );
    $VIEW_FIELDS["aditional2"]      = GetViewFieldDef("text",  "quoted", "text",   "area"  );
    $VIEW_FIELDS["aditional3"]      = GetViewFieldDef("text",  "quoted", "text",   "area"  );
    $VIEW_FIELDS["aditional4"]      = GetViewFieldDef("text",  "quoted", "text",   "area"  );
    $VIEW_FIELDS["aditional5"]      = GetViewFieldDef("text",  "quoted", "text",   "area"  );
    $VIEW_FIELDS["aditional6"]      = GetViewFieldDef("text",  "quoted", "text",   "area"  );
    $VIEW_FIELDS["noitem_msg"]      = GetViewFieldDef("text",  "quoted", "text",   "area"  );
    $VIEW_FIELDS["field1"]          = GetViewFieldDef("text",  "quoted", "text",   "selfld");
    $VIEW_FIELDS["field2"]          = GetViewFieldDef("text",  "quoted", "text",   "selfld");
    $VIEW_FIELDS["field3"]          = GetViewFieldDef("text",  "quoted", "text",   "selfld");
    $VIEW_FIELDS["calendar_type"]   = GetViewFieldDef("text",  "quoted", "text",   "select", array ("mon"=>_m("Month List"),"mon_table"=>_m("Month Table")));
    return $VIEW_FIELDS;
}

/** getViewTypes function
*       View types is an array. The basic format is
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
*   @return array
*/
function getViewTypes() {
    return   array(
        'list' => array( "name" => _m("Item listing"),
                         "before" => _m("Top HTML") ,
                         "odd" => _m("Odd Rows") ,
                         "even_odd_differ" => _m("Use different HTML code for even rows") ,
                         "even" => _m("Even Rows") ,
                         "row_delimiter" => _m("Row Delimiter") ,
                         "after" => _m("Bottom HTML") ,
                         "remove_string" => _m("Remove strings") ,
    // TODO                     "modification" => _m("Type") ,
    //                     "parameter" => _m("Parameter") ,
    //                     "img1" => _m("View image 1") ,
    //                     "img2" => _m("View image 2") ,
    //                     "img3" => _m("View image 3") ,
    //                     "img4" => _m("View image 4") ,
                         "order1" => _m("Sort primary") ,
                         "o1_direction" => " " ,
                         "order2" => _m("Sort secondary") ,
                         "o2_direction" => " " ,
                         "group_by1" => _m("Group by") ,
                         "g1_direction" => " " ,
                         "gb_header" => " " ,
    //                     "group_by2" => _m("Group by") ,
    //                     "g2_direction" => " " ,
                         "group_title" => _m("Group title format") ,
                         "group_bottom" => _m("Group bottom format") ,
    //                     "selected_item" => _m("HTML for Selected") ,
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
                         "noitem_msg" => _m("HTML code for \"No item found\" message"),
                         "flag" => _m("Add view ID as HTML comment")
    // TODO                     "scroller" => _m("Display page scroller") ,
    //                     "aditional" => _m("Additional") );
                       ),

        'full' => array( 'name' => _m("Fulltext view"),
                         "before" => _m("Top HTML") ,
                         "odd" => _m("Odd Rows") ,
                         "after" => _m("Bottom HTML") ,
                         "remove_string" => _m("Remove strings") ,
                         "cond1field" => _m("Condition 1") ,
                         "cond1op" => " " ,
                         "cond1cond" => " " ,
                         "cond2field" => _m("Condition 2") ,
                         "cond2op" => " " ,
                         "cond2cond" => " " ,
                         "cond3field" => _m("Condition 3") ,
                         "cond3op" => " " ,
                         "cond3cond" => " " ,
                         "noitem_msg" => _m("HTML code for \"No item found\" message"),
                         "flag" => _m("Add view ID as HTML comment")
                        ),

        'discus' => array( 'name' => _m("Discussion"),
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
                           "remove_string" => _m("HTML code of the form for posting comment"),
                           "aditional6" => array (
                               "label" => _m("E-mail template"),
                               "input" => "field",
                               "help" => _m("Number of e-mail template used for posting new comments to users")),
                           "flag" => _m("Add view ID as HTML comment")
                         ),

        // discussion to mail
        'disc2mail' => array( 'name' => _m("Discussion To Mail"),
                              "aditional" => _m("From: (email header)"),
                              "aditional2" => _m("Reply-To:"),
                              "aditional3" => _m("Errors-To:"),
                              "aditional4" => _m("Sender:"),
                              "aditional5" => _m("Mail Subject:"),
                              "even" => _m("Mail Body:")
                            ),

    /*  TODO
        'seetoo' => array( 'name' => _m("Related item"),
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

        'const' => array( 'name' => _m("View of Constants"),
                          "before" => _m("Top HTML") ,
                          "odd" => _m("Odd Rows") ,
                          "even" => _m("Even Rows") ,
                          "row_delimiter" => _m("Row Delimiter") ,
                          "after" => _m("Bottom HTML") ,
                          "remove_string" => _m("Remove strings") ,
                          "parameter" => _m("Constant Group") ,
                          "order1" => _m("Sort primary") ,
                          "o1_direction" => " " ,
                          "order2" => _m("Sort secondary") ,
                          "o2_direction" => " " ,
                          "group_by1" => _m("Group by") ,
                          "g1_direction" => " " ,
                          "gb_header" => " " ,
                          "group_title" => _m("Group title format") ,
                          "group_bottom" => _m("Group bottom format") ,
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
                          "noitem_msg" => _m("HTML code for \"No item found\" message"),
                          "even_odd_differ" => _m("Use different HTML code for even rows"),
                          "flag" => _m("Add view ID as HTML comment")
                        ),

        'rss' => array( 'name' => _m("RSS exchange"),
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
                        "noitem_msg" => _m("HTML code for \"No item found\" message")
                      ),

        'static' => array( 'name' => _m("Static page"),
                           "odd" => _m("HTML code"),
                           "flag" => _m("Add view ID as HTML comment")
                         ),

        // for javascript list of items
        'javascript' => array( 'name' => _m("Javascript item exchange"),
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
                               "noitem_msg" => _m("HTML code for \"No item found\" message")
                             ),

        'calendar' => array( 'name' => _m("Calendar"),
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
                             "noitem_msg" => _m("HTML code for \"No item found\" message"),
                             "flag" => _m("Add view ID as HTML comment")
                           ),

        // this view uses also "aditonal" and "aditional3" for the "Group by"
        // radio buttons and for the sort[] box, see se_view.php3

        'digest' => array( "name" => _m("Alerts Selection Set"),
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
                           "row_delimiter" => _m("Row Delimiter") ,
                           "after" => _m("Bottom HTML") ,
                           "remove_string" => _m("Remove strings") ,
                           "order1" => _m("Sort primary") ,
                           "o1_direction" => " " ,
                           "order2" => _m("Sort secondary") ,
                           "o2_direction" => " " ,
                           "group_by1" => _m("Group by") ,
                           "g1_direction" => " " ,
                           "gb_header" => " " ,
                           "group_title" => _m("Group title format") ,
                           "group_bottom" => _m("Group bottom format") ,
                           "listlen" => _m("Max number of items"),
                           "noitem_msg" => _m("HTML code for \"No item found\" message"),
                           "flag" => _m("Add view ID as HTML comment")
                         ),

        // View used for listing of ursl - mainly for listing items for index
        // servers (HtDig, MnogoSearch, ...)
        // The main difference from 'list' view is that the aliases are created
        // just from item table, so the memory usage is much smaller - you can
        // list all urls, even if there is a lot of items in the slice.
        'urls' => array( "name" => _m("URL listing"),
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
                         "noitem_msg" => _m("HTML code for \"No item found\" message"),
                         "flag" => _m("Add view ID as HTML comment")
                       ),

        // View used in Links module - displays set of link
        'links' => array( "name" => _m("Link listing"),
                          "before" => _m("Top HTML") ,
                          "odd" => _m("Odd Rows") ,
                          "even_odd_differ" => _m("Use different HTML code for even rows") ,
                          "even" => _m("Even Rows") ,
                          "row_delimiter" => _m("Row Delimiter") ,
                          "after" => _m("Bottom HTML") ,
                          "remove_string" => _m("Remove strings") ,
                          "order1" => _m("Sort primary") ,
                          "o1_direction" => " " ,
                          "order2" => _m("Sort secondary") ,
                          "o2_direction" => " " ,
                          "group_by1" => _m("Group by") ,
                          "g1_direction" => " " ,
                          "gb_header" => " " ,
                          "group_title" => _m("Group title format") ,
                          "group_bottom" => _m("Group bottom format") ,
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
                          "noitem_msg" => _m("HTML code for \"No item found\" message"),
                          "flag" => _m("Add view ID as HTML comment")
                        ),

        // View used in Links module - displays set of categories
        'categories' => array( "name" => _m("Category listing"),
                               "before" => _m("Top HTML") ,
                               "odd" => _m("Odd Rows") ,
                               "even_odd_differ" => _m("Use different HTML code for even rows") ,
                               "even" => _m("Even Rows") ,
                               "row_delimiter" => _m("Row Delimiter") ,
                               "after" => _m("Bottom HTML") ,
                               "remove_string" => _m("Remove strings") ,
                               "order1" => _m("Sort primary") ,
                               "o1_direction" => " " ,
                               "order2" => _m("Sort secondary") ,
                               "o2_direction" => " " ,
                               "group_by1" => _m("Group by") ,
                               "g1_direction" => " " ,
                               "gb_header" => " " ,
                               "group_title" => _m("Group title format") ,
                               "group_bottom" => _m("Group bottom format") ,
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
                               "noitem_msg" => _m("HTML code for \"No item found\" message"),
                               "flag" => _m("Add view ID as HTML comment")
                             ),
        // View used for creating input forms
        'inputform' => array( 'name' => _m("Input Form"),
//                         "before" => _m("Top HTML") ,
                         "odd" => _m("New item form template") ,
                         "even_odd_differ" => _m("Use different template for editing") ,
                         "even" => _m("Edit item form template"),
                         "remove_string" => _m("Remove strings"),
//                         "after" => _m("Bottom HTML") ,
                         "flag" => _m("Add view ID as HTML comment")
                        ),
    );
}
/** getViewTypesInfo function
 * @return array
 */
function getViewTypesInfo() {
    // modification - options for modification field of views
    // alias  - which aliases to show
    // order  - 'easy' - show just Ascending/Descending
    // fields - which fields show in selectboxes (slice / 'constant')
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
                                       'aditional' =>array('default'=>'<img src="'.AA_INSTAL_PATH.'images/blank.gif" width=20 height=1 border="0">'),
                                       'aditional2'=>array('default'=>'<input type=button name=sel_ids value="' ._m("Show selected"). '" onClick=showSelectedComments() class="discbuttons">'),
                                       'aditional3'=>array('default'=>'<input type=button name=all_ids value="' ._m("Show all"). '" onClick=showAllComments() class="discbuttons">'),
                                       'aditional4'=>array('default'=>'<input type=button name=add_disc value="' ._m("Add new"). '" onClick=showAddComments() class="discbuttons">'),
                                       'aliases' => 'discus');
    $VIEW_TYPES_INFO['discus2mail'] = array ('aliases' => 'discus2mail');
    $VIEW_TYPES_INFO['seetoo']     = array('modification'=>array('31'=>'related',
                                                             '32'=>'keyword with OR',
                                                             '33'=>'keyword with AND' ),
                                     'aliases' => 'field');
    $VIEW_TYPES_INFO['const']      = array('aliases' => 'const',
                                           'order' => 'easy',
                                           'fields' => 'GetConstantFields');
    $VIEW_TYPES_INFO['urls']       = array('aliases' => 'justids');
    $VIEW_TYPES_INFO['links']      = array('aliases' => 'links',
                                           'order' => 'easy',
                                           'fields' => 'GetLinkFields');
    $VIEW_TYPES_INFO['categories'] = array('aliases' => 'categories',
                                           'order' => 'easy',
                                           'fields' => 'GetCategoryFields');
    $VIEW_TYPES_INFO['rss']        = array('aliases' => 'field');
    $VIEW_TYPES_INFO['calendar']   = array('aliases' => 'field',
        'aliases_additional' => array (
            '_#CV_TST_1' => array ('hlp'=>_m("Calendar: Time stamp at 0:00 of processed cell")),
            '_#CV_TST_2' => array ('hlp'=>_m("Calendar: Time stamp at 24:00 of processed cell")),
            '_#CV_NUM_D' => array ('hlp'=>_m("Calendar: Day in month of processed cell")),
            '_#CV_NUM_M' => array ('hlp'=>_m("Calendar: Month number of processed cell")),
            '_#CV_NUM_Y' => array ('hlp'=>_m("Calendar: Year number of processed cell"))));

    $VIEW_TYPES_INFO['static']     = array('aliases' => 'none');
    $VIEW_TYPES_INFO['script']     = array('aliases' => 'field');
    $VIEW_TYPES_INFO['inputform']  = array('aliases' => '');
    return $VIEW_TYPES_INFO;
}

/** flag in the feedmap table */
define ("FEEDMAP_FLAG_MAP",    0);
define ("FEEDMAP_FLAG_VALUE",  1);
define ("FEEDMAP_FLAG_EMPTY",  2);
define ("FEEDMAP_FLAG_EXTMAP", 3);
define ("FEEDMAP_FLAG_JOIN",   4);
define ("FEEDMAP_FLAG_RSS",    5);

define ("DISCUS_HTML_FORMAT",  1);  // discussion html format flag in slice table

// don't check whether these fields exist (in the conds[] array used by searchform):
$CONDS_NOT_FIELD_NAMES = array(
    "operator"   => true,
    "value"      => true,
    "discussion" => true,
    "valuejoin"  => true );

// used in add slice wizard
define ("NOT_EMAIL_WELCOME", -1);
// CountHit probability: how offen write logged hits to item table
define ("COUNTHIT_PROBABILITY", 1000);

// PagecachePurge probability: how offen remove old entries from pagecache table
define ("PAGECACHEPURGE_PROBABILITY", 1000); // each 1000-th pagecache store event

// how much links check in one run (for links module link checker)
define ("LINKS_VALIDATION_COUNT", 100);

/** constants for manager class used in $manager->show */
define("MGR_ACTIONS",       2);  // show actions
define("MGR_SB_SEARCHROWS", 4);  // show search rows in searchbar
define("MGR_SB_ORDERROWS",  8);  // show order rows in searchbar
define("MGR_SB_BOOKMARKS", 16);  // show bookmarks in searchbar

/** constants for bins, used in new QueryZIDS function */
define("AA_BIN_ACTIVE",   1);
define("AA_BIN_PENDING",  2);
define("AA_BIN_EXPIRED",  4);
define("AA_BIN_APPROVED", 7);   // AA_BIN_ACTIVE|AA_BIN_PENDING|AA_BIN_EXPIRED
define("AA_BIN_HOLDING",  8);
define("AA_BIN_TRASH",   16);
define("AA_BIN_ALL",     31);   // all bins (AA_BIN_ACTIVE|AA_BIN_PENDING|...)

/** HTMLArea constants */
// not supported with new version of HtmlArea
// you need just to change following line in plugins/SpellChecker/spell-check-ui.html
//     <form style="display: none;" action="spell-check-logic.cgi"
// define("AA_HTMLAREA_SPELL_CGISCRIPT",""); // path for spellchecker cgi script (read misc/htmlarea/readme.aa)

/** getFilemanAccesses function
 * @return array
 */
function getFilemanAccesses()
{ return array (
    "0" => _m("Superadmin"),
//    "EDITOR" => _m("Slice Editor"),
    "ADMINISTRATOR" => _m("Slice Administrator"));
}
?>
