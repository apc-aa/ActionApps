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

require "./include/config.php3";
require $GLOBALS[AA_INC_PATH]."locsess.php3";
require $GLOBALS[AA_INC_PATH]."util.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."itemfunc.php3";
require $GLOBALS[AA_INC_PATH]."xmlparse.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";

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
$slice_info = GetSliceInfo($p_slice_id);

$offline_data = stripslashes($offline_data);

if( !$slice_info )
  SendErrorPage(L_NO_SUCH_SLICE);

if( $slice_info["permit_offline_fill"] < 1 )
  SendErrorPage(L_OFFLINE_ADMITED);
  
  # get slice fields and its priorities in inputform
list($fields,$prifields) = GetSliceFields($p_slice_id);   

$packets = explode( "<wddxPacket", $offline_data );

// print_r($packets);

reset($packets);
while( list(,$packet) = each($packets) ) {
  if( strlen($packet) < 6 )   # throw first, it should be "";
    continue;
  switch ( StoreWDDX2DB( "<wddxPacket".$packet, $slice_id, $fields ) ) {
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
Revision 1.1  2001/01/26 15:06:50  honzam
Off-line filling - first version with WDDX (then we switch to APC RSS+)

*/
?>
