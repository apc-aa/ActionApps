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

require_once AA_INC_PATH."mail.php3";
require_once AA_INC_PATH."mgettext.php3";

if (!is_object($db)) {
    $db=new DB_AA;
}

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
    global $collectionid, $collectionprop, $db, $no_slice_id, $slice_id;

    if (!$no_slice_id) {
        if (!$slice_id) { echo "Error: no slice ID"; exit; }
        $collectionprop = GetCollection($slice_id);
        if ($collectionprop) {
            $collectionid = $collectionprop['id'];
        } else {
            echo "Can't find collection with module_id=$slice_id. Bailing out.<br>";
            exit;
        }
    }
}

function get_howoften_options($include_instant = true) {
    if ($include_instant) {
        $retval["instant"] = _m("instant");
    }
    $retval["daily"]     = _m("daily");
    $retval["weekly"]    = _m("weekly");
    $retval["twoweeks"]  = _m("twoweeks");
    $retval["monthly"]   = _m("monthly");
    return $retval;
}

function get_bin_names() {
    return array (
        1 => _m("Active"),
        2 => _m("Holding bin"),
        3 => _m("Trash bin"));
}

function new_collection_id() {
    global $db;
    do {
        $new_id = gensalt(5);
        $db->query("SELECT id FROM alerts_collection WHERE id = '$new_id'");
    } while ($db->next_record());
    return $new_id;
}

// ----------------------------------------------------------------------------------------

function getAlertsField($field_id, $collection_id) {
    return substr($field_id.".............", 0, 16 - strlen ($collection_id)). $collection_id;
}

// -----------------------------------------------------------------------------------

function alerts_con_url($Url,$Params){
  return ( strstr($Url, '?') ? $Url."&".$Params : $Url."?".$Params );
}

function GetEmailLangs() {
    global $LANGUAGE_CHARSETS, $LANGUAGE_NAMES;
    foreach ( $LANGUAGE_CHARSETS as $l => $charset ) {
        $ret[$l] = $LANGUAGE_NAMES[$l]." (".$charset.")";
    }
    return $ret;
}

/** confirm_email function
 *   Confirms email on Reader management slices because the parameter $aw
 *   is sent only in Welcome messages.
 *   Returns true if email exists and not yet confirmed, false otherwise.
 */
function confirm_email($slice_id, $aw) {

    require_once AA_INC_PATH."itemfunc.php3";

    $set  = new AA_Set($slice_id, new AA_Condition(FIELDID_ACCESS_CODE, '=', '"'.$aw.'"'));
    $zids = $set->query();

    if ($zids->count() != 1) {
        if ($GLOBALS['debug']) { echo "AW not OK: ".$zids->count()." items"; }
        return false;
    }
    UpdateField($zids->longids(0), FIELDID_MAIL_CONFIRMED, new AA_Value('1'));
    return true;
}

/** unsubscribe_reader function
 */
function unsubscribe_reader($slice_id, $au, $c) {

    require_once AA_INC_PATH."itemfunc.php3";
    $db = getDB();
    $db->query (
        "SELECT item.id FROM content INNER JOIN item
         ON content.item_id = item.id
         WHERE item.slice_id='".q_pack_id($slice_id)."'
         AND content.field_id='".FIELDID_ACCESS_CODE."'
         AND content.text='$au'");
    if ($db->num_rows() != 1) {
        if ($GLOBALS['debug']) echo "AU not OK: ".$db->num_rows()." items";
        freeDB($db);
        return false;
    }
    $db->next_record();
    $item_id = unpack_id($db->f("id"));

    $field_id = getAlertsField(FIELDID_HOWOFTEN, $c);
    $db->query( "SELECT text FROM content WHERE field_id = '".$field_id."' AND item_id = '".q_pack_id($item_id)."'");

    if ($db->next_record()) {
        $frequency = $db->f("text");
        if ($frequency AND (substr($frequency,0,5) != 'unsub')) {
            $new_frequency = quote('unsubscribed:'. date('Y-m-d H:i'). ':au');  // just inform text
            $db->query( "UPDATE content SET text='$new_frequency' WHERE field_id = '$field_id' AND item_id = '".q_pack_id($item_id)."'");
            if ($GLOBALS['debug']) { echo "<!--OK: f $field_id unsubscribed-->"; }
            freeDB($db);
            return true;
        }
    }
    freeDB($db);
    return false;
}
?>