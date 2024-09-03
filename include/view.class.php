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
use AA\Util\Langs;

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

class AA_View {
    protected $id;
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
    function __construct($id, $rec=null) {
        $this->id = (int)$id;
        if (isset($rec)) {
            $this->fields = $rec;
        }
    }

    /** load function
     * @param bool $force
     * @return bool
     */
    function load( $force=false ): bool {
        if (!isset($this->fields) OR $force) {
            $this->fields = DB_AA::select1([], 'SELECT view.*, module.deleted, module.lang_file FROM view, module', [['module.id', 'view.slice_id', 'j'], ['view.id', $this->id]]);
        }
        return $this->fields ? true : false;
    }

    /** Checks, if view exists and the slice is not deleted */
    function isValid() {
        return $this->load() AND !$this->f('deleted');
    }

    /** f function
     * @param $field
     * @return string
     */
    function f( $field ) {
        if ( !$field ) {
            return '';
        }
        $this->load();
        return $this->fields[$field];
    }

    /** getViewInfo function */
    function getViewInfo() {
        $this->load();
        return $this->fields;
    }

    /**
     * @return string - long id of the slice of this view
     */
    function getSliceId() {
        return unpack_id($this->f('slice_id'));
    }

    /**
     * @return string - number or name ID of the view
     */
    function getId() {
        return $this->id;
    }

    /** getLang function
     *  Returns lang code ('cz', 'en', 'en-utf8', 'de',...)
     */
    function getLang()     {
        return Langs::getLang($this->f('lang_file'));
    }

    /** jumpLink function
     *  Generates link to view edit
     * @param $name
     * @return string
     */
    function jumpLink($name = null) {
        return "<a href=\"".$this->jumpUrl(). "\">".($name ?: $this->id)."</a>";
    }

    /** jumpUrl function
     *  Returns Url of view edit
     */
    function jumpUrl() {
        return get_admin_url("se_view.php?change_id=".unpack_id($this->f('slice_id')). "&view_id=". $this->id);
    }

    /** setfields function
     * @param $rec
     */
    function setfields($rec) {
        $this->fields = $rec;
    }

    /** setBannerParam function
     *  sets ['banners']
     * @param $banner_param
     */
    function setBannerParam($banner_param) {
        $this->fields = array_merge( (array)$this->fields, $banner_param);
    }

    /** old GetViewConds function
     * Fills array with conditions defined through
     * 'Slice Admin' -> 'Design View - Edit' -> 'Conditions' setting
     * @param array $param_conds possibly redefinition of conds from url (cmd[]=c)
     * @return array conditions array
     */
    public function getConds($param_conds= []) {
        // If param_conds[0] = "OR" as set by ParseViewParameters then set valuejoin
        // used by ParseMultiSelectConds

        // param_conds - redefines default condition values by url parameter (cmd[]=c)

        AA::$debug&2 && AA::$dbg->log("(AA_View->getConds) param_conds=",$param_conds);
        $conds = [];

        $this->resolveCondsConflict($conds, $this->f('cond1field'), $this->f('cond1op'), $this->f('cond1cond'),  $param_conds[1]);
        $this->resolveCondsConflict($conds, $this->f('cond2field'), $this->f('cond2op'), $this->f('cond2cond'),  $param_conds[2]);
        $this->resolveCondsConflict($conds, $this->f('cond3field'), $this->f('cond3op'), $this->f('cond3cond'),  $param_conds[3]);
        if ($param_conds[0]) {
            $conds['valuejoin'] = $param_conds[0];
        }
        AA::$debug&2 && AA::$dbg->log("(AA_View->getConds) conds=",$conds);

        return $conds;
    }

    /**
     * @return AA_Set of the view
     */
    public function getSet() {
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
    protected function resolveCondsConflict(&$conds, $fld, $op, $val, $param) {
        if ($fld AND $op) {
            $conds[] = [
                'operator' => $op,
                'value'    => (strlen((string)$param)>0 ? $param : $val),  // param could be also "0"
                $fld       => 1
            ];
        }
    }


    /** old GetViewSort function
     * @param array $param_sort
     * @return array|bool
     */
    public function getSort(array $param_sort=[]) {
        $sort = false;
        // grouping

        if (array_key_exists('group_by', $param_sort)) {
            if ($param_sort['group_by'] AND !AA_Alias::isAliasLike($param_sort['group_by'], true)) { // we can have group_by= to avoid grouping ({view:55::group_by-})
                $sort = String2Sort('100000' . $param_sort['group_by']);                             // for aliases, we do not want to sort
            }
        } else {
            if ($this->f('group_by1')) {
                $sort[] = [$this->f('group_by1') => AA_View::VIEW_SORT_DIRECTIONS[$this->f('g1_direction')]];
            }
        }

        //sorting
        if (array_key_exists('sort', $param_sort)) {
            if ($param_sort['sort'] AND $param_sort['sort'] != 'AAnoSORT') {
                $set = new AA_Set;
                $set->addSortFromString($param_sort['sort']);
                $sort = $set->getSort();
            }
        } else {
            if ($this->f('order1')) {
                $sort[] = [$this->f('order1') => AA_View::VIEW_SORT_DIRECTIONS[$this->f('o1_direction')]];
            }
            if ($this->f('order2')) {
                $sort[] = [$this->f('order2') => AA_View::VIEW_SORT_DIRECTIONS[$this->f('o2_direction')]];
            }
        }

        if ($param_sort['group_limit'] AND count($sort)>0) {
            reset($sort);   // go to first record
            $sort[key($sort)]['limit'] = $param_sort['group_limit'];
        }
        return $sort;
    }

    /** getViewFormat function
     * @param $selected_item
     * @return array
     */
    function getViewFormat($selected_item='') {
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

        $format['banners']             = $this->fields['banners'];

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
    static function getViewJumpLinks($text): string {
        $ret = '';
        $matches = [];
        if ($text) {
            $view_ids = [];
            if (preg_match_all("/view\.php3?\?vid=(\w+)/",$text, $matches) > 0) {
                $view_ids = (array)$matches[1];
            }
            if (preg_match_all("/{view:(\w+)/",$text, $matches) > 0) {
                $view_ids = array_merge($view_ids, (array)$matches[1]);
            }
            if ($view_ids = array_unique($view_ids)) {
                $ret = _m('Jump to view:');
                foreach($view_ids as $vid) {
                    $view = AA_Views::getView($vid);
                    if ($view) {                  // probably will be set
                        $ret .= ' '. $view->jumpLink();
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
    function xml_serialize($t,$i,$ii,$a) {
        $f = $this->getViewInfo();
        return xml_serialize("view",$f,$i.$ii,$ii,"tname=\"$t\" ".$a);
    }

    /** GetListLength function
     * @param $listlen
     * @param $to
     * @param $from
     * @param $page
     * @param $idscount
     * @param $random
     * @return array
     */
    public static function getListLength($listlen, $to, $from, $page, $idscount, $random) {
        $list_from = max(0, $from - 1);    // user counts items from 1, we from 0
        $list_to   = max(0, $to - 1);      // user counts items from 1, we from 0

        if ($to > 0) {
            $listlen = max(0, $list_to - $list_from + 1);
        }

        if ($page) {      // split listing to pages
            // Format:  <page>-<number of pages>
            $pos = strpos($page, '-');
            if ($pos) {
                $no_of_pages = substr($page, $pos + 1);
                $page_n      = substr($page, 0, $pos) - 1;    // count from zero
                // to be last page shorter than others if there is bad number of items
                $list_from = $page_n * floor($idscount / $no_of_pages);
                $listlen   = floor(($idscount * ($page_n + 1)) / $no_of_pages) - floor(($idscount * $page_n) / $no_of_pages);
            } else {
                // second parameter is not specified - take listlen parameter
                // we can also specify both - page and from, which means from the item xy on the page p
                $list_from += $listlen * ($page - 1);
            }
        }
        return [$listlen, $random ?: ($list_from ?: 0)];
    }

    /** ParseBannerParam function
     *  Parses banner url parameter (for view.php as well as for slice.php
     *  (banner parameter format: banner-<position in list>-<banner vid>-[<weight_field>]
     *  (@see {@link https://actionapps.org/faq/#219})
     */
    public static function parseBannerParam($banner_arr): array {
        $ret = [];
        if ($banner_arr) {
            foreach ((array)$banner_arr as $banner) {
                [$foo_pos, $foo_vid, $foo_fld] = explode('-', $banner);
                if (ctype_digit((string)$foo_pos) AND strlen($foo_vid)) {
                    $bparam = "vid=$foo_vid";
                    if ($foo_fld != 'norandom') {
                        $bparam .= "&set[$foo_vid]=random-" . ($foo_fld ?: 1);
                    }
                    $ret['banners'][$foo_pos] = $bparam;
                }
            }
        }
        return $ret;
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

    /** ParseViewParameters function
     *  Converts a query string into a data view_params data structure
     * @param $query_string
     * @return array
     */
    public static function parseViewParameters($query_string = "") {
        if ($use_globals = (!$query_string or !preg_match("/vid=[a-z]/", $query_string))) {
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
                    "set[$vid][]"
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
        $v_conds  = new AA_Set;

        AA::$debug & 2 && AA::$dbg->log("<br>ParseViewParameters - command:", $commands);

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
                    $zids->addDirty(($command->getParameter(1) == 'url') ? $x : $command->getParameterArray(1));

                    // This is bizarre code, just incrementing the first item, left as it is
                    // but questioned on apc-aa-coders - mitra
                    if (($command->getCommand() == 'x') and ($zids->count() > 0)) {
                        \AA\Util\Hitcounter::hit($zids->slice(0));
                    }
                    break;

                case 'c':  // Check for experimental c-OR-1-aaa-2-bbb-3-ccc syntax
                    // Note param_conds[0] is not otherwise used
                    // It is converted into conds in AA_View->getConds
                    // which is consumed in ParseMultiSelectConds
                    if ($command->getParameter(0) == 'OR') {
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

        $arr = isset($new_params['set'][$vid]) ? AA_View::parseSettings((array)$new_params['set'][$vid]) : [];

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

        AA::$debug & 2 && AA::$dbg->log($arr);
        return $arr;
    }

    /** GetViewAliases function
     * @param $als
     * @return array
     */
    public static function getViewAliases($conds) {
        $ret = [];
        if (is_array($conds)) {
            foreach ($conds as $k => $v) {
                $ret[str_pad('_#VIEW_C' . ($k + 1), 10, '_')] = GetAliasDef('f_s:' . $v['value']);
            }
        }
        return $ret;
    }

    /** GetAliasesFromUrl function
     * @param $als
     * @return array
     */
    public static function getAliasesFromUrl($als) {
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
class AA_Views {

    use \AA\Util\SingletonTrait;

    protected $a = [];

    /** xml_serialize function
     * @param $t
     * @param $i
     * @param $ii
     * @param $a
     * @return string
     */
    function xml_serialize($t,$i,$ii,$a) {
        return xml_serialize("views",$this->a,$i.$ii,$ii,"tname=\"$t\" ".$a);
    }

    /** Older factory static method - for numeric views only, do not check, if exist
     * @param int $vid
     * @return AA_View
     */
    static function getViewNumeric($vid) : AA_View {
        $views = AA_Views::singleton();
        return $views->_getView($vid);
    }

    /** Main factory static method - allows numeric and named views. Do not create view for non existing/accessible views
     * @param int|string $vid
     * @return AA_View|null
     */
    static function getView($vid) {
        if (ctype_digit((string)$vid)) {
            $vid = (int)$vid;
        } elseif (AA::$site_id ) {
            $vid = AA_Module_Site::findViewId($vid, AA::$site_id);
        } elseif ( is_long_id($m = AA::$module_id ?: AA::$slice_id) AND strlen($vid) ) {  // for planned tasks, for example
            $vid = DB_AA::select1('id', 'SELECT id FROM view', [['slice_id', $m, 'l'], ['field3', $vid]]);
        } else {
            return null;
        }

        $view = AA_Views::singleton()->_getView($vid);
        return $view->load() ? $view : null;  // if exists return
    }

    /**
     * @param int $vid
     * @param $field
     * @return null
     */
    static function getViewField($vid, $field) {
        /** @var AA_Views $views */
        $views = AA_Views::singleton();
        return $views->_getView($vid)?->f($field);
    }

    /**
     * @param int $vid
     * @return AA_View
     */
    function _getView($vid) : AA_View {
        if (!isset($this->a[$vid])) {
            $this->a[$vid] = new AA_View($vid);
        }
        return $this->a[$vid];
    }

    /** Returns an array of slice views, caching results in allknownviews
     * @param $slice_id
     * @return AA_View[]
     */
    static function getSliceViews($slice_id) {
        $a = [];
        if ($slice_id) {
            $views = AA_Views::singleton();
            $SQL   = "SELECT id FROM view WHERE slice_id='". q_pack_id($slice_id) ."'";
            $v_arr = GetTable2Array($SQL, 'NoCoLuMn', 'id');
            if (is_array($v_arr)) {
                foreach ($v_arr as $id) {
                    // cache it
                    if (!isset($views->a[$id])) {
                        $views->a[$id] = new AA_View($id);
                    }
                    $a["$id"] = &$views->a[$id];
                }
            }
        }
        return $a;
    }
}

