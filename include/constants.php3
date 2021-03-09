<?php
/**
 *
 * PHP version 7.2+
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
 * @link      https://www.apc.org/ APC
 *
*/

require_once __DIR__."/mgettext.php3";

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
    $ret = ['name' => $name, 'field' => $field, 'operators' => $operators];
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
 * @return array
 */
function GetAliasDef( $fce, $field='', $hlp='') {
    return ['fce' => $fce, 'param' => $field, 'hlp' => $hlp];
}


class AA_Alias {
    /** @var string  */
    private $alias;
    /** @var string  */
    private $funct;
    /** @var string  */
    private $field_id;
    /** @var string  */
    private $hlp;

    /** AA_Alias function
     * @param string $alias
     * @param string $field_id
     * @param string $funct
     * @param string $hlp = ''
     */
    function __construct($alias, $field_id, $funct, $hlp = '') {
        $this->alias       = '_#'.strtoupper(substr($alias.'________',2,8));  // just make sure the alias is in correct _#SOME_ALS format
        $this->field_id    = $field_id;
        $this->funct       = $funct;
        $this->hlp         = $hlp;
    }

    /** conditional create
     * @param string|null $alias
     * @param string|null $field_id
     * @param string|null $funct
     * @param string|null $hlp
     * @return AA_Alias|null
     */
    public static function factory(?string $alias, ?string $field_id, ?string $funct, ?string $hlp='') {
        if ($alias AND (substr($alias,0,2)=='_#')) {
            return new AA_Alias($alias, $field_id, $funct, $hlp);
        }
        return null;
    }

    /** getArray function
     * @return array
     */
    function getArray() {
        // $fce = ParamImplode(array_merge(array($this->funct),$this->parameters));
        return ['fce' => $this->funct, 'param' => $this->field_id, 'hlp' => $this->hlp];
    }

    /** getAlias function
     * @return string
     */
    function getAlias() {
        return $this->alias;
    }
}

class AA_Aliases {

    /**
     * @var AA_Alias[]
     */

    private $aliases;
    /** AA_Aliases function
     *
     */
    function __construct() {
        $this->aliases = [];
    }

    /** addAlias function
     * @param AA_Alias|null $alias
     */
    function addAlias(?AA_Alias $alias) {
        if ($alias) {
            $this->aliases[] = $alias;
        }
    }

    /** addTextAlias
     * @param $alias_name
     * @param $text
     */
    function addTextAlias($alias_name, $text) {
        $this->addAlias(new AA_Alias($alias_name, "id..............", ParamImplode(['f_p',$text])));
    }
    /** getArray function
     *
     */
    function getArray() {
        $ret = [];
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
*     @todo convert to AA_Module (AA_Module_site, ...)
*/
$MODULES = [
    'S' => [
        'table' => 'slice',
                                'name' => _m('Slice'),
                                'hide_create_module' => 1,
                                'directory' => "admin/",
                                'menu' => "include/menu.php3"
    ],
                  'W' => [
                      'table' => 'site',
                                'name' => 'Site',
                                'show_templates' => 1,  // show list of sites on 'create new' - used as templates
                                'directory' => "modules/site/",
                                'menu' => "modules/site/menu.php3",
                                'language_files' => [
                                    'en-utf8_site_lang.php3' => 'en-utf8_site_lang.php3',
                                    'en_site_lang.php3' => 'en_site_lang.php3',
                                    'es_site_lang.php3' => 'es_site_lang.php3',
                                    'es-utf8_site_lang.php3' => 'es-utf8_site_lang.php3',
                                    'cz_site_lang.php3' => 'cz_site_lang.php3',
                                    'cz-utf8_site_lang.php3' => 'cz-utf8_site_lang.php3',
                                    'sk-utf8_site_lang.php3' => 'sk-utf8_site_lang.php3'
                                ]
                  ],
                  'J' => [
                      'table' => 'jump',
                                'name' => _m('Jump inside AA control panel'),
                                'directory' => "modules/jump/",
                                'menu' => "menu.php3"
                  ],
                  'P' => [
                      'table' => 'polls',
                                'name' => _m('Polls for AA'),
                                'show_templates' => 1,
                                'directory' => "modules/polls/",
                                'menu' => "modules/polls/menu.php3",
                                'language_files' => [
                                    "en_polls_lang.php3" => "en_polls_lang.php3",
                                    "es_polls_lang.php3" => "es_polls_lang.php3",
                                    "es-utf8_polls_lang.php3" => "es-utf8_polls_lang.php3",
                                    "cz_polls_lang.php3" => "cz_polls_lang.php3",
                                    "cz-utf8_polls_lang.php3" => "cz-utf8_polls_lang.php3",
                                    "sk-utf8_polls_lang.php3" => "sk-utf8_polls_lang.php3",
                                ]
                  ],

];


$MODULES['Alerts'] = [
    'table' => 'module',
                            'name' => _m('Alerts'),
                            'directory' => "modules/alerts/",
                            'menu' => "modules/alerts/menu.php3",
                            'letter' => 'A'
];  // letter is used for the modules
                                               // which indentificator is not 1
                                               // letter long (we need 1-letter
                                               // identification for some
                                               // javascripts in um_util.php3
$MODULES['Links'] =  [
    'table' => 'links',
                            'name' => _m('Links'),
                            'show_templates' => 1,
                            'directory' => "modules/links/",
                            'menu' => "modules/links/menu.php3",
                            'letter' => 'L'
];


class AA_Langs {
    protected static $LANGS = [
        'cz-utf8' => ['utf-8'       , 'Cestina (Unicode)'],
        'en-utf8' => ['utf-8'       , 'English (Unicode)'],
        'es-utf8' => ['utf-8'       , 'Espanol (Unicode)'],
        'sk-utf8' => ['utf-8'       , 'Slovencina (Unicode)'],
        'vn'      => ['utf-8'       , 'Vietnamese (Unicode)'],
        'bg'      => ['windows-1251', 'Bulgarian (windows-1251)'],
        'cz'      => ['windows-1250', 'Cestina (windows-1250)'],
        'de'      => ['iso-8859-1'  , 'Deutsch (iso-8859-1)'],
        'en'      => ['iso-8859-1'  , 'English (iso-8859-1)'],
        'es'      => ['iso-8859-1'  , 'Espanol (iso-8859-1)'],
        'fr'      => ['iso-8859-1'  , 'Fran�ais (iso-8859-1)'],
        'hr'      => ['windows-1250', 'Hrvatski (windows-1250)'],
        'ja'      => ['EUC-JP'      , 'Japanian (EUC-JP)'],
        'hu'      => ['iso-8859-2'  , 'Magyar (iso-8859-2)'],
        'ro'      => ['iso-8859-2'  , 'Romanian (iso-8859-2)'],
        'ru'      => ['windows-1251', 'Russian (windows-1251)'],
        'sk'      => ['windows-1250', 'Slovencina (windows-1250)']
    ];

    public static function getFiles($type='news') {
        $arr = [];
        foreach (self::$LANGS as $k => $l) {
            $arr[$k.'_'.$type.'_lang.php3'] = $l[1];
        }
        return $arr;
    }

    public static function getCharsets() {
        $arr = [];
        foreach (self::$LANGS as $k => $l) {
            $arr[$l[0]] = $l[0];
        }
        return $arr;
    }

    public static function getNames() {
        return array_map( function($l) { return $l[1]; }, self::$LANGS);
    }

    /** returns lang code ('cz', 'en', 'en-utf8', 'de',...) from given $lang_file or from default
     */
    public static function getLang($lang_file) {
        $lang_code = substr($lang_file, 0, strpos($lang_file.'_','_'));
        return isset(self::$LANGS[$lang_code]) ? $lang_code : substr(DEFAULT_LANG_INCLUDE, 0, strpos(DEFAULT_LANG_INCLUDE,'_'));
    }

    /** returns charset - like 'windows-1250' from given lang code or current lang
     *  usage: AA_Langs::getCharset();
     */
    public static function getCharset($lang_code=null) {

        if (!$lang_code OR !is_array($ret = self::$LANGS[$lang_code])) {
            $ret = self::$LANGS[get_mgettext_lang()];
        }
        return $ret[0];
    }

    /** translate displayed lang name to standardized language code - cs -> cz
     */
    public static function getLangCode2Name($code) {
        $LANGS = ['cs' => 'cz'];
        return $LANGS[$code] ?: $code;
    }

    /** converts two letter lang code into number used for translation fields in $content4id array
     *  cz -> 78000000, en -> 118000000, ...
     *  you can use any two (smallcaps) letter for language
     */
    public static function getLangName2Num($lang) {
        return strlen($lang) ? AA_Value::MAX_INDEX * ((ord($lang{0}) - 97) * 26 + (ord($lang{1}) - 97 + 1)) : 0;
    }

    /** reverse function to getLangNumber
     *  78000000 -> cz, 78000001 -> cz, 118000000 -> en...
     */
    public static function getLangNum2Name($langnumber) {
        if ($langnumber < AA_Value::MAX_INDEX) {
            return '';
        }
        $num = $langnumber / AA_Value::MAX_INDEX - 1;
        return chr(intval($num / 26) + 97) . chr(($num % 26) + 97);
    }

    /** translate displayed lang name to standardized language code - cz -> cs
     */
    public static function getLangName2Code($lang) {
        $LANGS = ['cz' => 'cs'];
        return $LANGS[$lang] ?: $lang;
    }
}

/** any string - used as parameter to included JS files in order browser can
 *  use newer version of JS libs. Feel free to increase it
 */
define('AA_JS_VERSION', 8);

/** API url used for JS libs updates */
define('JS_UPDATE_API_URL', 'https://data.jsdelivr.com/v1/package/');

/** Standard Reader Management field IDs defined in Reader Minimal Template */
define ("FIELDID_USERNAME",      "headline........");
define ("FIELDID_PASSWORD",      "password........");
define ("FIELDID_EMAIL",         "con_email.......");
define ("FIELDID_MAIL_CONFIRMED","switch..........");
define ("FIELDID_ACCESS_CODE",   "text...........3");
define ("FIELDID_HOWOFTEN",      "alerts1");
define ("FIELDID_FILTERS",       "alerts2");
define ("FIELDID_2FA_SECRET",    "secret..........");

/** field id for slice-defined fields  */
define ("FIELDID_DYNAMIC_ID",    "source..........");

/** Number of items in editor window */
define("EDIT_ITEM_COUNT", 20);

/** Constant used in QueryZids() - defines time steps in query (seconds). We do
 *  not want to ask database with current timestamp, because then each query is
 *  completely different and MySQL can't use its cache.
 */
define("QUERY_DATE_STEP", 1000);

define("ANONYMOUS_EDIT_NOT_ALLOWED", 0);
define("ANONYMOUS_EDIT_ALL", 1);
define("ANONYMOUS_EDIT_ONLY_ANONYMOUS", 2);
define("ANONYMOUS_EDIT_NOT_EDITED_IN_AA", 3);
define("ANONYMOUS_EDIT_PASSWORD", 4);
define("ANONYMOUS_EDIT_HTTP_AUTH", 5);
define("ANONYMOUS_EDIT_CRON", 6);

// MAX_NO_OF_ITEMS_4_GROUP is used with group_n slice.php3 parameter and
// specifies how many items from the begining we have to search
define( 'MAX_NO_OF_ITEMS_4_GROUP', 1000 );

define('NO_PICTURE_URL', AA_INSTAL_URL.'images/blank.gif');

function GetConstantFields() {  // function - we need translate _m() on use (not at include time)
    return [
        'const_short_id'    => GetFieldDef( _m('Short Id'),    'constant.short_id',   'numeric'),
        'const_name'        => GetFieldDef( _m('Name'),        'constant.name',       'text'),
        'const_value'       => GetFieldDef( _m('Value'),       'constant.value',      'text'),
        'const_pri'         => GetFieldDef( _m('Priority'),    'constant.pri',        'numeric'),
        'const_group'       => GetFieldDef( _m('Group'),       'constant.group',      'text'),
        'const_class'       => GetFieldDef( _m('Class'),       'constant.class',      'text'),
    //  'const_counter'     => GetFieldDef( _m('Counter'),     '',                    'numeric'),
        'const_id'          => GetFieldDef( _m('Id'),          'constant.id',         'text'),   // 'id'
        'const_description' => GetFieldDef( _m('Description'), 'constant.description','text'),
        'const_level'       => GetFieldDef( _m('Level'),       'constant.level',      'numeric')
    ];
}

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

/** bites of content_edit field of field table */
define( "FIELD_UNREADABLE",            1 );
// define( "FIELD_..",                 2 );
// define( "FIELD_..",                 4 );

/** relation table flags */
define( "REL_FLAG_MODULE_DEPEND", 1 );  // specifies dependency between site modue and used slices
define( "REL_FLAG_FEED",          2 );  // item is fed using AA feeding (value 2 - compatible with content table)
define( "REL_FLAG_SOURCE",        4 );  // item is derived from other item ({newitem }, ...)

/** view table flags */
define( "VIEW_FLAG_COMMENTS", 1 );    // display HTML comments before and after the view
define( "VIEW_FLAG_LOG", 2 );         // write every use to database
define( "VIEW_FLAG_DISABLE", 4 );     // view is disabled - no content showed


/** inputFeedModes function
 * @return array
 */
function inputFeedModes() {
  return [
      STATE_FEEDABLE               => _m("Feed"),
      STATE_UNFEEDABLE             => _m("Do not feed"),
      STATE_FEEDNOCHANGE           => _m("Feed locked"),
      STATE_FEEDABLE_UPDATE        => _m("Feed & update"),
      STATE_FEEDABLE_UPDATE_LOCKED => _m("Feed & update & lock"),
  ];
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
    $ret = ["validate"=>$validate, "insert"=>$insert, "type"=>$type, "input"=>$input];
    if ( $value ) {
        $ret['value'] =  $value;
    }
    return $ret;
}
/** getViewFields function
 * @return array
 */
function getViewFields() {
    $VIEW_FIELDS = [];
    // se_views.php3 - view field definition
    /* Jakub added a special field "function:function_name" which calls function show_function_name() to show a special form part and store_function_name() to store form data. */
                                                 // $validate, $insert,  $type,   $input,  $value
    $VIEW_FIELDS["name"]            = GetViewFieldDef("text",  "quoted", "text",  "field50");
    $VIEW_FIELDS["before"]          = GetViewFieldDef("text",  "quoted", "text",  "area"   );
    $VIEW_FIELDS["even"]            = GetViewFieldDef("text",  "quoted", "text",  "area"   );
    $VIEW_FIELDS["even_odd_differ"] = GetViewFieldDef("",      "quoted", "bool",  "chbox"  );
    $VIEW_FIELDS["odd"]             = GetViewFieldDef("text",  "quoted", "text",  "areabig");
    $VIEW_FIELDS["row_delimiter"]   = GetViewFieldDef("text",  "quoted", "text",  "area");
    $VIEW_FIELDS["after"]           = GetViewFieldDef("text",  "quoted", "text",  "area"   );
    $VIEW_FIELDS["group_by1"]       = GetViewFieldDef("text",  "quoted", "text",  "group"  );
    $VIEW_FIELDS["g1_direction"]    = GetViewFieldDef("",      "quoted", "number","none"   );
    $VIEW_FIELDS["gb_header"]       = GetViewFieldDef("",      "quoted", "number","none"   );
    $VIEW_FIELDS["group_by2"]       = GetViewFieldDef("text",  "quoted", "text",  "orderal");
    $VIEW_FIELDS["g2_direction"]    = GetViewFieldDef("",      "quoted", "number","none"  );
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
    //$VIEW_FIELDS["flag"]            = GetViewFieldDef("",      "quoted", "bool",   "bitflag" );
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
    $VIEW_FIELDS["field3"]          = GetViewFieldDef("text",  "quoted", "text",   "vid");
    $VIEW_FIELDS["calendar_type"]   = GetViewFieldDef("text",  "quoted", "text",   "select", ["mon"=>_m("Month List"),"mon_table"=>_m("Month Table")]);
    return $VIEW_FIELDS;
}

function getViewGroupFunctions() {
    return [
        '0'   => _m("Whole text"),
        '1'   => _m("1st letter"),
        '2'   => '2 ' . _m("letters"),
        '3'   => '3 ' . _m("letters"),
        '4'   => '4 ' . _m("letters"),
        '5'   => '5 ' . _m("letters"),
        '6'   => '6 ' . _m("letters"),
        '7'   => '7 ' . _m("letters"),
        '8'   => '8 ' . _m("letters"),
        '9'   => '9 ' . _m("letters"),
        '127' => _m("all before ") . '~'   // we could add 126, 125,...
    ];
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
    $field3def = [
        "label" => _m("vid"),
                         "help" => _m("now possible to use non numeric vid - like: photo_list and usage as {view:photo_list:...} or ..?vid=photo_list... Use only smallcaps - [a-z_].")
    ];
    return   [
        'list' => [
                         "field3" => $field3def,
                         "name" => _m("Item listing"),
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
                         "group_by2" => _m("Sort alias"),
                         "g2_direction" => " " ,
                         "group_by1" => _m("Group by") ,
                         "g1_direction" => " " ,
                         "gb_header" => " " ,
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
                         "scroller" => [
                             "label" => _m("Accept site module paging - {xpage}"),
                             "help" => _m("if checked, no need to pass page-{xpage} param to the view")
                         ],
                         "noitem_msg" => [
                                 "label" => _m("HTML code for \"No item found\" message"),
                                 "help" => _m("use {#} if you do not want print anything")
                         ],
                         "flag" => _m("Add view ID as HTML comment")
    //                     "aditional" => _m("Additional") );
        ],

        'full' => [
                         "field3" => $field3def,
                         'name' => _m("Fulltext view"),
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
        ],

        'discus' => [
                           "field3" => $field3def,
                           'name' => _m("Discussion"),
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
                           "aditional6" => [
                               "label" => _m("E-mail template"),
                               "input" => "field",
                               "help" => _m("Number of e-mail template used for posting new comments to users")
                           ],
                           "flag" => _m("Allow HTML tags in the comments")
        ],

        // discussion to mail
        'disc2mail' => [
                              "field3" => $field3def,
                              'name' => _m("Discussion To Mail"),
                              "aditional" => _m("From: (email header)"),
                              "aditional2" => _m("Reply-To:"),
                              "aditional3" => _m("Errors-To:"),
                              "aditional4" => _m("Sender:"),
                              "aditional5" => _m("Mail Subject:"),
                              "even" => _m("Mail Body:")
        ],

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

        'const' => [
                          "field3" => $field3def,
                          'name' => _m("View of Constants"),
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
        ],

        'rss' => [
                        "field3" => $field3def,
                        'name' => _m("RSS exchange"),
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
        ],

        'static' => [
                           "field3" => $field3def,
                           'name' => _m("Static page"),
                           "odd" => _m("HTML code"),
                           "flag" => _m("Add view ID as HTML comment")
        ],

        // for javascript list of items
        'javascript' => [
                               "field3" => $field3def,
                               'name' => _m("Javascript item exchange"),
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
        ],

        'calendar' => [
                             "field3" => $field3def,
                             'name' => _m("Calendar"),
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
        ],

        // this view uses also "aditonal" and "aditional3" for the "Group by"
        // radio buttons and for the sort[] box, see se_view.php3

        'digest' => [
                           "field3" => $field3def,
                           "name" => _m("Alerts Selection Set"),
                           "function:digest_filters" => "",
                           "aditional2" => [
                               "label" => _m("Fulltext URL"),
                               "input" => "field",
                               "help" => _m("Link to the .shtml page used
                                 to create headline links.")
                           ],
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
        ],

        // View used for listing of ursl - mainly for listing items for index
        // servers (HtDig, MnogoSearch, ...)
        // The main difference from 'list' view is that the aliases are created
        // just from item table, so the memory usage is much smaller - you can
        // list all urls, even if there is a lot of items in the slice.
        'urls' => [
                         "field3" => $field3def,
                         "name" => _m("URL listing"),
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
        ],

        // View used in Links module - displays set of link
        'links' => [
                          "field3" => $field3def,
                          "name" => _m("Link listing"),
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
        ],

        // View used in Links module - displays set of categories
        'categories' => [
                               "field3" => $field3def,
                               "name" => _m("Category listing"),
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
        ],
        // View used for creating input forms
        'inputform' => [
                         "field3" => $field3def,
                         'name' => _m("Input Form"),
//                       "before" => _m("Top HTML") ,
                         "odd" => _m("New item form template") ,
                         "even_odd_differ" => _m("Use different template for editing") ,
                         "even" => _m("Edit item form template"),
                         "remove_string" => _m("Remove strings"),
//                       "after" => _m("Bottom HTML") ,
                         "flag" => _m("Add view ID as HTML comment")
        ],
    ];
}
/** getViewTypesInfo function
 * @return array
 */
function getViewTypesInfo() {
    $VIEW_TYPES_INFO = [];
    // modification - options for modification field of views
    // alias  - which aliases to show
    // order  - 'easy' - show just Ascending/Descending
    // fields - which fields show in selectboxes (slice / 'constant')
    $VIEW_TYPES_INFO['list'] = [
        'modification' => [
            '1' => 'search',
            '2' => 'parameter',
            '3' => 'statistic',
            '4' => 'all in thread',
            '5' => 'related',
            '6' => 'keyword related',
        ],
        'aliases'      => 'field',
    ];
    $VIEW_TYPES_INFO['full'] = [
        'modification' => [
            '11' => 'newest',
            '12' => 'newest with condition',
            '13' => 'oldest with condition',
            '14' => 'id',
            '15' => 'parameter',
        ],
        'aliases'      => 'field',
    ];
    $VIEW_TYPES_INFO['digest'] = ['aliases' => 'field'];
    $VIEW_TYPES_INFO['discus'] = [
        'modification' => [
            '21' => 'timeorder',
            '22' => 'reverse timeorder',
            '23' => 'thread',
        ],
        'aditional'    => ['default' => '<img src="' . AA_INSTAL_PATH . 'images/blank.gif" width=20 height=1 border="0">'],
        'aditional2'   => ['default' => '<input type=button name=sel_ids value="' . _m("Show selected") . '" onClick=showSelectedComments() class="discbuttons">'],
        'aditional3'   => ['default' => '<input type=button name=all_ids value="' . _m("Show all") . '" onClick=showAllComments() class="discbuttons">'],
        'aditional4'   => ['default' => '<input type=button name=add_disc value="' . _m("Add new") . '" onClick=showAddComments() class="discbuttons">'],
        'aliases'      => 'discus',
    ];
    $VIEW_TYPES_INFO['disc2mail'] = ['aliases' => 'disc2mail'];
    $VIEW_TYPES_INFO['seetoo'] = [
        'modification' => [
            '31' => 'related',
            '32' => 'keyword with OR',
            '33' => 'keyword with AND',
        ],
        'aliases'      => 'field',
    ];
    $VIEW_TYPES_INFO['const'] = [
        'aliases' => 'const',
        'order'   => 'easy',
        'fields'  => 'GetConstantFields',
    ];
    $VIEW_TYPES_INFO['urls'] = ['aliases' => 'justids'];
    $VIEW_TYPES_INFO['links'] = [
        'aliases' => 'links',
        'order'   => 'easy',
        'fields'  => 'GetLinkFields',
    ];
    $VIEW_TYPES_INFO['categories'] = [
        'aliases' => 'categories',
        'order'   => 'easy',
        'fields'  => 'GetCategoryFields',
    ];
    $VIEW_TYPES_INFO['rss'] = ['aliases' => 'field'];
    $VIEW_TYPES_INFO['calendar'] = [
        'aliases'            => 'field',
        'aliases_additional' => [
            '_#CV_TST_1' => ['hlp' => _m("Calendar: Time stamp at 0:00 of processed cell")],
            '_#CV_TST_2' => ['hlp' => _m("Calendar: Time stamp at 24:00 of processed cell")],
            '_#CV_NUM_D' => ['hlp' => _m("Calendar: Day in month of processed cell")],
            '_#CV_NUM_M' => ['hlp' => _m("Calendar: Month number of processed cell")],
            '_#CV_NUM_Y' => ['hlp' => _m("Calendar: Year number of processed cell")],
        ],
    ];

    $VIEW_TYPES_INFO['static'] = ['aliases' => 'none'];
    $VIEW_TYPES_INFO['javascript'] = ['aliases' => 'field'];
    $VIEW_TYPES_INFO['inputform'] = ['aliases' => ''];
    return $VIEW_TYPES_INFO;
}

/** flag in the feedmap table */
define ('FEEDMAP_FLAG_MAP',    0);
define ('FEEDMAP_FLAG_VALUE',  1);
define ('FEEDMAP_FLAG_EMPTY',  2);
define ('FEEDMAP_FLAG_EXTMAP', 3);
define ('FEEDMAP_FLAG_JOIN',   4);
define ('FEEDMAP_FLAG_RSS',    5);

/** flag in the slice table */
define ('DISCUS_HTML_FORMAT',  1);  // discussion html format flag in slice table
define ('DISCUS_ADD_DISABLED', 2);  // disbles filldisc adding on new comments
define ('SLICE_ALLOW_EXPIRED_CONTENT', 4);  // when we reference expired item, the content could be shown on public pages
define ('SLICE_ALLOW_PENDING_CONTENT', 8);  // pending items, when referenced, will be wisible - this allows you also to see preview of pending item

// don't check whether these fields exist (in the conds[] array used by searchform):
$CONDS_NOT_FIELD_NAMES = [
    "operator"   => true,
    "value"      => true,
    "discussion" => true,
    "valuejoin"  => true
];

// used in add slice wizard
define ("NOT_EMAIL_WELCOME", -1);

// CountHit probability: how offen check, if the hit counter is planed
define ("COUNTHIT_PROBABILITY", 10);

// AA\Later\PagecachePurge probability: how offen remove old entries from pagecache table
define ("PAGECACHEPURGE_PROBABILITY", 1000); // each 1000-th pagecache store event

// how much links check in one run (for links module link checker)
define ("LINKS_VALIDATION_COUNT", 100);

/** constants for manager class used in $manager->show */
define("MGR_ITEMS",          1);  // 1 reserved - used to pass 'show' minimal (0 means default)
define("MGR_ACTIONS",        2);  // show actions
define("MGR_SB_SEARCHROWS",  4);  // show search rows in searchbar
define("MGR_SB_ORDERROWS",   8);  // show order rows in searchbar
define("MGR_SB_BOOKMARKS",  16);  // show bookmarks in searchbar
define("MGR_SB_ALLTEXT"  ,  32);  // show '-- any text field --' option in search fieds
define("MGR_SB_ALLNUM"   ,  64);  // show '-- any numeric field --' option in search fieds
define("MGR_ALL",          127);  // show all

/** constants for bins, used in new QueryZIDS function */
define("AA_BIN_ACTIVE",   1);
define("AA_BIN_PENDING",  2);
define('AA_BIN_ACT_PEND', AA_BIN_ACTIVE|AA_BIN_PENDING );
define("AA_BIN_EXPIRED",  4);
define("AA_BIN_APPROVED", 7);   // AA_BIN_ACTIVE|AA_BIN_PENDING|AA_BIN_EXPIRED
define("AA_BIN_HOLDING",  8);
define("AA_BIN_TRASH",   16);
define("AA_BIN_ALL",     31);   // all bins (AA_BIN_ACTIVE|AA_BIN_PENDING|...)

/** status codes - in itemContent */
define("SC_ACTIVE",      1);
define("SC_HOLDING_BIN", 2);
define("SC_NO_BIN",      4);

/** getFilemanAccesses function
 * @return array
 */
function getFilemanAccesses()
{ return [
    "0" => _m("Superadmin"),
//    "EDITOR" => _m("Slice Editor"),
    "ADMINISTRATOR" => _m("Slice Administrator")
];
}
