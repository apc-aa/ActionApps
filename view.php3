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

#expected  vid       # id of view
#optionaly cmd       # id of item to show (iid=i-23-7464674747-24 means view 
                     # nuber 23 has to display item 74.. in format defined
                     # in view 24
                     # - given by url (not working yet)

require "./include/config.php3";
require $GLOBALS[AA_INC_PATH]."easy_scroller.php3";
require $GLOBALS[AA_INC_PATH]."util.php3";
require $GLOBALS[AA_INC_PATH]."item.php3";
require $GLOBALS[AA_INC_PATH]."view.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."searchlib.php3";
require $GLOBALS[AA_INC_PATH]."locsessi.php3";    # DB_AA object definition

//-----------------------------Functions definition--------------------------------------------------- 

function GetCategories($db,$p_slice_id){
 $SQL= " SELECT name, value FROM constant WHERE group_id='".$p_slice_id."'";
 $db->query($SQL);
 while ($db->next_record()){
   $unpacked=unpack_id($db->f("value"));  
   $arr[$unpacked]=$db->f("name");  
 }
 return $arr;  
} 
 
function ExitPage() {
  exit;
}  

function GetViewConds($view_info) {
  if( $view_info['cond1field'] )
    $conds[]=array( 'operator' => $view_info['cond1op'],
                    'value' => $view_info['cond1cond'],
                     $view_info['cond1field'] => 1 );
  if( $view_info['cond2field'] )
    $conds[]=array( 'operator' => $view_info['cond2op'],
                    'value' => $view_info['cond2cond'],
                     $view_info['cond2field'] => 1 );
  if( $view_info['cond3field'] )
    $conds[]=array( 'operator' => $view_info['cond3op'],
                    'value' => $view_info['cond3cond'],
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
  return $format;
}

function ParseCommand($cmd) {
  return explode("-",$cmd);
}  

//-----------------------------End of functions definition---------------------

add_vars();       # adds values from url (it's not automatical in SSIed script)

$p_slice_id= q_pack_id($slice_id);
$db = new DB_AA; 		 // open BD	

# Parse parameters
$command = ParseCommand($cmd);
switch ($command[0]) {
  case 'i': if ($command[1] == $vid ) {
              $vid = $command[3];
              $item_ids[] = $command[2];
            }
}              

# gets view data
$db->query("SELECT view.*, slice.deleted FROM view, slice
             WHERE slice.id=view.slice_id
               AND view.id='$vid'");
if( $db->next_record() )
  $view_info = $db->Record;

if (!$view_info OR ($view_info['deleted']>0)) {
  echo L_NO_SUCH_VIEW; 
  exit;
}  

$p_slice_id = $view_info["slice_id"];
$slice_id = unpack_id($p_slice_id);

# ---- display content in according to view type ----
switch( $view_info['type'] ) {
  case 'full':
    $format = GetViewFormat($view_info);

    $ids_cnt = count( $item_ids );
    if( $ids_cnt > 0 ) {
      $aliases = GetAliasesFromFields($fields);
      $itemview = new itemview( $db, $format, $fields, $aliases, $item_ids, 
                                0, 1, shtml_url() );
      $itemview->print_view();
    } else 
      echo "<div>". L_NO_ITEM ."</div>";
    break;  

  case 'discus':
  case 'seetoo':
  case 'const':

  case 'digest':
  case 'list':
  case 'rss':
  case 'script':
    $conds = GetViewConds($view_info);
    $sort  = GetViewSort($view_info);
    list($fields,) = GetSliceFields($slice_id);
    $item_ids=QueryIDs($fields, $slice_id, $conds, $sort, $group_by );
    $format = GetViewFormat($view_info);

//p_arr_m( $format );

    $ids_cnt = count( $item_ids );
    if( $ids_cnt > 0 ) {
      $aliases = GetAliasesFromFields($fields);
      $itemview = new itemview( $db, $format, $fields, $aliases, $item_ids, 
                                0, $view_info['listlen'], shtml_url() );
      $itemview->print_view();
    
    // 	if( ($scr->pageCount() > 1) AND !$no_scr)  $scr->pnavbar();
    } else 
      echo "<div>". L_NO_ITEM ."</div>";
    exit;
  case 'static': echo $view_info["odd"];
                 exit;
}                 

/*
$Log$
Revision 1.1  2001/05/18 13:41:02  honzam
New View feature, new and improved search function (QueryIDs)

*/
?>

