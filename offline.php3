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
  for (reset($HTTP_GET_VARS); list($k, $v) = each($HTTP_GET_VARS); ) 
  $$k = Myaddslashes($v); 
  for (reset($HTTP_POST_VARS); list($k, $v) = each($HTTP_POST_VARS); ) 
  $$k = Myaddslashes($v); 
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
  echo (L_OFFLINE_ERR_BEGIN . $txt . L_OFFLINE_ERR_END );
  exit;
}  

function SendOkPage($txt) {
  echo (L_OFFLINE_OK_BEGIN . $txt . L_OFFLINE_OK_END );
  exit;
}  

  # init used objects
$db = new DB_AA;
$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();
$itemvarset = new Cvarset();

if( !$slice_id )
  SendErrorPage(L_NO_SLICE_ID);

$error = "";
$ok = "";

$p_slice_id = q_pack_id($slice_id);
$slice_info = GetSliceInfo($slice_id);

$offline_data = stripslashes($offline_data);
$offline_data = str_replace(chr(14),' ',$offline_data);  // remove wrong chars

if( !$slice_info )
  SendErrorPage(L_NO_SUCH_SLICE);

if( $slice_info["permit_offline_fill"] < 1 )
  SendErrorPage(L_OFFLINE_ADMITED);
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
     $ok .= MsgOk( L_WDDX_DUPLICATED );  # this is error but not fatal - i
     break;
   case WDDX_BAD_PACKET:
     $error .= MsgErr( L_WDDX_BAD_PACKET );
     break;
   case WDDX_OK:
     $ok .= MsgOk( L_WDDX_OK );  # this is error but not fatal - i
     break;
  }   
}  

if( $error )
  SendErrorPage( $error );
 else
  SendOkPage( "$ok<br>". L_CAN_DELETE_WDDX_FILE . 
                  " <a href='$del_url'>".L_DELETE_WDDX."</a>", $del_url );
   
/*
$Log$
Revision 1.6  2001/12/21 11:44:55  honzam
fixed bug of includes in e-mail notify

Revision 1.5  2001/12/18 11:37:38  honzam
scripts are now "magic_quotes" independent - no matter how it is set

Revision 1.4  2001/03/30 11:50:22  honzam
offline filling bug and other smalll bugs fixed

Revision 1.3  2001/03/20 15:23:09  honzam
standardized content management for items - filler, itemedit, offline, feeding

Revision 1.2  2001/02/20 13:25:15  honzam
Better search functions, bugfix on show on alias, constant definitions ...

*/
?>
