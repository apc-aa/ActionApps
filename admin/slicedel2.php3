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

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if($delslice) {  
  if(!CheckPerms( $auth->auth["uid"], "aa", AA_ID, PS_ADD)) {
    MsgPage($sess->url(self_base())."index.php3", L_NO_PS_DEL);
    exit;
  }
} else {
  MsgPage($sess->url(self_base())."index.php3", L_NO_SLICE);
  exit;
}

$err["Init"] = "";          // error array (Init - just for initializing variable

# delete slice
$SQL = "DELETE FROM slice WHERE id='". q_pack_id($delslice) ."'";
$db->query($SQL);

# delete fields
$SQL = "DELETE FROM field WHERE slice_id='". q_pack_id($delslice) ."'";
$db->query($SQL);

# delete items
$db2  = new DB_AA;         
$SQL = "SELECT id FROM item WHERE slice_id='". q_pack_id($delslice) ."'";
$db->query($SQL);
while( $db->next_record() ) {
  # delete content
  $SQL = "DELETE FROM content WHERE item_id='". quote($db->f(id)). "'";
  $db2->query($SQL);
}  
$SQL = "DELETE FROM item WHERE slice_id='". q_pack_id($delslice) ."'";
$db->query($SQL);

# delete slice from permission system
DelPermObject($delslice, "slice");

page_close();                                // to save session variables
go_url($sess->url(self_base() . "slicedel.php3"));

/*
$Log$
Revision 1.1  2001/02/26 17:26:08  honzam
color profiles

*/
?>

