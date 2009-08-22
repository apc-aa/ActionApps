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
require_once AA_INC_PATH."feeding.php3";

/**
 * AA_Value - Holds information about one value - could be multiple,
 *            could contain flags...
 *          - the values are always plain (= no quoting, no htmlspecialchars...)
 */
class AA_Value {
    /** array of the values */
    var $val;

    /** holds the flag - common for all the values */
    var $flag;

    /** AA_Value function
     * @param $value
     * @param $flag
     */
    function AA_Value($value=null, $flag=null) {
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

    /** getValue function
     *  Returns the value for a field. If it is a multi-value
     *   field, this is the first value.
     * @param $i
     */
    function getValue($i=0) {
        return $this->val[$i];
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
            foreach($value as $val) {
                $this->val[] = is_array($val) ? $val['value'] : (!is_object($val) ? $val : serialize($val));
                // @todo check, if $val->getSomething is callable
            }
        } elseif ( !is_null($value) ) {
            $this->val[] = $value;
        }
        return $this;
    }

    /** Set the flag (for al the values the flag is common at this time) */
    function setFlag($flag) {
        $this->flag = $flag;
    }

    /** Returns number of values */
    function valuesCount() {
        return count($this->val);
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

    /** Remove duplicate values from the array */
    function removeDuplicates() {
        $this->val = array_values( array_unique($this->val) );
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
         foreach ($this->val as $v) {
             $ret[] = array('value'=>$v, 'flag'=>$this->flag);
         }
         return $ret;
     }
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

    /** getAaValue function
     *  Returns the AA_Value object for a field
     * @param $field_id
     */
    function getAaValue($field_id) {
        return new AA_Value( $this->content[$field_id] );
    }

    /** getValue function
     *  Returns the value for a field. If it is a multi-value
     *   field, this is the first value.
     * @param $field_id
     * @param $what
     */
    function getValue($field_id, $idx=0) {
        return ( is_array($this->content[$field_id]) ? $this->content[$field_id][$idx]['value'] : false );
    }

    function getFlag($field_id) {
        return ( is_array($this->content[$field_id]) ? $this->content[$field_id][0]['flag'] : false );
    }

    /** getValues function
     * @param $field_id
     */
    function getValues($field_id) {
        return ( is_array($this->content[$field_id]) ? $this->content[$field_id] : false );
    }

    /** getValuesArray function
     * @param $field_id
     */
    function getValuesArray($field_id) {
        $ret = array();
        if ( !empty($this->content[$field_id]) ) {
            foreach ($this->content[$field_id] as $val) {
                $ret[] = $val['value'];
            }
        }
        return $ret;
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
        if ( is_array($content4id) ) {
            $this->setFromArray($content4id);
        } elseif ( $content4id ) {
            $this->setByItemID($content4id );
        }
    }

    /** isField function
     *  Returns true, if the passed field id looks like field id
     *  @param $field_id
     *  @todo - look directly into module, if the field is really field
     *          in specific slice/module
     */
    function isField($field_id) {
        if ( !isset($GLOBALS['LINKS_FIELDS']) ) {
             $GLOBALS['LINKS_FIELDS'] = GetLinkFields();
             $GLOBALS['CATEGORY_FIELDS'] = GetCategoryFields();
             $GLOBALS['CONSTANT_FIELDS'] = GetConstantFields();
        }
        // changed this from [a-z_]+\.+[0-9]*$ because of alerts[12]....abcde
        return( ((strlen($field_id)==16) AND preg_match('/^[a-z0-9_]+\.+[0-9A-Za-z]*$/',$field_id))
               OR $GLOBALS['LINKS_FIELDS'][$field_id]
               OR $GLOBALS['CATEGORY_FIELDS'][$field_id]
               OR $GLOBALS['CONSTANT_FIELDS'][$field_id] );
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
        $zid           = (strtolower(get_class($item_id))=='zids') ? $item_id : new zids($item_id);
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
    function setFieldsFromForm(&$slice, $oldcontent4id="", $insert=true, $slice_fields=false) {
        global $auth;

        list($fields, $prifields) = $slice->fields(null, $slice_fields);
        if (!isset($prifields) OR !is_array($prifields)) {
            return false;
        }

        $profile   = AA_Profile::getProfile($auth->auth["uid"], $slice->unpacked_id()); // current user settings

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
                $GLOBALS[$varname]     = addslashes($x[0]);
                $GLOBALS[$htmlvarname] = $x[1];
            }

            $var = $GLOBALS[$varname];
            if (!is_array($var)) {
                $var = array(0 => $var);
            }

            // fill the multivalues
            foreach ($var as $v) {
                $flag = $f["html_show"] ? ($GLOBALS[$htmlvarname]=="h" ? FLAG_HTML : 0)
                                        : ($f["html_default"] > 0      ? FLAG_HTML : 0);
                // content uses NOT quoted values => stripslashes
                $this->content[$pri_field_id][]   = array('value'=> stripslashes($v), 'flag'=>$flag);
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
    function setFromForm( &$slice, $oldcontent4id="", $insert=true) {
        global $id;

        if (!$this->setFieldsFromForm($slice, $oldcontent4id, $insert)) {
            return false;
        }

        // the status_code must be set in order we can use email_notify()
        // in StoreItem() function.
        if (!$insert AND !$this->getStatusCode()) {
            $this->setStatusCode(max(1,$oldcontent4id['status_code.....'][0]['value']));
        }

        if (!$insert) {
            $this->setValue('flags...........', $oldcontent4id["flags..........."][0]['value']);
        }

        // id of an item (for update)
        if (!$this->getItemID()) {
            $this->setItemID($id);    // grabbed from globals (sent by form (for update))
        }                             // it is posted as 'id' and not as standard 'v'.unpack_id('id..............')
                                      // from historical reasons. We probably change it in next versions - TODO
        $this->setSliceID($slice->unpacked_id());
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

    /** getItemValue function
     *  Fills the name with dots to the standard 16 characters,
     *   returns the value for the field. You can use field names
     *   from the <i>item</i> table with this function.
     * @param $field_name
     */
    function getItemValue($field_name) {
        return $this->getValue(substr($field_name."................",0,16));
    }

    /** getItemID function
     */
    function getItemID() {
        return unpack_id($this->getItemValue("id"));
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
        return unpack_id($this->getItemValue("slice_id"));
    }

    /** getStatusCode function
     */
    function getStatusCode() {
        return $this->getItemValue("status_code");
    }

    /** getPublishDate function
     */
    function getPublishDate() {
        return $this->getItemValue("publish_date");
    }


    /** getExpiryDate function
     */
    function getExpiryDate() {
        return $this->getItemValue("expiry_date");
    }

    /** setValue function
     * @param $field_id
     * @param $val
     */
    function setValue($field_id,$val) {
        $this->content[$field_id][0]['value'] = $val;
    }

    /** setItemValue function
     * @param $field_name
     * @param $value
     */
    function setItemValue($field_name, $value) {
        $this->content[substr($field_name."...................",0,16)] =
            array (0 => array ("value" => $value));
    }

    /** setItemID function
     * @param $value
     */
    function setItemID($value) {
        $this->setItemValue("id", pack_id($value));
    }

    /** setSliceID function
     * @param $value
     */
    function setSliceID($value) {
        $this->setItemValue("slice_id", pack_id($value));
    }

    /** setStatusCode function
     * @param $value
     */
    function setStatusCode($value) {
        $this->setItemValue("status_code", $value);
    }

    /** setPublishDate function
     * @param $value
     */
    function setPublishDate($value) {
        $this->setItemValue("publish_date", $value);
    }

    /** setExpiryDate function
     * @param $value
     */
    function setExpiryDate($value) {
        $this->setItemValue("expiry_date", $value);
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
        global $db, $err, $varset, $itemvarset, $error, $ok;

        $db         = new DB_AA;

        $id = $this->getItemValue("id");
        if ($id == "new id") {	    // if the item has no id => set up an unique new id
            $id = new_id();
            $insert = true;
        } else {
            // Check duplicity
            $p_id   = q_pack_id($id);
            $SQL    = "SELECT id FROM item WHERE item.id='$p_id'";
            $db->query($SQL);
            $insert = !$db->next_record();
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

        return $added_to_db ? array(0=> ($insert ? INSERT : UPDATE) ,1=>$id) : false;
    }

    function validate() {
        $slice_id = $this->getSliceID();
        if (!$slice_id) {
            ItemContent::lastErr(ITEMCONTENT_ERROR_NO_SLICE_ID, _m("No Slice Id specified"));  // set error code
            return false;
        }
        $slice     = AA_Slices::getSlice($this->getSliceID());

        $fields    = $slice->getFields();
        $field_ids = $fields->getPriorityArray();
        foreach ($field_ids as $field_id) {
            $field = $fields->getField($field_id);

        }

        //  @todo \


        foreach ($prifields as $pri_field_id) {
            $f = $fields[$pri_field_id];
            //  'status_code.....' is not in condition - could be set from defaults
            if (($pri_field_id=='edited_by.......') || ($pri_field_id=='posted_by.......')) {
                continue;   // filed by AA - it could not be filled here
            }
            $varname = 'v'. unpack_id($pri_field_id);  // "v" prefix - database field var
            $htmlvarname = $varname."html";

            global $$varname, $$htmlvarname;

            $setdefault = $action == "add"
                    || !$f["input_show"]
                    || $profile->getProperty('hide',$pri_field_id)
                    || ($action == "insert" && $notshown [$varname]);

            list($validate) = explode(":", $f["input_validate"], 2);

            if ($setdefault) {
                // modify the value to be compatible with $_GET[] array - we use
                // slashed variables (this will be changed in future) - TODO
                $$varname     = addslashes(GetDefault($f));
                $$htmlvarname = GetDefaultHTML($f);
            } elseif ($validate=='date') {
                // we do not know at this moment, if we have to use default
                $default_val  = addslashes(GetDefault($f));
            }

            $editable = IsEditable($oldcontent4id[$pri_field_id], $f, $profile) && !$notshown[$varname];

            // Run the "validation" which changes field values
            if ($editable && ($action == "insert" || $action == "update")) {
                switch( $validate ) {
                    case 'date':
                        $foo_datectrl_name = new datectrl($varname);
                        $foo_datectrl_name->update();           // updates datectrl
                        if ($$varname != "") {                  // loaded from defaults
                            $foo_datectrl_name->setdate_int($$varname);
                        }
                        $foo_datectrl_name->ValidateDate($f["name"], $err, $f["required"], $default_val);
                        $$varname = $foo_datectrl_name->get_date();  // write to var
                        break;
                    case 'bool':
                        $$varname = ($$varname ? 1 : 0);
                        break;
                    case 'pwd':
                        // store the original password to use it in
                        // insert_fnc_pwd when it is not changed
                        if ($action == "update"){
                            $GLOBALS[$varname."c"] = $oldcontent4id[$pri_field_id][0]['value'];
                        }
                        break;
                }
            }

            // Run the validation which really only validates
            if ($do_validate && ($action == "insert" || $action == "update")) {
                // special setting for file upload - there we solve the problem
                // of required fileupload field, but which is empty at this moment
                if ( $f["required"] AND (substr($f["input_show_func"], 0,3) === 'fil')) {
                    ValidateInput($varname, $f["name"], $$varname. $GLOBALS[$varname.'x'] , $err, // status code is never required
                        $f["required"] ? 1 : 0, $f["input_validate"]);
                    continue;
                }

                switch( $validate ) {
                    case 'text':
                    case 'url':
                    case 'email':
                    case 'number':
                    case 'id':
                        ValidateInput($varname, $f["name"], $$varname, $err, // status code is never required
                            ($f["required"] AND ($pri_field_id!='status_code.....')) ? 1 : 0, $f["input_validate"]);
                        break;
                    // necessary for 'unique' validation: do not validate if
                    // the value did not change (otherwise would the value always
                    // be found)
                    case 'e-unique':
                    case 'unique':
                        if (addslashes ($oldcontent4id[$pri_field_id][0]['value']) != $$varname)
                            ValidateInput($varname, $f["name"], $$varname, $err,
                                      $f["required"] ? 1 : 0, $f["input_validate"]);
                        break;
                    case 'user':
                        // this is under development.... setu, 2002-0301
                        // value can be modified by $$varname = "new value";
                        $$varname = usr_validate($varname, $f["name"], $$varname, $err, $f, $fields);
                        break;
                }
            }
        }
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

        $itemvarset = new CVarset();   // Global! - we need it shared in insert_fnc_* functions, TODO - pass it as parameter or whatever and do not use globals

        $slice_id   = $this->getSliceID();
        $slice      = AA_Slices::getSlice($slice_id);
        $fields     = $slice->fields('record');
        $silent     = false;           // do not perform any additional operation (feed, invalidate, compute_fields, ... if not specified by flags)

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

        // do not store item, if status_code==4
        if ((int)$this->getStatusCode() == 4) {
            return false;
        }

        // remove old content first (just in content table - item is updated)
        if (($mode == 'update') OR ($mode == 'overwrite') OR ($mode == 'add')) {
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
            $varset     = new CVarset();
            $varset->doDeleteWhere('content', "item_id='". q_pack_id($id). "'");
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
                    $itemvarset->add("last_edit", "quoted", default_fnc_now(""));
                    $itemvarset->add("edited_by", "quoted", default_fnc_uid(""));
                }
                $itemvarset->doUpdate('item');
                break;
            case 'overwrite':
                if ($itemvarset->get('status_code') < 1) {
                    $itemvarset->set('status_code', 1);
                }
                $itemvarset->set('display_count', (int)$this->getValue('display_count...'));
                $itemvarset->add("last_edit", "quoted",   default_fnc_now(""));
                $itemvarset->add("edited_by", "quoted",   default_fnc_uid(""));
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
                $itemvarset->add('post_date', "quoted", default_fnc_now(""));
                $itemvarset->add('posted_by', "quoted", default_fnc_uid(""));
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

        if ($feed) {
            FeedItem($id);
        }

        if ($throw_events) {
            if ($mode == 'insert') {
                $event->comes('ITEM_NEW', $slice_id, 'S', $itemContent);  // new form event
            } else {
                $event->comes('ITEM_UPDATED', $slice_id, 'S', $itemContent, $oldItemContent); // new form event
            }
        }
        return $id;
    } // end of storeItem()

    /** updateComputedFields function
     * @param $id
     * @param $fields
     */
    function updateComputedFields($id, &$fields, $mode) {
        global $itemvarset; // set by insert_fnc_qte function

        $varset     = new CVarset();
        $itemvarset = new CVarset();

        $update     = (($mode == 'update') OR ($mode == 'overwrite') OR ($mode == 'add'));
        $computed_field_exist = false;
        foreach ($fields as $fid => $f) {
            // input insert function parameters of field
            $fnc = ParseFnc($f["input_insert_func"]);

            if (!$fnc) {
                continue;
            }

            // computed field?
            if ($fnc["fnc"]=='com') {
                $expand_string = $fnc["param"];
            }
            elseif ($fnc["fnc"]=='co2') {
                list($expand_insert,$expand_update) = ParamExplode($fnc["param"]);
                $expand_string = $update ? $expand_update : $expand_insert;
            } else {
                continue;
            }
            if (strlen($expand_string)<=0) {
                continue;
            }
            // the code, which (unaliased!) should be stored in the field
            // is in parameter
            if ($computed_field_exist === false) {
                $computed_field_exist = true;
                // prepare item for computing
                $slice = AA_Slices::getSlice($this->getSliceID());
                $item  = new AA_Item($this->getContent(),$slice->aliases());
            }

            // delete content just for this computed field
            $varset->doDeleteWhere('content', "item_id='". q_pack_id($id). "' AND field_id='$fid'");

            // compute new value for this computed field
            $new_computed_value = $item->unalias($expand_string);

            // set this value also to $item in order we can count with it
            // in next computed field
            $item->set_field_value($fid, $new_computed_value);
            //  store the computed value for this field to database
            insert_fnc_qte($id, $f, array('value' => $new_computed_value), '');
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
        $varset     = new CVarset();
        $delim = $in = "";
        foreach ($this->content as $fid => $fooo) {
            if (!$fields[$fid]['in_item_tbl']) {
                $in .= $delim."'". addslashes($fid) ."'";
                $delim = ",";
            }
        }
        if ($in) {
            // delete content just for displayed fields
            $varset->doDeleteWhere('content', "item_id='". q_pack_id($id). "' AND field_id IN ($in)");
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
                // serve multiple values for one field
                $order    = 0;
                $numbered = (count($cont) > 1);
                $set      = false;
                unset($parameters);
                unset($thumbnails);
                foreach ($cont as $v) {
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
                            if ($numbered) {
                                $parameters["order"] = $order;
                            }
                            $parameters["fields"]    = $fields;
                            $parameters["context"]   = $context;
                            $thumbnails = $fncname($id, $f, $v, $fnc["param"], $parameters);
                        }
                    } else {
                        if ($numbered) {
                            $parameters["order"] = $order;
                            $fncname($id, $f, $v, $fnc["param"], $parameters);
                        } else {
                            $fncname($id, $f, $v, $fnc["param"]);
                        }
                    }
                    // do not store multiple values if field is not marked as multiple
                    // ERRORNOUS
                    //if( !$f["multiple"]!=1 )
                        //continue;
                    $order++;
                }
            }
        }
    }


    /** export2CSV function
     * Exports item content to CSV. TODO
     */
    function export2CSV() {}

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
                    $s[] = $v2['html'] ? $v2['value'] : htmlspecialchars($v2['value']);
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
    $db = getDB();
    $SQL="SELECT * FROM item WHERE id='".q_pack_id($item_id)."'" ;
    $db->query($SQL);
    $ret = $db->next_record();
    freeDB($db);
    return $ret ? true : false;
}

?>
