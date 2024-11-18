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
        $this->alias    = self::ensureAlias($alias);  // just make sure the alias is in correct _#SOME_ALS format
        $this->field_id = (string)$field_id;
        $this->funct    = (string)$funct;
        $this->hlp      = (string)$hlp;
    }

    /** conditional create
     * @param string|null $alias
     * @param string|null $funct
     * @param string|null $field_id
     * @param string|null $hlp
     * @return AA_Alias|null
     */
    public static function factory(?string $alias, ?string $funct, ?string $field_id, ?string $hlp = '') {
        if ($alias and (str_starts_with($alias, '_#'))) {
            return new AA_Alias($alias, $funct, $field_id, $hlp);
        }
        return null;
    }

    /** just make sure the alias is in correct _#SOME_ALS format */
    public static function ensureAlias(string $alias): string {
        return '_#' . strtoupper(substr($alias . '________', 2, 8));
    }

    /** test if the text looks like alias (_#ABCDEFGH)
     * @param string $text
     * @param bool   $raw   if true, we want to pass also raw alias name (ABCDEFGH)
     * @return string alias with _# or empty string
     */
    public static function isAliasLike(string $text, bool $raw = false): string {
        if (preg_match("/^_#[A-Z0-9_#]{8}$/", $text) === 1) {;   // pattern="_#[A-Z0-9_#]{8} - used also in se_field.php UI"
            return $text;
        }
        return ($raw AND (preg_match("/^[A-Z0-9_#]{8}$/", $text) === 1)) ? "_#$text" : '';
    }

    /** getArray function
     * @return array
     */
    function getArray(): array {
        // $fce = ParamImplode(array_merge(array($this->funct),$this->parameters));
        return ['fce' => $this->funct, 'param' => $this->field_id, 'hlp' => $this->hlp];
    }

    /** get alias name
     * @return string
     */
    function getAlias(): string {
        return $this->alias;
    }

    /**
     * @return string
     */
    public function getField(): string {
        return $this->field_id;
    }

    /**
     * @return string
     */
    public function getHelp(): string {
        return $this->hlp;
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

class AA_Aliases implements Iterator, ArrayAccess, Countable {

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

    /**
     * @param AA_Property[] $properties
     * @return $this
     */
    function addFromProperties(array $properties): AA_Aliases {
        foreach ($properties as $prop_id => $property) {
            if ($property->isObject()) {
                continue;
            }
            // @todo - make alias field type aware
            $this->addAlias(new AA_Alias('_#' . substr(str_pad(strtoupper($prop_id), 8, '_'), 0, 8), 'f_h:, ', $prop_id, $prop_id));
        }
        return $this;
    }

    /** addFromArray adds aliases from old deprecated array
     * @param array $alias_arr
     * @return AA_Aliases
     * @deprecated
     */
    function addFromArray(array $alias_arr): AA_Aliases {
        foreach ($alias_arr as $aid => $als) {
            $this->addAlias(new AA_Alias($aid, $als['fce'], $als['param'], $als['hlp']));
        }
        return $this;
    }

    /** @deprecated convert the AA code to use AA_Aliases
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

    /** Iterator interface */
    public function rewind(): void   { $this->lazyGet('lazy_aliases'); reset($this->lazy_aliases); }
    public function current(): mixed { $this->lazyGet('lazy_aliases'); return current($this->lazy_aliases); }
    public function key(): mixed     { $this->lazyGet('lazy_aliases'); return key($this->lazy_aliases); }
    public function next(): void     { $this->lazyGet('lazy_aliases'); next($this->lazy_aliases); }
    public function valid(): bool    { $this->lazyGet('lazy_aliases'); return (current($this->lazy_aliases) !== false); }

    /** Countable interface - Returns number of values  */
    public function count(): int     { $this->lazyGet('lazy_aliases'); return count($this->lazy_aliases); }

    /** ArrayAccess interface */
    public function offsetSet($offset, $value): void { $this->lazyGet('lazy_aliases');$this->lazy_aliases[$offset] = $value; }
    public function offsetExists($offset): bool      { $this->lazyGet('lazy_aliases'); return isset($this->lazy_aliases[$offset]); }
    public function offsetUnset($offset): void       { $this->lazyGet('lazy_aliases'); unset($this->lazy_aliases[$offset]); }
    public function offsetGet($offset): mixed        { $this->lazyGet('lazy_aliases'); return $this->lazy_aliases[$offset] ?? null; }
}

// There we can mention $FIELD_TYPES, but they are not defined in this file,
// but in database as special slice with id 'AA_Core_Fields..'

// Field types - each field in slice is one of this type.
// The types are defined APC wide for easy item interchanging between APC nodes
// (on the other hand, new type can be added just by placing new fileld
// in database table fields as for 'AA_Core_Fields..' slice).


/** any string - used as parameter to included JS files in order browser can
 *  use newer version of JS libs. Feel free to increase it
 */
const AA_JS_VERSION = 16;

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

const MAX_TIME_NEVER = 2145826800;

const ANONYMOUS_EDIT_NOT_ALLOWED      = 0;
const ANONYMOUS_EDIT_ALL              = 1;
const ANONYMOUS_EDIT_ONLY_ANONYMOUS   = 2;
const ANONYMOUS_EDIT_NOT_EDITED_IN_AA = 3;
const ANONYMOUS_EDIT_PASSWORD         = 4;
const ANONYMOUS_EDIT_HTTP_AUTH        = 5;
const ANONYMOUS_EDIT_CRON             = 6;

// MAX_NO_OF_ITEMS_4_GROUP is used with group_n slice.php parameter and
// specifies how many items from the beginning we have to search
const MAX_NO_OF_ITEMS_4_GROUP = 1000;

const NO_PICTURE_URL  = AA_INSTAL_URL . 'images/blank.gif';
const NO_PICTURE_DATA = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';   // smallest 1px transparent gif

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
const ITEM_FLAG_ANONYMOUS_EDITABLE = 32;    // anonymously added and thus anonymously editable (reset on every use of itemedit.php)

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
const REL_FLAG_MODULE_DEPEND = 1;  // specifies dependency between site module and used slices
const REL_FLAG_FEED          = 2;  // item is fed using AA feeding (value 2 - compatible with content table)
const REL_FLAG_SOURCE        = 4;  // item is derived from other item ({newitem }, ...)

/** view table flags */
const VIEW_FLAG_COMMENTS = 1;    // display HTML comments before and after the view
const VIEW_FLAG_DISABLE  = 2;    // view is disabled - no content showed
const VIEW_FLAG_LOG      = 4;    // write every use to database


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
 * @param $type
 * @param $input
 * @param $value = false
 * @return array
 */
function GetViewFieldDef($validate, $type, $input, $value = false) {
    $ret = ["validate" => $validate, "type" => $type, "input" => $input];
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
    $VIEW_FIELDS["name"]            = GetViewFieldDef("text", "text", "field50");
    $VIEW_FIELDS["before"]          = GetViewFieldDef("text", "text", "area");
    $VIEW_FIELDS["even"]            = GetViewFieldDef("text", "text", "area");
    $VIEW_FIELDS["even_odd_differ"] = GetViewFieldDef("", "bool", "chbox");
    $VIEW_FIELDS["odd"]             = GetViewFieldDef("text", "text", "areabig");
    $VIEW_FIELDS["row_delimiter"]   = GetViewFieldDef("text", "text", "area");
    $VIEW_FIELDS["after"]           = GetViewFieldDef("text", "text", "area");
    $VIEW_FIELDS["group_by1"]       = GetViewFieldDef("text", "text", "group");
    $VIEW_FIELDS["g1_direction"]    = GetViewFieldDef("", "number", "none");
    $VIEW_FIELDS["gb_header"]       = GetViewFieldDef("", "number", "none");
    $VIEW_FIELDS["group_by2"]       = GetViewFieldDef("text", "text", "orderal");
    $VIEW_FIELDS["g2_direction"]    = GetViewFieldDef("", "number", "none");
    $VIEW_FIELDS["group_title"]     = GetViewFieldDef("text", "text", "area");
    $VIEW_FIELDS["group_bottom"]    = GetViewFieldDef("text", "text", "area");
    $VIEW_FIELDS["remove_string"]   = GetViewFieldDef("text", "text", "area");
    $VIEW_FIELDS["modification"]    = GetViewFieldDef("text", "text", "seltype");
    $VIEW_FIELDS["parameter"]       = GetViewFieldDef("text", "text", "selgrp");
    $VIEW_FIELDS["img1"]            = GetViewFieldDef("text", "text", "field");
    $VIEW_FIELDS["img2"]            = GetViewFieldDef("text", "text", "field");
    $VIEW_FIELDS["img3"]            = GetViewFieldDef("text", "text", "field");
    $VIEW_FIELDS["img4"]            = GetViewFieldDef("text", "text", "field");
    $VIEW_FIELDS["order1"]          = GetViewFieldDef("text", "text", "order");
    $VIEW_FIELDS["o1_direction"]    = GetViewFieldDef("", "number", "none");
    $VIEW_FIELDS["order2"]          = GetViewFieldDef("text", "text", "order");
    $VIEW_FIELDS["o2_direction"]    = GetViewFieldDef("", "number", "none");
    $VIEW_FIELDS["selected_item"]   = GetViewFieldDef("text", "text", "area");
    $VIEW_FIELDS["cond1field"]      = GetViewFieldDef("text", "text", "cond");
    $VIEW_FIELDS["cond1op"]         = GetViewFieldDef("text", "text", "none");
    $VIEW_FIELDS["cond1cond"]       = GetViewFieldDef("text", "text", "none");
    $VIEW_FIELDS["cond2field"]      = GetViewFieldDef("text", "text", "cond");
    $VIEW_FIELDS["cond2op"]         = GetViewFieldDef("text", "text", "none");
    $VIEW_FIELDS["cond2cond"]       = GetViewFieldDef("text", "text", "none");
    $VIEW_FIELDS["cond3field"]      = GetViewFieldDef("text", "text", "cond");
    $VIEW_FIELDS["cond3op"]         = GetViewFieldDef("text", "text", "none");
    $VIEW_FIELDS["cond3cond"]       = GetViewFieldDef("text", "text", "none");
    $VIEW_FIELDS["listlen"]         = GetViewFieldDef("number", "text", "field");
    //$VIEW_FIELDS["flag"]          = GetViewFieldDef("", "bool", "chbox");
    $VIEW_FIELDS["flag0"]           = GetViewFieldDef("", "bool",   "bitflag" ); // Add view ID as HTML comment / Allow HTML tags in the comments
    $VIEW_FIELDS["flag1"]           = GetViewFieldDef("", "bool",   "bitflag" ); // Disabled view
    $VIEW_FIELDS["flag2"]           = GetViewFieldDef("", "bool",   "bitflag" ); // Log view usage
    $VIEW_FIELDS["flag3"]           = GetViewFieldDef("", "bool",   "bitflag" ); // not used
    $VIEW_FIELDS["flag4"]           = GetViewFieldDef("", "bool",   "bitflag" ); // not used
    $VIEW_FIELDS["flag5"]           = GetViewFieldDef("", "bool",   "bitflag" ); // not used
    $VIEW_FIELDS["flag6"]           = GetViewFieldDef("", "bool",   "bitflag" ); // not used
    $VIEW_FIELDS["flag7"]           = GetViewFieldDef("", "bool",   "bitflag" ); // not used
    $VIEW_FIELDS["scroller"]        = GetViewFieldDef("", "number", "rioscrl" );
    $VIEW_FIELDS["aditional"]       = GetViewFieldDef("text", "text", "area");
    $VIEW_FIELDS["aditional2"]      = GetViewFieldDef("text", "text", "area");
    $VIEW_FIELDS["aditional3"]      = GetViewFieldDef("text", "text", "area");
    $VIEW_FIELDS["aditional4"]      = GetViewFieldDef("text", "text", "area");
    $VIEW_FIELDS["aditional5"]      = GetViewFieldDef("text", "text", "area");
    $VIEW_FIELDS["aditional6"]      = GetViewFieldDef("text", "text", "area");
    $VIEW_FIELDS["noitem_msg"]      = GetViewFieldDef("text", "text", "area");
    $VIEW_FIELDS["field1"]          = GetViewFieldDef("text", "text", "selfld");
    $VIEW_FIELDS["field2"]          = GetViewFieldDef("text", "text", "selfld");
    $VIEW_FIELDS["field3"]          = GetViewFieldDef("text", "text", "vid");
    $VIEW_FIELDS["calendar_type"]   = GetViewFieldDef("text", "text", "select", [
        "mon"       => _m("Month List"),
        "mon_table" => _m("Month Table"),
    ]);
    return $VIEW_FIELDS;
}

function getViewGroupFunctions(): array
{
    return [
        0   => _m("Whole text"),
        1   => _m("1st letter"),
        2   => '2 ' . _m("letters"),
        3   => '3 ' . _m("letters"),
        4   => '4 ' . _m("letters"),
        5   => '5 ' . _m("letters"),
        6   => '6 ' . _m("letters"),
        7   => '7 ' . _m("letters"),
        8   => '8 ' . _m("letters"),
        9   => '9 ' . _m("letters"),
        127 => _m("all before ") . '~'   // we could add 126, 125,...
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
function getViewTypes(): array
{
    $field3def = [
        "label" => _m("vid"),
        "help"  => _m("now possible to use non numeric vid - like: photo_list and usage as {view:photo_list:...} or ..?vid=photo_list... Use only smallcaps - [a-z_]."),
    ];

    $common = [
        "flag1" => _m("Disabled view"),
        "flag2" => [
                     "label" => _m("Log view usage"),
                     "help"  => _m("Be careful with this. It really logs avery view use! Good for debugging and checking, if disabled view is not used anymore..."),
              ]];

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
                "help"  => _m("if 'Accept {xpage}', no need to pass page-{xpage} param to the view<br>if 'Use infinite...' the invisible scroller is added to the view and the content is loaded by AJAX by observing the scroll position"),
            ],
            "noitem_msg"      => [
                "label" => _m("HTML code for \"No item found\" message"),
                "help"  => _m("use {#} if you do not want print anything"),
            ],
            "flag0"            => _m("Add view ID as HTML comment"),
                //                     "aditional" => _m("Additional") );
        ] + $common,

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
            "flag0"         => _m("Add view ID as HTML comment"),
        ] + $common,

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
            "flag0"            => _m("Allow HTML tags in the comments"),  // different meaning than in other views (historical reasons)
        ] + $common,

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
            "flag0"           => _m("Add view ID as HTML comment"),
        ] + $common,


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
            "flag0"  => _m("Add view ID as HTML comment"),
        ] + $common,

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
        ] + $common,

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
            "flag0"           => _m("Add view ID as HTML comment"),
        ] + $common,

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
            "flag0"                   => _m("Add view ID as HTML comment"),
        ] + $common,

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
            "flag0"         => _m("Add view ID as HTML comment"),
        ] + $common,

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
            "flag0"           => _m("Add view ID as HTML comment"),
        ] + $common,

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
            "flag0"           => _m("Add view ID as HTML comment"),
        ] + $common,

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
            "flag0"           => _m("Add view ID as HTML comment"),
        ] + $common,
    ];
}

/** getViewTypesInfo function
 * @param string $type - view type
 */
function getViewTypesInfo(string $type): array
{
    // modification - options for modification field of views
    // alias  - which aliases to show
    // order  - 'easy' - show just Ascending/Descending
    // fields - which fields show in selectboxes (slice / 'constant')
    return match ($type) {
        'list' => [
            'modification' => [
                1 => 'search',
                2 => 'parameter',
                3 => 'statistic',
                4 => 'all in thread',
                5 => 'related',
                6 => 'keyword related',
            ],
            'aliases' => 'field',
        ],
        'full' => [
            'modification' => [
                11 => 'newest',
                12 => 'newest with condition',
                13 => 'oldest with condition',
                14 => 'id',
                15 => 'parameter',
            ],
            'aliases' => 'field',
        ],
        'digest', 'rss', 'javascript' => [
            'aliases' => 'field',
        ],
        'discus' => [
            'modification' => [
                21 => 'timeorder',
                22 => 'reverse timeorder',
                23 => 'thread',
            ],
            'aditional'    => ['default' => '<img src="' . AA_INSTAL_PATH . 'images/blank.gif" width=20 height=1>'],
            'aditional2'   => ['default' => '<input type=button name=sel_ids value="' . _m("Show selected") . '" onClick=showSelectedComments() class="discbuttons">'],
            'aditional3'   => ['default' => '<input type=button name=all_ids value="' . _m("Show all") . '" onClick=showAllComments() class="discbuttons">'],
            'aditional4'   => ['default' => '<input type=button name=add_disc value="' . _m("Add new") . '" onClick=showAddComments() class="discbuttons">'],
            'aliases'      => 'discus',
        ],
        'disc2mail' => [
            'aliases' => 'disc2mail',
        ],
        'seetoo' => [
            'modification' => [
                31 => 'related',
                32 => 'keyword with OR',
                33 => 'keyword with AND',
            ],
            'aliases'      => 'field',
        ],
        'const' => [
            'aliases' => 'const',
            'order'   => 'easy',
            'fields'  => 'GetConstantFields',
        ],
        'urls' => [
            'aliases' => 'justids',
        ],
        'links' => [
            'aliases' => 'links',
            'order'   => 'easy',
            'fields'  => 'GetLinkFields',
        ],
        'categories' => [
            'aliases' => 'categories',
            'order'   => 'easy',
            'fields'  => 'GetCategoryFields',
        ],
        'calendar' => [
            'aliases'            => 'field',
            'aliases_additional' => (new AA_Aliases())
                ->addAlias(new AA_Alias('_#CV_TST_1', '', '', _m("Calendar: Time stamp at 0:00 of processed cell")))
                ->addAlias(new AA_Alias('_#CV_TST_2', '', '', _m("Calendar: Time stamp at 24:00 of processed cell")))
                ->addAlias(new AA_Alias('_#CV_NUM_D', '', '', _m("Calendar: Day in month of processed cell")))
                ->addAlias(new AA_Alias('_#CV_NUM_M', '', '', _m("Calendar: Month number of processed cell")))
                ->addAlias(new AA_Alias('_#CV_NUM_Y', '', '', _m("Calendar: Year number of processed cell"))),
        ],
        'static'     => ['aliases' => 'none' ],
        'inputform'  => ['aliases' => ''],
        default      => [],
    };
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
const SLICE_ALLOW_PENDING_CONTENT = 8;  // pending items, when referenced, will be visible - this allows you also to see preview of pending item

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
const MGR_SB_ALLTEXT    = 32;  // show '-- any text field --' option in search fields
const MGR_SB_ALLNUM     = 64;  // show '-- any numeric field --' option in search fields
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
