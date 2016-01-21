<?php
/**
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
 * @package   UserInput
 * @version   $Id$
 * @author    Mitra Ardron <mitra@mitra.biz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/


/**
 * Functions for manipulating ids.
 *
 * Author and Maintainer: Mitra mitra@mitra.biz
 *
 * This is developed from functions originally in util.php3, where many
 * still exist. They can gradually be included here
 *
 * Notes on naming conventions
 *       zids    one of these objects
 *       zid     a string that could be any type of id
 *
 * Ids handled on input
 *       shortid  type=s
 *       packedid type=p
 *       quotedpackedid   NOT YET SUPPORTED ON INPUT
 *       longid  type=l
 *       taggedid type=t
 * The type of an id, is the first letter of these, or any other string
 *
 * Hints on integrating this with other code
 *
 * Hints on modifying this code - ask mitra if unsure
 *       The code uses the &$xxx syntax for efficiency wherever possible,
 *               but does not unless clearly commented change the passed var
 *
 * Supported functions, and which types work with
 * longids       p l   e.g. 0112233445566778899aabbccddeeff0
 * packedids     p l   e.g. A!D\s'qwertyuio
 * shortids      s     e.g. 1234
 *
 */

//require_once AA_INC_PATH."util.php3";  // quote

class zids implements Iterator, ArrayAccess, Countable {
    var $a;     // Array of ids of type specified in $t
    var $type;  // Type of $a
    var $l2s;   // array used for translation from 'long' to 'short' type
    var $s2l;   // array used for translation from 'short' to 'long' type
    protected $_ext; // array of extended parameters (we are using it when we store group_by additional info into zids)

    /** zids function
     *  Constructor can be called with an array, or a zid
     * @param $initial
     * @param $inittype
     * @param $extended_att  additional attribute for each zid. It is in the form
     *                       array('attr'=>array(<array of attr for zids in the same based array, as $initial>))
     */
    function __construct($initial = null, $inittype = "z", $extended_att = array()){  // constructor
        // $inittype is for where type is known
        // Note it refers to the type of ELEMENTS if its an array
        $this->type = $inittype;
        $this->a    = array();  // Make sure at least empty array
        $this->_ext = is_array($extended_att) ? $extended_att : array();

        if (empty($initial)) {
            return;
        }

        if (is_array($initial)) { // Array
            if (is_array($initial[0])) { // Array of fields
                foreach ( $initial as $field ) {
                    if ( $field['value'] ) {           // copy just not empty ids
                        $this->a[] = $field['value'];
                    }
                }
            } else {
                foreach ( $initial as $id ) {
                    if ( $id ) {                       // copy just not empty ids
                        $this->a[] = $id;
                    }
                }
            }
        } else {
            $this->a[] = $initial;
        }
        if ($this->type == "z") {
            $this->type = guesstype($this->a[0]);
        }
        $this->clean();
    }

    /** remove non valid ids */
    function clean() {
        switch ($this->type) {
        case 's': $this->a = array_values(array_filter($this->a, 'is_short_id'));  break;
        case 'l': $this->a = array_values(array_filter($this->a, 'is_long_id'));   break;
        case 'p': $this->a = array_values(array_filter($this->a, 'is_packed_id')); break;
        }
    }

    /** setFromItemArr function
     * Grabs long ids from array as posted from manager.class checkboxes
     *  $items[x767ab56353544242552637389a853673]=1
     * @param $item_arr
     * @param $type - type of ids, mostly 'l', but TaskManager f.e. uses short ones
     */
    function setFromItemArr($item_arr, $type='l') {
        $this->clear($type);
        if ( isset($item_arr) AND is_array($item_arr) ) {
            foreach ( $item_arr as $it_id => $foo ) {
                $this->a[] = substr($it_id,1);      // remove initial 'x'
            }
        }
    }

    /** clear function
     *  removes all zids and resets
     * @param $inittype
     */
    function clear($inittype = 'z') {
        $this->a    = array();
        $this->type = $inittype;
        $this->_ext = array();
    }


    /** adds ids from $ids array and checks if the valueas are short or long
     *  wrong valuas are not added
     */
    function addDirty($ids) {
        if (substr(reset($ids),0,1)=='[') {
            $json_ids = array();
            foreach ($ids as $json) {
                $json_ids = array_merge($json_ids, json_decode($json, true));
            }
            $ids = $json_ids;
        }
        foreach ((array)$ids as $id) {
            $id   = trim($id);
            $type = guesstype($id);
            if ( !in_array($type, array('s','l')) OR ($id=='0') OR ($id=='')) {
                continue;
            }
            if ($this->type == 'z') {
                $this->type = $type;
            }
            if ($this->type == $type) {
                $this->a[] = $id;
            }
        }
        $this->clean();
    }

    /** add function
     *  Adds new id or array of ids or zids object.
     *   The type must be already set (from init).
     * @param $ids
     */
    function add($ids) {
        if ( isset($ids) AND is_object($ids) ) {           // zids
            if ($this->is_empty()) {
                $this->a    = $ids->a;
                $this->type = $ids->onetype();
            } elseif ($ids->onetype() == $this->onetype()) {
                $this->a = array_merge($this->a, (array)$ids->a);
            } else {
                return false;
            }
        } elseif ( isset($ids) AND is_array($ids) ) {      // array of ids
            $this->a = array_merge($this->a, $ids);
        } elseif ( $ids ) {                                // id
            $this->a[] = $ids;
        } else {
            return false;
        }
        $this->clean();
        return true;
    }

    /** union function
     *  Adds new id or array of ids or zids object and deletes duplicate ids.
     *   If called without parameters, only deletes duplicate ids.
     *   The type must be already set (from init).
     * @param $ids
     */
    function union($ids = "") {
        $this->add($ids);
        if ($this->count() > 0 ) {
            // we can't use array_unique because we need to preserve key range 0..x
            sort ($this->a);
            $last = "XXXXXXXX";
            $unique = array();
            foreach ($this->a as $v) {
                if ($v && $v != $last) {
                    $unique[] = $v;
                }
                $last = $v;
            }
            $this->a = $unique;
        }
    }

    /** Debugging function to print zids
     *  Don't rely on the output format, its only for debuging
     */
    function __toString() {
        return "zids object: type=".$this->type." (". ($this->count()<=0 ? 'Empty' : implode(",",$this->a) ).")";
    }

    /** onetype function
     * @return one-character type for standard types
     * be careful of how extension types are handled
     */
    function onetype() {
        // TODO - handle other types than single character types
        return $this->type;
    }

    /** Is zids empty? */
    function is_empty() {
        return (count($this->a) < 1);
    }

    /** warnid function
     * Quick check to warn if item doesn't exist
     */
    function warnid($i=null,$warnstr="") {
        if ( (isset($i) and !(isset($this->a[$i]))) ) {
            huhe("Warning: zids: $warnstr, item $i doesn't exist, returning null");
            return true;
        }
        return false;
    }

    /** longids function
     *  Return an array of long ids
     *  TODO: look at where used, typically used in interface to pre-zid code
     * @param $i
     */
    function longids($i=null) {
        if ($this->warnid($i,"longids")) {
            return null;
        }
        if ( !isset($i) AND ($this->count()<1) ) {
            return array();
        }
        switch ($this->type) {
            case "l":  return (isset($i) ? $this->a[$i]            : $this->a);
            case "p":  return (isset($i) ? unpack_id($this->a[$i]) : array_map("unpack_id", $this->a));
            case "t":  return (isset($i) ? id_t2l($this->a[$i])    : array_map("id_t2l", $this->a));
            case 's':  $trans = $this->translate('l');
                       return (isset($i) ? $trans[$i] : $trans );
            default:
                       print("ERROR - zids:longids(): can't handle type $this->type conversion to longids - $this");
                       return false;  //TODO - handle other types
        }
    }
    /** packedids function
     * @param $i
     */
    function packedids($i=null) {
        if ($this->warnid($i,"packedids")) {
            return null;
        }
        switch ($this->type) {
            case 'p': return (isset($i) ? $this->a[$i] : $this->a);
            case 'l': return (isset($i) ? pack_id($this->a[$i]) : array_map("pack_id",$this->a));
            case 't': return (isset($i) ? pack_id(id_t2l($this->a[$i]))
                                        : array_map("pack_id", $this->longids()));
            case 's': $trans = $this->translate('l');
                      return ( isset($i) ? pack_id($trans[$i]) : array_map("pack_id", $trans));
            default:
                      print("ERROR - zids:packedids(): can't handle type $this->type conversion to packedds - ask mitra");
                      return false;
        }
    }

    /** shortids function
     * @param $i
     */
    function shortids($i=null) {
        if ($this->warnid($i,"shortids")) {
            return null;
        }
        if ( $this->type == 's' ) {
            return (isset($i) ? $this->a[$i] : $this->a);
        }
        $l_zids = new zids( $this->longids(),'l');  // convert to long (for translation)
        $trans  = $l_zids->translate('s');
        return isset($i) ? $trans[$i] : $trans;
    }

    /** short_or_longids function
     *  Return either short id, or a long id, depending on use_short_ids()
     *  These are ids suitable for indexing return from GetItemContent
     * @param $i
     */
    function short_or_longids($i=null) {
        if ($this->warnid($i,"short_or_longids")) {
            return null;
        }
        return ($this->use_short_ids() ? $this->shortids($i) : $this->longids($i));
    }

    /** use_short_ids function
     *
     */
    function use_short_ids() {
        return ($this->type == "s");
    }

    /** id function
     * Return nth id, note there is no guarrantee what format this will be in, so its
     * only really useful for serialization or if type is checked as well
     * @param $idx
     */
    function id($idx=0) {
        return $this->a[$idx];
    }

    /** slice function
     * Create a new zids, from a subset of the data,  with the same type
     * Parameters are same as for "array_slice"
     * @param $offset
     * @param $length
     */
    function slice($offset, $length=1) {
        if (is_array($this->a)) {
            return new zids(array_slice($this->a,$offset,$length),$this->type);
        } else {
            return new zids(null, $this->type);
        }
    }

    /** zid function
     *  Returns n-th zid
     * @param $index
     */
    function zid($index) {
        return $this->slice($index);
    }

    /** get the ids array as is */
    function getArray() {
        return $this->a;
    }

    /** get extended attribute for id */
    function getAttr($index, $attr_name) {
        return is_array($this->_ext[$attr_name]) ? $this->_ext[$attr_name][$index] : null;
    }

    /** gettags function
     * Return associative array, longid->tag;
     */
    function gettags() {
        if ($this->type != "t") {
            return false;
        }
        $tags = array();
        foreach ( $this->a as $v ) {
            if (preg_match('/^(.*?)([0-9a-f]{24,32})$/',$v,$parts)) {
                $tags[$parts[2]] = $parts[1]; // Note can be empty
            } else {
                print("Cant parse tagged id '$v' - tell Mitra");
            }
        }
        return $tags;
    }

    /** retag function
     *  Restore tags in array, by looking for ids in zids2
     *   Return resulting new zids
     *   Reasonably efficent, only loops through each array once
     * @param $zids
     */
    function retag($zids) {
        if ($zids->type != "t") {
            return $this;  // Array is fine
        }
        $tags = array();
        while ( list(,$v) = each($zids->a)) {
            switch ($this->type) {
                case 'p': $k = pack_id(id_t2l($v)); break;
                default:
                          print("<br>Error: zids: can't retag array of type '".$this->type."', tell mitra");
                          return;
            }
            $tags[$k]=$v;
        }
        $b = array();
        if ( $this->count() > 0 ) {
            while ( list(,$v) = each($this->a)) {
                $b[] = $tags[$v];
            }
        }
        return new zids($b,"t");
    }

    /** sqlin function
     *  Return appropriate SQL for including in WHERE clause
     * Note that some code still does this by hand,
     * @param $column
     * @param $asis - returns the ids in long form (not packed even for 'l' type)
     */
    function sqlin( $column = 'short_or_long', $asis = false ) {
        $id_arr = array();
        if ($this->count()) {
            if ( $column == 'short_or_long' ) {
                $column = ( $this->use_short_ids() ? "item.short_id" : "item.id" );
            }
            if ( $asis ) {
                $id_arr = $this->a;
            } elseif ($this->use_short_ids()) {
                $id_arr = $this->shortids();
            } else {
                $id_arr = $this->packedids();
            }
        }
        if (!$id_arr) {  // false, null empty arr
            return $column ? '0' : ' = "" ';
        }

        $id_list = implode(",",array_map("qquote", $id_arr));

        // '=' is much quicker than 'IN ()' in MySQL 4.0.x
        // - don't ask me why, please. Honza
        return ((count($id_arr) == 1) ? " $column = $id_list " : " $column IN ($id_list) ");
    }

    /** getFirstSlice function
     *  Returns the slice id for the ids
     *  If items are from more than one slice, then it returns the random of them
     */
    function getFirstSlice() {
        if ($this->count() == 0) {
            return false;
        }
        foreach ( $this->a as $i => $id ) {
            $zid = $this->zid($i);
            $SQL        = "SELECT slice_id FROM item WHERE ". $zid->sqlin();
            $p_slice_id = GetTable2Array($SQL, 'aa_first', 'slice_id');
            if ( !empty($p_slice_id) ) {
                return unpack_id($p_slice_id);
            }
        }
        return false;
    }

    // no longer used
    ///** sort_and_restrict_as_in function
    // *  Sorts zids array in the same order as the zids are in $sort_zids
    // * @param $sort_zids
    // */
    //function sort_and_restrict_as_in($sort_zids) {
    //    $translation = $this->get_translation($sort_zids->onetype());
    //    $ret         = array();
    //    foreach ( $sort_zids->a as $zid ) {
    //        if ( $translation[$zid] ) {
    //            $ret[] = $translation[$zid];
    //        }
    //    }
    //    $this->a = $ret;
    //}

    /** translate function
     *  fills $s2l and $l2s array used for translation 'long' <-> 'short' and
     * @return array of zids in 'long' (for $type=='l') or short form
     * @param $type
     */
    function translate($type) {
        $db = getDB();
        $SQL = "SELECT id, short_id FROM item WHERE ". $this->sqlin();
        $db->tquery($SQL);
        while ( $db->next_record() ) {
            $unpacked_id = unpack_id($db->f('id'));
            $this->l2s[$unpacked_id] = $db->f('short_id');
            $this->s2l[$db->f('short_id')] = $unpacked_id;
        }
        freeDB($db);
        // we need it in the same order as in source
        $ret = array();
        foreach ( $this->a as $idx => $zid ) {
            switch ($type) {
                case 'l': $ret[$idx] = $this->s2l[$zid]; break;
                case 'p': $ret[$idx] = pack_id($this->s2l[$zid]); break;
                default:  $ret[$idx] = $this->l2s[$zid];
            }
        }
        return $ret;
    }
    /** get_translation function
     * @param $from_type
     */
    function get_translation($from_type) {
        if ($this->count() <= 0) {
            return array();
        }
        $ret = array();
        $trans = $this->get_retyped($from_type);
        foreach ( $this->a as $idx => $zid ) {
            $ret[$trans[$idx]] = $zid;
        }
        return $ret;
    }

    function get_retyped($to_type) {
        switch($to_type) {
            case "p": return $this->packedids();
            case "l": return $this->longids();
            case "s": return $this->shortids();
        }
        return array();
    }

    /** Iterator interface */
    public function rewind()  { reset($this->a);                        }
    public function current() { return current($this->a);               }
    public function key()     { return key($this->a);                   }
    public function next()    { next($this->a);                         }
    public function valid()   { return (current($this->a) !== false);   }

    /** Countable interface */
    public function count()   { return count($this->a);                 }

    /** ArrayAccess interface */
    public function offsetSet($offset, $value) { $this->a[$offset] = $value;      }
    public function offsetExists($offset)      { return isset($this->a[$offset]); }
    public function offsetUnset($offset)       { unset($this->a[$offset]);        }
    public function offsetGet($offset)         { return isset($this->a[$offset]) ? $this->a[$offset] : null; }
} // class zids

/** guesstype function
 *  This guesses the type from the length of the id,
 * short should be == 16 and long == 32 but there is or was somewhere a bug
 * leading to shorter (as short as 14) character ids.
 * @param $str
 */
function guesstype($str) {
    $s = strlen($str);
    if (($s < 12) AND (is_numeric($str) OR ($str==''))) {
        return 's';
    }
    if (($s >= 12) AND ($s <= 16)) {
        return 'p';
    }
    if (($s >= 24) AND ($s <= 32) AND (strspn($str, "0123456789abcdefABCDEF") == $s)) {
        return 'l';
    }
    if ($s > 32) {
        return 't'; // Could also test last 32 hex
    }
    debug("Error, unable to guess type of id '$str' - ask mitra");
    return ('z');
}

/** type validating functions */
function is_short_id($id)  { return guesstype($id)=='s'; }
function is_long_id($id)   { return guesstype($id)=='l'; }
function is_packed_id($id) { return guesstype($id)=='p'; }


/** ParamExplode(), unpack_id() and get_hash() functions moved here from
 *  util.php3 because we do not want to include util.php3 file for cached pages,
 *  but, we still need zids.php3 and those 3 functions for cache
 */

/** ParamExplode function
 * explodes $param by ":". The "#:" means true ":" - don't separate
 * @param $param
 * @return array
 */
function ParamExplode($param) {
    // replace all "#:" and <http>"://" with dumy string,
    // convert separators to ##Sx
    // change "#:" to ":" and change back "://" - then split by separation string
    // replaces in order
    return explode('##Sx', str_replace(array('#:', 'tp://', ':', '~@|_'), array('~@|_', 'tp~@|_//', '##Sx', ':'), $param));
}

function get_hash() {
    $arg_list = func_get_args();   // must be asssigned to the variable
    // return md5(json_encode($arg_list));
    // return md5(var_export($arg_list, true));
    // return md5(serialize($arg_list));
    return hash('md5', serialize($arg_list));  // quicker than md5()
}

/** pack_id function
 * @param $unpacked_id
 * @return packed md5 id, not quoted !!!
 * Note that pack_id is used in many places where it is NOT 128 bit ids.
 */
function pack_id($unpacked_id) {
    return ((string)$unpacked_id == "0" ? "0" : @pack("H*",trim($unpacked_id)));
}

/** unpack_id
 * @param $packed_id
 * @return unpacked md5 id
 */
function unpack_id($packed_id=''){
    return ((string)$packed_id != '0') ? bin2hex($packed_id) : '0';
}

/** quote function
 * function to double backslashes and apostrofs
 * @param $str
 */
function quote($str) {
    return addslashes($str);
}

/** q_pack_id function
 * returns packed and quoted id
 * @param $unpacked_id
 */
function q_pack_id($unpacked_id){
    $foo = pack_id($unpacked_id);
    return quote($foo);
}
/** qq_pack_id function
 * @param $str
 */
function qq_pack_id($str) {
    return "'".q_pack_id($str)."'";
}
/** qquote function
 * @param $str
 */
function qquote($str) {
    return "'".quote($str)."'";
}

/** qqquote function
 * @param $str
 */
function qqquote($str) {
    return '"'.quote($str).'"';
}


/** sqlin function
 *  Return appropriate SQL for including in WHERE clause
 * Note that some code still does this by hand,
 * @param $column
 * @param $values - array of values
 */
function sqlin( $column, $values ) {
    switch (count($values)) {
    case 0: return '0';
    case 1: return "$column = ".qquote(reset($values));
    }
    return "$column IN (". implode(",",array_map("qquote", $values)). ')';
}

/** id_t2l function
 * @param $str
 */
function id_t2l(&$str) {
    if (!$str) {
        huhe(print("Warning: zids:id_t2l:converting empty string"));
        return null;
    }
    // TODO: Look online for quicker way to substr last 32 chars - mitra
    if (preg_match('/^(.*?)([0-9a-f]{24,32})$/',$str,$parts)) {
        return $parts[2];
    }
    print("Unable to parse tagged id '$str' - tell mitra");
}


?>