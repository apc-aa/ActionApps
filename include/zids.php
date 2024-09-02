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
use AA\Util\ArrTrait;


/**
 * Functions for manipulating ids.
 *
 * Author and Maintainer: Mitra mitra@mitra.biz
 *
 * This is developed from functions originally in util.php, where many
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
 * The type of id, is the first letter of these, or any other string
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

//require_once __DIR__."/util.php";  // quote

class zids implements Iterator, ArrayAccess, Countable {
    use ArrTrait;

    protected array $arr = [];  // Array of ids of type specified in $t

    protected string $type;     // Type of $a
    protected array $l2s = [];  // array used for translation from 'long' to 'short' type
    protected array $s2l = [];  // array used for translation from 'short' to 'long' type
    protected array $_ext = []; // array of extended parameters (we are using it when we store group_by additional info into zids)

    /** zids function
     *  Constructor can be called with an array, or a zid
     * @param $initial
     * @param string $inittype l|p|s|w|t|z
     * @param array $extended_att  additional attribute for each zid. It is in the form
     *                             array('attr'=>array(<array of attr for zids in the same based array, as $initial>))
     */
    function __construct($initial = null, $inittype = "z", $extended_att = []){  // constructor
        // $inittype is for where type is known
        // Note it refers to the type of ELEMENTS if it is an array
        $this->type = $inittype;
        $this->arr  = [];  // Make sure at least empty array
        $this->_ext = is_array($extended_att) ? $extended_att : [];

        if (empty($initial)) {
            return;
        }

        if (is_array($initial)) { // Array
            if (is_array($initial[0])) { // Array of fields
                foreach ( $initial as $field ) {
                    if ( $field['value'] ) {           // copy just not empty ids
                        $this->arr[] = $field['value'];
                    }
                }
            } else {
                 $this->arr = array_values(array_filter($initial,'trim'));  // copy just not empty ids and assign them form 0 index
            }
        } else {
            $this->arr[] = $initial;
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
            case 's': $this->arr = array_values(array_filter($this->arr, 'is_short_id'));  break;
            case 'l': $this->arr = array_values(array_filter($this->arr, 'is_long_id'));   break;
            case 'p': $this->arr = array_values(array_filter($this->arr, 'is_packed_id')); break;
            case 'w': break;   // strings stored as plain strings - not "'" added in front
        }
    }

    /** fills $this->>type with current zids type */
    function guesstype() {
        foreach ($this->arr as $val) {
            if ( ($this->type = guesstype($val)) != 'z' ) {
                break;
            }
        }
        return $this->type;
    }

    /** setFromItemArr function
     * Grabs long ids from array as posted from manager.class checkboxes
     *  $items[x767ab56353544242552637389a853673]=1
     * @param array $item_arr
     * @return zids
     */
    public function setFromItemArr(array $item_arr) {
        return $this->clear($this->type)->add(array_map(fn ($k) => rawurldecode(str_starts_with($k, 'x') ? substr($k, 1) : $k), array_keys($item_arr)));   // in some managers we use 'x' before the item id - remove it
    }

    /** copy all data from provided zids */
    public function cloneFrom(zids $zids) {
        $this->arr  = $zids->arr;
        $this->type = $zids->type;
        $this->_ext = $zids->_ext;
    }

    /** clear function - removes all zids and resets
     * @param $inittype
     * @return zids
     */
    function clear($inittype = 'z') {
        $this->arr  = [];
        $this->type = $inittype;
        $this->_ext = [];
        return $this;
    }


    /** adds ids from $ids array and checks if the values are short or long
     *  wrong values are not added
     * @return zids this
     */
    function addDirty($ids) {
        if (str_starts_with(reset($ids), '[')) {
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
                $this->arr[] = $id;
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
                $this->arr  = $ids->arr;
                $this->type = $ids->onetype();
            } elseif ($ids->onetype() == $this->onetype()) {
                $this->arr = array_merge($this->arr, (array)$ids->arr);
            }
        } elseif ( isset($ids) AND is_array($ids) ) {      // array of ids
            $this->arr = array_merge($this->arr, $ids);
        } elseif ( $ids ) {                                // id
            $this->arr[] = $ids;
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
            sort ($this->arr);
            $last = "XXXXXXXX";
            $unique = [];
            foreach ($this->arr as $v) {
                if ($v && $v != $last) {
                    $unique[] = $v;
                }
                $last = $v;
            }
            $this->arr = $unique;
        }
    }

    /** Debugging function to print zids
     *  Don't rely on the output format, its only for debuging
     */
    function __toString() {
        return "zids object: type=".$this->type." (". ($this->count()<=0 ? 'Empty' : implode(",",$this->arr) ).")";
    }

    /** onetype function
     * @return string one-character type for standard types
     * be careful of how extension types are handled
     */
    function onetype() {
        // TODO - handle other types than single character types
        return $this->type;
    }

    /** Is zids empty? */
    function isEmpty() {
        return (count($this->arr) < 1);
    }

    /** warnid function
     * Quick check to warn if item doesn't exist
     */
    function warnid($i=null,$warnstr="") {
        if ( (isset($i) and !(isset($this->arr[$i]))) ) {
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
            case "l":  return (isset($i) ? $this->arr[$i]            : $this->arr);
            case "p":  return (isset($i) ? unpack_id($this->arr[$i]) : array_map("unpack_id", $this->arr));
            case "t":  return (isset($i) ? id_t2l($this->arr[$i])    : array_map("id_t2l", $this->arr));
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
            return (isset($i) ? $this->arr[$i] : $this->arr);
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
     * Return nth id, note there is no guarantee what format this will be in, so its
     * only really useful for serialization or if type is checked as well
     * @param $idx
     * @return
     */
    function id($idx=0) {
        return $this->arr[$idx];
    }

    /** similar to short_or_longids() but is for one value and counts with string zids ('s', 'l', 'w') */
    public function id_slw(int $idx=0) {
        if (array_key_exists($idx, $this->arr)) {
            return in_array($this->type, ['s','l','w']) ? $this->arr[$idx] : $this->longids($idx);
        }
        return false;
    }

    /**
     * @return array used for where condition for item table in DB_AA::select*
     */
    public function getDbWhereArray() {
        switch ($this->type) {
            case 'p': return ['id', $this->arr];
            case 'l': return ['id', $this->arr, 'l'];
            case 't': return ['id', $this->longids(), 'l'];
            case 's': return ['short_id', $this->arr, 'i'];
        }
        warn("ERROR - zids::getDbWhereArray(): can't handle type $this->type");
        return ['id', []];  // never satisfy condition... (1=2)
    }

    /** sanitize ids  - restrict (filter) item ids from specified slice
     * @param  string $slice_id
     * @return zids
     */
    public function itemFilter(array $slice_ids) {
        if (($this->count() < 1) OR !$slice_ids) {
            return $this->clear('l');
        }
        $where = [['slice_id',$slice_ids,'l'],$this->getDbWhereArray()];
        return $this->clear('l')->add(DB_AA::select('', "SELECT LOWER(HEX(id)) FROM `item`", $where));
    }

    /** Create a new zids, from a subset of the data,  with the same type
     *  Parameters are same as for "array_slice"
     * @param $offset
     * @param $length
     * @return zids
     */
    function slice($offset, $length=1): zids  {
        return new zids(array_slice($this->arr,$offset,$length),$this->type);
    }

    /** zid function
     *  Returns n-th zid
     * @param $index
     * @return zids
     */
    function zid($index): zids {
        return new zids($this->arr[$index], $this->type);
    }

    /** get the ids array as is */
    function getArray(): array {
        return $this->arr;
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
        foreach ($this->arr as $v ) {
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
            foreach ($this->arr as $v) {
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
            if ( $asis OR in_array($this->type, ['s','w']) ) {
                $id_list = join(",",array_map("qquote",$this->arr));
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
        foreach ($this->arr as $i => $id ) {
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
        foreach ($this->arr as $idx => $zid ) {
            $ret[$idx] = match ($type) {
                'l'     => $this->s2l[$zid],
                'p'     => pack_id($this->s2l[$zid]),
                default => $this->l2s[$zid],
            };
        }
        return $ret;
    }

    /** @return zids[] array of zids in chunks with max $size ids */
    function chunk(int $size): array {
        $chunks = array_chunk($this->arr, $size);
        $ret = [];
        foreach ($chunks as $chunk) {
            $ret[] = new zids($chunk, $this->type);
        }
        return $ret;
    }

    /** creates string id - it starts with ' and ideally one letter identifier of string type, then string id and possibly type or class or selector
     * @param string $type - ideally one letter identifier of string type - like 'C' for AA Class object
     * @param string $id   - any alphanumeric string with id (not containing '/')
     * @param string $sel  - possibly some kind of type or class or selector - in context of $type the $id+$sel is the unique identifier - later we can add sel2, sel3, ... separated by /
     * @return string
     */
    public static function sting_id(string $type, string $id, string $sel=''): string {
        return "'{$type}/{$id}". (strlen($sel) ? "/{$sel}" : '');
    }

    // Iterator, ArrayAccess and Countable interface implementation is in ArrTrait --------------------------

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
    if (is_string_id($str)) { // packed id newer starts with '
        return 'w';  // text id
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
function is_short_id($id): bool  { return (strlen($id) < 12) AND ctype_digit((string) $id); }
function is_long_id($id): bool   { return ((strlen($id)==32) AND ctype_xdigit($id)); }
function is_packed_id($id): bool { return (strlen($id)==16); }
function is_string_id($id): bool { return ($id[0] == "'"); }

/** used instead of explode ('-'... which returns [0 => ''] for empty string and not empty string
 *  @return string[] - array of non-empty ids
 *  @param  string   - dash-separated-ids
 */
function explode_ids($ids_string): array {
    return array_filter(array_map('trim',explode('-',$ids_string)));
}

/** ParamExplode(), unpack_id() and get_hash() functions moved here from
 *  util.php because we do not want to include util.php file for cached pages,
 *  but, we still need zids.php and those 3 functions for cache
 */

/** ParamExplode function
 * explodes $param by ":". The "#:" means true ":" - don't separate
 * @param $param
 * @return array
 */
function ParamExplode($param) {
    // replace all "#:" and <http>"://" with dummy string,
    // convert separators to ##Sx
    // change "#:" to ":" and change back "://" - then split by separation string
    // replaces in order
    return explode('##Sx', str_replace(['#:', 'tp://', 'tps://', ':', '~@|_'], ['~@|_', 'tp~@|_//', 'tps~@|_//', '##Sx', ':'], $param));
}


/** @see also get_short_hash for shorter hash */
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
    return is_numeric($hash[0]) ? 'Q'.$hash : $hash;
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
function unpack_id($packed_id=''): string {
    return ((string)$packed_id != '0') ? bin2hex($packed_id) : '0';
}

/** function to double backslashes and apostrophes
 * @param $str
 * @return string
 */
function quote($str): string {
    return addslashes($str);
}

/**
 * returns packed and quoted id - better to use xpack_id()
 * @param $unpacked_id
 * @return string
 */
function q_pack_id($unpacked_id): string {
    return quote(pack_id($unpacked_id));
}

/** qquote function
 * @param $str
 * @return string
 */
function qquote($str): string {
    return "'".quote($str)."'";
}


/** sqlin function
 *  Return appropriate SQL for including in WHERE clause
 * Note that some code still does this by hand,
 * @param $column
 * @param $values - array of values
 * @return string
 */
function sqlin( $column, $values, $long=false ): string {
    $fce = $long ? 'xpack_id' : 'qquote';
    return match (count($values)) {
        0       => '0',
        1       => "$column = ".$fce(reset($values)),
        default => "$column IN (".implode(",", array_map($fce, $values)).')',
    };
}

/** id_t2l function
 * @param $str
 * @return string|null
 */
function id_t2l($str): ?string {
    if (!$str) {
        warn("Warning: zids:id_t2l:converting empty string");
        return null;
    }
    // TODO: Look online for quicker way to substr last 32 chars - mitra
    if (preg_match('/^(.*?)([0-9a-f]{24,32})$/',$str,$parts)) {
        return $parts[2];
    }
    warn("Unable to parse tagged id '$str' - tell mitra");
    return null;
}

