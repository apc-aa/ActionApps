<?php
/**
 * File contains definition of profile class - used for custom user settings
 *
 * Should be included to other scripts (as /include/init_page.php3)
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

/** aaprofile class - used for storing specific custom settings of loged user
 *  in one specific slice
 */
class aaprofile {
    var $classname = "aaprofile";   // sometimes it is required class property

    var $module_id;      // for which slice/module we hold the profile?
    var $user_id;        // for which user we hold the profile?
    var $properties;     // user's profile/settings
    var $err;            // array of error messages from methods

    /** constructor - do nearly nothing (lazy evaluation used)
     *  @param $module_id - for which slice/module we hold the profile?
     *  @param $user_id   - for which user we hold the profile?
     */
    function aaprofile($user_id, $module_id) {
        $this->user_id   = $user_id;
        $this->module_id = $module_id;
    }

    /** Loads profile for current user and current slice/module from database
     *  User's custom setting is combined with slice defaults (user '*' in
     *  database. Users own settings is stronger, so it redefines the default
     *  ones if the same propery and property_selector is used
     *  @param bool $force - if true, reread the profile from database even if
     *                       profile is already loaded
     */
    function loadprofile( $force=false ) {
        if ( $force ) unset( $this->properties );  // refresh from database
        if ( !$this->module_id OR (isset($this->properties) AND is_array($this->properties)) ) {
            return;
        }
        $db = getDB();
        $this->properties = array();

        // default setting for the slice is stored as user with uid = *
        $SQL= " SELECT * FROM profile
                WHERE slice_id='". q_pack_id($this->module_id) ."'
                AND (uid='". $this->user_id ."' OR uid='*')";
        $db->tquery($SQL);
        while ( $db->next_record() ) {
            if ( $db->f('uid') == '*' ) {
                $general_profile[] = $db->Record;  // store for later use
            } else {
                $this->set($db->f('property'), $db->f('selector'), $db->f('value'), 'u', $db->f('id'), $db->f('uid'));
            }
        }

        // now add properties from default profile, if it is not already set
        if( $general_profile ) {
            foreach( $general_profile as $v ) {
                if( !$this->getProperty($v['property'],$v['selector'],true) ) {
                    $this->set( $v['property'], $v['selector'], $v['value'], '*', $v['id'], $v['uid'] );
                }
            }
        }
        freeDB($db);
    }

    /** Stores property
     *  @param type - exactly one of 'u' - user's, '*' - general,
     *                ('g' - group (will be probably added in future))
     */
    function set($property, $selector, $value, $type='u', $id='', $uid='') {
        $this->properties[$property][$selector] = array($value,$type, $id, $uid);
    }


    /** Get specific property array for current user and slice
     *  This function returns property as array(0 => <value>, 1 => <is_general>)
     *  (or as array of arrays, if selector '*' is used)
     *  @param selector - for properties with only one value (like listlen) it
     *                    is always 0 (you can ommit it)
     *  @returns property value (directly - not in array as get() does)
     */
    function get($property, $selector=0, $no_load=false ) {
        if ( !$no_load )  $this->loadprofile();
        if ( isset($this->properties) AND isset($this->properties[$property]) ) {
            return ( (string)$selector=='*' ) ? $this->properties[$property] :
                                        $this->properties[$property][$selector];
        }
        return false;

    }

    /** Get specific property setting for current user and slice
     *  @param selector - for properties with only one value (like listlen) it
     *                    is always 0 (you can ommit it)
     *  @returns property value (directly - not in array as get() does)
     */
    function getProperty( $property, $selector=0, $no_load=false ) {
        $prop = $this->get($property, $selector, $no_load);
        return is_array($prop) ? $prop[0] : false;
    }

    /** Many properties (the one we use for filling field content - 'predefine',
     *  'fill', 'hide&fill') have the same format:
     *  (<html_flag>:<default_fnc_* function>:<parameter>)
     *  This functions parses it and returns in array.
     */
    function parseContentProperty($value) {
        # profile value format:
        $fnc = ParseFnc(substr($value,2));  # all default should have fnc:param format
        if( $fnc ) {                        # call function
            $fncname = 'default_fnc_' . $fnc["fnc"];
            $x= array( $fncname($fnc["param"]), ($value[0] == '1') );
            return $x;
        } else {
            return array();
        }
    }

    /** Deletes all rules for the user
     *  (used on revoking the perms for user on a slice)
     */
    function delUserProfile() {
        $this->do_sql("DELETE FROM profile WHERE      uid='". $this->user_id ."'
                                             AND slice_id='". q_pack_id($this->module_id) ."'",
                      "Can't delete profile");
    }

    function insertProperty($property, $selector, $value, $global=false) {
        $property = quote($property); $selector = quote($selector); $value = quote($value);
        $last_id = $this->do_sql("INSERT INTO profile SET slice_id='". q_pack_id($this->module_id) ."',
                                                          uid='". ($global ? '*' : $this->user_id) ."',
                                                          property='$property',
                                                          selector='$selector',
                                                          value='$value'",
                      "Can't update profile");
        return $last_id;
    }

    function deleteProperty($property, $selector="", $global=false) {
        $property = quote($property); $selector = quote($selector);
        $this->do_sql("DELETE FROM profile WHERE property='$property'
                                             AND      uid='". ($global ? '*' : $this->user_id) ."'
                                             AND slice_id='". q_pack_id($this->module_id) ."'".
                              ($selector ? " AND selector = '$selector' " : ''),
                       "Can't delete profile");
    }

    function updateProperty($property, $selector, $value, $global=false, $id) {
        $property = quote($property); $selector = quote($selector); $value=quote($value);
        $SQL = "UPDATE profile SET";
        if ($selector != "") $SQL2 .= " selector='".$selector."'";
        if ($value != "") { if ($SQL2) {
                             $SQL2 .= ", value='".$value."'";
                          } else {
                             $SQL2 .= " value='".$value."'";
                          }
        }
        $SQL .= $SQL2;
        $SQL .= "WHERE property='$property'";
        if ($id) $SQL .= " AND id='".$id."'";
        $SQL .= " AND slice_id='". q_pack_id($this->module_id) ."'";
        $this->do_sql($SQL,"Can't delete profile");
    }


    function do_sql($SQL, $err="") {
        if ( !$this->module_id OR !$this->user_id ) return false;
        $db = getDB();
        if (!$db->tquery($SQL) AND $err) {
            $this->err["DB"] = $err;
        }
        $last_id = get_last_insert_id($db, "profile");
        freeDB($db);
        return $last_id;
    }
}
?>
