<?php
/**
 * Class ItemContent.
 *
 * @package UserInput
 * @version $Id$
 * @author Jakub Adamek, Econnect
 * @copyright (c) 2002-3 Association for Progressive Communications
*/
/*
Copyright (C) 1999-2003 Association for Progressive Communications
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

require_once $GLOBALS['AA_INC_PATH']."feeding.php3";


define('ITEMCONTENT_ERROR_BAD_PARAM', 200);
define('ITEMCONTENT_ERROR_DUPLICATE', 201);
define('ITEMCONTENT_ERROR_NO_ID',     202);

/**
 *  ItemContent class is an abstract data structure, used mostly for storing
 *  an item. The item can contain many fields, and each field contains 1..n
 *  valueincluding the value attribute (now attribute may be only html flag).
 */

/** Stores all info about an item. Uses both info from the <em>item</em> and
 *   <em>content</em> tables.
 *
 *   Gives convenient access to the things previously stored in the
 *   array $content4id.
 */
class ItemContent {
    var $classname = "ItemContent";

    // PUBLIC:

    // PRIVATE:
    var $content;

    /** Method returns or sets last itemContent error
     *  The trick for static class variables is used */
    function lastErr($err_id = null, $err_msg = null, $getmsg = false) {
        static $lastErr;
        static $lastErrMsg;
        if (!is_null($err_id)) {
            $lastErr    = $err_id;
            $lastErrMsg = $err_msg;
        }
        return $getmsg ? $lastErrMsg : $lastErr;
    }

    /** Return last error message - it is grabbed from static variable
     *  of lastErr() method */
    function lastErrMsg() {
        return ItemContent::lastErr(null, null, true);
    }

    /// Constructor which takes content for ID or item_id (unpacked).
    function ItemContent($content4id = "") {
        if ( is_array($content4id) ) {
            $this->setFromArray($content4id);
        } elseif ( $content4id ) {
            $this->setByItemID($content4id );
        }
    }

    function setFromArray(&$content4id) {
        $this->content = $content4id;
    }

    // Set by item ID (zid or unpacked or short)
    function setByItemID($item_id, $ignore_reading_password=false) {
        if (!$item_id) return false;
        $zid           = (strtolower(get_class($item_id))=='zids') ? $item_id : new zids($item_id);
        $content       = GetItemContent($zid, false, $ignore_reading_password);
        $this->content = is_array($content) ? reset($content) : null;
    }

    /** Functions tries to fill all the fields from the form. It do not add any
     *  item specific fields (like status_code), so it could be used also for
     *  dynamic "slice setting fields"
     */
    function setFieldsFromForm(&$slice, $oldcontent4id="", $insert=true, $slice_fields=false) {
        global $profile, $auth;

        list($fields, $prifields) = $slice->fields(null, $slice_fields);
        if (!isset($prifields) OR !is_array($prifields)) {
            return false;
        }

        if (!is_object($profile)) {
            $profile = new aaprofile($auth->auth["uid"], $slice->unpacked_id());  // current user settings
        }

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


    /** Fills content4id - values in content4id are NOT quoted (addslashes)
     *  (new version of previous GetContentFromForm() function)
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

    function is_empty() {
        return !is_array($this->content);
    }

    function is_set($field_id) {
        return is_array($this->content[$field_id]);
    }

    function getContent() {
        return $this->content;
    }

    /** Function quotes all content to use in database query */
    //  This is just transformation function - we do not say, that content is
    //  not already quoted - we will add $quoted flag to this class in order
    //  it will be transparent for ussage in near future
    function getContentQuoted() {
        return $this->_content_walk('quote');
    }

    /** Goes through all values of content and and returns transformed content.
     *  Transformation is given by callback function.
     */
    function _content_walk($callback) {     // private function
        if ( !isset( $this->content ) OR !is_array(  $this->content ) ) {
            return false;
        }
        foreach ( $this->content as $field => $val_array ) {
            foreach ( $val_array as $key => $val ) {
                $ret[$field][$key] = array( 'value' => $callback($val['value']),
                                            'flag'  => $val['flag']);
            }
        }
        return $ret;
    }

    /** Returns the value for a field. If it is a multi-value
    *   field, this is the first value. */
    function getValue($field_id, $what='value') {
        return ( is_array($this->content[$field_id]) ? $this->content[$field_id][0][$what] : false );
    }

    function getValues($field_id) {
        return ( is_array($this->content[$field_id]) ? $this->content[$field_id] : false );
    }

    /** Fills the name with dots to the standard 16 characters,
    *   returns the value for the field. You can use field names
    *   from the <i>item</i> table with this function. */
    function getItemValue($field_name) {
        return $this->getValue(substr($field_name."................",0,16));
    }

    function getQuotedValue($field_id) {
        return addslashes($this->getValue($field_id));
    }

    function getItemID()     { return unpack_id($this->getItemValue("id")); }
    function getSliceID()    { return unpack_id($this->getItemValue("slice_id")); }
    function getPSliceID()   { return addslashes($this->getItemValue("slice_id")); }
    function getStatusCode() { return $this->getItemValue("status_code"); }
    function getPublishDate(){ return $this->getItemValue("publish_date"); }
    function getExpiryDate() { return $this->getItemValue("expiry_date"); }

    function setValue($field_id,$val) {
        $this->content[$field_id][0]['value'] = $val;
    }

    function setItemValue($field_name, $value) {
        $this->content[substr($field_name."...................",0,16)] =
            array (0 => array ("value" => $value));
    }

    function setItemID($value)     { $this->setItemValue("id", pack_id($value)); }
    function setSliceID($value)    { $this->setItemValue("slice_id", pack_id($value)); }
    function setStatusCode($value) { $this->setItemValue("status_code", $value); }
    function setPublishDate($value){ $this->setItemValue("publish_date", $value); }
    function setExpiryDate($value) { $this->setItemValue("expiry_date", $value); }

    /*------------------------ */

    /** Special function - fills field by prepared array $v[]['value'] */
    function setFieldValue($field_id,$v) {
        $this->content[$field_id] = $v;
    }

    /** Set the content with CSV data */
    function setFromCSVArray(&$csvRec, &$fieldNames) {
        $i = 0;
        foreach ($fieldNames as $k => $foo) {
            $this->content[$k][0]['value'] = $csvRec[$i++];
        }
    }

    /** Store item content to DB. if an item has item_id, which is already
     *  stored in $items_id, then according to the $actionIfItemExists performs:
     *    a) "update"    : update the item in DB
     *    b) or "new_id" : store the item with different (unique random) id
     *    c) otherwise   : do nothing
     *  TODO - convert to grabber/saver API
     */
    function storeToDB($slice_id, $actionIfItemExists=STORE_WITH_NEW_ID, $invalidatecache = true) {
        require_once $GLOBALS['AA_INC_PATH']."varset.php3";
        require_once $GLOBALS['AA_INC_PATH']."itemfunc.php3";
        global $db, $err, $varset, $itemvarset, $error, $ok;

        $db         = new DB_AA;

        $id = $this->getItemValue("id");
        if ($id == "new id") {	    // if the item has no id => set up an unique new id
            $id = new_id();
            $insert = true;
        } else {
            // Check duplicity
            $p_id = q_pack_id($id);
            $SQL= "SELECT id FROM item WHERE item.id='$p_id'";
            $db->query($SQL);
            $insert = !$db->next_record();
        }
        if ($insert == false) {	    // if the item is already in the DB :
            switch ($actionIfItemExists) {
                case UPDATE:  	    // the item  will be updated
                    break;
                case STORE_WITH_NEW_ID:
                    $id = new_id();	// the item should be stored with a new id
                    $insert = true;
                    break;
                case NOT_STORE:
                default: 		    // NOT_STORE or any other value => do not store the item
                    return array(0=>NOT_STORE,1=>$id);
            }
        }

        $this->setItemID($id);
        $this->setSliceID($slice_id);
        $added_to_db = $this->storeItem( $insert ? 'insert' : 'update', $invalidatecache, false);     // invalidatecache, feed

        return $added_to_db ? array(0=> ($insert ? INSERT : UPDATE) ,1=>$id) : false;
    }

    /** Basic function for changing contents of items.
    *   Use always this function, not direct SQL queries.
    *   Updates the tables @c item and @c content.
    *   $GLOBALS[err][field_id] should be set on error in function
    *   It looks like it will return true even if inset_fnc_xxx fails
    *
    *   @param string $mode   how to deal with the stored item.
    *      update        - the fields defined in $this object are cleared and
    *                      then overwriten by values from $this object
    *                      - other fields of the item are untouched. The id
    *                      of the item must be set before calling this
    *                      function ($this->setItemID($id))
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
    *    @param bool   $invalidatecache   should we invalidate the cache for the
    *                                     slice?
    *    @param bool   $feed     procces feeding (as in in the slice setting)?
    *    @param string $context  special parameter used for thumbnails
    */
    function storeItem( $mode, $invalidatecache=true, $feed=true, $context='direct' ) {
        global $event, $itemvarset;
        $itemvarset = new CVarset();   // Global! - we need it shared in insert_fnc_* functions, TODO - pass it as parameter or whatever and do not use globals

        $slice_id   = $this->getSliceID();
        $slice      = new slice($slice_id);
        $fields     = $slice->fields('record');

        if ( ($mode != 'insert') AND
             ($mode != 'insert_new') AND
             ($mode != 'insert_if_new') AND
             ($mode != 'insert_as_new') AND
             ($mode != 'overwrite') AND
             ($mode != 'add')) {
            $mode = 'update';
        }

        switch ($mode) {
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
            $event->comes('ITEM_BEFORE_UPDATE', $slice_id, 'S', $this, $oldItemContent);
        } else {
            $event->comes('ITEM_BEFORE_INSERT', $slice_id, 'S', $this);
        }

        if ($mode == 'overwrite') {
            $varset     = new CVarset();
            $varset->doDeleteWhere('content', "item_id='". q_pack_id($id). "'");
        } elseif ($mode == 'update') {
            // delete content of all fields, which are in new content array
            // (this means - all not redefined fields are unchanged)
            $this->_clean_updated_fields($id, $fields);
        }
        // else 'add' do not clear the current content - the values are added
        // in paralel to curent values (stored as multivalues for all fields
        // stored in content table)

        // and NOW - store the fields and prepare itemvarset
        $this->_store_fields($id, $fields, $context);

        /* Alerts module uses moved2active as the time when
           an item was moved to the active bin */
        if (($mode=='insert') ||
            ($itemvarset->get('status_code') != $oldItemContent->getStatusCode()
                                        && $itemvarset->get('status_code') >= 1)) {
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
                $itemvarset->add("last_edit", "quoted",   default_fnc_now(""));
                $itemvarset->add("edited_by", "quoted",   default_fnc_uid(""));
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
            default:
                if ($itemvarset->get('status_code') < 1) {
                    $itemvarset->set('status_code', 1);
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
        if ( $itemContent->updateComputedFields($id, $fields) ) {
            // if computed fields are updated, reread the content
            $itemContent->setByItemID($id,true); // ignore reading password
        }

        if ($feed) {
            FeedItem($id, $fields);
        }

        if ($mode == 'insert') {
            $event->comes('ITEM_NEW', $slice_id, 'S', $itemContent);  // new form event
        } else {
            $event->comes('ITEM_UPDATED', $slice_id, 'S', $itemContent, $oldItemContent); // new form event
        }
        if ($debugsi) huhl("StoreItem err=",$err);
        return $id;
    } // end of storeItem()

    function updateComputedFields($id, &$fields) {
        $varset     = new CVarset();

        $computed_field_exist = false;
        foreach ($fields as $fid => $f) {
            // input insert function parameters of field
            $fnc = ParseFnc($f["input_insert_func"]);

            // computed field?
            if ($fnc AND ($fnc["fnc"]=='com') AND (strlen($fnc["param"])>0)) {

                // the code, which (unaliased!) should be stored in the field
                // is in parameter
                if ($computed_field_exist === false) {
                    $computed_field_exist = true;
                    // prepare item for computing
                    $slice = new slice($this->getSliceID());
                    $item  = new item($this->getContent(),$slice->aliases());
                }
                // delete content just for this computed field
                $varset->doDeleteWhere('content', "item_id='". q_pack_id($id). "' AND field_id='$fid'");
                // now store the comuted value for this field
                insert_fnc_qte($id, $f, array('value' => $item->unalias($fnc["param"])), '');
            }
        }
        return $computed_field_exist;
    }

    /** Stores the fields into content table for dynamic "slice setting fields"
     */
    function storeSliceFields($slice_id, &$fields) {
        // delete content of all fields, which are in new content array
        // (this means - all not redefined fields are unchanged)
        $this->_clean_updated_fields($slice_id, $fields);

        // we use slice_id as item id here
        $this->_store_fields($slice_id, $fields);
    }

    /** delete content of all fields, which are in new content array
     *  (this means - all not redefined fields are unchanged)
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

    /** private function - goes through content and runs all insert functions
     *  on each field in content array. The content is stored in the database
     *  of in itemvarset
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
                unset($parameters);
                foreach ( $cont as $v) {
                    // file upload needs the $fields array, because it stores
                    // some other fields as thumbnails
                    if ($fnc["fnc"]=="fil") {
                        if ($debugsi >= 5) huhl("StoreItem: fil");
                        if ($debugsi >= 5) { $GLOBALS[debug] = 1; $GLOBALS[debugupload] = 1; }
                        //Note $thumbnails is undefined the first time in this loop
                        if (is_array($thumbnails)) {
                            foreach ($thumbnails as $v_stop) {
                                if ($v_stop==$fid) {
                                    $stop=true;
                                }
                            }
                        }

                        if (!$stop) {
                            if ($debugsi >= 5) huhl($fncname,"(",$id,$f,$v,$fnc["param"],")");
                            if ($numbered) {
                                $parameters["order"] = $order;
                            }
                            $parameters["fields"]    = $fields;
                            $parameters["context"]   = $context;
                            $thumbnails = $fncname($id, $f, $v, $fnc["param"], $parameters);
                        }
                    } else {
                        if ($debugsi >= 5) huhl($fncname,"(",$id,$f,$v,$fnc["param"],")");
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


    /**
    Exports item content to CSV. TODO
    */
    function export2CSV() {}

    /**
    Transform $itemContent according to the transformation actions $trans_actions and slice fields $slice_fields
    */
    function transform(&$itemContent, &$trans_actions, &$slice_fields) {
        return $trans_actions->transform($itemContent,$slice_fields,$this);
    }

    /** Show the item in one row in a table according to the order specified
     *  by slice fields $slf  */
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

// Figure out if item alreaady imported into this slice
// Id's are unpacked
// Note that this could be replaced by feeding.php3:IsItemFed which is more complex and would use orig id
function itemIsDuplicate($item_id) {
    $db = getDB();
    $SQL="SELECT * FROM item WHERE id='".q_pack_id($item_id)."'" ;
    $db->query($SQL);
    $ret = $db->next_record();
    freeDB($db);
    return $ret ? true : false;
}

