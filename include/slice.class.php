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
use AA\Util\Langs;
use AA\Util\Txt;
use AA\Widget\Widget;

require_once __DIR__ . "/zids.php"; // Pack and unpack ids

class AA_Moduleconfig {
    public function __construct(
        protected string $type,
        protected string $name,
        protected string $class,
        protected string $dir,
        protected string $menu,
        protected array  $langs,
        protected bool   $use_templates,
    ) {}

    public function get($prop): string|array|bool {
        return match($prop) {
            'type', 'name', 'class', 'dir', 'menu', 'langs', 'use_templates' => $this->$prop,
            'letter' => $this->type[0],
            'url'    => $this->dir . 'index.php',
            default  => '',
        };
    }
}

class AA_Moduleregistry implements Iterator, ArrayAccess, Countable {
    use \AA\Util\ArrTrait, \AA\Util\SingletonTrait;

    /** array of the values */
    protected array $arr = [];

    private function __construct() {
        $this->registerModules();
    }

    private function registerModules(): void
    {
        // Get all declared classes
        $classes = ["AA_Slice", ...array_values(AA_Components::getClassNames('AA_Module_'))];

        // call getModuleconfig() on each class
        /** @var string $class */
        foreach ($classes as $class) {
            /** @var AA_Moduleconfig $conf */
            $conf = $class::getModuleconfig();
            $this->arr[$conf->get('type')] = $conf;
        }
    }

    /** Returns 'name', 'class', 'dir', 'url', 'menu', 'langs', 'use_templates', 'letter' for module types 'S', 'W', 'Links', ...
     * @see AA_Module::getModuleInfo() for info based on module_id)
     * */
    public static function info(string $type, string $info): string|array|bool {
        return static::singleton()[$type]?->get($info) ?? '';
    }
}


abstract class AA_Module {
    /** define, which class is used for module setting - like AA_Modulesettings_Slice */
    protected const SETTING_CLASS = '';
    protected const SETTING_TABLE = '';

    /** array of already constructed modules */
    protected static array $_modules     = [];  // Array unpacked module id -> AA_Module object
    /** @var array  mark as used for cache - in case we use the module, but do not need $module object itself - like in views  */
    protected static array $_used4cache   = [];

    protected string $module_id;
    protected ?array $module_setting;         // Array of module settings
    protected ?array $table_setting;          // Array of specific table settings
    protected $moduleobject_setting;          // module settings object

    /** it is better to call it using getModule() - $site = AA_Module_Site::getModule($module_id);
     */
    function __construct(string $id) {
        $this->module_id            = $id;
        $this->module_setting       = null;
        $this->table_setting        = null;
        $this->moduleobject_setting = false;
    }

    abstract public static function getModuleconfig(): AA_Moduleconfig;

    /** get module - called as:
     *   $name = AA_Module_Site::getModuleName($module_id)
     *   $name = AA_Slice::getModuleName($module_id)
     * @param      $module_id
     * @param bool $link
     * @return string
     */
    public static function getModuleName($module_id, bool $link=false, ?string $page=null, array $params=[]): string {
        $module = static::getModule($module_id);
        if ($module) {
            return $link ? a_href(static::getModuleUrl($module_id, $page, $params), $module->getName()) : $module->getName();
        }
        return '';
    }

    /** Module switch url
     * @param $module_id
     * @return string
     */
    public static function getModuleUrl($module_id, ?string $page=null, array $params=[]): string {
        return get_aa_url($page ?? AA_Module::getModuleInfo('url',$module_id), array_merge(['change_id'=>$module_id], $params));
    }

    /** get module - called as:
     *   $site        = AA_Module_Site::getModule($module_id)
     *   $slice       = AA_Slice::getModule($module_id)
     *   $some_module = AA_Module::getModule($module_id)  // this is not recommended - we usually know which module it should be
     *  @param string $module_id
     * @return AA_Module|AA_Slice|AA_Module_Site|AA_Module_Alerts|AA_Module_Links|AA_Module_Polls|null
     */
    public static function getModule($module_id): ?AA_Module {
        if (!is_long_id($module_id)) {
            return null;
        }
        if (!isset(AA_Module::$_modules[$module_id])) {
            if (($class = get_called_class()) === 'AA_Module') {
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


    /** AA_Module::getModules() function
     * @param string $type      - S | W | Alerts | J | P | Links
     * @param bool   $validonly - not deleted or all
     * @param array  $sort      - default ['priority','name']
     * @return array module id => name
     */
    public static function getModules(string $type, bool $validonly=false, array $sort=[]): array {
        $conds = [];
        if ($type) {
            $conds[] = ['type', $type];
        }
        if ($validonly) {
            $conds[] = ['deleted', 0, 'i'];
        }
        if (!$sort) {
           $sort = ['priority','name'];
        }
        return DB_AA::select(['unpackid'=>'name'], 'SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `module`', $conds, $sort);
    }

    /** Returns 'class', 'url', 'menu', 'letter' for module
     *
     * @param $info string  - 'class' | 'url' | 'menu' | 'letter' | 'type'
     * @param $module_id string
     *
     * @return string
     */
    public static function getModuleInfo(string $info, $module_id): string
    {
        static $_module_types = [];

        if (empty($_module_types)) {
            $_module_types = DB_AA::select(['unpackid' => 'type'], 'SELECT LOWER(HEX(`id`)) AS unpackid, `type` FROM `module`');
        }

        $type = $_module_types[$module_id] ?: 'S';
        return ($info === 'type') ? $type : AA_Moduleregistry::info($type, $info);
    }

    /** AA_Module::getModuleType function
     * @param $module_id
     * @return string     - S | W | Alerts | J | P | Links
     */
    public static function getModuleType($module_id) {
        return self::getModuleInfo('type', $module_id);
    }

    /** AA_Module::deleteModules function
     * @param array $module_ids
     * @return bool
     */
    public static function deleteModules(array $module_ids): bool {
        foreach ($module_ids as $module_id) {
            /** @var class-string<AA_Module> $class name - it is a string name of class */
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

    public static function _deleteModules(array $module_ids): bool {
        return true;
    }

    /** getModuleProperty function
     *  static function
     * @param $module_id
     * @param $prop
     * @return bool|mixed|null
     */
    public static function getModuleProperty($module_id, $prop) {
        return static::getModule($module_id)?->getProperty($prop);
    }

    protected function _isModuleTableProperty($prop) {
        return AA::Metabase()->isColumn('module', $prop);
    }

    protected function _getModuleTableProperty($prop) {
        if (is_null($this->module_setting)) {
            $this->module_setting = DB_AA::select1('', 'SELECT name, deleted, slice_url, lang_file, created_at, created_by, owner, app_id, priority, flag FROM `module`', [['id', $this->module_id, 'l']]) ?: [];
        }
        return $this->module_setting[$prop] ?? false;
    }

    protected function _isSpecificTableProperty($prop) {
        return static::SETTING_TABLE && AA::Metabase()->isColumn(static::SETTING_TABLE, $prop);
    }

    protected function _getSpecificTableProperty($prop) {
        if (is_null($this->table_setting)) {
            $this->table_setting = DB_AA::select1('', 'SELECT * FROM `' . static::SETTING_TABLE . '`', [['id', $this->module_id, 'l']]) ?: [];
            if ($this->table_setting) {
                unset($this->table_setting['id']);  // do not use it from here - it is packed - use $this->module_id instead
            }
        }
        return $this->table_setting[$prop] ?? false;
    }

    protected function _isModuleObjectProperty($prop) {
        /** @var class-string<AA_AbstractModulesettings> $class name - it is string name of class */
        $class = static::SETTING_CLASS;
        return empty($class) ? null : $class::isProperty($prop);
    }

    protected static function getModuleObjectId($module_id): string {
        return empty(static::SETTING_CLASS) ? '' : string2id(static::SETTING_CLASS . $module_id);
    }

    protected function _getModuleObjectProperty($prop) {
        if ($this->moduleobject_setting === false) {
            // tho object id is derived from SETTING_CLASS name and module_id
            /** @var class-string<AA_AbstractModulesettings> $class name - it is string name of class */
            $class = static::SETTING_CLASS;
            $this->moduleobject_setting = empty(static::SETTING_CLASS) ? null : $class::load(static::getModuleObjectId($this->module_id));
        }
        return is_null($this->moduleobject_setting) ? null : $this->moduleobject_setting->getProperty($prop);
    }

    public static function processModuleObject($module_id) {
        /** @var class-string<AA_AbstractModulesettings> $class name - it is string name of class */
        $class = static::SETTING_CLASS;
        if ($module_id AND !empty($class)) {
            // make sure the slicesettings object for this slice exists
            $modulesetings_id = static::getModuleObjectId($module_id);
            if (is_null($class::load($modulesetings_id))) {
                $modulesetings = new $class;
                $modulesetings->setNew($modulesetings_id, $module_id);
                $modulesetings->save();
            }

            $form       = AA_Form::factoryForm($class, $modulesetings_id, $module_id);
            $form_state = $form->process($_POST['aa']);
        }
    }

    public static function getModuleObjectForm($module_id): string
    {
        /** @var class-string<AA_AbstractModulesettings> $class name - it is string name of class */
        $class = static::SETTING_CLASS;
        if ($module_id AND !empty($class)) {
            return AA_Form::factoryForm($class, static::getModuleObjectId($module_id), $module_id)->getObjectEditHtml();
        }
        return '';
    }

    /**
     * @param array $used4cache
     */
    public static function mark4cache(array $used4cache): void {
        self::$_used4cache = array_merge(self::$_used4cache, $used4cache);
    }

    /** get module ID   */
    function getId(): string
    {
        return $this->module_id; // Return a 32 character id
    }

    function getName(): string {
        return Txt::decoratedName((string)$this->_getModuleTableProperty('name'), $this->deleted());
    }

    /** Checks, if the module_id is OK and the slice is not deleted */
    function isValid() {
        return !$this->deleted() AND $this->module_setting; // $this->deleted() loads also $this->module_setting and we have to test if the module even exists
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
        return Langs::getLang($this->_getModuleTableProperty('lang_file'));
    }

    /** getCharset function
     *  Returns character encoding for the slice ('windows-1250', ...)
     */
    function getCharset()     {
        return Langs::getCharset($this->getLang());   // like 'windows-1250'
    }

    function getDefaultLang() {
        return $this->getLang();
    }

    static function getUsedModules(): array {
        return array_unique(array_merge(self::$_used4cache, array_keys(AA_Module::$_modules)));
    }

    /** @return array id => name of current user's modules
     *  @param $module_type
     *  @param $perm
     *  @param $user_id
     */
    public static function getUserModules( $module_type = '') {

        $all_modules = self::getModules($module_type, true);
        $user_id     =  AuthGetUid();

        return array_filter($all_modules, static fn($mid) => CheckPerms($user_id, 'slice', $mid, PS_EDIT_SELF_ITEMS), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param array $module_ids
     * @return array - clean list of active (not deleted) unique modules
     */
    public static function filterActive( array $module_ids): array {
        static $active_modules = null;
        if ( is_null($active_modules)) {
            $active_modules = array_keys(self::getModules('', true, ['created_at-']));
        }
        return array_intersect($active_modules, $module_ids);    // do not change the order - this way it is always unique
    }

    /** find all sites, where the module belongs
     * @param string $module_id
     * @return array
     */
    public static function belongsToSites(string $module_id): array {
        return self::filterActive(DB_AA::select('', 'SELECT LOWER(HEX(`source_id`)) AS unpackid FROM relation', [['destination_id', $module_id, 'l'], ['flag', REL_FLAG_MODULE_DEPEND, 'i']]));
    }
}

class AA_Slice extends AA_Module {
    protected $name;                   // The name of the slice
    protected $fields;                 // 2 member array( $fields, $prifields)
    protected $dynamic_fields;         // 2 member array( $fields, $prifields) == slice_fields
    protected $dynamic_setting = null; // dynamic slice setting fields stored in content table

    protected $feeding_tree = null;     // graph of feeds

    protected const SETTING_CLASS = 'AA_Modulesettings_Slice';
    protected const SETTING_TABLE = 'slice';

    // computed values form slice fields
    var $show_func_used; // used show functions in the input form
    /** AA_Slice function
     * @param $module_id
     */
    function __construct(string $module_id) {
        $this->fields         = AA_Fields::factoryFor($module_id);
        $this->dynamic_fields = AA_Fields::factoryFor($module_id, 1);
        parent::__construct($module_id);
    }

    /** all modules config function
     *  @see AA_Module::getModuleconfig()
     *  @inheritDoc
     */
    public static function getModuleconfig(): AA_Moduleconfig {
        return new AA_Moduleconfig('S', _m('Slice'), 'AA_Slice', 'admin/','include/menu.php', [], false);
    }

    protected function _getSpecificTableProperty($prop) {
        if (is_null($this->table_setting)) {
            parent::_getSpecificTableProperty($prop);
            if ($this->table_setting['reading_password']) {
                $this->table_setting['reading_password'] =  AA_Credentials::encrypt($this->table_setting['reading_password']);
            }
        }
        return $this->table_setting[$prop] ?? false;
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
                "flag"  => $db->f("flag"),
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

    /**
     * @param bool $fname
     * @return bool
     */
    function isField($fname) {
        return $this->fields->isField($fname);
    }

    /** @return html link to slice
     */
    function jumpLink(): string {
        return a_href(get_admin_url("index.php", ['change_id'=>$this->getId()]), $this->getName());
    }

    /** type function
     *
     */
    function type() {
        return $this->getProperty('type');
    }

    /** AA_Slice::getSliceTypes()
     * @return array [type => type name]
     */
    public static function getSliceTypes(): array {
        return [
            'News_EN_tmpl....' => _m('Items'),
            'ReaderManagement' => _m('Reader management'),
            '___CodePage' => _m('Code - Pages'),
            '___CodeView' => _m('Code - Views / Snippets'),
            '___Constant' => _m('Constant'),
            '___Translate' => _m('Translations'),
            '___Field' => _m('Field definitions'),
            // 'AA_Core_Fields..' => _m('AA Core'),   // do not return it - it should not be selected
            ];
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

    /** Should we use pending bin condition - based on nocheck_pending slice setting?
     */
    function noCheckPending(): bool {
        $expr = (string)$this->getProperty('nocheck_pending');
        return !strlen($expr) ? false : ($expr === "1" || AA::Un($expr) === "1");
    }

    /** Should we use expired bin condition - based on nocheck_expired slice setting?
     */
    function noCheckExpired(bool $suggestion=false) {
        $expr = (string)$this->getProperty('nocheck_expired');
        return !strlen($expr) ? $suggestion : ($expr === "1" || AA::Un($expr) === "1");
    }

    /** should we show Pending Bin in AA UI */
    function noPendingBin(): bool { return (string)$this->getProperty('nocheck_pending')==='1'; }
    /** should we show Expired Bin in AA UI */
    function noExpiredBin(): bool { return (string)$this->getProperty('nocheck_expired')==='1'; }

    public function countItemsInBins() {
        $now = now('step');
        $ret = ['folder1'=>0, 'folder2'=>0, 'folder3'=>0, 'expired'=>0, 'pending'=>0];

        $ret = array_merge($ret, DB_AA::select(['fldr'=>'cnt'], "SELECT concat('folder',status_code) as fldr, count(*) as cnt FROM item ". DB_AA::makeWhere([['slice_id', $this->module_id, 'l']]). ' GROUP BY status_code'));
        $ret['expired'] = $this->noExpiredBin() ? 0 : DB_AA::select1('cnt', "SELECT count(*) as cnt FROM item ", [
            ['slice_id', $this->module_id, 'l'],
            ['status_code', 1],
            ['expiry_date', $now, '<='],
        ]);
        $ret['pending'] = $this->noPendingBin() ? 0 : DB_AA::select1('cnt', "SELECT count(*) as cnt FROM item ", [
            ['slice_id', $this->module_id, 'l'],
            ['status_code', 1],
            ['publish_date', $now, '>'],
            ['expiry_date', $now, '>'],
        ]);
        $ret['app']     = $ret['folder1'] - ($this->noCheckPending() ? 0 : $ret['pending']) - ($this->noCheckExpired() ? 0 : $ret['expired']);

        return $ret;
    }

    /** AA_Slice::_deleteModules() function - called automatically form AA_Module::deleteModules()
     * @param array $module_ids
     * @return bool
     */
    public static function _deleteModules(array $module_ids): bool {
        if (!count($module_ids)) {
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

        // consider also to delete this - see ConvertSliceEncoding.php
        // $ret[] = "UPDATE `alerts_filter` SET " . $this->constructSet($this->getTextCols('alerts_filter')) . " WHERE `vid` IN ( SELECT `id` FROM `view` WHERE " . sqlin('slice_id', $modules, true) . ")";;
        // $ret[] = "UPDATE `constant` SET " . $this->constructSet($this->getTextCols('constant')) . " WHERE `group_id` IN ( SELECT `group_id` FROM `constant_slice` WHERE " . sqlin('slice_id', $modules, true) . ")";
        // $ret[] = "UPDATE `ef_categories` SET " . $this->constructSet($this->getTextCols('ef_categories')) . " WHERE `target_category_id` IN ( SELECT `id` FROM `constant` WHERE `group_id` IN ( SELECT `group_id` FROM `constant_slice` WHERE " . sqlin('slice_id', $modules, true) . "))";

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
     */
    function getField($field_id): ?AA_Field {
        return $this->fields->getField($field_id);
    }

    /** @return Widget */
    function getWidget($field_id) {
        return $this->getField($field_id)?->getWidget();
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

    /** find default field for upload to - first file...........x or img_upload.....x or img_src........x field
     */
    function getDefaultUploadField(): string {
        $flds = $this->getFields();
        return (($fid = $flds->firstOfTypeFieldId('file')) OR ($fid = $flds->firstOfTypeFieldId('img_upload')) OR ($fid = $flds->firstOfTypeFieldId('img_src'))) ? $fid : '';
    }

    /** sql_id function - removed - use DB_AA::select1('', 'SELECT * FROM `slice`', [['id',$slobj->getId(), 'l']]); */
    // function sql_id()      { return q_pack_id($this->module_id); }

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
            if (!AA_Credentials::singleton()->checkCryptedPassword($this->getProperty('reading_password'))) {   // $this->getProperty('reading_password') is encrypted
                if ($GLOBALS['errcheck'] OR $GLOBALS['debug']) {
                    huhe(_m("Error: Missing Reading Password"));
                }
                return null;
            }
        }
        $this->loadsettingfields();
        return $this->dynamic_setting;
    }

    /** Get the base for the file uploads
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

    /** Try to transform file path to file url - based on setting of file uploads or filemanager
     * @param $filename
     * @return string
     */
    function getUrlFromPath($filename) {
        $upload = $this->getUploadBase();
        if (str_starts_with($filename, $upload['path'])) {
            return $upload['url']. substr($filename,strlen($upload['path']));
        }
        return $filename;
    }

    /** get_format_strings function
     *  Returns array of admin format strings as used in manager class
     */
    function get_format_strings() {
        // additional string for compact_top and compact_bottom needed
        // for historical reasons (not manager.class version of item manager)
        return [
            "compact_top"     => '<div class="aa-items-manager"><table class="mgr_table">'. $this->getProperty('admin_format_top'),
            "category_sort"   => false,
            "category_format" => "",
            "category_top"    => "",
            "category_bottom" => "",
            "even_odd_differ" => false,
            "even_row_format" => "",
            "odd_row_format"  => $this->getProperty('admin_format'),
            "compact_remove"  => $this->getProperty('admin_remove'),
            "compact_bottom"  => $this->getProperty('admin_format_bottom'). '</table></div>',
            "noitem_msg"      => $this->getProperty('admin_noitem_msg'),
            // id is packed (format string are used as itemview
            //               parameter, where $slice_info expected)
            "id"              => pack_id($this->module_id),
         ];
    }

    /** Get standard aliases definition from slice's fields
     *
     * @param bool   $additional_aliases
     * @param string $type
     *
     * @return array|null
     */
    function aliases($additional_aliases = false, $type=''): ?array
    {
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

        $profile = AA_Profile::getProfile(AuthGetUid(), $this->getId()); // current user settings

        // get slice fields and its priorities in inputform
        $fields      = $this->getFields($slice_fields);

        // it is needed to call IsEditable() function and GetContentFromForm()
        if ( $id ) {
            $oldcontent = GetItemContent($id);
            $oldcontent4id = $oldcontent[$id];   // shortcut
        }

        foreach ($fields as $pri_field_id => $field) {

            //  'status_code.....' is not in condition - could be set from defaults
            if (($pri_field_id === 'edited_by.......') || ($pri_field_id === 'posted_by.......')) {
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

    /** Return feeding tree where items should be fed.
     *  $tree[$from, $to] = array(approved=>$appr, category=>$cat_id) means, that item from
     *  slice $from will be fed to slice $to, category $cat_id and bin depending on $appr :
     *  $appp="y" => active folder else hold bin.
     * @param ItemContent $content
     * @return array
     */
    function getFeedTree(ItemContent $content): array {
        if (is_null($this->feeding_tree)) {
            $this->feeding_tree = [];

            $sl_id = $this->getId();
            $from_category_id = '';

            $flds = $this->getFields();
            // get this item category_id

            // huhl($flds->firstOfTypeFieldId(), AA_Fields::getBaseFieldId($flds->getRecordArray(), 'text'), AA_Fields::getBaseFieldId($flds->getRecordArray(), 'al'));

            if ($cat_group = $flds->getCategoryGroup()) {
                if ($cat_field = $flds->firstOfTypeFieldId()) {
                    if ( $p_cat_id = DB_AA::select1('id', 'SELECT `id` FROM `constant`', [['group_id', $cat_group], ['value', $content->getValue($cat_field)]]) ) {
                        $from_category_id = unpack_id($p_cat_id);
                    }
                }
            }

            $slice_queue[$sl_id] = ['approved'=>"y", 'category'=>$from_category_id];
            foreach($slice_queue as $sl_id => $val) {
                if ($val['approved'] === "y") {
                    $from_category_id = $val['category'];
                    if ($from_category_id) {
                        $p_from_cat_id = pack_id($from_category_id);
                    }

                    $db = getDB();
                    $SQL = "SELECT feeds.to_id, feeds.category_id, feeds.all_categories,
                           feeds.to_approved, feeds.to_category_id
                     FROM slice, feeds LEFT JOIN feedperms ON feedperms.from_id=feeds.from_id
                    WHERE feeds.from_id = slice.id
                      AND feeds.from_id='". q_pack_id($sl_id) ."'
                      AND (slice.export_to_all=1
                       OR  feedperms.to_id = feeds.to_id)";  // check perms to feed, too
                    $db->query($SQL);

                    while ($db->next_record()) {
                        $to_id = unpack_id($db->f('to_id'));
                        // condition is necessary for multi feeding to this slice
                        if (isset($slice_queue[$to_id])) {
                            continue;
                        }
                        $approved = $db->f('to_approved') ? "y" : "n";
                        if (($p_from_cat_id == $db->f('category_id')) OR $db->f('all_categories')) {
                            $slice_queue[$to_id] = $this->feeding_tree[$sl_id][$to_id] = ['approved'=>$approved, 'category'=>unpack_id($db->f('to_category_id'))];
                        }
                    }
                    freeDB($db);
                }
            }
        }
        return $this->feeding_tree;
    }
}

class AA_Module_Site extends AA_Module {
    protected const SETTING_CLASS = 'AA_Modulesettings_Site';
    protected const SETTING_TABLE = 'site';

    const FLAG_DISABLE   = 1;   // deactivated spot
    const FLAG_JUST_TEXT = 2;   // not used - planed for site speedup
    // (= flag means "don't stringexpand text)"
    const FLAG_COLLAPSE  = 4;   // (visualy) Collapsed spot


    /** all modules config function
     *  @see AA_Module::getModuleconfig()
     *  @inheritDoc
     */
    public static function getModuleconfig(): AA_Moduleconfig {
        return new AA_Moduleconfig('W', _m('Site'), 'AA_Module_Site',  'modules/site/',   'modules/site/menu.php', ['en-utf8_site_lang.php' => 'EN Site',
                                                                                                                             'en_site_lang.php'      => 'EN Site (old - 8bit)',
                                                                                                                             'es-utf8_site_lang.php' => 'ES Site',
                                                                                                                             'es_site_lang.php'      => 'ES Site (old - 8bit)',
                                                                                                                             'cz-utf8_site_lang.php' => 'CZ Site',
                                                                                                                             'cz_site_lang.php'      => 'CZ Site (old - 8bit)',
                                                                                                                             'sk-utf8_site_lang.php' => 'SK Site',
        ], true);
    }

    /** get current site id
     *  @return string|false site_id
     */
    public static function getIdFromUrl() {
        $host2  = str_starts_with($host = $_SERVER['HTTP_HOST'], 'www.') ? substr($host,4) : 'www.'.$host;
        $domain_arr = ["http://$host/", "https://$host/","http://$host", "https://$host","http://$host2/", "https://$host2/","http://$host2", "https://$host2"];
        if ($id = DB_AA::select1('unpackid', "SELECT LOWER(HEX(id)) AS unpackid FROM `module`", [ ['type', 'W'], ['deleted', '0', 'i'], ['slice_url', $domain_arr] ]) ) {
            return $id;
        }
        return DB_AA::select1('mid', "SELECT `o3`.`value` AS mid FROM `object_text` o1, `object_text` o2, `object_text` o3",
            [['o2.object_id', 'o1.object_id', 'j'], ['o3.object_id', 'o1.object_id', 'j'],
             ['o1.property','alternate_url'], ['o1.value', $domain_arr],
             ['o2.property','aa_type'],['o2.value','AA_Modulesettings_Site'], ['o3.property','aa_owner']], ['o3.id']);
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
            $out .= ($spots[$v]['flag'] & self::FLAG_JUST_TEXT) ? $spots[$v]['content'] : AA::Un($spots[$v]['content'], '', $item);
        }
        return $out;
    }

    function getSite($apc_state) {
        $tree        = $this->getTree();   // new sitetree();
        $show_ids    = [];

        // it fills $show_ids array
        $tree->walkTree($apc_state, 1, function ($spot_id) use (&$show_ids) { $show_ids[] = $spot_id; });

        if (count($show_ids)<1) {
            exit;
        }

        $out = self::spotsOutput($this->module_id, $show_ids, $apc_state['item']);

        // trim remove whitespaces from start and end (mainly the start - it is better to start right with the code)
        return trim(AA::Stringexpander()->postprocess($out));
    }

    public function getTree(): ?sitetree {
        $tree = null;
        if ($structure = $this->getProperty('structure')) {
            $tree = unserialize($structure);
        } elseif ($spotrow = DB_AA::select1('', "SELECT * FROM site_spot", [
            ['site_id', $this->module_id, 'l'],
            ['spot_id', '1'],
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



    public function showSpot(sitetree $tree, $spot_id) {
        $spot_data = DB_AA::select1('', 'SELECT id, content FROM site_spot', [
            ['site_id', $this->module_id, 'l'],
            ['spot_id', $spot_id, 'i'],
        ]);
        FrmTabCaption('Spot Settings','',true);
        FrmStaticText(_m('Spot ID'), "[$spot_id] - " . _m('You can include this spot code in another spot by using {sitespot:%1}', [$spot_id]));

        $tree->printVariables($spot_id, $tree->get('variables',$spot_id));
        if (($vars=$tree->isOption($spot_id))) {
            $tree->printConditions($spot_id, $tree->get('conditions',$spot_id), $vars);
        }
        FrmTabEnd();

        $form_buttons= ['submit',
                        "cancel"=> [
                            'value'=>_m('Show History'),
                            "url"  =>\AA\Util\ChangesMonitor::getHistoryUrl("S.$spot_data[id]"),
                        ],
        ];

        echo "<br><form method='post' name=fs action=\"". $_SERVER['PHP_SELF'] ."\">";
        echo $tree->getCurrentSpotId();
        FrmTabCaption('Edit spot', '', true);
        FrmInputText('name', _m("Spot name"), $tree->get('name', $spot_id), 50, 50, true, false, false);
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
            $home       = AA::Un(trim($this->_getSpecificTableProperty('state_file'))) ?: '/' .substr($this->_getModuleTableProperty('lang_file'),0,2). '/';
            return AA_Router::singleton('AA_Router_Seo', self::getRelatedModules([$this->module_id]), $home, $this->_getModuleObjectProperty('web_languages'));
        }
        return null;
    }

    /** Get all active related modules to the sites
     * @param array $sites_ids
     * @param bool  $myself     - report also ids of the searched sites
     * @return array
     */
    static function getRelatedModules(array $sites_ids, bool $myself=false): array {
        // chached version
        $rels = AA::Contentcache()->get_result(['AA\IO\DB\DB_AA','select'], ['', 'SELECT LOWER(HEX(`destination_id`)) AS unpackid FROM relation', [['source_id', $sites_ids, 'l'], ['flag', REL_FLAG_MODULE_DEPEND, 'i']]]);
        if ($myself) {
            $rels = array_merge($rels, $sites_ids);
        }
        return AA_Module::filterActive($rels);
    }

    /** translate view name to view id within all slices of current sitemodule
     *
     * @param  string  $vid
     * @param          $module_id
     *
     * @return int - view id or 0 if not found
     */
    static function findViewId($vid, $module_id): int {
        // get translation table name -> id within all slices of curent sitemodule. Must be ordered by id, in order we return the same id even if the same view name is used
        $views = AA::Contentcache()->get_result(['AA\IO\DB\DB_AA','select'], [['field3'=>'id'], 'SELECT id, field3 FROM view', [['slice_id', self::getRelatedModules([$module_id]), 'l'], ['field3', '', 'FILLED']], 'id']);
        return (int)$views[$vid] ?: 0;
    }

    /** AA_Module_Site::_deleteModules() function - called automatically form AA_Module::deleteModules()
     * @param array $module_ids
     * @return bool
     */
    public static function _deleteModules(array $module_ids): bool {
        if (!count($module_ids)) {
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
    protected const SETTING_TABLE = 'alerts_collection';

    /** all modules config function
     *  @see AA_Module::getModuleconfig()
     *  @inheritDoc
     */
    public static function getModuleconfig(): AA_Moduleconfig {
        return new AA_Moduleconfig('Alerts', _m('Alerts'), 'AA_Module_Alerts', 'modules/alerts/', 'modules/alerts/menu.php', [], false);
    }

    /** AA_Module_Alerts::_deleteModules() function - called automatically form AA_Module::deleteModules()
     * @param array $module_ids
     * @return bool
     */
    public static function _deleteModules(array $module_ids): bool {
        if (!count($module_ids)) {
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

    /** all modules config function
     *  @see AA_Module::getModuleconfig()
     *  @inheritDoc
     */
    public static function getModuleconfig(): AA_Moduleconfig {
        return new AA_Moduleconfig('Links', _m('Links'), 'AA_Module_Links', 'modules/links/', 'modules/links/menu.php', [], true);
    }

    /** AA_Module_Links::_deleteModules() function - called automatically form AA_Module::deleteModules()
     * @param array $module_ids
     * @return bool
     */
    public static function _deleteModules(array $module_ids): bool {
        if (!count($module_ids)) {
            return false;     // _m("No such module.")
        }
        DB_AA::delete_low_priority('links',   [['id', $module_ids, 'l']]);
        return true;
    }
}

class AA_Module_Jump extends AA_Module {
    // const SETTING_CLASS = 'AA_Modulesettings_Site';
    // const SETTING_TABLE = 'alerts_collection';

    /** all modules config function
     *  @see AA_Module::getModuleconfig()
     *  @inheritDoc
     */
    public static function getModuleconfig(): AA_Moduleconfig {
        return new AA_Moduleconfig('J', _m('Jump inside AA control panel'), 'AA_Module_Jump', 'modules/jump/', 'include/menu.php', [], false);
    }

    /** AA_Module_Jump::_deleteModules() function - called automatically form AA_Module::deleteModules()
     * @param array $module_ids
     * @return bool
     */
    public static function _deleteModules(array $module_ids): bool {
        // @todo
        return true;
    }
}

class AA_Module_Polls extends AA_Module {
    // const SETTING_CLASS = 'AA_Modulesettings_Site';
    // const SETTING_TABLE = 'alerts_collection';

    /** all modules config function
     *  @see AA_Module::getModuleconfig()
     *  @inheritDoc
     */
    public static function getModuleconfig(): AA_Moduleconfig {
        return new AA_Moduleconfig('P', _m('Polls for AA'), 'AA_Module_Polls', 'modules/polls/', 'modules/polls/menu.php', ["en_polls_lang.php"      => "EN Polls (old - 8bit)",
                                                                                                                                     "es-utf8_polls_lang.php" => "ES Polls",
                                                                                                                                     "es_polls_lang.php"      => "ES Polls (old - 8bit)",
                                                                                                                                     "cz-utf8_polls_lang.php" => "CZ Polls",
                                                                                                                                     "cz_polls_lang.php"      => "CZ Polls (old - 8bit)",
                                                                                                                                     "sk-utf8_polls_lang.php" => "SK Polls",
        ], true);
    }

    /** AA_Module_Polls::_deleteModules() function - called automatically form AA_Module::deleteModules()
     * @param array $module_ids
     * @return bool
     */
    public static function _deleteModules(array $module_ids): bool {
        if (!count($module_ids)) {
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

/** Module settings */
abstract class AA_AbstractModulesettings extends AA_Object {

    /** check, if the $prop is the property of this object */
    static function isProperty($prop): bool { return false; }
}

/** Slice settings */
class AA_Modulesettings_Slice extends AA_AbstractModulesettings {

    // must be protected or public - AA_Object needs to read it
    protected $translations;

    /** do not display Name property on the form by default */
    const USES_NAME = false;

    /** check, if the $prop is the property of this object */
    static function isProperty($prop): bool {
        return in_array($prop, ['spamprotect', 'translations', 'autofields', 'fieldslice', 'historylog', 'nocheck_pending', 'nocheck_expired']);
    }

    /** allows storing object in database
     *  AA_Object's method
     * @return array
     */
    public static function getClassProperties(): array {
        return [ //                        id             name                            type     multi  persist validator, required, help, morehelp, example
            // 'spamprotect'  => new AA_Property( 'spamprotect',     _m("Languages for translation"), 'string', true, true, new AA_Validate_Regexp(['pattern'=>'/^[a-z]{2}$/', 'maxlength'=>2]), false, _m('specify language codes in which you want translate content - small caps, two letters - like: en, es, de, ...')),
            'translations'    => new AA_Property( 'translations',    _m("Languages for translation"), 'string', true, true, new AA_Validate_Regexp(['pattern'=>'/^[a-z]{2}$/', 'maxlength'=>2]), false, _m('specify language codes in which you want translate content - small caps, two letters - like: en, es, de, ...')),
            'autofields'      => new AA_Property( 'autofields',      _m("Automatic field creation"),  'bool',  false, true, 'bool', false, _m('If checked, slice allows storing values to text....* field, even if the appropriate text....* is not defined in the slice. The field will be created using field text............ as template.')),
            'fieldslice'      => new AA_Property( 'fieldslice',      _m("Fields defined by another slice"), 'string',  false, true, 'id', false, _m('You can use special slice for additional fields definition. There you can write slice ID, which define the fields.<br>Could be used for data slice, which stores values for user created forms.')),
            'historylog'      => new AA_Property( 'historylog',      _m("Store item history for N days"), 'string',  false, true, '', false, _m('ALL - do not delele history log (default)<br>0 - do not store item history (nor ITEM_UPD/ITEM_NEW events in Log)<br><em>number</em> - days after which will be history of items deleted')),
            'nocheck_pending' => new AA_Property( 'nocheck_pending', _m("No pending items"), 'string',  false, true, '', false, _m('1 - disable pending bin completely in this slice (act publish_date field as normal one)<br>{aa-expression} - if evaluated as 1, disable pending check (for view, slice, {ids...}) (use {if:{qss:..}, {var:..}, {server:..} expression here)')),
            'nocheck_expired' => new AA_Property( 'nocheck_expired', _m("No expired items"), 'string',  false, true, '', false, _m('1 - disable expired bin completely in this slice (act expiry_date field as normal one)<br>{aa-expression} - if evaluated as 1, disable expiry date check (for view, slice, {ids...}) (use {if:{qss:..}, {var:..}, {server:..} expression here)')),
             // do not forget to add new property to  isProperty!
        ];
    }

    /** @inheritDoc */

    protected static function getAdminUrlTemplate(): string {
        return get_admin_url('slicedit.php', ['module_id=_#AA_OWNER']);
    }
}

/** Slice settings */
class AA_Modulesettings_Site extends AA_AbstractModulesettings {

    // must be protected or public - AA_Object needs to read it
    protected $translation_slice;
    protected $additional_aliases;

    /** do not display Name property on the form by default */
    const USES_NAME = false;


    /** check, if the $prop is the property of this object */
    static function isProperty($prop): bool {
        return in_array($prop, ['translate_slice', 'add_aliases', 'web_languages', 'page404', 'page404_code', 'sitemap_alias', 'perm_alias', 'loginpage_code', 'perm_mode', 'ga_id', 'alternate_url']);
    }

    /** allows storing object in database
     *  AA_Object's method
     * @return array
     */
    public static function getClassProperties(): array {
        return [ //                             id             name                                                type     multi  persist validator, required, help, morehelp, example
            'translate_slice' => new AA_Property( 'translate_slice',  _m("Slice with translations"),                'string', false, true, ['enum', AA_Module::getUserModules('S')], false, _m("the slice used for {tr:text...} translations (the slice needs to have just headline........ field set as 'Allow translation')")),
            'add_aliases'     => new AA_Property( 'add_aliases',      _m("Additional aliases"),                     'string', true,  true, ['enum',   AA_Module::getUserModules('W')], false, _m('Select sitemodule, where we have to look for additional {_:...} aliases')),
            'web_languages'   => new AA_Property( 'web_languages',    _m("Languages on website"),                   'string', true,  true, ['regexp', ['pattern'=>'/^[a-z]{2}$/','maxlength'=>2]], false, _m('List all languages for which you want to use sitemodule - en, es, cz, ..<br>It is quite necessary if you want to call sitemodule newer way from Apache:<br> RewriteEngine on<br> RewriteRule ^$ /apc-aa/modules/site/site.php [L,QSA]<br> RewriteCond %{REQUEST_FILENAME} !-f<br> RewriteCond %{REQUEST_FILENAME} !-d<br> RewriteRule ^ /apc-aa/modules/site/site.php [L,QSA]<br>')),
            'page404'         => new AA_Property( 'page404',          _m("Page not Found (404)"),                   'string', false, true, ['enum',   [1=>_m('Do not care'),2=>_m('Send standard 404 page, when {xid} is empty'),3=>_m('Send code below')]], false, _m('When first option "Do not care" is selected (old behavior), you should test unfilled {xid} in your sitemodule yourself')),
            'page404_code'    => new AA_Property( 'page404_code',     _m("HTML code for \"Page not Found\" (404)"), 'text',   false, true),
            'sitemap_alias'   => new AA_Property( 'sitemap_alias',    _m("sitemap.xml alias"),                      'string', false, true, '', false, _m('The sitemap.xml will be generated from all slices (in order above) where the specified alias (say _#SMAP_URL) exists. The alias should generate full url of the item in each slice. For private/hidden items it should generate empty string in order the URL is not shown.')),
            'perm_alias'      => new AA_Property( 'perm_alias',       _m("Permission alias"),                       'string', false, true, '', false, _m('You can define the alias, which will be checked for all the displayed {xid} (=pages). If the value will be word "Valid", then the page will be shown. Otherwise the loginform (see below) will be shown. Of course, you do not need to use this feature and do it older way - directly in the tree of sitemodule.')),
            'loginpage_code'  => new AA_Property( 'loginpage_code',   _m("HTML code for \"Login page\""),           'text',   false, true),
            'perm_mode'       => new AA_Property( 'perm_mode',        _m("User login databases"),                   'string', false, true, ['enum',  [1=>_m('first Reader Management slice listed in Uses slices'),2=>_m('all Reader Management slices listed in Uses slices'),3=>_m('AA + all Reader Management slices in AA (old behavior)')]], false, _m('First Reader Management slice is encouraged option - only the people from this slice is allowed to login to public pages. If not selected, "AA + all Reader Management slices" option is used.')),
            'ga_id'           => new AA_Property( 'ga_id',            _m("Google analytics tracking id"),           'string', false, true, ['text', ['maxlength'=>20]],  false, _m('If you are using {generate:HEAD} and {generate:FOOT} in yor sitemodule code, then the Google Analytics code is generated with this tracking ID. The generated code could be changed in the future when Google changes the snippet.<br>To use user Consent bar, add {eucookies} or {eucookies:OK to use cookies?:OK:more://www.example.cz/cz/cookies-strategy::No:top} in the site.')),
            'alternate_url'   => new AA_Property( 'alternate_url',    _m("Alternate URL"),                          'string', true,  true, '',  false, _m('Another domain(s) for which the web should be visible. However, consider to use server-side redirect to use single domain, which is SEO more friendly.')),
        ];
    }

    /** @inheritDoc */
    protected static function getAdminUrlTemplate(): string {
        return get_aa_url('/modules/site/modedit.php', ['module_id=_#AA_OWNER']);
    }
}

