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
        return AA_Langs::getLang($this->f('lang_file'));
    }

    /** jumpLink function
     *  Generates link to view edit
     * @param $name
     * @return string
     */
    function jumpLink($name = null) {
        return "<a href=\"".$this->jumpUrl(). "\">".($name ? $name: $this->id)."</a>";
    }

    /** jumpUrl function
     *  Returns Url of view edit
     */
    function jumpUrl() {
        return get_admin_url("se_view.php3?change_id=".unpack_id($this->f('slice_id')). "&view_id=". $this->id);
    }

    /** setfields function
     * @param $rec
     */
    function setfields($rec) {
        $this->fields = $rec;
    }

    /** setBannerParam function
     *  sets ['banner_position'] and ['banner_parameters']
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
     * @param $param_sort
     * @return array|bool
     */
    public function getSort($param_sort=null) {
        $sort = false;
        // grouping

        if ($param_sort['group_by']) {
            // $sort = String2Sort($param_sort['group_by']);
            $sort = String2Sort('100000'.$param_sort['group_by']);
        } else {
            if ($this->f('group_by1')) {
                $sort[] = [$this->f('group_by1') => AA_View::VIEW_SORT_DIRECTIONS[$this->f('g1_direction')]];
            }
        }

        //sorting
        if ($param_sort['sort']) {
            if ( $param_sort['sort'] != 'AAnoSORT' ) {
                $set    = new AA_Set;
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
        $format['banner_position']     = $this->fields['banner_position'];
        $format['banner_parameters']   = $this->fields['banner_parameters'];
        $format['selected_item']       = $selected_item;
        $format['id']                  = $this->fields['slice_id'];
        $format['vid']                 = $this->fields['id'];

        $format['banner_position']     = $this->fields['banner_position'];
        $format['banner_parameters']   = $this->fields['banner_parameters'];

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
     * @return if|mixed|string
     */
    static function getViewJumpLinks($text) {
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

    /** getView function
     *  older factory static method - for numeric views only, do not check, if exist
     * @param int $vid
     * @return AA_View
     */
    static function getViewNumeric($vid) : AA_View {
        $views = AA_Views::singleton();
        return $views->_getView($vid);
    }

    /** getView function
     *  main factory static method - allows numeric and named views. Do not create view for non existing/accessible views
     * @param int|string $vid
     * @return AA_View|null
     */
    static function getView($vid) {
        if (ctype_digit((string)$vid)) {
            $vid = (int)$vid;
        } elseif (AA::$site_id AND ($site = AA_Module_Site::getModule(AA::$site_id))) {
            $vid = $site->findViewId($vid);
        } else {
            return null;
        }
        $view = AA_Views::singleton()->_getView($vid);
        return $view->load() ? $view : null;  // if exists return
    }

    /** getViewField function
     *  static function
     * @param int $vid
     * @param $field
     * @return null
     */
    static function getViewField($vid, $field) {
        /** @var AA_Views $views */
        $views = AA_Views::singleton();
        $view  = $views->_getView($vid);
        return $view ? $view->f($field) : null;
    }

    /** _getView function
     * @param int $vid
     * @return AA_View
     */
    function _getView($vid) : AA_View {
        if (!isset($this->a[$vid])) {
            $this->a[$vid] = new AA_View($vid);
        }
        return $this->a[$vid];
    }

    /** getSliceViews function
     *  Returns an array of slice views, caching results in allknownviews
     * @param $slice_id
     * @return array
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

