<?php
//$Id$
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

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

use AA\IO\DB\DB_AA;

require_once __DIR__ . "/../../include/mail.php";
require_once __DIR__ . "/../../include/mgettext.php";

function GetCollection($slice_id) {
    $SQL =  "SELECT AC.*, module.name, module.lang_file, module.slice_url
              FROM alerts_collection AC INNER JOIN module ON AC.module_id = module.id
              WHERE module_id='".q_pack_id($slice_id)."'";
    $ret = GetTable2Array($SQL, 'aa_first');
    if (is_array($ret)) {
        $ret['module_id'] = unpack_id($ret['module_id']);
        $ret['slice_id']  = unpack_id($ret['slice_id']);
    }
    return $ret;
}

function set_collectionid() {
    global $collectionid, $collectionprop, $no_slice_id;

    if (!$no_slice_id) {
        if (!AA::$module_id) { echo "Error: no slice ID"; exit; }
        $collectionprop = GetCollection(AA::$module_id);
        if ($collectionprop) {
            $collectionid = $collectionprop['id'];
        } else {
            echo "Can't find collection with module_id=". AA::$module_id. ". Bailing out.<br>";
            exit;
        }
    }
}

function get_howoften_options() {
    $retval = [];
    $retval["instant"]   = _m("instant");
    $retval["daily"]     = _m("daily");
    $retval["weekly"]    = _m("weekly");
    $retval["twoweeks"]  = _m("twoweeks");
    $retval["monthly"]   = _m("monthly");
    $retval["irregular"] = _m("irregular");
    return $retval;
}

function get_bin_names() {
    return [
        1 => _m("Active"),
        2 => _m("Holding bin"),
        3 => _m("Trash bin")
    ];
}

function new_collection_id() {
    do {
        $new_id = gensalt(5);
    } while ( DB_AA::test('alerts_collection', [['id', $new_id]]) );
    return $new_id;
}

// ----------------------------------------------------------------------------------------

function getAlertsField($field_id, $collection_id) {
    return substr($field_id.".............", 0, 16 - strlen ($collection_id)). $collection_id;
}

// -----------------------------------------------------------------------------------

/** confirm_email function
 *   Confirms email on Reader management slices because the parameter $aw
 *   is sent only in Welcome messages.
 *   Returns true if email exists and not yet confirmed, false otherwise.
 */
function confirm_email($slice_id, $aw): bool {

    require_once __DIR__ . "/../../include/itemfunc.php";

    $set  = new AA_Set($slice_id, new AA_Condition(FIELDID_ACCESS_CODE, '==', $aw));
    $zids = $set->query();

    if ($zids->count() != 1) {
        warn("AW not OK: ".$zids->count()." items");
        return false;
    }
    UpdateField($zids->longids(0), FIELDID_MAIL_CONFIRMED, new AA_Value('1'));
    return true;
}

/** unsubscribe_reader function
 */
function unsubscribe_reader($slice_id, $au, $c): bool {
    require_once __DIR__ . "/../../include/itemfunc.php";

    $readers = DB_AA::select('iid', 'SELECT LOWER(HEX(item.id)) as iid FROM `content`,`item`',[['content.item_id', 'item.id', 'j'],['item.slice_id',$slice_id,'l'],['content.field_id', FIELDID_ACCESS_CODE],['content.text',$au]]);
    if (count($readers) != 1) {
        warn("AU not OK: ".count($readers)." items");
        return false;
    }
    $item_id   = reset($readers);
    $field_id  = getAlertsField(FIELDID_HOWOFTEN, $c);
    $frequency = DB_AA::select1('text', 'SELECT `text` FROM `content`',[['item_id', $item_id, 'l'], ['field_id', $field_id]]);

    if ($frequency === false) {
        return false;
    }

    if (!str_starts_with($frequency, 'unsub')) {  // left old unsub message and date
        UpdateField($item_id, $field_id, new AA_Value('unsubscribed:'. date('Y-m-d H:i'). ':au')); // just inform text
        debug("<!--OK: f $field_id unsubscribed-->");
    }
    return true;
}
