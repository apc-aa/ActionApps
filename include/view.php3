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

require_once $GLOBALS["AA_INC_PATH"] . "itemview.php3";
require_once $GLOBALS["AA_INC_PATH"] . "viewobj.php3";

# ----------------------------------------------------------------------------
#                         view functions
# ----------------------------------------------------------------------------

function GetAliasesFromUrl($to_arr = false) {
  global $aliases, $als;
  if( isset( $als ) AND is_array( $als ) ) {
    reset( $als );
    if( $to_arr ) {
      while( list($k,$v) = each( $als ) )
        $ret["_#".$k] = array("fce"=>"f_s:$v", "param"=>"", "hlp"=>"");
    } else {    
      while( list($k,$v) = each( $als ) )
        $aliases["_#".$k] = array("fce"=>"f_s:$v", "param"=>"", "hlp"=>"");
    }    
  }
  return $ret;
}  

/** 
 * Separates 'cmd' parameters for current view into array. 
 *
 * Parameters are separated by '-'. To escape '-' character use '--' (then it 
 * is not used as parameter separator. If you use aliases inside cmd[], then 
 * the aliases are expanded by this function (depending on als[] alias array)
 * @param string $cmd cmd[<vid>] string from url
 * @param array $als array of aliases used to expand inside cmd 
 *                   (als[] obviously comes from url, as well)
 * @return array separated cmd parameters
 */
function ParseCommand($cmd,$als=false) {
  if( isset( $als ) AND is_array( $als ) ) {  # substitute url aliases in cmd
    reset( $als );
    while( list($k,$v) = each( $als ) )
      $cmd = str_replace ($k, $v, $cmd);
  }    
  return split_escaped("-", $cmd, "--");
}  

/** 
 * Separates 'set' parameters for current view into array. To escape ',' 
 *                 character uses ',,'.
 * @param string $set set[<vid>] string from url. 'set' parameters are in form 
 *                    set[<vid>]=property1-value1,property2-value2
 * @return array asociative array of properties
 */
function ParseSettings($set) {
  $sets = split_escaped(",", $set, ",,");
  if( !( isset($sets) AND is_array($sets) ))
    return false;
  reset($sets);
  while( list(,$v) = each($sets) ) {
    $pos=strpos($v,'-');
    if( $pos )
      $ret[substr($v,0,$pos)] = substr($v,$pos+1);
  }
  return $ret;    
}  

function ParseViewParameters($query_string="") {
  global $cmd, $set, $vid, $als, $slice_id, $conds, $slices, $mapslices, $debug;
  global $all_ids, $ids, $sel_ids, $add_disc, $sh_itm, $parent_id, $disc_ids, $disc_type;  # used for discussions

  # Parse parameters 
  # if view in cmd[] or set[] is not specified - fill it from vid
  if( EReg( "vid=([0-9]*)", $query_string, $parts) ) {
    $query_string = str_replace( 'cmd[]', "cmd[".$parts[1]."]", $query_string );
    $query_string = str_replace( 'set[]', "set[".$parts[1]."]", $query_string );
  }

  if( $debug ) huhl("ParseViewParameters: vid=$vid, query_string=$query_string");

  add_vars($query_string);       # adds values from url (it's not automatical in SSIed script)

  # Splits on "-" and subsitutes aliases
  $command = ParseCommand($cmd[$vid], $GLOBALS['als']);

  #  This code below do not work!! - it is not the same as the code above!!
  #  (the code above parses only the specific guerystring for this view)
  #  if (!$cmd[$vid])       
  #    $cmd[$vid] = $cmd[0];
  #  $command = ParseCommand($cmd[$vid], $GLOBALS['als']);

  switch ($command[0]) {
    case 'v':  $vid = $command[1];
               break;
    case 'i':  $vid = $command[1];
//               for( $i=2; $i<count($command); $i++)
//                 $item_ids[] = $command[$i];
		$zids = new zids(array_slice($command,2));
		// TODO figure out why CountHit not called here - mitra
               break;
    case 'x':  $vid = $command[1];
  		$zids = new zids(array_slice($command,2));
//               for( $i=2; $i<count($command); $i++)
//                 $item_ids[] = $command[$i];
// This is bizarre code, just incrementing the first item, left as it is
// but questioned on apc-aa-coders - mitra
          if ($zids->use_short_ids()) {
			$si = $zids->shortids();
			CountHit($si[0],'short_id');
		  } else {
			$li = $zids->longids();
			CountHit($li[0],'id');
		  }
               break;
    case 'c':  if( $command[1] && ($command[2] != 'AAnoCONDITION')) 
                 $param_conds[$command[1]] = stripslashes($command[2]);
               if( $command[3] && ($command[4] != 'AAnoCONDITION')) 
                 $param_conds[$command[3]] = stripslashes($command[4]);
               if( $command[5] && ($command[6] != 'AAnoCONDITION')) 
                 $param_conds[$command[5]] = stripslashes($command[6]);
               break;
    case 'd':  $i=1;
               while( $command[$i] ) {
                 $v_conds[]=array( 'operator' => $command[$i+1],
                                 'value' => stripslashes($command[$i+2]),
                                 $command[$i] => 1 );
                 $i += 3;
               }
               break;
  }

  $arr = ParseSettings($set[$vid]);

  #  This code below do not work!! - it is not the same as the code above!!
  #  (the code above parses only the specific guerystring for this view)
  #  if (!$set[$vid])
  #    $set[$vid] = $set[0];
  #  $arr = ParseSettings($set[$vid]);

  # the parameters for discussion comes (quite not standard way) from globals
  if( !$arr["all_ids"] )   $arr["all_ids"] = $all_ids;
  if( !$arr["ids"] )       $arr["ids"] = $ids;
  if( !$arr["sel_ids"] )   $arr["sel_ids"] = $sel_ids;
  if( !$arr["add_disc"] )  $arr["add_disc"]  = $add_disc;
  if( !$arr["sh_itm"] )    $arr["sh_itm"] = $sh_itm;
  if( !$arr["parent_id"] ) $arr["parent_id"] = $parent_id;
  # IDs of discussion items for discussion list 
  if( !$arr["disc_ids"] )  $arr["disc_ids"] = $disc_ids;
  # used for discussion list view
  if( !$arr["disc_type"] ) $arr["disc_type"] = $disc_type;
  
  $arr['als']=GetAliasesFromUrl(true);
  $arr['vid']=$vid;
  $arr['conds']=$v_conds;
  $arr['slices']=$slices;
  $arr['mapslices']=$mapslices;
  $arr['param_conds'] = $param_conds; 
//  $arr['item_ids'] = $item_ids;
  $arr['zids'] = $zids;

  return $arr;
}


/** 
 * Helper function for GetViewConds() - resolves database x url conds conflict
 */
function ResolveCondsConflict(&$conds, $fld, $op, $val, $param) {
  if( $fld AND $op )
    $conds[]=array( 'operator' => $op,
                    'value' => ($param ? $param : $val),
                     $fld => 1 );
}                     
    
/** 
 * Fills array with conditions defined through 
 * 'Slice Admin' -> 'Design View - Edit' -> 'Conditions' setting
 * @param array $view_info view definition from database in asociative array
 * @param array $param_conds possibly redefinition of conds from url (cmd[]=c)
 * @return array conditions array
 */
function GetViewConds($view_info, $param_conds) {
  # param_conds - redefines default condition values by url parameter (cmd[]=c)
  ResolveCondsConflict($conds, $view_info['cond1field'], $view_info['cond1op'],
                               $view_info['cond1cond'],  $param_conds[1]);
  ResolveCondsConflict($conds, $view_info['cond2field'], $view_info['cond2op'],
                               $view_info['cond2cond'],  $param_conds[2]);
  ResolveCondsConflict($conds, $view_info['cond3field'], $view_info['cond3op'],
                               $view_info['cond3cond'],  $param_conds[3]);
  return $conds;
}                     

function GetViewSort($view_info) {

  # translate sort codes (we use numbers in views from historical reason)
  # '0'=>_m("Ascending"), '1' => _m("Descending"), '2' => _m("Ascending by Priority"), '3' => _m("Descending by Priority") 
  $SORT_DIRECTIONS = array( 0 => 'a', 1 => 'd', 2 => '1', 3 => '9' );

  if( $view_info['group_by1'] )
    $sort[] = array ( $view_info['group_by1'] => $SORT_DIRECTIONS[$view_info['g1_direction']]);
  if( $view_info['group_by2'] )
    $sort[] = array ( $view_info['group_by2'] => $SORT_DIRECTIONS[$view_info['g2_direction']]);

  if( $view_info['order1'] )
    $sort[] = array ( $view_info['order1'] => $SORT_DIRECTIONS[$view_info['o1_direction']]);
  if( $view_info['order2'] )
    $sort[] = array ( $view_info['order2'] => $SORT_DIRECTIONS[$view_info['o2_direction']]);
        
  return $sort;
}

function GetViewGroup($view_info) {
  return false;                        # this is managed by GetViewSort()
}

function GetViewFormat($view_info) {
  $format['group_by'] = $view_info['group_by1'];
  $format['category_format'] = $view_info['group_title'];
  $format['category_bottom'] = $view_info['group_bottom'];
  $format['compact_top'] = $view_info['before'];
  $format['compact_bottom'] = $view_info['after'];
  $format['compact_remove'] = $view_info['remove_string'];
  $format['even_row_format'] = $view_info['even'];
  $format['odd_row_format'] = $view_info['odd'];
  $format['even_odd_differ'] = $view_info['even_odd_differ'];
  $format['banner_position'] = $view_info['banner_position'];
  $format['banner_parameters'] = $view_info['banner_parameters'];
  $format['id'] = $view_info['slice_id'];
  $format['vid'] = $view_info['id'];
  
  $format['calendar_start_date'] = $view_info['field1'];
  $format['calendar_end_date'] = $view_info['field2'];
  $format['aditional'] = $view_info['aditional'];
  $format['aditional2'] = $view_info['aditional2'];
  $format['aditional3'] = $view_info['aditional3'];
  $format['calendar_type'] = $view_info['calendar_type'];
  return $format;
}

/** Parses banner url parameter (for view.php3 as well as for slice.php3 
 *  (banner parameter format: banner-<position in list>-<banner vid>-[<weight_field>]
 *  (@see {@link http://apc-aa.sourceforge.net/faq/#219})
 */
function ParseBannerParam(&$view_info, $banner_param) {
    if( $banner_param ) {
        list( $foo_pos, $foo_vid, $foo_fld ) = explode('-',$banner_param);
        $view_info['banner_position'] = $foo_pos;
        $view_info['banner_parameters'] = "vid=$foo_vid";
        if( $foo_fld == 'norandom' )
            return;
        $view_info['banner_parameters'] .= "&set[$foo_vid]=random-".
                                                     ($foo_fld ? $foo_fld : 1);
    }
}

// Expand a set of view parameters, and return the view
function GetView($view_param) {
  global $db, $nocache, $debug;
  if ($debug) huhl("GetView:",$view_param);
  #create keystring from values, which exactly identifies resulting content
  $keystr = serialize($view_param);

  if( !$nocache && ($res = $GLOBALS[pagecache]->get($keystr)) ) {
    return $res;
  } 
  
  $str2find_save = $GLOBALS[str2find_passon]; //Save str2find from same level
  $GLOBALS[str2find_passon] = ""; // clear it for caches stored further down
  $res = GetViewFromDB($view_param, $cache_sid);
  $str2find_this = ",slice_id=$cache_sid";
  if (!strstr($GLOBALS[str2find_passon],$str2find_this)) 
    $GLOBALS[str2find_passon] .= $str2find_this; // append our str2find
    // Note cache_sid set by GetViewFromDB
  $GLOBALS[pagecache]->store($keystr, $res, $GLOBALS[str2find_passon]);
  $GLOBALS[str2find_passon] .= $str2find_save; // and append saved for above
  return $res;
}


// Return view result based on parameters, set cache_sid
function GetViewFromDB($view_param, &$cache_sid) {
  global $db,$debug;

  if ($debug) huhl("GetViewFromDB:",$view_param);
  $vid = $view_param["vid"];
  $als = $view_param["als"];
  $conds = $view_param["conds"];
  $slices = $view_param["slices"];
  $mapslices = $view_param["mapslices"];
  $param_conds = $view_param["param_conds"];
//  $item_ids = $view_param["item_ids"];
  $zids = $view_param["zids"];
//  $use_short_ids = $view_param["use_short_ids"];
  $list_from = max(0, $view_param["from"]-1);    # user counts items from 1, we from 0
  $list_to = max(0, $view_param["to"]-1);        # user counts items from 1, we from 0
  $list_page = $view_param["page"];
  if( $view_param["random"] )
    $random = ( ($view_param["random"]==1) ? 'random' : 
                                             'random:'.$view_param["random"]);
  # gets view data
  $view_info = GetViewInfo($vid);
  if (!$view_info OR ($view_info['deleted']>0)) 
    return false; 

  $noitem_msg = (isset($view_param["noitem"]) ? $view_param["noitem"] :
                   ( $view_info['noitem_msg'] ? $view_info['noitem_msg'] : 
                                                ("<div>"._m("No item found") ."</div>")));
  
  ParseBannerParam($view_info, $view_param["banner"]);  // if banner set format

  $listlen    = ($view_param["listlen"] ? $view_param["listlen"] : $view_info['listlen'] );
  $p_slice_id = ($view_param["slice_id"] ? q_pack_id($view_param["slice_id"]) : $view_info['slice_id'] );
  $slice_id = unpack_id128($p_slice_id);
  
  $cache_sid = $slice_id;     # store the slice id for use in cache (GetView())
  
  # ---- display content in according to view type ----
  if ($debug) huhl("GetViewFromDB:view_info=",$view_info);
  switch( $view_info['type'] ) {
    case 'full':  # parameters: zids, als
      $format = GetViewFormat($view_info);
      if( isset($zids) && ($zids->count() > 0) ) {
        # get alias list from database and possibly from url
        list($fields,) = GetSliceFields($slice_id);
        $aliases = GetAliasesFromFields($fields, $als);
       
        $itemview = new itemview( $db, $format, $fields, $aliases, $zids, 
                                  0, 1, shtml_url(), "");
        return $itemview->get_output_cached("view");
      }  
      return $noitem_msg;
    case 'const':
      $format = GetViewFormat($view_info);
      $aliases = GetConstantAliases($als);
      $constantview = new constantview( $db, $format, $aliases, 
                            $view_info['parameter'], $view_info['order1'], 
                            ((($view_info['o1_direction'] == 1) || 
                              ($view_info['o1_direction'] == 3))? 'DESC' : ''), 
                            ($listlen ? $listlen : $view_info['listlen']) );
      return $constantview->get_output_cached();
  
    case 'discus': 
      // create array of discussion parameters
      $disc = array('ids'=> ($view_param["all_ids"] ? "" : $view_param["ids"]),
                    'item_id'=> $view_param["sh_itm"],
                    'vid'=> $vid,
                    'html_format' => ($view_info[flag] & DISCUS_HTML_FORMAT),
                    'parent_id' => $view_param["parent_id"],
                    'disc_ids' => $view_param["disc_ids"]);
      if ($view_param["disc_type"] == "list" || is_array ($view_param["disc_ids"]))
          $disc['type'] = "list";
      else if ($view_param["add_disc"])
          $disc['type'] = "adddisc";
      else if ($view_param["sel_ids"] || $view_param["all_ids"])
          $disc['type'] = "fulltext";
      else $disc['type'] = "thread";
      $aliases = GetDiscussionAliases();
  
      $format = GetDiscussionFormat($view_info);
      $format['id'] = $p_slice_id;                  // set slice_id because of caching
  
      $durl = shtml_url();
       # add state variable, if defined (apc - AA Pointer Cache)
      if( $GLOBALS['apc_state'] )                
        $durl = con_url($durl,'apc='.$GLOBALS['apc_state']['state']);

      $itemview = new itemview( $db, $format,"",$aliases,null,"","",$durl, $disc);
      return $itemview->get_output_cached("discussion");
    
    
    case 'seetoo':
  
    case 'calendar':
        $today = getdate();
        $month = $view_param['month'];
        if ($month < 1 || $month > 12) $month = $today['mon'];
        $year = $view_param['year'];
        if ($year < 1900 || $year > 3000) $year = $today['year'];
        
        $calendar_conds =
        array (array( 'operator' => '<',
                      'value' => mktime (0,0,0,$month+1,1,$year),
                      $view_info['field1'] => 1 ),
               array( 'operator' => '>=',
                      'value' => mktime (0,0,0,$month,1,$year),
                      $view_info['field2'] => 1 ));
        # Note drops through to next case
    case 'digest':
    case 'list':
    case 'rss':
    case 'script':  # parameters: conds, param_conds, als
      if (! $conds )         # conds could be defined via cmd[]=d command
        $conds = GetViewConds($view_info, $param_conds);
      // merge $conds with $calendar_conds
      if (is_array ($calendar_conds)) {
          reset ($calendar_conds);
          while (list(,$v)=each($calendar_conds))
              $conds[] = $v;
      }

      list($fields,) = GetSliceFields($slice_id);
      $aliases = GetAliasesFromFields($fields, $als);

      if (is_array ($slices)) {
          reset($slices);
          while (list(,$slice) = each($slices)) {
              list($fields,) = GetSliceFields ($slice);
              $aliases[q_pack_id($slice)] = GetAliasesFromFields($fields,$als);
          }
      }

      $sort  = GetViewSort($view_info);
	$zids2 = 
	    QueryZIDs($fields, $zids ? false : $slice_id, $conds, $sort,
                         $group_by, "ACTIVE", $slices, 0, 
			 $zids);
    # Note this zids2 is always packed ids, so lost tag information
    if ($debug) huhl("GetViewFromDB retrieved ".(isset($zids2) ? $zids2->count : 0)." IDS");
    if (isset($zids) && isset($zids2) && ($zids->onetype() == "t")) {
        $zids2 = $zids2->retag($zids);
        if ($debug) huhl("Retagged zids=",$zids2);
    }

	if ($debug) huhl("GetViewFromDB: Filtered ids=",$zids2); 

      $format = GetViewFormat($view_info);
      $format['calendar_month'] = $month;
      $format['calendar_year'] = $year;

      if (isset($zids2) && ($zids2->count() > 0)) {

        if( $list_to > 0 )
          $listlen = max(0, $list_to-$list_from + 1);

        if( $list_page ) {   # split listing to pages
                             # Format:  <page>-<number of pages>
          $pos=strpos($list_page,'-');
          if( $pos ) {
            $no_of_pages = substr($list_page,$pos+1);
            $page_n = substr($list_page,0,$pos)-1;      #count from zero
            $items = $zids2->count();
            $items_plus = $items + ($no_of_pages-1); # to be last page shorter than others if there is not so good number of items
//            $list_from = $page_n * floor($items_plus/$no_of_pages);
//            $listlen = floor($items_plus/$no_of_pages);
            $list_from = $page_n * floor($items/$no_of_pages);
            $listlen = floor(($items*($page_n+1))/$no_of_pages) - floor(($items*$page_n)/$no_of_pages);
          } else  # second parameter is not specified - take listlen parameter
            $list_from = $listlen * ($list_page-1);
        }

        if( !$list_from )
          $list_from = 0;

        $itemview = new itemview( $db, $format, $fields, $aliases, $zids2, 
                                  $random ? $random : $list_from, $listlen, 
                                  shtml_url(), "");
                                  
        if ($view_info['type'] == 'calendar')
            $itemview_type = 'calendar';
        else $itemview_type = 'view';
        if ($debug) huhl("GetViewFromDB going to get_output_cached");
        return $itemview->get_output_cached($itemview_type);
      }   #zids2->count >0
      // 	if( ($scr->pageCount() > 1) AND !$no_scr)  $scr->pnavbar();
      return $noitem_msg;
      
    case 'static':   # parameters: 0
  case 'static':
    // $format = GetViewFormat($view_info);  // not needed now
    // I create a CurItem object so I can use the unalias function 
    $CurItem = new item("", "", $als, "", "", "");
    $formatstring = $view_info["odd"];          # it is better to copy format-
    return $CurItem->unalias( $formatstring );  # string to variable - unalias
  }                                             # uses call by reference
}  

# ----------------------------------------------------------------------------
#                        constantview class
# ----------------------------------------------------------------------------

class constantview{
  var $db;
  var $slice_info;               # record from slice database for current slice
  var $aliases;                  # array of alias definitions
  var $group;                    # id of constant group
  var $order_fld;                # name of order field
  var $order_dir;                # order direction (DESC)
  
  function constantview( $db, $slice_info, $aliases, $group, $order_fld='pri', $order_dir='', $number=10000) {                   #constructor 
    $this->db = $db;
    $this->slice_info = $slice_info;  # $slice_info is array with this fields:
                                      #   compact_top, category_sort,
                                      #   category_format, category_top,
                                      #   category_bottom, even_odd_differ,
                                      #   even_row_format, odd_row_format,
                                      #   compact_remove, compact_bottom,
    $this->aliases = $aliases;
    $this->group = $group;
    $this->order_fld = $order_fld;
    $this->order_dir = $order_dir;
    $this->num_records = $number;
  }  
    

  function get_output_cached() {  

    #create keystring from values, which exactly identifies resulting content
    $keystr = serialize($this->slice_info).
              $this->group.
              $this->order_fld.
              $this->order_dir. 
              $this->num_records;
      
    if( $res = $GLOBALS[pagecache]->get($keystr) )
      return $res;

    #cache new value 
    $res = $this->get_output();

    $GLOBALS[pagecache]->store($keystr, $res, "slice_id=".unpack_id128($this->slice_info["id"]));
    return $res;
  }  
              
  function get_output() {  
    $db = $this->db;
    $content = GetConstantContent($this->group, $this->order_fld. " " .$this->order_dir);
    $CurItem = new item("", "", $this->aliases, "", "", "");   # just prepare
    $out = $this->slice_info[compact_top];
        
    reset( $content );
    $i=0;
    while( list( $iid, $const_cont) = each( $content ) ) {
      if( $const_cont["const_counter..."][0][value] > $this->num_records )
        break;

      $CurItem->columns = $const_cont;   # set right content for aliases
            # print item
      $CurItem->setformat( 
             (!($i%2) AND $this->slice_info[even_odd_differ]) ?
             $this->slice_info[even_row_format] : $this->slice_info[odd_row_format],
             $this->slice_info[compact_remove] );

      $out .= $CurItem->get_item();
      $i++;
    }  
    $out .= $this->slice_info[compact_bottom];
    return $out;
  }

  # print constant view
  function print_constant() {
    echo $this->get_output_cached();
  }  
};

?>
