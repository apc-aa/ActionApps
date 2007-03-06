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
     * State uses just basic types - array, int, text - not objects
     * @param  array $state state array which stores object's data. The array
     *                      you will get by getState() method.
     */
    function setFromState(&$state) {
        // we need to call getPersistentProperties() with $class parameter
        // because generic static classs method (@see below)
        // in storable_class is not able to detect, what type of class it is in
        // Grrr! PHP (5.2.0)

        // first - deal with versioning
        // convert state to last version
        $object_version = max($state['aa_version'], 1);
        if ( call_user_func(array(get_class($this), 'version')) > $object_version) {
            // if some object uses version > 1,then method convertState()
            // should be defined
            $state = $this->convertState($object_version, $state);
        }

        foreach (call_user_func_array(array(get_class($this), 'getPersistentProperties'), array(get_class($this))) as $property) {
            $property_id   = $property->getId();
            $property_type = $property->getType();
            $propery_value = $this->$property_id;
            if ($property->isArray()) {
                if ( !is_array($propery_value) ) {
                    $propery_value = array();
                }
                if ( is_array($state[$property_id])) {
                    foreach($state[$property_id] as $k => $v) {
                        // create objects, if necessary
                        $propery_value[$k] = $this->_oneStatePropertyValue($propery_value[$k], $property, $v);
                    }
                }
            } else {
                $propery_value = $this->_oneStatePropertyValue($propery_value, $property, $state[$property_id]);
            }
            $this->$property_id = $propery_value;
        }
    }

    function _oneStatePropertyValue($obj, $property, $state) {
        if (!$property->isObject()) {
            return $state;
        }
        // if the object is already created, then just rewrite persistent slots
        if (!is_object($obj))  {
            $property_type = $property->getType();
            $obj           = new $property_type;
        }
        $obj->setFromState($state);
        return $obj;
    }

    /**
     * Returns state array of the object - stores object's data for leter
     * restoring (by setFromState() method)
     */
    function getState() {
        $class = get_class($this);
        foreach (call_user_func_array(array($class, 'getPersistentProperties'), array($class)) as $property) {
            $property_id   = $property->getId();
            $property_type = $property->getType();
            if ($property->isArray()) {
                if ( is_array($this->$property_id) ) {
                    foreach($this->$property_id as $k => $v) {
                        $ret[$property_id][$k] = is_object($v) ? $v->getState() : $v;
                    }
                }
            } else {
                $ret[$property_id] = is_object($this->$property_id) ? $this->$property_id->getState() : $this->$property_id;
            }
        }
        // add version if it is not 1
        if ( ($version = call_user_func(array($class, 'version'))) > 1) {
            $ret['aa_version'] = $version;
        }
        return $ret;
    }

    /// Static ///

    /** Class version
     *  Used for getting state from data, which was stored sometimes in
     *  the history, so the inner structure of the class was changed
     */
    function version() {
        return 1;
    }

    /** Returns array of persistent slots (AA_property)
     *  Should be overiden in child classes!
     */
    function getPersistentProperties($class=null) {
        // we need to call getPersistentProperties() with $class parameter
        // because this generic static classs method is not able to detect, what
        // type of class it is in. Grrr! PHP (5.2.0)
        $ret = array();

        if (is_callable(array($class, 'getClassProperties'))) {
            $properties = call_user_func(array($class, 'getClassProperties'));
            foreach ( $properties as $id => $property) {
                if ( $property->isPersistent() ) {
                    $ret[$id] = $property;
                }
            }
        }
        return $ret;
    }
}

class AA_Object extends storable_class {

    /** Object ID   - 32 characters long hexadecimal number */
    var $aa_id;

    /** Object Name - max 16 characters long object name - optional, unique for whole AA */
    var $aa_name;

    /** Object Owner - id if object's parent, where the object belongs - oprional */
    var $aa_owner;

    function setOwner($owner_id) {
        $this->aa_owner = $owner_id;
    }

    function setName($name) {
        $this->aa_name = $name;
    }

    function setId($id) {
        $this->aa_id = $id;
    }

    /** Save the object to the database */
    function save() {
        $this->delete();

        $class = get_class($this);
        foreach (call_user_func_array(array($class, 'getClassProperties'), array($class)) as $property) {
            $property_id   = $property->getId();
            $property_type = $property->getType();
            if ($property->isArray()) {
                if ( is_array($this->$property_id) ) {
                    // all keys are numeric
                    foreach($this->$property_id as $k => $v) {
                        $this->_saveProperty($property, $v, $k);
                    }
                }
            } else {
                $this->_saveProperty($property, $this->$property_id);
            }
        }

        // now the mandatory fields
        // $this->_saveRow('aa_id',       $this->getId(),                        'text');
        $this->_saveRow('aa_type',     get_class($this),                         'text');
        // add version if it is not 1
        $this->_saveRow('aa_version',  call_user_func(array($class, 'version')), 'int');
        if ( $this->aa_name ) {
            $this->_saveRow('aa_name', $this->aa_name,                           'text');
        }
        if ( $this->aa_owner ) {
            $this->_saveRow('aa_owner', $this->aa_owner,                         'text');
        }
        // helper field aa_subobjects used for quicker load of object
        $subobjects = $this->getSubObjects();
        if (count($subobjects) > 0) {
            $this->_saveRow('aa_subobjects', join(',', $subobjects),             'text');
        }
        return $this->aa_id;
    }

    /** Deletes the object from the database including all the subobjects */
    function delete() {
        $to_delete = array( $this->getId() );

        // we have to ask database for subobjects, because it gives us the ids
        // as stored in database, not the ids of current subobjects, which could
        // be different. The $this->getSubObjects() donot help us here!
        $to_delete = array_merge($to_delete, explode(',',$this->getProperty($to_delete[0], 'aa_subobjects')));
        $varset    = new CVarset;

        $sqlin     = Cvarset::sqlin('object_id', $to_delete);
//        $varset->doDeleteWhere('object_text', Cvarset::sqlin('object_id', AA_Object::query('AA_Set', array('cde50ab466e2211b97ff8f93e7add44f', 'cd8499c809cdbb3b51d026e1e07520c5'))));
//        exit;
        $varset->doDeleteWhere('object_text',    $sqlin);
        $varset->doDeleteWhere('object_integer', $sqlin);
        $varset->doDeleteWhere('object_float',   $sqlin);
    }

    /** Get all object ids which is inside this object */
    function getSubObjects() {
        $ret   = array();
        $class = get_class($this);
        foreach (call_user_func_array(array($class, 'getClassProperties'), array($class)) as $property) {
            if (!$property->isObject()) {
                continue;
            }
            $property_id   = $property->getId();
            if ( is_array($this->$property_id) ) {
                // all keys are numeric
                foreach($this->$property_id as $v) {
                    if ( is_object($v) ) {
                        $ret[] = $v->getId();
                        $ret   = array_merge($ret, $v->getSubObjects());
                    }
                }
            } elseif ( is_object($this->$property_id) ) {
                $obj   = $this->$property_id;
                $ret[] = $obj->getId();
                $ret   = array_merge($ret, $obj->getSubObjects());
            }
        }
        return $ret;
    }

    function _saveProperty($property, $value, $priority=0) {

        // Property type - text | int | bool | float | <class_name>
        if ( !$property->isObject()) {
            $this->_saveRow($property->getId(), $value, $property->getType(), $priority);
            return true;
        }

        if (!is_object($value)) {
            return false;
        }
        //  this property is object - so save it (the id of the object is returned)
        $object_id = $value->save();
        // if not saved, then it returns null
        if (!$object_id) {
            return false;
        }
        $this->_saveRow($property->getId(), $object_id, 'text', $priority);
        return true;
    }

    function _saveRow($property_id, $value, $type, $priority=0) {
        $TABLE_NAMES = array('text'=>'text', 'bool'=>'integer', 'int'=>'integer', 'float'=>'float');

        $varset = new CVarset();
        $varset->add('object_id', 'text',   $this->getId());
        $varset->add('priority',  'number', $priority);
        $varset->add('property',  'text',   $property_id);

        // Property type - text | int | bool | float | <class_name>
        $varset->add('value',      $type,   $value);
        $varset->doInsert('object_'. $TABLE_NAMES[$type]);
    }

    function getId() {
        // id of the object is not deffined, yet
        if (!$this->aa_id) {
            $this->aa_id = new_id();
        }
        return $this->aa_id;
    }

    function getName() {
        return $this->aa_name;
    }

    /// Static ///
    function getNameArray($obj_type, $owner) {
        if ( empty($owner) ) {
            return array();
        }
        $SQL = "SELECT o1.object_id, o3.value FROM object_text as o1 INNER JOIN object_text as o2 ON o2.object_id=o1.object_id INNER JOIN object_text as o3 ON o3.object_id=o1.object_id
                 WHERE o1.property = 'aa_type'  AND o1.value = '$obj_type'
                   AND o2.property = 'aa_owner' AND ". CVarset::sqlin('o2.value', $owner) ."
                   AND o3.property = 'aa_name'";

        $ret = GetTable2Array($SQL, 'object_id', 'value');
        return is_array($ret) ? $ret : array();
    }

    function &factory($classname, $params=null) {
        return class_exists($classname) ? new $classname($params) : null;
    }

    function &factoryByName($mask, $name, $params=null) {
        return AA_Object::factory($mask. ucwords(strtolower($name)), $params);
    }

    function getProperty($id, $property) {
        return GetTable2Array("SELECT value FROM object_text WHERE object_id = '$id' AND property = '$property'", 'aa_first', 'value');
    }

    function getObjectType($id) {
        return AA_Object::getProperty($id, 'aa_type');
    }

    function &load($id, $type=null) {
        // @todo optimize the load
        //    - get used tables from properties,
        //    - load object from database in one step using aa_subobjects property of the objects

        if ( !$type ) {
            $type = getObjectType($id);
        }
        if ( !$type ) {
            return null;
        }
        $obj = new $type;
        $obj->setId($id);
        $properties = call_user_func_array(array($type, 'getClassProperties'), array($type));

        $props_from_db = GetTable2Array("SELECT `property`, `value` FROM object_text WHERE object_id = '$id' ORDER by property, priority", '');
        if ( is_array($props_from_db) ) {
            foreach ( $props_from_db as $v ) {
                $property_id = $v['property'];
                if ( !is_object($properties[$property_id])) {
                    if (in_array($property_id, array('aa_name', 'aa_owner'))) {
                        $obj->$property_id = $v['value'];
                    }
                    continue;
                }
                $property    = $properties[$property_id];
                if ( $property->isArray() ) {
                    $p = (array)$obj->$property_id;
                    $p[] = ($property->isObject() ? AA_Object::load($v['value'], $property->getType()) : $v['value']);
                    $obj->$property_id   = $p;
                } else {
                    $obj->$property_id   = ($property->isObject() ? AA_Object::load($v['value'], $property->getType()) : $v['value']);
                }
            }
        }

        $props_from_db = GetTable2Array("SELECT `property`,`value` FROM `object_integer` WHERE object_id = '$id' ORDER by property, priority", '');
        if ( is_array($props_from_db) ) {
            foreach ( $props_from_db as $v ) {
                $property_id = $v['property'];
                $property    = $properties[$property_id];
                if ( !is_object($properties[$property_id]) ) {
                    continue;
                }
                if ( $property->isArray() ) {
                    $p = (array)$obj->$property_id;
                    $p[] = ($property->isObject() ? AA_Object::load($v['value'], $property->getType()) : $v['value']);
                    $obj->$property_id   = $p;
                } else {
                    $obj->$property_id   = $v['value'];
                }
            }
        }

        $props_from_db = GetTable2Array("SELECT `property`,`value` FROM `object_float` WHERE object_id = '$id' ORDER by property, priority", '');
        if ( is_array($props_from_db) ) {
            foreach ( $props_from_db as $v ) {
                $property_id = $v['property'];
                $property    = $properties[$property_id];
                if ( !is_object($properties[$property_id]) ) {
                    continue;
                }
                if ( $property->isArray() ) {
                    $p = (array)$obj->$property_id;
                    $p[] = ($property->isObject() ? AA_Object::load($v['value'], $property->getType()) : $v['value']);
                    $obj->$property_id   = $p;
                } else {
                    $obj->$property_id   = $v['value'];
                }
            }
        }
        return $obj;
    }


    /** Finds object IDs for objects given by conditions
    *
    *   @param string        $type   - object type
    *   @param AA_Slices     $owners - search only objects owned by those slices
    *   @param AA_Set        $conds  - search conditions
    *                        $sort   - sort fields (see FAQ)
    *   @param zids          $restrict_zids - use it if you want to choose only from a set of ids
    *   @return A zids object with a list of the ids that match the query.
    *
    *   @global  bool $debug (in) many debug messages
    *   @global  bool $nocache (in) do not use cache, even if use_cache is set
    */
    function query($type, $owners=null, $set=null, $restrict_zids=null) {
        // select * from item, content as c1, content as c2 where item.id=c1.item_id AND item.id=c2.item_id AND
        // c1.field_id IN ('fulltext........', 'abstract..........') AND c2.field_id = 'keywords........' AND c1.text like '%eufonie%' AND c2.text like '%eufonie%' AND item.highlight = '1';
        global $debug;                 // displays debug messages
        global $nocache;               // do not use cache, if set

        $SQL = "SELECT o1.object_id FROM object_text as o1, object_text as o2 WHERE o1.object_id=o2.object_id
                AND o1.property='aa_type'  AND o1.value='".quote($type)."'
                AND o2.property='aa_owner' AND o2.value='".quote(reset($owners))."'";

        $ids = GetTable2Array($SQL, '', 'object_id');
        return $ids ? $ids : array();

        // @todo !!! - rewrite it.
        // do the same as in quryZids for any object
    }
}
?>
