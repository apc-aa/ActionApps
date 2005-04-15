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

/** Removes empty positions and normalizes keys to be from 0 to x with step of 1
*/
function normalize_arr(&$arr) {
    $ret = false;
    if ( isset($arr) AND is_array($arr) ) {
        foreach ($arr as $v) {
            if ( isset($v) ) {
                $ret[] = $v;
            }
        }
    }
    return $ret;
}


// SiteTree and Spot class definition
$SPOT_VAR_NAMES = array ('id'         => 'id',        // translation from long variable
                         'name'       => 'n',       // names to the current - shorter
                         'conditions' => 'c',
                         'variables'  => 'v',
                         'parent'     => 'p',
                         'positions'  => 'po',
                         'choices'    => 'ch',
                         'flag'       => 'f');

class spot {
    var $id;          // spot id
    var $n;           // spot name
    var $c;           // spot conditions
    var $v;           // spot variables
    var $p;           // id of parent spot
    var $po;          // positions - array of spot ids defining the sequence
    var $ch;          // choices - array of spot ids defining the choices for the spot
    var $f;           // flags
    // the names of variables are short in order the outpot of serialize() function
    // would be as short as possible

    function spot($id=false, $name=false, $conditions=false, $variables=false, $parent=false, $positions=false, $choices=false, $flag=0) {
        $this->id = $id;
        $this->n  = $name;
        $this->c  = $conditions; // Array of conditions to match to be this
        // branch executed

        $this->v  = $variables;   // Array of variable names used in
        // branching. The only spots with variables
        // defined may branch the code
        $this->p  = $parent;
        $this->po = $positions;
        $this->ch = $choices;
        $this->f  = $flag;
    }

    function addInSequence($new_id) {
        $this->po[] = $new_id;
    }

    function addChoice($new_id) {
        $this->ch[] = $new_id;
    }

    function addVariable($name) {
        $this->v[$name] = $name;
    }

    function removeVariable($name) {
        unset($this->v[$name]);
    }

    function addCondition($var, $cond) {
        $this->c[$var] = $cond;
    }

    function removeCondition($name) {
        unset($this->c[$name]);
    }

    function isLeaf() {
        return ((!is_array($this->ch) OR (count($this->ch)<1)) AND (count($this->po)<2));
    }

    /** Check the positions (po) array and choices (ch) array,
    *  and fixes possible problems
    *     - removes empty positions (where they comwe from?)
    *     - normalizes keys to be from 0 to .. with step of 1
    */
    function normalize() {
        $this->po = normalize_arr($this->po);
        $this->ch = normalize_arr($this->ch);
    }

    function removeSpot( $spot_id ) {
        // search in options
        $priorsib = $this->id;
        if (isset($this->ch) AND is_array($this->ch)) {
            foreach ($this->ch as $k => $v) {
                if ( $v == $spot_id ) {
                    unset($this->ch[$k]);
                    return $priorsib;   // Returning prior sibling
                }
                $priorsib = $v;  // Used for where to move pointer to
            }
        }
        //search in sequence
        if ( isset($this->po) AND is_array($this->po) ) {
            foreach ($this->po as $k => $v) {
                if ( $v == $spot_id ) {
                    unset($this->po[$k]);
                    return $priorsib;   // Returning prior sibling
                }
                $priorsib = $v;  // Used for where to move pointer to
            }
        }
        return false;
    }

    function moveUp( $spot_id ) {
        $this->normalize();  // just check, if there are no problems in this spot
        // search in options
        if ( isset($this->ch) AND is_array($this->ch) ) {
            foreach ($this->ch as $k => $v) {
                if ($v == $spot_id) {
                    if ( $k==0 ) {
                        return false;
                    }
                    $this->ch[$k]   = $this->ch[$k-1];
                    $this->ch[$k-1] = $v;
                    return true;
                }
            }
        }
        //search in sequence
        if ( isset($this->po) AND is_array($this->po) ) {
            foreach ($this->po as $k => $v) {
                if ($v == $spot_id) {
                    if ( $k<=1 ) {   // can't move to the first position in sequence
                        return false;
                    }
                    $this->po[$k]   = $this->po[$k-1];
                    $this->po[$k-1] = $v;
                    return true;
                }
            }
        }
        return false;
    }

    function moveDown( $spot_id ) {
        $this->normalize();  // just check, if there are no problems in this spot
        // search in options
        if (isset($this->ch) AND is_array($this->ch)) {
            $last = count($this->ch)-1;
            foreach ($this->ch as $k => $v) {
                if ($v == $spot_id) {
                    if ($k == $last) {
                        return false;
                    }
                    $this->ch[$k]   = $this->ch[$k+1];
                    $this->ch[$k+1] = $v;
                    return true;
                }
            }
        }
        //search in sequence
        if (isset($this->po) AND is_array($this->po)) {
            $last = count($this->po)-1;
            foreach ($this->po as $k => $v) {
                if ($v == $spot_id) {
                    if (($k==0) OR ($k==$last)) {    // can't move to the first position in sequence
                        return false;
                    }
                    $this->po[$k]   = $this->po[$k+1];
                    $this->po[$k+1] = $v;
                    return true;
                }
            }
        }
        return false;
    }

    function Name()                        { return $this->n; }
    function Id()                          { return $this->id; }
    function Conditions()                  { return $this->c; }
    function Variables()                   { return $this->v; }

    function get_translated($what)         { return $this->$what; }
    function get($what)                    { return $this->get_translated($GLOBALS['SPOT_VAR_NAMES'][$what]); }

    function set_translated($what, $value) { $this->$what = $value; }
    function set($what,$value)             { $this->set_translated($GLOBALS['SPOT_VAR_NAMES'][$what], $value); }

    function conditionMatches(&$state) {
        $i=0;
        if ( isset($this->c) AND is_array($this->c) ) {  //c is array of conditions
            foreach ($this->c as $var => $cond) {
                if (!ereg($cond, $state[$var])) {
                    return false;
                }
            }
        }
        return true;
    }
};

class sitetree {
    var $tree; // Array of spots
    var $start_id;

    function sitetree($spot=false) {
        $this->tree[1]  = new spot( $spot['spot_id'], $spot['name'] ? $spot['name']:'start', $spot['conditions'], $spot['variables'], $spot['spot_id'], array($spot['spot_id']), $spot['flag'] );
        $this->start_id = $spot['spot_id'];
    }

    function addInSequence($where, $name, $content=false, $conditions=false, $variables=false, $flag=false) {
        $new_id = $this->new_id();

        //get real parent
        $parent_spot =& $this->tree[$where];
        if ( !$parent_spot->get('positions') ) {   //real parent must have positions set
            $parent = $parent_spot->get('parent');
            $parent_spot =& $this->tree[$parent];
        } else {
            $parent = $where;
        }

        $parent_spot->addInSequence($new_id);  // Note this is going to the spot, not recursing

        $this->tree[$new_id] = new spot( $new_id, $name, $conditions, $variables, $parent, $flag );
        return true;
    }

    function new_id() {
        return max(array_keys($this->tree))+1;
    }

    function addChoice($where, $name, $content=false, $conditions=false, $variables=false, $flag=false) {
        $new_id = $this->new_id();

        //get real parent
        $where_spot =& $this->tree[$where];
        if (!$where_spot->get('variables')) {  // before creating choice must be defined the list of dependency variables
            return false;
        }

        $where_spot->addChoice($new_id);
        $this->tree[$new_id] = new spot( $new_id, $name, $conditions, $variables, $where, array($new_id), $flag );
        return true;
    }

    function removeSpot( $spot_id ) {
        $spot =& $this->tree[$spot_id];

        if ($spot AND $spot->isLeaf()) {
            $parent_id = $spot->get('parent');
            $parent =& $this->tree[$parent_id];
            if (!$parent) {
                return false;
            }
            if ( $priorsib = $parent->removeSpot($spot_id)) {
                unset($this->tree[$spot_id]);
                return $priorsib;
            }
        }
        return false;
    }

    function move($spot_id, $direction) {
        $spot =& $this->tree[$spot_id];
        if (!$spot) {
            return false;
        }
        $parent_id = $spot->get('parent');
        $parent =& $this->tree[$parent_id];
        if (!$parent) {
            return false;
        }
        return $parent->$direction($spot_id);
    }

    function addVariable($where, $var) {
        //get real parent
        $where_spot =& $this->tree[$where];
        if (!$where_spot) {
            return false;
        }
        $where_spot->addVariable($var);
        return true;
    }

    function removeVariable($where, $var) {
        //get real parent
        $where_spot =& $this->tree[$where];
        if (!$where_spot) {
            return false;
        }
        $where_spot->removeVariable($var);
        return true;
    }

    function addCondition( $where, $var, $cond ) {
        //get real parent
        if (!$this->isChoice($where)) {
            return false;
        }
        $where_spot =& $this->tree[$where];
        $where_spot->addCondition($var, $cond);
        return true;
    }

    function removeCondition( $where, $var ) {
        //get real parent
        $where_spot =& $this->tree[$where];
        if (!$where_spot) {
            return false;
        }
        $where_spot->removeCondition($var);
        return true;
    }

    function isChoice($spot_id) {
        $spot =& $this->tree[$spot_id];
        if (!$spot) {
            return false;
        }
        $parent_spot_id = $spot->get('parent');
        if (!$parent_spot_id OR !($vars=$this->get('variables',$parent_spot_id))) {
            return false;
        }
        return $vars;
    }

    function isOption($spot_id) {
        $spot =& $this->tree[$spot_id];
        if (!$spot) {
            return false;
        }
        $parent_spot_id = $spot->get('parent');
        if ( !$parent_spot_id OR !($choices=$this->get('choices',$parent_spot_id)) ) {
            return false;
        }
        if (isset($choices) AND is_array($choices)) {
            foreach ($choices as $v) {
                if ($v == $spot_id) {
                    return $this->get('variables',$parent_spot_id);
                }
            }
        }
        return false;
    }

    // Find the spot from the tree, and then do a get on the spot.
    function get($what, $id) {
        $s =& $this->tree[$id];
        return $s ? $s->get($what) : false;
    }

    function set($what, $id, $value) {
        $s =& $this->tree[$id];
        if ($s) {
            $s->set($what,$value);
        }
    }

    function getName($id) { return $this->get( 'name', $id ); }
    function exist($id)  { return isset($this->tree[$id]); }

    function haveBranches($id) {
        return $this->get('choices', $id) ? true : false;
    }

    function isSequenceStart($id) {
        return $this->get('positions', $id) ? true : false;
    }

    function conditionMatches( $id, &$state ) {
        $s =& $this->tree[$id];
        return $s ? $s->conditionMatches($state) : false;
    }

    //Walk the tree, starting at $id, calling $function
    function walkTree(&$state, $id, $function, $method='cond', $depth=0) {
        global $debugsite;
        if ($debugsite) huhl("state=",$state,"id=",$id,"function=$function method=$method depth=$depth");
        $current =& $this->tree[$id];
        $positions = $current->get("positions");
        if (!$positions) {
            echo 'something is wrong - no "positions" for parent';
            exit;
        }
        foreach ($positions as $poskey => $pos) {
            if ($debugsite) huhl("Position",$pos);
            if ($pos) {
                // There is a bug that introduced empty positions
                // this is to skip them.
                if (($method=='all') OR !($this->get("flag",$pos) & MODW_FLAG_DISABLE)) { 
                    $function($pos, $depth);
                }
                if ( $this->haveBranches($pos) AND (($method == 'cond') OR !($current->get("flag") & MODW_FLAG_HIDE))) {  // MODW_FLAG_HIDE -  prepared for collapsed branches. Not used, yet
                    $chcurrent =& $this->tree[$pos];
                    $choices = $chcurrent->get("choices");
                    if ( !$choices ) {
                        echo "something is wrong - haveBranches but it has not choices[]";
                        exit;
                    }
                    ksort($choices); // might not be in key order
                    foreach ($choices as $k => $cho) {
                        if ($debugsite) huhl("Choice: $cho");
                        if ($cho) { // skip buggy empty choices
                            if (($method=='all') OR ($this->conditionMatches($cho, $state) AND !($this->get("flag",$cho) & MODW_FLAG_DISABLE))) {
                                $this->walkTree($state, $cho, $function, $method, $depth+1);
                                if ($method=='cond') {
                                    break;                 // one matching spot is enough
                                }
                            }
                        } else {
                            if ($GLOBALS["sitefix"]) {
                                huhl("Before fix position($pos)=",$chcurrent);
                                unset($chcurrent->ch[$k]);
                                huhl("After fix position($pos)=",$chcurrent);
                            } else {
                                huhe("Warning: skipping Empty choice in position=$pos, run with &amp;sitefix=1 to fix; tree=",$this);
                            }
                        }
                    } // each choice
                } // haveBranches
            } else {  // Empty $pos
                if ($GLOBALS["sitefix"]) {
                    huhl("Before fix: poskey=$poskey val=",$current->po,"cur=", $current);
                    unset($current->po[$poskey]);
                    huhl("After fix: ",$current);
                } else {
                    huhe("Warning: skipping Empty position in id=$id key $poskey of tree=",$this->tree[$id]);
                }
            }
        } // each position
    } // function
};
?>
