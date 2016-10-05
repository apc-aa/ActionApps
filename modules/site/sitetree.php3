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
$SPOT_VAR_NAMES = array ('id'         => 'id',      // translation from long variable
                         'name'       => 'n',       // names to the current - shorter
                         'conditions' => 'c',
                         'variables'  => 'v',
                         'parent'     => 'p',
                         'positions'  => 'po',
                         'choices'    => 'ch',
                         'flag'       => 'f');

class AA_Site_Spot_Match {
    public $op;
    public $val;

    function __construct($op='', $val='') {
            $this->op  = $op;
            $this->val = $val;
    }

    public static function factoryByString($string, $var=null) {
        if ( strpos($string,'=:') === 0 ) { // begins with =: - special identification of new matches
            $arr = ParamExplode($string);
            return new AA_Site_Spot_Match($arr[1], $arr[2]);
        }
        // special case - for {aa_expresions} we used "=" in history
        return new AA_Site_Spot_Match(($var AND $var[0]=='{') ? '=' : 'REGEXP', $string);
    }

    function implode() {
        return ParamImplode(array('=',$this->op, $this->val));
    }

    public function match($value) {
        switch ( $this->op ) {
            case '=':        return ($value ==  $this->val);
            case 'REGEXP':   return preg_match('/'. str_replace('/', '\/', $this->val) .'/', $value);
            case 'contains': return (strpos($this->val, $value) !== false);
           // case 'empty':    return !strlen(trim($value));
        }
        return false;
    }
}

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

    function __construct($id=false, $name=false, $conditions=false, $variables=false, $parent=false, $positions=false, $choices=false, $flag=0) {
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

    function addInSequence(&$spot) {
        $this->po[] = $spot->Id();
        $spot->set('parent', $this->Id());  // set/repair parent of inserted spot
    }

    function addChoice(&$spot) {
        $this->ch[] = $spot->Id();
        $spot->set('parent', $this->Id());  // set/repair parent of inserted spot
    }

    function addVariable($name) {
        $this->v[$name] = $name;
    }

    function removeVariable($name) {
        unset($this->v[$name]);
    }

    function addCondition($var, $match) {
        $this->c[$var] = $match->implode();
    }

    function removeCondition($name) {
        unset($this->c[$name]);
    }

    function isLeaf() {
        return ((!is_array($this->ch) OR (count($this->ch)<1)) AND (count($this->po)<2));
    }

    /** Returns true, if the $spot_id is in sequence of this spot (position) */
    function isSequence($spot_id) {
        return (false !== ($k = array_search($spot_id, (array)$this->po)));
    }

    /** Returns true, if the $spot_id is choice of this spot */
    function isChoice($spot_id) {
        return (false !== ($k = array_search($spot_id, (array)$this->ch)));
    }

    /** Check the positions (po) array and choices (ch) array,
    *  and fixes possible problems
    *     - removes empty positions (where they come from?)
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
        if (false !== ($k = array_search($spot_id, (array)$this->ch))) {
            if ( $k == 0 ) {
                return false;
            }
            $this->ch[$k]   = $this->ch[$k-1];
            $this->ch[$k-1] = $spot_id;
            return true;
        }
        //search in sequence
        if (false !== ($k = array_search($spot_id, (array)$this->po))) {
            if ( $k<=1 ) {   // can't move to the first position in sequence
                return false;
            }
            $this->po[$k]   = $this->po[$k-1];
            $this->po[$k-1] = $spot_id;
            return true;
        }
        return false;
    }

    function moveDown( $spot_id ) {
        $this->normalize();  // just check, if there are no problems in this spot

        // search in options
        if (false !== ($k = array_search($spot_id, (array)$this->ch))) {
            if ($k == count($this->ch)-1) { // last
                return false;
            }
            $this->ch[$k]   = $this->ch[$k+1];
            $this->ch[$k+1] = $spot_id;
            return true;
        }
        //search in sequence
        if (false !== ($k = array_search($spot_id, (array)$this->po))) {
            if (($k==0) OR ($k==count($this->po)-1)) {    // can't move to the first position in sequence
                return false;
            }
            $this->po[$k]   = $this->po[$k+1];
            $this->po[$k+1] = $spot_id;
            return true;
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
        if ( isset($this->c) AND is_array($this->c) ) {  //c is array of conditions
            foreach ($this->c as $var => $cond) {
                $value = ($var[0]=='{') ? AA_Stringexpand::unalias($var, '', $state['item']) : $state[$var];
                if ( !(AA_Site_Spot_Match::factoryByString($cond,$var)->match($value)) ) {
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

    function __construct($spot=false) {
        $this->tree[1]  = new spot( $spot['spot_id'], $spot['name'] ? $spot['name']:'start', $spot['conditions'], $spot['variables'], $spot['spot_id'], array($spot['spot_id']), $spot['flag'] );
        $this->start_id = $spot['spot_id'];
    }

    /** Creates the spot object and adds it in the sequence (positions) */
    function addInSequence($where, $name, $conditions=false, $variables=false, $flag=false) {
        // parent is not set yet (set by addInSequence() in next step);
        $spot = new spot( $this->new_id(), $name, $conditions, $variables, false, $flag );
        if ($this->_addInSequence($spot, $where)) {
            $this->tree[$spot->Id()] = $spot;
            return true;
        }
        return false;
    }

    /** Adds already created spot object into sequence (positions array) */
    function _addInSequence(&$spot, $where) {
        //get real parent
        $parent_spot =& $this->tree[$where];

        // this is true for simple spot, which is normal member of any sequence
        // real parent must have positions set
        if ( !$parent_spot->get('positions') ) {
            // if we want to add spot to simple spot in sequence, then we have
           // to add it its parent (the first in the sequence
            $parent_spot =& $this->tree[$parent_spot->get('parent')];
        }

        // parent is set by addInSequence() in next step;
        $parent_spot->addInSequence($spot);  // Note this is going to the spot, not recursing

        return true;
    }

    function new_id() {
        return max(array_keys($this->tree))+1;
    }

    /** Creates the spot object and adds it in the choices array */
    function addChoice($where, $name, $content=false, $conditions=false, $variables=false, $flag=false) {
        // parent is not set yet (set by addChoice() in next step);
        $new_id = $this->new_id();
        $spot = new spot( $new_id, $name, $conditions, $variables, false, array($new_id), $flag );
        if ($this->_addChoice($spot, $where)) {
            $this->tree[$spot->Id()] = $spot;
            return true;
        }
        return false;
    }

    /** Adds already created spot object in the choices array */
    function _addChoice(&$spot, $where) {
        //get real parent
        $where_spot =& $this->tree[$where];
        if (!$where_spot->get('variables')) {  // before creating choice must be defined the list of dependency variables
            return false;
        }
        // parent is set by addChoice() in next step;
        $where_spot->addChoice($spot);
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

    /** Moves the spot up or down within the sitetree. The move is done only
     *  within the same parent.
     *  @param $spot_id int - id of spot to be moved
     *  @param $direction string - 'moveDown' or 'moveLeft'
     */
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


    /** Moves the spot left (to the parent) or right (to first child) within
     *  the sitetree.
     *  @param string $direction - 'moveLeft' or 'moveRight'
     */
    function moveLeftRight($spot_id, $direction) {
        $spot =& $this->tree[$spot_id];
        if (!$spot) {
            return false;
        }
        $parent_id = $spot->get('parent');
        $parent =& $this->tree[$parent_id];
        if (!$parent) {
            return false;
        }

        $spot_type = $parent->isChoice($spot_id) ? 'choice' : 'sequence';

        $destination_parent_id = false;
        if ($direction == 'moveLeft') {
            // destination_parent - parent of our parent (where we are going to move the spot)
            $destination_parent_id = $parent->get('parent');
        } else {       // 'moveRight'
            // fing next spot in the current spot-set (positions/choices)
            $sibling_id = $spot_id;
            while (false !== ($sibling_id = $this->getNextSibling($sibling_id))) {
                if ( $this->haveBranches($sibling_id) ) {
                    if ($spot_type == 'choice') {
                        // if the moved spot is choice, then we just add it to choices
                        $destination_parent_id = $sibling_id;
                    } else {
                        // in case the spot is normal sequence spot, then we
                        // have to add it to first option
                        $choices = $this->get('choices', $sibling_id);
                        $destination_parent_id = ((is_array($choices) AND isset($choices[0])) ? $choices[0] : false);
                    }
                    break;
                }
            }
        }

        if (false === $destination_parent_id) {  // destination_parent not found
            return false;
        }

        $destination_parent =& $this->tree[$destination_parent_id];
        if (!$destination_parent) {
            return false;
        }
        $parent->normalize();
        $destination_parent->normalize();

        if (!$parent->removeSpot($spot_id)) {
            return false;
        }

        if ($spot_type == 'choice') {
            $this->_addChoice($spot, $destination_parent_id);
        } else {
            $this->_addInSequence($spot, $destination_parent_id);
        }
        return true;
    }

    /** Returns id of next sibling - the next spot in the set
     *  (positions or choices) of the given $spot_id
     */
    function getNextSibling($spot_id) {
        $spot =& $this->tree[$spot_id];
        if (!$spot) {
            return false;
        }
        $parent_id = $spot->get('parent');
        $parent =& $this->tree[$parent_id];
        if (!$parent) {
            return false;
        }

        // get id of the destination spot for Right movement (tree admin)
        $spot_set = $this->isOption($spot_id) ? $parent->get("choices") : $parent->get("positions");
        if (!$spot_set) {
            echo 'something is wrong - no "positions" or "choices" for parent';
            return false;
        }
        // now we are looking for the spot which is on the same level under
        $found = false;
        foreach ($spot_set as $poskey => $pos) {
            if (!$pos) {     // There was a bug that introduced empty
                continue;    // positions - this is to skip them.
            }
            if (!$found AND ($pos == $spot_id)) {
                $found = true;
                continue;
            }
            if ($found) {
                return $pos;
            }
        }
        if (!$found) {
            echo 'something is wrong - spot is not found in the spot-set of its parent';
        }
        return false;
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

    function addCondition( $where, $var, $cond, $op) {
        //get real parent
        if (!$this->isChoice($where)) {
            return false;
        }
        $where_spot =& $this->tree[$where];
        $where_spot->addCondition($var, new AA_Site_Spot_Match($op, $cond));
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

    function setFlag($spot_id, $flag) {
        $current_flag = $this->get('flag', $spot_id);

        // set "structural" flag - stored in structure (not in site_spot table)
        $current_flag |= $flag;
        $this->set('flag', $spot_id, $current_flag); // wite the state also to the structure
    }

    function clearFlag($spot_id, $flag) {
        $current_flag = $this->get('flag', $spot_id);

        // set "structural" flag - stored in structure (not in site_spot table)
        $current_flag &= ~$flag;
        $this->set('flag', $spot_id, $current_flag); // wite the state also to the structure
    }

    function isFlag($spot_id, $flag) {
        return $this->get('flag', $spot_id) & $flag;
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

    function isLeaf($id)  {
        $s =& $this->tree[$id];
        return $s->isLeaf();
    }

    function conditionMatches( $id, &$state ) {
        $s =& $this->tree[$id];
        return $s ? $s->conditionMatches($state) : false;
    }

    /** Walk the tree, starting at $id, calling $functions for each spot
     *
     *
     */
    function walkTree(&$state, $id, $functions, $method='cond', $depth=0) {
        // $functions could be array which defines the callback functions,
        // or nonarray - just main - spot -  function
        if (!is_array($functions)) {
            $foo['spot'] = $functions;
            // functions are array now
            $functions = $foo;
        }
        $function_spot = $functions['spot'];

        $current =& $this->tree[$id];
        $positions = $current->get("positions");
        if (!$positions) {
            echo 'something is wrong - no "positions" for parent';
            exit;
        }
        foreach ($positions as $poskey => $pos) {
            if ($pos) {
                // There is a bug that introduced empty positions
                // this is to skip them.
                if (($method=='all') OR ($method=='collapsed') OR (($method=='cond') AND !$this->isFlag($pos, MODW_FLAG_DISABLE))) {
                    $function_spot($pos, $depth);
                }

                // if this position is collapsed, then print only first position
                // and skip the others as well as all the choices
                if (($method == 'collapsed') AND $this->isFlag($pos, MODW_FLAG_COLLAPSE)) {  // MODW_FLAG_COLLAPSE - collapsed branches.
                    break;
                }

                if ($this->haveBranches($pos)) {
                    $chcurrent =& $this->tree[$pos];
                    $choices = $chcurrent->get("choices");
                    if ( !$choices ) {
                        echo "something is wrong - haveBranches but it has not choices[]";
                        exit;
                    }
                    ksort($choices); // might not be in key order
                    $choices_count = count($choices);
                    $choices_index = 0;
                    foreach ($choices as $k => $cho) {
                        if ($cho) { // skip buggy empty choices
                            if (($method=='all') OR ($method=='collapsed') OR ($this->conditionMatches($cho, $state) AND !$this->isFlag($cho, MODW_FLAG_DISABLE))) {

                                // sometimes it is usefull to call a function before the choice
                                if ($functions['before_choice']) {
                                    $functions_before_choice = $functions['before_choice'];
                                    $functions_before_choice($cho, $depth, $choices_index, $choices_count);
                                }

                                $this->walkTree($state, $cho, $functions, $method, $depth+1);

                                // and sometimes it is usefull to call a function after the choice
                                if ($functions['after_choice']) {
                                    $functions_after_choice = $functions['after_choice'];
                                    $functions_after_choice($cho, $depth, $choices_index, $choices_count);
                                }
                                $choices_index++;

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
