<?php

/**
 *
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
 * @package   UserInput
 * @version   $Id$
 * @author    Mitra Ardron <mitra@mitra.biz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

use AA\IO\DB\DB_AA;
use AA\Security\ViewSlices;
use AA\Util\Langs;
use AA\Util\SingletonTrait;
use AA\Util\Txt;

/** Classes for manipulating views,
 * viewobj has static info about views, not anything dependent on parameters
 *
 * Author and Maintainer: Mitra mitra@mitra.biz
 *
 * It is intended - and you are welcome - to extend this to bring into
 * one place the functions for working with views.
 *
 * A design goal is to use lazy-evaluation wherever possible, i.e. to only
 * go to the database when something is needed.
 */

class AA_View
{
    protected int $id;
    protected $fields; // Array of fields

    /** Translate sort codes from views to slice
     *  (we use numbers in views from historical reason)
     *  '0'=>_m("Ascending"), '1' => _m("Descending"), '2' => _m("Ascending by Priority"), '3' => _m("Descending by Priority")
     */
    public const VIEW_SORT_DIRECTIONS = [0 => 'a', 1 => 'd', 2 => '1', 3 => '9'];

    /** view function
     * @param $id
     * @param $rec
     */
    public function __construct($id, $rec = null)
    {
        $this->id = (int) $id;
        if (isset($rec)) {
            $this->fields = $rec;
        }
    }

    /** load function
     * @param bool $force
     * @return bool
     */
    public function load($force = false): bool
    {
        if (!isset($this->fields) || $force) {
            $this->fields = DB_AA::select1([], 'SELECT view.*, module.deleted, module.lang_file FROM view, module', [['module.id', 'view.slice_id', 'j'], ['view.id', $this->id]]);
        }
        return (bool) $this->fields;
    }

    /** Checks, if view exists and the slice is not deleted */
    public function isValid(): bool
    {
        return $this->load() && !$this->f('deleted') && !$this->isDisabled();
    }

    /** f function
     * @param $field
     */
    public function f($field): ?string
    {
        if (!$field) {
            return '';
        }
        $this->load();
        return $this->fields[$field];
    }

    /** getViewInfo function */
    public function getViewInfo()
    {
        $this->load();
        return $this->fields;
    }

    /**
     * @return string - long id of the slice of this view
     */
    public function getSliceId(): string
    {
        return unpack_id($this->f('slice_id'));
    }

    /**
     * @return int - number ID of the view
     */
    public function getId(): int
    {
        return $this->id;
    }

    /** @return string lang code ('cz', 'en', 'en-utf8', 'de',...)
     */
    public function getLang(): string
    {
        return Langs::getLang($this->f('lang_file'));
    }

    public function isDisabled(): bool
    {
        return $this->f('flag') & VIEW_FLAG_DISABLE;
    }

    public function doLog(): bool
    {
        return $this->f('flag') & VIEW_FLAG_LOG;
    }

    /** Generates link to view edit
     * @param $name
     */
    public function jumpLink(bool $name = false): string
    {
        $id =  $this->id;
        if ($this->f('field3')) {
            $id .= " ({$this->f('field3')})";
        }
        if ($name && $this->f('name')) {
            $id .= " - {$this->f('name')}";
        }
        return a_href($this->jumpUrl(), Txt::decoratedName(Txt::safe($id), !$this->isValid()));   // HTML - the name is set in the admin UI
    }

    /** @returns string Url of view edit
     */
    public function jumpUrl(): string
    {
        return get_admin_url('se_view.php', ['change_id' => $this->getSliceId(), 'view_id' => $this->id]);
    }


    /** Sets ['banners']
     * @param $banner_param
     */
    public function setBannerParam($banner_param): void
    {
        $this->fields = array_merge((array) $this->fields, $banner_param);
    }

    /** old GetViewConds function
     * Fills array with conditions defined through
     * 'Slice Admin' -> 'Design View - Edit' -> 'Conditions' setting
     * @param array $param_conds possibly redefinition of conds from url (cmd[]=c)
     * @return array conditions array
     */
    public function getConds($param_conds = []): array
    {
        // If param_conds[0] = "OR" as set by ParseViewParameters then set valuejoin
        // used by ParseMultiSelectConds

        // param_conds - redefines default condition values by url parameter (cmd[]=c)

        AA::$debug & 2 && AA::$dbg->log("(AA_View->getConds) param_conds=", $param_conds);
        $conds = [];

        $this->resolveCondsConflict($conds, $this->f('cond1field'), $this->f('cond1op'), $this->f('cond1cond'), $param_conds[1]);
        $this->resolveCondsConflict($conds, $this->f('cond2field'), $this->f('cond2op'), $this->f('cond2cond'), $param_conds[2]);
        $this->resolveCondsConflict($conds, $this->f('cond3field'), $this->f('cond3op'), $this->f('cond3cond'), $param_conds[3]);
        if ($param_conds[0]) {
            $conds['valuejoin'] = $param_conds[0];
        }
        AA::$debug & 2 && AA::$dbg->log("(AA_View->getConds) conds=", $conds);

        return $conds;
    }

    /**
     * @return AA_Set of the view
     */
    public function getSet(): AA_Set
    {
        return new AA_Set([$this->getSliceId()], $this->getConds(), $this->getSort());
    }

    /** ResolveCondsConflict function
     * Helper function for AA_View->getConds() - resolves database x url conds conflict
     * @param $conds
     * @param $fld
     * @param $op
     * @param $val
     * @param $param
     */
    protected function resolveCondsConflict(&$conds, $fld, $op, $val, $param): void
    {
        if ($fld && $op) {
            $conds[] = [
                'operator' => $op,
                'value'    => (strlen((string) $param) > 0 ? $param : $val),  // param could be also "0"
                $fld       => 1,
            ];
        }
    }


    /** old GetViewSort function
     * @param array $param_sort
     * @return array|null
     */
    public function getSort(array $param_sort = []): ?array
    {
        $sort = null;
        // grouping

        if (array_key_exists('group_by', $param_sort)) {
            if ($param_sort['group_by'] && !AA_Alias::isAliasLike($param_sort['group_by'], true)) { // we can have group_by= to avoid grouping ({view:55::group_by-})
                $sort = String2Sort('100000' . $param_sort['group_by']);                             // for aliases, we do not want to sort
            }
        } elseif ($this->f('group_by1')) {
            $sort[] = [$this->f('group_by1') => self::VIEW_SORT_DIRECTIONS[$this->f('g1_direction')]];
        }

        //sorting
        if (array_key_exists('sort', $param_sort)) {
            if ($param_sort['sort'] && $param_sort['sort'] !== 'AAnoSORT') {
                $set = new AA_Set();
                $set->addSortFromString($param_sort['sort']);
                $sort = $set->getSort();
            }
        } else {
            if ($this->f('order1')) {
                $sort[] = [$this->f('order1') => self::VIEW_SORT_DIRECTIONS[$this->f('o1_direction')]];
            }
            if ($this->f('order2')) {
                $sort[] = [$this->f('order2') => self::VIEW_SORT_DIRECTIONS[$this->f('o2_direction')]];
            }
        }

        if ($param_sort['group_limit'] && count($sort)) {
            reset($sort);   // go to first record
            $sort[key($sort)]['limit'] = $param_sort['group_limit'];
        }
        return $sort;
    }

    /** getViewFormat function
     * @param $selected_item
     * @param bool $from_request the URL called the view - it draws only the banners the URL chose, not those a
     *                           template call in the request left here
     * @return array
     */
    public function getViewFormat($selected_item = '', bool $from_request = false): array
    {
        $format                        = [];
        $format['group_by']            = $this->fields['group_by1'];
        $format['gb_header']           = $this->fields['gb_header'];
        $format['category_format']     = $this->fields['group_title'];
        $format['category_bottom']     = $this->fields['group_bottom'];
        $format['compact_top']         = $this->fields['before'];
        $format['compact_bottom']      = $this->fields['after'];
        $format['compact_remove']      = $this->fields['remove_string'];
        $format['even_row_format']     = $this->fields['even'];
        $format['odd_row_format']      = $this->fields['odd'];
        $format['row_delimiter']       = $this->fields['row_delimiter'];
        $format['even_odd_differ']     = $this->fields['even_odd_differ'];
        $format['selected_item']       = $selected_item;
        $format['id']                  = $this->fields['slice_id'];
        $format['vid']                 = $this->fields['id'];
        $format['noitem_msg']          = $this->fields['noitem_msg'];

        $banners_from_request           = ($this->fields['banners_from_request'] ?? false) === true;
        $format['banners']              = (!$from_request || $banners_from_request) ? ($this->fields['banners'] ?? null) : null;
        $format['banners_from_request'] = $banners_from_request;

        $format['calendar_start_date'] = $this->fields['field1'];
        $format['calendar_end_date']   = $this->fields['field2'];
        $format['aditional']           = $this->fields['aditional'];
        $format['aditional2']          = $this->fields['aditional2'];
        $format['aditional3']          = $this->fields['aditional3'];
        $format['calendar_type']       = $this->fields['calendar_type'];
        return $format;
    }

    /** getViewJumpLinks function
     *  Returns html code which will list links to all views contained
     *  in the template code
     *  static method
     * @param $text
     * @return string
     */
    public static function getViewJumpLinks($text): string
    {
        $ret = '';
        $matches = [];
        if ($text) {
            $view_ids = [];
            if (preg_match_all("/view\.php3?\?vid=(\w+)/", $text, $matches) > 0) {
                $view_ids = (array) $matches[1];
            }
            if (preg_match_all("/{view:(\w+)/", $text, $matches) > 0) {
                $view_ids = array_merge($view_ids, (array) $matches[1]);
            }
            if ($view_ids = array_unique($view_ids)) {
                $ret = _m('Jump to view:');
                foreach ($view_ids as $vid) {
                    $view = AA_Views::getView($vid);
                    if ($view) {                  // probably will be set
                        $ret .= ' ' . $view->jumpLink();
                    }
                }
            }
        }
        return $ret;
    }

    /** xml_serialize function
     * @param $t
     * @param $i
     * @param $ii
     * @param $a
     * @return string
     */
    public function xml_serialize($t, $i, $ii, $a): string
    {
        $f = $this->getViewInfo();
        return xml_serialize("view", $f, $i . $ii, $ii, "tname=\"$t\" " . $a);
    }

    /** ParseBannerParam function
     *  Parses banner url parameter (for view.php as well as for slice.php
     *  (banner parameter format: banner-<position in list>-<banner vid>-[<weight_field>]
     *  (@see {@link https://actionapps.org/faq/#219})
     * @param bool $from_request the URL chose the banners - a view the URL picks lists only what the request may
     *                           read (AA\Security\ViewSlices), the banner views of a template stay trusted
     */
    public static function parseBannerParam($banner_arr, bool $from_request): array
    {
        $ret = [];
        if ($banner_arr) {
            foreach ((array) $banner_arr as $banner) {
                [$foo_pos, $foo_vid, $foo_fld] = array_pad(explode('-', is_string($banner) ? $banner : ''), 3, '');
                // it comes from the URL and becomes the parameters of a view - nothing but a view id and a weight field
                if (ctype_digit($foo_pos) && preg_match('/^[A-Za-z0-9_]+\z/', $foo_vid) && preg_match('/^(?:[0-9]*|norandom|[A-Za-z0-9_.]{16})\z/', $foo_fld)) {
                    $bparam = ['vid' => $foo_vid];
                    if ($foo_fld !== 'norandom') {
                        $bparam['set'][$foo_vid] = 'random-' . ($foo_fld ?: 1);
                    }
                    $ret['banners'][$foo_pos] = http_build_query($bparam, '', '&');   // not arg_separator.output - it may be &amp;
                    $ret['banners_from_request'] = $from_request;   // set only with banners: setBannerParam() merges, so the banners left from an earlier call keep their own flag
                }
            }
        }
        return $ret;
    }

    /** The banners the URL chose, decided before the listing around them renders (AA\Security\ViewSlices): each
     *  pinned to the id of its view, as a view name found nothing yet may find a view once a row sets AA::$slice_id;
     *  a missing or invalid view is left out. A refused banner stays - its narrow() gets the same answer and it
     *  shows an empty listing, as a refused view does.
     * @param array<int|string, string> $banners view parameters by position, as parseBannerParam() writes them
     * @return array<int|string, string>
     */
    public static function decideBanners(array $banners, ViewSlices $gate): array
    {
        $decided = [];
        foreach ($banners as $pos => $query) {
            parse_str((string) $query, $param);
            $view = is_string($param['vid'] ?? null) ? AA_Views::getView($param['vid']) : null;
            if (!$view?->isValid()) {
                continue;
            }
            $vid = $view->getId();
            $gate->decideOwn($vid, (string) $view->f('type'), $view->getSliceId());
            $pinned = ['vid' => $vid];
            if (isset($param['set'][$param['vid']])) {
                $pinned['set'][$vid] = $param['set'][$param['vid']];
            }
            $decided[$pos] = http_build_query($pinned, '', '&');
        }
        return $decided;
    }

    /** ParseSettings function
     * Separates 'set' parameters for current view into array. To escape ','
     *                 character uses ',,'.
     * @param array $set_arr set[<vid>][] array of strings from url. 'set' parameters are in form
     *                      set[<vid>]=property1-value1,property2-value2
     *                   or
     *                      set[<vid>][]=property1-value1&set[<vid>][]=property2-value2[,...]
     * @return array associative array of properties
     */
    public static function parseSettings($set_arr): array
    {
        define('ALLOW_MULTIPLE', ['banner' => true]);  // you can use set[245]=banner-2-260,banner-10-261

        $ret = [];
        foreach ($set_arr as $set) {
            $sets = split_escaped(",", $set, ",,");
            foreach ($sets as $v) {
                $pos = strpos($v, '-');
                if ($pos) {
                    $seting_name = substr($v, 0, $pos);
                    if (ALLOW_MULTIPLE[$seting_name]) {
                        $ret[$seting_name][] = substr($v, $pos + 1);  // always array (for banner for example)
                    } else {
                        $ret[$seting_name] = substr($v, $pos + 1);
                    }
                }
            }
        }

        /* specific for 'slices' parameter */
        if ($ret['slices']) {
            $ret['slices'] = array_filter(explode_ids($ret['slices']), 'is_long_id');
        }

        return $ret;
    }

    /** Converts a query string into a data view_params data structure
     * @param $query_string
     * @return array
     */
    public static function parseViewParameters($query_string = ""): array
    {
        if ($use_globals = (!$query_string || !preg_match("/vid=[a-z]/", $query_string))) {
            // old behavior - we do not want to use global dependency with {view.php} but we did not remove it in times when we wrote {view.php..}{ alias, so we are removing it at least for new "named views"
            global $cmd, $set, $vid, $als;
            global $x;   // url parameter - used for cmd[]=x-111-url view parameter
        }

        if ($query_string) {
            // Parse parameters
            // if view in cmd[] or set[] is not specified - fill it from vid
            if (preg_match("/vid=\s*(\w+)/", $query_string, $parts)) {              // a-z, A-Z, 0-9, including the _
                $vid = $parts[1];

                // it is possible to write vid=574&cmd[]=c-1-test&cmd[]=c-2-1
                $query_string = str_replace(['cmd[][]', 'cmd[]', 'set[][]', 'set[]'], [
                    "cmd[$vid][]",
                    "cmd[$vid][]",
                    "set[$vid][]",
                    "set[$vid][]",
                ], $query_string);

                // we no not want older calls to ParseViewParameters() will infect this call
                // @todo - make two versions - one for url parameters, second for
                // {view.php...} parameters, where we do not want to read global data
                unset($cmd[$vid]);
                unset($set[$vid]);
            }

            $new_params = add_vars($query_string, 'return');  // adds values from url (it's not automatic in SSIed script)

            if ($use_globals) {
                add_vars($query_string);   // import it also globally
                $vid = trim($vid);
            }
        } else {
            $new_params['cmd'] = $GLOBALS['cmd'];
            $new_params['set'] = $GLOBALS['set'];
            $new_params['als'] = $GLOBALS['als'];
        }

        AA::$debug & 2 && AA::$dbg->log("ParseViewParameters: vid=$vid, query_string=" . str_replace("slice_pwd=" . $GLOBALS['slice_pwd'], 'slice_pwd=*****', $query_string), "cmd:", $new_params['cmd'], "set:", $new_params['set'], "als:", $new_params['als']);

        // Splits on "-" and subsitutes aliases
        $commands = new AA_View_Commands($new_params['cmd'][$vid], $new_params['als']);
        $v_conds  = new AA_Set();

        AA::$debug & 2 && AA::$dbg->log("<br>ParseViewParameters - command:", $commands);

        $hits = [];
        $commands->reset();
        while ($command = $commands->current()) {
            $commands->next();
            switch ($command->getCommand()) {
                case 'v':
                    $vid = $command->getParameter(0);
                    break;

                case 'o':  // the same as x, but no hit for item is added
                case 'i':  // 'i' is exactly the same as 'o', now
                case 'x':
                    $vid  = $command->getParameter(0);
                    $zids = new zids();
                    $zids->addDirty(($command->getParameter(1) === 'url') ? $x : $command->getParameterArray(1));

                    // x counts just the first item, even when the view shows more of them;
                    // written once the output is there (AA_Showview, or processPage() in view.php),
                    // so it gets the real time and cache state
                    if ($command->getCommand() === 'x' && !$zids->isEmpty()) {
                        $hits[] = $zids->slice(0);
                    }
                    break;

                case 'c':  // Check for experimental c-OR-1-aaa-2-bbb-3-ccc syntax
                    // Note param_conds[0] is not otherwise used
                    // It is converted into conds in AA_View->getConds
                    // which is consumed in ParseMultiSelectConds
                    if ($command->getParameter(0) === 'OR') {
                        $param_conds[0] = 'OR';
                        $command_params = $command->getParameterArray(1);
                    } else {
                        $command_params = $command->getParameterArray();
                    }

                    //                       if (ctype_digit((string)$command_params[0])) {
                    //
                    //                       }

                    if (AA_Set::check($command_params[0], $command_params[1])) {
                        $param_conds[$command_params[0]] = stripslashes($command_params[1]);
                    }
                    if (AA_Set::check($command_params[2], $command_params[3])) {
                        $param_conds[$command_params[2]] = stripslashes($command_params[3]);
                    }
                    if (AA_Set::check($command_params[4], $command_params[5])) {
                        $param_conds[$command_params[4]] = stripslashes($command_params[5]);
                    }
                    break;

                case 'd':
                    $v_conds->addFromCommand($command);
                    break;
            }
        }

        $arr = isset($new_params['set'][$vid]) ? self::parseSettings((array) $new_params['set'][$vid]) : [];

        // Following line is here just for caching purposes - we are creating cache
        // keystring from view parameters and we need to add all set[] and cmd[]
        // in the keystring, because of cases like:
        //     view.php?vid=1781&set[997]=selected-759644
        // (view 997 is called inside 1781)
        $arr['forcache'] = [$new_params];

        // the parameters for discussion comes (quite not standard way) from globals
        if ($use_globals) {
            if (!$arr["all_ids"]) {
                $arr["all_ids"] = $GLOBALS['all_ids'];
            }
            if (!$arr["ids"]) {
                $arr["ids"] = $GLOBALS['ids'];
            }
            if (!$arr["sel_ids"]) {
                $arr["sel_ids"] = $GLOBALS['sel_ids'];
            }
            if (!$arr["add_disc"]) {
                $arr["add_disc"] = $GLOBALS['add_disc'];
            }
            if (!$arr["sh_itm"]) {
                $arr["sh_itm"] = $GLOBALS['sh_itm'];
            }
            if (!$arr["parent_id"]) {
                $arr["parent_id"] = $GLOBALS['parent_id'];
            }

            // IDs of discussion items for discussion list
            if (!$arr["disc_ids"]) {
                $arr["disc_ids"] = $GLOBALS['disc_ids'];
            }

            // used for discussion list view
            if (!$arr["disc_type"]) {
                $arr["disc_type"] = $GLOBALS['disc_type'];
            }

            // used for Links module - categories and links
            if (!$arr["cat"]) {
                $arr["cat"] = $GLOBALS['cat'];
            }
            if (!$arr["show_subcat"]) {
                $arr["show_subcat"] = $GLOBALS['show_subcat'];
            }

            $arr['als'] = self::getAliasesFromUrl($GLOBALS['als']);
        }

        $arr['vid']         = $vid;
        $arr['conds']       = $v_conds->getConds();
        $arr['param_conds'] = $param_conds;
        //  $arr['item_ids'] = $item_ids;
        $arr['zids'] = $zids;
        $arr['hits'] = $hits;   // set always - a set[]=hits-… from the URL must not get here
        $arr['from_request'] = !$query_string;   // the URL chose them (AA_Showview narrows the slices); set always, like hits

        AA::$debug & 2 && AA::$dbg->log($arr);
        return $arr;
    }

    /**
     * @param  AA_Aliases  $aliases
     * @param  array       $conds
     */
    public static function addViewAliases(AA_Aliases $aliases, array $conds): void
    {
        foreach ($conds as $k => $v) {
            $aliases->addTextAlias(str_pad('_#VIEW_C' . ($k + 1), 10, '_'), $v['value']);
        }
    }

    /** GetAliasesFromUrl function
     * @param $als
     * @return array
     */
    public static function getAliasesFromUrl($als): array
    {
        $ret = [];
        if (is_array($als)) {
            foreach ($als as $k => $v) {
                $ret["_#" . $k] = GetAliasDef("f_s:$v");
            }
        }
        return $ret;
    }
}

/** usually called as $view = AA_Views::getView($vid);
 * @method  static AA_Views singleton()
 */
class AA_Views
{
    use SingletonTrait;

    protected $a = [];

    /** xml_serialize function
     * @param $t
     * @param $i
     * @param $ii
     * @param $a
     * @return string
     */
    public function xml_serialize($t, $i, $ii, $a): string
    {
        return xml_serialize("views", $this->a, $i . $ii, $ii, "tname=\"$t\" " . $a);
    }

    /** Older factory static method - for numeric views only, do not check, if exist
     * @param int $vid
     * @return AA_View
     */
    public static function getViewNumeric($vid): AA_View
    {
        return self::singleton()->_getView((int) $vid);
    }

    /** Main factory static method - allows numeric and named views. Do not create view for non existing/accessible views
     * @param int|string $vid
     * @return AA_View|null
     */
    public static function getView($vid): ?AA_View
    {
        if (ctype_digit((string) $vid)) {
            $vid = (int) $vid;
        } elseif (AA::$site_id) {
            $vid = AA_Module_Site::findViewId($vid, AA::$site_id);
        } elseif (is_long_id($m = AA::$module_id ?: AA::$slice_id) && strlen($vid)) {  // for planned tasks, for example
            if (AA_Module::getModuleType($m) === 'W') {
                $vid = AA_Module_Site::findViewId($vid, $m);
            } else {
                $vid = DB_AA::select1('id', 'SELECT id FROM view', [['slice_id', $m, 'l'], ['field3', $vid]]);
            }
        } else {
            return null;
        }

        $view = self::singleton()->_getView((int) $vid);
        return $view->load() ? $view : null;  // if exists return
    }

    /**
     * @param  int     $vid
     * @param  string  $field
     *
     * @return null
     */
    public static function getViewField(int $vid, string $field): ?string
    {
        /** @var AA_Views $views */
        return self::singleton()->_getView($vid)?->f($field);
    }

    /**
     * @param  int  $vid
     *
     * @return AA_View
     */
    public function _getView(int $vid): AA_View
    {
        if (!isset($this->a[$vid])) {
            $this->a[$vid] = new AA_View($vid);
        }
        return $this->a[$vid];
    }

    /** Stores the top part of the edit form of an Alerts digest view: the grouping and sort[]
     *  (view.aditional, view.aditional3) and its selections (alerts_filter rows).
     *  $filters are posted as [rowid => ['description' => ..., 'conds' => ...]], where rowid is the id
     *  of a stored selection or "new<n>" for a new one; an empty description deletes the selection.
     *  The row ids come from the request, so only the selections of this view can change.
     * @param int    $vid       the edited view - nothing is stored unless it is a view of $slice_id
     * @param string $slice_id  unpacked id of the slice the page works on
     * @param bool   $created   the view was just created ("Save as new" posts the rows of the source
     *                          view) - every posted selection becomes a new row of it
     * @return bool  false - the view is not in the slice, or a selection could not be inserted
     */
    public static function storeDigestFilters(int $vid, string $slice_id, int $group, string $sort, array $filters, bool $created): bool
    {
        $view = [['id', $vid, 'i'], ['slice_id', $slice_id, 'l']];
        if (!DB_AA::test('view', $view)) {
            return false;
        }
        DB_AA::update('view', [['aditional', $group, 'i'], ['aditional3', $sort, 's']], $view);

        $ok = true;
        foreach ($filters as $rowid => $filter) {
            $rowid  = (string) $rowid;
            $is_new = (bool) preg_match('/^new\d+$/D', $rowid);
            if ((!$is_new && !preg_match('/^[1-9]\d*$/D', $rowid)) || !is_array($filter)) {
                continue;
            }
            $description = $filter['description'] ?? '';
            $conds       = $filter['conds'] ?? '';
            if (!is_string($description) || !is_string($conds)) {
                continue;
            }
            if ($is_new || $created) {
                if (($description !== '') && !AA::Metabase()->doInsert('alerts_filter', ['vid' => $vid, 'description' => $description, 'conds' => $conds])) {
                    $ok = false;
                }
                continue;
            }
            $where = [['id', (int) $rowid, 'i'], ['vid', $vid, 'i']];
            if ($description === '') {
                DB_AA::delete('alerts_filter', $where);
            } else {
                DB_AA::update('alerts_filter', [['description', $description, 's'], ['conds', $conds, 's']], $where);
            }
        }
        return $ok;
    }

    /** Returns an array of slice views, caching results in allknownviews
     * @param $slice_id
     * @return AA_View[]
     */
    public static function getSliceViews($slice_id): array
    {
        $a = [];
        if ($slice_id) {
            $views = self::singleton();
            foreach (DB_AA::select('id', 'SELECT id FROM view', [['slice_id', $slice_id, 'l']]) as $id) {
                // cache it
                if (!isset($views->a[$id])) {
                    $views->a[$id] = new AA_View($id);
                }
                $a[(string) $id] = &$views->a[$id];
            }
        }
        return $a;
    }
}
