<?php
/**
 * File contains definition of AA_Storable class - abstract class which
 * implements two methods for storing and restoring class data (used in
 * searchbar class, manager class, ...
 *
 * Should be included to other scripts (as /include/searchbar.class.php3)
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
*/

/**
 * AA_Storable - abstract class which implements methods for storing and
 * restoring class data (used in searchbar class, manager class, ...).
 *
 * If you want to use storable methods in your class, you should derive the new
 * class from AA_Storable. Then you should define $persistent_slots array,
 * where you specify all the variables you want to store. Then you just call
 * getState() and setFromState() methods for storing and restoring object's data
 */
class AA_Storable {

    /** setFromState function
     * Restores the object's data from $state
     * State uses just basic types - array, int, text - not objects
     * @param  array $state state array which stores object's data. The array
     *                      you will get by getState() method.
     */
    function setFromState(&$state) {
        // we need to call getPersistentProperties() with $class parameter
        // because generic static class method (@see below)
        // in AA_Storable is not able to detect, what type of class it is in
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
            $propery_value = $this->$property_id;
            if ($property->isMulti()) {
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

    /** _oneStatePropertyValue function
     * @param $obj
     * @param $property
     * @param $state
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
        $class = get_class($this);
        foreach (call_user_func_array(array($class, 'getPersistentProperties'), array($class)) as $property) {
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
        if ( ($version = call_user_func(array($class, 'version'))) > 1) {
            $ret['aa_version'] = $version;
        }
        // we need the exact class in order we can factory the object from state
        $ret['aa_class']   = $class;
        return $ret;
    }

    /// Static ///

    /** version function
     *  Class version
     *  Used for getting state from data, which was stored sometimes in
     *  the history, so the inner structure of the class was changed
     */
    function version() {
        return 1;
    }

    /** getPersistentProperties function
     *  Returns array of persistent slots (AA_property)
     *  Uses getClassProperties() method of the classes from which it grabs all
     *  all persistent properties
     * @param $class
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

class AA_Object extends AA_Storable {

    /** Object ID   - 32 characters long hexadecimal number */
    var $aa_id;

    /** Object Name - max 16 characters long object name - optional, unique for whole AA */
    var $aa_name;

    /** Object Owner - id if object's parent, where the object belongs - optional */
    var $aa_owner;

    /** We store also following data, but it do not need its own variable
     *   aa_type       - class of the object
     *   aa_version    - version of the object class (if it is not 1)
     *   aa_subobjects - helper field used for quicker load of object
     */

    /** Constructor
     *  @param $params array of parameters in form 'property_name' => 'value'
     */
    function AA_Object($params=array()) {
        // ask class, which parameters uses and fill it
        // call AA_Widget_Txt::getClassProperties()), for example

        $class = get_class($this);
        foreach (call_user_func_array(array($class, 'getClassProperties'), array($class)) as $name =>$property) {
            if (isset($params[$name])) {
                $this->$name = $params[$name];
            }
        }
    }

    /** setOwnerId function
     * @param $owner_id
     */
    function setOwnerId($owner_id) {
        $this->aa_owner = $owner_id;
    }

    /** setName function
     * @param $name
     */
    function setName($name) {
        $this->aa_name = $name;
    }

    /** setId function
     * @param $id
     */
    function setId($id) {
        $this->aa_id = $id;
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

    function getProperty($property_id, $default=null) {
        return is_null($default) ? $this->$property_id : (($this->$property_id == '') ? $default : $this->$property_id);
    }

    /** save function
     *  Save the object to the database
     */
    function save() {
        if ( !$this->aa_owner ) {
            throw new Exception('No owner set for property '. $this->id. ' - '. $this->name);
        }

        $this->delete();
        $object_id = $this->getId();

        $class = get_class($this);
        foreach (call_user_func_array(array($class, 'getClassProperties'), array($class)) as $property) {
            $property_id   = $property->getId();
            $property->save($this->$property_id, $object_id, $this->getOwnerId());
        }

                        //        id                        name                        type    multi  persist validator, required, help, morehelp, example
        $prop = AA_Object::getPropertyObject('aa_type');
        $prop->save(get_class($this), $object_id);

        $ver = call_user_func(array($class, 'version'));
        if ($ver < 1) {
            $prop = AA_Object::getPropertyObject('aa_version');
            $prop->save(call_user_func(array($class, 'version')), $object_id);
        }

        $prop = AA_Object::getPropertyObject('aa_owner');
        $prop->save($this->aa_owner, $object_id);

        if ( $this->aa_name ) {
            $prop = AA_Object::getPropertyObject('aa_name');
            $prop->save($this->aa_name, $object_id);
        }
        // helper field aa_subobjects used for quicker load of object
        $prop = AA_Object::getPropertyObject('aa_subobjects');
        $prop->save($this->_getSubObjects(), $object_id);

        return $this->aa_id;
    }

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
        $to_delete = array( $this->getId() );

        // we have to ask database for subobjects, because it gives us the ids
        // as stored in database, not the ids of current subobjects, which could
        // be different. The $this->_getSubObjects() do not help us here!
        $to_delete = array_merge($to_delete, explode(',',$this->loadProperty($to_delete[0], 'aa_subobjects')));
        $varset    = new CVarset;

        $sqlin     = Cvarset::sqlin('object_id', $to_delete);
//        $varset->doDeleteWhere('object_text', Cvarset::sqlin('object_id', AA_Object::query('AA_Set', array('cde50ab466e2211b97ff8f93e7add44f', 'cd8499c809cdbb3b51d026e1e07520c5'))));
//        exit;
        $varset->doDeleteWhere('object_text',    $sqlin);
        $varset->doDeleteWhere('object_integer', $sqlin);
        $varset->doDeleteWhere('object_float',   $sqlin);
    }

    /** _getSubObjects function
     *  Get all object ids which is inside this object
     */
    function _getSubObjects() {
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

    /** getClassProperties function
     *   - abstract method defining the class properties
     *   - properties are used for two reasons
     *      - it could be stored in the database object storage when the object
     *        is stored (save()) (when persistent is set to true)
     *      - the object could be edited on html page - the form is automaticaly
     *        created using the properties (@see AA_Components)
     *  static
     */
    function getClassProperties()  {
        // array of AA parameters (can't be object's data, since we need
        // to call it staticaly (as class method)
        return array();
    }

    /** getNameArray function
     * @param $obj_type
     * @param $owner
     */
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

    /** factory function
     * @param $classname
     * @param $params
     */
    function &factory($classname, $params=null) {
        return class_exists($classname) ? new $classname($params) : null;
    }

    /** factoryByName function - creates any object based on mask and name:
     *   AA_Object::factoryByName('AA_Responder_', $name);
     * @param $mask
     * @param $name
     * @param $params
     */
    function &factoryByName($class_mask, $name, $params=null) {
        return AA_Object::factory(AA_Object::constructClassName($class_mask, $name), $params);
    }

    /** create the name of class from the type and name
     *  static class method
     **/
    function constructClassName($class_mask, $name) {
        return $class_mask. ucwords(strtolower($name));
    }


    /** parseClassProperties function
     *  Parses class parameters from the string, which is stored in the database
     *  Typical use is for fields.input_show_func, where parameters are stored
     *  as string in the form: fnc:const:param
     *  @param $class_mask
     *  @param $param
     *  @return asociative array of parameters, the name of parameters is given
     *  by the class itself ($class_mask . fnc).
     */
    function parseClassProperties($class_mask, $string) {
        // we do not use ParamExplode() - I  do not like the http:// replacement there
        $splited = explode('##Sx', str_replace(array('#:', ':', '~@|_'), array('~@|_', '##Sx', ':'), $string));

        // first parameter is the class identifier - the parameters starts then
        $i      = 1;
        $class  = AA_Object::constructClassName($class_mask, $splited[0]);
        $params = array('class' => $class);

        if ( class_exists($class) ) {
            // ask class, which parameters uses
            // call AA_Widget_Txt::getClassProperties()), for example
            foreach (call_user_func_array(array($class, 'getClassProperties'), array($class)) as $name => $property) {
                if (isset($splited[$i])) {
                    $params[$name] = $splited[$i++];
                }
            }
        }

        return $params;
    }

    /** factoryByString function
     *  Creates object from the string, which is used for storing setting in
     *  the database (older approach). The string looks like:
     *    dte:1:10:1
     *  which means, that it is instance of AA_Widget_Dte (when $class_mask == 'AA_Widget')
     *  and the properties are filled with values 1, 10 and 1 (in this order)
     *
     * @param $class_mask like 'AA_Widget'
     * @param $string     like dte:1:10:1 in field.input_show_func
     */
    function &factoryByString($class_mask, $string) {
        $params = AA_Object::parseClassProperties($class_mask, $string);
        return AA_Object::factory($params['class'], $params);
    }

    function loadProperty($id, $property) {
        return GetTable2Array("SELECT value FROM object_text WHERE object_id = '$id' AND property = '$property'", 'aa_first', 'value');
    }

    /** getObjectType
     * @param $id
     */
    function getObjectType($id) {
        return AA_Object::loadProperty($id, 'aa_type');
    }

    /** Loads object from database: AA_Object::load($set_id, 'AA_Set')
     * @param $id     - aa_id - object id
     * @param $type   - object class - like 'AA_Form'
     * @static
     */
    function &load($id, $type=null) {
        // @todo optimize the load
        //    - get used tables from properties,
        //    - load object from database in one step using aa_subobjects property of the objects

        if ( !$type ) {
            $type = AA_Object::getObjectType($id);
        }

        if ( !$type ) {
            return null;
        }

        $obj = new $type;
        $obj->setId($id);
        $properties = call_user_func_array(array($type, 'getClassProperties'), array($type));

        $tab = GetTable2Array("SELECT `property`, `value` FROM object_text WHERE object_id = '$id' ORDER by property, priority", '');
        $props_from_db = is_array($tab) ? $tab : array();

        $tab = GetTable2Array("SELECT `property`,`value` FROM `object_integer` WHERE object_id = '$id' ORDER by property, priority", '');
        if (is_array($tab)) {
            $props_from_db = array_merge($props_from_db, $tab);
        }
        $tab = GetTable2Array("SELECT `property`,`value` FROM `object_float` WHERE object_id = '$id' ORDER by property, priority", '');
        if (is_array($tab)) {
            $props_from_db = array_merge($props_from_db, $tab);
        }

        // first prepare value array
        foreach ( $props_from_db as $v ) {
            $prop_arr[$v['property']][] = $v['value'];
        }

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


    /** query function
     * Finds object IDs for objects given by conditions
     *
     *   @param string        $type   - object type
     *   @param AA_Slices     $owners - search only objects owned by those slices
     *   @param $set
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

    function querySet($type, $set, $restrict_zids=null) {

        $owners = $set->getModules();
        $conds  = $set->getConds();
        $sort   = $set->getSort();

        if (is_object($restrict_zids) AND ($restrict_zids->count() == 0)) {
            return new zids(); // restrict_zids defined but empty - no result
        }

        $properties = call_user_func(array($type, 'getClassProperties'));


        // parse conditions ----------------------------------
        $tables_counter = array('object_text'=>1, 'object_integer'=>0, 'object_float'=>0);
        $tables         = array();

        $tables['t0']['cond'] = "t0.property='aa_type' AND t0.value='".quote($type)."'";
        $tables['t0']['join'] = 'object_text as t0';

        if ( !empty($owners) ) {
            $tables['t1']['cond'] = "t1.property='aa_owner' AND ". sqlin('t1.value', $owners);
            $tables['t1']['join'] = "LEFT JOIN object_text as t1 ON (t1.object_id=t0.object_id AND t1.property='aa_owner')";
            $tables_counter['object_text']++;
        }

        // Conditions
        foreach ($conds as $cond) {
            // fill arrays according to this condition
            $cond_flds   = array();
            $store       = '';
            foreach ( $cond as $fid => $v ) {
                if ( $CONDS_NOT_FIELD_NAMES[$fid] ) {
                    continue;      // it is not field_id parameters - skip it for now
                }

                $field = $properties[$fid];

                if ( empty($field) OR ($v=="")) {
                    debug("skipping $fid in conds[]: not known $fid or empty condition");
                    continue;
                }

                $field_store = $field->storageType();
                if ( empty($field_store) ) {
                    debug("skipping $fid in conds[]: no storage table (is it object?)");
                    continue;
                }
                // will not work with one condition for columns of different types (text/int/...) - which is right, I think.
                $store = $field_store;
                $cond_flds[] = $fid;
            }
            if ( !empty($cond_flds) ) {
                $tbl = (($store == 'object_text') ? 't' : ($store == 'object_integer') ? 'i' : 'f') . $tables_counter[$store]++;

                // fill arrays to be able construct select command
                $tables[$tbl]['cond'] = GetWhereExp( "$tbl.value", $cond['operator'], $cond['value'] );
                $tables[$tbl]['join'] = "LEFT JOIN $store as $tbl ON ($tbl.object_id=t0.object_id AND ". sqlin("$tbl.property", $cond_flds); // OR $tbl.property is NULL))"; - like in content
                if (count($cond_flds) == 1) {
                    // mark this field as sortable (store without apostrofs)
                    $sortable[ reset($cond_flds) ] = $tbl;
                }
            }
        }


        // Sorting
        $select_order    = array();
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
                debug("skipping $fid in sort[]: not known $fid");
                continue;
            }

            if ( !$sortable[ $fid ] ) {           // this field is not joined, yet
                $store = $field->storageType();
                if ( empty($store) ) {
                    debug("skipping $fid in sort[]: no storage table (is it object?)");
                    continue;
                }

                $tbl = (($store == 'object_text') ? 't' : ($store == 'object_integer') ? 'i' : 'f') . $tables_counter[$store]++;
                $tables[$tbl]['join'] = "LEFT JOIN $store as $tbl ON ($tbl.object_id=t0.object_id AND ". sqlin("$tbl.property", $cond_flds); // OR $tbl.property is NULL))"; - like in content

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
        }

        $delim = '';
        foreach ($tables as $tbl => $table ) {
            if ($table['cond']) {
                $SQL .= " $delim (". $table['cond'] .' )';
                $delim = 'AND';
            }
        }

        if ( count($select_order) ) {                                // order ----------
            $SQL .= " ORDER BY ". implode(', '. $select_order);
        }

        // not cached result
        return GetZidsFromSQL( $SQL, 'objectid', 'l', false,
                               // last parameter is used for sorting zids to right order
                               // - if no order specified and restrict_zids are specified,
                               // return zids in unchanged order
                               (is_object($restrict_zids) AND count($select_order)) ? $restrict_zids : null);   // , $select_limit_field);  - see GetZidsFromSQL()
    }

    /** getSearchArray function
     *  @static
     */
    function _getSearchArray($properties) {
        $i   = 0;
        $ret = array();
        foreach ($properties as $prop_id => $property) {
            if ($property->isObject()) {
                continue;
            }
            $field_type = $property->getType();    // @todo - convert to right search values

            // we can hide the field, if we put in fields.search_pri=0
            $search_pri = ++$i;
                               //             $name,        $field,       $operators, $table, $search_pri, $order_pri
            $ret[$prop_id] = GetFieldDef( $prop_id, $prop_id, $field_type, false, $search_pri, $search_pri);
        }
        return $ret;
    }

    /** generateAliases
     *  @static
     */
    function _generateAliases($properties) {
        $aliases = array();
        foreach ($properties as $prop_id => $property) {
            if ($property->isObject()) {
                continue;
            }
            // @todo - make alias field type aware
            $aliases["_#". substr(str_pad(strtoupper($prop_id),8,'_'),0,8)] = GetAliasDef( "f_h", $prop_id, $prop_id);
        }
        $aliases["_#AA_NAME_"] = GetAliasDef( "f_h", 'aa_name', 'aa_name');
        $aliases["_#AA_ID___"] = GetAliasDef( "f_h", 'aa_id',   'aa_id');
        $aliases["_#AA_OWNER"] = GetAliasDef( "f_h", 'aa_owner','aa_owner');
        return $aliases;
    }

    /**
     *  @static
     */
    function getContent($settings, $zids) {
        $ret = array();

        $class      = $settings['class'];
        $properties = call_user_func(array($class, 'getClassProperties'));

        foreach ($zids as $id) {
            $content = new AA_Content;

            $obj     = call_user_func_array(array($class, 'load'), array($id));
            if (is_null($obj)) {
                throw new Exception('object not loaded: '. $id);
                continue;
            }
            foreach ($properties as $prop_id => $property) {
                // @todo - make alias field type aware
                $content->setAaValue($prop_id, AA_Value::factory($obj->getProperty($prop_id)));
            }
            $content->setAaValue('aa_name',  new AA_Value( $obj->getProperty('aa_name') ));
            $content->setAaValue('aa_id',    new AA_Value( $obj->getProperty('aa_id') ));
            $content->setAaValue('aa_owner', new AA_Value( $obj->getProperty('aa_owner') ));

            $ret[$id] = $content;
        }
        return $ret;
    }

    /** generate manager from object structure
     * @param $classname
     * @param $params
     * @static
     */
    function getManagerConf($object_class, $manager_url, $actions=null, $switches=null) {
        $properties    = call_user_func(array($object_class, 'getClassProperties'));

        $aliases       = AA_Object::_generateAliases($properties);
        $search_fields = AA_Object::_getSearchArray($properties);
        $new_link      = a_href(get_admin_url('oedit.php3', array('otype' => $object_class, 'ret_url' => $manager_url)), GetAAImage('icon_new.gif', _m('new'), 17, 17).' '. _m('Add'));

        $manager_settings = array(
             'show'      =>  0, //MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS,    // MGR_ACTIONS | MGR_SB_SEARCHROWS | MGR_SB_ORDERROWS | MGR_SB_BOOKMARKS
             'searchbar' => array(
                 'fields'               => $search_fields,
                 'search_row_count_min' => 1,
                 'order_row_count_min'  => 1,
                 'add_empty_search_row' => true,
                 'function'             => false  // name of function for aditional action hooked on standard filter action
                                 ),
             'scroller'  => array(
                 'listlen'              => EDIT_ITEM_COUNT
                                 ),
             'itemview'  => array(
                 'manager_vid'          => false,    // $slice_info['manager_vid'],      // id of view which controls the design
                 'format'               => array(    // optionaly to manager_vid you can set format array
                     'compact_top'      => '<table>
                                            <tr>
                                              <th width="30">&nbsp;</th>
                                              <th>'.join("</th>\n<th>", array_keys($search_fields)+array(_m('Name'), _m('ID'), _m('Owner'), _m('Action'))).'</th>
                                            </tr>
                                            ',
                     'category_sort'    => false,
                     'category_format'  => "",
                     'category_top'     => "",
                     'category_bottom'  => "",
                     'even_odd_differ'  => false,
                     'even_row_format'  => "",
                     'odd_row_format'   => '
                                            <tr>
                                              <td><input type="checkbox" name="chb[x_#AA_ID___]" value=""></td>
                                              <td>'.join("</td>\n<td>", array_keys($aliases)).'</td>
                                              <td>'. a_href(get_admin_url('oedit.php3', array('oid=_#AA_ID___', 'otype' => $object_class, 'ret_url' => $manager_url)), _m('Edit')). '</td></td>
                                            </tr>
                                           ',
                     'compact_remove'   => "",
                     'compact_bottom'   => "</table><br>". $new_link,
                     'noitem_msg'       => _m('No object found'). '<br>'. $new_link
                     ),
                 'fields'               => $search_fields,
                 'aliases'              => $aliases,
                                           //    static class method               , first parameter to the method
                 'get_content_funct'    => array(array('AA_Object', 'getContent'), array('class'=>$object_class))
                                 ),
             'actions'   => $actions,
             'switches'  => $switches,
             'bin'       => 'app',
             'messages'  => array(
                 'title'       => _m('Manage %1', array($object_class))
                                 )
                 );

        return $manager_settings;
    }
}

/** Components (plugins) manipulation class */
class AA_Components extends AA_Object {

    /// Static ///

    /** Used parameter format (in fields.input_show_func table)
     *  @todo - specify the parameters better - value type, used widget, ... so
     *          we could generate Parameter wizard (and validation) from those
     *          informations
     */


    /** getClassNames function
     *  Return names of all known AA classes, which begins with $mask
     *  static function
     * @param $mask
     */
    function getClassNames($mask) {
        $right_classes = array();

        // php4 returns classes all in lower case :-(
        $mask          = strtolower($mask);
        $mask_length   = strlen($mask);
        foreach (get_declared_classes() as $classname) {
            if ( substr(strtolower($classname),0,$mask_length) == $mask ) {
                $right_classes[] = $classname;
            }
        }
        return $right_classes;
    }

    /** getSelectionCode function
     * @param $mask
     * @param $input_id
     * @param $params
     */
    function getSelectionCode($mask, $input_id, &$params) {
        $options      = array('AA_Empty' => _m('select ...'));
        $html_options = array('AA_Empty' => '');
        foreach (AA_Components::getClassNames($mask) as $selection_class) {
            // call static class methods
            $options[$selection_class]      = call_user_func(array($selection_class, 'name'));
            $html_options[$selection_class] = call_user_func_array(array($selection_class, 'htmlSetting'), array($input_id, &$params));
        }
        return getSelectWithParam($input_id, $options, "", $html_options);
    }
}

?>
