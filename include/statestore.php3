<?php
/**
 * File contains definition of storable_class class - abstract class which 
 * implements two methods for storing and restoring class data (used in 
 * searchbar class, manager class, ...
 *
 * Should be included to other scripts (as /include/searchbar.class.php3)
 *
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications 
*/
/* 
Copyright (C) 1999, 2000 Association for Progressive Communications 
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

if (!defined("INCLUDE_STORABLE_CLASS_INCLUDED"))
     define ("INCLUDE_STORABLE_CLASS_INCLUDED",1);
else return;

/** 
 * storable_class - abstract class which implements methods for storing and
 * restoring class data (used in searchbar class, manager class, ...). 
 *
 * If you want to use strable methods in your class, you should derive the new 
 * class from storable_class. Then you should define $persistent_slots array,
 * where you specify all the variables you want to store. Then you just call
 * getState() and setFromState() methods for storing and restoring object's data
 */
class storable_class { 
    /** 
     * Restores the object's data from $state
     * @param  array $state state array which stores object's data. The array
     *                      you will get by getState() method.      
     */
    function setFromState(&$state) {
        if ( !isset($this->persistent_slots) OR !is_array($this->persistent_slots) )
            return false;
        reset($this->persistent_slots);
        while ( list( ,$v ) = each($this->persistent_slots) ) {
            if( is_object( $this->$v ) )
                $this->$v->setFromState($state[$v]);
            else  
                $this->$v = $state[$v];
        }
    }

    /** 
     * Returns state array of the object - stores object's data for leter 
     * restoring (by setFromState() method)
     */
    function getState() {
        if ( !isset($this->persistent_slots) OR !is_array($this->persistent_slots) )
            return false;
        reset($this->persistent_slots);
        while ( list( ,$v ) = each($this->persistent_slots) ) 
            $ret[$v] = ( is_object( $this->$v ) ? $this->$v->getState() : $this->$v);
        return $ret;
    }
}    
?>
