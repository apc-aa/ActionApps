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

function Page_HTML_Begin ($title="") {  
    HTMLPageBegin (ADM_SLICE_CSS);
    echo '
      <TITLE>'.$title.'</TITLE>
    </HEAD>
    <BODY>';
}

# print closing HTML tags for page
function Page_HTML_End(){ 
    echo '
    </BODY>
    </HTML>';
}

function GetCategories($db,$p_slice_id){
 $SQL= " SELECT name, value FROM constant WHERE group_id='".$p_slice_id."'";
 $db->query($SQL);
 while ($db->next_record()){
   $unpacked=unpack_id($db->f("value"));  
   $arr[$unpacked]=$db->f("name");  
 }
 return $arr;  
} 
 
function pCatSelector($sess_name,$sess_id,$url,$cats,$selected,$sli_id=0,$encaps=true){
 if (sizeof($cats)>0)
 {   
   echo "<form action=$url method=get>";
   echo "<input type=hidden name=$sess_name value=$sess_id>";
   if( !$encaps )    // not encapsulated - need to send slice_id
   { echo "<input type=hidden name=slice_id value=$sli_id>";
     echo "<input type=hidden name=encap value=".($encaps ? "true":"false").">";
   }
   echo _m("Select Category ") . "<select name=cat_id>";
   $seloption=(($selected=="")?"selected":"");
   echo '<option value="all" $seloption>'._m("All categories").'</option>';
   while (list($id,$name)= each($cats)) {
     $seloption=(($selected==$id)?"selected":"");
     echo "<option value=$id $seloption>".htmlspecialchars($name)."</option>";  
   }
   echo "<input type=hidden name=scr_".$scr_name."_Go value=1>";
   echo "<input type=submit name=Go value=Go>";
   echo "</select>"; 
   echo "</form>";  
 }
}    

function ExitPage() {
  global $encap, $r_packed_state_vars, $r_state_vars;
  if (!$encap)
    Page_HTML_End();
  $r_packed_state_vars = serialize($r_state_vars);
  page_close();
  exit;
}  

function StoreVariables( $vars ) {
  if( isset($vars) AND is_array($vars) ) {
    reset($vars);
    while( list(,$v) = each( $vars ) )
      $state_vars[$v] = $GLOBALS[$v];
  }
  return $state_vars;
}  

function RestoreVariables() {
  global $r_state_vars;
  if( isset($r_state_vars) AND is_array($r_state_vars) ) {
    reset($r_state_vars);
    while( list($k,$v) = each( $r_state_vars ) )
      $GLOBALS[$k] = $v;
  }
}  

# two purpose function - it loggs item view and it translates short_id to id
function LogItem($id, $column) {
  global $db;

  CountHit($id, $column);

  if( $column == "id" )
    return $id;
    
  $SQL = "SELECT id, display_count FROM item WHERE short_id='$id'";
  $db->query($SQL);
  if( $db->next_record() )
    return unpack_id( $db->f('id') );
  return false;
}  

function GetSortArray( $sort ) {
  if( substr($sort,-1) == '-' )
    return array ( substr($sort,0,-1) => 'd' );
  if( substr($sort,-1) == '+' )
    return array ( substr($sort,0,-1) => 'a' );
  return array ( $sort => 'a' );
}    

function SubstituteAliases( $als, &$var ) {
  if( !isset( $als ) OR !is_array( $als ) )  # substitute url aliases in cmd
    return;
  reset( $als );
  while( list($k,$v) = each( $als ) )
    $var = str_replace ($k, $v, $var);
}    

function PutSearchLog ()
{
    global $QUERY_STRING_UNESCAPED, $REDIRECT_QUERY_STRING_UNESCAPED, 
        $searchlog;
        
    $httpquery = $QUERY_STRING_UNESCAPED.$REDIRECT_QUERY_STRING_UNESCAPED;
    $httpquery = DeBackslash ($httpquery);
    $httpquery = str_replace ("'", "\\'", $httpquery);
    $db = new DB_AA;
    $found_count = count ($GLOBALS[item_ids]);
    list($usec, $sec) = explode(" ",microtime()); 
    $slice_time = 1000 * ((float)$usec + (float)$sec - $GLOBALS[slice_starttime]); 
    $user = $GLOBALS[HTTP_SERVER_VARS]['REMOTE_USER'];
    $db->query (
    "INSERT INTO searchlog (date,query,user,found_count,search_time,additional1) 
    VALUES (".time().",'$httpquery','$user',$found_count,$slice_time,'$searchlog')");
}

?>