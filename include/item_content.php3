<?php
/**
 * Class ItemContent.
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
 * @package   UserInput
 * @version   $Id$
 * @author    Jakub Adamek, Econnect
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (c) 2002-3 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/
require_once AA_INC_PATH."locsess.php3";    // DB_AA object definition
require_once AA_INC_PATH."feeding.php3";
require_once AA_INC_PATH."itemfunc.php3";

/**
 * AA_Value - Holds information about one value - could be multiple,
 *            could contain flags...
 *          - the values are always plain (= no quoting, no htmlspecialchars...)
 */
class AA_Value implements Iterator, ArrayAccess, Countable {
    /** array of the values */
    var $val;

    /** holds the flag - common for all the values */
    var $flag;

    /** AA_Value function
     * @param $value
     * @param $flag
     */
    function __construct($value=null, $flag=null) {
        $this->clear();
        $this->addValue($value);
        $this->setFlag(!is_null($flag) ? $flag : ( (is_array($value) AND is_array($value[0])) ? $value[0]['flag'] : 0));
    }

    /** Static for creating value from any value
     *  Called as
     *     AA_Value::factory($val);
     */
    function factory($val) {
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
    function factoryFromJson($json) {
        $aav = new AA_Value();
        if (($json[0] == '[') AND (!is_null($arr = json_decode($json, true)))) {
            $aav->setValues(array_values($arr));
        } else {
            $aav->setValues($json);
        }
        return $aav;
    }

    /** Static for creating value from array[][value]
     *  Called as
     *     AA_Value::factoryFromContent($arr);
     */
    function factoryFromContent($arr) {
        $aav = new AA_Value();
        // preserves keys - necessary for translated values
        foreach($arr as $key => $val) {
            $aav->val[(int)$key] = $val['value'];
        }
        $first = reset($arr);
        return $aav->setFlag( $first['flag'] );
    }


    /** getValue function
     *  Returns the value for a field. If it is a multi-value
     *   field, this is the first value.
     * @param $i
     */
    function getValue($i=0) {
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
    function getFlag($i=0) {
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
            foreach($value as $key => $val) {
                $this->val[(int)$key] = is_array($val) ? $val['value'] : (!is_object($val) ? $val : serialize($val));
                // @todo check, if $val->getSomething is callable
            }
        } elseif ( !is_null($value) ) {
            $this->val[] = $value;
        }
        return $this->removeDuplicates();
    }

    /** Remove Value */
    function removeValues($remove) {
        $this->val = array_diff($this->val, $remove);
        return $this;
    }

    /** Set $value - value is normal (numeric) array or string value */
    function setValues($value) {
        $this->val = is_array($value) ? $value : (is_null($value) ? array() : array($value));
        return $this;
    }

    /** Set the flag (for al the values the flag is common at this time) */
    function setFlag($flag) {
        $this->flag = $flag;
        return $this;
    }

    /** clear function  */
    function clear() {
        $this->val  = array();
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
     */
    function fixTranslations($translations) {
        if ($translations) {
            $maxindex = 0;
            foreach ($this->val as $k => $v) {
                if (strlen($v)) { // do not add index for all empty values
                    $maxindex = max($maxindex, $k % 1000000);
                }
            }

            $first  = true;
            $newval = array();
            foreach($translations as $lang) {
                $lang_id = AA_Content::getLangNumber($lang);  // converts two letter lang code into number used for translation fields (cz -> 78000000, en -> 118000000, ...)
                for ($i=0; $i<=$maxindex; $i++) {
                    $value = isset($this->val[$lang_id + $i]) ? $this->val[$lang_id + $i] : '';
                    if ( !strlen($value) AND $first AND isset($this->val[$i])) {
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
        if (key($this->val) < 1000000) {  // do not remove for multilingual
            $this->val = array_values( array_unique($this->val) );
        }
        return $this;
    }

    /** getArray function
     *  @return clasic $content value array - [0]['value'] = ..
     *                                           ['flag']  = ..
     *                                        [1]['value'] = ..
     *          the values are not quoted, ...
     *  Mainly for backward compatibility with old - array approach
     */
     function getArray() {
         $ret = array();
         foreach ($this->val as $k => $v) {
             $ret[(int)$k] = array('value'=>$v, 'flag'=>$this->flag);
         }
         return $ret;
     }

    /** Iterator interface */
    public function rewind()  { reset($this->val);                        }
    public function current() { return current($this->val);               }
    public function key()     { return key($this->val);                   }
    public function next()    { next($this->val);                         }
    public function valid()   { return (current($this->val) !== false);   }

    /** Countable interface - Returns number of values  */
    public function count()   { return count($this->val);                 }

    /** ArrayAccess interface */
    public function offsetSet($offset, $value) { $this->val[$offset] = $value;      }
    public function offsetExists($offset)      { return isset($this->val[$offset]); }
    public function offsetUnset($offset)       { unset($this->val[$offset]);        }
    public function offsetGet($offset)         { return isset($this->val[$offset]) ? $this->val[$offset] : null; }
}


/** Class holds any data (AA_Values)
 *  Universal data structure interface - it could hold item data as well as
 *  data from the form
 */
class AA_Content {
    protected $content;
    protected $id_field     = 'aa_id';
    protected $owner_field  = 'aa_owner';

    function unalias($text) {
        return AA_Stringexpand::unalias($text);
    }

    /** isField function
     *  Returns true, if the passed field_id is field id
     *  @param $field_id
     */
    function isField($field_id) {
        return isset($this->content[$field_id]);
    }

    /** setAaValue function
     *  Special function - fills field from AA_Value object
     * @param $field_id
     * @param $value       AA_Value
     */
    function setAaValue($field_id, $value) {
        if (is_object($value)) {
            // we expect AA_Value object here
            $this->content[$field_id] = $value->getArray();
        }
    }

    /** set object id based on id_field setting for this content */
    function setId($id) {
        $this->setAaValue($this->id_field, new AA_Value( $id ));
    }

    /** set object id based on id_field setting for this content */
    function setOwnerId($id) {
        $this->setAaValue($this->owner_field, new AA_Value( $id ));
    }

    /** set object id based on id_field setting for this content */
    function addValue($field_id, $value) {
        $this->content[$field_id][] = array('value'=>$value, 'flag'=>0);
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
     */
    function getAaValue($field_id) {
        return AA_Value::factoryFromContent( $this->content[$field_id] );
    }

    function isMultilingual($field_id) {
        return is_array($a = $this->content[$field_id]) && (key($a) >= 1000000);
    }

    /** getValue function
     *  Returns the value for a field. If it is a multi-value
     *   field, this is the first value.
     * @param $field_id
     * @param $what
     */
    function getValue($field_id, $idx=0) {
        if ( !is_array($a = $this->content[$field_id]) ) {
            return false;
        }
        if (isset($a[$idx])) {
            return $a[$idx]['value'];
        }
        // the same test as in isMultilingual($field_id) above;
        if ( (key($a)>=1000000) AND ($idx<1000000) ) {
            if (strlen($v = $a[AA::$langnum[0]+$idx]['value'])) {
                return $v;
            } elseif (strlen($v = $a[$this->_getDefaultLangNum()+$idx]['value'])) {
                return $v;
            }
        }
        return false;
        //return ( is_array($a = $this->content[$field_id]) ? $a[$idx]['value'] : false );
    }

    private function _getDefaultLangNum() {
        static $def_lang_num = '';
        if ($def_lang_num) {
            return $def_lang_num;
        }
        $def_lang = '';
        if ( AA::$site_id ) {
            $def_lang = AA_Modules::getModule(AA::$site_id)->getDefaultLang();
        } else {
            $def_lang = AA_Modules::getModule($this->getOwnerId())->getDefaultLang() ?: strtolower(substr(DEFAULT_LANG_INCLUDE,0,2));      // actual language - two letter shortcut cz / es / en
        }
        return ($def_lang_num = AA_Content::getLangNumber($def_lang)); // array of prefered languages in priority order.
    }

    function getFlag($field_id) {
        if (is_array($this->content[$field_id])) {
            $curr = reset($this->content[$field_id]);
            return $curr['flag'];
        }
        return false;
    }

    /** getValues function
     * @param $field_id
     */
    function getValues($field_id) {
        return ( is_array($a = $this->content[$field_id]) ? $a : false );
    }

    /** getValuesArray function
     * @param $field_id
     */
    function getValuesArray($field_id) {
        return empty($this->content[$field_id]) ? array() : array_map( function($val) {return $val['value'];}, $this->content[$field_id] );
    }

    function diff($object2compare) {
        $changes = array();
        foreach ($this->content as $fid => $a) {
            $b = $object2compare->getValuesArray($fid);
            if ($this->getValuesArray($fid) != $b) {
                $changes[] = new AA_ChangeProposal($this->getId(), $fid, $b);
            }
        }
        // search for values not present in current object
        $b_fields = $object2compare->getFields();
        foreach ($b_fields as $fid) {
            if (!$this->isField($fid)) {
                $changes[] = new AA_ChangeProposal($this->getId(), $fid, $object2compare->getValuesArray($fid));
            }
        }
        return $changes;
    }

    /** converts two letter lang code into number used for translation fields in $content4id array
     *  cz -> 78000000, en -> 118000000, ...
     *  you can use any two (smallcaps) letter for language
     */
    static function getLangNumber($lang) {
        return strlen($lang) ? 1000000*((ord($lang{0})-97)*26+(ord($lang{1})-97+1)) : 0;
    }

    /** reverse function to getLangNumber
     *  78000000 -> cz, 78000001 -> cz, 118000000 -> en...
     */
    static function getLangId($langnumber) {
        if ($langnumber<1000000) {
            return '';
        }
        $num = $langnumber/1000000-1;
        return chr(intval($num / 26) + 97) . chr(($num % 26)+97);
    }


    /** @return Abstract Data Structure of current object
     *  @deprecated - for backward compatibility (used in AA_Object getContent)
     */
    function getContent() {
        return $this->content;
    }
}

define('ITEMCONTENT_ERROR_BAD_PARAM',   200);
define('ITEMCONTENT_ERROR_DUPLICATE',   201);
define('ITEMCONTENT_ERROR_NO_ID',       202);
define('ITEMCONTENT_ERROR_NO_SLICE_ID', 203);
define('ITEMCONTENT_ERROR_NO_PERM',     204);

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
    var $classname = "ItemContent";

    /** ItemContent function
     *  Constructor which takes content for ID or item_id (unpacked).
     * @param $content4id
     */
    function ItemContent($content4id = "") {
        if ($content4id) {
            if ( is_array($content4id) ) {
                $this->setFromArray($content4id);
            } elseif ( $content4id ) {
                $this->setByItemID($content4id );
            }
        }
    }

    /** isField function
     *  Returns true, if the passed field id looks like field id
     *  @param $field_id
     *  @todo - look directly into module, if the field is really field
     *          in specific slice/module
     */
    function isField($field_id) {
        static $f_def;
        if ( !isset($f_def) ) {
            $f_def = array_flip(array_merge(array_keys(GetLinkFields()), array_keys(GetCategoryFields()), array_keys(GetConstantFields())));
        }
        // changed this from [a-z_]+\.+[0-9]*$ because of alerts[12]....abcde
        return (((strlen($field_id)==16) AND preg_match('/^[a-z0-9_]+\.+[0-9A-Za-z]*$/',$field_id)) OR $f_def[$field_id]);
    }

    /** lastErr function
     *  Method returns or sets last itemContent error
     *  The trick for static class variables is used
     * @param $err_id
     * @param $err_msg
     * @param $getmsg
     */
    function lastErr($err_id = null, $err_msg = null, $getmsg = false) {
        static $lastErr;
        static $lastErrMsg;
        if (!is_null($err_id)) {
            $lastErr    = $err_id;
            $lastErrMsg = $err_msg;
        }
        return $getmsg ? $lastErrMsg : $lastErr;
    }

    /** lastErrMsg function
     *  Return last error message - it is grabbed from static variable
     *  of lastErr() method
     */
    function lastErrMsg() {
        return ItemContent::lastErr(null, null, true);
    }

    /** setFromArray function
     * @param $content4id
     */
    function setFromArray(&$content4id) {
        $this->content = $content4id;
    }

    /** setByItemID function
     * Set by item ID (zid or unpacked or short)
     * @param $item_id
     * @param $ignore_reading_password
     */
    function setByItemID($item_id, $ignore_reading_password=false) {
        if (!$item_id) {
            return false;
        }
        $zid           = is_object($item_id) ? $item_id : new zids($item_id);
        $content       = GetItemContent($zid, false, $ignore_reading_password);
        $this->content = is_array($content) ? reset($content) : null;
    }

    /** setFieldsFromForm function
     *  Functions tries to fill all the fields from the form. It do not add any
     *  item specific fields (like status_code), so it could be used also for
     *  dynamic "slice setting fields"
     * @param $slice
     * @param $content4id
     * @param $insert
     * @param $slice_fields
     */
    function setFieldsFromForm($slice, $oldcontent4id="", $insert=true, $slice_fields=false) {
        global $auth;

        list($fields, $prifields) = $slice->fields(null, $slice_fields);
        if (!isset($prifields) OR !is_array($prifields)) {
            return false;
        }

        $profile   = AA_Profile::getProfile($auth->auth["uid"], $slice->getId()); // current user settings

        foreach ($prifields as $pri_field_id) {
            $f = $fields[$pri_field_id];

            // to content array add just displayed fields (see ShowForm())
            if (!IsEditable($oldcontent4id[$pri_field_id], $f, $profile) AND !$insert) {
                continue;
            }

            // "v" prefix - database field var
            $varname     = 'v'. unpack_id($pri_field_id);
            $htmlvarname = $varname."html";

            // if there are predefined values in user profile, fill it.
            // Fill it only if $insert (new item). Otherwise left there filled value

            $profile_value = $profile->getProperty('hide&fill',$f['id']);
            if (!$profile_value) {
                $profile_value = $profile->getProperty('fill',$f['id']);
            }

            if ( $profile_value ) {
                $x = $profile->parseContentProperty($profile_value);
                // modify the value to be compatible with $_GET[] array
                $GLOBALS[$varname]     = addslashes($x->getValue());
                $GLOBALS[$htmlvarname] = $x->getFlag();
            }

            $var = $GLOBALS[$varname];
            if (!is_array($var)) {
                $var = array(0 => $var);
            }

            // fill the multivalues
            foreach ($var as $key => $v) {
                $flag = $f["html_show"] ? ($GLOBALS[$htmlvarname]=="h" ? FLAG_HTML : 0)
                                        : ($f["html_default"] > 0      ? FLAG_HTML : 0);
                // content uses NOT quoted values => stripslashes
                $this->content[$pri_field_id][(int)$key]   = array('value'=> stripslashes($v), 'flag'=>$flag);
            }
        }
        return true;
    }


    /** setFromForm function
     *  Fills content4id - values in content4id are NOT quoted (addslashes)
     *  (new version of previous GetContentFromForm() function)
     * @param $slice
     * @param $oldcontent4id
     * @param $insert
     */
    function setFromForm( $slice, $oldcontent4id="", $insert=true) {
        global $id;

        if (!$this->setFieldsFromForm($slice, $oldcontent4id, $insert)) {
            return false;
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
    }

    /** is_empty function
     *
     */
    function is_empty() {
        return !is_array($this->content);
    }

    /** is_set function
     * @param $field_id
     */
    function is_set($field_id) {
        return is_array($this->content[$field_id]);
    }

    /** true if the item is viseble - not expired/trashed/pending/... */
    function isActive() {
        $now = now('step');
        return (($this->getStatusCode() == 1) AND ($this->getPublishDate() <= $now) AND ($this->getExpiryDate() > $now));
    }

    /** matches function
     * @param $conditions
     */
    function matches(&$conditions) {
        return $conditions->matches($this);
    }

    /** getFields function
     *
     */
    function getFields() {
        $fields = array();
        if ( isset( $this->content ) AND is_array( $this->content ) ) {
            foreach ( $this->content as $field => $foo ) {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    /** getItemID function
     */
    function getItemID() {
        return unpack_id($this->getValue('id..............'));
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
        return unpack_id($this->getValue('slice_id........'));
    }

    /** getStatusCode function
     */
    function getStatusCode() {
        return $this->getValue("status_code.....");
    }

    /** getPublishDate function
     */
    function getPublishDate() {
        return $this->getValue("publish_date....");
    }

    /** getExpiryDate function
     */
    function getExpiryDate() {
        return $this->getValue("expiry_date.....");
    }

    /** setValue function
     * @param $field_id
     * @param $val
     */
    function setValue($field_id,$val,$num=0) {
        $this->content[$field_id][$num]['value'] = $val;
    }

    /** setItemID function
     * @param $value
     */
    function setItemID($value) {
        $this->setValue("id..............", pack_id($value));
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
     * @param $csvRec
     * @param $fieldNames
     */
    function setFromCSVArray(&$csvRec, &$fieldNames) {
        $i = 0;
        foreach ($fieldNames as $k => $foo) {
            $this->content[$k][0]['value'] = $csvRec[$i++];
        }
    }

    /** storeToDB function
     *  Store item content to DB. if an item has item_id, which is already
     *  stored in $items_id, then according to the $actionIfItemExists performs:
     *    a) "update"    : update the item in DB
     *    b) or "new_id" : store the item with different (unique random) id
     *    c) otherwise   : do nothing
     *  TODO - convert to AA_Grabber/AA_Saver API
     * @param $alice_id
     * @param $actionIfItemExists
     * @param $invalidatecache
     */
    function storeToDB($slice_id, $actionIfItemExists=STORE_WITH_NEW_ID, $invalidatecache = true) {
        require_once AA_INC_PATH."varset.php3";
        require_once AA_INC_PATH."itemfunc.php3";
        global $err, $varset, $itemvarset, $error, $ok;

        $id = $this->getValue("id..............");
        if ($id == "new id") {	    // if the item has no id => set up an unique new id
            $id = new_id();
            $insert = true;
        } else {
            // Check duplicity
            $insert = (false===DB_AA::test('item', array(array('id',$id, 'l'))));
        }
        if ($insert == false) {	    // if the item is already in the DB :
            switch ($actionIfItemExists) {
                case UPDATE:  	    // the item  will be updated
                    break;
                case STORE_WITH_NEW_ID:
                    $id     = new_id();	// the item should be stored with a new id
                    $insert = true;
                    break;
                case NOT_STORE:
                default: 		    // NOT_STORE or any other value => do not store the item
                    return array(0=>NOT_STORE,1=>$id);
            }
        }

        $this->setItemID($id);
        $this->setSliceID($slice_id);
        $added_to_db = $this->storeItem($insert ? 'insert' : 'update', array($invalidatecache, false));     // invalidatecache, feed

        return $added_to_db ? array( 0 => ($insert ? 'INSERT' : 'UPDATE'), 1 => $id ) : false;
    }

    function validateReport() {
        $slice  = AA_Slice::getModule($this->getSliceID());
        $fields = $slice->getFields();
        $ret    = array();

        foreach ($fields as $field_id => $field) {
            $property    = $field->getAaProperty();
            if ($validreport = $property->validateReport($this->getValuesArray($field_id))) {
                $ret[$field_id] = $validreport;
            }
        }
        return $ret;
    }

    /** validates and fills content with default and hidden fields in order it could be stored into database */
    function complete4Insert() {
        global $auth;

        $slice = AA_Slice::getModule($this->getSliceID());
        if (!$slice) {
            ItemContent::lastErr(ITEMCONTENT_ERROR_NO_SLICE_ID, _m("No Slice Id specified"));  // set error code
            return false;
        }

        // start from scretch with new content
        $new_content = new ItemContent;
        $fields      = $slice->getFields();
        $profile     = AA_Profile::getProfile($auth->auth["uid"], $slice->getId()); // current user settings

        foreach ($fields as $field_id => $field) {
            $property = $field->getAaProperty();
            $new_content->setAaValue($field_id, $property->complete4Insert($this->getAaValue($field_id), $profile));
        }

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
            ItemContent::lastErr(ITEMCONTENT_ERROR_NO_PERM, _m("No Permission to insert Item for user %1", array($auth->auth["uid"])));  // set error code
            return false;
        }
        return true;
    }

    /** storeItem function
     *  Basic function for changing contents of items.
     *   Use always this function, not direct SQL queries.
     *   Updates the tables @c item and @c content.
     *   $GLOBALS[err][field_id] should be set on error in function
     *   It looks like it will return true even if inset_fnc_xxx fails
     *
     *   @param string $mode   how to deal with the stored item.
     *      update        - the fields defined in $this object are cleared and
     *                      then overwriten by values from $this object
     *                      - other fields of the item are untouched (except
     *                      the last_edit, edited_by and also all computed
     *                      fields).
     *                      The id of the item must be set before calling this
     *                      function ($this->setItemID($id))
     *      update_silent - the same as update, but no additional operations are
     *                      performed (the computed fields are not computed,
     *                      last_edit and edited_by is not changed, events are
     *                      not issued
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
     *    @param array  $flags    additional item processing flags.:
     *                  $flags[0] - invalidatecache - should we invalidate
     *                                                 the cache for the slice?
     *
     *                  $flags[1] - feed            - procces feeding (as in in
     *                                                the slice setting)?
     *                  $flags[2] - throw_events    - issue update/insert event?
     *    @param string $context  special parameter used for thumbnails
     */
    function storeItem( $mode, $flags = array(), $context='direct' ) {
        global $event, $itemvarset;

        $itemvarset   = new CVarset();   // Global! - we need it shared in insert_fnc_* functions, TODO - pass it as parameter or whatever and do not use globals

        $slice_id     = $this->getSliceID();
        $slice        = AA_Slice::getModule($slice_id);
        $fields       = $slice->fields('record');
        $silent       = false;           // do not perform any additional operation (feed, invalidate, compute_fields, ... if not specified by flags)

        if ( !in_array($mode, array('insert', 'insert_new', 'insert_if_new', 'insert_as_new', 'overwrite', 'add', 'update_silent'))) {
            $mode = 'update';
        }

        switch ($mode) {
            case 'update_silent': $silent = true;
                                  $mode   ='update';
                                  $id     = $this->getItemID();
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
            case 'insert_if_new': if (!$this->getItemID()) {
                                      ItemContent::lastErr(ITEMCONTENT_ERROR_NO_ID, _m("No Id specified (%1 - %2)", array($this->getItemID(), $this->getValue('headline........'))));  // set error code
                                      if ($GLOBALS['errcheck']) huhl(ItemContent::lastErrMsg());
                                      return false;
                                  }
                                  if (itemIsDuplicate($this->getItemID())) {
                                      ItemContent::lastErr(ITEMCONTENT_ERROR_DUPLICATE, _m("Duplicated ID - skiped (%1 - %2)", array($this->getItemID(), $this->getValue('headline........'))));  // set error code
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

        $invalidatecache = isset($flags[0]) ? $flags[0] : !$silent;
        $feed            = isset($flags[1]) ? $flags[1] : !$silent;
        $throw_events    = isset($flags[2]) ? $flags[2] : !$silent;

        if (!($id AND is_array($fields))) {
            ItemContent::lastErr(ITEMCONTENT_ERROR_BAD_PARAM, _m("StoreItem for slice %1 - failed parameter check for id = '%2'", array($slice->name(),$id)));  // set error code
            if ($GLOBALS['errcheck']) huhl(ItemContent::lastErrMsg());
            return false;
        }

        // do not store item, if status_code==SC_NO_BIN
        if ((int)$this->getStatusCode() == SC_NO_BIN) {
            ItemContent::lastErr(ITEMCONTENT_ERROR_NO_ID, _m("No Status code"));
            return false;
        }

        // remove old content first (just in content table - item is updated)
        if (in_array($mode, array('update', 'overwrite', 'add'))) {
            $oldItemContent = new ItemContent($id);
            if ($throw_events) {
                $event->comes('ITEM_BEFORE_UPDATE', $slice_id, 'S', $this, $oldItemContent);
            }
        } else {
            if ($throw_events) {
                $event->comes('ITEM_BEFORE_INSERT', $slice_id, 'S', $this);
            }
        }

        switch ($mode) {
        case 'overwrite':
            DB_AA::delete('content', array(array('item_id', $id, 'l')));
            break;
        case 'update':
            // delete content of all fields, which are in new content array
            // (this means - all not redefined fields are unchanged)
            $this->_clean_updated_fields($id, $fields);
            break;
        case 'insert':
            // reset hit counter fields for new items
            foreach ((array)$fields as $fid => $foo) {
                if (substr($fid, 0, 4) == 'hit_' ) {
                    $this->setValue($fid, 0);
                }
            }
            break;
        }
        // else 'add' do not clear the current content - the values are added
        // in paralel to curent values (stored as multivalues for all fields
        // stored in content table)

        // and NOW - store the fields and prepare itemvarset
        $this->_store_fields($id, $fields, $context);

        // Alerts module uses moved2active as the time when an item was moved to the active bin
        if (($mode=='insert') OR (($itemvarset->get('status_code') != $oldItemContent->getStatusCode()) AND $itemvarset->get('status_code') >= 1)) {
            $itemvarset->add("moved2active", "number", $itemvarset->get('status_code') > 1 ? 0 : time());
        }

        /** update item table */
        // we can't redefine id or short_id for the field, so if it is set, unset it
        $itemvarset->remove("short_id");
        $itemvarset->addkey('id', 'unpacked', $id);
        $itemvarset->add("slice_id",  "unpacked", $slice_id);
        // update item table
        switch ($mode) {
            case 'update':
            case 'add':
                if (!$silent) {
                    $itemvarset->add("last_edit", "quoted", AA_Generator::factoryByString('now')->generate()->getValue());
                    $itemvarset->add("edited_by", "quoted", AA_Generator::factoryByString('uid')->generate()->getValue());
                }
                $itemvarset->doUpdate('item');
                break;
            case 'overwrite':
                if ($itemvarset->get('status_code') < 1) {
                    $itemvarset->set('status_code', 1);
                }
                $itemvarset->set('display_count', (int)$this->getValue('display_count...'));
                $itemvarset->add("last_edit", "quoted",   AA_Generator::factoryByString('now')->generate()->getValue());
                $itemvarset->add("edited_by", "quoted",   AA_Generator::factoryByString('uid')->generate()->getValue());
                $itemvarset->doReplace('item');
                break;
            case 'insert':
                // check, if all data in item table are correct
                if ($itemvarset->get('status_code') < 1) {
                    $itemvarset->set('status_code', 1);
                }
                if ($itemvarset->get('publish_date') < 1) {
                    $itemvarset->set('publish_date', now());
                }
                if ($itemvarset->get('expiry_date') < 1) {
                    $itemvarset->set('expiry_date', 1861916400);   // sometimes in 2029
                }
                $itemvarset->set('display_count', (int)$this->getValue('display_count...'));
                $itemvarset->add('post_date', "quoted", AA_Generator::factoryByString('now')->generate()->getValue());
                $itemvarset->add('posted_by', "quoted", AA_Generator::factoryByString('uid')->generate()->getValue());
                $itemvarset->doInsert('item');
        }
        if ($invalidatecache) {
            // invalidate old cached values
            $GLOBALS['pagecache']->invalidateFor("slice_id=$slice_id");
        }


        // get the content back from database
        $itemContent = new ItemContent();
        $itemContent->setByItemID($id,true);     // ignore reading password

        // look for computed fields and update it (based on the stored item)
        if (!$silent) {
            if ( $itemContent->updateComputedFields($id, $fields, $mode) ) {
                // if computed fields are updated, reread the content
                $itemContent->setByItemID($id,true); // ignore reading password
            }
        }

        // invalidate from inner cache
        AA_Items::invalidateItem(new zids($id, 'l'));

        if ($feed) {
            FeedItem($id);
        }

        if ($throw_events) {
            if ($mode == 'insert') {
                AA_Plannedtask::executeForEvent($slice_id, 'ITEM_NEW', $id);
                $event->comes('ITEM_NEW', $slice_id, 'S', $itemContent);  // new form event
            } else {
                $diff = $itemContent->diff($oldItemContent);
                if (count($diff)) {
                    $changes = AA_ChangesMonitor::singleton();
                    $changes->addHistory($diff);
                }
                AA_Plannedtask::executeForEvent($slice_id, 'ITEM_UPDATED', $id);
                $event->comes('ITEM_UPDATED', $slice_id, 'S', $itemContent, $oldItemContent); // new form event
            }
        }
        return $id;
    } // end of storeItem()

    /** updateComputedFields function
     * @param $id
     * @param $fields
     */
    function updateComputedFields($id, $fields=null, $mode='update', $restict_fields=null) {
        global $itemvarset; // set by insert_fnc_qte function

        $itemvarset   = new CVarset();
        $field_writer = new AA_Field_Writer;

        $update       = (($mode == 'update') OR ($mode == 'overwrite') OR ($mode == 'add'));
        $computed_field_exist = false;

        // could be called also from outside to recompute fields
        $slice        = AA_Slice::getModule($this->getSliceID());
        if (!$fields) {
            $fields   = $slice->fields('record');
        }

        foreach ($fields as $fid => $f) {

            if (is_array($restict_fields) AND count($restict_fields) AND !in_array($fid, $restict_fields)) {
                // we can restict the recomputed fields in {recompute}
                // so - skip not recomputed fields
                continue;
            }

            // input insert function parameters of field
            $fnc = ParseFnc($f["input_insert_func"]);

            if (!$fnc) {
                continue;
            }

            // computed field?
            switch ($fnc["fnc"]) {
                case 'seo':
                    $seo_alias     = strlen(trim($fnc["param"])) ? $fnc["param"] : '_#HEADLINE';
                    $slice_charset = $slice->getCharset();
                    $seo_charset   = ($slice_charset AND $slice_charset != 'utf-8') ? ":$slice_charset" : '';
                    $expand_string = '{ifset:{'.$fid.'}:_#1:{seoname:{'.$seo_alias.'}:all'.$seo_charset.'}}';
                    unset($expand_insert,$expand_update,$expand_delimiter, $recompute);
                    break;
                case 'com':
                    $expand_string = $fnc["param"];
                    unset($expand_insert,$expand_update,$expand_delimiter, $recompute);
                    break;
                case 'co2':
                    list($expand_insert,$expand_update,$expand_delimiter,$recompute) = ParamExplode($fnc["param"]);
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
            $aa_val = new AA_Value( strlen($expand_delimiter) ? array_filter(explode($expand_delimiter,$new_computed_value) ,'strlen') : $new_computed_value, $f['html_default']>0 ? FLAG_HTML : 0);

            // set this value also to $item in order we can count with it
            // in next computed field
            $item->setAaValue($fid, $aa_val);
            $values = $item->getValues($fid);

            //if ($GLOBALS['debug']) {
            //    huhl($id, $fid, $new_computed_value, $aa_val, $values, '-----');
            //}
            //AA_Log::write('DEBUG', "$id - $fid:".serialize($values), 'debug');

            // delete content just for this computed field
            // $this->_clean_updated_fields($id, $fields);
            if ($id AND $fid) {
                DB_AA::delete('content', array(array('item_id', $id, 'l'), array('field_id', $fid)));
            }

            foreach($values as $varr) {
                //  store the computed value for this field to database
                $field_writer->insert_fnc_qte($id, $f, $varr, '');
            }
        }

        if (!$itemvarset->isEmpty()) {
            $itemvarset->addkey('id', 'unpacked', $id);
            $itemvarset->doUpdate('item');
        }
        return $computed_field_exist;
    }

    /** storeSliceFields function
     *  Stores the fields into content table for dynamic "slice setting fields"
     * @param $slice_id
     * @param $fields
     */
    function storeSliceFields($slice_id, &$fields) {
        // delete content of all fields, which are in new content array
        // (this means - all not redefined fields are unchanged)
        $this->_clean_updated_fields($slice_id, $fields);

        // we use slice_id as item id here
        $this->_store_fields($slice_id, $fields);

        $GLOBALS['pagecache']->invalidateFor("slice_id=$slice_id");
    }

    /** unalias the text using content of this itemcontent and aliases of the slice */
    function unalias($text) {
        $item = GetItemFromContent($this);
        return is_null($item) ? '' : $item->unalias($text);
    }

    /** _clean_updated_fields function
     *  delete content of all fields, which are in new content array
     *  (this means - all not redefined fields are unchanged)
     * @param $id
     * @param $fields
     */
    function _clean_updated_fields($id, &$fields) {
        $in = array();
        foreach ($this->content as $fid => $fooo) {
            if (!$fields[$fid]['in_item_tbl']) {
                $in[] = $fid;
            }
        }
        if ($in AND $id) {
            // delete content just for displayed fields
            DB_AA::delete('content', array(array('item_id', $id, 'l'), array('field_id', $in)));
            // note extra images deleted in insert_fnc_fil if needed
        }
    }

    /** _store_fields function
     *  private function - goes through content and runs all insert functions
     *  on each field in content array. The content is stored in the database
     *  of in itemvarset
     * @param $id
     * @param $fields
     * @param $context
     */
    function _store_fields($id, &$fields, $context='direct') {
        $field_writer = new AA_Field_Writer;
        $parameters   = array();
        $thumbnails   = array();

        foreach ($this->content as $fid => $cont) {
            $f = $fields[$fid];

            // input insert function parameters of field
            $fnc = ParseFnc($f["input_insert_func"]);
            if ($fnc) {
                $fncname = 'insert_fnc_' . $fnc["fnc"];
                // update content table or fill $itemvarset
                if (!is_array($cont)) {
                    continue;
                }
                $set      = false;
                unset($parameters);
                unset($thumbnails);
                foreach ($cont as $numkey => $v) {

                    $numkey = (int)$numkey;
                    // file upload needs the $fields array, because it stores
                    // some other fields as thumbnails

                    if ($fnc["fnc"]=="fil") {
                        //Note $thumbnails is undefined the first time in this loop
                        if (is_array($thumbnails)) {
                            foreach ($thumbnails as $v_stop) {
                                if ($v_stop == $fid) {
                                    $stop = true;
                                }
                            }
                        }

                        if (!$stop) {
                            $parameters["order"]   = $numkey;
                            $parameters["fields"]  = $fields;
                            $parameters["context"] = $context;
                            $thumbnails = $field_writer->$fncname($id, $f, $v, $fnc["param"], $parameters);
                        }
                    } else {
                        $field_writer->$fncname($id, $f, $v, $fnc["param"], array('order'=>$numkey));
                    }
                    // do not store multiple values if field is not marked as multiple
                    // ERRORNOUS
                    //if( !$f["multiple"]!=1 )
                        //continue;
                }
            }
        }
        // if ($_POST['AA_CP_Session']=='6953b900572e78f86907c6484992a895') {
        //     huhl(AA_Item::getItem($id));
        //     exit;
        // }
    }

    /** transform function
     * Transform $itemContent according to the transformation actions $trans_actions and slice fields $slice_fields
     * @param $itemContent
     * @param $trans_actions
     * @param $slice_fields
     */
    function transform(&$itemContent, &$trans_actions, &$slice_fields) {
        return $trans_actions->transform($itemContent,$slice_fields,$this);
    }

    /** showAsRowInTable function
     *  Show the item in one row in a table according to the order specified
     *  by slice fields $slf
     * @param $slf
     * @param $tr_att
     */
    function showAsRowInTable(&$slf, $tr_att="") {
        echo "<tr ".$tr_att." >";
        foreach ( $slf as $k => $foo) {
            if (!($v = $this->content[$k])) {
                echo "<td></td>";
            } else {
                echo "<td>";
                unset($s);
                while (list (,$v2) = each ($v)) {
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
 *  Figure out if item alreaady imported into this slice
 * Id's are unpacked
 * Note that this could be replaced by feeding.php3:IsItemFed which is more complex and would use orig id
 * @param $item_id
 */
function itemIsDuplicate($item_id) {
    return DB_AA::test('item', array(array('id', $item_id, 'l')));
}

/** field_content is AA_Value object
 *  $field_content could be AA_Value or scallar or array()
 *  */
function UpdateField($item_id, $field_id, $field_content, $invalidate = true) {
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

    if ($newcontent4id->storeItem( 'update', array($invalidate, false, true))) {    // invalidatecache, not feed
        $updated_items = 1;
    }
    return $updated_items;
}

?>
