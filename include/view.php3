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

function ParseCommand($cmd) {
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
  global $cmd, $set, $vid, $als;

  add_vars($query_string);       # adds values from url (it's not automatical in SSIed script)

  # Parse parameters
  $command = ParseCommand($cmd[ $vid ]);
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
    case 'c':  if( $command[1] ) 
                 $param_conds[$command[1]] = $command[2];
               if( $command[3] ) 
                 $param_conds[$command[3]] = $command[4];
               if( $command[5] ) 
                 $param_conds[$command[5]] = $command[6];
               break;
    case 'd':  $i=1;
               while( $command[$i] ) {
                 $conds[]=array( 'operator' => $command[$i+1],
                                 'value' => $command[$i+2],
                                 $command[$i] => 1 );
                 $i += 3;
               }
               break;
  }

  $arr = ParseSettings($set[ $vid ]);
  $arr[als]=GetAliasesFromUrl(true);
  $arr[vid]=$vid;
  $arr[conds]=$conds;
  $arr[param_conds] = $param_conds; 
  $arr[item_ids] = $item_ids;
  $arr[use_short_ids] = $use_short_ids;

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
  $format['compact_top'] = $view_info['before'];
  $format['compact_bottom'] = $view_info['after'];
  $format['compact_remove'] = $view_info['remove_string'];
  $format['even_row_format'] = $view_info['even'];
  $format['odd_row_format'] = $view_info['odd'];
  $format['even_odd_differ'] = $view_info['even_odd_differ'];
  $format['id'] = $view_info['slice_id'];
  return $format;
}

# return view result based on parameters
function GetView($view_param) {
   global $db;

  $vid = $view_param["vid"];
  $als = $view_param["als"];
  $conds = $view_param["conds"];
  $param_conds = $view_param["param_conds"];
  $item_ids = $view_param["item_ids"];
  $use_short_ids = $view_param["use_short_ids"];
  $listlen = $view_param["listlen"];
  
  # gets view data
  $db->query("SELECT view.*, slice.deleted FROM view, slice
               WHERE slice.id=view.slice_id
                 AND view.id='$vid'");
  if( $db->next_record() )
    $view_info = $db->Record;
  
  if (!$view_info OR ($view_info['deleted']>0)) {
    return false; 
  }  
  
  $p_slice_id = $view_info["slice_id"];
  $slice_id = unpack_id($p_slice_id);
  
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
      return "<div>". L_NO_ITEM ."</div>";
  
    case 'const':
      $format = GetViewFormat($view_info);
      $aliases = GetConstantAliases($als);
      $constantview = new constantview( $db, $format, $aliases, 
                            $view_info['parameter'], $view_info['order1'], 
                            ( ($view_info['o1_direction'] == 1) ? 'DESC' : ''), 
                            ($listlen ? $listlen : $view_info['listlen']) );
      return $constantview->get_output_cached();
  
    case 'discus':
    case 'seetoo':
  
    case 'digest':
    case 'list':
    case 'rss':
    case 'script':  # parameters: conds, param_conds, als
  
      if (! $conds )         # conds could be defined via cmd[]=d command
        $conds = GetViewConds($view_info, $param_conds);
      list($fields,) = GetSliceFields($slice_id);
      if (! $item_ids ) {    # ids could be defined via cmd[]=x command
        $sort  = GetViewSort($view_info);
        $item_ids=QueryIDs($fields, $slice_id, $conds, $sort, $group_by );
      }  
      $format = GetViewFormat($view_info);
  
  //p_arr_m( $format );
  
      $ids_cnt = count( $item_ids );
      if( $ids_cnt > 0 ) {
        $aliases = GetAliasesFromFields($fields, $als);
        $itemview = new itemview( $db, $format, $fields, $aliases, $item_ids, 0,
                                  ($listlen ? $listlen : $view_info['listlen']), 
                                  shtml_url(), "", $use_short_ids );
        return $itemview->get_output_cached("view");
      }  
      // 	if( ($scr->pageCount() > 1) AND !$no_scr)  $scr->pnavbar();
      return "<div>". L_NO_ITEM ."</div>";
      
    case 'static':   # parameters: 0
  case 'static': 
    // $aliases;
    // let us see if this work!
    GetAliasesFromUrl();
    //echo ($aliases);       // debugging
    $format = GetViewFormat($view_info);
    // I create a CurItem object so I can use the unalias function 
    $CurItem = new item("", "", $aliases, "", "", "");
    return $CurItem->unalias( $view_info["odd"] );
  }
}  


# ----------------------------------------------------------------------------
#                         itemview class
# ----------------------------------------------------------------------------

class itemview{
  var $db;
  var $ids;                      # ids to show
  var $from_record;              # from which index begin showing items
  var $num_records;              # number of shown records 
  var $slice_info;               # record from slice database for current slice
  var $fields;                   # array of fields used in this slice
  var $aliases;                  # array of alias definitions
  var $clean_url;                # url of slice page with session id and encap..
  var $group_fld;                # id of field for grouping
  var $disc;                     # array of discussion parameters (see apc-aa/view.php3)
  var $use_short_ids;            # flag indicates if short_id used within $ids
  
  function itemview( $db, $slice_info, $fields, $aliases, $ids, $from, $number, 
                     $clean_url, $disc="", $use_short_ids=false){
   #constructor
    $this->db = $db;
    $this->slice_info = $slice_info;  # $slice_info is array with this fields:
                                      #      - print_view() function:
                                      #   compact_top, category_sort,
                                      #   category_format, category_top,
                                      #   category_bottom, even_odd_differ,
                                      #   even_row_format, odd_row_format,
                                      #   compact_remove, compact_bottom,
                                      #      - print_item() function:
                                      #   fulltext_format, fulltext_remove,
                                      #   fulltext_format_top, 
                                      #   fulltext_format_bottom, 
    $this->group_fld = ($slice_info[category_sort] ?
                        GetCategoryFieldId($fields) : $slice_info[group_by]);

    $this->aliases = $aliases;
    $this->fields = $fields;
    $this->ids = $ids;
    $this->from_record = $from;
    $this->num_records = $number;
    $this->clean_url = $clean_url;
    $this->disc = $disc;
    $this->use_short_ids = $use_short_ids;
  }  

    
  function get_output_cached($view_type="") {  
    $cache = new PageCache($this->db, CACHE_TTL, CACHE_PURGE_FREQ);

    #create keystring from values, which exactly identifies resulting content

    $keystr = serialize($this->slice_info).
              $view_type.
              $this->from_record.
              $this->num_records.
              $this->clean_url.
              $this->ids[0];
    for( $i=$this->from_record; $i<$this->from_record+$this->num_records; $i++)
      $keystr .= $this->ids[$i];
    $keystr .=serialize($this->disc);
      
    if( $res = $cache->get($keystr) )
      return $res;

    #cache new value 
    $res = $this->get_output($view_type);

    $cache->store($keystr, $res, "slice_id=".unpack_id($this->slice_info["id"]));
    return $res;
  }  
              
  // show discussion comments in the thread mode
  function get_disc_thread(&$CurItem) {

    if (!$this->slice_info['d_showimages']) {
       $order =  $this->slice_info['d_order'];
    }

    $d_content = GetDiscussionContent($this->disc['item_id'], "",$this->disc['vid'],true,$order,$this->disc['html_format'],$this->clean_url);
    $d_tree = GetDiscussionTree($d_content);

    $out .= "<form name=f>";
    $script_loc = $this->clean_url."&sh_itm=".$this->disc['item_id']; // location of the shtml with slice.php3 script
    $cnt = 0;     // count of discussion comments

    $out .= $this->slice_info['d_top'];         // top html code

    if ($d_tree) {    // if not empty tree

      $CurItem->setformat( $this->slice_info['d_compact']);

      if ($this->slice_info['d_showimages'] || $this->slice_info['d_order'] == 'thread') {  // show discussion in the thread mode
         GetDiscussionThread($d_tree, "0", 0, $outcome);

         while ( list( $d_id, $images ) = each( $outcome )) {
            SetCheckboxContent( $d_content, $d_id, $cnt++ );
            SetImagesContent( $d_content, $d_id, $images, $this->slice_info['d_showimages'], $this->slice_info['images']);
            $CurItem->columns = $d_content[$d_id];
            $out.= $CurItem->get_item();
         }
      }
      else {                      // show discussion sorted by date
        reset($d_content);
         while ( list( $d_id, ) = each( $d_content )) {
          if ( $d_content[$d_id]["hide"] )
            continue;
          SetCheckboxContent( $d_content, $d_id, $cnt++ );
          $CurItem->columns = $d_content[$d_id];
          $out.= $CurItem->get_item();
        }
      }
    }

    // buttons bar
     $CurItem->setformat($this->slice_info[d_bottom]);        // bottom html code
     $col["d_buttons......."][0][value] = GetButtons($cnt==0, $script_loc);
     $col["d_buttons......."][0][flag] = FLAG_HTML;
     $CurItem->columns = $col;
     $out.= $CurItem->get_item() ;

     $out.= "</form>";

    // create a javascript code for getting selected ids and sending them to a script
    $out .= "
      <SCRIPT Language=\"JavaScript\"><!--
        function showSelectedComments() {
          var url = \"". $script_loc . "&sel_ids=1\"
          var done = 0;

          for (var i = 0; i < ".$cnt."; i++) {
            if( eval('document.f.c_'+i).checked) {
              done = 1
              url += \"&ids[\" +  escape(eval('document.f.h_'+i).value) + \"]=1\"
            }
          }
          if (done == 0) {
            alert (\" ". L_D_SELECTED_NONE ."\" )
          } else {
            document.location = url
          }
        }
       // --></SCRIPT>";
   return $out;
  }

  // show discussion comments in the fulltext mode
  function get_disc_fulltext(&$CurItem) {

    $CurItem->setformat( $this->slice_info['d_fulltext']);      // set fulltext format
    $d_content = GetDiscussionContent($this->disc['item_id'], $this->disc['ids'],$this->disc['vid'],true,'timeorder',$this->disc['html_format'],$this->clean_url);
    $d_tree = GetDiscussionTree($d_content);
    if ($this->disc['ids'] && is_array($this->disc['ids'])) {  // show selected cooments
      reset($d_content);
      while (list ($id,) = each($d_content)) {
        if ($outcome[$id])            // if the comment is already in the outcome => skip
          continue;
        GetDiscussionThread($d_tree, $id, 1, $outcome);
      }
    }
    else     // show all comments
      GetDiscussionThread($d_tree, "0", 0, $outcome);

    $out.= "<table border=0 cellspacing=0 cellpadding=0>";
    while ( list( $d_id, $images ) = each( $outcome )) {
      $CurItem->columns = $d_content[$d_id];
      $depth = count($images)-1;
      $out.= "<tr><td><table border=0 cellspacing=0 cellpadding=0>
                      <tr>";
      if ($depth>0)
         $out .= "<td>".PrintImg("blank.gif",$depth*20, 21)."</td>";
      $out .= "<td><table border=0 cellspacing=0 cellpadding=0>
                   <tr><td>".$CurItem->get_item()."</td></tr>
                   </table>
                  </td></tr>
                  </table>
             </td></tr>";
    }
    $out.="</table>";

    return $out;
  }

  // show the form for adding discussion comments
  function get_disc_add(&$CurItem) {

    // if parent_id is set => show discussion comment
    if ($this->disc['parent_id']) {
      $d_content = GetDiscussionContent($this->disc['item_id'], $this->disc['ids'],$this->disc['vid'],true,'timeorder',$this->disc['html_format'],$this->clean_url);
      $CurItem->setformat( $this->slice_info['d_fulltext']);
      $CurItem->columns = $d_content[$this->disc['parent_id']];
      $out .= $CurItem->get_item();
    } else {
      $col["d_item_id......."][0][value] = $this->disc['item_id'];
      $col["d_disc_url......"][0][value] = $this->clean_url . "&sh_itm=".$this->disc['item_id'];
      $CurItem->columns = $col;
    }
    // show a form for posting a comment
    $CurItem->setformat( $this->slice_info['d_form']);
    $out .= $CurItem->get_item();

    return $out;
  }

  #view_type used internaly for different view types
  function get_output($view_type="") {  
    $db = $this->db;

    if ($view_type == "discussion") {
      $CurItem = new item("", "", $this->aliases, $this->clean_url, "", "");   # just prepare
      switch ($this->disc['type']) {
        case 'thread' : $out = $this->get_disc_thread($CurItem); break;
        case 'fulltext' : $out = $this->get_disc_fulltext($CurItem); break;
        default: $out = $this->get_disc_add($CurItem); break;
      }
    }

    else {
     // other view_type than discussion
    if( !( isset($this->ids) AND is_array($this->ids) ))
      return;

    for( $i=$this->from_record; $i<$this->from_record+$this->num_records; $i++){
      if( $this->ids[$i] )
        $foo_ids[] = $this->ids[$i];
    }  
    $content = GetItemContent($foo_ids, $this->use_short_ids);
    $CurItem = new item("", "", $this->aliases, $this->clean_url, "", "");   # just prepare

    switch ( $view_type ) {

      case "fulltext":  
        $iid = $this->ids[0];      # unpacked item id
        $CurItem->columns = $content[$iid];   # set right content for aliases

        # print item
        $CurItem->setformat( $this->slice_info[fulltext_format],
                             $this->slice_info[fulltext_remove]);
        $out = $this->slice_info[fulltext_format_top];
        $out .= $CurItem->get_item();
        $out .= $this->slice_info[fulltext_format_bottom];
        break;

      case "itemlist":          # multiple items as fulltext one after one
        $out = $this->slice_info[fulltext_format_top];
        for( $i=0; $i<$this->num_records; $i++ ) {
          $iid = $this->ids[$this->from_record+$i];
          if( !$iid )
            continue;                                     # iid = unpacked item id 

          $CurItem->columns = $content[$iid];   # set right content for aliases
          
            # print item
          $CurItem->setformat( $this->slice_info[fulltext_format],
                               $this->slice_info[fulltext_remove]);
          $out .= $CurItem->get_item();
        }
        $out .= $this->slice_info[fulltext_format_bottom];
        break;
      default:                         # compact view
        $oldcat = "_No CaTeg";
	//        $out = $this->slice_info[compact_top];
	$out = $CurItem->unalias( $this->slice_info[compact_top], "");
        $group_by_field = GetCategoryFieldId( $this->fields );
        
        for( $i=0; $i<$this->num_records; $i++ ) {
          $iid = $this->ids[$this->from_record+$i];
          if( !$iid )
            continue;                                     # iid = unpacked item id 
          $catname = $content[$iid][$this->group_fld][0][value];
              
          $CurItem->columns = $content[$iid];   # set right content for aliases
          
            # print category name if needed
          if($this->group_fld AND ($catname != $oldcat)) {
            $oldcat = $catname;
            
            $CurItem->setformat( $this->slice_info[category_format] );
      
            $out .= $this->slice_info[category_top];
            $out .= $CurItem->get_item();
            $out .= $this->slice_info[category_bottom];
          }  
          
            # print item
          $CurItem->setformat( 
             (!($i%2) AND $this->slice_info[even_odd_differ]) ?
             $this->slice_info[even_row_format] : $this->slice_info[odd_row_format],
             $this->slice_info[compact_remove] );

          $out .= $CurItem->get_item();
        }
        $out .= $this->slice_info[compact_bottom];
      }  
    }
    return $out;
  }

  # compact view
  function print_view($flag="CACHE") {
    if( $flag == "CACHE" )
      echo $this->get_output_cached("view");
     else 
      echo $this->get_output("view");
  }  
  
  # fulltext of one item  
  function print_item($flag="CACHE") {
    if( $flag == "CACHE" )
      echo $this->get_output_cached("fulltext");
     else 
      echo $this->get_output("fulltext");
  }

  # multiple items as fulltext one after one
  function print_itemlist($flag="CACHE") {
    if( $flag == "CACHE" )
      echo $this->get_output_cached("itemlist");
     else 
      echo $this->get_output("itemlist");
  }

  # discusion thread or fulltext or one comment
  function print_discussion($flag="CACHE") {
    if( $flag == "CACHE" )
      echo $this->get_output_cached("discussion");
     else 
      echo $this->get_output("discussion");
  }
};


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



/*
$Log$
Revision 1.19  2001/11/05 18:43:38  honzam
fixed bug with multiple ids passed to view in i and x option

Revision 1.18  2001/11/05 13:43:00  honzam
fixed bug of wrong html code generated for empty comments table

Revision 1.17  2001/10/17 21:53:46  honzam
fixed bug in url passed aliases

Revision 1.16  2001/10/04 14:39:41  honzam
bugfix: set parameter is now parsed correctly

Revision 1.14  2001/10/02 11:36:41  honzam
bugfixes

Revision 1.13  2001/09/27 16:12:09  honzam
Dash escaping in url parameter, New view alias, New constant view

Revision 1.12  2001/09/12 06:19:00  madebeer
Added ability to generate RSS views.
Added f_q to item.php3, to grab 'blurbs' from another slice using aliases

Revision 1.11  2001/06/07 09:59:32  honzam
fixed bug of not displayed fulltext_top html code and fulltext_bottom html code

Revision 1.10  2001/05/18 13:54:36  honzam
New View feature, new and improved search function (QueryIDs)

Revision 1.9  2001/03/20 16:10:37  honzam
Standardized content management for items - filler, itemedit, offline, feeding
Better feeding support

Revision 1.8  2001/03/06 00:15:14  honzam
Feeding support, color profiles, radiobutton bug fixed, ...

Revision 1.7  2001/02/26 17:22:30  honzam
color profiles, itemmanager interface changes

Revision 1.6  2001/01/23 23:58:03  honzam
Aliases setings support, bug in permissions fixed (can't login not super user), help texts for aliases page

Revision 1.4  2000/12/23 19:56:50  honzam
Multiple fulltext item view on one page, bugfixes from merge v1.2.3 to v1.5.2

Revision 1.3  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.2  2000/08/03 12:39:35  honzam
Bug in sort order fixed

Revision 1.1.1.1  2000/06/21 18:40:50  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:27  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.2  2000/06/12 21:41:24  madebeer
removed whitespace from config-ecn.inc
added $Id $Log and $Copyright to some stray files

Revision 1.1  2000/06/09 15:14:12  honzama
New configurable admin interface

Revision 1.12  2000/04/24 16:50:34  honzama
New usermanagement interface.

Revision 1.11  2000/03/22 09:38:39  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc

*/
?>
