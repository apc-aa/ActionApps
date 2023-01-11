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

require_once __DIR__ . "/mgettext.php";

//
// Used constants. Do not edit if you are not developer.
//

/** GetFieldDef function
 * Field definition shortcut (used in constants.php for CONSTANT_FILEDS)
 * @param $name
 * @param $field
 * @param $operators  = 'text'
 * @param $table      = false
 * @param $search_pri = false
 * @param $order_pri  = false
 * @return array
 */
function GetFieldDef($name, $field, $operators = 'text', $table = false, $search_pri = false, $order_pri = false) {
    $ret = ['name' => $name, 'field' => $field, 'operators' => $operators];
    if ($table) {
        $ret['table'] = $table;
    }
    if ($search_pri) {
        $ret['search_pri'] = $search_pri;  // searchbar priority (false = "do not show in searchbar")
    }
    if ($order_pri) {
        $ret['order_pri'] = $order_pri;   // orderbar  priority (false = "do not show in orderbar")
    }
    return $ret;
}

/** GetAliasDef function
 * Alias definition shortcut
 * @param $fce
 * @param $field = ''
 * @param $hlp   = ''
 * @return array
 */
function GetAliasDef($fce, $field = '', $hlp = '') {
    return ['fce' => $fce, 'param' => $field, 'hlp' => $hlp];
}


class AA_Alias {
    /** @var string */
    private $alias;
    /** @var string */
    private $funct;
    /** @var string */
    private $field_id;
    /** @var string */
    private $hlp;

    /** AA_Alias function
     * @param string $alias
     * @param string $funct
     * @param string $field_id
     * @param string $hlp = ''
     */
    function __construct($alias, $funct, $field_id, $hlp = '') {
        $this->alias    = '_#' . strtoupper(substr($alias . '________', 2, 8));  // just make sure the alias is in correct _#SOME_ALS format
        $this->field_id = $field_id;
        $this->funct    = $funct;
        $this->hlp      = $hlp;
    }

    /** conditional create
     * @param string|null $alias
     * @param string|null $funct
     * @param string|null $field_id
     * @param string|null $hlp
     * @return AA_Alias|null
     */
    public static function factory(?string $alias, ?string $funct, ?string $field_id, ?string $hlp = '') {
        if ($alias and (substr($alias, 0, 2) == '_#')) {
            return new AA_Alias($alias, $funct, $field_id, $hlp);
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

    /** get alias name
     * @return string
     */
    function getAlias() {
        return $this->alias;
    }

    /**
     * @return string
     */
    public function getField(): string {
        return $this->field_id;
    }
}


/**   */
interface LazyloaderInterface {
    /** loads object */
    public function lazyLoad();
}

/**
 * @usage in constructor or like (new AA_Aliases())->addLoader('lazy_aliases', new AA_Aliasgenerator_Slice($slice_id));
 */
trait LazyloadTrait {
    private $lazy_loaders = [];

    final public function addLoader(string $var, LazyloaderInterface $loader) {
        $this->lazy_loaders[$var] = $loader;
        return $this;
    }

    final public function lazyGet($var) {
        if (array_key_exists($var, $this->lazy_loaders)) {
            $this->$var = $this->lazy_loaders[$var]->lazyLoad();
            unset($this->lazy_loaders[$var]);   // loader is used once and then removed, so we will not use it next time
        }
    }
}

class AA_Aliases {

    use LazyloadTrait;

    /**
     * @var AA_Alias[] - using LazyloadTrait so always called $this->lazyGet('lazy_aliases') before use
     */
    private $lazy_aliases = [];

    /** AA_Aliases function
     *
     */
    function __construct($loader = null) {
        if (is_object($loader)) {
            $this->addLoader('lazy_aliases', $loader);
        }
    }

    /** addAlias function
     * @param AA_Alias|null $alias
     */
    function addAlias(?AA_Alias $alias): AA_Aliases {
        $this->lazyGet('lazy_aliases');
        if ($alias) {
            $this->lazy_aliases[$alias->getAlias()] = $alias;
        }
        return $this;
    }

    /** adds another AA_Aliases */
    public function add(AA_Aliases $aliases): AA_Aliases {
        $this->lazyGet('lazy_aliases');
        $this->lazy_aliases = array_merge($this->lazy_aliases, $aliases->getAliases());
        return $this;
    }

    /** addTextAlias
     * @param $alias_name
     * @param $text
     */
    function addTextAlias($alias_name, $text): AA_Aliases {
        $this->addAlias(new AA_Alias($alias_name, ParamImplode(['f_p', $text]), "id.............."));
        return $this;
    }

    /** addFromArray adds aliases from old deprecated array
     * @param $alias_name
     * @param $text
     * @deprecated
     */
    function addFromArray(array $alias_arr): AA_Aliases {
        foreach ($alias_arr as $aid => $als) {
            $this->addAlias(new AA_Alias($aid, $als['fce'], $als['param'], $als['hlp']));
        }
        return $this;
    }

    /** getArray function
     * @deprecated convert the AA code to use AA_Aliases
     */
    public function getArray(): array {
        $this->lazyGet('lazy_aliases');
        $ret = [];
        foreach ($this->lazy_aliases as $alias) {
            $ret[$alias->getAlias()] = $alias->getArray();
        }
        return $ret;
    }

    function getAlias(string $name): ?AA_Alias {
        $this->lazyGet('lazy_aliases');
        return $this->lazy_aliases[$name];
    }

    function getAliases() {
        $this->lazyGet('lazy_aliases');
        return $this->lazy_aliases;
    }

    /** get alias names - ['_#HEADLINE', ...] */
    function getNames(): array {
        $this->lazyGet('lazy_aliases');
        return array_keys($this->lazy_aliases);
    }

    /** get alias names for specified field - ['_#HEADLINE', ...] */
    function getNamesForField(string $fld): array {
        $this->lazyGet('lazy_aliases');
        $ret = [];
        foreach ($this->lazy_aliases as $name => $a) {
            if ($a->getField() == $fld) {
                $ret[] = $name;
            }
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
 * @todo convert to AA_Module (AA_Module_site, ...)
 */
$MODULES = [
    'S' => [
        'table'              => 'slice',
        'name'               => _m('Slice'),
        'hide_create_module' => 1,
        'directory'          => "admin/",
    ],
    'W' => [
        'table'          => 'site',
        'name'           => 'Site',
        'show_templates' => 1,  // show list of sites on 'create new' - used as templates
        'directory'      => "modules/site/",
        'language_files' => [
            'en-utf8_site_lang.php' => 'en-utf8_site_lang.php',
            'en_site_lang.php'      => 'en_site_lang.php',
            'es_site_lang.php'      => 'es_site_lang.php',
            'es-utf8_site_lang.php' => 'es-utf8_site_lang.php',
            'cz_site_lang.php'      => 'cz_site_lang.php',
            'cz-utf8_site_lang.php' => 'cz-utf8_site_lang.php',
            'sk-utf8_site_lang.php' => 'sk-utf8_site_lang.php',
        ],
    ],
    'J' => [
        'table'     => 'jump',
        'name'      => _m('Jump inside AA control panel'),
        'directory' => "modules/jump/",
    ],
    'P' => [
        'table'          => 'polls',
        'name'           => _m('Polls for AA'),
        'show_templates' => 1,
        'directory'      => "modules/polls/",
        'language_files' => [
            "en_polls_lang.php"      => "en_polls_lang.php",
            "es_polls_lang.php"      => "es_polls_lang.php",
            "es-utf8_polls_lang.php" => "es-utf8_polls_lang.php",
            "cz_polls_lang.php"      => "cz_polls_lang.php",
            "cz-utf8_polls_lang.php" => "cz-utf8_polls_lang.php",
            "sk-utf8_polls_lang.php" => "sk-utf8_polls_lang.php",
        ],
    ],

];


$MODULES['Alerts'] = [
    'table'     => 'module',
    'name'      => _m('Alerts'),
    'directory' => "modules/alerts/",
    'letter'    => 'A',
];  // letter is used for the modules
// which indentificator is not 1
// letter long (we need 1-letter
// identification for some
// javascripts in um_util.php
$MODULES['Links'] = [
    'table'          => 'links',
    'name'           => _m('Links'),
    'show_templates' => 1,
    'directory'      => "modules/links/",
    'letter'         => 'L',
];


class AA_Langs {
    protected static $LANGS = [
        'cz-utf8' => ['utf-8', 'Cestina (Unicode)'],
        'en-utf8' => ['utf-8', 'English (Unicode)'],
        'es-utf8' => ['utf-8', 'Espanol (Unicode)'],
        'sk-utf8' => ['utf-8', 'Slovencina (Unicode)'],
        'vn'      => ['utf-8', 'Vietnamese (Unicode)'],
        'bg'      => ['windows-1251', 'Bulgarian (windows-1251)'],
        'cz'      => ['windows-1250', 'Cestina (windows-1250)'],
        'de'      => ['iso-8859-1', 'Deutsch (iso-8859-1)'],
        'en'      => ['iso-8859-1', 'English (iso-8859-1)'],
        'es'      => ['iso-8859-1', 'Espanol (iso-8859-1)'],
        'fr'      => ['iso-8859-1', 'Fran�ais (iso-8859-1)'],
        'hr'      => ['windows-1250', 'Hrvatski (windows-1250)'],
        'ja'      => ['EUC-JP', 'Japanian (EUC-JP)'],
        'hu'      => ['iso-8859-2', 'Magyar (iso-8859-2)'],
        'ro'      => ['iso-8859-2', 'Romanian (iso-8859-2)'],
        'ru'      => ['windows-1251', 'Russian (windows-1251)'],
        'sk'      => ['windows-1250', 'Slovencina (windows-1250)'],
    ];

    public static function getFiles($type = 'news') {
        $arr = [];
        foreach (self::$LANGS as $k => $l) {
            $arr[$k . '_' . $type . '_lang.php'] = $l[1];
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
        return array_map(function ($l) { return $l[1]; }, self::$LANGS);
    }

    /** returns lang code ('cz', 'en', 'en-utf8', 'de',...) from given $lang_file or from default
     */
    public static function getLang($lang_file) {
        $lang_code = substr($lang_file, 0, strpos($lang_file . '_', '_'));
        return isset(self::$LANGS[$lang_code]) ? $lang_code : substr(DEFAULT_LANG_INCLUDE, 0, strpos(DEFAULT_LANG_INCLUDE, '_'));
    }

    /** returns charset - like 'windows-1250' from given lang code or current lang
     *  usage: AA_Langs::getCharset();
     */
    public static function getCharset($lang_code = null) {
        if (!$lang_code or !is_array($ret = self::$LANGS[$lang_code])) {
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
        $lang = strtolower($lang);
        $ret  = strlen($lang) ? AA_Value::MAX_INDEX * ((ord($lang[0]) - 97) * 26 + (ord($lang[1]) - 97 + 1)) : 0;
        return max(0, $ret);
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

    /** is current (or provided) $charset Unicode?
     */
    public static function isUTF(string $charset = ''): bool {
        return (stripos(($charset ?: AA_Langs::getCharset()), 'utf-8') !== false);
    }
}

/** any string - used as parameter to included JS files in order browser can
 *  use newer version of JS libs. Feel free to increase it
 */
const AA_JS_VERSION = 11;

/** API url used for JS libs updates */
const JS_UPDATE_API_URL = 'https://data.jsdelivr.com/v1/package/';

/** Standard Reader Management field IDs defined in Reader Minimal Template */
const FIELDID_USERNAME       = "headline........";
const FIELDID_PASSWORD       = "password........";
const FIELDID_EMAIL          = "con_email.......";
const FIELDID_MAIL_CONFIRMED = "switch..........";
const FIELDID_ACCESS_CODE    = "text...........3";
const FIELDID_HOWOFTEN       = "alerts1";
const FIELDID_FILTERS        = "alerts2";
const FIELDID_2FA_SECRET     = "secret..........";

/** field id for slice-defined fields  */
const FIELDID_DYNAMIC_ID = "source..........";

/** Number of items in editor window */
const EDIT_ITEM_COUNT = 20;

/** Constant used in QueryZids() - defines time steps in query (seconds). We do
 *  not want to ask database with current timestamp, because then each query is
 *  completely different and MySQL can't use its cache.
 */
const QUERY_DATE_STEP = 1000;

const ANONYMOUS_EDIT_NOT_ALLOWED      = 0;
const ANONYMOUS_EDIT_ALL              = 1;
const ANONYMOUS_EDIT_ONLY_ANONYMOUS   = 2;
const ANONYMOUS_EDIT_NOT_EDITED_IN_AA = 3;
const ANONYMOUS_EDIT_PASSWORD         = 4;
const ANONYMOUS_EDIT_HTTP_AUTH        = 5;
const ANONYMOUS_EDIT_CRON             = 6;

// MAX_NO_OF_ITEMS_4_GROUP is used with group_n slice.php3 parameter and
// specifies how many items from the begining we have to search
const MAX_NO_OF_ITEMS_4_GROUP = 1000;

const NO_PICTURE_URL = AA_INSTAL_URL . 'images/blank.gif';

function GetConstantFields() {  // function - we need translate _m() on use (not at include time)
    return [
        'const_short_id'    => GetFieldDef(_m('Short Id'), 'constant.short_id', 'numeric'),
        'const_name'        => GetFieldDef(_m('Name'), 'constant.name', 'text'),
        'const_value'       => GetFieldDef(_m('Value'), 'constant.value', 'text'),
        'const_pri'         => GetFieldDef(_m('Priority'), 'constant.pri', 'numeric'),
        'const_group'       => GetFieldDef(_m('Group'), 'constant.group', 'text'),
        'const_class'       => GetFieldDef(_m('Class'), 'constant.class', 'text'),
        //  'const_counter'     => GetFieldDef( _m('Counter'),     '',                    'numeric'),
        'const_id'          => GetFieldDef(_m('Id'), 'constant.id', 'text'),   // 'id'
        'const_description' => GetFieldDef(_m('Description'), 'constant.description', 'text'),
        'const_level'       => GetFieldDef(_m('Level'), 'constant.level', 'numeric'),
    ];
}

/** content table flags */
const FLAG_HTML        = 1;   // content is in HTML
const FLAG_FEED        = 2;   // item is fed
const FLAG_FREEZE      = 4;   // content can't be changed
const FLAG_OFFLINE     = 8;   // off-line filled
const FLAG_UPDATE      = 16;   // content should be updated if source is changed (after feeding)
const FLAG_TEXT_STORED = 64;   // value is stored in the text field (and not number field of content table) (added 05/15/2004)

/** item table flags (numbers - just to be compatible with content table) */
const ITEM_FLAG_FEED               = 2;   // item is fed
const ITEM_FLAG_OFFLINE            = 8;   // off-line filled or imported from file
const ITEM_FLAG_ANONYMOUS_EDITABLE = 32;    // anonymously added and thus anonymously editable (reset on every use of itemedit.php3)

/** states of feed field of field table */
const STATE_FEEDABLE               = 0;
const STATE_UNFEEDABLE             = 1;
const STATE_FEEDNOCHANGE           = 2;
const STATE_FEEDABLE_UPDATE        = 3;
const STATE_FEEDABLE_UPDATE_LOCKED = 4;

/** bites of content_edit field of field table */
const FIELD_UNREADABLE = 1;
// define( "FIELD_..",                 2 );
// define( "FIELD_..",                 4 );

/** relation table flags */
const REL_FLAG_MODULE_DEPEND = 1;  // specifies dependency between site modue and used slices
const REL_FLAG_FEED          = 2;  // item is fed using AA feeding (value 2 - compatible with content table)
const REL_FLAG_SOURCE        = 4;  // item is derived from other item ({newitem }, ...)

/** view table flags */
const VIEW_FLAG_COMMENTS = 1;    // display HTML comments before and after the view
const VIEW_FLAG_LOG      = 2;         // write every use to database
const VIEW_FLAG_DISABLE  = 4;     // view is disabled - no content showed


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
function GetViewFieldDef($validate, $insert, $type, $input, $value = false) {
    $ret = ["validate" => $validate, "insert" => $insert, "type" => $type, "input" => $input];
    if ($value) {
        $ret['value'] = $value;
    }
    return $ret;
}

/** getViewFields function
 * @return array
 */
function getViewFields() {
    $VIEW_FIELDS = [];
    // se_views.php - view field definition
    /* Jakub added a special field "function:function_name" which calls function show_function_name() to show a special form part and store_function_name() to store form data. */
    // $validate, $insert,  $type,   $input,  $value
    $VIEW_FIELDS["name"]            = GetViewFieldDef("text", "quoted", "text", "field50");
    $VIEW_FIELDS["before"]          = GetViewFieldDef("text", "quoted", "text", "area");
    $VIEW_FIELDS["even"]            = GetViewFieldDef("text", "quoted", "text", "area");
    $VIEW_FIELDS["even_odd_differ"] = GetViewFieldDef("", "quoted", "bool", "chbox");
    $VIEW_FIELDS["odd"]             = GetViewFieldDef("text", "quoted", "text", "areabig");
    $VIEW_FIELDS["row_delimiter"]   = GetViewFieldDef("text", "quoted", "text", "area");
    $VIEW_FIELDS["after"]           = GetViewFieldDef("text", "quoted", "text", "area");
    $VIEW_FIELDS["group_by1"]       = GetViewFieldDef("text", "quoted", "text", "group");
    $VIEW_FIELDS["g1_direction"]    = GetViewFieldDef("", "quoted", "number", "none");
    $VIEW_FIELDS["gb_header"]       = GetViewFieldDef("", "quoted", "number", "none");
    $VIEW_FIELDS["group_by2"]       = GetViewFieldDef("text", "quoted", "text", "orderal");
    $VIEW_FIELDS["g2_direction"]    = GetViewFieldDef("", "quoted", "number", "none");
    $VIEW_FIELDS["group_title"]     = GetViewFieldDef("text", "quoted", "text", "area");
    $VIEW_FIELDS["group_bottom"]    = GetViewFieldDef("text", "quoted", "text", "area");
    $VIEW_FIELDS["remove_string"]   = GetViewFieldDef("text", "quoted", "text", "area");
    $VIEW_FIELDS["modification"]    = GetViewFieldDef("text", "quoted", "text", "seltype");
    $VIEW_FIELDS["parameter"]       = GetViewFieldDef("text", "quoted", "text", "selgrp");
    $VIEW_FIELDS["img1"]            = GetViewFieldDef("text", "quoted", "text", "field");
    $VIEW_FIELDS["img2"]            = GetViewFieldDef("text", "quoted", "text", "field");
    $VIEW_FIELDS["img3"]            = GetViewFieldDef("text", "quoted", "text", "field");
    $VIEW_FIELDS["img4"]            = GetViewFieldDef("text", "quoted", "text", "field");
    $VIEW_FIELDS["order1"]          = GetViewFieldDef("text", "quoted", "text", "order");
    $VIEW_FIELDS["o1_direction"]    = GetViewFieldDef("", "quoted", "number", "none");
    $VIEW_FIELDS["order2"]          = GetViewFieldDef("text", "quoted", "text", "order");
    $VIEW_FIELDS["o2_direction"]    = GetViewFieldDef("", "quoted", "number", "none");
    $VIEW_FIELDS["selected_item"]   = GetViewFieldDef("text", "quoted", "text", "area");
    $VIEW_FIELDS["cond1field"]      = GetViewFieldDef("text", "quoted", "text", "cond");
    $VIEW_FIELDS["cond1op"]         = GetViewFieldDef("text", "quoted", "text", "none");
    $VIEW_FIELDS["cond1cond"]       = GetViewFieldDef("text", "quoted", "text", "none");
    $VIEW_FIELDS["cond2field"]      = GetViewFieldDef("text", "quoted", "text", "cond");
    $VIEW_FIELDS["cond2op"]         = GetViewFieldDef("text", "quoted", "text", "none");
    $VIEW_FIELDS["cond2cond"]       = GetViewFieldDef("text", "quoted", "text", "none");
    $VIEW_FIELDS["cond3field"]      = GetViewFieldDef("text", "quoted", "text", "cond");
    $VIEW_FIELDS["cond3op"]         = GetViewFieldDef("text", "quoted", "text", "none");
    $VIEW_FIELDS["cond3cond"]       = GetViewFieldDef("text", "quoted", "text", "none");
    $VIEW_FIELDS["listlen"]         = GetViewFieldDef("number", "quoted", "text", "field");
    $VIEW_FIELDS["flag"]            = GetViewFieldDef("", "quoted", "bool", "chbox");
    //$VIEW_FIELDS["flag"]            = GetViewFieldDef("",      "quoted", "bool",   "bitflag" );
    $VIEW_FIELDS["scroller"]      = GetViewFieldDef("", "quoted", "bool", "chbox");
    $VIEW_FIELDS["aditional"]     = GetViewFieldDef("text", "quoted", "text", "area");
    $VIEW_FIELDS["aditional2"]    = GetViewFieldDef("text", "quoted", "text", "area");
    $VIEW_FIELDS["aditional3"]    = GetViewFieldDef("text", "quoted", "text", "area");
    $VIEW_FIELDS["aditional4"]    = GetViewFieldDef("text", "quoted", "text", "area");
    $VIEW_FIELDS["aditional5"]    = GetViewFieldDef("text", "quoted", "text", "area");
    $VIEW_FIELDS["aditional6"]    = GetViewFieldDef("text", "quoted", "text", "area");
    $VIEW_FIELDS["noitem_msg"]    = GetViewFieldDef("text", "quoted", "text", "area");
    $VIEW_FIELDS["field1"]        = GetViewFieldDef("text", "quoted", "text", "selfld");
    $VIEW_FIELDS["field2"]        = GetViewFieldDef("text", "quoted", "text", "selfld");
    $VIEW_FIELDS["field3"]        = GetViewFieldDef("text", "quoted", "text", "vid");
    $VIEW_FIELDS["calendar_type"] = GetViewFieldDef("text", "quoted", "text", "select", ["mon"       => _m("Month List"),
                                                                                         "mon_table" => _m("Month Table"),
    ]);
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
 * @return array
 */
function getViewTypes() {
    $field3def = [
        "label" => _m("vid"),
        "help"  => _m("now possible to use non numeric vid - like: photo_list and usage as {view:photo_list:...} or ..?vid=photo_list... Use only smallcaps - [a-z_]."),
    ];
    return [
        'list' => [
            "field3"          => $field3def,
            "name"            => _m("Item listing"),
            "before"          => _m("Top HTML"),
            "odd"             => _m("Odd Rows"),
            "even_odd_differ" => _m("Use different HTML code for even rows"),
            "even"            => _m("Even Rows"),
            "row_delimiter"   => _m("Row Delimiter"),
            "after"           => _m("Bottom HTML"),
            "remove_string"   => _m("Remove strings"),
            // TODO                     "modification" => _m("Type") ,
            //                     "parameter" => _m("Parameter") ,
            //                     "img1" => _m("View image 1") ,
            //                     "img2" => _m("View image 2") ,
            //                     "img3" => _m("View image 3") ,
            //                     "img4" => _m("View image 4") ,
            "order1"          => _m("Sort primary"),
            "o1_direction"    => " ",
            "order2"          => _m("Sort secondary"),
            "o2_direction"    => " ",
            "group_by2"       => _m("Sort alias"),
            "g2_direction"    => " ",
            "group_by1"       => _m("Group by"),
            "g1_direction"    => " ",
            "gb_header"       => " ",
            "group_title"     => _m("Group title format"),
            "group_bottom"    => _m("Group bottom format"),
            //                     "selected_item" => _m("HTML for Selected") ,
            "cond1field"      => _m("Condition 1"),
            "cond1op"         => " ",
            "cond1cond"       => " ",
            "cond2field"      => _m("Condition 2"),
            "cond2op"         => " ",
            "cond2cond"       => " ",
            "cond3field"      => _m("Condition 3"),
            "cond3op"         => " ",
            "cond3cond"       => " ",
            "listlen"         => _m("Listing length"),
            "scroller"        => [
                "label" => _m("Accept site module paging - {xpage}"),
                "help"  => _m("if checked, no need to pass page-{xpage} param to the view"),
            ],
            "noitem_msg"      => [
                "label" => _m("HTML code for \"No item found\" message"),
                "help"  => _m("use {#} if you do not want print anything"),
            ],
            "flag"            => _m("Add view ID as HTML comment")
            //                     "aditional" => _m("Additional") );
        ],

        'full' => [
            "field3"        => $field3def,
            'name'          => _m("Fulltext view"),
            "before"        => _m("Top HTML"),
            "odd"           => _m("Odd Rows"),
            "after"         => _m("Bottom HTML"),
            "remove_string" => _m("Remove strings"),
            "cond1field"    => _m("Condition 1"),
            "cond1op"       => " ",
            "cond1cond"     => " ",
            "cond2field"    => _m("Condition 2"),
            "cond2op"       => " ",
            "cond2cond"     => " ",
            "cond3field"    => _m("Condition 3"),
            "cond3op"       => " ",
            "cond3cond"     => " ",
            "noitem_msg"    => _m("HTML code for \"No item found\" message"),
            "flag"          => _m("Add view ID as HTML comment"),
        ],

        'discus'    => [
            "field3"          => $field3def,
            'name'            => _m("Discussion"),
            "before"          => _m("Top HTML"),
            "odd"             => _m("HTML code for index view of the comment"),
            "after"           => _m("Bottom HTML"),
            "aditional2"      => _m("HTML code for \"Show selected\" button"),
            "aditional3"      => _m("HTML code for \"Show all\" button"),
            "aditional4"      => _m("HTML code for \"Add\" button"),
            "even_odd_differ" => _m("Show images"),
            "modification"    => _m("Order by"),
            "img1"            => _m("View image 1"),
            "img2"            => _m("View image 2"),
            "img3"            => _m("View image 3"),
            "img4"            => _m("View image 4"),
            "even"            => _m("HTML code for fulltext view of the comment"),
            "aditional"       => _m("HTML code for space before comment"),
            "remove_string"   => _m("HTML code of the form for posting comment"),
            "aditional6"      => [
                "label" => _m("E-mail template"),
                "input" => "field",
                "help"  => _m("Number of e-mail template used for posting new comments to users"),
            ],
            "flag"            => _m("Allow HTML tags in the comments"),
        ],

        // discussion to mail
        'disc2mail' => [
            "field3"     => $field3def,
            'name'       => _m("Discussion To Mail"),
            "aditional"  => _m("From: (email header)"),
            "aditional2" => _m("Reply-To:"),
            "aditional3" => _m("Errors-To:"),
            "aditional4" => _m("Sender:"),
            "aditional5" => _m("Mail Subject:"),
            "even"       => _m("Mail Body:"),
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
            "field3"          => $field3def,
            'name'            => _m("View of Constants"),
            "before"          => _m("Top HTML"),
            "odd"             => _m("Odd Rows"),
            "even"            => _m("Even Rows"),
            "row_delimiter"   => _m("Row Delimiter"),
            "after"           => _m("Bottom HTML"),
            "remove_string"   => _m("Remove strings"),
            "parameter"       => _m("Constant Group"),
            "order1"          => _m("Sort primary"),
            "o1_direction"    => " ",
            "order2"          => _m("Sort secondary"),
            "o2_direction"    => " ",
            "group_by1"       => _m("Group by"),
            "g1_direction"    => " ",
            "gb_header"       => " ",
            "group_title"     => _m("Group title format"),
            "group_bottom"    => _m("Group bottom format"),
            "cond1field"      => _m("Condition 1"),
            "cond1op"         => " ",
            "cond1cond"       => " ",
            "cond2field"      => _m("Condition 2"),
            "cond2op"         => " ",
            "cond2cond"       => " ",
            "cond3field"      => _m("Condition 3"),
            "cond3op"         => " ",
            "cond3cond"       => " ",
            "listlen"         => _m("Listing length"),
            "noitem_msg"      => _m("HTML code for \"No item found\" message"),
            "even_odd_differ" => _m("Use different HTML code for even rows"),
            "flag"            => _m("Add view ID as HTML comment"),
        ],

        'rss' => [
            "field3"       => $field3def,
            'name'         => _m("RSS exchange"),
            "before"       => _m("Top HTML"),
            "odd"          => _m("Odd Rows"),
            "after"        => _m("Bottom HTML"),
            "order1"       => _m("Sort primary"),
            "o1_direction" => " ",
            "order2"       => _m("Sort secondary"),
            "o2_direction" => " ",
            "cond1field"   => _m("Condition 1"),
            "cond1op"      => " ",
            "cond1cond"    => " ",
            "cond2field"   => _m("Condition 2"),
            "cond2op"      => " ",
            "cond2cond"    => " ",
            "cond3field"   => _m("Condition 3"),
            "cond3op"      => " ",
            "cond3cond"    => " ",
            "listlen"      => _m("Listing length"),
            "noitem_msg"   => _m("HTML code for \"No item found\" message"),
        ],

        'static'     => [
            "field3" => $field3def,
            'name'   => _m("Static page"),
            "odd"    => _m("HTML code"),
            "flag"   => _m("Add view ID as HTML comment"),
        ],

        // for javascript list of items
        'javascript' => [
            "field3"       => $field3def,
            'name'         => _m("Javascript item exchange"),
            "before"       => _m("Top HTML"),
            "odd"          => _m("Odd Rows"),
            "after"        => _m("Bottom HTML"),
            "order1"       => _m("Sort primary"),
            "o1_direction" => " ",
            "order2"       => _m("Sort secondary"),
            "o2_direction" => " ",
            "cond1field"   => _m("Condition 1"),
            "cond1op"      => " ",
            "cond1cond"    => " ",
            "cond2field"   => _m("Condition 2"),
            "cond2op"      => " ",
            "cond2cond"    => " ",
            "cond3field"   => _m("Condition 3"),
            "cond3op"      => " ",
            "cond3cond"    => " ",
            "listlen"      => _m("Listing length"),
            "noitem_msg"   => _m("HTML code for \"No item found\" message"),
        ],

        'calendar' => [
            "field3"          => $field3def,
            'name'            => _m("Calendar"),
            "calendar_type"   => _m("Calendar Type"),
            "before"          => _m("Top HTML"),
            "aditional3"      => _m("Additional attribs to the TD event tag"),
            "odd"             => _m("Event format"),
            "after"           => _m("Bottom HTML"),
            "remove_string"   => _m("Remove strings"),
            "order1"          => _m("Sort primary"),
            "o1_direction"    => " ",
            "order2"          => _m("Sort secondary"),
            "o2_direction"    => " ",
            "field1"          => _m("Start date field"),
            "field2"          => _m("End date field"),
            "group_title"     => _m("Day cell top format"),
            "group_bottom"    => _m("Day cell bottom format"),
            "even_odd_differ" => _m("Use other header for empty cells"),
            "aditional"       => _m("Empty day cell top format"),
            "aditional2"      => _m("Empty day cell bottom format"),
            "cond1field"      => _m("Condition 1"),
            "cond1op"         => " ",
            "cond1cond"       => " ",
            "cond2field"      => _m("Condition 2"),
            "cond2op"         => " ",
            "cond2cond"       => " ",
            "cond3field"      => _m("Condition 3"),
            "cond3op"         => " ",
            "cond3cond"       => " ",
            "listlen"         => _m("Listing length"),
            "noitem_msg"      => _m("HTML code for \"No item found\" message"),
            "flag"            => _m("Add view ID as HTML comment"),
        ],

        // this view uses also "aditonal" and "aditional3" for the "Group by"
        // radio buttons and for the sort[] box, see se_view.php

        'digest'     => [
            "field3"                  => $field3def,
            "name"                    => _m("Alerts Selection Set"),
            "function:digest_filters" => "",
            "aditional2"              => [
                "label" => _m("Fulltext URL"),
                "input" => "field",
                "help"  => _m("Link to the .shtml page used
                                 to create headline links."),
            ],
            "before"                  => _m("Top HTML"),
            "odd"                     => _m("Odd Rows"),
            "even_odd_differ"         => _m("Use different HTML code for even rows"),
            "even"                    => _m("Even Rows"),
            "row_delimiter"           => _m("Row Delimiter"),
            "after"                   => _m("Bottom HTML"),
            "remove_string"           => _m("Remove strings"),
            "order1"                  => _m("Sort primary"),
            "o1_direction"            => " ",
            "order2"                  => _m("Sort secondary"),
            "o2_direction"            => " ",
            "group_by1"               => _m("Group by"),
            "g1_direction"            => " ",
            "gb_header"               => " ",
            "group_title"             => _m("Group title format"),
            "group_bottom"            => _m("Group bottom format"),
            "listlen"                 => _m("Max number of items"),
            "noitem_msg"              => _m("HTML code for \"No item found\" message"),
            "flag"                    => _m("Add view ID as HTML comment"),
        ],

        // View used for listing of ursl - mainly for listing items for index
        // servers (HtDig, MnogoSearch, ...)
        // The main difference from 'list' view is that the aliases are created
        // just from item table, so the memory usage is much smaller - you can
        // list all urls, even if there is a lot of items in the slice.
        'urls'       => [
            "field3"        => $field3def,
            "name"          => _m("URL listing"),
            "before"        => _m("Top HTML"),
            "odd"           => _m("Row HTML"),
            "after"         => _m("Bottom HTML"),
            "remove_string" => _m("Remove strings"),
            "order1"        => _m("Sort primary"),
            "o1_direction"  => " ",
            "order2"        => _m("Sort secondary"),
            "o2_direction"  => " ",
            "cond1field"    => _m("Condition 1"),
            "cond1op"       => " ",
            "cond1cond"     => " ",
            "cond2field"    => _m("Condition 2"),
            "cond2op"       => " ",
            "cond2cond"     => " ",
            "cond3field"    => _m("Condition 3"),
            "cond3op"       => " ",
            "cond3cond"     => " ",
            "listlen"       => _m("Listing length"),
            "noitem_msg"    => _m("HTML code for \"No item found\" message"),
            "flag"          => _m("Add view ID as HTML comment"),
        ],

        // View used in Links module - displays set of link
        'links'      => [
            "field3"          => $field3def,
            "name"            => _m("Link listing"),
            "before"          => _m("Top HTML"),
            "odd"             => _m("Odd Rows"),
            "even_odd_differ" => _m("Use different HTML code for even rows"),
            "even"            => _m("Even Rows"),
            "row_delimiter"   => _m("Row Delimiter"),
            "after"           => _m("Bottom HTML"),
            "remove_string"   => _m("Remove strings"),
            "order1"          => _m("Sort primary"),
            "o1_direction"    => " ",
            "order2"          => _m("Sort secondary"),
            "o2_direction"    => " ",
            "group_by1"       => _m("Group by"),
            "g1_direction"    => " ",
            "gb_header"       => " ",
            "group_title"     => _m("Group title format"),
            "group_bottom"    => _m("Group bottom format"),
            "cond1field"      => _m("Condition 1"),
            "cond1op"         => " ",
            "cond1cond"       => " ",
            "cond2field"      => _m("Condition 2"),
            "cond2op"         => " ",
            "cond2cond"       => " ",
            "cond3field"      => _m("Condition 3"),
            "cond3op"         => " ",
            "cond3cond"       => " ",
            "listlen"         => _m("Listing length"),
            "noitem_msg"      => _m("HTML code for \"No item found\" message"),
            "flag"            => _m("Add view ID as HTML comment"),
        ],

        // View used in Links module - displays set of categories
        'categories' => [
            "field3"          => $field3def,
            "name"            => _m("Category listing"),
            "before"          => _m("Top HTML"),
            "odd"             => _m("Odd Rows"),
            "even_odd_differ" => _m("Use different HTML code for even rows"),
            "even"            => _m("Even Rows"),
            "row_delimiter"   => _m("Row Delimiter"),
            "after"           => _m("Bottom HTML"),
            "remove_string"   => _m("Remove strings"),
            "order1"          => _m("Sort primary"),
            "o1_direction"    => " ",
            "order2"          => _m("Sort secondary"),
            "o2_direction"    => " ",
            "group_by1"       => _m("Group by"),
            "g1_direction"    => " ",
            "gb_header"       => " ",
            "group_title"     => _m("Group title format"),
            "group_bottom"    => _m("Group bottom format"),
            "cond1field"      => _m("Condition 1"),
            "cond1op"         => " ",
            "cond1cond"       => " ",
            "cond2field"      => _m("Condition 2"),
            "cond2op"         => " ",
            "cond2cond"       => " ",
            "cond3field"      => _m("Condition 3"),
            "cond3op"         => " ",
            "cond3cond"       => " ",
            "listlen"         => _m("Listing length"),
            "noitem_msg"      => _m("HTML code for \"No item found\" message"),
            "flag"            => _m("Add view ID as HTML comment"),
        ],
        // View used for creating input forms
        'inputform'  => [
            "field3"          => $field3def,
            'name'            => _m("Input Form"),
            //                       "before" => _m("Top HTML") ,
            "odd"             => _m("New item form template"),
            "even_odd_differ" => _m("Use different template for editing"),
            "even"            => _m("Edit item form template"),
            "remove_string"   => _m("Remove strings"),
            //                       "after" => _m("Bottom HTML") ,
            "flag"            => _m("Add view ID as HTML comment"),
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
    $VIEW_TYPES_INFO['list']       = [
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
    $VIEW_TYPES_INFO['full']       = [
        'modification' => [
            '11' => 'newest',
            '12' => 'newest with condition',
            '13' => 'oldest with condition',
            '14' => 'id',
            '15' => 'parameter',
        ],
        'aliases'      => 'field',
    ];
    $VIEW_TYPES_INFO['digest']     = ['aliases' => 'field'];
    $VIEW_TYPES_INFO['discus']     = [
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
    $VIEW_TYPES_INFO['disc2mail']  = ['aliases' => 'disc2mail'];
    $VIEW_TYPES_INFO['seetoo']     = [
        'modification' => [
            '31' => 'related',
            '32' => 'keyword with OR',
            '33' => 'keyword with AND',
        ],
        'aliases'      => 'field',
    ];
    $VIEW_TYPES_INFO['const']      = [
        'aliases' => 'const',
        'order'   => 'easy',
        'fields'  => 'GetConstantFields',
    ];
    $VIEW_TYPES_INFO['urls']       = ['aliases' => 'justids'];
    $VIEW_TYPES_INFO['links']      = [
        'aliases' => 'links',
        'order'   => 'easy',
        'fields'  => 'GetLinkFields',
    ];
    $VIEW_TYPES_INFO['categories'] = [
        'aliases' => 'categories',
        'order'   => 'easy',
        'fields'  => 'GetCategoryFields',
    ];
    $VIEW_TYPES_INFO['rss']        = ['aliases' => 'field'];
    $VIEW_TYPES_INFO['calendar']   = [
        'aliases'            => 'field',
        'aliases_additional' => (new AA_Aliases())
            ->addAlias(new AA_Alias('_#CV_TST_1', '', '', _m("Calendar: Time stamp at 0:00 of processed cell")))
            ->addAlias(new AA_Alias('_#CV_TST_2', '', '', _m("Calendar: Time stamp at 24:00 of processed cell")))
            ->addAlias(new AA_Alias('_#CV_NUM_D', '', '', _m("Calendar: Day in month of processed cell")))
            ->addAlias(new AA_Alias('_#CV_NUM_M', '', '', _m("Calendar: Month number of processed cell")))
            ->addAlias(new AA_Alias('_#CV_NUM_Y', '', '', _m("Calendar: Year number of processed cell"))),
    ];

    $VIEW_TYPES_INFO['static']     = ['aliases' => 'none'];
    $VIEW_TYPES_INFO['javascript'] = ['aliases' => 'field'];
    $VIEW_TYPES_INFO['inputform']  = ['aliases' => ''];
    return $VIEW_TYPES_INFO;
}

/** flag in the feedmap table */
const FEEDMAP_FLAG_MAP    = 0;
const FEEDMAP_FLAG_VALUE  = 1;
const FEEDMAP_FLAG_EMPTY  = 2;
const FEEDMAP_FLAG_EXTMAP = 3;
const FEEDMAP_FLAG_JOIN   = 4;
const FEEDMAP_FLAG_RSS    = 5;

/** flag in the slice table */
const DISCUS_HTML_FORMAT          = 1;  // discussion html format flag in slice table
const DISCUS_ADD_DISABLED         = 2;  // disbles filldisc adding on new comments
const SLICE_ALLOW_EXPIRED_CONTENT = 4;  // when we reference expired item, the content could be shown on public pages
const SLICE_ALLOW_PENDING_CONTENT = 8;  // pending items, when referenced, will be wisible - this allows you also to see preview of pending item

// used in add slice wizard
const NOT_EMAIL_WELCOME = -1;

// CountHit probability: how offen check, if the hit counter is planed
const COUNTHIT_PROBABILITY = 10;

// AA\Later\PagecachePurge probability: how offen remove old entries from pagecache table
const PAGECACHEPURGE_PROBABILITY = 1000; // each 1000-th pagecache store event

// how much links check in one run (for links module link checker)
const LINKS_VALIDATION_COUNT = 100;

/** constants for manager class used in $manager->show */
const MGR_ITEMS         = 1;  // 1 reserved - used to pass 'show' minimal (0 means default)
const MGR_ACTIONS       = 2;  // show actions
const MGR_SB_SEARCHROWS = 4;  // show search rows in searchbar
const MGR_SB_ORDERROWS  = 8;  // show order rows in searchbar
const MGR_SB_BOOKMARKS  = 16;  // show bookmarks in searchbar
const MGR_SB_ALLTEXT    = 32;  // show '-- any text field --' option in search fieds
const MGR_SB_ALLNUM     = 64;  // show '-- any numeric field --' option in search fieds
const MGR_ALL           = 127;  // show all

/** constants for bins, used in new QueryZIDS function */
const AA_BIN_ACTIVE   = 1;
const AA_BIN_PENDING  = 2;
const AA_BIN_ACT_PEND = AA_BIN_ACTIVE | AA_BIN_PENDING;
const AA_BIN_EXPIRED  = 4;
const AA_BIN_APPROVED = 7;   // AA_BIN_ACTIVE|AA_BIN_PENDING|AA_BIN_EXPIRED
const AA_BIN_HOLDING  = 8;
const AA_BIN_TRASH    = 16;
const AA_BIN_ALL      = 31;   // all bins (AA_BIN_ACTIVE|AA_BIN_PENDING|...)

/** status codes - in itemContent */
const SC_ACTIVE      = 1;
const SC_HOLDING_BIN = 2;
const SC_NO_BIN      = 4;

/** getFilemanAccesses function
 * @return array
 */
function getFilemanAccesses() {
    return [
        "0"             => _m("Superadmin"),
        //    "EDITOR" => _m("Slice Editor"),
        "ADMINISTRATOR" => _m("Slice Administrator"),
    ];
}
