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
# expected $delslice - unpacked id of slice to delete

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH] . "feeding.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if($delslice) {  
  if(!IsSuperadmin()) {
    MsgPage($sess->url(self_base())."index.php3", L_NO_PS_DEL, "admin");
    exit;
  }
} else {
  MsgPage($sess->url(self_base())."index.php3", L_NO_SLICE, "admin");
  exit;
}

$err["Init"] = "";      // error array (Init - just for initializing variable

$p_delslice = q_pack_id($delslice);

# check if slice can be deleted
$SQL = "SELECT deleted FROM slice WHERE id='$p_delslice'";
$db->query($SQL);
if( !$db->next_record() )
  go_url($sess->url(self_base() . "slicedel.php3"), "Msg=". L_NO_SUCH_SLICE);
if( $db->f(deleted) < 1 )
  go_url($sess->url(self_base() . "slicedel.php3"), "Msg=". L_NO_DELETED_SLICE);

# delete slice ----------------------------------------------------------------
$SQL = "DELETE LOW_PRIORITY FROM slice WHERE id='$p_delslice'";
$db->query($SQL);

# delete fields
$SQL = "DELETE LOW_PRIORITY FROM field WHERE slice_id='$p_delslice'";
$db->query($SQL);

# delete items
$db2  = new DB_AA;         
$SQL = "SELECT id FROM item WHERE slice_id='$p_delslice'";
$db->query($SQL);
while( $db->next_record() ) 
  DeleteItem($db2, unpack_id($db->f(id))); # deletes from content, offline and
                                           # relation tables

# delete items
$SQL = "DELETE LOW_PRIORITY FROM item WHERE slice_id='$p_delslice'";
$db->query($SQL);

# delete feedmap
$SQL = "DELETE LOW_PRIORITY FROM feedmap WHERE from_slice_id='$p_delslice'
                                            OR to_slice_id='$p_delslice'";
$db->query($SQL);

# delete feedprms
$SQL = "DELETE LOW_PRIORITY FROM feedperms WHERE from_id='$p_delslice'
                                            OR to_id='$p_delslice'";
$db->query($SQL);

# delete email_notify
$SQL = "DELETE LOW_PRIORITY FROM email_notify WHERE slice_id='$p_delslice'";
$db->query($SQL);

# delete slice from permission system -----------------------------------------
DelPermObject($delslice, "slice");

# optimize tables -------------------------------------------------------------
$db->query("OPTIMIZE TABLE slice");
$db->query("OPTIMIZE TABLE field");
$db->query("OPTIMIZE TABLE content");
$db->query("OPTIMIZE TABLE offline");
$db->query("OPTIMIZE TABLE item");
$db->query("OPTIMIZE TABLE feedmap");
$db->query("OPTIMIZE TABLE feedperms");
$db->query("OPTIMIZE TABLE email_notify");
$db->query("OPTIMIZE TABLE relation");

page_close();                                // to save session variables
go_url(con_url($sess->url(self_base() . "slicedel.php3"), 
                                          "Msg=".rawurlencode(L_DELSLICE_OK)));

/*
$Log$
Revision 1.4  2001/05/18 13:50:09  honzam
better Message Page handling (not so much)

Revision 1.3  2001/03/20 15:24:05  honzam
working version of slice deletion

Revision 1.2  2001/03/06 00:15:14  honzam
Feeding support, color profiles, radiobutton bug fixed, ...

Revision 1.1  2001/02/26 17:26:08  honzam
color profiles

*/
?>

