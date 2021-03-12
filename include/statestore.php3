<?php

/**
 * File contains definition of AA_Storable class - abstract class which
 * implements two methods for storing and restoring class data (used in
 * searchbar class, manager class, ...
 *
 * Should be included to other scripts (as /include/searchbar.class.php3)
 *
 * PHP version 7.2+
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
*/

use AA\IO\DB\DB_AA;
use AA\IO\Grabber\ObjectForm;
use AA_Plannedtask;

/** allows serialize objects */
abstract class AA_Serializable {

    /** factory function
     * @param string $classname
     * @param array $params
     * @return AA_Serializable
     */
    static function factory($classname, $params=null) {
        return class_exists($classname) ? new $classname($params) : null;
    }

    /** factoryByName function - creates any object based on mask and name:
     *   AA_Stingexpand::factoryByName('', $params);
     * @param string $name
     * @param array  $params
     * @param null $class_mask
     * @return AA_Serializable
     */
    static function factoryByName($name, $params=null, $class_mask=null) {
        return self::factory(static::constructClassName($name, $class_mask), $params);
    }

    /** factoryByString function
     *  Creates object from the string, which is used for storing setting in
     *  the database (older approach). The string looks like:
     *    dte:1:10:1
     *  which means, that it is instance of AA\Widget\DteWidget (when $class_mask == 'AA\Widget\Widget')
     *  and the properties are filled with values 1, 10 and 1 (in this order)
     *
     * @param string $string     like dte:1:10:1 in field.input_show_func
     * @return AA_Serializable
     */
    static function factoryByString($string) {
        $params = static::parseClassProperties($string);
        return static::factory($params['class'], $params);
    }

    /** Constructor
     *  @param $params array of parameters in form 'property_name' => 'value'
     */
    function __construct($params= []) {
        // ask class, which parameters uses and fill it
        // call AA\Widget\TxtWidget::getClassProperties()), for example
        $this->setProperties($params);
    }

    /**
     * @param array $params
     */
    function setProperties($params= []) {
        $class = get_class($this);
        $props = $class::getClassProperties();
        foreach ($props as $name =>$property) {
            if (isset($params[$name])) {
                $this->$name = $params[$name];
            }
        }
    }

    /** getClassProperties function of AA_Serializable
     *   - abstract method defining the class properties
     *   - properties are used for two reasons
     *      - it could be stored in the database object storage when the object
     *        is stored (save()) (when persistent is set to true)
     *      - the object could be edited on html page - the form is automatically
     *        created using the properties
     * @return array
     * @see AA_Components
     */
    static function getClassProperties(): array {
        // array of AA parameters (can't be object's data, since we need
        // to call it statically (as class method)
        return [];
    }

    /** create the name of class from the type and name
     *  static class method
     * @param string $name
     * @param string $class_mask
     * @return string
     */
    static function constructClassName($name, $class_mask=null) {
        $name = ucwords(strtolower(str_replace('-', '', $name)));    // str_replace probably for validator e-mail, but maybe not necessary
        $class_mask = rtrim($class_mask,'_') ?: get_called_class();
        if (($pos=strrpos($class_mask, '\\')) !== false) {
            return substr_replace($class_mask, "\\$name", $pos, 1);
        } else {
            return $class_mask . '_' . ucwords(strtolower(str_replace('-', '', $name)));   // str_replace probably for validator e-mail, but maybe not necessary
        }
    }

    /** parseClassProperties function
     *  Parses class parameters from the string, which is stored in the database
     *  Typical use is for fields.input_show_func, where parameters are stored
     *  as string in the form: fnc:const:param
     *  @param string $string
     *  @return array - asociative array of parameters, the name of parameters is given
     *  by the class itself ($class_mask . fnc).
     */
    static function parseClassProperties($string) {
        // we do not use ParamExplode() - I  do not like the http:// replacement there
        $splited = explode('##Sx', str_replace(['#:', ':', '~@|_'], ['~@|_', '##Sx', ':'], $string));

        // first parameter is the class identifier - the parameters starts then
        $class  = self::constructClassName(array_shift($splited), get_called_class().'_');
        $params = ['class' => $class];

        if ( class_exists($class) ) {
            // ask class, which parameters uses
            // call AA\Widget\TxtWidget::getClassProperties()), for example
            $props = $class::getClassProperties();
            $name  = '';

            foreach ($props as $name =>$property) {
                $value = array_shift($splited);
                if (isset($value)) {
                    $params[$name] = $property->isMulti() ? json2asoc($value) : $value;  // const_arr for widget uses this, for example
                    //$params[$name] = $property->isMulti() ? json2arr($value,true) : $value;  // const_arr for widget uses this, for example
                    //$params[$name] = $value;
                }
            }
            // is there rest? Add all the values to the last parameter
            // It isthere for older 1-parameter classes, where we do not escape ":"
            // like AA_Generator_Txt - we want it as it was written - not splitted
            if (count($splited) AND $name) {
                $params[$name] .= ':'.join(':',$splited);
            }
        }
        return $params;
    }
}



/**
 * AA_Storable - abstract class which implements methods for storing and
 * restoring class data (used in searchbar class, manager class, ...).
 *
 * If you want to use storable methods in your class, you should derive the new
 * class from AA_Storable. Then the property shoul be marked as persistent
 * where you specify all the variables you want to store. Then you just call
 * getState() and setFromState() methods for storing and restoring object's data
 */
class AA_Storable extends AA_Serializable {


    /** getPersistentProperties function
     *  Returns array of persistent slots (AA_property)
     *  Uses getClassProperties() method of the classes from which it grabs all
     *  all persistent properties
     * @param $class
     * @return array
     */
    static function getPersistentProperties() {
        $ret        = [];
        $properties = static::getClassProperties();
        foreach ( $properties as $id => $property) {
            if ( $property->isPersistent() ) {
                $ret[$id] = $property;
            }
        }
        return $ret;
    }

    /** convertState function
     *  Setting state from previous versions of state
     * @param int   $version
     * @param array $state
     * @return array state transformed
     */
    function &convertState($version, &$state) {
        return $state;
    }


    /** setFromState function
     * Restores the object's data from $state
     * State uses just basic types - array, int, text - not objects
     * @param  array $state state array which stores object's data. The array
     *                      you will get by getState() method.
     */
    function setFromState(&$state) {
        // first - deal with versioning
        // convert state to last version
        $object_version = max($state['aa_version'], 1);
        if ( static::version() > $object_version) {
            // if some object uses version > 1,then method convertState()
            // should be defined
            $state = $this->convertState($object_version, $state);
        }

        $props = static::getPersistentProperties();
        foreach ($props as $property) {
            $property_id   = $property->getId();
            $propery_value = $this->$property_id;
            if ($property->isMulti()) {
                if ( !is_array($propery_value) ) {
                    $propery_value = [];
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

    /** _oneStatePropertyValue function
     * @param $obj
     * @param $property
     * @param $state
     * @return
     */
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

    static public function factoryFromState($type, $state) {
        if ((strlen($state['aa_class']) > 0) AND (strpos($state['aa_class'], $type)===0)) {
            // we are able to construct also subclasses, so if the property is marked as AA_Formrow, then the real type
            // of the variable could be AA_Formrow_* (like AA_Formrow_Full, ...)
            $type = $state['aa_class'];
        }
        $obj = new $type;
        $obj->setFromState($state);
        return $obj;
    }

    /** getState function
     * Returns state array of the object - stores object's data for leter
     * restoring (by setFromState() method)
     */
    function getState() {
        $ret   = [];
        $props = static::getPersistentProperties();
        foreach ($props as $property) {
            $property_id   = $property->getId();
            if ($property->isMulti()) {
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
        if ( ($version = static::version()) > 1) {
            $ret['aa_version'] = $version;
        }
        // we need the exact class in order we can factory the object from state
        $ret['aa_class']   = get_called_class();
        return $ret;
    }

    /// Static ///

    /** version function
     *  Class version
     *  Used for getting state from data, which was stored sometimes in
     *  the history, so the inner structure of the class was changed
     */
    static function version() {
        return 1;
    }
}

class AA_Object extends AA_Storable implements AA_iEditable {

    /** Object ID   - 32 characters long hexadecimal number */
    var $aa_id;

    /** Object Name - max 16 characters long object name - optional, unique for whole AA */
    var $aa_name;

    /** Object Owner - id if object's parent, where the object belongs - optional */
    var $aa_owner;

    /** display Name property on the form by default< */
    const USES_NAME = true;

    /** We store also following data, but it do not need its own variable
     *   aa_type       - class of the object
     *   aa_version    - version of the object class (if it is not 1)
     *   aa_subobjects - helper field used for quicker load of object
     */

    function setNew($id, $owner, $name='') {
        $this->aa_id    = $id;
        $this->aa_owner = $owner;
        $this->aa_name  = $name;
    }

    /** setOwnerId function
     * @param $owner_id
     */
    function setOwnerId($owner_id) {
        $this->aa_owner = $owner_id;
        return $this;
    }

    /** setName function
     * @param $name
     */
    function setName($name) {
        $this->aa_name = $name;
        return $this;
    }

    /** setId function
     * @param $id
     */
    function setId($id) {
        $this->aa_id = $id;
        return $this;
    }

    /** getId function  */
    function getId() {
        // id of the object is not deffined, yet
        if (!$this->aa_id) {
            $this->aa_id = new_id();
        }
        return $this->aa_id;
    }

    /** getName function */
    function getName() {
        return $this->aa_name;
    }

    /** getOwnerId function */
    function getOwnerId() {
        return $this->aa_owner;
    }

    /** getObjectType */
    function getObjectType() {
        // return $this->aa_type;     // no aa_type here
        return get_called_class();
    }

    function getProperty($property_id, $default=null) {
        return is_null($default) ? $this->$property_id : (($this->$property_id == '') ? $default : $this->$property_id);
    }

    function setProperty($property_id, $value) {
        $this->$property_id = $value;
        return $this;
    }

    /** AA_iEditable method - save the object to the database
     * @return string|bool - id of saved data or false
     * @throws Exception
     */
    public function save() {
        if ( !$this->aa_owner ) {
            throw new Exception('No owner set for property '. $this->id. ' - '. $this->name);
        }

        $this->delete();
        $object_id = $this->getId();

        $props = static::getClassProperties();
        foreach ($props as $property) {
            $property_id   = $property->getId();
            $property->save($this->$property_id, $object_id, $this->getOwnerId());
        }
                        //        id                        name                        type    multi  persist validator, required, help, morehelp, example
        $prop = self::getPropertyObject('aa_type');
        $prop->save(get_class($this), $object_id);

        $ver = static::version();
        if ($ver < 1) {
            $prop = self::getPropertyObject('aa_version');
            $prop->save($ver, $object_id);
        }

        $prop = self::getPropertyObject('aa_owner');
        $prop->save($this->aa_owner, $object_id);

        if ( $this->aa_name ) {
            $prop = self::getPropertyObject('aa_name');
            $prop->save($this->aa_name, $object_id);
        }
        // helper field aa_subobjects used for quicker load of object
        $prop = self::getPropertyObject('aa_subobjects');
        $prop->save($this->_getSubObjects(), $object_id);

        $this->aftersave();
        return $this->aa_id;
    }

    /** will be called after saving of the object
     *  could do some clenup work in child classes (like AA_Plannedtask)
     **/
    function aftersave() {}

    static public function getPropertyObject($property_id) {
        switch ($property_id) {
                                                       // id               name          type     multi  persistent validator required
            case 'aa_type':       return new AA_Property('aa_type' ,      'Object type','string', false, true, 'string', true);
            case 'aa_name':       return new AA_Property('aa_name' ,      'Name',       'string', false, true, 'string', false);
            case 'aa_owner':      return new AA_Property('aa_owner' ,     'Owner',      'string', false, true, 'id',     true);
            case 'aa_version':    return new AA_Property('aa_version' ,   'Version',    'int',    false, true, 'int',    false);
                                  // helper field aa_subobjects used for quicker load of object
            case 'aa_subobjects': return new AA_Property('aa_subobjects' ,'Subobjects', 'string', true,  true, 'string', false);
            case 'aa_id':         return new AA_Property('aa_id' ,        'Id',         'string', false, true, 'id',     true);
        }
        return null;
    }

    /** delete function
     *  Deletes the object from the database including all the subobjects
     */
    function delete() {
        AA_Object::deleteObjects([$this->getId()]);
    }

    // array of unpacked ids
    static public function deleteObjects($object_ids) {
        // objects consists from object itself and subobjects
        $subobjects_props = AA_Object::loadProperties($object_ids, 'aa_subobjects');
        foreach ($subobjects_props as $arr) {
           $object_ids = array_merge($object_ids, $arr);
        }
        if (count($object_ids = array_unique($object_ids))) {
            DB_AA::delete_low_priority('object_text',    [['object_id', $object_ids]]);
            DB_AA::delete_low_priority('object_integer', [['object_id', $object_ids]]);
            DB_AA::delete_low_priority('object_float',   [['object_id', $object_ids]]);
        }
        return true;
    }

    // get all Owners objects (for deletion)
    static public function getOwnersObjects($owner_id) {
        return is_long_id($owner_id) ? DB_AA::select( '', 'SELECT object_id FROM `object_text`', [['property', 'aa_owner'], ['value', $owner_id]]) : [];
    }

    /** _getSubObjects function
     *  Get all object ids which is inside this object
     */
    function _getSubObjects() {
        $ret   = [];
        $props = static::getClassProperties();
        foreach ($props as $property) {
            if (!$property->isObject()) {
                continue;
            }
            $property_id   = $property->getId();
            if ( is_array($this->$property_id) ) {
                // all keys are numeric
                foreach($this->$property_id as $v) {
                    if ( is_object($v) AND is_subclass_of($v, 'AA_Object')) {
                        $ret[] = $v->getId();
                        $ret   = array_merge($ret, $v->_getSubObjects());
                    }
                }
            } elseif ( is_object($this->$property_id) AND is_subclass_of($this->$property_id, 'AA_Object') ) {
                $obj   = $this->$property_id;
                $ret[] = $obj->getId();
                $ret   = array_merge($ret, $obj->_getSubObjects());
            }
        }
        return $ret;
    }

    /// Static ///

    /** getNameArray function
     * @param $obj_type
     * @param $owner
     * @return array|bool|string
     */
    static function getNameArray($obj_type, $owner) {
        if ( empty($owner) ) {
            return [];
        }
        $SQL = "SELECT o1.object_id, o3.value FROM object_text as o1 INNER JOIN object_text as o2 ON o2.object_id=o1.object_id INNER JOIN object_text as o3 ON o3.object_id=o1.object_id
                 WHERE o1.property = 'aa_type'  AND o1.value = '$obj_type'
                   AND o2.property = 'aa_owner' AND ". CVarset::sqlin('o2.value', $owner) ."
                   AND o3.property = 'aa_name'";

        $ret = GetTable2Array($SQL, 'object_id', 'value');
        return is_array($ret) ? $ret : [];
    }

    static function loadProperty($id, $property) {
        return DB_AA::select1('value', 'SELECT value FROM `object_text`', [['object_id', $id], ['property', $property]]);
    }

    /** called as AA_Object::loadProperties($ids, 'name')
     *  @return array - properties always in array   ['obj1'=>[valA,valB,...], 'obj2'=>[valC], ...]
     */
    static function loadProperties($ids, $property) {
        return DB_AA::select( ['object_id'=> '+value'], 'SELECT object_id, value FROM `object_text`', [['object_id', $ids], ['property', $property]]);
    }

    /** Loads object from database: AA_Object::load($set_id, 'AA_Set')
     * @param $id - aa_id - object id
     * @param $type - object class - like 'AA_Form'
     * @static
     * @return AA_Object | null
     */
    static function load($id, $type=null) {
        // @todo optimize the load
        //    - get used tables from properties,
        //    - load object from database in one step using aa_subobjects property of the objects

        if ( !$type AND !($type = self::loadProperty($id, 'aa_type')) ) {
            return null;
        }

        $obj = new $type;
        $obj->setId($id);
        $properties = $type::getClassProperties();

        $prop_arr =                        DB_AA::select( ['property'=> '+value'], 'SELECT `property`, `value` FROM `object_text`',    [['object_id', $id]], ['property', 'priority']);
        $prop_arr = array_merge($prop_arr, DB_AA::select( ['property'=> '+value'], 'SELECT `property`, `value` FROM `object_integer`', [['object_id', $id]], ['property', 'priority']));
        $prop_arr = array_merge($prop_arr, DB_AA::select( ['property'=> '+value'], 'SELECT `property`, `value` FROM `object_float`',   [['object_id', $id]], ['property', 'priority']));

        //$tab = GetTable2Array("SELECT `property`, `value` FROM object_text WHERE object_id = '$id' ORDER by property, priority", '');
        //$props_from_db = is_array($tab) ? $tab : array();
        //
        //$tab = GetTable2Array("SELECT `property`,`value` FROM `object_integer` WHERE object_id = '$id' ORDER by property, priority", '');
        //if (is_array($tab)) {
        //    $props_from_db = array_merge($props_from_db, $tab);
        //}
        //$tab = GetTable2Array("SELECT `property`,`value` FROM `object_float` WHERE object_id = '$id' ORDER by property, priority", '');
        //if (is_array($tab)) {
        //    $props_from_db = array_merge($props_from_db, $tab);
        //}
        //
        //// first prepare value array
        //$prop_arr = array();
        //foreach ( $props_from_db as $v ) {
        //    $prop_arr[$v['property']][] = $v['value'];
        //}

        foreach ($properties as $property_id => $property) {
            $prop_val = '';
            if (is_array($prop_arr[$property_id])) {
                foreach ($prop_arr[$property_id] as $val) {
                    if ($property->isObject()) {
                        if (preg_match('/^[0-9a-f]{32}$/', $val)) {
                            // stored as object (subclass of AA_Object in prvious save())
                            $tmp_val = AA_Object::load($val, $property->getType());
                        } else {
                                // stores as serialized state
                            $tmp_val = AA_Storable::factoryFromState($property->getType(),  unserialize($val));
                        }
                    } else {
                        $tmp_val = $val;
                    }
                    if (!$property->isMulti()) {
                        $prop_val = $tmp_val;
                        break;  // next property
                    }
                    if (!is_array($prop_val)) { // PHP 7.1 needs to convert var to array before use
                        $prop_val = [];
                    }
                    $prop_val[] = $tmp_val;
                }
            }
            // final assignment
            $obj->$property_id = $prop_val;
        }

        // standard object properties
        $obj->setName($prop_arr['aa_name'][0]);
        $obj->setOwnerId($prop_arr['aa_owner'][0]);
        return $obj;
    }


   ///** query function
   // * Finds object IDs for objects given by conditions
   // *
   // *   @param string        $type   - object type
   // *   @param $set
   // *   @param zids          $restrict_zids - use it if you want to choose only from a set of ids
   // *   @return A zids object with a list of the ids that match the query.
   // *
   // *   @global  bool $debug (in) many debug messages
   // *   @global  bool $nocache (in) do not use cache, even if use_cache is set
   // */
   //    // @todo !!! - rewrite it.
   //    // do the same as in queryZids for any object
    static function querySet($type, $set, $restrict_zids=null) {

        $owners = $set->getModules();
        $conds  = $set->getConds();
        $sort   = $set->getSort();

        if (is_object($restrict_zids) AND ($restrict_zids->count() == 0)) {
            return new zids(); // restrict_zids defined but empty - no result
        }

        $properties = $type::getClassProperties();
        $properties['aa_name']  = self::getPropertyObject('aa_name');
        $properties['aa_owner'] = self::getPropertyObject('aa_owner');
        $properties['aa_type']  = self::getPropertyObject('aa_type');

        // parse conditions ----------------------------------
        $tables_counter = ['object_text'=>1, 'object_integer'=>0, 'object_float'=>0];
        $tables         = [];

        $tables['t0']['cond'] = "t0.property='aa_type' AND t0.value='".quote($type)."'";
        $tables['t0']['join'] = 'object_text as t0';

        if ( !empty($owners) ) {
            $tables['t1']['cond'] = "t1.property='aa_owner' AND ". CVarset::sqlin('t1.value', $owners);
            $tables['t1']['join'] = "LEFT JOIN object_text as t1 ON (t1.object_id=t0.object_id AND t1.property='aa_owner')";
            $tables_counter['object_text']++;
        }

        $sortable = [];

        // Conditions
        foreach ($conds as $cond) {
            // fill arrays according to this condition
            $cond_flds   = [];
            $store       = '';
            foreach ( $cond as $fid => $v ) {
                if ( $GLOBALS['CONDS_NOT_FIELD_NAMES'][$fid] ) {
                    continue;      // it is not field_id parameters - skip it for now
                }

                $field = $properties[$fid];
                if ( empty($field) OR ($v=="")) {
                    AA::$debug&2 && AA::$dbg->log("skipping $fid in conds[]: not known $fid or empty condition");
                    continue;
                }

                $field_store = AA_Property::storageType($field->getType());
                if ( empty($field_store) ) {
                    AA::$debug&2 && AA::$dbg->log("skipping $fid in conds[]: no storage table (is it object?)");
                    continue;
                }
                // will not work with one condition for columns of different types (text/int/...) - which is right, I think.
                $store = $field_store;
                $cond_flds[] = $fid;
            }
            if ( !empty($cond_flds) ) {
                $tbl = (($store == 'object_text') ? 't' : (($store == 'object_integer') ? 'i' : 'f')) . $tables_counter[$store]++;

                // fill arrays to be able construct select command
                $tables[$tbl]['cond'] = (new AA_Dbcondition("$tbl.value", $cond['operator'], $cond['value']))->getWhereExp();
                $tables[$tbl]['join'] = "LEFT JOIN $store as $tbl ON ($tbl.object_id=t0.object_id AND ". CVarset::sqlin("$tbl.property", $cond_flds).')'; // OR $tbl.property is NULL))"; - like in content
                if (count($cond_flds) == 1) {
                    // mark this field as sortable (store without apostrofs)
                    $sortable[ reset($cond_flds) ] = $tbl;
                }
            }
        }


        // Sorting
        $select_order    = [];
        $select_distinct = '';
        foreach ($sort as  $sort_no => $srt) {
            if (key($srt)=='limit') {
                next($srt);       // skip the 'limit' record in the array
            }

            $fid = key($srt);

            // random sorting by following url parameters:
            //    sort[0]=random
            //    sort[0]=category........&sort[1]=random
            //    /apc-aa/view.php3?vid=13&cmd[13]=c-1-1&set[13]=sort-random
            // This operatin is quite slow in MySQL, so if you need just
            // one random item (for banner, ...), you should rather use
            // set[]=random-1 parameter for view.php3
            if ( $fid == 'random' ) {
                $select_order[] = 'RAND()';

                // break! - we do not want to create expressions like
                //    ORDER BY RAND(),item.publish_date DESC
                // bacause it makes no sense
                // (on the other hand the following expressions are perfectly OK:
                //    ORDER BY s0, RAND()
                break;
            }

            $field = $properties[$fid];

            if ( empty($field)) {
                AA::$debug&2 && AA::$dbg->log("skipping $fid in sort[]: not known $fid");
                continue;
            }

            if ( !$sortable[ $fid ] ) {           // this field is not joined, yet
                $store = AA_Property::storageType($field->getType());
                if ( empty($store) ) {
                    AA::$debug&2 && AA::$dbg->log("skipping $fid in sort[]: no storage table (is it object?)");
                    continue;
                }

                $tbl = (($store == 'object_text') ? 't' : (($store == 'object_integer') ? 'i' : 'f')) . $tables_counter[$store]++;
                $tables[$tbl]['join'] = "LEFT JOIN $store as $tbl ON ($tbl.object_id=t0.object_id AND ". CVarset::sqlin("$tbl.property", $fid).')'; // OR $tbl.property is NULL))"; - like in content

                // mark this field as sortable (store without apostrofs)
                $sortable[$fid] = $tbl;
            }

            // join constant table if we want to sort by priority
            $tab_field_id   = $sortable[$fid] .'.value';
            $select_order[] = $tab_field_id . (stristr(current( $srt ), 'd') ? ' DESC' : '');

            if ($srt['limit']) {
                // select_distinct added in order we can group by multiple value fields
                // (items are shown more times)
                $select_distinct .= ", $tab_field_id";
            }
        }

        // construct query --------------------------
        $SQL        = "SELECT DISTINCT t0.object_id as objectid $select_distinct FROM ";
        foreach ($tables as $tbl => $table ) {
            $SQL .= $table['join'] .' ';
        }

        $SQL .= " WHERE ";

        if (is_object($restrict_zids)) {
            $SQL .= $restrict_zids->sqlin() ." AND ";
            // just to keep order
            $select_order[] = "field(objectid,". implode(",",array_map("qquote", $restrict_zids->longids())). ')';
        }

        $delim = '';
        foreach ($tables as $tbl => $table ) {
            if ($table['cond']) {
                $SQL .= " $delim (". $table['cond'] .' )';
                $delim = 'AND';
            }
        }

        if ( count($select_order) ) {                                // order ----------
            $SQL .= " ORDER BY ". implode(', ', $select_order);
        }
        // not cached result
        return GetZidsFromSQL( $SQL, 'objectid', 'l');
    }

    /**
     * @param $properties
     * @return \AA\Util\Searchfields
     */
    protected static function getSearchfields($properties) {
        $i   = 0;
        $searchfields = new AA\Util\Searchfields();
        $props = array_merge(['aa_name' => self::getPropertyObject('aa_name')], $properties);
        //if (!isset($properties['aa_name']))  { $properties['aa_name']  = self::getPropertyObject('aa_name'); }
        //if (!isset($properties['aa_owner'])) { $properties['aa_owner'] = self::getPropertyObject('aa_owner'); }
        //if (!isset($properties['aa_type']))  { $properties['aa_type']  = self::getPropertyObject('aa_type'); }

        foreach ($props as $prop_id => $property) {
            if ($property->isObject()) {
                continue;
            }

            $field_type = $property->getType();    // @todo - convert to right search values

            switch ($field_type) {
                case 'int': $field_type = 'numeric'; break;

                case 'text':
                case 'numeric':
                case 'date':
                case 'constants':
                case 'numconstants':
                          break;

                // case 'string':
                default:
                      $field_type = 'text';
            }

            // we can hide the field, if we put in fields.search_pri=0
            $search_pri = ++$i;
                               //             $name,        $field,       $operators, $table, $search_pri, $order_pri
            $searchfields->add($prop_id, $property->getName(), $prop_id, $field_type, false, $search_pri, $search_pri);
        }
        return $searchfields;
    }

    /** generateAliases
     */
    static function _generateAliases($properties) {
        // the order is important - must be in the same order as search fields (for getManagerConf)
        $aliases["_#AA_NAME_"] = GetAliasDef( "f_1", 'aa_name', 'aa_name');
        foreach ($properties as $prop_id => $property) {
            if ($property->isObject()) {
                continue;
            }
            // @todo - make alias field type aware
            $aliases["_#". substr(str_pad(strtoupper($prop_id),8,'_'),0,8)] = GetAliasDef( 'f_h:, ', $prop_id, $prop_id);
        }
        $aliases["_#AA_ID___"] = GetAliasDef( "f_1", 'aa_id',   'aa_id');
        $aliases["_#AA_OWNER"] = GetAliasDef( "f_1", 'aa_owner','aa_owner');
        $aliases["_#AA_OW_NM"] = GetAliasDef( "f_t:{modulefield:{_#AA_OWNER}:name}", 'aa_owner','aa_owner');
        return $aliases;
    }

    /**
     *  @static
     */
    function getContent($settings, $zids) {
        $ret        = [];
        $class      = $settings['class'];
        $properties = $class::getClassProperties();

        foreach ($zids as $id) {
            $content = new AA_Content;

            $obj     = $class::load($id);

            if (is_null($obj)) {
                throw new Exception('object not loaded: '. $id);
            }
            foreach ($properties as $prop_id => $property) {
                // @todo - make alias field type aware
                $content->setAaValue($prop_id, AA_Value::factory($obj->getProperty($prop_id)));
            }
            $content->setAaValue('aa_name',  new AA_Value( $obj->getName() ));
            $content->setAaValue('aa_id',    new AA_Value( $obj->getId() ));
            $content->setAaValue('aa_owner', new AA_Value( $obj->getOwnerId() ));

            $ret[$id] = $content;
        }
        return $ret;
    }

    /** Manager top HTML
     *  could be changed in child classes
     * @param AA\Util\Searchfields $fields
     * @return string
     */
    protected static function getManagerTopHtml($fields) {
        return '
          <table>
            <tr>
              <th>'.join("</th>\n<th>", array_merge( [_m('Action')], $fields->getFieldnamesArray(), [_m('ID'), _m('Owner ID'), _m('Owner')])).'</th>
            </tr>
            ';
    }

    /** Manager row HTML
     *  could be changed in child classes
     * @param AA\Util\Searchfields $fields
     * @param $aliases
     * @param $links
     * @return string
     */
    protected static function getManagerRowHtml($fields, $aliases, $links) {
        return '
            <tr>
              <td style="white-space:nowrap;">'.
                   a_href($links['Edit'], _m('Edit'), 'aa-button-edit').' '.
                   a_href($links['Delete'], _m('Delete'), 'aa-button-delete', ['onclick'=>"return confirm('". _m("Do you really want to delete this object?"). "')"]).' '.
//                   a_href($links['Run'], _m('Export'), 'aa-button-show').
             '</td>          
              <td>'.join("</td>\n<td>", array_keys($aliases)).'</td>
            </tr>
            ';
    }

    /** generate manager from object structure
     * @static
     * @param string                 $manager_url
     * @param AA_Manageractions? $actions
     * @param AA_Manageractions? $switches
     * @return array
     */
    static function getManagerConf($manager_url, $actions=null, $switches=null) {
        $object_class  = get_called_class();
        $properties    = static::getClassProperties();

        $aliases       = AA_Object::_generateAliases($properties);


        $search_fields = AA_Object::getSearchfields($properties);
        $new_link      = a_href(get_admin_url('oedit.php3', ['otype' => $object_class, 'ret_url' => $manager_url]), GetAAImage('icon_new.gif', _m('new'), 17, 17).' '. _m('Add'));

        $links  = [
                   'Edit'=>get_admin_url('oedit.php3', ['module_id=_#AA_OWNER', 'oid=_#AA_ID___', 'otype' => $object_class, 'ret_url' => $manager_url]),
                   'Delete'=>get_admin_url('oedit.php3', ['module_id=_#AA_OWNER', 'oid=_#AA_ID___', 'otype' => $object_class, 'ret_url' => $manager_url, 'delete' => '1']),
//                   'Run'=>get_aa_url('export.php', ['id=_#AA_ID___'], false)
        ];

        $manager_settings = [
             'show'      =>  MGR_ALL & ~MGR_SB_BOOKMARKS,    // MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS | MGR_SB_ALLTEXT | MGR_SB_ALLNUM
             'searchbar' => [
                 'fields'               => $search_fields,
                 'search_row_count_min' => 1,
                 'order_row_count_min'  => 1,
                 'add_empty_search_row' => true,
                 'function'             => false  // name of function for aditional action hooked on standard filter action
//                 'default_sort'         => array( 0 => array('time' => 'd'))  // it would be nice to add default sort, but we do not have creation_time or something like that
             ],
             'scroller'  => [
                 'listlen'              => 200
             ],
             'itemview'  => [
                 'manager_vid'          => false,    // $slice_info['manager_vid'],      // id of view which controls the design
                 'format'               => [    // optionaly to manager_vid you can set format array
                     'compact_top'      => '<div class="aa-table aa-items-manager">'. static::getManagerTopHtml($search_fields),
                     'category_sort'    => false,
                     'category_format'  => "",
                     'category_top'     => "",
                     'category_bottom'  => "",
                     'even_odd_differ'  => false,
                     'even_row_format'  => "",
//                   'odd_row_format'   => static::getManagerRowHtml($search_fields, $aliases, array('Edit'=>get_admin_url('oedit.php3', array('module_id=_#AA_OWNER', 'oid=_#AA_ID___', 'otype' => $object_class, 'ret_url' => $manager_url)),'Delete'=>get_admin_url('oedit.php3', array('oid=_#AA_ID___', 'otype' => $object_class, 'ret_url' => $manager_url, 'delete' => '1')))),
                     'odd_row_format'   => static::getManagerRowHtml($search_fields, $aliases, $links),
                     'compact_remove'   => "",
                     'compact_bottom'   => "</table></div><br>". $new_link,
                     'noitem_msg'       => _m('No object found'). '<br>'. $new_link
                 ],
                 'fields'               => $search_fields,
                 'aliases'              => $aliases,
                                           //    static class method               , first parameter to the method
                 'get_content_funct'    => [['AA_Object', 'getContent'], ['class'=>$object_class]]
             ],
             'actions'   => $actions,
             'switches'  => $switches,
             'bin'       => 'app',
             'messages'  => [
                 'title'       => _m('Manage %1', [$object_class])
             ]
        ];

        return $manager_settings;
    }

    /** AA_iEditable method - creates Object from the form data */
    public static function factoryFromForm($oowner, $otype=null) {
        $grabber = new ObjectForm();
        $grabber->prepare();    // maybe some initialization in grabber
        // we expect just one form - no need to loop through contents
        $content    = $grabber->getContent();
        // while ($content = $grabber->getContent())
        $store_mode = $grabber->getStoreMode();        // add | update | insert
        $grabber->finish();    // maybe some finalization in grabber


        // specific part for form
        $object = new $otype;
        $object->setNew($content->getId(), $oowner, $content->getName());

        // self didn't give us the calling class and we do not have late statis bindings in PHP < 5.3
        $props = is_null($otype) ? static::getClassProperties() : $otype::getClassProperties();

        foreach ($props as $name => $property) {
            $object->$name = $property->toValue($content->getAaValue($name));
        }

        return $object;
    }

    /** AA_iEditable method - adds Object's editable properties to the $form */
    public static function addFormrows($form) {

//        $form->addRow(new AA_Formrow_Defaultwidget(AA_Object::getPropertyObject('aa_name')));  // use default widget for the field
        if (static::USES_NAME) {
            AA_Object::getPropertyObject('aa_name')->addPropertyFormrows($form);  // use default widget for the field
        }

        // self didn't give us the calling class and we do not have late static bindings in PHP < 5.3
        $props = static::getClassProperties();
        foreach ($props as $name => $property) {
            $property->addPropertyFormrows($form);
        }
        return $form;
    }
}

/** With this interface the object can be edited by AA_Form */
interface AA_iEditable {
    /** AA_iEditable method - adds Object's editable properties to the $form */
    public static function addFormrows($form);
    /** AA_iEditable method - creates Object from the form data */
    public static function factoryFromForm($oowner, $otype=null);
    /** AA_iEditable method - save the object to the database
     *  @return string|bool - id of saved data or false
     */
    public        function save();
}

/** Components (plugins) manipulation class */
class AA_Components {

    /// Static ///

    /** Used parameter format (in fields.input_show_func table)
     *  @todo - specify the parameters better - value type, used widget, ... so
     *          we could generate Parameter wizard (and validation) from those
     *          informations
     */


    /** getClassNames function
     *  Return names of all known AA classes, which begins with $mask with "type" as key
     *  static function
     * @param $mask
     * @return array
     */
    static function getClassNames($mask)
    {
        $right_classes = [];
        if (false !== $pos = strrpos($mask, '\\')) {  // new composer approach with autoload - we can't use  get_declared_classes()
            $classfilter = substr($mask,$pos+1);
            $classdir    = substr($mask,3,$pos-3);

            $dirname = AA_BASE_PATH.'src/'.$classdir;
            $files = AA_Directory_Wrapper::wrapper($dirname)->getFileNames();
            foreach ($files as $file) {
                $name = str_replace(['.php'], [''],$file);

                // filter out other possible classes
                if (!$name OR ($name=='Abstract')) {
                    continue;
                }

                if (strlen($shortcut = strtolower(str_replace($classfilter,'',$name)))) {
                    $right_classes[strtolower($shortcut)] = 'AA\\'. $classdir.'\\'.$name;
                }
            }
            return $right_classes;
        } else {
            $mask_length = strlen($mask);
            $right_class_names = array_filter(get_declared_classes(), function ($classname) use ($mask_length, $mask) {
                return substr($classname, 0, $mask_length) == $mask;
            });
            $right_classes = [];
            foreach ($right_class_names as $classname) {
                $right_classes[strtolower(substr($classname, $mask_length))] = $classname;
            }
            return $right_classes;
        }
    }

    /** get 'cod' from AA\Widget\CodWidget
     *  called as:  AA_Components::getClassType('Widget',get_called_class());
     *              AA_Components::getClassType('Widget',get_class($widget))
     * @param string $mask class start like
     * @param string $classname - probably from get_called_class()
     * @return string
     */
    static function getClassType($mask, $classname) {
        return strtolower(str_replace($mask,'',substr($classname, strrpos($classname, '\\') + 1)));
    }

    /** returns sorted array for selectbox (class => name)
     * @param  string $mask
     * @return array of class => name
     */
    static function getClassArray($mask) {
        $classes = self::getClassNames($mask);
        $arr = array_map(function($v) {return $v::name();}, $classes);
        $arr = array_filter($arr, 'trim');  // remove all with empty name()
        asort($arr);
        return $arr;
    }

    /** getSelectionCode function
     * @param $mask
     * @param $input_id
     * @param $params
     * @return string
     */
    static function getSelectionCode($mask, $input_id, &$params) {
        $options      = ['AA_Empty' => _m('select ...')];
        $html_options = ['AA_Empty' => ''];
        foreach (AA_Components::getClassNames($mask) as $selection_class) {
            // call static class methods
            $options[$selection_class]      = $selection_class::name();
            $html_options[$selection_class] = $selection_class::htmlSetting($input_id, $params);
        }
        return getSelectWithParam($input_id, $options, "", $html_options);
    }
}


