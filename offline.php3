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

// script for off-line filling (items send in some XML format, currently WDDX)

// Parameters:
//   slice_id     - id of slice into which the item is added
//   offline_data - set of WDDXed items to fill in
//   offline_file - alternative to offline_data - WDDXed items in a file
//   del_url      - url to point the script if filling is successfull
//                  (should delete local copy of file with wddx)

/**
 * Handle with PHP magic quotes - quote the variables if quoting is set off
 * @param mixed $value the variable or array to quote (add slashes)
 * @return mixed the quoted variables (with added slashes)
 */
function AddslashesDeep($value) {
    return is_array($value) ? array_map('AddslashesDeep', $value) : addslashes($value);
}

if (!get_magic_quotes_gpc()) {
    // Overrides GPC variables
    foreach ($_GET as $k => $v) {
        $kk = AddslashesDeep($v);
    }
    foreach ($_POST as $k => $v) {
        $kk = AddslashesDeep($v);
    }
    foreach ($_COOKIE as $k => $v) {
        $kk = AddslashesDeep($v);
    }
}

require_once "./include/config.php3";
require_once AA_INC_PATH."locsess.php3";
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."itemfunc.php3";
require_once AA_INC_PATH."notify.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."feeding.php3";
require_once AA_INC_PATH."convert_charset.class.php3";

define("WDDX_DUPLICATED", 1);
define("WDDX_BAD_PACKET", 2);

/** IsDuplicated function
 *  is packet already stored in database?
 * @param $packet
 * @param $db
 */
function IsDuplicated( $packet, $db ) {
    if ( is_array($packet) || is_object($packet)) {
        $packet = serialize($packet);
    }
    $SQL = "SELECT * FROM offline WHERE digest='". md5($packet) ."'";
    $db->query($SQL);
    return ( $db->next_record() ? 1 : 0 );
}

/** RegisterItem function
 *  is packet already stored in database?
 * @param $id
 * @param $packet
 * @param $db
 */
function RegisterItem( $id, $packet, $db ) {
    if (is_array($packet) || is_object($packet)) {
        $packet = serialize($packet);
    }
    $SQL = "INSERT INTO offline ( id, digest, flag ) VALUES ( '$id', '". md5($packet) ."', '' )";
    $db->query($SQL);
}

/** StoreWDDX2DB function
 * gets one item stored in WDDX format and stored it in database
 * @param $packet
 * @param $slice_id
 * @param $fields
 * @param $bin2fill
 */
function StoreWDDX2DB( $packet, $slice_id, $fields, $bin2fill ) {
    global $db, $itemvarset, $varset;

    if (IsDuplicated($packet, $db)) {
        return WDDX_DUPLICATED;
    }

    $vals =  wddx_deserialize($packet);
    if (!$vals) {
        return WDDX_BAD_PACKET;
    }

    // update database
    $id = new_id();

    $slice  = AA_Slices::getSlice($slice_id);
    $charset = $slice->getCharset();   // like 'windows-1250'
    $encoder       = new ConvertCharset;

    // prepare content4id array before call StoreItem function
    while (list($key,$val) = each($vals)) {
        if (isset($val) AND is_array($val)) {
            switch( $val[0] ) {   // field type - defines action to do with content
                case "base64":
                    $content4id[$key][0]['value'] = base64_decode($val[2]);
                    // $val[1] is filename - not used now
                    break;
                default:                           // store multiple values
                    reset($val);
                    $i=0;
                    while (list(,$v) = each($val)) {
                        $content4id[$key][$i]['value']   = $encoder->Convert($v, 'utf-8', $charset);
                        $content4id[$key][$i++]['flag'] |= FLAG_OFFLINE;  // mark as offline filled
                    }
            }
        } else {                           // if not array - just store content
            $content4id[$key][0]['value'] = $encoder->Convert($val, 'utf-8', $charset);
        }
        // set html flag from field default
        if ( $fields[$key]["html_default"] > 0 ) {
            $content4id[$key][0]['flag'] |= FLAG_HTML;
        }
        $content4id[$key][0]['flag'] |= FLAG_OFFLINE;      // mark as offline filled
    }

    // fill required fields if not set
    $content4id["status_code....."][0]['value'] = ($bin2fill==1 ? 1 : 2);
    if (!$content4id["post_date......."]) {
        $content4id["post_date......."][0]['value'] = time();
    }
    if (!$content4id["publish_date...."]) {
        $content4id["publish_date...."][0]['value'] = time();
    }
    if (!$content4id["expiry_date....."]) {
        $content4id["expiry_date....."][0]['value'] = time()+157680000;
    }
    if (!$content4id["last_edit......."]) {
        $content4id["last_edit......."][0]['value'] = time();
    }
    $content4id["flags..........."][0]['value'] = ITEM_FLAG_OFFLINE;

    StoreItem($id, $slice_id, $content4id, $fields, true, true, true);
                                      // insert, invalidatecache, feed
    RegisterItem(q_pack_id($id), $packet, $db);
    return WDDX_OK;
}

function SendErrorPage($txt) {
    HTMLPageBegin();
    echo "</head><body>".$txt."</body></html>";
    exit;
}

function SendOkPage($txt) {
    HTMLPageBegin();
    echo "</head><body>".$txt."</body></html>";
    exit;
}

  // init used objects
$db = new DB_AA;
$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();
$itemvarset = new Cvarset();

if ( !$slice_id ) {
    SendErrorPage(_m("Slice ID not defined"));
}

$error = "";
$ok = "";

$p_slice_id = q_pack_id($slice_id);
$slice      = AA_Slices::getSlice($slice_id);

// There are two possibilities, how to send the data
//   1) using GET/POST in $offline_data variable
//   2) using file input type in $offline_file variable
if ( isset($_FILES['offline_file'] )) {
    $offline_data = Files::getUploadedFile('offline_file');
} else {
    $offline_data = stripslashes($offline_data);
}
$offline_data = str_replace(chr(14),' ',$offline_data);  // remove wrong chars

// java applet for some reason sometimes skips first 8 characters
// Well, the first 8 characters are skiped on upload, because the file on client
// contains long number as first 8 characters (not sure why, now). But if you
// edit the uploaded file (for some debug purposes), you can delete it. In that
// case we have to repair the data and restore first 8 characters
// You can delete next lines, if you want - it is usable just for debuging
if ( (strlen($offline_data) > 4) AND (substr($offline_data,0,4) == 'ket') ) {
    $offline_data = '<wddxPac'. $offline_data;
}

if ( !$slice ) {
    SendErrorPage(_m("Bad slice ID"));
}

if ( $slice->getProperty("permit_offline_fill") < 1 ) {
    SendErrorPage(_m("You don't have permission to fill this slice off-line"));
} else {
    $bin2fill = $slice->getProperty("permit_offline_fill");
}

// get slice fields and its priorities in inputform
list($fields,$prifields) = GetSliceFields($slice_id);

$packets = explode( "<wddxPacket", $offline_data );
foreach ($packets as $packet) {;
    if ( strlen($packet) < 6 ) {  // throw first, it should be "";
        continue;
    }
    $packet = "<wddxPacket".$packet;

    // fix for php >5.1 - it crashes without those lines
    // (IE shows Page not found) - I think it is PHP 5.1.2 bug
    //$packet = utf8_encode($packet);
    $packet = str_replace("<wddxPacket>","<wddxPacket version='1.0'>",$packet);

    switch (StoreWDDX2DB($packet,$slice_id,$fields,$bin2fill)) {
        case WDDX_DUPLICATED:
        $ok .= MsgOk( _m("Duplicated item send - skipped") );  // this is error but not fatal - i
        break;
        case WDDX_BAD_PACKET:
        $error .= MsgErr( _m("Wrong data (WDDX packet)") );
        break;
        case WDDX_OK:
        $ok .= MsgOk( _m("Item OK - stored in database") );  // this is error but not fatal - i
        break;
    }
}

if ( $error ) {
  SendErrorPage( $error );
} else {
  SendOkPage( "$ok<br>". _m("Now you can dalete local file. ") .
                  " <a href='$del_url'>"._m(" Delete ")."</a>", $del_url );
}

?>
