<?php
/**
 * File contains definition of cattree class - handles tree of categories
 *
 * Should be included to other scripts
 *
 * @package Links
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
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
if (!defined("LINKS_CATTREE_INCLUDED"))
     define ("LINKS_CATTREE_INCLUDED",1);
else return;

/** Category assignments - stores which category is subcategory of another */
class catassignment {
    var $from;
    var $to;
    var $base;
    var $state;

    /** just constructor - variable assignments */
    function  catassignment($from, $to, $base, $state) {
        $this->from = $from;
        $this->to = $to;
        $this->base = $base;
        $this->state = $state;
    }

    function  getFrom() { return $this->from; }
    function  getTo()   { return $this->to; }
    function  getBase() { return $this->base; }
    function  getState(){ return $this->state; }
}

/**
 * cattree class - handles tree of categories
 */
class cattree {
  var $db;             // database handler
  var $treeStart;      // where to start - category root
  var $go_to_empty;    // boolean - should we go to the empty subcategories?
  var $path_delimeter; // string to show between categories in path

  var $catnames;       // asociative array with names of columns and values of current row
  var $assignments;    // category assignments - stores which category is subcategory of another
  var $ancesors_idx;   // for each category it defines array of ancessors - used just for speedup (walkTree)

  var $STATES_CODING = array('highlight'=>'!', 'visible'=>'-', 'hidden'=>'x');

  // constructor
  function cattree(&$db, $treeStart=-1, $go_to_empty=false, $path_delimeter=' > ') {
    $this->db             = $db;
    $this->treeStart      = $treeStart;
    $this->go_to_empty    = $go_to_empty;
    $this->path_delimeter = $path_delimeter;
  }

  /** Compare function for sorting categories in subcategory
   *  The sort oder is by name, but general categories goes as last and in its
   *  own order
   *  @param object $a,$b - instances of catassignment class
   */
  function cmp_assignments( $a, $b ) {
      $aname = $this->catnames[$a->getTo()];
      $bname = $this->catnames[$b->getTo()];
      $apri  = (integer) Links_GlobalCatPriority($aname);
      $bpri  = (integer) Links_GlobalCatPriority($bname);

      if ( $apri == $bpri ) {
          return strcmp($aname, $bname);
      }
      return (( $apri < $bpri ) ? -1 : 1);
  }

  /** Sort categories assignments by name - general categories goes at the end*/
  function sort_categories() {
      usort($this->assignments, array($this, "cmp_assignments"));
  }

  function update() {
      $db = $this->db;
      unset( $this->catnames );
      unset( $this->assignments );
      unset( $this->ancesors_idx );
      $this->ancesors_idx = array();

      # lookup - all categories names
      $SQL= " SELECT id, name FROM links_categories WHERE deleted='n'";
      $db->query($SQL);
      while ($db->next_record())
          $this->catnames[$db->f('id')] = htmlspecialchars($db->f('name'));

      # lookup - category tree
      $SQL= " SELECT category_id, what_id, base, state
                FROM links_cat_cat, links_categories
               WHERE links_cat_cat.what_id = links_categories.id
               ORDER BY name";

      $db->query($SQL);
      while ($db->next_record()) {
          $this->assignments[] = new catassignment (
                                        $db->f('category_id'),
                                        $db->f('what_id'),
                                        $db->f('base')=='n' ? '@' : ' ',
                                        $this->STATES_CODING[$db->f('state')]);
      }
      $this->sort_categories();
      foreach( $this->assignments as $idx => $assig ) {
          $this->ancesors_idx[$assig->getFrom()][] = $idx;
      }
  }

  /** Not filled yet? ==> Fill it from database  */
  function updateIfNeeded() {
      if ( !isset($this->catnames) OR !is_array($this->catnames) )
          $this->update();
  }


  /** Search category $parenid, if there exist subcategory of name $name
   *  @returns id of found category or false
   */
  function subcatExist($parentid, $name) {
      $this->updateIfNeeded();
      if( isset($this->ancesors_idx) AND is_array($this->ancesors_idx[$parentid]) ) {
          foreach( $this->ancesors_idx[$parentid] as $idx ) {
              $assig = $this->assignments[$idx];
              if ( $this->catnames[$assig->getTo()]==$name ) {
                  return $assig->getTo();
              }
          }
      }
      return false;
  }

  /** Returs name of category given by its id  */
  function getName($cid) {
      $this->updateIfNeeded();
      return $this->catnames[$cid];
  }

  /**
   * Prints javascript which defines necessary javascript variables for category
   * tree. There must be already includede js_lib_links.js file on the page
   * in order ClearListbox(), GoCategory() and ChangeCategory() are defined
   *
   * @param string $fromId special string which in conjunction with $toId defines
   *                       the tree for javascript (see Links_GetTreeDefinition)
   * @param string $toId (see fromId, Links_GetTreeDefinition())
   * @param string special string identifying if category $base{n} is base categ.
   */
  function printTreeData($treeStart=-1) {
      $this->updateIfNeeded();
      if( $treeStart == -1 )
        $treeStart = $this->treeStart;

      // generate strings for javascript
      if ( isset($this->assignments) AND is_array($this->assignments) ) {
          foreach( $this->assignments as $assig ) {
              $fromString .= $delim . $assig->getFrom();
              $toString   .= $delim . $assig->getTo();
              $baseString .= $delim . "'" . $assig->getBase() . "'";
              $delim = ',';
          }
      }

      echo '<SCRIPT Language="JavaScript"><!--

  // data ----------------------------------------------
  s=new Array('. $fromString .')
  t=new Array('. $toString .')
  b=new Array('. $baseString .')

  var assignno = s.length    // number of category assignments
  var level = 0              // current depth of tree path
  var treeStart ='. $this->treeStart .'
  var go_into_empty_cat = '. ($this->go_to_empty ? 'true' : 'false') .'
  var path_delimeter    = "'. $this->path_delimeter .'"
  a=new Array()'."\n";

  reset( $this->catnames );
  while( list( $allId, $allName ) = each( $this->catnames ) )
      echo 'a['. $allId .']="'. $allName ."\"\n";

  echo '
  downcat = new Array()
  downcat[level] = treeStart // stores path form root to current category
  // -->
  </SCRIPT>';
  }


  /**
   * Prints javascript which changes tree to given category
   * There must be already includede js_lib_links.js file on the page
   * in order ClearListbox(), GoCategory() and ChangeCategory() are defined
   *
   * @param int $cat_path path of category to switch to (this->treeStart must
   *                      be on the path
   * @param int $pathDiv  <div> #id where the path should be displayed
   * @param int $cat_id_field hidden form field which stores selected category
   */
  function goCategory($cat_path, $pathDiv="", $cat_id_fld="", $form="") {
      $ids_on_path = explode( ',', $cat_path );
      if ( !isset($ids_on_path) OR !is_array($ids_on_path) )
          return false;

      $state = 'before_treeStart'; // indicates state of processing while cycle
      reset( $ids_on_path );
      while( list( ,$cid) = each($ids_on_path) ) {
          if ( $state=='before_treeStart' ) {
              if ( $cid == $this->treeStart )
                  $state = 'start';
              continue;
          } elseif ( $state=='start' ) {
              echo "\n".'<SCRIPT Language="JavaScript"><!--'."\n";
              $state = 'go';
          }
          echo "ChangeCategory('$cid', eval('document.$form.tree'), '$pathDiv', '$cat_id_fld')\n";
      }
      if ($state == 'go')
          echo '  // -->
                 </SCRIPT>';
  }


  /**
   * Returns multiple selectbox which behaves like category tree
   * Links_PrintTreeData() function must be called first (to define javascript
   * variables.
   * @param bool   withState  Have we show also category state?
   * @param string onWhat     Event to react (onchange/ondblclick)
   * @param int    cat2show   Which category to show as default
   * @param string pathdiv    id of an html element (<div id='...'>) where to
   *                          display currently selected category path
   * @param string cat_id_fld (probably hidden) form field, where the current
   *                          selected category id is written
   * @param string form       if specified, the cattree selectbox is enclosed
   *                          by the form
   * @param string in_form    the name of form, in which the tree selectbox is
   *                          (it must be specified for $onWhat='dblclick' where
   *                          $form is not specified)
   * @return string selectbox prepared to print
   */
  function getFrmTree($withState, $onWhat, $cat2show=-1, $pathDiv="",
                      $cat_id_fld="", $form="", $width=250, $rows=8, $in_form="") {
      $this->updateIfNeeded();
      if( $cat2show == -1 ) {
        $cat2show = $this->treeStart;
      }
      $on = ( ($onWhat == 'change')   ?
       "onchange=\"GoToCategoryID('', this, '$pathDiv', '$cat_id_fld')\"" :
             (($onWhat == 'dblclick') ?
       "ondblclick=\"GoToCategoryID('', this, '$pathDiv', '$cat_id_fld')\"
        onchange=\"ChangeSelectedCat('', this, '$pathDiv', '$cat_id_fld')\""
       :''));

      $ret = ($form ? '<form name="'. $form. '">' : '' );
      $ret .= $this->getFrmSubCatList($withState, $on, $cat2show, $width, "", $rows);
      $ret .= ($form ? '</form>' : '' );

      // for selectbox which use doubleclick we have to print also 'GO' button,
      // because Netscape 4 do not support dblclick event

      if ( $onWhat == 'dblclick' ) {
          $ret .= '<div align="center"><br>
                      <a href="javascript:GoToCategoryID(\'\', eval(document.'.
                      ($form ? $form : $in_form) .'.tree), \''.$pathDiv.'\', \''.
                      $cat_id_fld .'\')">'. _m('Switch to category') .'</a>
                   </div>';
      }
      return $ret;
  }

  /**
   * Returns multiple selectbox with subcategory list
   * @return string selectbox prepared to print
   */
  function getFrmSubCatList($withState, $onWhat, $cat2show, $width=250, $name='tree', $rows=8 ) {
      if ( !$name )   $name='tree';
      if ( !$width )  $width=250;
      if ( !$rows )   $rows=8;
      $ret = "<select name=\"$name\" size=\"$rows\" $onWhat style=\"width:${width}px\">";
      if( isset($this->ancesors_idx) AND is_array($this->ancesors_idx[$cat2show]) ) {
          foreach( $this->ancesors_idx[$cat2show] as $idx ) {  // start position
              $assig = $this->assignments[$idx];
              $ret .= '<option value="'. $assig->getTo() .'">';
              $ret .= ($withState ? '('. $assig->getState(). ') ' : '');
              $ret .= $this->catnames[$assig->getTo()]. $assig->getBase();
              $ret .= '</option>';
          }
      }
      $ret .=  '</select>';
      return $ret;
  }


  /**
   * Walks category tree and calls specified function
   *
   * @param string $function - called function
   */
  function walkTree($start_id, $function, $level=0) {
      if( !isset($this->ancesors_idx) OR !is_array($this->ancesors_idx[$start_id]) )
        return false;

      foreach( $this->ancesors_idx[$start_id] as $idx ) {
          $assig = $this->assignments[$idx];
          $function( $assig->getTo(), $this->catnames[$assig->getTo()],
                     $assig->getBase(), $assig->getState(),
                     $assig->getFrom(), $level);

          // not crossreferenced and never ending cycles protection
          if( ($assig->getBase() != '@') AND ($level <= 100) )
              $this->walkTree($assig->getTo(), $function, $level+1);
      }
  }
}

?>