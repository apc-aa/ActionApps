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

/*
  ItemContent class is an abstract data structure, used mostly for storing an item. The item can contain many  fields, and
  each field contains 1..n value including the value attribute (now attribute may be only html flag).
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

    // Set by unpacked item ID
    function setByItemID ($item_id, $ignore_reading_password = false) {
        $this->content = GetItemContent ($item_id, false, $ignore_reading_password);
        $this->content = $this->content [$item_id];
    }

    function getContent() {
        return $this->content;
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
    function setFieldValues($field_id,$v) {
	    $this->content[$field_id] = $v;
    }

    /** Sets the content with CSV data */
    function importCSVData($csvRec, $fieldNames) {
	    $i = 0;
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
    Transforms $itemContent according to the $trans_actions.
    */
    function transform(&$itemContent, $trans_actions, $slice_fields) {
	    while (list ($field_id, ) = each($slice_fields)) {
		    $trans_actions->transform($itemContent,$field_id,$this->content[$field_id]);
	    }
    }

    /** Shows item in one row in a table
    */
    function showAsRowInTable($tr_att="") {
	  echo "<tr ".$tr_att." >";
	  while (list($k,$v) = each($this->content)) {
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
	    echo "</tr>";
    }

    /** Store item content to DB. if an item has item_id, which is already stored in $items_id, then
        according to the $actionIfItemExists performs:
	  a) "update" : update the item in DB
	  b) or "new_id" : store the item with different (unique random) id
	  c) otherwise : do nothing
    */
    function storeToDB($slice_id,$fields, $items_id ="", $actionIfItemExists="update") {

	  require_once $GLOBALS["AA_INC_PATH"]."varset.php3";
	  require_once $GLOBALS["AA_INC_PATH"]."itemfunc.php3";

	  global $db, $err, $varset, $itemvarset, $error, $ok;
	  $varset = new Cvarset();
	  $itemvarset = new Cvarset();
	  $db = new DB_AA;

	  $insert = true;
	  $id = new_id();		// we suppose, that item is not already stored in the DB

	  if (isset($items_id)) {
		  if ($items_id[$this->getItemID()]) {
			  // item is already stored
			  switch ($actionIfItemExists) {
				  case "update" : { // item  should be updated
					  $insert = false;
					  $id = $items_id[$this->getItemID()];
					  break;
				  }
				  case "new_id" :
				  	break; // item should be stored with new id
				  default:
				  	return;	// do nothing
			  }
		  }
	  }

	  $added_to_db=StoreItem( $id, $slice_id, $this->content, $fields, $insert, true, false ); // invalidatecache, feed
	  return $added_to_db;
    }
}
