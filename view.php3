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
#optionaly cmd[]     # command to modify the view
                     # cmd[23]=v-25 means: show view id 25 in place of id 23 
                     # cmd[23]=i-24-7464674747 means view 
                     #   number 23 has to display item 74.. in format defined
                     #   in view 24
                     # cmd[23]=c-1-Environment means display view no 23 in place 
                     #   of view no 23 (that's normal), but change value for 
                     #   condition 1 to "Environment".
                     # cmd[23]=c-1-Environment-2-Jane means the same as above, 
                     #   but there are redefined two conditions
#optionaly als[]     # user alias - see slice.php3 for more details

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

function ParseCommand($cmd) {
  return explode("-",$cmd);
}  

function GetAliasesFromUrl() {
  global $aliases, $als;
  if( isset( $als ) AND is_array( $als ) ) {
    reset( $als );
    while( list($k,$v) = each( $als ) )
      $aliases["_#".$k] = array("fce"=>"f_s:$v", "param"=>"", "hlp"=>"");
  }
}  

//-----------------------------End of functions definition---------------------

add_vars();       # adds values from url (it's not automatical in SSIed script)

$p_slice_id= q_pack_id($slice_id);
$db = new DB_AA; 		 // open BD	

# Parse parameters
$command = ParseCommand($cmd[$vid]);
switch ($command[0]) {
  case 'v':  $vid = $command[1];
             break;
  case 'i':  $vid = $command[1];
             $item_ids[] = $command[2];
             break;
  case 'c':  if( $command[1] ) 
               $param_conds[$command[1]] = $command[2];
             if( $command[3] ) 
               $param_conds[$command[3]] = $command[4];
             if( $command[5] ) 
               $param_conds[$command[5]] = $command[6];
             break;
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
      # get alias list from database and possibly from url
      $aliases = GetAliasesFromFields($fields);
      GetAliasesFromUrl();
      
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
    $conds = GetViewConds($view_info, $param_conds);
    $sort  = GetViewSort($view_info);
    list($fields,) = GetSliceFields($slice_id);
    $item_ids=QueryIDs($fields, $slice_id, $conds, $sort, $group_by );
    $format = GetViewFormat($view_info);

//p_arr_m( $format );

    $ids_cnt = count( $item_ids );
    if( $ids_cnt > 0 ) {
      $aliases = GetAliasesFromFields($fields);
      GetAliasesFromUrl();
#print_r($aliases);      
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
Revision 1.6  2001/07/31 16:32:50  honzam
Added '-' operator modifier for relative time conditions. The operator was implemented to view definition too (se_view.php3)

Revision 1.5  2001/07/31 15:20:12  honzam
new - display condition redefining parameter to view.php3 (cmd[]=c)

Revision 1.4  2001/07/09 17:43:53  honzam
url passed user aliases

Revision 1.3  2001/06/24 16:46:22  honzam
new sort and search possibility in admin interface

Revision 1.2  2001/05/23 23:04:54  honzam
fixed bug of not updated list of item in Item manager after item edit

Revision 1.1  2001/05/18 13:41:02  honzam
New View feature, new and improved search function (QueryIDs)

*/
?>