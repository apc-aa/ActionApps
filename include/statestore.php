<?php

/**
 * File contains definition of AA_Storable class - abstract class which
 * implements two methods for storing and restoring class data (used in
 * searchbar class, manager class, ...
 *
 * Should be included to other scripts (as /include/searchbar.class.php)
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
use AA\Managed;
use AA\Manageraction\Manageractions;
use AA\Manageraction\Object_Delete;
use AA\Util\AbstractAliasgenerator;
use AA\Util\Searchfields;
use AA_Plannedtask;

/** allows to serialize objects
 * @method expand(false|mixed|string $param)
 */
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
     *   AA_Stringexpand::factoryByName('', $params);
     * @param string $name
     * @param array  $params
     * @param null $class_mask
     * @return AA_Serializable
     */
    static function factoryByName($name, $params=null, $class_mask=null) {
        return self::factory(static::constructClassName($name, $class_mask), $params);
    }

    /** factoryByString function
     *  Creates an object from the string, which is used for storing setting in
     *  the database (older approach). The string looks like:
     *    dte:1:10:1
     *  which means, that it is the instance of AA\Widget\DteWidget (when $class_mask === 'AA\Widget\Widget')
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
        // ask class which parameters uses and fill it
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
     * @return AA_Property[]
     * @see AA_Components
     */
    public static function getClassProperties(): array {
        // array of AA parameters (can't be object's data, since we need
        // to call it statically (as class method)
        return [];
    }

    /** Returns array of persistent slots (AA_property)
     *  Uses getClassProperties() method of the classes from which it grabs
     *  all persistent properties
     */
    protected static function getPersistentProperties(): array {
        return array_filter(static::getClassProperties(), static fn ($property) => $property->isPersistent());
    }

    /** create the name of class from the type and name
     *  static class method
     * @param string $name
     * @param string $class_mask
     * @return string
     */
    static function constructClassName($name, $class_mask=null): string {
        $name       = ucwords(strtolower(str_replace('-', '', $name)));    // str_replace probably for validator e-mail, but maybe not necessary
        $class_mask = rtrim($class_mask,'_') ?: get_called_class();
        if (($pos=strrpos($class_mask, '\\')) !== false) {
            return substr_replace($class_mask, "\\$name", $pos, 1);
        }
        return $class_mask . '_' . $name;
    }

    /** Parses class parameters from the string, which is stored in the database
     *  Typical use is for fields.input_show_func, where parameters are stored
     *  as string in the form: fnc:const:param
     *  @param string $string
     *  @return array - associative array of parameters, the name of parameters is given
     *  by the class itself ($class_mask . fnc).
     */
    public static function parseClassProperties($string): array {

        // the first parameter is the class identifier - the parameters start then
        [$class_shortcut, $prop_string] = explode(':', $string, 2);
        $class  = self::constructClassName($class_shortcut, get_called_class().'_');
        $params = ['class' => $class];

        if ( class_exists($class) ) {
            // ask class which parameters uses - call AA\Widget\TxtWidget::getClassProperties()), for example
            $params = array_merge($params, self::parseProperties((string)$prop_string, $class::getClassProperties(), true));
        }
        return $params;
    }

    /**
     * @param string $string
     * @param array  $props
     * @param bool   $join_last
     * @return array
     */
    public static function parseProperties(string $string, iterable $props, bool $join_last=false): array {
        // we do not use ParamExplode() - I  do not like the http:// replacement there
        $split = explode('##Sx', str_replace(['#:', ':', '~@|_'], ['~@|_', '##Sx', ':'], $string));

        $params = [];
        $name = '';
        foreach ($props as $name => $property) {
            $value = trim(array_shift($split) ?? '');
//                if (isset($value)) {
            $params[$name] = $property->isMulti() ? json2asoc($value) : $value;  // const_arr for widget uses this, for example
            //$params[$name] = $property->isMulti() ? json2arr($value,true) : $value;  // const_arr for widget uses this, for example
            //$params[$name] = $value;
//                }
        }
        // is there rest? Add all the values to the last parameter
        // It is there for older 1-parameter classes, where we do not escape ":"
        // like AA_Generator_Txt - we want it as it was written - not split
        if ($join_last AND count($split) AND $name) {
            $params[$name] .= ':' . implode(':', $split);
        }
        return $params;
    }

    /** Reverse to parseClassProperties */
    public function serializeToString(): string {
        $class_shotcut = AA_Components::getClassType(get_called_class(), 'AA_Logentry_');
        $parts = [$class_shotcut];
        $props = static::getPersistentProperties();

        foreach ($props as $name => $property) {
            $value = $this->$name;
            if (is_array($value)) {
                $value = match (count($value)) {
                    0       => '',
                    1       => $value[0],
                    default => json_encode($value),
                };
            } elseif (!is_scalar($value)) {
                warn("property {$name} not storable");
                $value = '';
            }
            $parts[] = $value;
        }
        return ParamImplode($parts);
    }
}



/**
 * AA_Storable - abstract class which implements methods for storing and
 * restoring class data (used in searchbar class, manager class, ...).
 *
 * If you want to use storable methods in your class, you should derive the new
 * class from AA_Storable. Then the property should be marked as persistent
 * where you specify all the variables you want to store. Then you just call
 * getState() and setFromState() methods for storing and restoring object's data
 */
class AA_Storable extends AA_Serializable {

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

    public static function factoryFromState($type, $state) {
        if ((strlen($state['aa_class']) > 0) AND str_starts_with($state['aa_class'], $type)) {
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
        // we need the exact class in order we can factor the object from state
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
            throw new \RuntimeException('No owner set for property '. $this->aa_id. ' - '. $this->aa_name);
        }

        $this->delete();
        $object_id = $this->getId();

        $props = static::getClassProperties();
        foreach ($props as $property_id => $property) {
            //$property_id   = $property->getId();
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

    /** Update just selected properties
     * @param  array  $prop_names  like ['status', 'status_text']
     * @throws Exception
     */
    public function update(array $prop_names): void
    {
        // @todo DB Transaction start
        static::deleteObjects([$this->getId()], $prop_names);
        $props = static::getClassProperties();
        $object_id = $this->getId();
        foreach ($prop_names as $property_id) {
            $props[$property_id]?->save($this->$property_id, $object_id, $this->getOwnerId());
        }
    }

    /** will be called after saving of the object
     *  could do some cleanup work in child classes (like AA_Plannedtask)
     **/
    function aftersave() {}

    public static function getPropertyObject($property_id) {
        return match ($property_id) {
            'aa_type'       => new AA_Property('aa_type', 'Object type', 'string', false, true, 'string', true),
            'aa_name'       => new AA_Property('aa_name', 'Name', 'string', false, true, 'string', false),
            'aa_owner'      => new AA_Property('aa_owner', 'Owner', 'string', false, true, 'id', true),
            'aa_version'    => new AA_Property('aa_version', 'Version', 'int', false, true, 'int', false),
            'aa_subobjects' => new AA_Property('aa_subobjects', 'Subobjects', 'string', true, true, 'string', false),     // helper field aa_subobjects used for quicker load of object
            'aa_id'         => new AA_Property('aa_id', 'Id', 'string', false, true, 'id', true),
            default         => null,
        };
    }

    protected function sanitizeProperties() {
        $props = static::getClassProperties();
        foreach ($props as $var => $property) {
            $this->$var = $property->sanitizeValue($this->$var);
        }
    }

    /** delete function
     *  Deletes the object from the database including all the subobjects
     */
    function delete() {
        self::deleteObjects([$this->getId()]);
    }

    // array of unpacked ids
    public static function deleteObjects($object_ids, array $properties=[]): bool
    {
        // objects consists from object itself and subobjects
        $subobjects_props = self::loadProperties($object_ids, 'aa_subobjects');
        $object_ids = array_merge($object_ids, ...$subobjects_props);
        if (count($object_ids = array_unique($object_ids))) {
            $cond = [['object_id', $object_ids]];
            if ($properties) {
                $cond[] = ['property',$properties];
            }
            DB_AA::delete_low_priority('object_text',    $cond);
            DB_AA::delete_low_priority('object_integer', $cond);
            DB_AA::delete_low_priority('object_float',   $cond);
        }
        return true;
    }

    /** Get all Owners objects (for deletion) */
    public static function getOwnersObjects($owner_id) {
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
                    if ( $v instanceof AA_Object) {
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
                   AND o2.property = 'aa_owner' AND ". Cvarset::sqlin('o2.value', $owner) ."
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
        static $cache = []; // cache for loaded objects - AA_Plannedtask was loaded multiple times for multiple storeItem() -> executeForEvent()

        // AA::$dbg->log($id, $type);

        if ($type) {
            $key = "$id|$type";
            if (isset($cache[$key])) {
                return $cache[$key];
            }
        }

        // AA::$dbg->log("$key - not cached", count($cache)  );

        // @todo optimize the load
        //    - get used tables from properties,
        //    - load object from database in one step using aa_subobjects property of the objects
        if ( (!$type AND !($type = self::loadProperty($id, 'aa_type'))) OR !class_exists($type)) {
            return null;
        }
        $key = "$id|$type";

        $obj = new $type;
        $obj->setId($id);
        $properties = $type::getClassProperties();

        // for speedup - we will check DB only for used types
        $storegetypes = [];
        foreach ($properties as $property) {
            $storegetypes[AA_Property::storageType($property->getType())] = true;
        }

        $prop_arr =                            DB_AA::select( ['property'=> '+value'], 'SELECT `property`, `value` FROM `object_text`',    [['object_id', $id]], ['property', 'priority']);
        if ($storegetypes['object_integer'] ) {
            $prop_arr = array_merge($prop_arr, DB_AA::select(['property' => '+value'], 'SELECT `property`, `value` FROM `object_integer`', [['object_id', $id]], ['property', 'priority']));
        }
        if ($storegetypes['object_float'] ) {
            $prop_arr = array_merge($prop_arr, DB_AA::select(['property' => '+value'], 'SELECT `property`, `value` FROM `object_float`', [['object_id', $id]], ['property', 'priority']));
        }

        foreach ($properties as $property_id => $property) {
            $prop_val = '';
            if (is_array($prop_arr[$property_id])) {
                foreach ($prop_arr[$property_id] as $val) {
                    if ($property->isObject()) {
                        if (preg_match('/^[0-9a-f]{32}$/', $val)) {
                            // stored as object (subclass of AA_Object in prvious save())
                            $tmp_val = self::load($val, $property->getType());
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

        $cache[$key] = $obj;
        // AA::$dbg->log("$key - now cached", count($cache) );
        return $cache[$key];
    }


   ///** query function
   // * Finds object IDs for objects given by conditions
   // *
   // *   @param string|array  $type   - object type - could by in array ['class'=>$type] - for compatibility with Manager query_funct
   // *   @param $set
   // *   @param zids          $restrict_zids - use it if you want to choose only from a set of ids
   // *   @return A zids object with a list of the ids that match the query.
   // *
   // *   @global  bool $debug (in) many debug messages
   // *   @global  bool $nocache (in) do not use cache, even if use_cache is set
   // */
   //    // @todo !!! - rewrite it.
   //    // do the same as in queryZids for any object
    static function querySet($type, $set, ?zids $restrict_zids=null): zids
    {
        $type = is_array($type) ? $type['class'] : $type;

        $owners = $set->getModules();
        $conds  = $set->getConds();
        $sort   = $set->getSort();

        if (is_object($restrict_zids) AND !$restrict_zids->count()) {
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

        if (is_object($restrict_zids)) {
            $tables['t0']['cond'] .= " AND ". $restrict_zids->sqlin('t0.object_id', true);
            // just to keep order
            // $select_order[] = "field(objectid,". implode(",",array_map("qquote", $restrict_zids->longids())). ')';
        }

        if ( !empty($owners) ) {
            $tables['t1']['cond'] = "t1.property='aa_owner' AND ". Cvarset::sqlin('t1.value', $owners);
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
                if ( AA_Condition::CONDS_NOT_FIELD_NAMES[$fid] ) {
                    if ($fid === 'all_fields') {
                        $cond_flds[] = $fid;
                        $store = 'object_text';
                    } elseif ($fid === 'all_fields_numeric') {
                        $cond_flds[] = $fid;
                        $store = 'object_integer';
                    }
                    continue;      // it is not field_id parameters - skip it for now
                }

                $field = $properties[$fid];
                if ( empty($field) OR ($v=="")) {
                    AA::$debug&2 && AA::$dbg->log("skipping $fid in conds[]: not known $fid or empty condition");
                    continue;
                }

                $store = AA_Property::storageType($field->getType());
                if ( empty($store) ) {
                    AA::$debug&2 && AA::$dbg->log("skipping $fid in conds[]: no storage table (is it object?)");
                    continue;
                }
                // will not work with one condition for columns of different types (text/int/...) - which is right, I think.
                $cond_flds[] = $fid;
            }
            if ( !empty($cond_flds) ) {
                $tbl = (($store === 'object_text') ? 't' : (($store === 'object_integer') ? 'i' : 'f')) . $tables_counter[$store]++;

                // fill arrays to be able to construct select command
                $tables[$tbl]['cond'] = (new AA_Dbcondition($tbl, "value", $cond['operator'], $cond['value']))->getWhereExp();
                if ( in_array('all_fields', $cond_flds) OR in_array('all_fields_numeric', $cond_flds) ) {
                    $tables[$tbl]['join'] = "LEFT JOIN $store as $tbl ON ($tbl.object_id=t0.object_id)";
                } else {
                    $tables[$tbl]['join'] = "LEFT JOIN $store as $tbl ON ($tbl.object_id=t0.object_id AND " . Cvarset::sqlin("$tbl.property", $cond_flds) . ')'; // OR $tbl.property is NULL))"; - like in content
                }
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
            if (key($srt)==='limit') {
                next($srt);       // skip the 'limit' record in the array
            }

            $fid = key($srt);

            // random sorting by following url parameters:
            //    sort[0]=random
            //    sort[0]=category........&sort[1]=random
            //    /apc-aa/view.php?vid=13&cmd[13]=c-1-1&set[13]=sort-random
            // This operatin is quite slow in MySQL, so if you need just
            // one random item (for banner, ...), you should rather use
            // set[]=random-1 parameter for view.php
            if ( $fid === 'random' ) {
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

                $tbl = (($store === 'object_text') ? 't' : (($store === 'object_integer') ? 'i' : 'f')) . $tables_counter[$store]++;
                $tables[$tbl]['join'] = "LEFT JOIN $store as $tbl ON ($tbl.object_id=t0.object_id AND ". Cvarset::sqlin("$tbl.property", $fid) .')'; // OR $tbl.property is NULL))"; - like in content

                // mark this field as sortable (store without apostrophes)
                $sortable[$fid] = $tbl;
            }

            // join constant table if we want to sort by priority
            $tab_field_id   = $sortable[$fid] .'.value';
            $select_order[] = $tab_field_id . (stripos(current($srt), 'd') !== false ? ' DESC' : '');

            if ($srt['limit']) {
                // select_distinct added in order we can group by multiple value fields
                // (items are shown more times)
                $select_distinct .= ", $tab_field_id";
            }
        }

        // construct query --------------------------
        $SQL = "SELECT DISTINCT t0.object_id as objectid $select_distinct FROM ";
        foreach ($tables as $tbl => $table ) {
            $SQL .= $table['join'] .' ';
        }

        $SQL .= " WHERE ";

        $delim = '';
        foreach ($tables as $tbl => $table ) {
            if ($table['cond']) {
                $SQL .= " $delim (". $table['cond'] .' )';
                $delim = 'AND';
            }
        }

        if ( !count($select_order) ) {                                // order ----------
            $select_order[] = 't0.id DESC';                           // default order      );
        }
        $SQL .= " ORDER BY ". implode(', ', $select_order);

        // not cached result
        return GetZidsFromSQL( $SQL, 'objectid', 'l');
    }

    /**
     *
     */
    public static function getContent($zids, $settings=[]) {
        $ret        = [];
        $class      = $settings['class'];
        $properties = $class::getClassProperties();

        foreach ($zids as $id) {
            $content = new AA_Content;

            $obj     = $class::load($id);

            if (is_null($obj)) {
                throw new \RuntimeException('object not loaded: '. $id);
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

    /** abstract class for admin url to edit this object - used in AA Finder ...
     * @todo - create manageable interface? */
    protected static function getAdminUrlTemplate(): string {
        return '';
        // $url  = get_aa_url('modules/site/aliases.php', ['id=_#AA_ID___','module_id=_#AA_OWNER']);  // like this
    }

    /** AA Admin edit url for this object. In most cases no need to override this method if the getAdminUrlTemplate is set
     * @todo - create manageable interface? */
    public function getAdminUrl(): string {
        $url = static::getAdminUrlTemplate();
        return $url ? str_replace(['_#AA_ID___', '_#AA_OWNER'], [$this->getId(), $this->getOwnerId()], $url) : '';
    }

    /** Manager top HTML
     *  could be changed in child classes
     * @param AA\Util\Searchfields $fields
     * @return string
     */
    protected static function getManagerTopHtml($fields) {
        return '
          <table>
          <thead>
            <tr>
              <th scope=col>'.implode("</th>\n<th scope=col>", array_merge( ['', _m('Action')], $fields->getFieldnamesArray(), [_m('ID'), _m('Owner ID'), _m('Owner')])).'</th>
            </tr>
          </thead><tbody>
            ';
    }

    /** Manager row HTML
     *  could be changed in child classes
     * @param AA\Util\Searchfields $fields
     * @param $aliases AA_Aliases
     * @param $buttons
     * @return string
     */
    protected static function getManagerRowHtml($fields, $aliases, $buttons) {
        return '
            <tr>
              <td><input name="chb[x_#AA_ID___]" value="" type="checkbox"></td>
              <td style="white-space:nowrap;"> '. implode(' ', $buttons) .' </td>          
              <td>'.implode("</td>\n<td>", $aliases->getNames()).'</td>
            </tr>
            ';
    }

    /** generate manager from object structure
     * @static
     * @param Manageractions? $actions
     * @param Manageractions? $switches
     * @return Managed
     */
    static function getManagerConf(Manageractions $actions=null, Manageractions $switches=null): Managed {
        $object_class  = get_called_class();
        $aliases       = new AA_Aliases(new AA_Aliasgenerator_Object(static::class));
        $search_fields = Searchfields::factoryFromProperties(array_merge(['aa_name' => self::getPropertyObject('aa_name')], static::getClassProperties()));

        // MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS | MGR_SB_ALLTEXT | MGR_SB_ALLNUM
        $show              = $search_fields->containNumeric() ? (MGR_ALL & ~MGR_SB_BOOKMARKS) : (MGR_ALL & ~MGR_SB_BOOKMARKS & ~MGR_SB_ALLNUM);

        if (!is_object($actions)) {
            $actions   = new Manageractions;
        }
        $actions->addAction(new Object_Delete('DeleteObject'));

        $buttons  = [
            'Edit'   => a_href(get_url(Managed::MANGED_URL, [
                'id=_#AA_ID___',
                'module_id=_#AA_OWNER',
            ], true), _m('Edit'), ['class' => 'aa-button-edit']),
            //                   'Run'=>get_aa_url('export.php', ['id=_#AA_ID___'], false)
        ];
        $buttons = array_merge($buttons, $actions->getManagerButtons(Managed::MANGED_URL,'_#AA_ID___', '_#AA_OWNER'));

        $manager_settings = [
             'module_id' => AA::$module_id,
             'show'      =>  $show,
             'searchbar' => [
                 'fields'               => $search_fields,
                 'search_row_count_min' => 1,
                 'order_row_count_min'  => 1,
                 'add_empty_search_row' => true,
                 'function'             => false,  // name of function for additional action hooked on standard filter action
//                 'default_sort'         => array( 0 => array('time' => 'd'))  // it would be nice to add default sort, but we do not have creation_time or something like that
             ],
             'scroller'  => [
                 'listlen'              => 200,
             ],
             'itemview'  => [
                 'manager_vid'          => false,    // $slice_info['manager_vid'],      // id of view which controls the design
                 'format'               => [    // optionally to manager_vid you can set format array
                                                'compact_top'      => '<div class="aa-table aa-items-manager">'. static::getManagerTopHtml($search_fields),
                                                'category_sort'    => false,
                                                'category_format'  => "",
                                                'category_top'     => "",
                                                'category_bottom'  => "",
                                                'even_odd_differ'  => false,
                                                'even_row_format'  => "",
                                                'odd_row_format'   => static::getManagerRowHtml($search_fields, $aliases, $buttons),
                                                'compact_remove'   => "",
                                                'compact_bottom'   => "</tbody></table></div><br>",
                                                'noitem_msg'       => _m('No object found'). '<br>',
                 ],
                 //'fields'               => $search_fields,
                 'aliases'              => $aliases,
                                           //    static class method               , first parameter to the method
                 'get_content_funct'    => [['AA_Object', 'getContent'], ['class'=>$object_class]],
                 'query_funct'          => [['AA_Object', 'querySet'], ['class'=>$object_class]],
             ],
             'actions'   => $actions,
             'switches'  => $switches,
             'bin'       => 'app',
        ];

        return (new \AA\Managed_Object(static::class))->setSettings($manager_settings);
    }

    /** AA_iEditable method - creates Object from the form data */
    public static function factoryFromForm($oowner, $otype=null) {
        $grabber = new ObjectForm();
        $grabber->prepare();    // maybe some initialization in grabber
        // we expect just one form - no need to loop through contents
        $content    = $grabber->getItem();
        // while ($content = $grabber->getContent())
        $store_mode = $grabber->getStoreMode();        // add | update | insert
        $grabber->finish();    // maybe some finalization in grabber


        // specific part for form
        $object = new $otype;

        $object->setNew($content->getId(), $oowner, $content->getName());

        // self didn't give us the calling class and we do not have late static bindings in PHP < 5.3
        $props = is_null($otype) ? static::getClassProperties() : $otype::getClassProperties();

        foreach ($props as $name => $property) {
            $object->$name = $property->toValue($content->getAaValue($name));
        }

        return $object;
    }

    /** AA_iEditable method - adds Object's editable properties to the $form */
    public static function addFormrows(AA_Form $form): void {

//        $form->addRow(new AA_Formrow_Defaultwidget(AA_Object::getPropertyObject('aa_name')));  // use default widget for the field
        if (static::USES_NAME) {
            self::getPropertyObject('aa_name')?->addPropertyFormrows($form);  // use default widget for the field
        }

        // self didn't give us the calling class and we do not have late static bindings in PHP < 5.3
        $props = static::getClassProperties();
        foreach ($props as $name => $property) {
            $property->addPropertyFormrows($form);
        }
        // return $form;  // it is void method, for now
    }
}

/** cached object aliases
 *  @usage new AA_Aliases(new AA_Aliasgenerator_Object($slice_id))
 */
class AA_Aliasgenerator_Object extends AbstractAliasgenerator {
    protected $object_type;

    public function __construct(string $object_type) {
        $this->object_type = $object_type;
    }

    /** generate params array for getAliasesFromParams from real generator object */
    protected function getParamsArray(): array {
        return ['object_type'=>$this->object_type];
    }

    public static function getAliasesFromParams(array $params): AA_Aliases {
        $aliases = new AA_Aliases();

        // the order is important - must be in the same order as search fields (for getManagerConf)
        $aliases->addAlias(new AA_Alias('_#AA_NAME_', 'f_1', 'aa_name', 'aa_name'));
        $aliases->addFromProperties($params['object_type']::getClassProperties());   // do not move up or down - must be in the same order as search fields (for getManagerConf)
        $aliases->addAlias(new AA_Alias('_#AA_ID___', 'f_1', 'aa_id',   'aa_id'));
        $aliases->addAlias(new AA_Alias('_#AA_OWNER', 'f_1', 'aa_owner','aa_owner'));
        $aliases->addAlias(new AA_Alias('_#AA_OW_NM', 'f_t:{modulefield:{_#AA_OWNER}:name}', 'aa_owner','aa_owner'));
        return $aliases;
    }
}

/** With this interface the object can be edited by AA_Form */
interface AA_iEditable {
    /** AA_iEditable method - adds Object's editable properties to the $form */
    public static function addFormrows(AA_Form $form): void;
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

    /** getClassNames function
     *  Return names of all known AA classes, which begins with $mask with "type" as key
     *
     * @param               $mask
     * @param callable|null  $filter
     * @param  bool  $tolower
     *
     * @return array
     */
    static function getClassNames($mask, ?callable $filter=null, bool $tolower=true): array {
        $right_classes = [];
        if (false !== $pos = strrpos($mask, '\\')) {  // new composer approach with autoload - we can't use  get_declared_classes()
            $classfilter = substr($mask,$pos+1);
            $classdir    = substr($mask,3,$pos-3);

            $dirname = AA_BASE_PATH.'src/'.$classdir;
            $files = AA_Directory_Wrapper::wrapper($dirname)->getFileNames();
            foreach ($files as $file) {
                $name = str_replace(['.php'], [''],$file);

                // filter out other possible classes
                if (!$name OR str_starts_with($name, 'Abstract')) {  // We do not want Abstract... classes
                    continue;
                }

                if (($shortcut = str_replace($classfilter, '', $name)) !== '') {
                    $right_classes[$tolower ? strtolower($shortcut) : $shortcut] = 'AA\\'. $classdir.'\\'.$name;
                }
            }
        } else {
            $mask_length = strlen($mask);
            $right_class_names = array_filter(get_declared_classes(), static fn($classname) => str_starts_with($classname, $mask));
            foreach ($right_class_names as $classname) {
                $shortcut = substr($classname, $mask_length);
                $right_classes[$tolower ? strtolower($shortcut) : $shortcut] = $classname;
            }
        }
        if (is_callable($filter)) {
            $right_classes = array_filter($right_classes, $filter);
        }
        return $right_classes;
    }


    /** get 'Cod' from AA\Widget\CodWidget
     *  called as:  AA_Components::getClassType(get_called_class(),'Widget');
     *              AA_Components::getClassType(get_class($widget),'Widget')
     *              AA_Components::getClassType($class_name)
     * @param string $classname - probably from get_called_class()
     * @param string $mask      - removed class start (like 'Widget' from 'CodWidget')
     * @return string
     */
    static function getClassType(string $classname, string $mask = ''): string {
        $classname = array_slice(explode("\\", $classname), -1, 1)[0];
        return $mask ? str_replace($mask,'',$classname) : $classname;
    }

    /** returns sorted array for selectbox (class => name)
     * @param  string $mask
     * @return array of class => name
     */
    static function getClassArray($mask, $filter=null, $tolower=true): array {
        $classes = self::getClassNames($mask, $filter, $tolower);
        $arr = array_map(static fn ($v) => $v::name(), $classes);
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
        foreach (self::getClassNames($mask) as $selection_class) {
            // call static class methods
            $options[$selection_class]      = $selection_class::name();
            $html_options[$selection_class] = $selection_class::htmlSetting($input_id, $params);
        }
        return getSelectWithParam($input_id, $options, "", $html_options);
    }


    /** generate manager from object structure
     * @static
     * @param string              $mask          like 'AA\\Widget\\'
     * @param string              $base_class    like 'AA\\Widget\\Widget'
     * @param Manageractions|null $actions
     * @param Manageractions|null $switches
     * @return Managed
     */
    static function getManagerConf(string $mask, string $base_class, Manageractions $actions=null, Manageractions $switches=null): Managed {
        // trying to live without aliases
        // $aliases       = new AA_Aliases(new AA_Aliasgenerator_Object(static::class));
        // $aliases       = $table->generateAliases();

        $search_fields = Searchfields::factoryFromProperties($base_class::getClassProperties());

        // MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS | MGR_SB_ALLTEXT | MGR_SB_ALLNUM
        $show = (MGR_ALL & ~MGR_SB_BOOKMARKS & ~MGR_SB_ALLNUM);

        if (!is_object($actions)) {
            $actions   = new Manageractions;
        }
        // $actions->addAction(new Object_Delete('DeleteObject'));

        // $buttons  = [
        //     'Edit'   => a_href(get_url(Managed::MANGED_URL, [
        //         'id=_#AA_ID___',
        //         'module_id=_#AA_OWNER'
        //     ], true), _m('Edit'), ['class' => 'aa-button-edit']),
        //     //                   'Run'=>get_aa_url('export.php', ['id=_#AA_ID___'], false)
        // ];
        // $buttons = array_merge($buttons, $actions->getManagerButtons(Managed::MANGED_URL,'_#AA_ID___', '_#AA_OWNER'));
        // $buttons = array_merge($buttons, $actions->getManagerButtons('', $key_alias, AA::$module_id));
        $buttons = $actions->getManagerButtons(Managed::MANGED_URL,'_#AA_ID___', '');

        $columns = [];
        if (count($actions)) {
            $columns['_aa_chb'] = ['',"<input name=\"chb[x{aa_id}]\" value=\"\" type=checkbox>"];
        }
        if ($buttons) {
            $columns['_aa_action'] = [_m('Action'), implode(' ', $buttons)];
        }

        foreach ($search_fields as $fid => $fld) {
            $columns[$fid] = [$fld['name'], '{'.$fid.'}'];
        }

        $manager_settings = [
            'show'      =>  MGR_ALL & ~(MGR_SB_BOOKMARKS | MGR_SB_ALLNUM),
            'searchbar' => [
                'fields'               => $search_fields,
                'search_row_count_min' => 1,
                'order_row_count_min'  => 1,
                'add_empty_search_row' => true,
                'function'             => false,  // name of function for additional action hooked on standard filter action
            ],
            'scroller'  => [
                'listlen'              => 100,
            ],
            'itemview'  => [
                // 'fields'               => $search_fields,
                'aliases'              => new AA_Aliases(new AA_Aliasgenerator_Components($base_class)),
                //    static class method               , first parameter to the method
                'get_content_funct'    => [['AA_Components', 'getContent'], ['mask'=>$mask]],
                'query_funct'          => [['AA_Components', 'querySet'],   ['mask'=>$mask, 'class'=>$base_class]],
            ],
            'actions'   => $actions,
            'switches'  => $switches,
            'bin'       => 'app',
        ];

        return (new \AA\Managed())->setSettings($manager_settings)->setZidsType('w')->setColumns($columns)->setTitle(_m('Manage components'));
        // return (new \AA\Managed_Object(static::class))->setSettings($manager_settings);
    }

    /** getContent function for loading content of specified table for manager class
     *
     * @param zids $zids     array if ids to get from database
     * @param array $settings array - just one parameter: mask
     * @return AA_Content[] - Abstract Data Structure containing the links data
     *                        {@link https://actionapps.org/faq/#1337}
     * @static
     * @see Manager, GetItemContent(), itemview class, item class
     */
    static function getContent($zids, $settings): array {
        $ret = [];
        $mask = $settings['mask'];
        $classes = self::getClassNames($mask, null, false);

        foreach ($zids as $id) {
            if (array_key_exists($id, $classes)) {
                $class   = $classes[$id];
                $content = new AA_Content;
                $content->setId($id);
                $class::contentAdd($content);
                // $content->setAaValue('aa_owner', new AA_Value( $obj->getOwnerId() ));
                $ret[$id] = $content;
            }
        }
        return $ret;
    }

    /** function to add content fields to getContent of Component for each type of Components (for Manager)*/
    public static function contentAdd(AA_Content $content) {}

    /**  - Finds link IDs for links according to given  conditions
     * @param array  $settings - array - just one parameter: table, where to search
     * @param AA_Set $set - AA_Set object which specifies Sortorder and Conditions
     *                          - there we store also BINs conditions, since each
     *                            table/module can use different Bins AND for other
     *                            the idea of BINs makes no sense at all
     * @global bool  $debug =1       - many debug messages
     * @return zids
     */
    static function querySet($settings, $set): zids
    {
        $mask       = $settings['mask'];
        $base_class = $settings['class'];
        $arr        = self::getClassArray($mask, null, false);
        $contents   = self::getContent(new zids(array_keys($arr),'w'), ['mask'=>$mask]);
        $tablename  = 'tmp_components_'. array_slice(explode("\\", strtolower(trim($mask,"\\"))), -1, 1)[0];

        new AA_Temporary_Table($tablename, $base_class::getClassProperties(), $contents);

        $conds    = $set->getConds();
        $sort     = $set->getSort();

        $FIELDS = Searchfields::factoryFromProperties($base_class::getClassProperties())->getArrayDeprecated();
        $where_sql      = MakeSQLConditions($FIELDS, $conds, 'LIKE', $foo);
        $order_by_sql   = MakeSQLOrderBy(   $FIELDS, $sort,  $foo);

        $SQL  = "SELECT DISTINCT `aa_id` FROM `$tablename` WHERE 1=1 ";
        $SQL .=  $where_sql . $order_by_sql;

        // get result --------------------------
        return GetZidsFromSQL($SQL, 'aa_id', 'w');
    }
}

class AA_Temporary_Table {
    private $tablename;

    /** @var AA_Property[]  */
    private array $properties;

    /**
     * @param string $tablename
     * @param AA_Property[] $properties
     * @param AA_Content[]  $contents
     */
    public function __construct(string $tablename, array $properties, array $contents) {
        $this->tablename  = $tablename;
        $this->properties = $properties;
        $this->create($contents);
    }

    /**
     * @param AA_Property[] $properties
     * @return void
     */
    private function fieldsFromProperties(): array {
        $fields = [];
        foreach ($this->properties as $prop_id => $property) {
            if ($property->isObject()) {
                continue;
            }
            $fields[] = match ($property->getType()) {
                'int'   => "$prop_id INT",
                default => "$prop_id VARCHAR(255)",
            };
        }
        return $fields;
    }

    /**
     * @param AA_Content[] $contents
     * @return void
     */
    private function create(array $contents) {
        $fields = implode(',', $this->fieldsFromProperties());
        $SQL = "CREATE TEMPORARY TABLE IF NOT EXISTS {$this->tablename} ({$fields})";
        DB_AA::sql($SQL);
        ($metabase = AA::Metabase())->addTable(AA_MbT::factoryFromDb($this->tablename));

        foreach($contents as $content) {
            $metabase->doInsert($this->tablename, $content->getAllAsScalar(', '));
        }
    }
}


/** cached Components aliases
 *  @usage (new AA_Aliasgenerator_Components())->getAliases()
 */
class AA_Aliasgenerator_Components extends AbstractAliasgenerator {
    protected string $base_class;

    public function __construct(string $base_class) {
        $this->base_class = $base_class;
    }

    /** generate params array for getAliasesFromParams from real generator object */
    protected function getParamsArray(): array {
        return ['base_class'=>$this->base_class];
    }

    public static function getAliasesFromParams(array $params): AA_Aliases {
        return (new AA_Aliases)->addFromProperties($params['base_class']::getClassProperties());
    }
}

