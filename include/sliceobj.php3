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

// A class for manipulating slices
//
// Author and Maintainer: Mitra mitra@mitra.biz
//
// And yes, I'll move the docs to phpDocumentor as soon as someone explains how
// to use it!
//
// It is intended - and you are welcome - to extend this to bring into
// one place the functions for working with slices.
//
// A design goal is to use lazy-evaluation wherever possible, i.e. to only
// go to the database when something is needed.

//If this is needed, comment why! It trips out anything calling sliceobj NOT from one level down
//require_once "../include/config.php3";
//require_once $GLOBALS['AA_INC_PATH']."locsess.php3";
require_once $GLOBALS['AA_INC_PATH']."zids.php3"; // Pack and unpack ids
require_once $GLOBALS['AA_INC_PATH']."viewobj.php3"; //GetViewsWhere

class slice {
    var $name;            // The name of the slice
    var $unpackedid;      // The unpacked id of the slice i.e. 32 chars
    var $fields;          // 2 member array( $fields, $prifields)
    var $dynamic_fields;  // 2 member array( $fields, $prifields)
    var $setting;         // slice setting - Record form slice table
    var $dynamic_setting; // dynamic slice setting fields stored in content table

    // computed values form slice fields
    var $js_validation;  // javascript form validation code
    var $show_func_used; // used show functions in the input form

    function slice($init_id="",$init_name=null) {
        global $errcheck;
        if ($errcheck && ! ereg("[0-9a-f]{32}",$init_id))
            huhe(_m("WARNING: slice: %s doesn't look like an unpacked id",
                array($init_id)));
        $this->unpackedid = $init_id; // unpacked id
        if (isset($init_name)) $this->name = $init_name;
    }

    // Load $this from the DB for any of $fields not already loaded
    function loadsettings($force=false) {
        if ( !$force AND isset($this->setting) AND is_array($this->setting) ) {
            return;
        }
        $SQL = "SELECT * FROM slice WHERE id = '".$this->sql_id(). "'";
        $db = getDB();
        $db->tquery($SQL);
        if ( $db->next_record()) {
            $this->setting = DBFields($db);
        } elseif ($GLOBALS[errcheck]) {
            huhl("Slice ".$this->unpacked_id()." is not a valid slice");
        }
        freeDB($db);
    }

    // Load $this from the DB for any of $fields not already loaded
    function loadsettingfields($force=false) {
        if ( !$force AND isset($this->dynamic_setting) AND is_array($this->dynamic_setting) ) {
            return;
        }
        $db = getDB();
        $SQL = "SELECT * FROM content WHERE item_id = '".$this->sql_id()."' ORDER BY content.number";
        $db->tquery($SQL);
        while ($db->next_record()) {
            // which database field is used (from 05/15/2004 we have FLAG_TEXT_STORED set for text-field-stored values
            $db_field = ( ($db->f("text")!="") OR ($db->f("flag") & FLAG_TEXT_STORED) ) ? 'text' : 'number';
            $content4id[$db->f("field_id")][] = array( "value" => $db->f($db_field),
                                                       "flag"  => $db->f("flag") );
        }
        freeDB($db);
        $this->dynamic_setting = new ItemContent($content4id);
    }

    function getfield($fname) {
        if (isSliceField($fname)) {
            $this->loadsettingfields();
            return $this->dynamic_setting->getValue($fname);
        } else {
            $this->loadsettings();
            return $this->setting[$fname];
        }
    }

    function name()        { return $this->getfield('name');         }
    function deleted()     { return $this->getfield('deleted');      }
    function fileman_dir() { return $this->getfield('fileman_dir');  }
    function type()        { return $this->getfield('type');         }
    function unpacked_id() { return $this->unpackedid;               } // Return a 32 character id
    function packed_id()   { return pack_id128($this->unpackedid);   }

    // Return an id in a form that can be passed to sql, (needs outer quotes)
    function sql_id()      { return q_pack_id($this->unpackedid); }

    // fetch the fields
    // returns an array with two elements [0] is array in form
    // wanted by Storeitem etc, [1] is array of fields in priority order
    function fields( $return_type = null, $slice_fields = false ) {
        if ($slice_fields) {
            if (!isset($this->dynamic_fields)) {
                $this->dynamic_fields = GetSliceFields($this->unpacked_id(), true);
            }
            $fields = &$this->dynamic_fields;
        } else {
            if (!isset($this->fields)) {
                $this->fields = GetSliceFields($this->unpacked_id());
            }
            $fields = &$this->fields;
        }

        switch ( $return_type ) {
            case 'fill':    return true;              // just make sure $this->fields is filled
            case 'record':  return $fields[0];  // array of field definitions where field_id is key
            case 'pri':     return $fields[1];  // array of field definitions sorted by priority - integer key
            case 'search':
                $fields_list = &$fields[0];    // in order we can use it in foreach
                foreach ( $fields_list as $fld ) { // in priority order
                    $showfunc = ParseFnc($fld['input_show_func']);
                    $field_type = 'numeric';
                    if ($fld['text_stored']) { $field_type = 'text'; }
                    if (substr($fld['input_validate'],0,4)=='date') { $field_type = 'date'; }
                    if (HaveConstants($showfunc['fnc'])) {
                        if (!AreSliceConstants($showfunc['param'])) { $field_type = 'constants';}
                    }
                    /* $field_type = ( $fld['text_stored'] ?
                      'text' : (( substr($fld['input_validate'],0,4)=='date' ) ?
                      'date' : 'numeric')); */
                    // we can hide the field, if we put in fields.search_pri=0
                    $search_pri = ($fld['search_pri'] ? ++$i : 0 );
                                       //             $name,        $field,   $operators, $table, $search_pri, $order_pri
                    $ret[$fld['id']] = GetFieldDef( $fld['name'], $fld['id'], $field_type, false, $search_pri, $search_pri);
                }
                return $ret;
        }
        return $fields;                         // two member array ('record' array, 'pri' array)
    }


    /** Returns slice setting field content in ItemContent object */
    function get_dynamic_setting_content($ignore_reading_password = false) {
        if ($ignore_reading_password || ($this->getfield('reading_password') == '') || ($this->getfield('reading_password') == $GLOBALS["slice_pwd"])) {
            $this->loadsettingfields();
            return $this->dynamic_setting;
        } else {
            if ($GLOBALS['errcheck'] OR $GLOBALS['debug']) {
                huhe(_m("Error: Missing Reading Password"));
            }
            return false;
        }
    }

    // Get all the views for this slice
    function views() {
        $SQL = "slice_id = '".$this->sql_id()."'";
        return GetViewsWhere($SQL);
    }

    /** Returns array of admin format strings as used in manager class */
    function get_format_strings() {
         $this->loadsettings();
         // additional string for compact_top and compact_bottom needed
         // for historical reasons (not manager.class verion of item manager)
         return array ( "compact_top"     => '<table border="0" cellspacing="0" cellpadding="1" bgcolor="#F5F0E7" class="mgr_table">'.
                                             $this->setting['admin_format_top'],
                        "category_sort"   => false,
                        "category_format" => "",
                        "category_top"    => "",
                        "category_bottom" => "",
                        "even_odd_differ" => false,
                        "even_row_format" => "",
                        "odd_row_format"  => $this->setting['admin_format'],
                        "compact_remove"  => $this->setting['admin_remove'],
                        "compact_bottom"  => $this->setting['admin_format_bottom']. '</table>',
                        "noitem_msg"      => $this->setting['admin_noitem_msg'],
                        // id is packed (format string are used as itemview
                        //               parameter, where $slice_info expected)
                        "id"              => $this->setting['id'] );
    }

    /** Get standard aliases definition from slice's fields */
    function aliases($additional_aliases = false) {
        return GetAliasesFromFields($this->fields('record'), $additional_aliases);
    }

    /** Returns javascript code for inputform validation */
    function get_js_validation($action, $id=0, $shown_fields=false) {
        $this->_compute_field_stats($action, $id, $shown_fields);
        return $this->js_validation;
    }

    /** Returns array of inputform function used the in inputform */
    function get_show_func_used($action, $id=0, $shown_fields=false) {
        $this->_compute_field_stats($action, $id, $shown_fields);
        return $this->show_func_used;
    }

    /** Computes js_validation code and show_func_used
     *  $action       - 'update' | 'edit'
     *  $id           - id of item to edit
     *  $shown_fields - array of field ids which we will use in the output 
     *                  (inputform)(we have to count with them). 
     *                  If false, then we use all the fields
     */
    function _compute_field_stats($action, $id=0, $shown_fields=false) {
        if (isset($this->js_validation)) {
            return;                                // already computed
        }

        global $profile, $auth;
        if (!is_object( $profile ) ) {             // current user settings
            $profile = new aaprofile($auth->auth["uid"], $this->unpacked_id());
        }
        $this->loadsettings();

        // get slice fields and its priorities in inputform
        list( $fields, $prifields) = $this->fields();
        if (!is_array($prifields)) {
            return '';
        }

        // it is needed to call IsEditable() function and GetContentFromForm()
        if ( $id ) {
            $oldcontent = GetItemContent($id);
            $oldcontent4id = $oldcontent[$id];   // shortcut
        }

        $js_proove_fields = 'true';
        foreach ( $prifields as $pri_field_id ) {

            $f = $fields[$pri_field_id];

            //  'status_code.....' is not in condition - could be set from defaults
            if (($pri_field_id=='edited_by.......') || ($pri_field_id=='posted_by.......')) {
                continue;   // filed by AA - it could not be filled here
            }
            $varname = 'v'. unpack_id($pri_field_id);  // "v" prefix - database field var

            list($validate) = explode(":", $f["input_validate"]);
            if ($validate == 'e-unique') {
                $validate = "email";
            }

            $editable = IsEditable($oldcontent4id[$pri_field_id], $f, $profile);
            if (is_array($shown_fields) AND !$shown_fields[$pri_field_id]) {
                $editable = false;
            }

            $js_proove_password_filled = ($action != "edit") && $f["required"] && !$oldcontent4id[$pri_field_id][0]['value'];

            // prepare javascript function for validation of the form
            if ( $editable ) {

                // fill show_func_used array - used on several places
                // to destinguish, which javascripts we should include and
                // if we have to use form multipart or not
                list($show_func) = explode(":", $f["input_show_func"], 2);
                $this->show_func_used[$show_func] = true;

                switch( $validate ) {
                    case 'text':
                    case 'url':
                    case 'email':
                    case 'number':
                    case 'id':
                    case 'pwd':
                        $js_proove_fields .= "\n && validate (myform, '$varname', '$validate', "
                            .($f["required"] ? "1" : "0").", "
                            .($js_proove_password_filled ? "1" : "0").")";
                    break;
                }
            }
        }

        $this->js_validation = get_javascript_field_validation(). "\n
            function proove_fields () {
                var myform = document.inputform;
                return $js_proove_fields;
            }\n";
    }
}

class slices {
    var $a;     // Array unpackedsliceid -> slice obj

    // Create slices array from unpacked slice ids
    function slices($iarr=null) {
        $this->a = array();
        foreach ( (array)$iarr as $unpackedsliceid) {
            $this->addslice($unpackedsliceid);
        }
    }

    // Return array of slice_obj
    function objarr() {
        return $this->a;
    }

    function & addslice($unpackedsliceid) {
        if (!$this->a[$unpackedsliceid]) {
            $this->a[$unpackedsliceid] = new slice($unpackedsliceid);
        }
        return $this->a[$unpackedsliceid];
    }
}

$GLOBALS['allknownslices'] = new slices();  // Globally accessable

// Utility functions to avoid mucking with classes where only used once
function sliceid2name($unpackedsliceid) {
    global $allknownslices;
    $s = $allknownslices->addslice($unpackedsliceid);
    return $s->name();
}

// Utility functions to avoid mucking with classes where only used once
function sliceid2field($unpackedsliceid,$field) {
    global $allknownslices;
    $s = $allknownslices->addslice($unpackedsliceid);
    $s = $s->getfield($field);  // Note this should save it but it doesn't BUG!
    return $s;
}

// Function just here for debugging
/*
function report_sliceids() {
    $db = getDB();
    $db->tquery("SELECT name,id FROM slice");
    while ($db->next_record()) {
        print("\nName=".$db->f("name")." unpacked ID=".unpack_id128($db->f("id")));
    }
    freeDB($db);
}
report_sliceids();
*/
/* A set of functions to exercise this object and test code */
/*
function test_sliceobj() {
    $v = new slice(unpack_id128("AA_Core_Fields.."));
    $n = $v->name();
    if ($debug) huhl("test_sliceobj:slice=",$v);
    if ($n != "Action Aplication Core")  {
        print("\n<br>Sliceobj test didn't work, either 'Action Application Core' slice is missing, or code broken");
        return false;
    } else return true;
}
test_sliceobj();
*/
?>