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
 
# script for off-line filling (items send in some XML format, currently WDDX)

# Parameters:
#   slice_id     - id of slice into which the item is added
#   offline_data - set of WDDXed items to fill in
#   del_url      - url to point the script if filling is successfull 
#                  (should delete local copy of file with wddx)

# handle with PHP magic quotes - quote the variables if quoting is set off
function Myaddslashes($val, $n=1) {
  if (!is_array($val)) {
    return addslashes($val);
  }  
  for (reset($val); list($k, $v) = each($val); )
    $ret[$k] = Myaddslashes($v, $n+1);
  return $ret;
}    

if (!get_magic_quotes_gpc()) { 
  // Overrides GPC variables 
  if( isset($HTTP_GET_VARS) AND is_array($HTTP_GET_VARS))
    for (reset($HTTP_GET_VARS); list($k, $v) = each($HTTP_GET_VARS); ) 
      $$k = Myaddslashes($v); 
  if( isset($HTTP_POST_VARS) AND is_array($HTTP_POST_VARS))
    for (reset($HTTP_POST_VARS); list($k, $v) = each($HTTP_POST_VARS); ) 
      $$k = Myaddslashes($v); 
  if( isset($HTTP_COOKIE_VARS) AND is_array($HTTP_COOKIE_VARS))
    for (reset($HTTP_COOKIE_VARS); list($k, $v) = each($HTTP_COOKIE_VARS); ) 
      $$k = Myaddslashes($v); 
}

require "./include/config.php3";
require $GLOBALS[AA_INC_PATH]."locsess.php3";
require $GLOBALS[AA_INC_PATH]."util.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."itemfunc.php3";
require $GLOBALS[AA_INC_PATH]."notify.php3";
require $GLOBALS[AA_INC_PATH]."xmlparse.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."feeding.php3";

function SendErrorPage($txt) {
  HTMLPageBegin();
  echo "</head><body>".$txt."</body></html>";
  exit;
}  

function SendOkPage($txt) {
  HTMLPageBegin();
  echo "</head><body>".$txt."</body></html>";
  exit;
}  

  # init used objects
$db = new DB_AA;
$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();
$itemvarset = new Cvarset();

if( !$slice_id )
  SendErrorPage(_m("Slice ID not defined"));

$error = "";
$ok = "";

$p_slice_id = q_pack_id($slice_id);
$slice_info = GetSliceInfo($slice_id);

$offline_data = stripslashes($offline_data);
$offline_data = str_replace(chr(14),' ',$offline_data);  // remove wrong chars

if( !$slice_info )
  SendErrorPage(_m("Bad slice ID"));

if( $slice_info["permit_offline_fill"] < 1 )
  SendErrorPage(_m("You don't have permission to fill this slice off-line"));
 else
  $bin2fill = $slice_info["permit_offline_fill"]; 
  
  # get slice fields and its priorities in inputform
list($fields,$prifields) = GetSliceFields($slice_id);   

$packets = explode( "<wddxPacket", $offline_data );

// print_r($packets);

reset($packets);
while( list(,$packet) = each($packets) ) {
  if( strlen($packet) < 6 )   # throw first, it should be "";
    continue;
  switch (StoreWDDX2DB( "<wddxPacket".$packet,$slice_id,$fields,$bin2fill)) {
   case WDDX_DUPLICATED:
     $ok .= MsgOk( _m("Duplicated item send - skipped") );  # this is error but not fatal - i
     break;
   case WDDX_BAD_PACKET:
     $error .= MsgErr( _m("Wrong data (WDDX packet)") );
     break;
   case WDDX_OK:
     $ok .= MsgOk( _m("Item OK - stored in database") );  # this is error but not fatal - i
     break;
  }   
}  

if( $error )
  SendErrorPage( $error );
 else
  SendOkPage( "$ok<br>". _m("Now you can dalete local file. ") . 
                  " <a href='$del_url'>"._m(" Delete ")."</a>", $del_url );

?>
