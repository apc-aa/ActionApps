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

function set_collectionid() {
    global $collectionid, $collectionprop, $db, $no_slice_id, $slice_id;

    if (!$no_slice_id) {
        if (!$slice_id) { echo "Error: no slice ID"; exit; }
        $db->query("SELECT AC.*, module.name, module.lang_file, module.slice_url
            FROM alerts_collection AC INNER JOIN module
            ON AC.module_id = module.id
            WHERE module_id='".q_pack_id($slice_id)."'");
        if ($db->next_record()) {
            $collectionid = $db->f("id");
            $collectionprop = $db->Record;
        } else {
            echo "Can't find collection with module_id=$slice_id (". HTMLEntities(pack_id($slice_id))."). Bailing out.<br>";
            exit;
        }
    }
}

function get_howoften_options($include_instant = true) {
    if ($include_instant) {
        $retval["instant"] = _m("instant");
    }
    $retval["daily"]   = _m("daily");
    $retval["weekly"]  = _m("weekly");
    $retval["monthly"] = _m("monthly");
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
        $new_id = new_alphanumeric_id(5);
        $db->query("SELECT id FROM alerts_collection WHERE id = '$new_id'");
    } while ($db->next_record());
    return $new_id;
}

function new_alphanumeric_id($saltlen) {
    srand((double) microtime() * 1000000);
    $salt_chars = "abcdefghijklmnoprstuvwxBCDFGHJKLMNPQRSTVWXZ0123456589";
    for ($i = 0; $i < $saltlen; $i ++) {
        $salt .= $salt_chars[rand(0,strlen($salt_chars)-1)];
    }
    return $salt;
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
?>