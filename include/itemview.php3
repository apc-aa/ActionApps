<?php
/**
 * File contains definition of itemview class
 * used to display set of item/links
 *
 * Should be included to other scripts
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
 * @package   Include
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

require_once AA_INC_PATH . "stringexpand.php3";
require_once AA_INC_PATH . "hitcounter.class.php3";

/**
 * itemview class - used to display set of items or links or ...
 *
 * it - selects right set of items/link IDs to display (based on page scroller
 *      for example)
 *    - get item/links content from database (using "Abstract Data Structure"
 *      described also in {@link http://apc-aa.sourceforge.net/faq/#1337})
 *    - instantiate 'item' class (defined in item.php3) for each item/link which
 *      should be printed.
 *
 * @see itemview
 */
class itemview {
  var $zids;               // zids to show
  var $from_record;        // from which index begin showing items
  var $num_records;        // number of shown records
  var $slice_info;         // record from slice database for current slice
  var $fields;             // array of fields used in this slice
  var $aliases;            // array of alias definitions. If used with multi-slice,
                           // this variable contains an array of aliases for all slices
                           // aliases[slice_id0] are aliases for slice 0 etc.
  var $clean_url;          // url of slice page with session id and encap..
  var $group_fld;          // id of field for grouping
  var $disc;               // array of discussion parameters (see apc-aa/view.php3)
  var $get_content_funct;  // function to call, if we want to get content
                           // (using "Abstract Data Structure" described also
                           // in {@link http://apc-aa.sourceforge.net/faq/#1337})
  var $parameters;         // optional asociative array of additional parameters
                           // - used for category_id (in Links module) ...
                           // - filed by parameter() method
  private $_content;       // stores item contents to be used for display

  /** itemview function
   * @param $slice_info
   * @param $fields
   * @param $aliases
   * @param $zids
   * @param $from
   * @param $number
   * @param $clean_url
   * @param $disc
   * @param $get_content_funct
   */
  function __construct( $slice_info, $fields, $aliases, $zids, $from, $number, $clean_url, $disc="", $get_content_funct='GetItemContent') {

      $this->slice_info = $slice_info;  // $slice_info is array with this fields:
                                      //      - print_view() function:
                                      //   compact_top, category_sort,
                                      //   category_format, category_top,
                                      //   category_bottom, even_odd_differ,
                                      //   even_row_format, odd_row_format,
                                      //   row_delimiter
                                      //   compact_remove, compact_bottom,
                                      //   vid - used for scroller
                                      //      - print_item() function:
                                      //   fulltext_format, fulltext_remove,
                                      //   fulltext_format_top,
                                      //   fulltext_format_bottom,
                                      //   banner_position, banner_parameters

    $this->group_fld  = $slice_info['group_by'];

    $this->aliases    = $aliases;
    // add special alias, which is = 1 for selected item (given by
    // set[34]=selected-43535 view.php3 parameter
    if ( !$aliases['_#SELECTED'] AND $slice_info['selected_item'] ) {
        $this->aliases['_#SELECTED'] = array('fce'=>'f_e:selected:'.$slice_info['selected_item'], "param"=>"", "hlp"=>"");
    }

    // we fill the ID_COUNT now, because the global $GLOBALS['QueryIDsCount'] variable is
    // most probably filled by the right value. Later the $QueryIDsCount could be damaged
    // mainly if you use nested views, ... {view.php3?vid=} which makes new queries
    $idcount = (string)($GLOBALS['QueryIDsCount'] ? $GLOBALS['QueryIDsCount'] : ' 0');
    $this->aliases["_#ID_COUNT"] = GetAliasDef( "f_t:$idcount",  "id..............", _m("number of found items"));

    $this->fields            = $fields;
    $this->zids              = $zids;
    $this->from_record       = $from;      // number or text "random[:<weight_field>]"
    $this->num_records       = $number;    // negative number used for displaying n-th group of items only
    $this->clean_url         = $clean_url;
    $this->disc              = $disc;
    $this->parameters        = array();
    $this->_content          = array();
    $this->get_content_funct = $get_content_funct ?: 'GetItemContent';
  }

  /** parameter function
   *  Optional asociative array of additional parameters
   *  Used for category_id (in Links module) ...
   * @param $property
   * @param $value
   */
  function parameter($property, $value ) {
      $this->parameters[$property] = $value;
  }

  /** assign_items function
   * @param $zids
   */
  function assign_items($zids) {
      // redefine number of items
      $idcount = (string)($GLOBALS['QueryIDsCount'] ? $GLOBALS['QueryIDsCount'] : ' 0');
      $this->aliases["_#ID_COUNT"] = GetAliasDef( "f_t:$idcount",  "id..............", _m("number of found items"));
      $this->zids = $zids;
  }

  /** is_random function
   * returns true, if view have to show random item (weighted or not)
   */
  function is_random() {
      return (substr($this->from_record, 0, 6) == 'random');
  }

   /** get_output_cached function
    * @param $view_type
    */
    function get_output_cached($view_type="") {
        //create keystring from values, which exactly identifies resulting content

        if ( $this->is_random() ) {                         // don't cache random item
            $res = $this->get_output($view_type);
            return $res;
        }
        if (isset($this->zids)) {
            $keystr = get_hash($this->slice_info, $view_type, $this->from_record, $this->num_records, ((isset($this->zids)) ? $this->zids->id(0) : ""));
        }
        $number_of_ids = ( ($this->num_records < 0) ? MAX_NO_OF_ITEMS_4_GROUP :  // negative used for displaying n-th group of items only
            $this->from_record+$this->num_records );
        for ( $i=$this->from_record; $i<$number_of_ids; $i++) {
            if (isset($this->zids)) {
                $keystr .= $this->zids->id($i);
            }
        }
        $key = get_hash('outp', $keystr, $this->disc, $this->aliases, PageCache::globalKeyArray());

        global $str2find_passon, $pagecache;
        if ( !$GLOBALS['nocache'] && ($res = $pagecache->get($key)) ) {
            return $res;
        }

        $str2find_save   = $str2find_passon;    // Save str2find from same level
        $str2find_passon = new CacheStr2find(); // clear it for caches stored further down
        $res             = $this->get_output($view_type);
        $str2find_passon->add(unpack_id($this->slice_info["id"]), 'slice_id');
        $pagecache->store($key, $res, $str2find_passon);
        $str2find_passon->add_str2find($str2find_save); // and append saved for above

        return $res;
    }

  /** get_disc_buttons function
   * @param $empty
   */
  function get_disc_buttons($empty) {
    if (!$empty) {
      $out.= $this->slice_info['d_sel_butt'];
      $out.= ' '. $this->slice_info['d_all_butt'];
    }
    $out.= ' '. $this->slice_info['d_add_butt'];
    return $out;
  }

  // show list of discussion items --- useful as search form return value
  /** get_disc_list function
   * @param $CurItem
   */
  function get_disc_list($CurItem) {
      $top_html_already_printed = false;
      if (is_array($this->disc['disc_ids'])) {
          $zids = new zids($this->disc['disc_ids'], 'l');
          $this->_content = GetDiscussionContent($zids, true, $this->disc['html_format'], $this->clean_url);
          if (is_array($this->_content)) {
              foreach ( $this->_content as $id => $disc) {
                  $CurItem->set_data($disc);
                  // print top HTML with aliases
                  if ( !$top_html_already_printed ) {
                      $CurItem->setformat($this->slice_info['d_top']);
                      $out = $CurItem->get_item();
                      $top_html_already_printed = true;
                  }
                  $CurItem->setformat($this->slice_info['d_compact']);
                  $out .= $CurItem->get_item();
              }
          }
      }
      if ( !$top_html_already_printed ) {  // print top HTML even no item found
          $CurItem->setformat($this->slice_info['d_top']);
          $out = $CurItem->get_item();
      }
      $CurItem->setformat($this->slice_info['d_bottom']);
      $out .= $CurItem->get_item();
      return $out;
  }

  /** get_disc_thread function
   *  show discussion comments in the thread mode
   * @param $CurItem
   */
  function get_disc_thread($CurItem) {
      $top_html_already_printed = false;

      $zids           = QueryDiscussionZIDs($this->disc['item_id'], "", $this->slice_info['d_order']);
      $this->_content = GetDiscussionContent($zids, true, $this->disc['html_format'], $this->clean_url);
      $d_tree         = GetDiscussionTree($this->_content);

      $out .= '<a name="disc"></a><form name="discusform" action="">';
      $cnt = 0;     // count of discussion comments

      if ($d_tree) {    // if not empty tree
          $CurItem->setformat( $this->slice_info['d_compact']);

          if ($this->slice_info['d_showimages'] || $this->slice_info['d_order'] == 'thread') {
              // show discussion in the thread mode
              GetDiscussionThread($d_tree, "0", 0, $outcome);
              if ( $outcome ) {
                  while ( list( $d_id, $images ) = each( $outcome )) {
                      SetCheckboxContent( $this->_content, $d_id, $cnt++ );
                      SetImagesContent( $this->_content, $d_id, $images, $this->slice_info['d_showimages'], $this->slice_info['images']);
                      $this->setColumns($CurItem, $d_id);
                      // print top HTML with aliases
                      if ( !$top_html_already_printed ) {
                          $CurItem->setformat( $this->slice_info['d_top']);
                          $out .= $CurItem->get_item();    // top html code
                          $CurItem->setformat( $this->slice_info['d_compact']); // back
                          $top_html_already_printed = true;
                      }
                      $out.= $CurItem->get_item();
                  }
              }
          } else {                      // show discussion sorted by date
              foreach ($this->_content as $d_id => $foo ) {
                  if ( $this->_content[$d_id]["hide"] ) {
                      continue;
                  }
                  SetCheckboxContent( $this->_content, $d_id, $cnt++ );
                  $this->setColumns($CurItem, $d_id);
                  // print top HTML with aliases
                  if ( !$top_html_already_printed ) {
                      $CurItem->setformat( $this->slice_info['d_top']);
                      $out .= $CurItem->get_item();    // top html code
                      $CurItem->setformat( $this->slice_info['d_compact']); // back
                      $top_html_already_printed = true;
                  }
                  $out.= $CurItem->get_item();
              }
          }
      }
      if ( !$top_html_already_printed ) {  // print top HTML even no item found
          $CurItem->setformat( $this->slice_info['d_top']);
          $out .= $CurItem->get_item();    // top html code
          $top_html_already_printed = true;
      }

      // buttons bar
      $CurItem->setformat($this->slice_info['d_bottom']);        // bottom html code
      $col["d_buttons......."][0]['value'] = $this->unaliasWithScrollerEasy($this->get_disc_buttons($cnt==0));
      $col["d_buttons......."][0]['flag']  = FLAG_HTML;
      $col["d_item_id......."][0]['value'] = $this->disc['item_id'];

      // set $col["d_url_fulltext.."], $col["d_url_reply....."], $col["d_disc_url......"]
      setDiscUrls($col, $this->clean_url, $this->disc['item_id']);
      $CurItem->set_data($col);
      $out.= $CurItem->get_item() ;

      $out.= "</form>";

      // create a javascript code for getting selected ids and sending them to a script
      list($script_loc,) = explode('#',$col["d_disc_url......"][0]['value']); // remove #disc part

    $out .= "
      <script type=\"text/javascript\"><!--
        function showSelectedComments() {
          var url = \"". get_url($script_loc, "sel_ids=1") ."\";
          var done = 0;

          for (var i = 0; i<$cnt; i++) {
            if ( eval('document.forms[\"discusform\"].c_'+i).checked) {
              done = 1;
              url += \"&ids[\" +  escape(eval('document.forms[\"discusform\"].h_'+i).value) + \"]=1\";
            }
          }
          url += \"\#disc\";
          if (done == 0) {
            alert (\" ". _m("No comment was selected") ."\" );
          } else {
            document.location = url;
          }
        }
        function showAllComments() {document.location = \"". get_url($script_loc, "all_ids=1#disc") ."\"; }
        function showAddComments() {document.location = \"". get_url($script_loc, "add_disc=1#disc") ."\";}
       // --></script>";
   return $out;
  }

  /** get_disc_fulltext function
   *  show discussion comments in the fulltext mode
   * @param $CurItem
   */
  function get_disc_fulltext($CurItem) {
      $CurItem->setformat( $this->slice_info['d_fulltext']);      // set fulltext format
      $zids           = QueryDiscussionZIDs($this->disc['item_id'], $this->disc['ids']);
      $this->_content = GetDiscussionContent($zids, true, $this->disc['html_format'], $this->clean_url);
      $d_tree         = GetDiscussionTree($this->_content);
      if ($this->disc['ids'] && is_array($this->disc['ids']) && is_array($this->_content)) {  // show selected cooments
          foreach ($this->_content as $id => $val) {
              // if hidden => skip  OR if the comment is already in the outcome => skip
              if (($val["hide"] == true) OR $outcome[$id]) {
                  continue;
              }
              GetDiscussionThread($d_tree, $id, 1, $outcome);
          }
      } else {     // show all comments
          GetDiscussionThread($d_tree, "0", 0, $outcome);
      }

      $out.= '<a name="disc"></a>';
      if ( isset($outcome) AND is_array($outcome) ) {
          while ( list( $d_id, $images ) = each( $outcome )) {
              $this->setColumns($CurItem, $d_id);
              $depth = count($images)-1;
              $spacer = "";
              $out.= '
              <table border="0" cellspacing="0" cellpadding="0" class="discrow" id="disc'.$d_id.'">
                <tr>';
              for ( $i=0; $i<$depth; $i++)
              $spacer .= $this->slice_info['d_spacer'];
              if ($spacer) {
                  $out .= "
                      <td valign=top class=\"discspacer\">$spacer</td>";
              }
              $out .= "
                  <td width=\"99%\" class=\"discitem\">".$CurItem->get_item()."
                  </td>
                </tr>
              </table>
              <br>";
          }
      }
      return $out;
  }

  /** get_disc_add function
   *  show the form for adding discussion comments
   * @param $CurItem
   */
  function get_disc_add($CurItem) {
      // if parent_id is set => show discussion comment
      $out.= '<a name="disc"></a>';
      if ($this->disc['parent_id']) {

          $zids           = QueryDiscussionZIDs($this->disc['item_id'], $this->disc['ids']);
          $this->_content = GetDiscussionContent($zids, true, $this->disc['html_format'], $this->clean_url);

          $CurItem->setformat( $this->slice_info['d_fulltext']);
          $this->setColumns($CurItem, $this->disc['parent_id']);
          $out .= $CurItem->get_item();
      } else {
          $col["d_item_id......."][0]['value'] = $this->disc['item_id'];
          setDiscUrls($col, $this->clean_url, $this->disc['item_id']);
          $CurItem->set_data($col);
      }
      // show a form for posting a comment
      $CurItem->setformat( $this->slice_info['d_form']);
      $out .= $CurItem->get_item();

      // preset the for values from cookies
      $cookie = new CookieManager();
      $js     = '';
      foreach (array('d_author', 'd_e_mail', 'd_url_address', 'd_url_description') as $form_field) {
          if ( $value = $cookie->get($form_field) ) {
              $js .= "setControl('f','$form_field',\"$value\");\n";
          }
      }
      if ( $js ) {
          $out .= "\n <script type=\"text/javascript\" src=\"". get_aa_url('javascript/fillform.js', '', false) . "\"></script>";
          $out .= '
          <script type="text/javascript"> <!--
          '.$js.'
          //-->
          </script>
          ';
      }
      return $out;
  }

  /** unaliasWithScrollerEasy function
   *  Just wrapper for unaliasWithScroller() without $item parameter
   * @param $txt
   */
  function unaliasWithScrollerEasy(&$txt) {
      // we just need to pass something as item parameter
      // in unaliasWithScroller (in order we can use reference, there)
      $fooparameter = null;
      return $this->unaliasWithScroller($txt, $fooparameter);
  }
  /** unaliasWithScroller function
   * @param $txt
   * @param $item
   */
  function unaliasWithScroller($txt, &$item) {
      // If no item is specified, then still try and expand aliases using parameters
      if (!$item) {
          $item = new AA_Item(null,$this->aliases,null,null,$this->parameters);
      }
      return AA_Stringexpand::unalias($txt, '', $item, true, $this);
  }

  // set the aliases from the slice of the item ... used to view items from
  // several slices at once: all slices have to define aliases with the same names
  /** setColumns function
   * @param $CurItem
   * @param $iid
   */
  function setColumns($CurItem, $iid) {
      // hack for searching in multiple slices. This is not so nice part
      // of code - we mix there $aliases[<alias>] with $aliases[<p_slice_id>][<alias>]
      // used (filled) in slice.php3
      $CurItem->set_data($this->_content[$iid]);
      // slice_id... in content is packed!!!
      $p_slice_id = addslashes($CurItem->getval('slice_id........'));
      $CurItem->aliases = (is_array($this->aliases[$p_slice_id]) ? $this->aliases[$p_slice_id] : $this->aliases);
  }

  // ----------------------------------------------------------------------------------
  //  get_output

  /** get_output
   *  view_type used internaly for different view types
   * @param $view_type
   */
  function get_output($view_type="") {
    global $debug;

    if ($view_type == "discussion") {
      $CurItem = new AA_Item("", $this->aliases);   // just prepare
      $CurItem->set_parameters($this->parameters);
      switch ($this->disc['type']) {
        case 'thread':   $out = $this->get_disc_thread($CurItem); break;
        case 'fulltext': $out = $this->get_disc_fulltext($CurItem); break;
        case 'list':     $out = $this->get_disc_list($CurItem); break;
        case 'add_disc':
        default:         $out = $this->get_disc_add($CurItem); break;
      }
      return $out;
    }
     // other view_type than discussion

    if ( !( isset($this->zids) AND is_object($this->zids) )) {
      return;
    }

    $is_random = $this->is_random();

    // fill the foo_ids - ids to itemids to get from database
    if ( !$is_random ) {
      $foo_zids = $this->zids->slice((integer)$this->from_record, ( ($this->num_records < 0) ? MAX_NO_OF_ITEMS_4_GROUP :  $this->num_records ));
      if ($debug) huhl('from-'.$this->from_record, 'num-'.$this->num_records, $foo_zids);
    } else { // Selecting random record
      list( $random, $rweight ) = explode( ":", $this->from_record);
      if ( !$rweight || !is_array($this->fields[$rweight]) ) {
        // not weighted, we can select random id(s)
        for ( $i=0; $i<$this->num_records; $i++) {
          $sel = mt_rand( 0, $this->zids->count() - 1) ;
          if ( $this->zids->id($sel) )
            $foo_ids[] = $this->zids->id($sel);
        }
        $foo_zids = new zids($foo_ids, $this->zids->onetype());
        $this->zids = $foo_zids;  // Set zids so can index into it
      } else {   // weighted - we must get all items (banners) and then select
                 // the one based on weight field (now in $rweight variable
        $foo_zids = $this->zids;
      }
    }

    // Create an array of content, indexed by either long or short id (not tagged id)

    // fill Abstract Data Structure by the right function
    // (GetItemContent / GetLinkContent / $metabase->getContent / ...)
    if ( is_array($this->get_content_funct) ) {
        // Get content function should be also method of some class. In that
        // case it is passed as two members array(method,params), where both
        // method as well as params are arrays again, so in fact it looks like:
        // array(array('classname', method),array(param1, param2))
        // Example array(array('AA_Metabase','getContent'),array(table=>'toexecute'))
        // getContent is in this case static class method
        // parameter array (if supplied) is passed as FIRST parameter of the method
        $this->_content = call_user_func_array($this->get_content_funct[0], array($this->get_content_funct[1], $foo_zids));
    } else {
        $this->_content = call_user_func_array($this->get_content_funct, array($foo_zids));
    }

    $CurItem = new AA_Item("", $this->aliases);   // just prepare
    $CurItem->set_parameters($this->parameters);

    // process the random selection (based on weight)
    if ( $rweight && is_array($this->fields[$rweight]) ) {
      $this->zids->clear('l');   // remove id and prepare for long ids
      //get sum of all weight
      reset( $this->_content );
      while ( list(,$v) = each($this->_content) ) {
        $weightsum += $v[$rweight][0]['value'];
      }
      for ( $i=0; $i<$this->num_records; $i++) {
        $winner = mt_rand(1,$weightsum);
        reset( $this->_content );
        $ws=0;
        while ( list($k,$v) = each($this->_content) ) {
          $ws += $v[$rweight][0]['value'];
          if ( $ws >= $winner ) {
            $this->zids->add($k);
            break;
          }
        }
      }
      $this->from_record = 0;
    }

    // count hit for random item - it is used for banners, so it is important
    // to count display number
    if ( $is_random AND ($this->zids->count()==1) ) {
        AA_Hitcounter::hit($this->zids);
    }

    switch ( $view_type ) {
      case "fulltext":
        $iid = $this->zids->short_or_longids(0);  // unpacked or short id
        $this->setColumns($CurItem, $iid);        // set right content for aliases

        // print item
        $out = AA_Stringexpand::unalias($this->slice_info['fulltext_format_top'].$this->slice_info['fulltext_format'].$this->slice_info['fulltext_format_bottom'], $this->slice_info['fulltext_remove'], $CurItem);
        break;

      case "itemlist":          // multiple items as fulltext one after one
        $out = $this->slice_info['fulltext_format_top'];
        for ( $i=0; $i<$this->num_records; $i++ ) {
          $iid = $this->zids->short_or_longids($this->from_record+$i);
          if ( !$iid )
            continue;                                     // iid = quoted or short id

          $this->setColumns($CurItem, $iid);   // set right content for aliases

            // print item
          $CurItem->setformat( $this->slice_info['fulltext_format'],
                               $this->slice_info['fulltext_remove']);
          $out .= $CurItem->get_item();
        }
        $out .= $this->unaliasWithScroller($this->slice_info['fulltext_format_bottom'], $CurItem);
        break;

      case "calendar":
        $out = $this->get_output_calendar();
        break;

      default:
        // compact view (of items or links)
        $oldcat                   = "_No CaTeg";
        $catname                  = '';
        $group_n                  = 0;    // group counter (see group_n slice.php3 parameter)
        $top_html_already_printed = false;

        // negative num_record used for displaying n-th group of items only
        $number_of_ids = ( ($this->num_records < 0) ? MAX_NO_OF_ITEMS_4_GROUP : $this->num_records );
        $ingroup_index = 0;
        $group_index   = 0;
        AA::$debug && AA::$dbg->log("itemlist - number_of_ids: $number_of_ids");

        $zidscount = $this->zids->count();
        for ( $i=0; $i<$number_of_ids; ++$i ) {
            // display banner, if you have to
            if ( $this->slice_info['banner_parameters'] && ($this->slice_info['banner_position']==$i) ) {
                $out .= GetView(ParseViewParameters($this->slice_info['banner_parameters']));
            }

            $zidx = $this->from_record+$i;
            if ($zidx >= $zidscount) {
                break;
            }
            /* mimo hack -- put this on a stack **/
            if (!$GLOBALS['QueryIDsIndex'])          { $GLOBALS['QueryIDsIndex']          = array(); }
            if (!$GLOBALS['QueryIDsPageIndex'])      { $GLOBALS['QueryIDsPageIndex']      = array(); }
            if (!$GLOBALS['QueryIDsGroupIndex'])     { $GLOBALS['QueryIDsGroupIndex']     = array(); }
            if (!$GLOBALS['QueryIDsItemGroupIndex']) { $GLOBALS['QueryIDsItemGroupIndex'] = array(); }
            array_push($GLOBALS['QueryIDsIndex'],         $zidx);          // So that _#ITEMINDX = f_e:itemindex can find it
            array_push($GLOBALS['QueryIDsPageIndex'],     $i);             // So that _#PAGEINDX = f_e:pageindex can find it
            array_push($GLOBALS['QueryIDsGroupIndex'],    $group_index);   // So that _#GRP_INDX = f_e:groupindex can find it
            array_push($GLOBALS['QueryIDsItemGroupIndex'],$ingroup_index); // So that _#IGRPINDX = f_e:itemgroupindex can find it

            $iid = $this->zids->short_or_longids($zidx);
            if ( !$iid ) {
                huhe("Warning: itemview: got a null id");
                continue;
            }
            // Note if iid is invalid, then expect empty answers


            $OldItem = clone($CurItem);  // this could be used in CATEGORY BOTTOM -
                                  // we need old item aliases & content there
            $this->setColumns($CurItem, $iid);   // set right content for aliases

            if ($this->group_fld) {

                //AA::$debug && AA::$dbg->info("group_fld", $iid, $this->zids->getAttr($zidx, 'group_by')) && exit;

                if (is_null($catname = $this->zids->getAttr($zidx, 'group_by'))) {
                    $catname = $CurItem->getval($this->group_fld);
                } else {
                    // it shoudn't be set, but just in case - we do not want to redefine it
                    // @todo move it outside else - there it is just for first testing
                    if (!isset($CurItem->aliases["_#GRP_NAME"])) {
                        $CurItem->aliases["_#GRP_NAME"] = GetAliasDef( ParamImplode(array("f_t", $catname)),  "id..............", _m("exact group name"));
                    }
                }
                if ($this->slice_info['gb_header']) {
                    if ($this->slice_info['gb_header'] == 127) {  // all before ~
                        $catname = strtok($catname, '~');
                    } else {
                        $catname = substr($catname, 0, $this->slice_info['gb_header']);
                    }
                }
            }

            // get top HTML code, unalias it and add scroller, if needed
            if ( !$top_html_already_printed ) {
                $out = $this->unaliasWithScroller($this->slice_info['compact_top'], $CurItem);
                // we move printing of top HTML here, in order we can use aliases
                // data from the first found item
                $top_html_already_printed = true;
            }

            // should we display row_delimiter (see category_top below!)
            $print_delimiter = (($i > 0) AND isset($this->slice_info['row_delimiter']));

            // print category name if needed
            if ($this->group_fld AND strcasecmp($catname,$oldcat)) {
                if ( $this->num_records >= 0 ) {
                    if ($oldcat != "_No CaTeg") {
                        // print bottom category code for previous category

                        // we need to use old values for category bottom
                        $GLOBALS['QueryIDsIndex'][count($GLOBALS['QueryIDsIndex'])-1]                   = end($GLOBALS['QueryIDsIndex'])-1;
                        $GLOBALS['QueryIDsPageIndex'][count($GLOBALS['QueryIDsPageIndex'])-1]           = end($GLOBALS['QueryIDsPageIndex'])-1;
                        $GLOBALS['QueryIDsItemGroupIndex'][count($GLOBALS['QueryIDsItemGroupIndex'])-1] = end($GLOBALS['QueryIDsItemGroupIndex'])-1;

                        $out .= $this->unaliasWithScroller($this->slice_info['category_bottom'], $OldItem);

                        // we need to use old values
                        $GLOBALS['QueryIDsIndex'][count($GLOBALS['QueryIDsIndex'])-1]                   = end($GLOBALS['QueryIDsIndex'])+1;
                        $GLOBALS['QueryIDsPageIndex'][count($GLOBALS['QueryIDsPageIndex'])-1]           = end($GLOBALS['QueryIDsPageIndex'])+1;
                        $GLOBALS['QueryIDsItemGroupIndex'][count($GLOBALS['QueryIDsItemGroupIndex'])-1] = end($GLOBALS['QueryIDsItemGroupIndex'])+1;

                        $GLOBALS['QueryIDsGroupIndex'][count($GLOBALS['QueryIDsGroupIndex'])-1] = ++$group_index; // change current
                    }
                    $ingroup_index = 0;
                    $GLOBALS['QueryIDsItemGroupIndex'][count($GLOBALS['QueryIDsItemGroupIndex'])-1] = $ingroup_index; // change current
                    $out .= $this->unaliasWithScroller($this->slice_info['category_top'], $CurItem);
                    $out .= $this->unaliasWithScroller($this->slice_info['category_format'], $CurItem);
                    $category_top_html_printed = true;

                    // do not print row_delimiter if we printed category top
                    $print_delimiter           = false;
                } else {
                    // used to display n-th group only
                    $group_n++;
                }
                $oldcat = $catname;
            }

            if ( ($this->num_records < 0) AND ($group_n != -$this->num_records )) {
                continue;    // we have to display just -$this->num_records-th group
            }


            // print item
            $CurItem->setformat( (($i%2) AND $this->slice_info['even_odd_differ']) ?
                                 $this->slice_info['even_row_format'] : $this->slice_info['odd_row_format'],
                                 $this->slice_info['compact_remove'] );

            if ($print_delimiter) {
                $out .= $this->unaliasWithScroller($this->slice_info['row_delimiter'], $CurItem);
            }

            $out .= $CurItem->get_item();
            $ingroup_index++;

            // return to QueryIDs* right values (could be changed by get_item(), if we use inner view
            // TODO - do QueryIDs* better - not as global variables with such hacks
            /*$GLOBALS['QueryIDsIndex']     = $zidx;  // So that _#ITEMINDX = f_e:itemindex can find it
            $GLOBALS['QueryIDsPageIndex'] = $i;     // So that _#PAGEINDX = f_e:pageindex can find it
            */
            /*mimo hack, clear the end of the stack **/
            $last_ii  = array_pop($GLOBALS['QueryIDsIndex']);
            $last_pi  = array_pop($GLOBALS['QueryIDsPageIndex']);
            $last_gi  = array_pop($GLOBALS['QueryIDsGroupIndex']);
            $last_igi = array_pop($GLOBALS['QueryIDsItemGroupIndex']);
        }
        array_push($GLOBALS['QueryIDsIndex'],         $last_ii );  // So that _#ITEMINDX = f_e:itemindex can find it
        array_push($GLOBALS['QueryIDsPageIndex'],     $last_pi );  // So that _#PAGEINDX = f_e:pageindex can find it
        array_push($GLOBALS['QueryIDsGroupIndex'],    $last_gi );  // So that _#GRP_INDX = f_e:groupindex can find it
        array_push($GLOBALS['QueryIDsItemGroupIndex'],$last_igi);  // So that _#IGRPINDX = f_e:itemgroupindex can find it
        if ($category_top_html_printed) {
            $out .= $this->unaliasWithScroller($this->slice_info['category_bottom'], $CurItem);
        }
        if ( !$top_html_already_printed ) {  // print top HTML even no item found
            $out  = $this->unaliasWithScroller($this->slice_info['compact_top'], $CurItem);
        }
        $out .= $this->unaliasWithScroller($this->slice_info['compact_bottom'], $CurItem);
        array_pop($GLOBALS['QueryIDsIndex']);
        array_pop($GLOBALS['QueryIDsPageIndex']);
        array_pop($GLOBALS['QueryIDsGroupIndex']);
        array_pop($GLOBALS['QueryIDsItemGroupIndex']);
    }
    return $out;
  }

// ----------------------------------------------------------------------------
//                            calendar view
// ----------------------------------------------------------------------------
    /** resolve_calendar_aliases function
     * @param $txt
     * @param $day
     */
    function resolve_calendar_aliases($txt,$day="") {
        $month = (int)$this->slice_info['calendar_month'];
        $year  = (int)$this->slice_info['calendar_year'];

        $aliases = array (
            "_#CV_NUM_Y" => $year,
            "_#CV_NUM_M" => $month,
            "_#CV_NUM_D" => $day,
            "_#CV_TST_1" => mktime (0,0,0,$month,(int)$day,$year),
            "_#CV_TST_2" => mktime (0,0,0,$month,(int)$day+1,$year));

        reset ($aliases);
        while (list ($alias,$replace) = each($aliases)) {
            $txt = str_replace ($alias,$replace,$txt);
        }
        return $txt;
    }

    /** get_output_calendar function
     *  send content via reference to be quicker
     */
    function get_output_calendar() {
        $CurItem = new AA_Item("", $this->aliases);   // just prepare
        $CurItem->set_parameters($this->parameters);

        $month = $this->slice_info['calendar_month'];
        $year  = $this->slice_info['calendar_year'];

        $min_cell_date = mktime (0,0,0,$month,1,$year);
        $max_cell_date = mktime (0,0,0,$month+1,1,$year);

        $min_cell = getdate($min_cell_date);
        $max_cell = getdate($max_cell_date-1);

        $max_cell = $max_cell["mday"] - $min_cell["mday"] + 1;
        $min_cell = 1;

        /* calendar is an array of days, every day contains info about events starting on that day:
            iid is short_id of event
            span is number of days in current month
            start is 1 when it's the first cell containing this iid. The event is repeated in all days over which it spans.
        */
        $calendar = array();
        $max_events = 0;
        $row_len  = 7;
        $firstday = getdate(mktime (0,0,0,$month,1,$year));
        $firstday = $firstday["wday"] - 2;
        if ($firstday < -1) {
            $firstday += $row_len;
        }
        $rowcount = ($max_cell + $firstday + 1) / $row_len;

        for ( $i=0; $i<$this->num_records && ($i+$this->from_record < $this->zids->count()); $i++ ) {
            $iid = $this->zids->short_or_longids($this->from_record+$i);
            if ( !$iid ) {
                continue;
            }// iid = unpacked item id
            $start_date = $this->_content[$iid][$this->slice_info['calendar_start_date']][0]['value'];
            $end_date   = $this->_content[$iid][$this->slice_info['calendar_end_date']][0]['value'];

            AA::$debug && AA::$dbg->info("------ $start_date - $end_date");

            if ($start_date > $max_cell_date) {
                AA::$debug && AA::$dbg->warn("Some error in calendar view! $start_date &gt; $max_cell_date");
                continue;
            }

            if ($end_date < $min_cell_date) {
                AA::$debug && AA::$dbg->warn("Some error in calendar view! $end_date &lt; $min_cell_date");
                continue;
            }

            $start_cell = ($start_date < $min_cell_date) ? $min_cell : date('j',$start_date);
            $end_cell   = ($end_date >= $max_cell_date)  ? $max_cell : date('j',$end_date);

            $ievent = 0;
            do {
                $free = true;
                for ($date = $start_cell; $date <= $end_cell; ++$date) {
                    if ($calendar[$date][$ievent]["iid"]) {
                        $free = false;
                        break;
                    }
                }
                if (!$free) {
                    ++$ievent;
                }
            } while (!$free);

            $max_events = max($max_events, $ievent+1);

            AA::$debug && AA::$dbg->info("------ $start_date - $end_date: $start_cell - $end_cell : min($row_len-(($firstday+$start_cell) % 7),$end_cell-$start_cell+1)");

            $calendar[$start_cell][$ievent] = array("iid"=>$iid,"span"=>min($row_len-(($firstday+$start_cell) % 7),$end_cell-$start_cell+1),"start"=>1);
            for ($date = $start_cell+1; $date <= $end_cell; ++$date) {
                $calendar[$date][$ievent] = array ("iid" => $iid,"span"=>$end_cell-$date+1);
            }
        }

        AA::$debug && AA::$dbg->info('----------calendar------------', $calendar);

        $out = $this->unaliasWithScroller($this->resolve_calendar_aliases($this->slice_info['compact_top']), $CurItem);

        if ($this->slice_info['calendar_type'] == 'mon_table') {
            for ($cell = 7 - $firstday; $cell <= $max_cell; $cell += $row_len) {
                for ($ievent = 0; $ievent < $max_events; ++$ievent)
                    if ($calendar [$cell][$ievent]["iid"]) {
                        $calendar [$cell][$ievent]["start"] = 1;
                        $calendar [$cell][$ievent]["span"] = min ($calendar[$cell][$ievent]["span"],$row_len);
                    }
            }

            // go throgh all weeks
            for ($row=0; $row < $rowcount; ++$row) {
                $outrow = "";
                $firstcell = $row * $row_len - $firstday;
                for ($cell = $firstcell; $cell < $firstcell + $row_len; ++$cell) {
                    $events = $calendar[$cell];
                    if ($this->slice_info['even_odd_differ'] && count($events) == 0) {
                        $header = $this->slice_info['aditional'];
                    } else {
                        $header = $this->slice_info['category_format'];
                    }
                    $label = $cell >= $min_cell && $cell <= $max_cell ? $cell : "";
                    $CurItem->setformat($this->resolve_calendar_aliases($header,$label));
                    $outrow .= $CurItem->get_item();
                }
                if ($outrow) {
                    $out .= "\n<tr>$outrow</tr>";
                }

                if ($this->slice_info['odd_row_format']) {
                    $row_for_week_printed = false;
                    for ($ievent = 0; $ievent < $max_events; ++$ievent) {
                        $outrow = "";
                        $we_have_event_this_week = false;
                        for ($cell = $firstcell; $cell < $firstcell + $row_len; ++$cell) {
                            $event = $calendar[$cell][$ievent];
                            if ($event["iid"] && $event["start"]) {
                                $this->setColumns($CurItem, $event['iid']);
                                $CurItem->setformat($this->slice_info['aditional3']);
                                $tdattribs = $CurItem->get_item();
                                $CurItem->setformat ($this->slice_info['odd_row_format']);
                                $colspan = ($event['span']>1) ? 'colspan='.$event['span'] : '';
                                $outrow .= "<td valign=top $colspan $tdattribs>" .$CurItem->get_item()."</td>";
                                $we_have_event_this_week = true;
                            } elseif (!$event["iid"]) {
                                $outrow .= '<td class="empty">&nbsp;</td>';
                            }
                        }
                        // do not print empty calendar rows ...
                        if ($outrow AND $we_have_event_this_week) {
                            $out .= "\n<tr>$outrow</tr>";
                            $row_for_week_printed = true;
                        }
                    }
                    // ... but print it in case we didn't print any (at leas one should be printed)
                    if (!$row_for_week_printed) {
                        $out .= "\n<tr>$outrow</tr>";
                    }
                }
                $outrow = "";
                for ($cell = $firstcell; $cell < $firstcell + $row_len; ++$cell) {
                    $events = $calendar[$cell];
                    if ($this->slice_info['even_odd_differ'] && count($events) == 0) {
                        $footer = $this->slice_info['aditional2'];
                    } else {
                        $footer = $this->slice_info['category_bottom'];
                    }
                    $label = $cell >= $min_cell && $cell <= $max_cell ? $cell : "";
                    $CurItem->setformat($this->resolve_calendar_aliases($footer,$label));
                    $outrow .= $CurItem->get_item();
                }
                if ($outrow) {
                    $out .= "\n<tr>$outrow</tr>";
                }
            }

        } else {
            for ($cell = $min_cell; $cell <= $max_cell; ++$cell) {
                $calendar_aliases["_#CV_NUM_D"] = $cell;
                $events = $calendar[$cell];
                if ($this->slice_info['even_odd_differ'] AND (count($events) == 0)) {
                    $header = $this->slice_info['aditional'];
                    $footer = $this->slice_info['aditional2'];
                } else {
                    $header = $this->slice_info['category_format'];
                    $footer = $this->slice_info['category_bottom'];
                }
                $CurItem->setformat($this->resolve_calendar_aliases($header,$cell));
                $out .= $CurItem->get_item();

                for ($ievent = 0; $ievent < $max_events; ++$ievent) {
                    $event = $events[$ievent];
                    if ($event["iid"] && $event["start"]) {
                        $this->setColumns($CurItem, $event['iid']);
                        $CurItem->setformat($this->slice_info['aditional3']);
                        $tdattribs = $CurItem->get_item();
                        $CurItem->setformat($this->slice_info['odd_row_format']);
                        $out .= "<td valign=\"top\" rowspan=\"".$event['span']."\" $tdattribs>" .$CurItem->get_item()."</td>";
                    } elseif (!$event["iid"]) {
                        $out .= '<td class="empty">&nbsp;</td>';
                    }
                }

                $CurItem->setformat ($this->resolve_calendar_aliases($footer,$cell));
                $out .= $CurItem->get_item();
            }
        }

        $out .= $this->unaliasWithScroller($this->resolve_calendar_aliases($this->slice_info['compact_bottom']), $CurItem);
        return $out;
    }

// ----------------------------------------------------------------------------
//                            end of calendar view
// ----------------------------------------------------------------------------

  /** idcount function
   *
   */
  function idcount() {
        return $this->zids->count();
  }

};   //class itemview

?>
