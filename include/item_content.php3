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

    /// Constructor which takes content for ID or item_id (unpacked).
    function ItemContent ($content4id = "") {
        if ( is_array($content4id) ) {
            $this->setFromArray ($content4id);
        } elseif ( $content4id ) {
            $this->setByItemID ($content4id );
        }
    }

    function setFromArray (&$content4id) {
        $this->content = $content4id;
    }

    // Set by item ID (zid or unpacked or short)
    function setByItemID ($item_id, $ignore_reading_password = false) {
        if ( !$item_id ) return false;
        $zid           = ((get_class($item_id)=='zids') ? $item_id : new zids($item_id));
        $content       = GetItemContent($zid);
        $this->content = is_array($content) ? reset($content) : null;
    }

    function is_empty() {
        return !is_array($this->content);
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
    function getValue ($field_id) {
        return $this->content[$field_id][0]["value"];
    }

    function getValues ($field_id) {
        return $this->content[$field_id];
    }

    /** Fills the name with dots to the standard 16 characters,
    *   returns the value for the field. You can use field names
    *   from the <i>item</i> table with this function. */
    function getItemValue ($field_name) {
        return $this->getValue (substr($field_name."................",0,16));
    }

    function getQuotedValue ($field_id) {
        return addslashes ($this->getValue ($field_id));
    }

    function getItemID()     { return unpack_id($this->getItemValue ("id")); }
    function getSliceID()    { return unpack_id($this->getItemValue ("slice_id")); }
    function getPSliceID()   { return addslashes ($this->getItemValue ("slice_id")); }
    function getStatusCode() { return $this->getItemValue ("status_code"); }
    function getPublishDate(){ return $this->getItemValue ("publish_date"); }
    function getExpiryDate() { return $this->getItemValue ("expiry_date"); }

    function setItemValue ($field_name, $value) {
        $this->content[substr($field_name."...................",0,16)] =
            array (0 => array ("value" => $value));
    }

    function setItemID($value)     { $this->setItemValue ("id", pack_id ($value)); }
    function setSliceID($value)    { $this->setItemValue ("slice_id", pack_id ($value)); }
    function setStatusCode($value) { $this->setItemValue ("status_code", $value); }
    function setPublishDate($value){ $this->setItemValue ("publish_date", $value); }
    function setExpiryDate($value) { $this->setItemValue ("expiry_date", $value); }

    /*------------------------ */
    function setFieldValue($field_id,$v) {
        $this->content[$field_id] = $v;
    }

    /** Set the content with CSV data */
    function setFromCSVArray(&$csvRec, &$fieldNames) {
        $i = 0;
        reset($fieldNames);
        while (list ($k, ) = each($fieldNames)) {
        $this->content[$k][0][value] = $csvRec[$i];
        $i++;
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

    /** Show the item in one row in a table according to the order specified by slice fields $slf
    */
    function showAsRowInTable(&$slf,$tr_att="") {

      echo "<tr ".$tr_att." >";
      reset($slf);
      while (list($k,) = each($slf)) {
          if (!($v = $this->content[$k]))
            echo "<td></td>";
          else {

            echo "<td>";
            unset($s);
            while (list (,$v2) = each ($v)) {
                $v2[value] = stripslashes($v2[value]);
            $s[] = $v2[html] ? $v2[value] : htmlspecialchars($v2[value]);
            }
            if (count($s) == 1) {
                echo $s[0];
            }
            else {
            echo "[ ". implode(", ",$s) . " ]";
            }
            echo "</td>";
          }
        }
        echo "</tr>";
    }

    /** Store item content to DB. if an item has item_id, which is already stored in $items_id, then
        according to the $actionIfItemExists performs:
      a) "update" : update the item in DB
      b) or "new_id" : store the item with different (unique random) id
      c) otherwise : do nothing
    */
    function storeToDB($slice_id,&$fields, $actionIfItemExists=STORE_WITH_NEW_ID) {

      require_once $GLOBALS["AA_INC_PATH"]."varset.php3";
      require_once $GLOBALS["AA_INC_PATH"]."itemfunc.php3";

      global $db, $err, $varset, $itemvarset, $error, $ok;
      $varset = new Cvarset();
      $itemvarset = new Cvarset();
      $db = new DB_AA;

      $id = $this->getItemValue ("id");
      if ($id == "new id") {		// if the item has no id => set up an unique new id
        $id = new_id();
        $insert = true;
      }
      else {
        // Check duplicity
        $p_id = q_pack_id($id);
        $SQL= "SELECT id FROM item WHERE item.id='$p_id'";
        $insert = $db->query($SQL) ? false: true;
      }
      if ($insert == false) {	// if the item is already in the DB :

          switch ($actionIfItemExists) {
              case UPDATE : { 		// the item  will be updated
                  break;
              }
              case STORE_WITH_NEW_ID : {
                $id = new_id();	 	// the item should be stored with a new id
                $insert = true;
                break;
              }
              case NOT_STORE:
                default: 		// NOT_STORE or any other value => do not store the item
                return array(0=>NOT_STORE,1=>$id);
          }
      }

      $added_to_db=StoreItem( $id, $slice_id, $this->content, $fields, $insert, true, false ); // invalidatecache, not feed
      return $added_to_db ? array(0=> ($insert ? INSERT : UPDATE) ,1=>$id) : false;
    }
}
