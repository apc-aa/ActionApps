<?php
//$Id$
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

#
# Functions for manipulating ids.
#
# Author and Maintainer: Mitra mitra@mitra.biz
#
# This is developed from functions originally in util.php3, where many
# still exist. They can gradually be included here
#
# Notes on naming conventions
#       zids    one of these objects
#       zid     a string that could be any type of id
#
# Ids handled on input
#       shortid  type=s
#       packedid type=p
#       quotedpackedid   NOT YET SUPPORTED ON INPUT
#       longid  type=l
#       taggedid type=t
# The type of an id, is the first letter of these, or any other string
#
# Hints on integrating this with other code
#
# Hints on modifying this code - ask mitra if unsure
#       The code uses the &$xxx syntax for efficiency wherever possible,
#               but does not unless clearly commented change the passed var
#
# Supported functions, and which types work with
# longids       p l   e.g. 0112233445566778899aabbccddeeff0
# packedids     p l   e.g. A!D\s'qwertyuio
# q_packedids   p l   e.g. A!D\\ss\'qwertyuio
# qq_packedids  p l   e.g. 'A!D\\ss\'qwertyuio'
# qqq_packedids p l   e.g. "A!D\\ss\'qwertyuio"
# shortids      s     e.g. 1234

require_once $GLOBALS["AA_INC_PATH"]."util.php3";  // quote

class zids {
        var $a; # Array of ids of type specified in $t
            # Note encapsulation broken in itemview which sets this directly
        var $type;  # Type of $a
        var $l2s;   // array used for translation from 'long' to 'short' type
        var $s2l;   // array used for translation from 'short' to 'long' type

    # Constructor can be called with an array, or a zid
    function zids($initial = null, $inittype = "z"){  # constructor
        $this->refill($initial, $inittype);
    }

    # Refills the array, may be called with an array, or a zid
    function refill($initial = null, $inittype = "z"){
        global $debug;
        #$inittype is for where type is known
        # Note it refers to the type of ELEMENTS if its an array
        $this->type = $inittype;
        if ($initial and is_array($initial)) { # Array
            $this->a = array();  # Make sure at least empty array
            if (is_array($initial[0])) { # Array of fields
                foreach ( $initial as $field ) {
                    if ( $field['value'] ) {                   # copy just not empty ids
                        $this->a[] = $field['value'];
                    }
                }
            } else {
                foreach ( $initial as $id ) {
                    if ( $id ) {                   # copy just not empty ids
                        $this->a[] = $id;
                    }
                }
            }
        } elseif ($initial) {  # Single id
                $this->a[] = $initial;
        } else {
           $this->$type=$inittype;    # Prepare for zids
           return;                    # Empty $zids;
        }

        if ($this->type == "z")
            $this->type = guesstype($this->a[0]);
        elseif ($this->type != guesstype($this->a[0]))
            huhe("Warning: zids created type=$this->type but id $this->a[0] looks like type="
                . guesstype($this->a[o]));
    }

    /** Grabs long ids from array as posted from manager.class checkboxes
     *  $items[x767ab56353544242552637389a853673]=1
     */
    function set_from_item_arr($item_arr) {
         $this->clear('l');
         if ( isset($item_arr) AND is_array($item_arr) ) {
             foreach ( $item_arr as $it_id => $foo ) {
                 $this->a[] = substr($it_id,1);      // remove initial 'x'
             }
         }
    }

    // removes all zids and resets
    function clear($inittype = 'z') {
        unset($this->a);
        $this->type = $inittype;
    }

    /** Adds new id or array of ids or zids object.
    *   The type must be already set (from init). */
    function add($ids) {
        if ( isset($ids) AND is_object($ids) ) {           // zids
            if ($ids->onetype() == $this->onetype()) {
                $this->a = array_merge($this->a, $ids->a);
            } else {
                return false;
            }
        } elseif ( isset($ids) AND is_array($ids) ) {      // array of ids
            $this->a = array_merge($this->a, $ids);
        } elseif ( $ids ) {                                // id
            $this->a[] = $ids;
        } else
            return false;
        return true;
    }

    /** Adds new id or array of ids or zids object and deletes duplicate ids.
    *   If called without parameters, only deletes duplicate ids.
    *   The type must be already set (from init). */
    function union($ids = "") {
        $this->add ($ids);
        if ($this->count() > 0 ) {
            // we can't use array_unique because we need to preserve key range 0..x
            sort ($this->a);
            $last = "XXXXXXXX";
            $unique = "";
            for (reset ($this->a); list (,$v) = each($this->a);) {
                if ($v && $v != $last)
                    $unique[] = $v;
                $last = $v;
            }
            if ($v && $v != $last)
                $unique[] = $v;
            $this->a = $unique;
        }
    }

    // Debugging function to print zids, don't rely on the output format, its only for debuging
    function printobj() {
        print("zids object: type=".$this->type." (". ($this->count()<=0 ? 'Empty' : implode(",",$this->a) ).")");
    }

    # Return one-character type for standard types
    # be careful of how extension types are handled
    function onetype() {
        #TODO - handle other types than single character types
        return $this->type;
    }

    # Count how many ids
    function count() {
        if (is_array ($this->a))
            return count($this->a);
        else return 0;
    }

    # Quick check to warn if item doesn't exist
    function warnid($i=null,$warnstr="") {
        if( (isset($i) and !(isset($this->a[$i]))) ) {
            huhe("Warning: zids: $warnstr, item $i doesn't exist, returning null");
            return true;
        }
        return false;
    }
    # Return an array of long ids
    # TODO: look at where used, typically used in interface to pre-zid code
    function longids($i=null) {
        if ($this->warnid($i,"longids")) return null;
        switch ($this->type) {
        case "l":  return (isset($i) ? $this->a[$i] : $this->a);
        case "p":  return (isset($i) ? unpack_id128($this->a[$i])
                        : array_map("unpack_id128", $this->a));
        case "t":  return (isset($i) ? id_t2l($this->a[$i]) : array_map("id_t2l", $this->a));
        case 's':  $trans = $this->translate('l');
                   return (isset($i) ? $trans[$i] : $trans );
        default:
        print("ERROR - zids:longids(): can't handle type $this->type conversion to longids - ask mitra");
        $this->printobj();
        return false;  #TODO - handle other types
        }
    }

    function packedids($i=null) {
        if ($this->warnid($i,"packedids")) return null;
        switch ($this->type) {
            case 'p': return (isset($i) ? $this->a[$i] : $this->a);
            case 'l': return (isset($i) ? pack_id128($this->a[$i]) : array_map("pack_id128",$this->a));
            case 't': return (isset($i) ? pack_id128(id_t2l($this->a[$i]))
                                : array_map("pack_id128", $this->longids()));
            case 's': $trans = $this->translate('l');
                      return ( isset($i) ? pack_id128($trans[$i]) : array_map("pack_id128", $trans));
        default:
            print("ERROR - zids:packedids(): can't handle type $this->type conversion to packedds - ask mitra");
            return false;
        }
    }

    # Return quoted ids, i.e. with slashes doubled and apostrophes slashed
    # appropriate for putting in SQL (inside "")
    # note, contrary to its name, this does NOT put quotes or double quotes
    # around the ids
    function q_packedids($i=null) {
        if ($this->warnid($i,"q_packedids")) return null;
        switch ($this->type) {
            case "p": return (isset($i) ? quote($this->a[$i]) : array_map("quote",$this->a));
            case "l": return (isset($i) ? q_pack_id($this->a[$i]) : array_map("q_pack_id",$this->a));
            case "t": return (isset($i) ? q_pack_id(id_t2l($this->a[$i]))
                                : array_map("q_pack_id", $this->longids()));
        default:
            print("ERROR - zids:q_packedids(): can't handle type $this->type conversion to packedds - ask mitra");
            return false;
        }
    }

    # As above, but inside single quotes
    function qq_packedids($i=null) {
        if ($this->warnid($i,"qq_packedids")) return null;
        switch ($this->type) {
            case "p": return (isset($i) ? qquote($this->a[$i]) : array_map("qquote",$this->a));
            case "l": return (isset($i) ? qq_pack_id($this->a[$i])
                : array_map("qq_pack_id",$this->a));
            case "t": return (isset($i) ? qq_pack_id(id_t2l($this->a[$i]))
                : array_map("qq_pack_id", $this->longids()));
        default:
            print("ERROR - zids:qq_packedids(): can't handle type $this->type conversion to packedds - ask mitra");
            return false;
        }
    }

    # As above, but inside double quotes
    function qqq_packedids($i=null) {
        if ($this->warnid($i,"qqq_packedids")) return null;
        switch ($this->type) {
            case "p": return (isset($i) ? qqquote($this->a[$i]) : array_map("qqquote",$this->a));
            case "l": return (isset($i) ? qqq_pack_id($this->a[$i])
                : array_map("qqq_pack_id",$this->a));
            case "t": return (isset($i) ? qqq_pack_id(id_t2l($this->a[$i]))
                : array_map("qqq_pack_id", $this->longids()));
        default:
            print("ERROR - zids:qqq_packedids(): can't handle type $this->type conversion to packedds - ask mitra");
            return false;
        }
    }

    function shortids($i=null) {
        if ($this->warnid($i,"shortids")) return null;
        if ( $this->type == 's' ) return (isset($i) ? $this->a[$i] : $this->a);
        $l_zids = new zids( $this->longids(),'l');  // convert to long (for translation)
        $trans = $l_zids->translate('s');
        return isset($i) ? $trans[$i] : $trans;
    }

    # Return either short id, or a long id, depending on use_short_ids()
    # These are ids suitable for indexing return from GetItemContent
    function short_or_longids($i=null) {
        if ($this->warnid($i,"short_or_longids")) return null;
        return ($this->use_short_ids()
            ? $this->shortids($i)
            : $this->longids($i));
    }

    function use_short_ids() {
        if ($this->type == "s") return true;
        return false;
    }

    # Return nth id, note there is no guarrantee what format this will be in, so its
    # only really useful for serialization or if type is checked as well
    function id($idx) {
        return $this->a[$idx];
    }

    # Create a new zids, from a subset of the data,  with the same type
    # Parameters are same as for "array_slice"
    function slice($offset, $length) {
        if (is_array ($this->a))
            return new zids(array_slice($this->a,$offset,$length),$this->type);
        else return new zids(null, $this->type);
    }

    # Return associative array, longid->tag;
    function gettags() {
        if ($this->type != "t") return false;
        $tags = array();
        while ( list(,$v) = each($this->a)) {
            if (preg_match('/^(.*?)([0-9a-f]{24,32})$/',$v,$parts))
                $tags[$parts[2]] = $parts[1]; # Note can be empty
            else
                print("Cant parse tagged id '$v' - tell Mitra");
        }
        return $tags;
    }

    # Restore tags in array, by looking for ids in zids2
    # Return resulting new zids
    # Reasonably efficent, only loops through each array once
    function retag($zids) {
        if ($debug) huhl("Retagging zids=",$zids);
        if ($zids->type != "t") return $this;  # Array is fine
        $tags = array();
        while ( list(,$v) = each($zids->a)) {
            switch ($this->type) {
            case 'p': $k = pack_id128(id_t2l($v)); break;
            default:
                print("<br>Error: zids: can't retag array of type '$type', tell mitra");
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

  # Return appropriate SQL for including in WHERE clause
  # Note that some code still does this by hand,
  function sqlin( $add_column = true ) {
    if ($this->count() == 0) return "";
    if ( $add_column )
        $column = ( $this->use_short_ids() ? "item.short_id" : "item.id" );
    if ($this->use_short_ids())
        return "$column IN ("
            . implode(",", array_map( "qquote", $this->shortids())) . ")";
    else
        return "$column IN ("
            . implode(",", $this->qqq_packedids()) . ") ";
  }

  /** Sorts zids array in the same order as the zids are in $sort_zids */
  function sort_and_restrict_as_in($sort_zids) {
      $translation = $this->get_translation($sort_zids->onetype());
      foreach ( $sort_zids->a as $zid ) {
          if ( $translation[$zid] )  $ret[] = $translation[$zid];
      }
      $this->a = $ret;
  }

  /** fills $s2l and $l2s array used for translation 'long' <-> 'short' and
   *  return array of zids in 'long' (for $type=='l') or short form
   */
  function translate($type) {
      $db = getDB();
      $SQL = "SELECT id, short_id FROM item WHERE ". $this->sqlin();
      $db->tquery($SQL);
      while( $db->next_record() ) {
          $unpacked_id = unpack_id128($db->f('id'));
          $this->l2s[$unpacked_id] = $db->f('short_id');
          $this->s2l[$db->f('short_id')] = $unpacked_id;
      }
      freeDB($db);
      // we need it in the same order as in source
      foreach ( $this->a as $idx => $zid ) {
          $ret[] = ($type=='l') ? $this->s2l[$zid] :
                   ($type=='p') ? pack_id128($this->s2l[$zid]) :
                                  $this->l2s[$zid] ;
      }
      return $ret;
  }

  function get_translation($from_type) {
      if ( $this->count() <= 0 )
          return array();

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
} #class zids

# This guesses the type from the length of the id,
# short should be == 16 and long == 32 but there is or was somewhere a bug
# leading to shorter (as short as 14) character ids.
function guesstype($str) {
        $s = strlen($str);
        if (($s >= 12) and ($s <= 16)) return 'p';
        if (($s >= 24) and ($s <= 32)) return 'l'; # Could also test 32 hex
        if ($s > 32) return 't'; # Could also test last 32 hex
        if ($s < 16) return 's';
        print("Error, unable to guess type of id '$str' - ask mitra");
        return ('z');
}

# returns packed md5 id, not quoted !!!
# Note that pack_id is used in many places where it is NOT 128 bit ids.
# This version is ONLY for 128 bit ids.
function pack_id128($unpacked_id){
    global $errcheck;
    if ($errcheck && !preg_match("/^[0-9a-f]{24,32}$/", $unpacked_id)) # Note was + instead {32}
        huhe("Warning: trying to pack $unpacked_id.<br>\n");
  return ((string)$unpacked_id == "0" ? "0" : @pack("H*",trim($unpacked_id)));
}

# returns unpacked md5 id
# Note this will NOT unpack correctly a quoted packed id
function unpack_id128($packed_id){
  if( (string)$packed_id == "0" )
    return "0";
  $foo=bin2hex($packed_id);  // unpack("H*", $str) does not work in PHP 4.0.3 so bin2hex used
  if ($errcheck && !preg_match("/^[0-9a-f]{24,32}$/", $foo)) # Note was + instead {32}
    huhe("Warning: unpacked id to $foo..<br>\n");
  return (string)$foo;
}


# returns packed and quoted md5 id
function q_pack_id ($unpacked_id){
  $foo = pack_id128($unpacked_id);
  return (quote($foo));
}
function qq_pack_id($str) {
        return ("'".q_pack_id($str)."'");
}
function qqq_pack_id($str) {
        return ('"'.q_pack_id($str).'"');
}
function qquote($str) {
        return ("'".quote($str)."'");
}
function qqquote($str) {
        return ('"'.quote($str).'"');
}

function id_t2l(&$str) {
    global $errcheck;
        if (!$str) {
            huhe(print("Warning: zids:id_t2l:converting empty string"));
            return null;
        }
        #TODO: Look online for quicker way to substr last 32 chars - mitra
        if (preg_match('/^(.*?)([0-9a-f]{24,32})$/',$str,$parts))
                return $parts[2];
        print("Unable to parse tagged id '$str' - tell mitra");
}


?>