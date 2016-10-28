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

class AA_Module {
    /** define, which class is used for module setting - like AA_Modulesettings_Slice */
    const SETTING_CLASS = '';
    const SETTING_TABLE = '';

    /** array of already constructed modules */
    protected static $_modules=array();  // Array unpacked module id -> AA_Module object

    protected $module_id;
    protected $module_setting;         // Array of module settings
    protected $table_setting;          // Array of specific table settings
    protected $moduleobject_setting;   // Array of module settings

    /** it is better to call it using getModule() - $site = AA_Module_Site::getModule($module_id); */
    function __construct($id) {
        $this->module_id            = $id;
        $this->module_setting       = null;
        $this->table_setting        = null;
        $this->moduleobject_setting = false;
    }

    /** get module - called as:
     *   $name = AA_Module_Site::getModuleName($module_id)
     *   $name = AA_Slice::getModuleName($module_id)
     * @param $module_id
     */
    static public function getModuleName($module_id) {
        $module = static::getModule($module_id);
        return $module ? $module->getName() : '';
    }

    /** get module - called as:
     *   $site        = AA_Module_Site::getModule($module_id)
     *   $slice       = AA_Slice::getModule($module_id)
     *   $some_module = AA_Module::getModule($module_id)  // this is not recommended - we usualy knot, which module it should be
     *  @param $module_id
     */
    static public function getModule($module_id) {
        if (!is_long_id($module_id)) {
            return null;
        }
        if (!isset(static::$_modules[$module_id])) {
            if (($class = get_called_class()) == 'AA_Module') {
                // tyhis is not usual case - use it just if you do not know what module is it
                $class = AA_Module::getModuleType($module_id);
            }
            if (!$class) {
                return null;
            }
            static::$_modules[$module_id] =  new $class($module_id);
        }
        return static::$_modules[$module_id];
    }

    /** AA_Module::getModuleType function
     * @param $module_id
     */
    static public function getModuleType($module_id) {
        $type  = DB_AA::select1('SELECT type FROM `module`', 'type', array(array('id',$module_id, 'l')));
        switch ($type) {
            case 'W':       return 'AA_Module_Site';
            case 'S':       return 'AA_Slice';
            case 'Alerts':  return 'AA_Module_Alerts';
            case 'J':       return '';                 // @todo - create AA_Module_Jump
            case 'P':       return '';                 // @todo - create AA_Module_Polls
            case 'Links':   return 'AA_Module_Links';
        }
        return '';
    }

    /** AA_Module::deleteModule function
     * @param $module_id
     */
    static public function deleteModules($module_ids) {
        foreach ($module_ids as $module_id) {
            if (!is_long_id($module_id) OR !($class = AA_Module::getModuleType($module_id))) {
                return false;     // _m("No such module.")
            }
            if (!$class::_deleteModules(array($module_id))) {
                return false;
            }

            AA_Object::deleteObjects(AA_Object::getOwnersObjects($module_id));

            // delete module from module table
            DB_AA::delete_low_priority('module', array(array('id', $module_id, 'l')));
            DelPermObject($module_id, "slice");   // delete module from permission system
        }
        return true;
    }

    /** getModuleProperty function
     *  static function
     * @param $module_id
     * @param $field
     */
    static public function getModuleProperty($module_id, $prop) {
        $module = static::getModule($module_id);
        return $module ? $module->getProperty($prop) : null;
    }

    protected function _isModuleTableProperty($prop) {
        return AA_Metabase::singleton()->isColumn('module', $prop);
    }

    protected function _getModuleTableProperty($prop) {
        if (is_null($this->module_setting)) {
            $this->module_setting = DB_AA::select1('SELECT name, deleted, slice_url, lang_file, created_at, created_by, owner, app_id, priority, flag FROM `module`', '', array(array('id',$this->module_id, 'l')));
            if (!is_array($this->module_setting)) {
                $this->module_setting = array();
            }
        }
        return isset($this->module_setting[$prop]) ? $this->module_setting[$prop] : false;
    }

    protected function _isSpecificTableProperty($prop) {
        return static::SETTING_TABLE && AA_Metabase::singleton()->isColumn(static::SETTING_TABLE, $prop);
    }

    protected function _getSpecificTableProperty($prop) {
        if (is_null($this->table_setting)) {
            $this->table_setting = DB_AA::select1('SELECT * FROM `'.static::SETTING_TABLE.'`', '', array(array('id',$this->module_id, 'l')));
            if (!is_array($this->table_setting)) {
                $this->table_setting = array();
            } else {
                unset($this->table_setting['id']);  // do not use it from here - it is packed - use $this->module_id instead
            }
        }
        return isset($this->table_setting[$prop]) ? $this->table_setting[$prop] : false;
    }

    protected function _isModuleObjectProperty($prop) {
        $class = static::SETTING_CLASS;
        return empty($class) ? null : $class::isProperty($prop);
    }

    protected function _getModuleObjectProperty($prop) {
        if ($this->moduleobject_setting === false) {
            // tho object id is derived from SETTING_CLASS name and module_id
            $class = static::SETTING_CLASS;
            $this->moduleobject_setting = empty($class) ? null : $class::load(string2id($class.$this->module_id));
        }
        return is_null($this->moduleobject_setting) ? null : $this->moduleobject_setting->getProperty($prop);
    }

    static public function processModuleObject($module_id) {
        $class = static::SETTING_CLASS;
        if ($module_id AND !empty($class)) {
            // make sure the slicesettings object for this slice exists
            $modulesetings_id = string2id($class.$module_id);
            if (is_null($class::load($modulesetings_id))) {
                $modulesetings = new $class;
                $modulesetings->setNew($modulesetings_id, $module_id);
                $modulesetings->save();
            }

            $form       = AA_Form::factoryForm($class, $modulesetings_id, $module_id);
            $form_state = $form->process($_POST['aa']);
        }
    }

    static public function getModuleObjectForm($module_id) {
        $class = static::SETTING_CLASS;
        if ($module_id AND !empty($class)) {
            return AA_Form::factoryForm($class, string2id($class.$module_id), $module_id)->getObjectEditHtml();
        }
        return '';
    }

    /** get module ID   */
    function getId() {
        return $this->module_id; // Return a 32 character id
    }

    function getName() {
        return $this->_getModuleTableProperty('name');
    }

    function getProperty($prop) {
        if ($this->_isSpecificTableProperty($prop)) {
            return $this->_getSpecificTableProperty($prop);
        }
        if ($this->_isModuleTableProperty($prop)) {
            return $this->_getModuleTableProperty($prop);
        }
        if ($this->_isModuleObjectProperty($prop)) {
            return $this->_getModuleobjectProperty($prop);
        }
        return null;
    }

    /** getLang function
     *  Returns lang code ('cz', 'en', 'en-utf8', 'de',...)
     */
    function getLang()     {
        return GetLang($this->getProperty('lang_file'));
    }

    /** getCharset function
     *  Returns character encoding for the slice ('windows-1250', ...)
     */
    function getCharset()     {
        return $GLOBALS["LANGUAGE_CHARSETS"][$this->getLang()];   // like 'windows-1250'
    }

    function getDefaultLang() {
        return $this->getLang();
    }

    static function getUsedModules() {
        return array_keys(static::$_modules);
    }
}

class AA_Slice extends AA_Module {
    var $name;            // The name of the slice
    var $fields;          // 2 member array( $fields, $prifields)
    var $dynamic_fields;  // 2 member array( $fields, $prifields)
    var $dynamic_setting; // dynamic slice setting fields stored in content table

    const SETTING_CLASS = 'AA_Modulesettings_Slice';
    const SETTING_TABLE = 'slice';

    // computed values form slice fields
    var $js_validation;  // javascript form validation code
    var $show_func_used; // used show functions in the input form
    /** AA_Slice function
     * @param $module_id
     */
    function __construct($module_id) {
        $this->fields         = new AA_Fields($module_id);
        $this->dynamic_fields = new AA_Fields($module_id, 1);
        parent::__construct($module_id);
    }

    protected function _getSpecificTableProperty($prop) {
        if (is_null($this->table_setting)) {
            parent::_getSpecificTableProperty();
            if ($this->table_setting['reading_password']) {
                $this->table_setting['reading_password'] =  AA_Credentials::encrypt($this->table_setting['reading_password']);
            }
        }
        return isset($this->table_setting[$prop]) ? $this->table_setting[$prop] : false;
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
        $SQL = "SELECT * FROM content WHERE item_id = '".q_pack_id($this->module_id)."' ORDER BY content.number";
        $db->tquery($SQL);
        $content4id = array();
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
    function getProperty($prop) {
        // test slice table property, module table property and moduleobject property
        if ( !is_null($ret=parent::getProperty($prop)) ) {
            return $ret;
        }
        if (AA_Fields::isSliceField($prop)) {
            $this->loadsettingfields();
            return $this->dynamic_setting->getValue($prop);
        }
        return null;
    }

    /** isField function
     * @param $fname
     */
    function isField($fname) {
        return $this->fields->isField($fname);
    }

    /** name function
     *
     */
    function name() {
        return $this->getProperty('name');
    }

    /** Checks, if the module_id is OK and the slice is not deleted */
    function isValid() {
        return !$this->deleted();
    }

    /** jumpLink function
     *
     */
    function jumpLink() {
        return "<a href=\"".get_admin_url("index.php3?change_id=".$this->getId()). "\">".$this->name()."</a>";
    }

    /** deleted function
     *
     */
    function deleted() {
        return $this->getProperty('deleted');
    }

    /** type function
     *
     */
    function type() {
        return $this->getProperty('type');
    }

    function isExpiredContentAllowed() {
        return ($this->getProperty('flag') & SLICE_ALLOW_EXPIRED_CONTENT) == SLICE_ALLOW_EXPIRED_CONTENT;
    }

    /** AA_Slice::_deleteModules() function - called automaticaly form AA_Module::deleteModules()
     *  @param $module_id
     */
    static public function _deleteModules($module_ids) {
        if (!is_array($module_ids) OR !count($module_ids)) {
            return false;     // _m("No such module.")
        }
        // deletes from content, offline and relation tables
        AA_Items::deleteItems(new zids(DB_AA::select('id', 'SELECT id FROM `item`', array(array('slice_id', $module_ids, 'l'))),'p'));

        // now performed in AA_Items::deleteItems
        // DB_AA::delete_low_priority('item',  array(array('slice_id', $module_ids, 'l')));

        DB_AA::delete_low_priority('feedmap',   array(array('from_slice_id', $module_ids, 'l')));
        DB_AA::delete_low_priority('feedmap',   array(array('to_slice_id',   $module_ids, 'l')));
        DB_AA::delete_low_priority('feedperms', array(array('from_id', $module_ids, 'l')));
        DB_AA::delete_low_priority('feedperms', array(array('to_id',   $module_ids, 'l')));
        DB_AA::delete_low_priority('email_notify',  array(array('slice_id', $module_ids, 'l')));
        DB_AA::delete_low_priority('field',  array(array('slice_id', $module_ids, 'l')));
        DB_AA::delete_low_priority('view',  array(array('slice_id', $module_ids, 'l')));
        DB_AA::delete_low_priority('email',  array(array('owner_module_id', $module_ids, 'l')));
        DB_AA::delete_low_priority('profile',  array(array('slice_id', $module_ids, 'l')));
        DB_AA::delete_low_priority('rssfeeds',  array(array('slice_id', $module_ids, 'l')));
        DB_AA::delete_low_priority('constant_slice',  array(array('slice_id', $module_ids, 'l')));
        DB_AA::delete_low_priority('slice',  array(array('id', $module_ids, 'l')));

        return true;
    }

    /** unpacked_id function - removed - use getId()  */
    // function unpacked_id() {
    //     return $this->module_id; // Return a 32 character id
    // }

    /** packed_id function - removed - use pack_id($module->getId()) */
    // function packed_id() {
    //     return pack_id($this->module_id);
    // }

    /** getFields function
     */
    function & getFields($dynamic_fields = false) {
        return $dynamic_fields ? $this->dynamic_fields : $this->fields;
    }

    /** getField function     */
    function getField($field_id) {
        return $this->fields->getField($field_id);
    }

    function getWidget($field_id) {
        $field = $this->getField($field_id);
        return $field ? $field->getWidget() : null;
    }

    /** getTranslations
     *  Returns array of two letters shortcuts for languages used in this slice for translations - array('en','cz','es')
     */
    function getTranslations()  {
        return $this->getProperty('translations');
    }

    /** for translated fields - if not translated, use default language of the module */
    function getDefaultLang() {
        return is_array($translations = $this->getTranslations()) ? $translations[0] : $this->getLang();
    }

    /** sql_id function - removed - use DB_AA::select1('SELECT * FROM `slice`', '', array(array('id',$slobj->getId(), 'l'))); */
    // function sql_id()      { return q_pack_id($this->module_id); }

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
            case 'name':    return $fields->getNameArray();

        }
        return array($fields->getRecordArray(), $fields->getPriorityArray());                         // two member array ('record' array, 'pri' array)
    }

    /** get_dynamic_setting_content function
     *  Returns slice setting field content in ItemContent object
     * @param $ignore_reading_password
     */
    function get_dynamic_setting_content($ignore_reading_password = false) {
        if (!$ignore_reading_password) {
            $credentials = AA_Credentials::singleton();
            if (!$credentials->checkCryptedPassword($this->getProperty('reading_password'))) {
                if ($GLOBALS['errcheck'] OR $GLOBALS['debug']) {
                    huhe(_m("Error: Missing Reading Password"));
                }
                return false;
            }
        }
        $this->loadsettingfields();
        return $this->dynamic_setting;
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
            $ret['path']  = IMG_UPLOAD_PATH. $this->getId();
            $ret['url']   = get_if($this->getProperty('_upload_url.....'), IMG_UPLOAD_URL. $this->getId());
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
        return AA_Views::getSliceViews($this->module_id);
    }

    /** get_format_strings function
     *  Returns array of admin format strings as used in manager class
     */
    function get_format_strings() {
         // additional string for compact_top and compact_bottom needed
         // for historical reasons (not manager.class verion of item manager)
         return array ( "compact_top"     => '<table border="0" cellspacing="0" cellpadding="1" bgcolor="#F5F0E7" class="mgr_table">'.
                                             $this->getProperty('admin_format_top'),
                        "category_sort"   => false,
                        "category_format" => "",
                        "category_top"    => "",
                        "category_bottom" => "",
                        "even_odd_differ" => false,
                        "even_row_format" => "",
                        "odd_row_format"  => $this->getProperty('admin_format'),
                        "compact_remove"  => $this->getProperty('admin_remove'),
                        "compact_bottom"  => $this->getProperty('admin_format_bottom'). '</table>',
                        "noitem_msg"      => $this->getProperty('admin_noitem_msg'),
                        // id is packed (format string are used as itemview
                        //               parameter, where $slice_info expected)
                        "id"              => pack_id($this->module_id));
    }

    /** aliases function
     *  Get standard aliases definition from slice's fields
     * @param $additional_aliases
     */
    function aliases($additional_aliases = false, $type='') {
        return $this->fields->getAliases($additional_aliases, $type);
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

    function allowed_bin_4_user() {
        // put the item into the right bin
        $bin2fill = IfSlPerm(PS_EDIT_ALL_ITEMS, $this->module_id) ? 1 : (int) $this->getProperty("permit_anonymous_post");
        return ($bin2fill < 1) ? SC_NO_BIN : $bin2fill;
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
        $profile = AA_Profile::getProfile($auth->auth["uid"], $this->getId()); // current user settings

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

class AA_Module_Site extends AA_Module {
    const SETTING_CLASS = 'AA_Modulesettings_Site';
    const SETTING_TABLE = 'site';

    /** for translated fields - if not translated, use default language of the module */
    function getDefaultLang() {
        if (($translate_slice = $this->getProperty('translate_slice')) AND is_array($translations = AA_Slice::getModule($translate_slice)->getProperty('translations'))) {
            return $translations[0];
        }
        return $this->getLang();
    }

    function getSite($apc_state) {
        global $show_ids; // @todo - convert to object variable

        $tree        = unserialize($this->getProperty('structure'));   // new sitetree();
        $show_ids    = array();
        $out         = '';

        // it fills $show_ids array
        $tree->walkTree($apc_state, 1, 'ModW_StoreIDs', 'cond');
        if (count($show_ids)<1) {
            exit;
        }

        $spots =  DB_AA::select(array('spot_id'=>array()), 'SELECT spot_id, content, flag from site_spot', array(array('site_id', $this->module_id, 'l'), array('spot_id', $show_ids, 'i')));

        foreach ( $show_ids as $v ) {
            $out .= ( ($spots[$v]['flag'] & MODW_FLAG_JUST_TEXT) ? $spots[$v]['content'] : AA_Stringexpand::unalias($spots[$v]['content'], '', $apc_state['item']));
        }
        return $out;
    }

    function getRelatedSlices() {
        return array_map('unpack_id', DB_AA::select('', 'SELECT destination_id FROM relation', array(array('source_id', $this->module_id, 'l'), array('flag', REL_FLAG_MODULE_DEPEND, 'i'))));
    }

    /** AA_Module_Site::_deleteModules() function - called automaticaly form AA_Module::deleteModules()
     *  @param $module_id
     */
    static public function _deleteModules($module_ids) {
        if (!is_array($module_ids) OR !count($module_ids)) {
            return false;     // _m("No such module.")
        }

        DB_AA::delete_low_priority('site_spot',   array(array('site_id', $module_ids, 'l')));
        DB_AA::delete_low_priority('site',        array(array('id', $module_ids, 'l')));
        return true;
    }
}

class AA_Module_Alerts extends AA_Module {
    // const SETTING_CLASS = 'AA_Modulesettings_Site';
    const SETTING_TABLE = 'alerts_collection';

    /** AA_Module_Alerts::_deleteModules() function - called automaticaly form AA_Module::deleteModules()
     *  @param $module_id
     */
    static public function _deleteModules($module_ids) {
        if (!is_array($module_ids) OR !count($module_ids)) {
            return false;     // _m("No such module.")
        }

        if ( !count($collectionids = DB_AA::select('', 'SELECT id FROM `alerts_collection`', array(array('module_id', $module_ids, 'l'))))) {
            DB_AA::delete_low_priority('alerts_collection',                                  array(array('module_id', $module_ids, 'l')));
            return true;
        }
        DB_AA::delete_low_priority('alerts_collection_filter',   array(array('collectionid', $collectionids)));
        DB_AA::delete_low_priority('alerts_collection_howoften', array(array('collectionid', $collectionids)));
        DB_AA::delete_low_priority('alerts_collection',          array(array('id', $collectionids)));
        return true;
    }
}

class AA_Module_Links extends AA_Module {
    // const SETTING_CLASS = 'AA_Modulesettings_Site';
    // const SETTING_TABLE = 'alerts_collection';

    /** AA_Module_Links::_deleteModules() function - called automaticaly form AA_Module::deleteModules()
     *  @param $module_id
     */
    static public function _deleteModules($module_ids) {
        if (!is_array($module_ids) OR !count($module_ids)) {
            return false;     // _m("No such module.")
        }
        DB_AA::delete_low_priority('links',   array(array('id', $module_ids, 'l')));
        return true;
    }
}

class AA_Module_Polls extends AA_Module {
    // const SETTING_CLASS = 'AA_Modulesettings_Site';
    // const SETTING_TABLE = 'alerts_collection';

    /** AA_Module_Links::_deleteModules() function - called automaticaly form AA_Module::deleteModules()
     *  @param $module_id
     */
    static public function _deleteModules($module_ids) {
        if (!is_array($module_ids) OR !count($module_ids)) {
            return false;     // _m("No such module.")
        }

        if ( !count($pollids = DB_AA::select('', 'SELECT id FROM `polls`', array(array('module_id', $module_ids, 'l'))))) {
            DB_AA::delete_low_priority('polls',                            array(array('module_id', $module_ids, 'l')));
            return true;
        }
        DB_AA::delete_low_priority('polls_ip_lock', array(array('poll_id',   $pollids)));
        DB_AA::delete_low_priority('polls_answer',  array(array('poll_id',   $pollids)));
        DB_AA::delete_low_priority('polls_design',  array(array('module_id', $module_ids, 'l')));
        DB_AA::delete_low_priority('polls',         array(array('module_id', $module_ids, 'l')));
        return true;
    }
}

/** Slice settings */
class AA_Modulesettings_Slice extends AA_Object {

    // must be protected or public - AA_Object needs to read it
    protected $translations;

    /** do not display Name property on the form by default */
    const USES_NAME = false;

    /** check, if the $prop is the property of this object */
    static function isProperty($prop) {
        return in_array($prop, array('translations', 'autofields'));
    }

    /** allows storing object in database
     *  AA_Object's method
     */
    static function getClassProperties() {
        return array ( //                        id             name                            type     multi  persist validator, required, help, morehelp, example
            'translations' => new AA_Property( 'translations', _m("Languages for translation"), 'string', true, true, new AA_Validate_Regexp(array('pattern'=>'/^[a-z]{2}$/', 'maxlength'=>2)), false, _m('specify language codes in which you want translate content - small caps, two letters - like: en, es, de, ...')),
            'autofields'   => new AA_Property( 'autofields',   _m("Automatic field creation"),  'bool',  false, true, 'bool', false, _m('If checked, slice allows storing values to text....* field, even if the appropriate text....* is not defined in the slice. The field will be created using field text............ as template.')),
            'fielditems'   => new AA_Property( 'fielditems',   _m("Fields defined by items"),  'text',   false, true, '', false, _m('You can use special slice for additional fields definition. There you can write IDs if items, which defines the fields, or better something like {ids:ac4940d37d6601ce969dc9a7e41826fc}.<br>Could be used for data slice, which stores values for user created forms.'))
            );
    }
}

/** Slice settings */
class AA_Modulesettings_Site extends AA_Object {

    // must be protected or public - AA_Object needs to read it
    protected $translation_slice;
    protected $additional_aliases;

    /** do not display Name property on the form by default */
    const USES_NAME = false;


    /** check, if the $prop is the property of this object */
    static function isProperty($prop) {
        return in_array($prop, array('translate_slice', 'add_aliases', 'web_languages', 'page404', 'page404_code', 'sitemap_alias', 'perm_alias', 'loginpage_code'));
    }

    /** allows storing object in database
     *  AA_Object's method
     */
    static function getClassProperties() {
        return array ( //                             id             name                                 type     multi  persist validator, required, help, morehelp, example
            'translate_slice' => new AA_Property( 'translate_slice',  _m("Slice with translations"), 'string', false, true, array('enum', AA_Modules::getUserModules('S')), false, _m("the slice used for {tr:text...} translations (the slice needs to have just headline........ field set as 'Allow translation')")),
            'add_aliases'     => new AA_Property( 'add_aliases',      _m("Additional aliases"),      'string', true,  true, array('enum',   AA_Modules::getUserModules('W')), false, _m('Select sitemodule, where we have to look for additional {_:...} aliases')),
            'web_languages'   => new AA_Property( 'web_languages',    _m("Languages on website"),    'string', true,  true, array('regexp', array('pattern'=>'/^[a-z]{2}$/','maxlength'=>2)), false, _m('List all languages for which you want to use sitemodule - en, es, cz, ..<br>It is quite necessary if you want to call sitemodule newer way from Apache:<br> RewriteEngine on<br> RewriteRule ^$ /apca-aa/modules/site/site.php3 [L,QSA]<br> RewriteCond %{REQUEST_FILENAME} !-f<br> RewriteCond %{REQUEST_FILENAME} !-d<br> RewriteRule ^ /apc-aa/modules/site/site.php3 [L,QSA]<br>')),
            'page404'         => new AA_Property( 'page404',          _m("Page not Found (404)"),    'string', false, true, array('enum',   array('1'=>_m('Do not care'),'2'=>_m('Send standard 404 page, when {xid} is empty'),'3'=>_m('Send code below'))), false, _m('When first option "Do not care" is selected (old behavior), you should test unfilled {xid} in your sitemodule yourself')),
            'page404_code'    => new AA_Property( 'page404_code',     _m("HTML code for \"Page not Found\" (404)"), 'text', false, true),
            'sitemap_alias'   => new AA_Property( 'sitemap_alias',    _m("sitemap.xml alias"),       'string', false, true, '', false, _m('The sitemap.xml will be generated from all slices (in order above) where the specified alias (say _#SMAP_URL) exists. The alias shoud in each slice generate full url of the item. For private/hidden items it shopuld generate empty string in order the URL is not shown.')),
            'perm_alias'      => new AA_Property( 'perm_alias',       _m("Permission alias"),        'string', false, true, '', false, _m('You can define the alias, which will be checked for all the displayed {xid} (=pages). If the value will be word "Valid", then the page will be shown. Otherwise the loginform (see below) will be shown. Of course, you do not need to use this feature and do it older way - directly in the tree of sitemodule.')),
            'loginpage_code'  => new AA_Property( 'loginpage_code',   _m("HTML code for \"Login page\""), 'text', false, true)
            );
    }
}








// deprecated - @todo - remove it - move to AA_Module
class AA_Modules {

    // Store the single instance of Database
    private static $_instance;

    var $a = array();     // Array unpacked module id -> AA_Module object

    private function __construct() {
        $this->a = array();
    }

    public static function singleton() {
        if(!isset(self::$_instance)) {
            self::$_instance = new AA_Modules();
        }
        return self::$_instance;
    }

    /** @return array id => name of current user's modules
     *  @param $module_type
     *  @param $perm
     *  @param $user_id
     */
    public static function getUserModules( $module_type = '', $perm = PS_EDIT_SELF_ITEMS, $user_id = '') {
        global $auth;

        $where_add = empty($module_type) ? '' : "AND type='$module_type'";
        $all_modules = GetTable2Array("SELECT id, name FROM module WHERE deleted=0 $where_add ORDER BY priority, name", 'unpack:id', 'name');

        if (empty($user_id)) {
            $user_id =  $auth->auth["uid"];
        }

        $ret = array();
        foreach($all_modules as $mid => $mname) {
            if (CheckPerms( $user_id, 'slice', $mid, $perm)) {
                $ret[$mid] = $mname;
            }
        }

        return $ret;
    }

    /** main factory static method: $module = AA_Modules::getModule($module_id);
     *  @param $module_id
     */
    function getModule($module_id) {
        return AA_Modules::singleton()->_getModule($module_id);
    }

    /** getModuleProperty function
     *  static function
     * @param $module_id
     * @param $field
     */
    function getModuleProperty($module_id, $prop) {
        $module = AA_Modules::getModule($module_id);
        return $module ? $module->getProperty($prop) : null;
    }

    /** _getModule function
     * @param $module_id
     */
    function _getModule($module_id) {
        if (!isset($this->a[$module_id])) {
            $this->a[$module_id] = new AA_Module($module_id);
        }
        return $this->a[$module_id];
    }
}

?>
