<?php
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

/*  (c) Jakub Adámek, October 2002

    Used by live checkboxes (item alias function f_k).
    Updates a boolean field in database and sends
    back a cookie with the given acknowlegement.

    Params: live_checkbox[short_id][field_id]=action
            ack=acknowledgement identification
    
    where 
        short_id is the item short ID
        field_id is the field ID (like "highlight......")
        action is one of on, off and decides whether the checkbox should be switched on or off
*/
            
require "include/config.php3";
require $GLOBALS[AA_INC_PATH]."util.php3";
require $GLOBALS[AA_INC_PATH]."itemfunc.php3";

if ($encap) require $GLOBALS[AA_INC_PATH]."locsessi.php3";
else require $GLOBALS[AA_INC_PATH]."locsess.php3"; 

reset ($live_checkbox);
list ($short_id, $ar) = each ($live_checkbox);
reset ($ar);
list ($field_id, $action) = each ($ar);

$db = new DB_AA;
$db->query ("SELECT id, slice_id FROM item WHERE short_id = $short_id");

setcookie ( $ack, "1", time() + 60 );
header ("Content-Type: image/gif");

if ($db->next_record()) {
    $item_id = unpack_id ($db->f("id"));
    $slice_id = unpack_id ($db->f("slice_id")); 
    
    $content4id[$field_id][0]["value"] = $action == "on" ? 1 : 0;
    list($fields) = GetSliceFields ($slice_id);   
    
    StoreItem ($item_id,
               $slice_id,
               $content4id,
               $fields,
               false,
               true,
               false);
   
    echo "OK";            
}
else           
    echo "KO";

?>
