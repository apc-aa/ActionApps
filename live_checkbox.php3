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

/*  (c) Jakub Adamek, October 2002

    Used by live checkboxes (item alias function f_k).
    Updates a boolean field in database and sends
    back an image file

    Params: live_checkbox[short_id][field_id]=action

    where
        short_id is the item short ID
        field_id is the field ID (like "highlight......")
        action is one of on, off and decides whether the checkbox should be switched on or off
        (it looks like action is ignored, and the field is just toggled - mitra)
*/
/*
function f () {
    $image_path = "http://localhost/aa_jakub/images/";
    header ("Content-Type: image/gif");
    readfile ($image_path.'cb_on.gif');
    exit;
}
*/

require_once "./include/init_page.php3";
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."itemfunc.php3";

$image_path = AA_BASE_PATH."images/";

reset($live_checkbox);
list($short_id, $ar) = each ($live_checkbox);
reset($ar);
list($field_id, $action) = each ($ar);

//is_object( $db ) || ($db = getDB());
//$db->query("SELECT id, slice_id FROM item WHERE short_id = $short_id");
if ($arr = DB_AA::select1('SELECT id, slice_id FROM `item`', '', array(array('short_id',$short_id, 'i')))) {
    $item_id  = unpack_id($arr['id']);
    $slice_id = unpack_id($arr['slice_id']);
} else {
    failed();
}

if (!IfSlPerm(PS_EDIT_ALL_ITEMS)) {
    failed();
}

if (!$debug) {
    header ("Content-Type: image/gif");
}
$content4ids = GetItemContent($item_id);
reset($content4ids);
$content4id   = current($content4ids);
$action       = ! ($content4id[$field_id][0]['value']);
$content4id   = array ($field_id => array (0 => array ("value" => $action)));
list($fields) = GetSliceFields($slice_id);

//huhl($item_id, $slice_id, $content4id, false, true, false);


StoreItem($item_id, $slice_id, $content4id, false, true, false);

//huhl($image_path.'cb_'.($action ? "on" : "off").'.gif');

readfile ($image_path.'cb_'.($action ? "on" : "off").'.gif');
page_close();
exit;

// ------------------------------------------------------------------------------------

function failed () {
    global $image_path;
    readfile ($image_path.'cb_failed.png');
    page_close();
    exit;
}
?>
