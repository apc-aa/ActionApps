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
            if (is_array($initial[0])) { # Array of fields 
                $this->a = array();  # Make sure at least empty array
                reset($initial);
                while (list(,$v) = each($initial)) {
                    if (isset($initial[0][value])) # Test needed, because often empty value
                        $this->a[] = $v[value];
                }
            } else {
                if ($initial[0] != "") {
                    reset($initial);
                    $this->a = $initial;
                }
            }
        } elseif ($initial) {  # Single id
                $this->a[] = $initial;
        } else {
         return;  # Empty $zids;
        }

        if ($this->type == "z") 
            $this->type = guesstype($this->a[0]);
        elseif ($this->type != guesstype($this->a[0]))
            huhe("Warning: zids created type=$this->type but id $this->a[0] looks like type="
                . guesstype($this->a[o]));
    }

    // Debugging function to print zids, don't rely on the output format, its only for debuging
    function printobj() {
        print("zids object: type=".$this->type." (".implode(",",$this->a).")");
    }

    # Return one-character type for standard types
    # be careful of how extension types are handled
    function onetype() {
        #TODO - handle other types than single character types
        return $this->type;
    }
    # Count how many ids
    function count() {
        return count($this->a);
    }

    # Quick check to warn if item doesn't exist
    function warnid($i=null,$warnstr="") {
        #huhl("zids:$warnstr:$i",$this);
        if (isset($i) and !(isset($this->a[$i]))) {
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
        default:
        print("ERROR: can't handle type $this->type conversion to longids - ask mitra");
        return false;  #TODO - handle other types
        }
    }

    function packedids($i=null) { 
        if ($this->warnid($i,"packedids")) return null;
        switch ($this->type) {
            case "p": return (isset($i) ? $this->a[$i] : $this->a);
            case "l": return (isset($i) ? pack_id128($this->a[$i]) : array_map("pack_id128",$this->a));
            case "t": return (isset($i) ? pack_id128(id_t2l($this->a[$i])) 
                                : array_map("pack_id128", $this->longids()));
        default:
            print("ERROR: can't handle type $this->type conversion to packedds - ask mitra");   
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
            print("ERROR: can't handle type $this->type conversion to packedds - ask mitra");
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
            print("ERROR: can't handle type $this->type conversion to packedds - ask mitra");   
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
            print("ERROR: can't handle type $this->type conversion to packedds - ask mitra");   
            return false;
        }
    }

    function shortids($i=null) {
        if ($this->warnid($i,"shortids")) return null;
        switch($this->type) {
            case "s":  return (isset($i) ? $this->a[$i] : $this->a);
        default:
            print("ERROR: can't handle type $this->type conversion to shortids - ask mitra");
            return false;
        }
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
        return new zids(array_slice($this->a,$offset,$length),$this->type);
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
        while ( list(,$v) = each($this->a)) {
            $b[] = $tags[$v];
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

} #class ids

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
  return ((string)$unpacked_id == "0" ? "0" : pack("H*",trim($unpacked_id)));
}

# returns unpacked md5 id
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