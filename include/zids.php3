<?php
/**
 *
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
 * @package   UserInput
 * @version   $Id$
 * @author    Mitra Ardron <mitra@mitra.biz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

use AA\IO\DB\DB_AA;


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

//require_once __DIR__."/util.php3";  // quote

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
     * @param array $extended_att  additional attribute for each zid. It is in the form
     *                             array('attr'=>array(<array of attr for zids in the same based array, as $initial>))
     */
    function __construct($initial = null, $inittype = "z", $extended_att = []){  // constructor
        // $inittype is for where type is known
        // Note it refers to the type of ELEMENTS if its an array
        $this->type = $inittype;
        $this->a    = [];  // Make sure at least empty array
        $this->_ext = is_array($extended_att) ? $extended_att : [];

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
                 $this->a = array_values(array_filter($initial,'trim'));  // copy just not empty ids and assign them form 0 index
            }
        } else {
            $this->a[] = $initial;
        }
        unset( $initial );
        $this->clean(); // this also sets ->type
    }

    /** remove non valid ids */
    function clean() {
        if ($this->type == "z") {
            // find and set current
            if ( $this->guesstype() == 'z' ) {
                $this->clear();
            }
        }
        switch ($this->type) {
        case 's': $this->a = array_values(array_filter($this->a, 'is_short_id'));  break;
        case 'l': $this->a = array_values(array_filter($this->a, 'is_long_id'));   break;
        case 'p': $this->a = array_values(array_filter($this->a, 'is_packed_id')); break;
        }
    }

    /** fills $this->>type with current zids type */
    function guesstype() {
        foreach ($this->a as $val) {
            if ( ($this->type = guesstype($val)) != 'z' ) {
                break;
            }
        }
        return $this->type;
    }

    /** setFromItemArr function
     * Grabs long ids from array as posted from manager.class checkboxes
     *  $items[x767ab56353544242552637389a853673]=1
     * @param $item_arr
     * @return zids
     */
    public function setFromItemArr($item_arr) {
        return $this->clear()->add(array_map(function ($k) {
            return ltrim($k, 'x');
        }, array_keys($item_arr)));   // in some managers we use 'x' before the item id - remove it
    }
//    function setFromItemArr($item_arr, $type='l') {
//        $this->clear($type);
//        if ( isset($item_arr) AND is_array($item_arr) ) {
//            foreach ( $item_arr as $it_id => $foo ) {
//                $this->a[] = substr($it_id,1);      // remove initial 'x'
//            }
//        }
//        return $this;
//    }

    /** clear function - removes all zids and resets
     * @param $inittype
     * @return zids
     */
    function clear($inittype = 'z') {
        $this->a    = [];
        $this->type = $inittype;
        $this->_ext = [];
        return $this;
    }


    /** adds ids from $ids array and checks if the valueas are short or long
     *  wrong valuas are not added
     * @return zids this
     */
    function addDirty($ids) {
        if (substr(reset($ids),0,1)=='[') {
            $json_ids = [];
            foreach ($ids as $json) {
                $json_ids = array_merge($json_ids, json_decode($json, true));
            }
            $ids = $json_ids;
        }
        foreach ((array)$ids as $id) {
            $id   = trim($id);
            $type = guesstype($id);
            if ( !in_array($type, ['s','l']) OR ($id=='0') OR ($id=='')) {
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
        return $this;
    }

    /** add function
     *  Adds new id or array of ids or zids object.
     *   The type must be already set (from init).
     * @param zids|array|string $ids
     * @return zids this
     */
    function add($ids) {
        if ( isset($ids) AND is_object($ids) ) {           // zids
            if ($this->isEmpty()) {
                $this->a    = $ids->a;
                $this->type = $ids->onetype();
            } elseif ($ids->onetype() == $this->onetype()) {
                $this->a = array_merge($this->a, (array)$ids->a);
            }
        } elseif ( isset($ids) AND is_array($ids) ) {      // array of ids
            $this->a = array_merge($this->a, $ids);
        } elseif ( $ids ) {                                // id
            $this->a[] = $ids;
        }
        $this->clean();
        return $this;
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
            $unique = [];
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
    function isEmpty() {
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
     * @return array|int|string|null
     */
    function longids($i=null) {
        if ($this->warnid($i,"longids")) {
            return null;
        }
        if ( !isset($i) AND ($this->count()<1) ) {
            return [];
        }
        switch ($this->type) {
            case "l":  return (isset($i) ? $this->a[$i]            : $this->a);
            case "p":  return (isset($i) ? unpack_id($this->a[$i]) : array_map("unpack_id", $this->a));
            case "t":  return (isset($i) ? id_t2l($this->a[$i])    : array_map("id_t2l", $this->a));
            case 's':  $trans = $this->translate('l');
                       return (isset($i) ? $trans[$i] : $trans );
            default:
                       warn("ERROR - zids:longids(): can't handle type $this->type conversion to longids - $this");
                       return null;  //TODO - handle other types
        }
    }

    /** shortids function
     * @param $i
     * @return int[]|int|null
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
     * @return int[]|int|string[]|string|null
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
        return ($this->type == 's');
    }

    /** id function
     * Return nth id, note there is no guarrantee what format this will be in, so its
     * only really useful for serialization or if type is checked as well
     * @param $idx
     * @return
     */
    function id($idx=0) {
        return $this->a[$idx];
    }

    /**
     * @return array used for where condition for item table in DB_AA::select*
     */
    public function getDbWhereArray() {
        switch ($this->type) {
            case 'p': return ['id', $this->a];
            case 'l': return ['id', $this->a, 'l'];
            case 't': return ['id', $this->longids(), 'l'];
            case 's': return ['short_id', $this->a, 'i'];
        }
        warn("ERROR - zids::getDbWhereArray(): can't handle type $this->type");
        return ['id', []];  // never satisfy condition... (1=2)
    }

    /** sanitize ids  - restrict (filter) item ids from specified slice
     * @param  string $slice_id
     * @return zids
     */
    public function itemFilter($slice_id) {
        if (($this->count() < 1) OR !is_long_id($slice_id)) {
            return $this->clear('l');
        }
        $where = [['slice_id',$slice_id,'l'],$this->getDbWhereArray()];
        return $this->clear('l')->add(DB_AA::select('', "SELECT LOWER(HEX(id)) FROM item", $where));
    }

    /** slice function
     * Create a new zids, from a subset of the data,  with the same type
     * Parameters are same as for "array_slice"
     * @param $offset
     * @param $length
     * @return zids
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
     * @return zids
     */
    function zid($index) {
        return new zids($this->a[$index], $this->type);
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
        $tags = [];
        foreach ( $this->a as $v ) {
            if (preg_match('/^(.*?)([0-9a-f]{24,32})$/',$v,$parts)) {
                $tags[$parts[2]] = $parts[1]; // Note can be empty
            } else {
                warn("Cant parse tagged id '$v' - tell Mitra");
            }
        }
        return $tags;
    }

    /** retag function
     *  Restore tags in array, by looking for ids in zids2
     *   Return resulting new zids
     *   Reasonably efficent, only loops through each array once
     * @param $zids
     * @return void|zids
     */
    function retag($zids) {
        if ($zids->type != "t") {
            return $this;  // Array is fine
        }
        $tags = [];
        foreach ($zids->a as $v) {
            switch ($this->type) {
                case 'p': $k = pack_id(id_t2l($v)); break;
                default:
                          warn("<br>Error: zids: can't retag array of type '".$this->type."', tell mitra");
                          return;
            }
            $tags[$k]=$v;
        }
        $b = [];
        if ( $this->count() > 0 ) {
            foreach ($this->a as $v) {
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
     * @return string
     */
    function sqlin( $column = 'short_or_long', $asis = false ) {
        $id_list = '';
        if ($this->count()) {
            if ( $column == 'short_or_long' ) {
                $column = ( $this->use_short_ids() ? "item.short_id" : "item.id" );
            }
            if ( $asis OR ($this->type == 's') ) {
                $id_list = join(",",array_map("qquote",$this->a));
            } else {
                $id_list = join(",",array_map('xpack_id',$this->longids()));
            }
        }
        if (!$id_list) {
            return $column ? '0' : ' = "" ';
        }

        // '=' is much quicker than 'IN ()' in MySQL 4.0.x
        // - don't ask me why, please. Honza
        return strpos($id_list,',') ? " $column IN ($id_list) ": " $column = $id_list ";
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

    /** translate function
     *  fills $s2l and $l2s array used for translation 'long' <-> 'short' and
     * @return array of zids in 'long' (for $type=='l') or short form
     * @param $type
     */
    function translate($type) {
        $db = getDB();
        $SQL = "SELECT id, short_id FROM item WHERE ". $this->sqlin();
        $db->query($SQL);
        while ( $db->next_record() ) {
            $unpacked_id = unpack_id($db->f('id'));
            $this->l2s[$unpacked_id] = $db->f('short_id');
            $this->s2l[$db->f('short_id')] = $unpacked_id;
        }
        freeDB($db);
        // we need it in the same order as in source
        $ret = [];
        foreach ( $this->a as $idx => $zid ) {
            switch ($type) {
                case 'l': $ret[$idx] = $this->s2l[$zid]; break;
                case 'p': $ret[$idx] = pack_id($this->s2l[$zid]); break;
                default:  $ret[$idx] = $this->l2s[$zid];
            }
        }
        return $ret;
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
 * @return string
 */
function guesstype($str) {
    $s = strlen($str);
    if (($s < 12) AND (ctype_digit((string) $str) OR ($str==''))) {
        return 's';
    }
    if (($s >= 12) AND ($s <= 16)) {
        return 'p';
    }
    if (($s >= 24) AND ($s <= 32) AND ctype_xdigit($str)) {
        return 'l';
    }
    if ($s > 32) {
        return 't'; // Could also test last 32 hex
    }
    warn("Error, unable to guess type of id '$str' - ask mitra");
    return ('z');
}

/** type validating functions */
function is_short_id($id)  { return (strlen($id) < 12) AND ctype_digit((string) $id); }
function is_long_id($id)   { return ((strlen($id)==32) AND ctype_xdigit($id)); }
function is_packed_id($id) { return (strlen($id)==16); }

/** used instead of explode ('-'... which returns [0 => ''] for empty string and not empty string
 *  @return string[] - array of non empty ids
 *  @param  string   - dash-separated-ids
 */
function explode_ids($ids_string): array {
    return array_filter(array_map('trim',explode('-',$ids_string)));
}

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
    return explode('##Sx', str_replace(['#:', 'tp://', 'tps://', ':', '~@|_'], ['~@|_', 'tp~@|_//', 'tps~@|_//', '##Sx', ':'], $param));
}

function get_hash(...$arg_list) {
    // return md5(json_encode($arg_list));
    // return md5(var_export($arg_list, true));
    // return md5(serialize($arg_list));
    return hash('md5', serialize($arg_list));  // quicker than md5()
}

/** shortest possible hash which uses only url, DOM and JS identifier safe characters. Contains only a-zA-Z0-9, starts with character. */
function get_short_hash(...$arg_list) {
    // we do not need '-' - it is bad for DOM identifiers, '+' is bad for urls, '/' and even '_' has some problems in Dropzone, so we remove it as well.
    // We use Q as escape, so we replace Q as well to be able to convert back, if needed
    $hash = str_replace(['Q','+','/'], ['QA','QB','QC'], rtrim(base64_encode(hash('crc32b', serialize($arg_list), true)), '='));
    // we want to start with character so we change 1 to Q1, 2 to Q2, ... at the begin of the hash
    return is_numeric($hash{0}) ? 'Q'.$hash : $hash;
}

/** pack_id function
 * @param $unpacked_id
 * @return string packed md5 id, not quoted !!!
 * Note that pack_id is used in many places where it is NOT 128 bit ids.
 */
function pack_id($unpacked_id) {
    return ((string)$unpacked_id == "0" ? "0" : @pack("H*",trim($unpacked_id)));
}

// better version of pack_id()
function xpack_id($id) {
    // must be 32 character long hexadecimal number
    return is_long_id($id) ? "0x$id" : '0';
}

/** unpack_id
 * @param $packed_id
 * @return string unpacked md5 id
 */
function unpack_id($packed_id=''){
    return ((string)$packed_id != '0') ? bin2hex($packed_id) : '0';
}

/** quote function
 * function to double backslashes and apostrofs
 * @param $str
 * @return string
 */
function quote($str) {
    return addslashes($str);
}

/** q_pack_id function
 * returns packed and quoted id
 * @param $unpacked_id
 * @return string
 */
function q_pack_id($unpacked_id){
    $foo = pack_id($unpacked_id);
    return quote($foo);
}

/** qquote function
 * @param $str
 * @return string
 */
function qquote($str) {
    return "'".quote($str)."'";
}


/** sqlin function
 *  Return appropriate SQL for including in WHERE clause
 * Note that some code still does this by hand,
 * @param $column
 * @param $values - array of values
 * @return string
 */
function sqlin( $column, $values, $long=false ) {
    $fce = $long ? 'xpack_id' : 'qquote';
    switch (count($values)) {
    case 0: return '0';
    case 1: return "$column = ".$fce(reset($values));
    }
    return "$column IN (". implode(",",array_map($fce, $values)). ')';
}

/** id_t2l function
 * @param $str
 * @return null
 */
function id_t2l($str) {
    if (!$str) {
        warn("Warning: zids:id_t2l:converting empty string");
        return null;
    }
    // TODO: Look online for quicker way to substr last 32 chars - mitra
    if (preg_match('/^(.*?)([0-9a-f]{24,32})$/',$str,$parts)) {
        return $parts[2];
    }
    warn("Unable to parse tagged id '$str' - tell mitra");
}


