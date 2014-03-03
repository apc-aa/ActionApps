<?php
/**
 * File contains definition of profile class - used for custom user settings
 *
 * Should be included to other scripts (as /include/init_page.php3)
 *
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
 * @package   Include
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

require_once AA_INC_PATH. "locauth.php3";  // for getMembership()

/** AA_Profile class - used for storing specific custom settings of loged user
 *  in one specific slice
 */
class AA_Profile {
    var $classname = "AA_Profile";   // sometimes it is required class property

    var $module_id;      // for which slice/module we hold the profile?
    var $user_id;        // for which user we hold the profile?
    var $properties;     // user's profile/settings
    var $err;            // array of error messages from methods

    /** AA_Profile function - do not use directly - use rather
     *     $profile = AA_Profile::getProfile($user_id, $module_id);
     *  constructor - do nearly nothing (lazy evaluation used)
     *  @param $module_id - for which slice/module we hold the profile?
     *  @param $user_id   - for which user we hold the profile?
     */
    function AA_Profile($user_id, $module_id) {
        $this->user_id   = $user_id;
        $this->module_id = $module_id;
    }

    /** getProfile - main factory static method
     *  Profiles should be used like $profile = AA_Profile::getProfile($user_id, $module_id);
     * @param $slice_id
     */
    function & getProfile($user_id, $module_id) {
        // AA_Profiles array
        static $instances = array();
        if (empty($instances[$user_id])) {
            $instances[$user_id] = array();
        }
        if (empty($instances[$user_id][$module_id])) {
            $instances[$user_id][$module_id] = new AA_Profile($user_id, $module_id);
        }

        return $instances[$user_id][$module_id];
    }


    /** loadprofile function
     *  Loads profile for current user and current slice/module from database
     *  User's custom setting is combined with slice defaults (user '*' in
     *  database. Users own settings is stronger, so it redefines the default
     *  ones if the same propery and property_selector is used
     *  @param bool $force - if true, reread the profile from database even if
     *                       profile is already loaded
     */
    function loadprofile( $force=false ) {
        if ( $force ) {
            unset( $this->properties );  // refresh from database
        }
        if ( !$this->module_id OR (isset($this->properties) AND is_array($this->properties)) ) {
            return;
        }
        $this->properties = array();

        // get also profiles from user's group(s)
        $groups     = AA::$perm->getMembership($this->user_id);
        $usr_groups = array_merge(array($this->user_id, '*'), $groups);

        // default setting for the slice is stored as user with uid = *
        $profiles = DB_AA::select(array(), 'SELECT * FROM profile', array(array('slice_id',$this->module_id,'l'), array('uid',$usr_groups)));

        foreach ($profiles as $pi) {
            if ( $pi['uid'] == $this->user_id ) {
                $this->set($pi['property'], $pi['selector'], $pi['value'], 'u', $pi['id'], $pi['uid']);
            } elseif ( in_array($pi['uid'], $groups) ) {
                $group_profile[] = $pi;  // store for later use
            } else {
                $general_profile[] = $pi;  // store for later use
            }
        }
        // now add properties from group profile(s), if it is not already set
        // TODO: deal with priority of multiple groups
        if ( $group_profile ) {
            foreach ( $group_profile as $v ) {
                if ( !$this->getProperty($v['property'],$v['selector'],true) ) {
                    $this->set( $v['property'], $v['selector'], $v['value'], '*', $v['id'], $v['uid'] );
                }
            }
        }
        // now add properties from default profile, if it is not already set
        if ( $general_profile ) {
            foreach ( $general_profile as $v ) {
                if ( !$this->getProperty($v['property'],$v['selector'],true) ) {
                    $this->set( $v['property'], $v['selector'], $v['value'], '*', $v['id'], $v['uid'] );
                }
            }
        }
    }

    /** set function
     *  Stores property
     * @param $property
     * @param $selector
     * @param $value
     *  @param $type - exactly one of 'u' - user's, '*' - general,
     *                ('g' - group (will be probably added in future))
     * @param $id
     * @param $uid
     */
    function set($property, $selector, $value, $type='u', $id='', $uid='') {
        $this->properties[$property][$selector] = array($value,$type, $id, $uid);
    }


    /** get function
     *  Get specific property array for current user and slice
     *  This function returns property as array(0 => <value>, 1 => <is_general>)
     *  (or as array of arrays, if selector '*' is used)
     * @param $property
     *  @param $selector - for properties with only one value (like listlen) it
     *                    is always 0 (you can ommit it)
     * @param $no_load
     *  @return property value (directly - not in array as get() does)
     */
    function get($property, $selector=0, $no_load=false ) {
        if ( !$no_load ) {
            $this->loadprofile();
        }
        if ( isset($this->properties) AND isset($this->properties[$property]) ) {
            return ( (string)$selector=='*' ) ? $this->properties[$property] :
                                        $this->properties[$property][$selector];
        }
        return false;

    }

    /** getProperty function
     *  Get specific property setting for current user and slice
     * @param $property
     *  @param $selector - for properties with only one value (like listlen) it
     *                    is always 0 (you can ommit it)
     * @param $no_load
     *  @return property value (directly - not in array as get() does)
     */
    function getProperty( $property, $selector=0, $no_load=false ) {
        $prop = $this->get($property, $selector, $no_load);
        return is_array($prop) ? $prop[0] : false;
    }

    /** parseContentProperty function
     *  Many properties (the one we use for filling field content - 'predefine',
     *  'fill', 'hide&fill') have the same format:
     *  (<html_flag>:<default_fnc_* function>:<parameter>)
     *  This functions parses it and returns in array.
     * @param $value
     */
    function parseContentProperty($value) {
        if (!($generator = AA_Generator::factoryByString(substr($value,2)))) {  // see format described above
            return array();
        }
        return $generator->generate()->setFlag(($value[0] == '1') ? FLAG_HTML : 0);
    }

    /** delUserProfile function
     *  Deletes all rules for the user
     *  (used on revoking the perms for user on a slice)
     */
    function delUserProfile() {
        $this->do_sql("DELETE FROM profile WHERE      uid='". $this->user_id ."'
                                             AND slice_id='". q_pack_id($this->module_id) ."'",
                      "Can't delete profile");
    }
    /** insertProperty function
     * @param $property
     * @param $selector
     * @param $value
     * @param $global
     */
    function insertProperty($property, $selector, $value, $global=false) {
        $property = quote($property); $selector = quote($selector); $value = quote($value);
        $last_id = $this->do_sql("INSERT INTO profile (slice_id, uid, property, selector, value)
                                         VALUES ('". q_pack_id($this->module_id) ."','". ($global ? '*' : $this->user_id) ."','$property','$selector','$value')",
                      "Can't update profile");
        return $last_id;
    }
    /** deleteProperty function
     * @param $property
     * @param $selector
     * @param $global
     */
    function deleteProperty($property, $selector="", $global=false) {
        $property = quote($property); $selector = quote($selector);
        $this->do_sql("DELETE FROM profile WHERE property='$property'
                                             AND      uid='". ($global ? '*' : $this->user_id) ."'
                                             AND slice_id='". q_pack_id($this->module_id) ."'".
                              ($selector ? " AND selector = '$selector' " : ''),
                       "Can't delete profile");
    }

    /** Copies the profile form one user to another
     *  @static
     **/
    function copyProfile($slice_id, $from_uid, $to_uid) {
        $p_slice_id = q_pack_id($slice_id);
        $rules      = GetTable2Array("SELECT * FROM profile WHERE slice_id='$p_slice_id' AND (uid='$from_uid')");
        $varset     = new CVarset;
        foreach ($rules as $row) {
            $varset->resetFromRecord($row);
            $varset->remove('id');
            $varset->set('uid', $to_uid);
            $varset->doInsert('profile');
        }
    }

    /** updateProperty function
     * @param $property
     * @param $selctor
     * @param $value
     * @param $global
     * @param $id
     */
    function updateProperty($property, $selector, $value, $id) {
        $property = quote($property); $selector = quote($selector); $value=quote($value);
        $SQL = "UPDATE profile SET";
        if ($selector != "") {
            $SQL2 .= " selector='".$selector."'";
        }
        if ($value != "") {
            if ($SQL2) {
                $SQL2 .= ", value='".$value."'";
            } else {
                $SQL2 .= " value='".$value."'";
            }
        }
        $SQL .= $SQL2;
        $SQL .= "WHERE property='$property'";
        if ($id) {
            $SQL .= " AND id='".$id."'";
        }
        $SQL .= " AND slice_id='". q_pack_id($this->module_id) ."'";
        $this->do_sql($SQL,"Can't delete profile");
    }

    /** do_sql function
     * @param $SQL
     * @param $err
     */
    function do_sql($SQL, $err="") {
        if ( !$this->module_id OR !$this->user_id ) {
            return false;
        }
        $db = getDB();
        if (!$db->tquery($SQL) AND $err) {
            $this->err["DB"] = $err;
        }
        $last_id = $db->last_insert_id();

        freeDB($db);
        return $last_id;
    }
}
/** AddProfileProperty function
 * @param $uid
 * @param $slice_id
 * @param $property
 * @param $field_id
 * @param $fnction
 * @param $param
 * @param $html
 */
function AddProfileProperty($uid, $slice_id, $property, $field_id, $fnction, $param, $html) {
    $profile = AA_Profile::getProfile($uid, $slice_id); // user settings
    switch($property) {
        case 'listlen':
        case 'input_view':
        case 'admin_perm':
            if ( (($property=='admin_perm') AND (strlen($param)==32) ) OR ($param > 0) ) {
                $profile->deleteProperty($property);
                $profile->insertProperty($property, '0', $param);
                $Msg = MsgOK(_m("Rule added"));
            } else {
                $profile->deleteProperty($property);
                $Msg = MsgOK(_m("Rule deleted"));
            }
            break;
        case 'admin_order':
            if ( $field_id ) {
                $profile->deleteProperty($property, $field_id);
                $profile->insertProperty($property, $field_id, $field_id.$fnction);
                $Msg = MsgOK(_m("Rule added"));
            }
            break;
        case 'admin_search':
            if ( $field_id ) {
                $profile->deleteProperty($property, $field_id);
                // 0 is just placeholder - normaly we use it for html flag
                // @todo - do it better with some classes and custom parameters
                $profile->insertProperty($property, $field_id, "0:$fnction:$param");
                $Msg = MsgOK(_m("Rule added"));
            }
            break;
        case 'hide':
        case 'ui_manager_hide':
        case 'ui_inputform_hide':
            if ( $field_id ) {
                $profile->deleteProperty($property, $field_id);
                $profile->insertProperty($property, $field_id, '1');
                $Msg = MsgOK(_m("Rule added"));
            }
            break;
        case 'fill':
        case 'hide&fill':
        case 'predefine':
            if ( $field_id ) {
                $profile->deleteProperty($property,$field_id);
                $profile->insertProperty($property, $field_id, "$html:$fnction:$param");
                $Msg = MsgOK(_m("Rule added"));
            }
            break;
        case 'ui_manager':
        case 'ui_inputform':
            if ( $field_id ) {
                $profile->deleteProperty($property,$field_id);
                $profile->insertProperty($property, $field_id, "$param");
                $Msg = MsgOK(_m("Rule added"));
            }
            break;
    }
    return $Msg;
}

?>
