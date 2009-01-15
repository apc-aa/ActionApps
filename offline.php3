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
require_once AA_INC_PATH."xmlparse.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."feeding.php3";

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
