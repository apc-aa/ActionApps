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

class view {
    var $id;
    var $fields; // Array of fields

    function view($id,$rec=null) {
        $this->id = $id;
        if (isset($rec)) {
            $this->fields = $rec;
        }
    }

    function load( $force=false ) {
        if (!isset($this->fields) OR $force) {
            $SQL = "SELECT view.*, module.deleted, module.lang_file FROM view, module
                     WHERE module.id=view.slice_id AND view.id='".$this->id."'";
            $this->fields = GetTable2Array($SQL, 'aa_first', 'aa_fields');
        }
    }

    function f( $field ) {
        if ( !$field ) {
            return '';
        }
        $this->load();
        return $this->fields[$field];
    }

    function getViewInfo() {
        $this->load();
        return $this->fields;
    }

    /** Generates link to view edit */
    function jumpLink($name = null) {
        return "<a href=\"".$this->jumpUrl(). "\">".($name ? $name: $this->id)."</a>";
    }

    /** Returns Url of view edit */
    function jumpUrl() {
        return get_admin_url("se_view.php3?change_id=".unpack_id($this->f('slice_id')). "&view_id=". $this->id);
    }

    function setfields($rec) {
        $this->fields = $rec;
    }
    
    /** sets ['banner_position'] and ['banner_parameters'] */
    function setBannerParam($banner_param) {
        $this->fields = array_merge( $this->fields, $banner_param);
    }

    function getViewFormat($selected_item='') {
        $format                        = array();
        $format['group_by']            = $this->fields['group_by1'];
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

    /** Returns html code which will list links to all views contained
     *  in the template code
     *  static method
     */
    function getViewJumpLinks($text) {
        $ret = '';
        $matches = array();
        if ($text AND (preg_match_all("/view\.php3?\?vid=(\d+)/",$text, $matches)>0)) {
            $ret = _m('Jump to view:');
            $view_ids = array_unique((array)$matches[1]);
            foreach($view_ids as $vid) {
                $view = AA_Views::getView($vid);
                if ($view) {                  // probably will be set
                    $ret .= ' '. $view->jumpLink();
                }
            }
        }
        return $ret;
    }

    function xml_serialize($t,$i,$ii,$a) {
        $f = $this->getViewInfo();
        return xml_serialize("view",$f,$i.$ii,$ii,"tname=\"$t\" ".$a);
    }
}

// Companion of view->xml_serialize
function VIEW_xml_unserialize($n,$a) {
    $v = new view($n,$a);
    return $v;
}

class AA_Views {
    var $a = array();

    function AA_Views() {
        $this->a = array();
    }

    /** "class function" obviously called as AA_Views::global_instance();
     *  This function makes sure, there is global instance of the class
     *  @todo  convert to static class variable (after migration to PHP5)
     */
    function & global_instance() {
        if ( !isset($GLOBALS['allknownviews']) ) {
            $GLOBALS['allknownviews'] = new AA_Views;
        }
        return $GLOBALS['allknownviews'];
    }

    function xml_serialize($t,$i,$ii,$a) {
        return xml_serialize("views",$this->a,$i.$ii,$ii,"tname=\"$t\" ".$a);
    }

    /** main factory static method */
    function & getView($vid) {
        $views = AA_Views::global_instance();
        return $views->_getView($vid);
    }

    /** static function */
    function getViewField($vid, $field) {
        $views = AA_Views::global_instance();
        $view  = $views->_getView($vid);
        return $view ? $view->f($field) : null;
    }

    function & _getView($vid) {
        if (!isset($this->a[$vid])) {
            $this->a[$vid] = new view($vid);
        }
        return $this->a[$vid];
    }

    /** Returns an array of slice views, caching results in allknownviews */
    function getSliceViews($slice_id) {
        $a = array();
        if ($slice_id) {
            $views = AA_Views::global_instance();
            $SQL   = "SELECT id FROM view WHERE ". q_pack_id($slice_id);
            $v_arr = GetTable2Array($SQL, 'NoCoLuMn', 'id');
            if (is_array($v_arr)) {
                foreach ($v_arr as $id) {
                    // cache it
                    if (!isset($views->a[$id])) {
                        $views->a[$id] = new view($id);
                    }
                    $a["$id"] = &$views->a[$id];
                }
            }
        }
        return $a;
    }
}

function VIEWS_xml_unserialize($n,$a) {
    $vs = new AA_Views();
    $vs->a = $a;
    return $vs;
}

?>