<?php
/**
 * Class ItemContent.
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
 * @author    Jakub Adamek, Econnect
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (c) 2002-3 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

use AA\IO\DB\DB_AA;
use AA\Util\ChangeProposal;
use AA\Util\ChangesMonitor;

require_once __DIR__."/locsess.php3";    // DB_AA object definition
require_once __DIR__."/feeding.php3";
require_once __DIR__."/itemfunc.php3";

/**
 * AA_Value - Holds information about one value - could be multiple,
 *            could contain flags...
 *          - the values are always plain (= no quoting, no htmlspecialchars...)
 */
class AA_Value implements Iterator, ArrayAccess, Countable {

    /** you can use MAX_INDEX numerical values for multivalues - bigger indexes are used for language translations */
    const MAX_INDEX = 1000000;

    /** array of the values */
    protected $val;

    /** holds the flag - common for all the values */
    protected $flag;

    /** AA_Value function
     * @param $value
     * @param null|int $flag  FLAG_HTML | FLAG_FEED | FLAG_FREEZE | FLAG_OFFLINE | FLAG_UPDATE | FLAG_TEXT_STORED
     */
    function __construct($value = null, $flag = null) {
        $this->clear();
        $this->addValue($value);
        $this->setFlag(!is_null($flag) ? $flag : ((is_array($value) and is_array($value[0])) ? $value[0]['flag'] : 0));
    }

    /** For creating value from any value
     *  Called as
     *     AA_Value::factory($val);
     * @param $val
     * @return AA_Value
     */
    static function factory($val) {
        if (is_object($val)) {
            if (strtolower(get_class($val)) == 'aa_value') {
                return $val;
            }
            // we use serialized values for objects.
            // The idea is, that it is in fact the same as with timestamp for date - it is just inner value,
            // which is hardly ever shown to user as is. The same with objects here.
            return new AA_Value(serialize($val));   // maybe we can set some flag for serialized values
        }
        return new AA_Value($val);
    }

    /** Static for creating value from string of JSON (for Arrays)
     *  Called as
     *     AA_Value::factoryFromJson($val);
     */
    static function factoryFromJson($json) {
        $aav = new AA_Value();
        if (($json[0] == '[') and (!is_null($arr = json_decode($json, true)))) {
            $aav->setValues(array_values($arr));
        } else {
            $aav->setValues($json);
        }
        return $aav;
    }

    /** for creating value from array[][value]
     *  Called as
     *     AA_Value::factoryFromContent($arr);
     */
    static function factoryFromContent($arr) {
        $aav = new AA_Value();
        // preserves keys - necessary for translated values
        foreach ($arr as $key => $val) {
            $aav->val[(int)$key] = $val['value'];
        }
        $first = reset($arr);
        return $aav->setFlag($first['flag']);
    }


    /** getValue function
     *  Returns the value for a field. If it is a multi-value
     *   field, this is the first value.
     * @param $i
     * @return
     */
    function getValue($i = 0) {
        return $this->val[$i];
    }

    /** getValues function
     *  Returns the simple array of values
     * @param $i
     */
    function getValues() {
        return $this->val;
    }

    /** Returns the value for a field. If it is a multi-value
     *   field, this is the first value. */
    function getFlag($i = 0) {
        return $this->flag;
    }

    /** @return true, if the value do not contain any data */
    function isEmpty() {
        return count($this->val) < 1;
    }

    /** Add Value */
    function addValue($value) {
        if (is_array($value)) {
            // normal array(val1, val2, ...) used or used AA array used
            // in AA_ItemContent - [0]['value'] = ..
            //                         ['flag']  = ..
            //                     [1]['value'] = ..
            foreach ($value as $key => $val) {
                $this->val[(int)$key] = is_array($val) ? $val['value'] : (!is_object($val) ? $val : serialize($val));
                // @todo check, if $val->getSomething is callable
            }
        } elseif (!is_null($value)) {
            $this->val[] = $value;
        }
        return $this->removeDuplicates();
    }

    public function containValue(string $txt): bool {
        return in_array($txt, $this->val);
    }

    /** Remove Value */
    function removeValues(array $remove) {
        $this->val = array_diff($this->val, $remove);
        return $this;
    }

    /** Set $value - value is normal (numeric) array or string value */
    function setValues($value) {
        $this->val = is_array($value) ? $value : (is_null($value) ? [] : [$value]);
        return $this;
    }

    /** Set the flag (for al the values the flag is common at this time) */
    function setFlag($flag) {
        $this->flag = $flag;
        return $this;
    }

    /** clear function  */
    function clear() {
        $this->val = [];
        $this->flag = 0;
    }

    /** Replaces the strings in all values  */
    function replaceInValues($search, $replace) {
        foreach ($this->val as $k => $v) {
            $this->val[$k] = str_replace($search, $replace, $v);
        }
    }

    /** Makes sure the value contains all the translations.
     *  It also converts untranslated values to default language (then user switched to translated field, for example)
     * @param string[] $translations
     * @return AA_Value
     */
    function fixTranslations($translations) {
        if ($translations) {
            $maxindex = 0;
            foreach ($this->val as $k => $v) {
                if (strlen($v)) { // do not add index for all empty values
                    $maxindex = max($maxindex, $k % AA_Value::MAX_INDEX);
                }
            }

            $first = true;
            $newval = [];
            foreach ($translations as $lang) {
                $lang_id = AA_Langs::getLangName2Num($lang);  // converts two letter lang code into number used for translation fields (cz -> 78000000, en -> 118000000, ...)
                for ($i = 0; $i <= $maxindex; $i++) {
                    $value = isset($this->val[$lang_id + $i]) ? $this->val[$lang_id + $i] : '';
                    if (!strlen($value) and $first and isset($this->val[$i])) {
                        // if the translation is not set try to use standard value (possibly filled before we set the translations for the field)
                        $value = $this->val[$i];
                    }
                    $newval[$lang_id + $i] = $value;
                }
                $first = false;
            }
            $this->val = $newval;
        }
        return $this;
    }

    /** Remove duplicate values from the array */
    function removeDuplicates() {
        reset($this->val);
        if (key($this->val) < AA_Value::MAX_INDEX) {  // do not remove for multilingual
            $this->val = array_values(array_unique($this->val));
        }
        return $this;
    }

    /** getArray function
     * @return array - clasic $content value array - [0]['value'] = ..
     *                                                   ['flag']  = ..
     *                                                [1]['value'] = ..
     *          the values are not quoted, ...
     *  Mainly for backward compatibility with old - array approach
     */
    function getArray() {
        $ret = [];
        foreach ($this->val as $k => $v) {
            $ret[(int)$k] = ['value' => $v, 'flag' => $this->flag];
        }
        return $ret;
    }

    /** Iterator interface */
    public function rewind() { reset($this->val); }

    public function current() { return current($this->val); }

    public function key() { return key($this->val); }

    public function next() { next($this->val); }

    public function valid() { return (current($this->val) !== false); }

    /** Countable interface - Returns number of values  */
    public function count() { return count($this->val); }

    /** ArrayAccess interface */
    public function offsetSet($offset, $value) { $this->val[$offset] = $value; }

    public function offsetExists($offset) { return isset($this->val[$offset]); }

    public function offsetUnset($offset) { unset($this->val[$offset]); }

    public function offsetGet($offset) { return isset($this->val[$offset]) ? $this->val[$offset] : null; }
}


/** Class holds any data (AA_Values)
 *  Universal data structure interface - it could hold item data as well as
 *  data from the form
 */
class AA_Content {
    protected $content      = [];
    protected $id_field     = 'aa_id';
    protected $owner_field  = 'aa_owner';

    function unalias($text) {
        return AA::Stringexpander()->unalias($text);
    }

    /** isField function
     *  Returns true, if the passed field_id is field id
     * @param $field_id
     * @return bool
     */
    function isField($field_id) {
        return isset($this->content[$field_id]);
    }

    /** setAaValue function
     *  Special function - fills field from AA_Value object
     * @param $field_id string
     * @param $value AA_Value
     */
    function setAaValue($field_id, $value) {
        if (is_object($value)) {
            // we expect AA_Value object here
            $this->content[$field_id] = $value->getArray();
        }
        return $this;
    }

    /** set object id based on id_field setting for this content */
    function setId($id) {
        $this->setAaValue($this->id_field, new AA_Value( $id ));
        return $this;
    }

    /** set object id based on id_field setting for this content */
    function setOwnerId($id) {
        $this->setAaValue($this->owner_field, new AA_Value( $id ));
        return $this;
    }

    /** set object id based on id_field setting for this content */
    function addValue($field_id, $value) {
        $this->content[$field_id][] = ['value'=>$value, 'flag'=>0];
        return $this;
    }

    /** get object id based on id_field setting for this content */
    function getId() {
        return $this->getValue($this->id_field);
    }

    /** get owner id based on id_field setting for this content */
    function getOwnerId() {
        return $this->getValue($this->owner_field);
    }

    /** get owner id based on id_field setting for this content */
    function getName() {
        return $this->getValue('aa_name');
    }

    /** get list of fields */
    function getFields() {
        return array_keys($this->content);
    }

    /** getAaValue function
     *  Returns the AA_Value object for a field
     * @param $field_id
     * @return AA_Value
     */
    function getAaValue($field_id) {
        return AA_Value::factoryFromContent( $this->content[$field_id] );
    }

    /** is the field marked as Multilingual_
     * @param string $field_id ISD of the field
     * @return bool
     */
    function isMultilingual($field_id) {
        return is_array($a = $this->content[$field_id]) && (key($a) >= AA_Value::MAX_INDEX);
    }

    /** count internal index for n-th value taking into account the mulilingual fields
     * @param string $field_id
     * @param int $idx
     * @return bool|int
     */
    protected function getIndex($field_id, $idx=0) {
        if ( !is_array($a = $this->content[$field_id]) ) {
            return false;
        }
        if (isset($a[$idx])) {
            return $idx;
        }
        // the same test as in isMultilingual($field_id) above;
        if ( (key($a)>=AA_Value::MAX_INDEX) AND ($idx<AA_Value::MAX_INDEX) ) {
            if (strlen($a[$index = AA::$langnum[0]+$idx]['value'])) {
                return $index;
            }
            if (strlen($a[$index = $this->_getDefaultLangNum()+$idx]['value'])) {
                return $index;
            }
        }
        return false;
        //return ( is_array($a = $this->content[$field_id]) ? $a[$idx]['value'] : false );
    }

    /** getValue function
     *  Returns the value for a field. If it is a multi-value
     *   field, this is the first value.
     * @param string $field_id
     * @param int    $idx
     * @return string | false
     */
    function getValue($field_id, $idx=0) {
        return (($index = $this->getIndex($field_id, $idx)) === false ) ? false : $this->content[$field_id][$index]['value'];
    }

    private function _getDefaultLangNum() {
        static $def_lang_num = '';
        if ($def_lang_num) {
            return $def_lang_num;
        }
        $def_lang = '';
        if ( AA::$site_id ) {
            $def_lang = AA_Module_Site::getModule(AA::$site_id)->getDefaultLang();
        } else {
            $def_lang = AA_Module::getModule($this->getOwnerId())->getDefaultLang() ?: strtolower(substr(DEFAULT_LANG_INCLUDE,0,2));      // actual language - two letter shortcut cz / es / en
        }
        return ($def_lang_num = AA_Langs::getLangName2Num($def_lang)); // array of prefered languages in priority order.
    }

    function getFlag($field_id) {
        if (is_array($this->content[$field_id])) {
            $curr = reset($this->content[$field_id]);
            return $curr['flag'];
        }
        return false;
    }

    /** getValues function
     * @param string $field_id
     * @return array
     */
    function getValues($field_id): array {
        return is_array($a = $this->content[$field_id]) ? $a : [] ;
    }

    /** getValuesArray function
     * @param $field_id
     * @return array
     */
    function getValuesArray($field_id): array
    {
        return empty($this->content[$field_id]) ? [] : array_column($this->content[$field_id],'value');
    }

    /** computes difference between two AA_Contents
     * @param AA_Content $object2compare
     * @param array      $ignore
     * @return ChangeProposal[]
     */
    function diff($object2compare, array $ignore=[]): array {
        $changes = [];
        $item_id = $this->getId();
        foreach ($this->content as $fid => $a) {
            if (in_array($fid, $ignore)) {
                continue;
            }
            $b = $object2compare->getValuesArray($fid);
            if ($this->getValuesArray($fid) != $b) {
                $changes[] = new ChangeProposal($item_id, $fid, $b);
            }
        }
        // search for values not present in current object
        $b_fields = $object2compare->getFields();
        foreach ($b_fields as $fid) {
            if (empty($this->content[$fid]) AND !in_array($fid, $ignore)) {
                $changes[] = new ChangeProposal($item_id, $fid, $object2compare->getValuesArray($fid));
            }
        }
        return $changes;
    }

    /** @return array - Abstract Data Structure of current object
     *  @deprecated - for backward compatibility (used in AA_Object getContent)
     */
    function getContent() {
        return $this->content;
    }

    /** Special function for specific usage in storeItem with update_filled
     *  Removes all fields, which do not have value
     */
    function removeEmptyFields() {
        $this->content = array_filter($this->content, function ($fid) {
            return array_filter(array_map('trim', $this->getValuesArray($fid)), 'strlen');
        }, ARRAY_FILTER_USE_KEY);
    }
}

define('ITEMCONTENT_ERROR_BAD_PARAM',   200);
define('ITEMCONTENT_ERROR_DUPLICATE',   201);
define('ITEMCONTENT_ERROR_NO_ID',       202);
define('ITEMCONTENT_ERROR_NO_SLICE_ID', 203);
define('ITEMCONTENT_ERROR_NO_PERM',     204);
define('ITEMCONTENT_ERROR_INVALID',     205);

/**
 *  ItemContent class is an abstract data structure, used mostly for storing
 *  an item. The item can contain many fields, and each field contains 1..n
 *  value including the value attribute (now attribute may be only html flag).
 */

/** Stores all info about an item. Uses both info from the <em>item</em> and
 *   <em>content</em> tables.
 *
 *   Gives convenient access to the things previously stored in the
 *   array $content4id.
 */
class ItemContent extends AA_Content {

    use \AA\Util\LastErrTrait;

    /** ItemContent function
     *  Constructor which takes content for ID or item_id (unpacked).
     * @param $content4id  array|string  content4id array or item id
     */
    function __construct($content4id = "") {
        if ($content4id) {
            if ( is_array($content4id) ) {
                $this->setFromArray($content4id);
            } else {
                $this->setByItemID($content4id );
            }
        }
    }

    /** isField function
     *  Returns true, if the passed field id looks like field id
     * @param $field_id
     * @todo - look directly into module, if the field is really field in specific slice/module
     * @todo - this function is not compatible to AA_Content->isField() - one of them should be renamed
     * @return bool
     */
    function isField($field_id) {
        static $f_def;
        if ( !isset($f_def) ) {
            $f_def = array_flip(array_merge(GetLinkFields()->getFieldidsArray(), array_keys(GetCategoryFields()), array_keys(GetConstantFields())));
        }

        // changed this from [a-z_]+\.+[0-9]*$ because of alerts[12]....abcde
        return (((strlen($field_id)==16) AND preg_match('/^[a-z0-9_]+\.+[0-9A-Za-z]*$/',$field_id)) OR $f_def[$field_id]);
    }

    /** setFromArray function
     * @param array $content4id
     */
    function setFromArray(array $content4id) {
        $this->content = $content4id;
    }

    /** setByItemID function
     * Set by item ID (zid or unpacked or short)
     * @param $item_id
     * @param $ignore_reading_password
     * @return bool
     */
    function setByItemID($item_id, $ignore_reading_password=false) {
        if (!$item_id) {
            return false;
        }
        $zid           = is_object($item_id) ? $item_id : new zids($item_id);
        $content       = GetItemContent($zid, false, $ignore_reading_password);
        $this->content = is_array($content) ? reset($content) : [];
    }

    /** setFieldsFromForm function
     *  Functions tries to fill all the fields from the form. It do not add any
     *  item specific fields (like status_code), so it could be used also for
     *  dynamic "slice setting fields"
     * @param array     $oldcontent4id
     * @param bool       $insert
     * @param AA_Fields  $fields
     * @param AA_Profile $profile
     * @return bool
     */
    function setFieldsFromForm($oldcontent4id, bool $insert, $fields, $profile): bool {
        
        // check, if any data was send to the server (GET is not possible there, I think)
        if ($_SERVER['REQUEST_METHOD'] != 'POST') {
            return false;
        }

        $aacontent4id = null; // modern input format - introduced with [rim]
        if ($_POST['aa']) {
            //$grabber = new AA_Grabber_Form();
            $grabber = new AA\IO\Grabber\Form();
            $grabber->prepare();    // maybe some initialization in grabber
            $aacontent4id = $grabber->getItem();
            $grabber->finish();
        }

        foreach ($fields as $pri_field_id => $field) {
            // to content array add just displayed fields (see ShowForm())
            if (!IsEditable($oldcontent4id[$pri_field_id], $field, $profile) AND !$insert) {
                continue;
            }

            if ($aacontent4id AND $aacontent4id->is_set($pri_field_id) AND (!in_array($pri_field_id, ['id..............','slice_id........']))) {
                $this->content[$pri_field_id] = $aacontent4id->getValues($pri_field_id);
                continue;
            }
            // if makes no sense - the value is always returned
            if ($field_value = $field->readValueFromOldtypeForm($oldcontent4id, $insert)) {
                $this->content[$pri_field_id] = $field_value->getArray();
            }
        }
        return true;
    }

    /** setFieldsFromForm function
     *  Functions tries to fill all the fields from the form. It do not add any
     *  item specific fields (like status_code), so it could be used also for
     *  dynamic "slice setting fields"
     * @param AA_Fields  $fields
     * @param AA_Profile $profile
     */
    function setFieldsFromProfile($fields, $profile) {

        foreach ($fields as $pri_field_id => $field) {
            // set default in case of insert & no value
            if (!$this->content[$pri_field_id]) {
                $default_val = $field->getDefault();
                if (! $default_val->isEmpty()) {
                    $this->content[$pri_field_id] = $default_val->getArray();
                }
            }
            // fill from profile - it is stronger, than default values
            if ($profile_value = $profile->getFormFieldValue($pri_field_id)) {
                $this->content[$pri_field_id] = $profile_value->getArray();
            }
        }
    }

    /** setFromForm function
     *  Fills content4id - values in content4id are NOT quoted (addslashes)
     *  (new version of previous GetContentFromForm() function)
     * @param AA_Slice $slice
     * @param string $id  - item/slice id or empty if item insert
     * @param array $oldcontent4id
     * @param bool $insert
     * @return bool
     */
    function setFromForm($slice, $id, $oldcontent4id=[], bool $insert=true, $slice_fields=false): bool {
        global $auth;

        $fields  = $slice->getFields($slice_fields);
        $profile = AA_Profile::getProfile($auth->auth["uid"], $slice->getId()); // current user settings

        if (!$this->setFieldsFromForm($oldcontent4id, $insert, $fields, $profile)) {
            return false;
        }

        // if there are predefined values in user profile, fill it.
        // Fill it only if $insert (new item). Otherwise left there filled value
        if ($insert) {  
            // apply Profile To Content
            $this->setFieldsFromProfile($fields, $profile);
        }

        // the status_code must be set in order we can use email_notify()
        // in StoreItem() function.
        if (!$insert AND !$this->getStatusCode()) {
            $this->setValue('status_code.....', max(1,$oldcontent4id['status_code.....'][0]['value']));
        }

        if (!$insert) {
            $this->setValue('flags...........', $oldcontent4id["flags..........."][0]['value']);
        }

        // id of an item (for update)
        if (!$this->getItemID()) {
            $this->setItemID($id);    // grabbed from globals (sent by form (for update))
        }                             // it is posted as 'id' and not as standard 'v'.unpack_id('id..............')
                                      // from historical reasons. We probably change it in next versions - TODO
        $this->setSliceID($slice->getId());
        return true;
    }

    /** isEmpty function
     *
     */
    function isEmpty() {
        return !$this->content;
    }

    /** is_set function
     * @todo  rename to more standard isSet after switch to PHP version 7
     * @param $field_id
     * @return bool
     */
    function is_set($field_id) {
        return is_array($this->content[$field_id]);
    }

    /** Without $bin returns true if the item is visible - not expired/trashed/pending/...
     *  You can specify bin AA_BIN_ACTIVE or AA_BIN_HOLDING or AA_BIN_TRASH or AA_BIN_ALL or its bitwise combination (the trash and pending is not supported, now)
     */
    function isActive($bin=AA_BIN_ACTIVE) {

        if ( (($status=$this->getStatusCode()) == 1) AND ($bin & AA_BIN_ACTIVE)) {
            $now = now('step');
            if (($this->getPublishDate() <= $now) OR (($slice = AA_Slice::getModule($this->getSliceID())) AND $slice->isPendingContentAllowed())) {
                if (($this->getExpiryDate() > $now) OR (($slice = $slice ?: AA_Slice::getModule($this->getSliceID())) AND $slice->isExpiredContentAllowed())) {
                    return true;
                }
            }
        } elseif (($status==2) AND ($bin & AA_BIN_HOLDING)) {
            return true;
        } elseif (($status==3) AND ($bin & AA_BIN_TRASH)) {
            return true;
        }
        return false;
    }

    /** matches function
     * @param $conditions
     * @return
     */
    function matches($conditions) {
        return $conditions->matches($this);
    }

    /** getFields function
     *
     */
    function getFields() {
        $fields = [];
        foreach ( $this->content as $field => $foo ) {
            $fields[] = $field;
        }
        return $fields;
    }

    /** getItemID function
     * @return string longID
     */
    function getItemID() {
        return strlen($id = $this->getValue('unpacked_id.....')) ? $id : unpack_id($this->getValue('id..............'));
    }

    /** redefined AA_Content's function */
    function getId() {
        return $this->getItemID();
    }

    /** redefined AA_Content's function */
    function getOwnerId() {
        return $this->getSliceID();
    }

    /** getSliceID function
     */
    function getSliceID() {
        return strlen($id = $this->getValue('u_slice_id......')) ? $id : unpack_id($this->getValue('slice_id........'));
    }

    /** getStatusCode function
     */
    function getStatusCode(): int {
        return (int)$this->getValue("status_code.....");
    }

    /** getPublishDate function
     */
    function getPublishDate(): int {
        return (int)$this->getValue("publish_date....");
    }

    /** getExpiryDate function
     */
    function getExpiryDate(): int {
        return (int)$this->getValue("expiry_date.....");
    }

    /** setValue function
     * @param string $field_id
     * @param string $val
     * @param int    $num
     */
    function setValue($field_id,$val,$num=0) {
        $this->content[$field_id][$num]['value'] = $val;
    }

    /** setItemID function
     * @param $value
     * @return bool
     */
    function setItemID($value) {
        if (!is_long_id($value)) {
            $this->setValue('id..............', pack_id(0));
            $this->setValue('unpacked_id.....', 0);
            return false;
        }
        // we want to switch the default to unpacked_id, but id is still necessary (for field copy in complete4Insert...)
        $this->setValue('id..............', pack_id($value));
        $this->setValue('unpacked_id.....', $value);
    }

    /** setSliceID function
     * @param $value
     */
    function setSliceID($value) {
        $this->setValue("slice_id........", pack_id($value));
    }

    /*------------------------ */

    /** setFromCSVArray function
     *  Set the content with CSV data
     * @param $csvRec[]
     * @param $fieldNames[]
     */
    function setFromCSVArray($csvRec, $fieldNames) {
        $i = 0;
        foreach ($fieldNames as $k => $foo) {
            $this->content[$k][0]['value'] = $csvRec[$i++];
        }
    }

    /**
     * @param string $type  present|visible|all   validate just PRESENT fields in ItemContent (for update), The fiels VISIBLE (dislayed on inputform), ALL fields in the slice
     * @return array of non valid fields like: ['text............'=>[410,'Not filled']]
     */
    function validateReport($type='present') {
        $slice      = AA_Slice::getModule($this->getSliceID());
        $fields     = $slice->getFields();
        $ret        = [];
        //$cur_fields = $this->getFields();

        foreach ($fields as $field_id => $field) {
            if (($type=='visible' AND !$field->getProperty('input_show'))) {
                continue;
            }
            if (($type=='present' AND !$this->is_set($field_id))) {
                continue;
            }
            $property    = $field->getAaProperty();
            if ($validreport = $property->validateDetailed($this)) {
                // if the inserter generates the content itself, it is not necessary to have it filled
                if ($validreport[0] == VALIDATE_ERROR_NOT_FILLED) {
                    if (AA_Inserter::factoryByField($field)->isContentSelfGenerated()) {
                        continue;
                    }
                    // item table fields like publish date should be OK if not displayed on standard inputform
                    if (($field->storageTable()=='item') AND !$field->getProperty('input_show')) {
                        continue;
                    }
                    if (substr($field->getProperty('input_show_func'), 0,3) === 'fil') {
                        // special setting for file upload - there we solve the problem
                        // of required fileupload field, but which is empty at this moment
                        $varname = 'v'. unpack_id($field_id).'x';
                        // @todo check is_uploaded_file(). Use Files:: for it. Could be array() of files.
                        if ( isset($_FILES[$varname]) AND $_FILES[$varname]['tmp_name'] ) {  // old form of file upload
                            continue;
                        }
                    }
                }
                $ret[$field_id] = $validreport;
            }
        }
        return $ret;
    }

    /** validates and fills content with default and hidden fields in order it could be stored into database
     * @param string $validate   LOG | STRICT | QUIET
     * @return bool
     */
    function complete4Insert($validate='LOG') {
        global $auth;
        $slice_id = $this->getSliceID();
        $slice = AA_Slice::getModule($slice_id);
        if (!$slice) {
            ItemContent::lastErr(ITEMCONTENT_ERROR_NO_SLICE_ID, _m("No Slice Id specified"));  // set error code
            return false;
        }

        $invalid = $this->validateReport('all');
        if ($invalid) {
            switch ($validate) {
                case 'STRICT':
                    ItemContent::lastErr(ITEMCONTENT_ERROR_INVALID, join("\n", array_map(function ($err) { return $err[0].": ".$err[1]; }, $invalid)));
                    return false;
                case 'LOG':
                    foreach ($invalid as $fld => $msg) {
                        AA_Log::write('M_FLDI_VLD', ParamImplode([$slice_id,$fld]), ParamImplode([$msg[0] . ' - ' . $msg[1], document_uri(), $_SERVER['HTTP_REFERER']]));
                    }
                    // drop to next case 'QUIET'
                case 'QUIET':
                    // nothing - continue
            }
        }

        // start from scretch with new content
        $new_content = new ItemContent;
        $fields      = $slice->getFields();
        $profile     = AA_Profile::getProfile($auth->auth["uid"], $slice->getId()); // current user settings

        foreach ($fields as $field_id => $field) {
            $property = $field->getAaProperty();
            $new_content->setAaValue($field_id, $property->completeProperty4Insert($this, $profile));
        }

        // normalize - set id and well as unpacked_id
        $new_content->setItemID($this->getItemID());

        $status = max(1, $new_content->getStatusCode(), $slice->allowed_bin_4_user());
        $new_content->setValue('status_code.....', $status);

        if ( $new_content->getPublishDate() <= 0 ) {
            $new_content->setValue('publish_date....', now());
        }
        if ( $new_content->getExpiryDate() <= 0 ) {
            $new_content->setValue('expiry_date.....', now()+(60*60*24*365*10));
        }
        $new_content->setSliceID($slice->getId());
        $this->content = $new_content->getContent();
        if ($status == SC_NO_BIN) {
            ItemContent::lastErr(ITEMCONTENT_ERROR_NO_PERM, _m("No Permission to insert Item for user %1", [$auth->auth["uid"]]));  // set error code
            return false;
        }
        return true;
    }

    /** validates and fills content with default and hidden fields in order it could be stored into database */
    function validate4Update() {
        // global $auth;

        $slice = AA_Slice::getModule($this->getSliceID());
        if (!$slice) {
            ItemContent::lastErr(ITEMCONTENT_ERROR_NO_SLICE_ID, _m("No Slice Id specified"));  // set error code
            return false;
        }
        if (!is_long_id($this->getItemID())) {
            ItemContent::lastErr(ITEMCONTENT_ERROR_NO_SLICE_ID, _m("Bad Item Id"));  // set error code
            return false;
        }

        // start from scretch with new content
        //$new_content = new ItemContent;
        //$fields      = $slice->getFields();
        //$profile     = AA_Profile::getProfile($auth->auth["uid"], $slice->getId()); // current user settings
        //
        //foreach ($fields as $field_id => $field) {
        //    $property = $field->getAaProperty();
        //    $new_content->setAaValue($field_id, $property->complete4Insert($this->getAaValue($field_id), $profile));
        //}
        //
        //// normalize - set id and well as unpacked_id
        //$new_content->setItemID($this->getItemID());
        //
        //$status = max(1, $new_content->getStatusCode(), $slice->allowed_bin_4_user());
        //$new_content->setValue('status_code.....', $status);
        //
        //if ( $new_content->getPublishDate() <= 0 ) {
        //    $new_content->setValue('publish_date....', now());
        //}
        //if ( $new_content->getExpiryDate() <= 0 ) {
        //    $new_content->setValue('expiry_date.....', now()+(60*60*24*365*10));
        //}
        //$new_content->setSliceID($slice->getId());
        //$this->content = $new_content->getContent();
        //if ($status == SC_NO_BIN) {
        //    ItemContent::lastErr(ITEMCONTENT_ERROR_NO_PERM, _m("No Permission to insert Item for user %1", array($auth->auth["uid"])));  // set error code
        //    return false;
        //}
        return true;
    }


    /** storeItem function
     *  Basic function for changing contents of items.
     *   Use always this function, not direct SQL queries.
     *   Updates the tables @c item and @c content.
     *   $GLOBALS[err][field_id] should be set on error in function
     *   It looks like it will return true even if inset_fnc_xxx fails
     *
     * @param string $mode how to deal with the stored item.
     *      update        - the fields defined in $this object are cleared and
     *                      then overwriten by values from $this object
     *                      - other fields of the item are untouched (except
     *                      the last_edit, edited_by and also all computed
     *                      fields).
     *                      The id of the item must be set before calling this
     *                      function ($this->setItemID($id))
     *      update_filled - the same as update, but the text fields with value = ""
     *                      is not updated (old value is not overwritten in this case)
     *      update_silent - the same as update, but no additional operations are
     *                      performed (the computed fields are not computed,
     *                      last_edit and edited_by is not changed, events are
     *                      not issued
     *      update/insert - updates the item if it exists. If not, the item
     *                      is inserted
     *      add           - do not clear the current content - the values are
     *                      added in paralel to curent values (stored
     *                      as multivalues for all fields stored in content
     *                      table). The id of the item must be set before
     *                      calling this function ($this->setItemID($id))
     *      overwrite     - the whole item is cleared and then filed by the
     *                      content of $this object
     *      insert_as_new - the item is stored as new item - new id is always
     *                      generated ($this->getItemID() is not taken into
     *                      account)
     *      insert_new    - if the id is not defined or the id is duplicated then
     *                      the item is stored with new id (as new item)
     *      insert        - the same as insert_as_new, but "this id" is
     *                      accepted - if the id is defined, it is stored
     *                      under specified id, otherwise the new id is
     *                      generated. The id MUST be new - the id must not be
     *                      in the database
     *      insert_if_new - the item is stored only if the item with this id
     *                      ($this->getItemID()) is not in the database.
     *                      Otherwise it is skiped (not stored)
     * @param array  $flags additional item processing flags.:
     *                  $flags[0] - invalidatecache - should we invalidate the cache for the slice?
     *                  $flags[1] - feed            - process feeding (as in in the slice setting)?
     *                  $flags[2] - throw_events    - issue update/insert event?
     *                  $flags[3] - allow_last_edit - issue update/insert event?
     * @param string $context special parameter used for thumbnails - ''|feed
     * @return bool|mixed|string
     */
    function storeItem( $mode, $flags = [], $context='' ) {
        global $event;

        if (! ($slice_id     = $this->getSliceID()))            {return false;}
        if (! ($slice        = AA_Slice::getModule($slice_id))) {return false;}
        if (! count($fields  = $slice->getFields()))            {return false;}
        $silent       = false;           // do not perform any additional operation (feed, invalidate, compute_fields, ... if not specified by flags)

        $function_params = [$mode, join('-',(array)$flags), $context];  // just for AA_Log

        if ($mode == 'update_filled') {
            $this->removeEmptyFields();
            // and from this point no difference from normal 'update'
        }

        if ( !in_array($mode, ['insert', 'insert_new', 'insert_if_new', 'insert_as_new', 'overwrite', 'add', 'update_silent', 'update/insert'])) {
            $mode = 'update';
        }

        switch ($mode) {
            case 'update_silent': $silent = true;
                                  $mode   ='update';
                                  $id     = $this->getItemID();
                                  break;
            case 'update/insert': // not tested, yet...
                                  if (!$this->getItemID() OR !itemIsDuplicate($this->getItemID())) {
                                      $mode = 'insert';
                                      $id   = $this->getItemID() ?: new_id();
                                  } else {
                                      $mode   ='update';
                                      $id     = $this->getItemID();
                                  }
                                  break;
            case 'insert_as_new': $id = new_id();
                                  $mode ='insert';
                                  break;
            case 'insert_new':    // if item is duplicate or id is not defined, store it as new item
                                  $id = (!$this->getItemID() OR itemIsDuplicate($this->getItemID())) ? new_id() : $this->getItemID();
                                  $mode ='insert';
                                  break;
            case 'insert':        $id = get_if($this->getItemID(), new_id());
                                  break;

            /** @noinspection PhpMissingBreakStatementInspection */
            case 'insert_if_new': if (!$this->getItemID()) {
                                      ItemContent::lastErr(ITEMCONTENT_ERROR_NO_ID, _m("No Id specified (%1 - %2)", [$this->getItemID(), $this->getValue('headline........')]));  // set error code
                                      if ($GLOBALS['errcheck']) huhl(ItemContent::lastErrMsg());
                                      return false;
                                  }
                                  if (itemIsDuplicate($this->getItemID())) {
                                      ItemContent::lastErr(ITEMCONTENT_ERROR_DUPLICATE, _m("Duplicated ID - skiped (%1 - %2)", [$this->getItemID(), $this->getValue('headline........')]));  // set error code
                                      if ($GLOBALS['errcheck']) huhl(ItemContent::lastErrMsg());
                                      if ($GLOBALS['debugfeed'] >= 4) print("\n<br>skipping duplicate: ".$this->getValue('headline........'));
                                      return false;
                                  }
                                  $mode ='insert';
                                  // no break!
            case 'overwrite':
            case 'add':
            default:              $id   = $this->getItemID();
                                  break;
        }

        $invalidatecache         = isset($flags[0]) ? $flags[0] : !$silent;
        $feed                    = isset($flags[1]) ? $flags[1] : !$silent;
        $throw_events            = isset($flags[2]) ? $flags[2] : !$silent;
        $allow_edit              = [];
        $allow_edit['last_edit'] = ($flags[3] AND $silent);

        if (!$id) {
            ItemContent::lastErr(ITEMCONTENT_ERROR_BAD_PARAM, _m("StoreItem for slice %1 - failed parameter check for id = '%2'", [$slice->getName(), $id]));  // set error code
            if ($GLOBALS['errcheck']) huhl(ItemContent::lastErrMsg());
            return false;
        }                                       

        // do not store item, if status_code==SC_NO_BIN
        if ($this->getStatusCode() == SC_NO_BIN) {
            ItemContent::lastErr(ITEMCONTENT_ERROR_NO_ID, _m("No Status code"));
            return false;
        }

        // remove old content first (just in content table - item is updated)
        if (in_array($mode, ['update', 'overwrite', 'add'])) {
            $oldItemContent = new ItemContent($id);
            if ($throw_events) {
                $event->comes('ITEM_BEFORE_UPDATE', $slice_id, 'S', $this, $oldItemContent);
            }
        } else {
            $oldItemContent = new ItemContent();
            if ($throw_events) {
                $event->comes('ITEM_BEFORE_INSERT', $slice_id, 'S', $this);
            }
        }

        switch ($mode) {
            case 'overwrite':
                // delete content for all the fields but not the safe fields
                $delete_conds = [['item_id', $id, 'l']];
                if ( count($safe_fields = $fields->getSafeFieldsArray()) ) {
                    $delete_conds[] = ['field_id', $safe_fields, 's<>'];
                }
                DB_AA::delete('content', $delete_conds);
                break;
            case 'update':
                $this->_clean_updated_fields($id, $fields);
                break;
            case 'insert':
                // reset hit counter fields for new items
                $hitfieds = array_filter($fields->getPriorityArray(), function($fid) { return (substr($fid, 0, 4) == 'hit_' ); });
                foreach ($hitfieds as $fid ) {
                    $this->setValue($fid, 0);
                }

                // we store the skeleton item to the item table to get short_id in order we can use _#SITEM_ID in computed fields (filename change aliases)
                // Most of the fields will be rewritten later (see below)
                AA::Metabase()->doInsert('item', ['id'=>$id,'slice_id'=>$slice_id, 'status_code'=>$this->getStatusCode(), 'externally_fed'=>'']);
                $this->setValue('short_id........',  DB_AA::select1('short_id', "SELECT short_id FROM `item`", [['id', $id, 'l']]));
                break;
        }
        // else 'add' do not clear the current content - the values are added
        // in parallel to current values (stored as multivalues for all fields
        // stored in content table)

        // and NOW - store the fields and prepare item_varset
        $item_varset = $this->_store_fields($id, $fields, $context);

        // Alerts module uses moved2active as the time when an item was moved to the active bin
        if (($mode=='insert') OR (($item_varset->get('status_code') != $oldItemContent->getStatusCode()) AND $item_varset->get('status_code') >= 1)) {
            $item_varset->add("moved2active", "number", $item_varset->get('status_code') > 1 ? 0 : time());
        }

        /** update item table */
        // we can't redefine id or short_id for the field, so if it is set, unset it
        $item_varset->remove("short_id");
        $item_varset->addkey('id', 'unpacked', $id);
        $item_varset->add("slice_id",  "unpacked", $slice_id);
        // update item table
        switch ($mode) {
            case 'update':
            case 'add':
                if ($silent) {
                    if ($allow_edit['last_edit'] AND $this->getValue('last_edit')) {
                        $item_varset->add("last_edit", "date", (int)$this->getValue('last_edit'));
                    }
                } else {
                    $item_varset->add("last_edit", "quoted", AA_Generator::factoryByString('now')->generate()->getValue());
                    $item_varset->add("edited_by", "quoted", AA_Generator::factoryByString('uid')->generate()->getValue());
                }
                $item_varset->doUpdate('item');
                break;
            case 'overwrite':
                if ($item_varset->get('status_code') < 1) {
                    $item_varset->set('status_code', 1);
                }
                $item_varset->set('display_count', (int)$this->getValue('display_count...'));
                $item_varset->add("last_edit", "quoted",   AA_Generator::factoryByString('now')->generate()->getValue());
                $item_varset->add("edited_by", "quoted",   AA_Generator::factoryByString('uid')->generate()->getValue());
                $item_varset->set('externally_fed', (int)$this->getValue('externally_fed..'));
                $item_varset->doReplace('item');
                break;
            case 'insert':
                // check, if all data in item table are correct
                if ($item_varset->get('status_code') < 1) {
                    $item_varset->set('status_code', 1);
                }
                if ($item_varset->get('publish_date') < 1) {
                    $item_varset->set('publish_date', AA_Generator::factoryByString('now')->generate()->getValue());
                }
                if ($item_varset->get('expiry_date') < 1) {
                    $item_varset->set('expiry_date', AA_Generator::factoryByString('never')->generate()->getValue());
                }
                $item_varset->set('display_count', (int)$this->getValue('display_count...'));
                $item_varset->add('post_date', "quoted", AA_Generator::factoryByString('now')->generate()->getValue());
                $item_varset->add('posted_by', "quoted", AA_Generator::factoryByString('uid')->generate()->getValue());
                $item_varset->set('externally_fed', (int)$this->getValue('externally_fed..'));
                $item_varset->doUpdate('item');  // already inserted above
        }
        if ($invalidatecache) {
            // invalidate old cached values
            AA::Pagecache()->invalidateFor($slice_id);
        }

        // get the content back from database
        $itemContent = new ItemContent();
        $itemContent->setByItemID($id,true);     // ignore reading password

        // look for computed fields and update it (based on the stored item)
        if (!$silent) {
            if ( $itemContent->updateComputedFields($fields, $mode) ) {
                // if computed fields are updated, reread the content
                $itemContent->setByItemID($id,true); // ignore reading password
            }
        }

        $zid = new zids($id,'l');

        // invalidate from inner cache
        // AA_Items::invalidateItem(new zids($id, 'l'));

        // maybe we can delete this condition - we just know we need to invalidate old contents for recomputing items in ITEM_NEW task
        if ($throw_events) {
            //AA_Items::invalidate();      // this deletes also cached items in Saver, which you do not want - that's why it is commented out
            AA::Contentcache()->clear_soft();  // do not delete {define:..} used by {var:..} - sometimes used by {newitem}
        }
        // renew content if the item in the cache - we will use it in Planned tasks, ... where we do not know slice password...
        AA_Items::getItem($zid, $slice->getProperty('reading_password'), true);

        if ($feed) {
            FeedItem($id);
        }

        $historylog = $slice->getProperty('historylog');
        // ignore last_edit change (there was quite a lot of such changes, where the only change was in this field (in import....))
        if (($mode != 'insert') AND ($historylog !== '0') AND count($diff = $itemContent->diff($oldItemContent, ['last_edit.......']))) {
            ChangesMonitor::singleton()->addHistory($diff);
        }
        // slice setting historylog applied to history as well as to log for item new/update
        if ($historylog !== '0') {
            AA_Log::write(($mode == 'insert') ? 'ITEM_NEW' : 'ITEM_UPD', AA_Log::context($id), $function_params);
        }
        // events are after ChangeMonitor - sometimes we use {chenged:} in the events
        if ($throw_events) {
            if ($mode == 'insert') {
                AA_Plannedtask::executeForEvent($slice_id, 'ITEM_NEW', $id);
                $event->comes('ITEM_NEW', $slice_id, 'S', $itemContent);  // new form event
            } else {
                AA_Plannedtask::executeForEvent($slice_id, 'ITEM_UPDATED', $id);
                $event->comes('ITEM_UPDATED', $slice_id, 'S', $itemContent, $oldItemContent); // new form event
            }
        }

        return $id;
    } // end of storeItem()

    /** updateComputedFields function
     * @param AA_Fields|null $fields
     * @param string         $mode
     * @param array          $restict_fields
     * @return bool
     */
    function updateComputedFields(?AA_Fields $fields = null, string $mode = 'update', array $restict_fields = []): bool {

        if (!($id = $this->getItemID()) OR $this->isEmpty()) {  // the recomputed item is already deleted, probably
            return false;
        }

        // could be called also from outside to recompute fields
        if (! ($slice = AA_Slice::getModule($this->getSliceID())) ) {
            return false;
        }

        $field_writer = new AA_Field_Writer($this->getSliceID());

        $update       = (($mode == 'update') OR ($mode == 'overwrite') OR ($mode == 'add'));
        $computed_field_exist = false;

        if (!$fields) {
            $fields = $slice->getFields();
        }

        foreach ($fields as $fid => $field) {

            if ( count($restict_fields) AND !in_array($fid, $restict_fields)) {
                // we can restrict the recomputed fields in {recompute}
                // so - skip not recomputed fields
                continue;
            }

            // input insert function parameters of field
            $fnc = ParseFnc($field->getProperty("input_insert_func"));

            if (!$fnc) {
                continue;
            }

            // computed field?
            switch ($fnc["fnc"]) {
                case 'seo':
                    $seo_alias        = strlen(trim($fnc["param"])) ? $fnc["param"] : '_#HEADLINE';
                    $slice_charset    = $slice->getCharset();
                    $seo_charset      = ($slice_charset AND $slice_charset != 'utf-8') ? ":$slice_charset" : '';

                    // seo field is not filled or we "Insert as new" and teh seo is already used (probably we forgot to delete the value)
                    if (!strlen($this->getValue($fid)) OR (($mode=='insert') AND strlen(str_replace([$this->getItemID(), '-'], ['',''], StrExpand('AA_Stringexpand_Seo2ids', ['all', $this->getValue($fid)], ['item'=>GetItemFromContent($this)]))))) {
                        $expand_string = '{seoname:{expand:'.$seo_alias.'}:all'.$seo_charset.'}';
                    } else {
                        continue 2;  // next field
                    } 
                 // $expand_string    = ($mode=='insert') ? '{seoname:{ifset:{@'.$fid.':| /}:_#1:{'.$seo_alias.'}}:all'.$seo_charset.'}' : '{ifset:{@'.$fid.':| /}:_#1:{seoname:{'.$seo_alias.'}:all'.$seo_charset.'}}';
                 // $expand_string    = '{ifset:{@'.$fid.':| /}:_#1:{seoname:{'.$seo_alias.'}:all'.$seo_charset.'}}';
                    $expand_delimiter = '| /';     // just some unprobabale string from the line above
                    unset($expand_insert,$expand_update,$recompute);
                    break;
                case 'com':
                    $expand_string = $fnc["param"];
                    unset($expand_insert,$expand_update,$expand_delimiter, $recompute);
                    break;
                case 'co2':
                    [$expand_insert,$expand_update,$expand_delimiter,$recompute] = ParamExplode($fnc["param"]);
                    $expand_string = $update ? $expand_update : $expand_insert;
                    break;
                default:
                    continue 2;  // next field
            }

            if (strlen($expand_string)<=0) {
                continue;
            }
            // the code, which (unaliased!) should be stored in the field {ifset:{seo.............}:_#1:{seoname:{_#HEADLINE}:all:windows-1250}}
            // is in parameter
            if ($computed_field_exist === false) {
                $computed_field_exist = true;
                // prepare item for computing
                $item  = new AA_Item($this->getContent(),$slice->aliases());
            }


            // compute new value for this computed field
            $new_computed_value = $item->unalias($expand_string);
            $aa_val = new AA_Value( strlen($expand_delimiter) ? array_filter(explode($expand_delimiter,$new_computed_value) ,'strlen') : $new_computed_value, $field->getProperty('html_default')>0 ? FLAG_HTML : 0);

            // set this value also to $item in order we can count with it
            // in next computed field
            $item->setAaValue($fid, $aa_val);
            $values = $item->getValues($fid);

            // delete content just for this computed field
            // $this->_clean_updated_fields($id, $fields);
            if ($id AND $fid) {
                DB_AA::delete('content', [['item_id', $id, 'l'], ['field_id', $fid]]);
            }

            foreach($values as $varr) {
                //  store the computed value for this field to database
                AA_Inserter::factoryByString('qte')->setField($field)->execute($field_writer, $id, $varr);
            }
        }

        $item_varset = $field_writer->getItemVarset();

        if (!$item_varset->isEmpty()) {
            $item_varset->addkey('id', 'unpacked', $id);
            $item_varset->doUpdate('item');
        }

        return $computed_field_exist;
    }

    /** storeSliceFields function
     *  Stores the fields into content table for dynamic "slice setting fields"
     * @param $slice_id
     * @param AA_Fields $fields
     */
    function storeSliceFields($slice_id, $fields) {
        // delete content of all fields, which are in new content array
        // (this means - all not redefined fields are unchanged)
        $this->_clean_updated_fields($slice_id, $fields);

        // we use slice_id as item id here
        $this->_store_fields($slice_id, $fields);

        AA::Pagecache()->invalidateFor($slice_id);
    }

    /** unalias the text using content of this itemcontent and aliases of the slice */
    function unalias($text) {
        $item = GetItemFromContent($this);
        return is_null($item) ? '' : $item->unalias($text);
    }

    ///** _clean_updated_fields function
    // *  delete content of all fields, which are in new content array
    // *  (this means - all not redefined fields are unchanged)
    // * @param $id
    // * @param $fields
    // */
    //function _clean_updated_fields($id, &$fields) {
    //    $in = array();
    //    foreach ($this->content as $fid => $fooo) {
    //        if (!$fields[$fid]['in_item_tbl']) {
    //            $in[] = $fid;
    //        }
    //    }
    //    if ($in AND $id) {
    //        // delete content just for displayed fields
    //        DB_AA::delete('content', array(array('item_id', $id, 'l'), array('field_id', $in)));
    //        // note extra images deleted in insert_fnc_fil if needed
    //    }
    //}

    /**
     * @param $id
     * @param AA_Fields $fields
     */
    function _clean_updated_fields($id, $fields) {
        $in  = [];
        foreach ($this->content as $fid => $cont) {
            $fld = $fields->getField($fid);
            if ($fld AND !$fld->getProperty('in_item_tbl') AND !$fld->isSafeStored()) {   // do not delete 'secret....* ',  'password....', ... fields (the content should be rewritten in Inserter, if all conditions matches)
                // deal with translations. if only translated values are present, then delete just the specific translation
                // however - if also basic 0-1000000 indexes are present, clear all the field content
                $keys = (is_array($cont) AND $cont) ? array_keys($cont) : [0]; // sometimes we want delete field so no AA_Value is_a passed (delete file...)
                foreach ($keys as $k) {
                    $in[$ind = ($k / AA_Value::MAX_INDEX)][] = $fid;
                    if ($ind==0) {
                        continue;
                    }
                }
            }
        }
        if ($in AND $id) {
            // delete content just for displayed fields
            foreach($in as $lang => $field_arr) {
                if ($lang==0) {
                    DB_AA::delete('content', [['item_id', $id, 'l'], ['field_id', array_unique($field_arr)]]);
                } else {
                    //huhl(DB_AA::makeWhere(array(array('item_id', $id, 'l'), array('field_id', array_unique($field_arr)), array('number', $lang*AA_Value::MAX_INDEX, '>='), array('number', ($lang+1)*AA_Value::MAX_INDEX, '<'))));
                    //huhl(DB_AA::makeWhere(array(array('item_id', $id, 'l'), array('field_id', array_unique($field_arr)), array('number', 0, '>='), array('number', AA_Value::MAX_INDEX, '<'))));
                    //huhl($in);
                    //huhl(DB_AA::select(array(), 'SELECT * FROM content', array(array('item_id', $id, 'l'), array('field_id', array_unique($field_arr)))));
                    //exit;
                    DB_AA::delete('content', [['item_id', $id, 'l'], ['field_id', array_unique($field_arr)], ['number', $lang*AA_Value::MAX_INDEX, '>='], ['number', ($lang+1)*AA_Value::MAX_INDEX, '<']]);
                    DB_AA::delete('content', [['item_id', $id, 'l'], ['field_id', array_unique($field_arr)], ['number', 0, '>='], ['number', AA_Value::MAX_INDEX, '<']]);
                    DB_AA::delete('content', [['item_id', $id, 'l'], ['field_id', array_unique($field_arr)], ['number', 'ISNULL']]);
                }
            }
            // note extra images deleted in insert_fnc_fil if needed
        }
    }

    /** _store_fields function
     *  private function - goes through content and runs all insert functions
     *  on each field in content array. The content is stored in the database
     *  or in returned item_varset
     * @param $id
     * @param AA_Fields $aa_fields
     * @param string    $context  - special parameter used for thumbnails - ''|feed
     * @return Cvarset
     */
    function _store_fields($id, $aa_fields, $context='') {
        $field_writer = new AA_Field_Writer($this->getSliceID());
        $stop         = false;

        foreach ($this->content as $fid => $cont) {
            if (!is_array($cont) OR !($fld = $aa_fields->getField($fid))) {
                continue;
            }

            $inserter = AA_Inserter::factoryByField($fld);

            if ( !is_a($inserter,'AA_Inserter_Nul')) {
                // update content table or fill item_varset
                $parameters = [];
                $thumbnails = [];    // probably for preventing infinite loop ? HM

                foreach ($cont as $numkey => $v) {

                    $numkey = (int)$numkey;
                    // file upload needs the $fields array, because it stores
                    // some other fields as thumbnails

                    if ( is_a($inserter,'AA_Inserter_Fil')) {
                        //Note $thumbnails is undefined the first time in this loop
                        if ($thumbnails) {
                            foreach ($thumbnails as $v_stop) {
                                if ($v_stop == $fid) {
                                    $stop = true;
                                }
                            }
                        }

                        if (!$stop) {
                            $parameters["order"]   = $numkey;
                            $parameters["fields"]  = $aa_fields;
                            $parameters["context"] = $context;
                            $thumbnails = $inserter->execute($field_writer, $id, $v, $parameters);
                        }
                    } else {
                        $inserter->execute($field_writer, $id, $v, ['order'=>$numkey]);
                    }
                    // do not store multiple values if field is not marked as multiple
                    // ERRORNOUS
                    //if( !$f["multiple"]!=1 )
                        //continue;
                }
            }
        }
        return $field_writer->getItemVarset();
    }

    /** transform function
     * Transform $itemContent according to the transformation actions $trans_actions and slice fields $slice_fields
     * @param $itemContent
     * @param Actions $trans_actions
     * @param $slice_fields
     * @return
     */
    function transform(&$itemContent, $trans_actions, $slice_fields) {
        return $trans_actions->transform($itemContent, $slice_fields, $this);
    }

    /** showAsRowInTable function
     *  Show the item in one row in a table according to the order specified
     *  by slice fields $slf
     * @param $slf
     * @param $tr_att
     */
    function showAsRowInTable($slf, $tr_att="") {
        echo "<tr ".$tr_att." >";
        foreach ( $slf as $k => $foo) {
            if (!($v = $this->content[$k])) {
                echo "<td></td>";
            } else {
                echo "<td>";
                unset($s);
                foreach ($v as $v2) {
                    $v2['value'] = stripslashes($v2['value']);
                    $s[] = $v2['html'] ? $v2['value'] : myspecialchars($v2['value']);
                }
                if (count($s) == 1) {
                    echo $s[0];
                } else {
                    echo "[ ". implode(", ",$s) . " ]";
                }
                echo "</td>";
            }
        }
        echo "</tr>";
    }
}

/** itemIsDuplicate function
 *  Figure out if item already imported into this slice
 * Id's are unpacked
 * Note that this could be replaced by feeding.php3:IsItemFed which is more complex and would use orig id
 * @param $item_id
 * @return bool
 */
function itemIsDuplicate($item_id) {
    return DB_AA::test('item', [['id', $item_id, 'l']]);
}

/** field_content is AA_Value object
 *  $field_content could be AA_Value or scalar or array()
 * @param string $item_id long item id
 * @param string $field_id field id
 * @param string|AA_Value $field_content
 * @param array $flags   =  // invalidate cache, feed, throw events
 * @return int
 */
function UpdateField($item_id, $field_id, $field_content, $flags = []) {
    if (!$flags) {
        $flags = [true, false, true];   // invalidate cache, not feed, throw events
    }
    $content4id = new ItemContent();
    $content4id->setByItemID($item_id, true);     // ignore password
    // if we do not ignore it, then whole item is destroyed for slices with slice_pwd

    if (!($field_content instanceof AA_Value)) {
        $field_content = new AA_Value($field_content, $content4id->getFlag($field_id));
    }

    $sli_id     = $content4id->getSliceID();
    unset($content4id);

    $newcontent4id = new ItemContent();
    $newcontent4id->setAaValue($field_id, $field_content);
    $newcontent4id->setItemID($item_id);
    $newcontent4id->setSliceID($sli_id);
    $updated_items = 0;

    if ($newcontent4id->storeItem( 'update', $flags )) {    // invalidatecache, not feed, throw_events
        $updated_items = 1;
    }
    return $updated_items;
}


