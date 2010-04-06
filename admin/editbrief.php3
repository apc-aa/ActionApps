<?php
/**
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version
 * @author    Mitra Ardron - March 2004,
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
 * based on live_checkbox.php3 by Jakub Adámek, October 2002
 *
 * This is intended to be a basic utility that can be used in hand-made links
 *   to make specific changes
 *
 *   Params: edit[short_id][field_id]=value
 *       return_url = where to go next
 *       fail_url = where to go on failure (if not set, goes to return_url)
 *
 *   where
 *       short_id is the item short ID - or comma separated list
 *       field_id is the field ID (like "highlight......") or comma sep list
 *       action is value to set it to
 *       - special cases of action:
 *           now()  means set to current unix time.
*/

require_once "../include/init_page.php3";
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."itemfunc.php3";

$db = getDB();
reset ($edit);
// Loop over each edit instance
while (list($short_ids, $ar) = each($edit)) {
    reset ($ar);
    list ($field_id, $action) = each ($ar);
    if ($action == "now()") {
        $action = now();
    }
    $short_id_arr = explode(",",$short_ids);
    $field_id_arr = explode(",",$field_id);

    // Clear content4id
    $content4id = array();

    // Now build fields for new content
    while (list(,$field_id) = each($field_id_arr)) {
        $content4id[$field_id] = array(0 => array("value" => $action));
    }

    // Loop over items, setting fields
    while (list(,$short_id) = each($short_id_arr)) {
      if ($short_id) { // skip potential empty last id
        $db->query("SELECT id, slice_id FROM item WHERE short_id = $short_id");
        if ($db->next_record()) {
            $item_id  = unpack_id($db->f("id"));
            $slice_id = unpack_id($db->f("slice_id"));
        } else {
            failed("Can't find short_id=".$short_id);
        }

        // Check have permissions on slice item is in
        if (!IfSlPerm(PS_EDIT_ALL_ITEMS)) {
            failed ("Insufficient permissions to change data");
        }

/** Don't think need old content, StoreItem will find
  *      // read what was there
  *      // note this could be made more efficient by fetching all
  *      // items content in one go, (GetItemContent can do this).
  *      $content4ids = GetItemContent($short_id);
  *      reset($oldcontent4ids);
  *      $oldcontent4id = current($content4ids);
*/
        // get the field list, and cache because of looping
        if (!$slicefields[$slice_id]) {
            list($slicefields[$slice_id]) = GetSliceFields($slice_id);
        }
        $fields = $slicefields[$slice_id];
//        huhl("Would store i=",$item_id, "f=",$content4id);
        StoreItem($item_id, $slice_id, $content4id, $fields, false, true, false);
      }
    }
}
freeDB($db);
go_url(expand_return_url($return_url));
exit;

// ------------------------------------------------------------------------------------
/** failed function
 * @param #msg
 * @return executes MsgPage function
 */
function failed($msg) {
    global $return_url;
    MsgPage($return_url,$msg);
    // Note MsgPage exits
}
?>
