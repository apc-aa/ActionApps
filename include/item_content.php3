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
    
    function ItemContent (&$content4id) {
        $this->setFromArray ($content4id);
    }
    
    function setFromArray (&$content4id) {
        $this->content = $content4id;
    }
    
    function setByItemID ($item_id) {
        $this->content = GetItemContent ($item_id);
        $this->content = $this->content [$item_id];
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
}
