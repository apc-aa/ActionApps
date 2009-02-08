<?php
/**
 * A class for manipulating slices
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
 * @author    Mitra Ardron <mitra@mitra.biz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
*/

// A class for manipulating slices
//
// Author and Maintainer: Mitra mitra@mitra.biz
//
// It is intended - and you are welcome - to extend this to bring into
// one place the functions for working with slices.
//
// A design goal is to use lazy-evaluation wherever possible, i.e. to only
// go to the database when something is needed.

//If this is needed, comment why! It trips out anything calling sliceobj NOT from one level down
//require_once "../include/config.php3";
//require_once AA_INC_PATH."locsess.php3";
require_once AA_INC_PATH."zids.php3"; // Pack and unpack ids
require_once AA_INC_PATH."view.class.php3"; //GetViewsWhere

class AA_Slice {
    var $name;            // The name of the slice
    var $unpackedid;      // The unpacked id of the slice i.e. 32 chars
    var $fields;          // 2 member array( $fields, $prifields)
    var $dynamic_fields;  // 2 member array( $fields, $prifields)
    var $setting;         // slice setting - Record form slice table
    var $dynamic_setting; // dynamic slice setting fields stored in content table

    // computed values form slice fields
    var $js_validation;  // javascript form validation code
    var $show_func_used; // used show functions in the input form
    /** AA_Slice function
     * @param $slice_id
     */
    function AA_Slice($slice_id) {
        global $errcheck;
        if ($errcheck && ! ereg("[0-9a-f]{32}",$slice_id)) {
            huhe(_m("WARNING: slice: %s doesn't look like an unpacked id", array($slice_id)));
        }
        $this->unpackedid     = $slice_id; // unpacked id
        $this->fields         = new AA_Fields($this->unpackedid);
        $this->dynamic_fields = new AA_Fields($this->unpackedid, 1);
    }

    /** loadsettings function
     *  Load $this from the DB for any of $fields not already loaded
     *  @return true/false if the settings is loaded (= slice_id is OK)
     *  @param  $force
     */
    function loadsettings($force=false) {
        if ( !$force AND isset($this->setting) AND is_array($this->setting) ) {
            return true;
        }

        // get fields from slice table
        $SQL = "SELECT * FROM slice WHERE id = '".$this->sql_id(). "'";
        $this->setting = GetTable2Array($SQL, 'aa_first', 'aa_fields');
        if ( $this->setting ) {
            // do it more secure and do not store it plain
            // (we should be carefull - mainly with debug outputs)
            if ($this->setting['reading_password']) {
                $this->setting['reading_password'] =  md5($this->setting['reading_password']);
            }
        } else {
            if ($GLOBALS['errcheck']) {
                huhl("Slice ".$this->unpacked_id()." is not a valid slice");
            }
            return false;
        }

        // get fields from module table
        $SQL = "SELECT name, deleted, slice_url, lang_file, created_at, created_by, owner, app_id, priority, flag FROM module WHERE id = '".$this->sql_id(). "'";
        $this->setting = array_merge($this->setting, GetTable2Array($SQL, 'aa_first', 'aa_fields'));
        return true;
    }

    /** loadsettingfields function
     *  Load $this from the DB for any of $fields not already loaded
     * @param $force
     */
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

    /** getProperty function
     * @param $fname
     */
    function getProperty($fname) {
        if (AA_Fields::isSliceField($fname)) {
            $this->loadsettingfields();
            return $this->dynamic_setting->getValue($fname);
        } else {
            $this->loadsettings();
            return $this->setting[$fname];
        }
    }

    /** name function
     *
     */
    function name() {
        return $this->getProperty('name');
    }

    /** Checks, if the unpackedid is OK and the slice is not deleted */
    function isValid() {
        return $this->loadsettings() AND !$this->deleted();
    }

    /** jumpLink function
     *
     */
    function jumpLink() {
        return "<a href=\"".get_admin_url("index.php3?change_id=".$this->unpacked_id()). "\">".$this->name()."</a>";
    }

    /** deleted function
     *
     */
    function deleted() {
        return $this->getProperty('deleted');
    }

    /** fleman_dir function
     *
     */
    function fileman_dir() {
        return $this->getProperty('fileman_dir');
    }

    /** type function
     *
     */
    function type() {
        return $this->getProperty('type');
    }

    /** unpacked_id function
     *
     */
    function unpacked_id() {
        return $this->unpackedid; // Return a 32 character id
    }

    /** packed_id function
     *
     */
    function packed_id() {
        return pack_id($this->unpackedid);
    }

    /** getFields function
     *
     */
    function & getFields($dynamic_fields = false) {
        return $dynamic_fields ? $this->dynamic_fields : $this->fields;
    }

    /** getWidgetAjaxHtml function
     * @param $field_id
     * @param $item_id
     * @param $visual
     */
    function getWidgetAjaxHtml($field_id, $item_id, $visual='') {
        return $this->fields->getWidgetAjaxHtml($field_id, $item_id, $visual);
    }

    /** getWidgetAjaxHtml function
     * @param $field_id
     * @param $item_id
     */
    function getWidgetLiveHtml($field_id, $item_id) {
        return $this->fields->getWidgetLiveHtml($field_id, $item_id);
    }

    /** getLang function
     *  Returns lang code ('cz', 'en', 'en-utf8', 'de',...)
     */
    function getLang()     {
        $lang_file = substr($this->getProperty('lang_file'), 0, strpos($this->getProperty('lang_file'), '_news_lang'));
        return isset($GLOBALS['LANGUAGE_NAMES'][$lang_file]) ? $lang_file : substr(DEFAULT_LANG_INCLUDE, 0, 2);
    }

    /** getCharset function
     *  Returns character encoding for the slice ('windows-1250', ...)
     */
    function getCharset()     {
        return $GLOBALS["LANGUAGE_CHARSETS"][$this->getLang()];   // like 'windows-1250'
    }

    /** sql_id function
     *  Return an id in a form that can be passed to sql, (needs outer quotes)
     */
    function sql_id()      { return q_pack_id($this->unpackedid); }

    /** fields function
     *  fetch the fields
     * @param $return_type
     * @param $slice_fields
     * @return an array with two elements [0] is array in form
     * wanted by Storeitem etc, [1] is array of fields in priority order
     */
    function fields( $return_type = null, $slice_fields = false ) {

        $fields = $slice_fields ? $this->dynamic_fields : $this->fields;

        switch ( $return_type ) {
            // record - deprecated
            case 'record':  return $fields->getRecordArray();    // array of field definitions where field_id is key
            case 'pri':     return $fields->getPriorityArray();  // array of field definitions sorted by priority - integer key
            case 'search':  return $fields->getSearchArray();
        }
        return array($fields->getRecordArray(), $fields->getPriorityArray());                         // two member array ('record' array, 'pri' array)
    }

    /** get_dynamic_setting_content function
     *  Returns slice setting field content in ItemContent object
     * @param $ignore_reading_password
     */
    function get_dynamic_setting_content($ignore_reading_password = false) {
        if ($ignore_reading_password || ($this->getProperty('reading_password') == '') || ($this->getProperty('reading_password') == md5($GLOBALS["slice_pwd"]))) {
            $this->loadsettingfields();
            return $this->dynamic_setting;
        } else {
            if ($GLOBALS['errcheck'] OR $GLOBALS['debug']) {
                huhe(_m("Error: Missing Reading Password"));
            }
            return false;
        }
    }

    /** getUploadBase function
     *  Get the base for the file uploads
     */
    function getUploadBase() {
        $ret = array();
        $fileman_dir = $this->getProperty('fileman_dir');
        if ($fileman_dir AND is_dir(FILEMAN_BASE_DIR.$fileman_dir)) {
            $ret['path']  = FILEMAN_BASE_DIR.$fileman_dir."/items";
            $ret['url']   = FILEMAN_BASE_URL.$fileman_dir."/items";
            $ret['perms'] = FILEMAN_MODE_DIR;
        } else {
            // files are copied to subdirectory of IMG_UPLOAD_PATH named as slice_id
            $ret['path']  = IMG_UPLOAD_PATH. $this->unpacked_id();
            $ret['url']   = get_if($this->getProperty('_upload_url.....'), IMG_UPLOAD_URL. $this->unpacked_id());
            $ret['perms'] = (int)IMG_UPLOAD_DIR_MODE;
        }
        return $ret;
    }

    /** getUrlFromPath function
     *  Try to transform file path to file url - based on setting of file
     *  uploads or filemanager
     * @param $filename
     */
    function getUrlFromPath($filename) {
        $upload = $this->getUploadBase();
        if (strpos($filename, $upload['path']) === 0) {
            return $upload['url']. substr($filename,strlen($upload['path']));
        }
        return $filename;
    }

    /** views function
     *  Get all the views for this slice
     */
    function views() {
        return AA_Views::getSliceViews($this->unpackedid);
    }

    /** get_format_strings function
     *  Returns array of admin format strings as used in manager class
     */
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

    /** aliases function
     *  Get standard aliases definition from slice's fields
     * @param $additional_aliases
     */
    function aliases($additional_aliases = false) {
        return $this->fields->getAliases($additional_aliases);
    }

    /** get_js_validation function
     *  Returns javascript code for inputform validation
     * @param $action
     * @param $id
     * @param $shown_fields
     * @param $slice_fields
     */
    function get_js_validation($action, $id=0, $shown_fields=false, $slice_fields=false) {
        $this->_compute_field_stats($action, $id, $shown_fields, $slice_fields);
        return $this->js_validation;
    }

    /** get_show_func_used function
     *  Returns array of inputform function used the in inputform
     * @param $action
     * @param $id
     * @param $shown_fields
     * @param $slice_fields
     */
    function get_show_func_used($action, $id=0, $shown_fields=false, $slice_fields=false) {
        $this->_compute_field_stats($action, $id, $shown_fields, $slice_fields);
        return $this->show_func_used;
    }

    /** _compute_field_stats function
     *  Computes js_validation code and show_func_used
     * @param $action       - 'update' | 'edit'
     * @param $id           - id of item to edit
     * @param $shown_fields - array of field ids which we will use in the output
     *                  (inputform)(we have to count with them).
     *                  If false, then we use all the fields
     * @param $slice_fields
     */
    function _compute_field_stats($action, $id=0, $shown_fields=false, $slice_fields=false) {
        if (isset($this->js_validation)) {
            return;                                // already computed
        }

        global $auth;
        $profile = AA_Profile::getProfile($auth->auth["uid"], $this->unpacked_id()); // current user settings
        $this->loadsettings();

        // get slice fields and its priorities in inputform
        list( $fields, $prifields) = $this->fields(null, $slice_fields);
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
                if ($show_func == 'fil') {
                    continue;
                }

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

class AA_Slices {
    var $a = array();     // Array unpackedsliceid -> slice obj
    /** AA_Slices function
     *
     */
    function AA_Slices() {
        $this->a = array();
    }

    /** singleton
     *  called from getSlice method
     *  This function makes sure, there is just ONE static instance if the class
     *  @todo  convert to static class variable (after migration to PHP5)
     */
    function singleton() {
        static $instance = null;
        if (is_null($instance)) {
            // Now create the AA_Slices object
            $instance = new AA_Slices;
        }
        return $instance;
    }

    /** getSlice function
     *  main factory static method
     * @param $slice_id
     */
    function & getSlice($slice_id) {
        $slices = AA_Slices::singleton();
        return $slices->_getSlice($slice_id);
    }

    /** getSliceProperty function
     *  static function
     * @param $slice_id
     * @param $field
     */
    function getSliceProperty($slice_id, $field) {
        $slices = AA_Slices::singleton();
        $slice  = $slices->_getSlice($slice_id);
        return $slice ? $slice->getProperty($field) : null;
    }

    /** getName function
     *  static function
     * @param $slice_id
     */
    function getName($slice_id) {
        return AA_Slices::getSliceProperty($slice_id, 'name');
    }

    /** _getSlice function
     * @param $slice_id
     */
    function & _getSlice($slice_id) {
        if (!isset($this->a[$slice_id])) {
            $this->a[$slice_id] = new AA_Slice($slice_id);
        }
        return $this->a[$slice_id];
    }
}

?>