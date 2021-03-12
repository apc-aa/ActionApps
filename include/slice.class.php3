<?php
/**
 * A class for manipulating slices
 *
 * PHP version 7.2+
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
 * @link      https://www.apc.org/ APC
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

use AA\IO\DB\DB_AA;
use AA\Widget\Widget;

require_once __DIR__."/zids.php3"; // Pack and unpack ids

class AA_Module {
    /** define, which class is used for module setting - like AA_Modulesettings_Slice */
    const SETTING_CLASS = '';
    const SETTING_TABLE = '';

    /** array of already constructed modules */
    protected static $_modules     = [];  // Array unpacked module id -> AA_Module object
    protected static $_module_types= [];  // Array unpacked module id -> type

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
     * @return bool|mixed|string
     */
    static public function getModuleName($module_id) {
        $module = static::getModule($module_id);
        return $module ? $module->getName() : '';
    }

    /** get module - called as:
     *   $site        = AA_Module_Site::getModule($module_id)
     *   $slice       = AA_Slice::getModule($module_id)
     *   $some_module = AA_Module::getModule($module_id)  // this is not recommended - we usualy know, which module it should be
     *  @param $module_id
     * @return AA_Module|AA_Slice|AA_Module_Site|AA_Module_Alerts|AA_Module_Links|AA_Module_Polls
     */
    static public function getModule($module_id) {
        if (!is_long_id($module_id)) {
            return null;
        }
        if (!isset(AA_Module::$_modules[$module_id])) {
            if (($class = get_called_class()) == 'AA_Module') {
                // this is not usual case - use it just if you do not know what module is it
                $class = AA_Module::getModuleInfo('class', $module_id);
            }
            if (!$class) {
                return null;
            }
            AA_Module::$_modules[$module_id] =  new $class($module_id);
        }
        return AA_Module::$_modules[$module_id];
    }

    /** AA_Module::getModuleInfo function
     * @param $info string
     * @param $module_id
     * @return string
     */
    static public function getModuleInfo($info, $module_id)
    {
        static $MODULEINFO = [
            //                   class             url                         menu
            'W'      => ['AA_Module_Site',  'modules/site/index.php3',   'modules/site/menu.php3'],
            'S'      => ['AA_Slice',        'admin/index.php3',          'include/menu.php3'],
            'Alerts' => ['AA_Module_Alerts','modules/alerts/index.php3', 'modules/alerts/menu.php3'],
            'J'      => ['',                'modules/jump/index.php3',   'include/menu.php3'],
            'P'      => ['AA_Module_Polls', 'modules/polls/index.php3',  'modules/polls/menu.php3'],
            'Links'  => ['AA_Module_Links', 'modules/links/index.php3',  'modules/links/menu.php3']
        ];
        static $_module_types = [];

        if (empty($_module_types)) {
            $_module_types = DB_AA::select(['unpackid' => 'type'], 'SELECT LOWER(HEX(`id`)) AS unpackid, `type` FROM `module`');
        }

        $type = $_module_types[$module_id] ?: 'S';
        switch ($info) {
            case 'type':
                return $type;
            case 'class':
                return $MODULEINFO[$type][0];
            case 'url':
                return $MODULEINFO[$type][1];
            case 'menu':
                return $MODULEINFO[$type][2];
        }
        return '';
    }


    /** AA_Module::getModuleType function
     * @param $module_id
     * @return string
     */
    static public function getModuleType($module_id) {
        return self::getModuleInfo('type', $module_id);
    }


    /** AA_Module::deleteModules function
     * @param $module_id
     * @return bool
     */
    static public function deleteModules($module_ids) {
        foreach ($module_ids as $module_id) {
            if (!is_long_id($module_id) OR !($class = AA_Module::getModuleInfo('class', $module_id))) {
                return false;     // _m("No such module.")
            }
            if (!$class::_deleteModules([$module_id])) {
                return false;
            }

            AA_Object::deleteObjects(AA_Object::getOwnersObjects($module_id));

            // delete module from module table
            DB_AA::delete_low_priority('module', [['id', $module_id, 'l']]);
            DelPermObject($module_id, "slice");   // delete module from permission system
        }
        return true;
    }

    /** getModuleProperty function
     *  static function
     * @param $module_id
     * @param $prop
     * @return bool|mixed|null
     */
    static public function getModuleProperty($module_id, $prop) {
        $module = static::getModule($module_id);
        return $module ? $module->getProperty($prop) : null;
    }

    protected function _isModuleTableProperty($prop) {
        return AA::Metabase()->isColumn('module', $prop);
    }

    protected function _getModuleTableProperty($prop) {
        if (is_null($this->module_setting)) {
            $this->module_setting = DB_AA::select1('', 'SELECT name, deleted, slice_url, lang_file, created_at, created_by, owner, app_id, priority, flag FROM `module`', [['id', $this->module_id, 'l']]);
            if (!is_array($this->module_setting)) {
                $this->module_setting = [];
            }
        }
        return isset($this->module_setting[$prop]) ? $this->module_setting[$prop] : false;
    }

    protected function _isSpecificTableProperty($prop) {
        return static::SETTING_TABLE && AA::Metabase()->isColumn(static::SETTING_TABLE, $prop);
    }

    protected function _getSpecificTableProperty($prop) {
        if (is_null($this->table_setting)) {
            $this->table_setting = DB_AA::select1('', 'SELECT * FROM `' . static::SETTING_TABLE . '`', [['id', $this->module_id, 'l']]);
            if (!is_array($this->table_setting)) {
                $this->table_setting = [];
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

    public static function processModuleObject($module_id) {
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

    public static function getModuleObjectForm($module_id) {
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
        $name = $this->_getModuleTableProperty('name');
        return $this->deleted() ? "&times;&times;&times; $name &times;&times;&times;" : $name;
    }

    /** Checks, if the module_id is OK and the slice is not deleted */
    function isValid() {
        return !$this->deleted();
    }

    /** Checks, if the module_id is OK and the slice is not deleted  */
    function deleted() {
        return $this->_getModuleTableProperty('deleted');
    }

    function getProperty($prop) {
        if ($this->_isSpecificTableProperty($prop)) {
            return $this->_getSpecificTableProperty($prop);
        }
        if ($this->_isModuleTableProperty($prop)) {
            return $this->_getModuleTableProperty($prop);
        }
        if ($this->_isModuleObjectProperty($prop)) {
            return $this->_getModuleObjectProperty($prop);
        }
        return null;
    }

    /** getLang function
     *  Returns lang code ('cz', 'en', 'en-utf8', 'de',...)
     */
    function getLang()     {
        return AA_Langs::getLang($this->_getModuleTableProperty('lang_file'));
    }

    /** getCharset function
     *  Returns character encoding for the slice ('windows-1250', ...)
     */
    function getCharset()     {
        return AA_Langs::getCharset($this->getLang());   // like 'windows-1250'
    }

    function getDefaultLang() {
        return $this->getLang();
    }

    static function getUsedModules() {
        return array_keys(AA_Module::$_modules);
    }

    /** @return array id => name of current user's modules
     *  @param $module_type
     *  @param $perm
     *  @param $user_id
     */
    public static function getUserModules( $module_type = '') {
        global $auth;

        $where_add = empty($module_type) ? '' : "AND type='$module_type'";
        $all_modules = GetTable2Array("SELECT id, name FROM module WHERE deleted=0 $where_add ORDER BY priority, name", 'unpack:id', 'name');

        $user_id =  $auth->auth["uid"];

        return array_filter($all_modules, function ($mid) use ($user_id) {
            return CheckPerms($user_id, 'slice', $mid, PS_EDIT_SELF_ITEMS);
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param array $module_ids
     * @return array - clean list of active (not deleted) unique modules
     */
    public static function filterActive( array $module_ids) {
        static $active_modules = null;
        if ( is_null($active_modules)) {
            $active_modules = DB_AA::select('', 'SELECT LOWER(HEX(`id`)) AS unpackid FROM module', [['deleted', 0, 'i']], ['created_at-']);
        }
        return array_intersect($active_modules, $module_ids);    // do not change the order - this way it is always unique
    }

    /** find all sites, where the module belongs
     * @param string $module_id
     * @return array
     */
    public static function belongsToSites(string $module_id): array {
        return AA_Module::filterActive(DB_AA::select('', 'SELECT LOWER(HEX(`source_id`)) AS unpackid FROM relation', [['destination_id', $module_id, 'l'], ['flag', REL_FLAG_MODULE_DEPEND, 'i']]));
    }
}

class AA_Slice extends AA_Module {
    protected $name;                   // The name of the slice
    protected $fields;                 // 2 member array( $fields, $prifields)
    protected $dynamic_fields;         // 2 member array( $fields, $prifields) == slice_fields
    protected $dynamic_setting = null; // dynamic slice setting fields stored in content table

    const SETTING_CLASS = 'AA_Modulesettings_Slice';
    const SETTING_TABLE = 'slice';

    // computed values form slice fields
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
            parent::_getSpecificTableProperty($prop);
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
        if ( !$force AND is_object($this->dynamic_setting) ) {
            return;
        }
        $db = getDB();
        $SQL = "SELECT * FROM content WHERE item_id = '".q_pack_id($this->module_id)."' ORDER BY content.number";
        $db->query($SQL);
        $content4id = [];
        while ($db->next_record()) {
            // which database field is used (from 05/15/2004 we have FLAG_TEXT_STORED set for text-field-stored values
            $db_field = ( ($db->f("text")!="") OR ($db->f("flag") & FLAG_TEXT_STORED) ) ? 'text' : 'number';
            $content4id[$db->f("field_id")][] = [
                "value" => $db->f($db_field),
                "flag"  => $db->f("flag")
            ];
        }
        freeDB($db);
        $this->dynamic_setting = new ItemContent($content4id);
    }

    /** getProperty function
     * @param $fname
     * @return bool|mixed|null
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
     * @param bool $fname
     * @return bool
     */
    function isField($fname) {
        return $this->fields->isField($fname);
    }

    /** jumpLink function
     * @return string
     */
    function jumpLink() {
        return "<a href=\"".get_admin_url("index.php3?change_id=".$this->getId()). "\">".$this->getName()."</a>";
    }

    /** type function
     *
     */
    function type() {
        return $this->getProperty('type');
    }

    /**
     * @return bool
     */
    function isExpiredContentAllowed() {
        return ($this->getProperty('flag') & SLICE_ALLOW_EXPIRED_CONTENT) == SLICE_ALLOW_EXPIRED_CONTENT;
    }

    /**
     * @return bool
     */
    function isPendingContentAllowed() {
        return ($this->getProperty('flag') & SLICE_ALLOW_PENDING_CONTENT) == SLICE_ALLOW_PENDING_CONTENT;
    }

    public function countItemsInBins() {
        $now = now('step');
        $ret = ['folder1'=>0, 'folder2'=>0, 'folder3'=>0, 'expired'=>0, 'pending'=>0];

        $ret = array_merge($ret, DB_AA::select(['fldr'=>'cnt'], "SELECT concat('folder',status_code) as fldr, count(*) as cnt FROM item ". DB_AA::makeWhere([['slice_id', $this->module_id, 'l']]). ' GROUP BY status_code'));
        $ret['expired'] = DB_AA::select1('cnt', "SELECT count(*) as cnt FROM item ", [
            ['slice_id', $this->module_id, 'l'],
            ['status_code', 1],
            ['expiry_date', $now, '<=']
        ]);
        $ret['pending'] = DB_AA::select1('cnt', "SELECT count(*) as cnt FROM item ", [
            ['slice_id', $this->module_id, 'l'],
            ['status_code', 1],
            ['publish_date', $now, '>'],
            ['expiry_date', $now, '>']
        ]);
        $ret['app']     = $ret['folder1']-$ret['pending']-$ret['expired'];

        return $ret;
    }

    /** AA_Slice::_deleteModules() function - called automatically form AA_Module::deleteModules()
     * @param $module_id
     * @return bool
     */
    static public function _deleteModules($module_ids) {
        if (!is_array($module_ids) OR !count($module_ids)) {
            return false;     // _m("No such module.")
        }
        // deletes from content, offline and relation tables
        AA_Items::deleteItems(new zids(DB_AA::select('id', 'SELECT id FROM `item`', [['slice_id', $module_ids, 'l']]),'p'));

        // now performed in AA_Items::deleteItems
        // DB_AA::delete_low_priority('item',  array(array('slice_id', $module_ids, 'l')));

        DB_AA::delete_low_priority('alerts_collection',  [['slice_id',        $module_ids, 'l']]);
        DB_AA::delete_low_priority('constant_slice',     [['slice_id',        $module_ids, 'l']]);
        DB_AA::delete_low_priority('email',              [['owner_module_id', $module_ids, 'l']]);
        DB_AA::delete_low_priority('email_notify',       [['slice_id',        $module_ids, 'l']]);
        DB_AA::delete_low_priority('external_feeds',     [['slice_id',        $module_ids, 'l']]);
        DB_AA::delete_low_priority('feedmap',            [['from_slice_id',   $module_ids, 'l']]);
        DB_AA::delete_low_priority('feedmap',            [['to_slice_id',     $module_ids, 'l']]);
        DB_AA::delete_low_priority('feedperms',          [['from_id',         $module_ids, 'l']]);
        DB_AA::delete_low_priority('feedperms',          [['to_id',           $module_ids, 'l']]);
        DB_AA::delete_low_priority('field',              [['slice_id',        $module_ids, 'l']]);
        DB_AA::delete_low_priority('jump',               [['dest_slice_id',   $module_ids, 'l']]);
        if (DB_AA::exists_table('mysql_auth_group')) {
            DB_AA::delete_low_priority('mysql_auth_group',   [['slice_id',        $module_ids, 'l']]);
        }
        if (DB_AA::exists_table('mysql_auth_userinfo')) {
            DB_AA::delete_low_priority('mysql_auth_userinfo', [['slice_id',        $module_ids, 'l']]);
        }
        DB_AA::delete_low_priority('profile',            [['slice_id',        $module_ids, 'l']]);
        DB_AA::delete_low_priority('rssfeeds',           [['slice_id',        $module_ids, 'l']]);
        DB_AA::delete_low_priority('slice',              [['id',              $module_ids, 'l']]);
        DB_AA::delete_low_priority('view',               [['slice_id',        $module_ids, 'l']]);

        return true;
    }

    /** getFields function
     * @param bool $dynamic_fields
     * @return AA_Fields
     */
    function getFields($dynamic_fields = false): AA_Fields {
        return $dynamic_fields ? $this->dynamic_fields : $this->fields;
    }

    /** getField function
     *  @return AA_Field
     */
    function getField($field_id) {
        return $this->fields->getField($field_id);
    }

    /** @return Widget */
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

    /** find default field for upload to - first file...........x or img_source.....x field
     */
    function getDefaultUploadField() {
        $flds = $this->getFields();
        return (($fid = $flds->getCategoryFieldId('file')) OR ($fid = $flds->getCategoryFieldId('img_upload'))) ? $fid : '';
    }

    /** sql_id function - removed - use DB_AA::select1('SELECT * FROM `slice`', '', array(array('id',$slobj->getId(), 'l'))); */
    // function sql_id()      { return q_pack_id($this->module_id); }


    /** fields function
     *  fetch the fields
     * @param $return_type
     * @param $slice_fields
     * @return array with two elements [0] is array in form
     * wanted by Storeitem etc, [1] is array of fields in priority order
     * @deprecated - use getFields() and getField() instead
     */
    function fields( $return_type = null, $slice_fields = false ) {

        $fields = $slice_fields ? $this->dynamic_fields : $this->fields;

        switch ( $return_type ) {
            case 'pri':     return $fields->getPriorityArray();  // array of field definitions sorted by priority - integer key
            case 'name':    return $fields->getNameArray();

        }
        return [$fields->getRecordArray(), $fields->getPriorityArray()];                         // two member array ('record' array, 'pri' array)
    }

    /**
     * @return \AA\Util\Searchfields
     */
    function getSearchfields() {
        return $this->fields->getSearchfields();
    }

    /** get_dynamic_setting_content function
     *  Returns slice setting field content in ItemContent object
     * @param $ignore_reading_password
     * @return null|ItemContent
     */
    function get_dynamic_setting_content($ignore_reading_password = false) {
        if (!$ignore_reading_password) {
            if (!AA_Credentials::singleton()->checkCryptedPassword($this->getProperty('reading_password'))) {
                if ($GLOBALS['errcheck'] OR $GLOBALS['debug']) {
                    huhe(_m("Error: Missing Reading Password"));
                }
                return null;
            }
        }
        $this->loadsettingfields();
        return $this->dynamic_setting;
    }

    /** getUploadBase function
     *  Get the base for the file uploads
     */
    function getUploadBase() {
        $ret = [];
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
     * @return string
     */
    function getUrlFromPath($filename) {
        $upload = $this->getUploadBase();
        if (strpos($filename, $upload['path']) === 0) {
            return $upload['url']. substr($filename,strlen($upload['path']));
        }
        return $filename;
    }

    /** get_format_strings function
     *  Returns array of admin format strings as used in manager class
     */
    function get_format_strings() {
         // additional string for compact_top and compact_bottom needed
         // for historical reasons (not manager.class verion of item manager)
         return [
             "compact_top"     => '<table border="0" cellspacing="0" cellpadding="1" bgcolor="#F5F0E7" class="mgr_table">'.
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
                        "id"              => pack_id($this->module_id)
         ];
    }

    /** aliases function
     *  Get standard aliases definition from slice's fields
     * @param $additional_aliases
     * @return array|null|string
     */
    function aliases($additional_aliases = false, $type='') {
        return $this->fields->getAliases($additional_aliases, $type);
    }

    /**
     * @return int
     */
    function allowed_bin_4_user() {
        // put the item into the right bin
        $bin2fill = IfSlPerm(PS_EDIT_ALL_ITEMS, $this->module_id) ? 1 : (int) $this->getProperty("permit_anonymous_post");
        return ($bin2fill < 1) ? SC_NO_BIN : $bin2fill;
    }

    /** get_show_func_used function
     *  Returns array of inputform function used the in inputform
     * @param string $id         - longID of item to edit
     * @param array $shown_fields - array of field ids which we will use in the output
     *                           (inputform)(we have to count with them).
     *                           If false, then we use all the fields
     * @param bool $slice_fields
     * @return array
     */
    public function get_show_func_used($id, array $shown_fields, $slice_fields=false): array {
        $show_func_used = [];

        global $auth;
        $profile = AA_Profile::getProfile($auth->auth["uid"], $this->getId()); // current user settings

        // get slice fields and its priorities in inputform
        $fields      = $this->getFields($slice_fields);

        // it is needed to call IsEditable() function and GetContentFromForm()
        if ( $id ) {
            $oldcontent = GetItemContent($id);
            $oldcontent4id = $oldcontent[$id];   // shortcut
        }

        foreach ($fields as $pri_field_id => $field) {

            //  'status_code.....' is not in condition - could be set from defaults
            if (($pri_field_id=='edited_by.......') || ($pri_field_id=='posted_by.......')) {
                continue;   // filed by AA - it could not be filled here
            }

            // prepare javascript function for validation of the form
            if ( $shown_fields[$pri_field_id] AND IsEditable($oldcontent4id[$pri_field_id], $field, $profile) ) {

                // fill show_func_used array - used on several places
                // to distinguish, which javascripts we should include and
                // if we have to use form multipart or not
                [$show_func] = explode(":", $field->getProperty("input_show_func"), 2);
                $show_func_used[$show_func] = true;
            }
        }
        return $show_func_used;
    }
}

class AA_Module_Site extends AA_Module {
    const SETTING_CLASS = 'AA_Modulesettings_Site';
    const SETTING_TABLE = 'site';


    const FLAG_DISABLE   = 1;   // deactivated spot
    const FLAG_JUST_TEXT = 2;   // not used - planed for site speedup
    // (= flag means "don't stringexpand text)"
    const FLAG_COLLAPSE  = 4;   // (visualy) Collapsed spot


    protected $_related_slices = null;
    protected $_related_views  = null;


    /** get current site id
     *  @return string|false site_id
     */
    public static function getIdFromUrl() {
        $host2  = (strpos($host = $_SERVER['HTTP_HOST'], 'www.')===0) ? substr($host,4) : 'www.'.$host;
        $domain_arr = ["http://$host/", "https://$host/","http://$host", "https://$host","http://$host2/", "https://$host2/","http://$host2", "https://$host2"];
        return DB_AA::select1('unpackid', "SELECT LOWER(HEX(id)) AS unpackid FROM `module`", [
            ['type', 'W'],
            ['deleted', '0', 'i'],
            ['slice_url', $domain_arr]
        ]);
    }


    /** for translated fields - if not translated, use default language of the module */
    function getDefaultLang() {
        if (($translate_slice = $this->getProperty('translate_slice')) AND is_array($translations = AA_Slice::getModuleProperty($translate_slice,'translations'))) {
            return $translations[0];
        }
        return $this->getLang();
    }

    /** joins output of expanded sitespots
     * @param string      $module_id
     * @param int[]       $spot_ids - array of spots to
     * @param string|null $item - item for whit we evaluate
     * @return string
     */
    static function spotsOutput($module_id, $spot_ids, $item = null) {
        $out = '';
        $spots =  DB_AA::select(['spot_id'=> []], 'SELECT spot_id, content, flag from site_spot', [['site_id', $module_id, 'l'], ['spot_id', $spot_ids, 'i']]);

        foreach ( $spot_ids as $v ) {
            $out .= ($spots[$v]['flag'] & AA_Module_Site::FLAG_JUST_TEXT) ? $spots[$v]['content'] : AA::Stringexpander()->unalias($spots[$v]['content'], '', $item);
        }
        return $out;
    }

    function getSite($apc_state) {
        $tree        = $this->getTree();   // new sitetree();
        $show_ids    = [];

        // it fills $show_ids array
        $tree->walkTree($apc_state, 1, function ($spot_id) use (&$show_ids) { $show_ids[] = $spot_id; }, 'cond');

        if (count($show_ids)<1) {
            exit;
        }

        $out = AA_Module_Site::spotsOutput($this->module_id, $show_ids, $apc_state['item']);

        // trim remove whitespaces from start and end (mainly the start - it is better to start right with the code)
        return trim(AA::Stringexpander()->postprocess($out));
    }

    public function getTree() {
        $tree = null;
        if ($structre = $this->getProperty('structure')) {
            $tree = unserialize($structre);
        } elseif ($spotrow = DB_AA::select1('', "SELECT * FROM site_spot", [
            ['site_id', $this->module_id, 'l'],
            ['spot_id', '1']
        ])) {  // false if not found
            // get information about start spot (=start of the tree)
            $tree = new sitetree($spotrow);
        }
        return ($tree instanceof sitetree) ? $tree : null;
    }

    /**
     * @param      $tree
     * @param bool $clean_unused
     */
    public function saveTree($tree, $clean_unused=false) {
        DB_AA::update('site', [['structure', serialize($tree)]], [['id',$this->module_id, 'l']]);
        if ($clean_unused) {
            $this->cleanTree($tree);
        }
    }

    /** Delete all unused spots in the site
     * @param sitetree $tree
     */
    public function cleanTree($tree) {
        $show_ids  = $tree->getAllSpotIds();
        $all_spots = DB_AA::select(['id'=>'spot_id'], 'SELECT id, spot_id FROM site_spot', [['site_id', $this->module_id, 'l']]);
        if ( $ids2delete = array_keys(array_diff($all_spots, $show_ids)) ) {
            DB_AA::delete('site_spot', [['site_id', $this->module_id, 'l'], ['id',$ids2delete,'i']]);
        }
    }

    /** site editing functions */



    public function showSpot($tree, $spot_id) {
        $spot_data = DB_AA::select1('', 'SELECT id, content FROM site_spot', [
            ['site_id', $this->module_id, 'l'],
            ['spot_id', $spot_id, 'i']
        ]);
        FrmTabCaption();
        FrmStaticText(_m('Spot ID'), "[$spot_id] - " . _m('You can include this spot code in another spot by using {sitespot:%1}', [$spot_id]));

        ModW_PrintVariables($spot_id, $tree->get('variables',$spot_id));
        if (($vars=$tree->isOption($spot_id))) {
            ModW_PrintConditions($spot_id, $tree->get('conditions',$spot_id), $vars);
        }
        FrmTabEnd();

        $form_buttons= ['submit',
                        "cancel"=> [
                            'value'=>_m('Show History'),
                            "url"  =>\AA\Util\ChangesMonitor::getHistoryUrl("S.$spot_data[id]")
                        ]
        ];

        echo "<br><form method='post' name=fs action=\"". $_SERVER['PHP_SELF'] ."\">";
        FrmTabCaption('',$form_buttons);
        ModW_HiddenRSpotId();
        FrmInputText('name', _m("Spot name"), $tree->get('name', $spot_id), 50, 50, true, false, false, false);
        FrmTextarea('content', '', $spot_data['content'], 50, 80, false, AA_View::getViewJumpLinks($spot_data['content']), "", true);
        FrmTabEnd($form_buttons);
        echo "</form>";
    }



    /**
     * @return AA_Router|null
     */
    public function getRouter() {
        if ($this->_getSpecificTableProperty('flag') == 1) {    // 1 - Use AA_Router_Seo
            // home can contain some logic like: {ifin:{server:SERVER_NAME}:czechweb.cz:/cz/home:/en/home}
            $home       = AA::Stringexpander()->unalias(trim($this->_getSpecificTableProperty('state_file'))) ?: '/' .substr($this->_getModuleTableProperty('lang_file'),0,2). '/';
            return AA_Router::singleton('AA_Router_Seo', $this->getRelatedSlices(), $home, $this->_getModuleObjectProperty('web_languages'));
        }
        return null;
    }

    /**
     * @param string $type
     * @return array
     */
    function getRelatedSlices($type='') {
        if (is_null($this->_related_slices)) {
            // could be rewritten to form:  DB_AA::select(array('unpackid'=>'name'), 'SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `slice_owner` ORDER BY `name`');
            $this->_related_slices = AA_Module::filterActive(DB_AA::select('', 'SELECT LOWER(HEX(`destination_id`)) AS unpackid FROM relation', [['source_id', $this->module_id, 'l'], ['flag', REL_FLAG_MODULE_DEPEND, 'i']]));
        }
        if ($type) {
            // we need to preserve order!
            $type_slices  = DB_AA::select('', 'SELECT LOWER(HEX(`id`)) AS unpackid FROM slice', [['id', $this->_related_slices, 'l'], ['type', $type]]);
            $ret = [];
            foreach ($this->_related_slices as $sl) {
                if (in_array($sl,$type_slices)) {
                    $ret[] = $sl;
                }
            }
            return $ret;
        }
        return $this->_related_slices;
    }

    /** translate view name to view id within all slices of curent sitemodule
     * @param string $vid
     * @return int - 0 if not found
     */
    function findViewId($vid) {
        if (is_null($this->_related_views)) {
            // get translation table name -> id within all slices of curent sitemodule. Must be ordered by id, in order we return the same id even if the same view name is used
            $this->_related_views = DB_AA::select(['field3'=>'id'], 'SELECT id, field3 FROM view', [['slice_id', $this->getRelatedSlices(), 'l'], ['field3', '', 'FILLED']], 'id');
        }
        return $this->_related_views[$vid] ?: 0;
    }

    /** AA_Module_Site::_deleteModules() function - called automaticaly form AA_Module::deleteModules()
     * @param $module_id
     * @return bool
     */
    static public function _deleteModules($module_ids) {
        if (!is_array($module_ids) OR !count($module_ids)) {
            return false;     // _m("No such module.")
        }

        DB_AA::delete_low_priority('site_spot',   [['site_id', $module_ids, 'l']]);
        DB_AA::delete_low_priority('site',        [['id', $module_ids, 'l']]);
        return true;
    }
}

class AA_Module_Alerts extends AA_Module {
    // const SETTING_CLASS = 'AA_Modulesettings_Site';

    // !!! this will not work - id in alerts_collection is not module ID
    const SETTING_TABLE = 'alerts_collection';

    /** AA_Module_Alerts::_deleteModules() function - called automaticaly form AA_Module::deleteModules()
     * @param $module_id
     * @return bool
     */
    static public function _deleteModules($module_ids) {
        if (!is_array($module_ids) OR !count($module_ids)) {
            return false;     // _m("No such module.")
        }

        if ( !count($collectionids = DB_AA::select('', 'SELECT id FROM `alerts_collection`', [['module_id', $module_ids, 'l']]))) {
            DB_AA::delete_low_priority('alerts_collection',                                  [['module_id', $module_ids, 'l']]);
            return true;
        }
        DB_AA::delete_low_priority('alerts_collection_filter',   [['collectionid', $collectionids]]);
        DB_AA::delete_low_priority('alerts_collection_howoften', [['collectionid', $collectionids]]);
        DB_AA::delete_low_priority('alerts_collection',          [['id', $collectionids]]);
        return true;
    }
}

class AA_Module_Links extends AA_Module {
    // const SETTING_CLASS = 'AA_Modulesettings_Site';
    // const SETTING_TABLE = 'alerts_collection';

    /** AA_Module_Links::_deleteModules() function - called automaticaly form AA_Module::deleteModules()
     * @param $module_id
     * @return bool
     */
    static public function _deleteModules($module_ids) {
        if (!is_array($module_ids) OR !count($module_ids)) {
            return false;     // _m("No such module.")
        }
        DB_AA::delete_low_priority('links',   [['id', $module_ids, 'l']]);
        return true;
    }
}

class AA_Module_Polls extends AA_Module {
    // const SETTING_CLASS = 'AA_Modulesettings_Site';
    // const SETTING_TABLE = 'alerts_collection';

    /** AA_Module_Polls::_deleteModules() function - called automaticaly form AA_Module::deleteModules()
     * @param $module_id
     * @return bool
     */
    static public function _deleteModules($module_ids) {
        if (!is_array($module_ids) OR !count($module_ids)) {
            return false;     // _m("No such module.")
        }

        if ( !count($pollids = DB_AA::select('', 'SELECT id FROM `polls`', [['module_id', $module_ids, 'l']]))) {
            DB_AA::delete_low_priority('polls',                            [['module_id', $module_ids, 'l']]);
            return true;
        }
        DB_AA::delete_low_priority('polls_ip_lock', [['poll_id',   $pollids]]);
        DB_AA::delete_low_priority('polls_answer',  [['poll_id',   $pollids]]);
        DB_AA::delete_low_priority('polls_design',  [['module_id', $module_ids, 'l']]);
        DB_AA::delete_low_priority('polls',         [['module_id', $module_ids, 'l']]);
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
        return in_array($prop, ['translations', 'autofields', 'fieldslice', 'historylog']);
    }

    /** allows storing object in database
     *  AA_Object's method
     * @return array
     */
    static function getClassProperties(): array {
        return [ //                        id             name                            type     multi  persist validator, required, help, morehelp, example
            'translations' => new AA_Property( 'translations', _m("Languages for translation"), 'string', true, true, new AA_Validate_Regexp(['pattern'=>'/^[a-z]{2}$/', 'maxlength'=>2]), false, _m('specify language codes in which you want translate content - small caps, two letters - like: en, es, de, ...')),
            'autofields'   => new AA_Property( 'autofields',   _m("Automatic field creation"),  'bool',  false, true, 'bool', false, _m('If checked, slice allows storing values to text....* field, even if the appropriate text....* is not defined in the slice. The field will be created using field text............ as template.')),
            'fieldslice'   => new AA_Property( 'fieldslice',   _m("Fields defined by another slice"), 'string',  false, true, 'id', false, _m('You can use special slice for additional fields definition. There you can write slice ID, which define the fields.<br>Could be used for data slice, which stores values for user created forms.')),
            'historylog'   => new AA_Property( 'historylog',   _m("Store item history for N days"), 'string',  false, true, '', false, _m('ALL - do not delele history log (default)<br>0 - do not store item history (nor ITEM_UPD/ITEM_NEW events in Log)<br><em>number</em> - days after which will be history of items deleted'))
             // do not forget to add new property to  isProperty!
        ];
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
        return in_array($prop, ['translate_slice', 'add_aliases', 'web_languages', 'page404', 'page404_code', 'sitemap_alias', 'perm_alias', 'loginpage_code', 'perm_mode', 'ga_id']);
    }

    /** allows storing object in database
     *  AA_Object's method
     * @return array
     */
    static function getClassProperties(): array {
        return [ //                             id             name                                                type     multi  persist validator, required, help, morehelp, example
            'translate_slice' => new AA_Property( 'translate_slice',  _m("Slice with translations"),                'string', false, true, ['enum', AA_Module::getUserModules('S')], false, _m("the slice used for {tr:text...} translations (the slice needs to have just headline........ field set as 'Allow translation')")),
            'add_aliases'     => new AA_Property( 'add_aliases',      _m("Additional aliases"),                     'string', true,  true, ['enum',   AA_Module::getUserModules('W')], false, _m('Select sitemodule, where we have to look for additional {_:...} aliases')),
            'web_languages'   => new AA_Property( 'web_languages',    _m("Languages on website"),                   'string', true,  true, ['regexp', ['pattern'=>'/^[a-z]{2}$/','maxlength'=>2]], false, _m('List all languages for which you want to use sitemodule - en, es, cz, ..<br>It is quite necessary if you want to call sitemodule newer way from Apache:<br> RewriteEngine on<br> RewriteRule ^$ /apc-aa/modules/site/site.php3 [L,QSA]<br> RewriteCond %{REQUEST_FILENAME} !-f<br> RewriteCond %{REQUEST_FILENAME} !-d<br> RewriteRule ^ /apc-aa/modules/site/site.php3 [L,QSA]<br>')),
            'page404'         => new AA_Property( 'page404',          _m("Page not Found (404)"),                   'string', false, true, ['enum',   ['1'=>_m('Do not care'),'2'=>_m('Send standard 404 page, when {xid} is empty'),'3'=>_m('Send code below')]], false, _m('When first option "Do not care" is selected (old behavior), you should test unfilled {xid} in your sitemodule yourself')),
            'page404_code'    => new AA_Property( 'page404_code',     _m("HTML code for \"Page not Found\" (404)"), 'text',   false, true),
            'sitemap_alias'   => new AA_Property( 'sitemap_alias',    _m("sitemap.xml alias"),                      'string', false, true, '', false, _m('The sitemap.xml will be generated from all slices (in order above) where the specified alias (say _#SMAP_URL) exists. The alias shoud in each slice generate full url of the item. For private/hidden items it should generate empty string in order the URL is not shown.')),
            'perm_alias'      => new AA_Property( 'perm_alias',       _m("Permission alias"),                       'string', false, true, '', false, _m('You can define the alias, which will be checked for all the displayed {xid} (=pages). If the value will be word "Valid", then the page will be shown. Otherwise the loginform (see below) will be shown. Of course, you do not need to use this feature and do it older way - directly in the tree of sitemodule.')),
            'loginpage_code'  => new AA_Property( 'loginpage_code',   _m("HTML code for \"Login page\""),           'text',   false, true),
            'perm_mode'       => new AA_Property( 'perm_mode',        _m("User login databases"),                   'string', false, true, ['enum',  ['1'=>_m('first Reader Management slice listed in Uses slices'),'2'=>_m('all Reader Management slices listed in Uses slices'),'3'=>_m('AA + all Reader Management slices in AA (old behavior)')]], false, _m('First Reader Management slice is encouraged option - only the people from this slice is allowed to login to public pages. If not selected, "AA + all Reader Management slices" option is used.')),
            'ga_id'           => new AA_Property( 'ga_id',            _m("Google analytics tracking id"),           'string', false, true, ['text', ['maxlength'=>20]],  false, _m('If you are using {generate:HEAD} and {generate:FOOT} in yor sitemodule code, then the Google Analytics code is generated with this tracking ID. The generated code could be changed in the future when Google changes the snippet.'))
        ];
    }
}

