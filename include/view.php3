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

define("VIEW_PHP3_INC",1);
require $GLOBALS[AA_INC_PATH] . "itemview.php3";

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

function ParseCommand($cmd,$als=false) {
  if( isset( $als ) AND is_array( $als ) ) {  # substitute url aliases in cmd
    reset( $als );
    while( list($k,$v) = each( $als ) )
      $cmd = str_replace ($k, $v, $cmd);
  }    
  $a = str_replace ("--", "__u>_.", $cmd);# dummy string
  $b = str_replace ("-", "##Sx", $a);     # Separation string is ##Sx
  $c = str_replace ("__u>_.", "-", $b);   # change "--" to "-"
  return explode( "##Sx", $c );
}  

function ParseSettings($set) {
  $a = str_replace (",,", "__u>_.", $set);# dummy string
  $b = str_replace (",", "##Sx", $a);     # Separation string is ##Sx
  $c = str_replace ("__u>_.", ",", $b);   # change ",," to ","
  $sets = explode( "##Sx", $c );
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
  global $cmd, $set, $vid, $als, $slice_id, $conds, $slices;
  global $all_ids, $ids, $sel_ids, $add_disc, $sh_itm, $parent_id;  # used for discussions

  add_vars($query_string);       # adds values from url (it's not automatical in SSIed script)

  # Parse parameters

  # if view in cmd[] is not specified ...
  $cmd4view = ( (!$cmd[$vid] && strpos('x'.$query_string, 'cmd[]') ) ? $cmd[0] : $cmd[$vid]);
  $command = ParseCommand($cmd4view, $GLOBALS['als']);

  switch ($command[0]) {
    case 'v':  $vid = $command[1];
               break;
    case 'i':  $vid = $command[1];
               for( $i=2; $i<count($command); $i++)
                 $item_ids[] = $command[$i];
               break;
    case 'x':  $vid = $command[1];
               for( $i=2; $i<count($command); $i++)
                 $item_ids[] = $command[$i];
               if( strlen($command[2]) < 16  )
                 $use_short_ids = true;
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
                 $conds[]=array( 'operator' => $command[$i+1],
                                 'value' => stripslashes($command[$i+2]),
                                 $command[$i] => 1 );
                 $i += 3;
               }
               break;
  }

  $set4view = ( (!$set[$vid] && strpos('x'.$query_string, 'set[]')) ? $set[0] : $set[$vid]);
  $arr = ParseSettings($set4view);

  # the parameters for discussion comes (quite not standard way) from globals
  if( !$arr["all_ids"] )   $arr["all_ids"] = $all_ids;
  if( !$arr["ids"] )       $arr["ids"] = $ids;
  if( !$arr["sel_ids"] )   $arr["sel_ids"] = $sel_ids;
  if( !$arr["add_disc"] )  $arr["add_disc"]  = $add_disc;
  if( !$arr["sh_itm"] )    $arr["sh_itm"] = $sh_itm;
  if( !$arr["parent_id"] ) $arr["parent_id"] = $parent_id;
  
  $arr['als']=GetAliasesFromUrl(true);
  $arr['vid']=$vid;
  $arr['conds']=$conds;
  $arr['slices']=$slices;
  $arr['param_conds'] = $param_conds; 
  $arr['item_ids'] = $item_ids;
  $arr['use_short_ids'] = $use_short_ids;

  return $arr;
}


function GetViewConds($view_info, $param_conds) {
  # param_conds - redefines default condition values by url parameter (cmd[]=c)
  if( $view_info['cond1field'] )
    $conds[]=array( 'operator' => $view_info['cond1op'],
                    'value' => ($param_conds[1] ? $param_conds[1] : 
                                                  $view_info['cond1cond']),
                     $view_info['cond1field'] => 1 );
  if( $view_info['cond2field'] )
    $conds[]=array( 'operator' => $view_info['cond2op'],
                    'value' => ($param_conds[2] ? $param_conds[2] : 
                                                  $view_info['cond2cond']),
                     $view_info['cond2field'] => 1 );
  if( $view_info['cond3field'] )
    $conds[]=array( 'operator' => $view_info['cond3op'],
                    'value' => ($param_conds[3] ? $param_conds[3] : 
                                                  $view_info['cond3cond']),
                     $view_info['cond3field'] => 1 );
  return $conds;                   
}                     

function GetViewSort($view_info) {
  if( $view_info['group_by1'] )
    $sort[] = array ( $view_info['group_by1'] => 
        (( $view_info['g1_direction'] == 1 ) ? 'd' : 'a'));
  if( $view_info['group_by2'] )
    $sort[] = array ( $view_info['group_by2'] => 
        (( $view_info['g2_direction'] == 1 ) ? 'd' : 'a'));

  if( $view_info['order1'] )
    $sort[] = array ( $view_info['order1'] => 
        (( $view_info['o1_direction'] == 1 ) ? 'd' : 'a'));
  if( $view_info['order2'] )
    $sort[] = array ( $view_info['order2'] => 
        (( $view_info['o2_direction'] == 1 ) ? 'd' : 'a'));
        
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

function GetView($view_param) {
  global $db, $nocache;
  $cache = new PageCache($db, CACHE_TTL, CACHE_PURGE_FREQ);

  #create keystring from values, which exactly identifies resulting content
  $keystr = serialize($view_param);

  if( !$nocache && ($res = $cache->get($keystr)) ) {
    return $res;
  } 
  
  $res = GetViewFromDB($view_param, $cache_sid);
  $cache->store($keystr, $res, "slice_id=$cache_sid");

  return $res;
}


# return view result based on parameters
function GetViewFromDB($view_param, &$cache_sid) {
   global $db;

  $vid = $view_param["vid"];
  $als = $view_param["als"];
  $conds = $view_param["conds"];
  $slices = $view_param["slices"];
  $param_conds = $view_param["param_conds"];
  $item_ids = $view_param["item_ids"];
  $use_short_ids = $view_param["use_short_ids"];
  $list_from = $view_param["from"];
  $list_to = $view_param["to"];
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
                                                ("<div>".L_NO_ITEM ."</div>")));
  
  if( $view_param["banner"] ) {
    list( $foo_pos, $foo_vid, $foo_fld ) = explode('-',$view_param["banner"]);
    $view_info['banner_position'] = $foo_pos;
    $view_info['banner_parameters'] = "vid=$foo_vid&set[$foo_vid]=random-".
                                      ($foo_fld ? $foo_fld : 1);
  }  

  $listlen    = ($view_param["listlen"] ? $view_param["listlen"] : $view_info['listlen'] );
  $p_slice_id = ($view_param["slice_id"] ? q_pack_id($view_param["slice_id"]) : $view_info['slice_id'] );
  $slice_id = unpack_id($p_slice_id);
  
  $cache_sid = $slice_id;     # store the slice id for use in cache (GetView())
  
  # ---- display content in according to view type ----
  switch( $view_info['type'] ) {
    case 'full':  # parameters: item_ids, als
      $format = GetViewFormat($view_info);
  
      $ids_cnt = count( $item_ids );
      if( $ids_cnt > 0 ) {
        # get alias list from database and possibly from url
        list($fields,) = GetSliceFields($slice_id);
        $aliases = GetAliasesFromFields($fields, $als);
       
        $itemview = new itemview( $db, $format, $fields, $aliases, $item_ids, 
                                  0, 1, shtml_url(), "", $use_short_ids);

        return $itemview->get_output_cached("view");
      }  
      return $noitem_msg;
    case 'const':
      $format = GetViewFormat($view_info);
      $aliases = GetConstantAliases($als);
      $constantview = new constantview( $db, $format, $aliases, 
                            $view_info['parameter'], $view_info['order1'], 
                            ( ($view_info['o1_direction'] == 1) ? 'DESC' : ''), 
                            ($listlen ? $listlen : $view_info['listlen']) );
      return $constantview->get_output_cached();
  
    case 'discus': 
      // create array of discussion parameters
      $disc = array('ids'=> ($view_param["all_ids"] ? "" : $view_param["ids"]),
                    'type'=> ($view_param["add_disc"] ? "adddisc" 
                           : ( ($view_param["sel_ids"] || $view_param["all_ids"]) 
                           ? "fulltext" : "thread")),
                    'item_id'=> $view_param["sh_itm"],
                    'vid'=> $vid,
                    'html_format' => ($view_info[flag] & DISCUS_HTML_FORMAT),
                    'parent_id' => $view_param["parent_id"]);
      $aliases = GetDiscussionAliases();
  
      $format = GetDiscussionFormat($view_info);
      $format['id'] = $p_slice_id;                  // set slice_id because of caching
  
      $durl = shtml_url();
       # add state variable, if defined (apc - AA Pointer Cache)
      if( $GLOBALS['apc_state'] )                
        $durl = con_url($durl,'apc='.$GLOBALS['apc_state']['state']);

      $itemview = new itemview( $db, $format,"",$aliases,"","","",$durl, $disc);
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

      if (! $item_ids ) {    # ids could be defined via cmd[]=x command
        $sort  = GetViewSort($view_info);
        $item_ids=QueryIDs($fields, $slice_id, $conds, $sort, $group_by, "ACTIVE", $slices );
      }  
      $format = GetViewFormat($view_info);
      $format['calendar_month'] = $month;
      $format['calendar_year'] = $year;
  
      $ids_cnt = count( $item_ids );
      if( ($ids_cnt > 0) AND !( ($ids_cnt==1) AND !$item_ids[0]) ) {

        if( $list_to )
          $listlen = max(0, $list_to-$list_from + 1);
        
        if( $list_page ) {   # split listing to pages
                             # Format:  <page>-<number of pages>
          $pos=strpos($list_page,'-');
          if( $pos ) {
            $no_of_pages = substr($list_page,$pos+1);
            $page_n = substr($list_page,0,$pos)-1;      #count from zero
            $items = count($item_ids);
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

        $itemview = new itemview( $db, $format, $fields, $aliases, $item_ids, $random ? $random : $list_from,
                                  $listlen, shtml_url(), "", $use_short_ids );
        if ($view_info['type'] == 'calendar') 
            $itemview_type = 'calendar'; 
        else $itemview_type = 'view';
        return $itemview->get_output_cached($itemview_type);
      }  
      // 	if( ($scr->pageCount() > 1) AND !$no_scr)  $scr->pnavbar();
      return $noitem_msg;
      
    case 'static':   # parameters: 0
  case 'static': 
    $format = GetViewFormat($view_info);
    // I create a CurItem object so I can use the unalias function 
    $CurItem = new item("", "", $als, "", "", "");
    return $CurItem->unalias( $view_info["odd"] );
  }
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
    $cache = new PageCache($this->db, CACHE_TTL, CACHE_PURGE_FREQ);

    #create keystring from values, which exactly identifies resulting content
    $keystr = serialize($this->slice_info).
              $this->group.
              $this->order_fld.
              $this->order_dir. 
              $this->num_records;
      
    if( $res = $cache->get($keystr) )
      return $res;

    #cache new value 
    $res = $this->get_output();

    $cache->store($keystr, $res, "slice_id=".unpack_id($this->slice_info["id"]));
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
