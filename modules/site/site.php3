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

function getmicrotime(){
  list($usec, $sec) = explode(" ",microtime());
  return ((float)$usec + (float)$sec);
}

$timestart = getmicrotime();

// APC AA site Module main administration page
require_once "../../include/config.php3";
require_once $GLOBALS["AA_INC_PATH"]."locsess.php3";
require_once $GLOBALS["AA_INC_PATH"]."util.php3";
require_once $GLOBALS["AA_INC_PATH"]."pagecache.php3";
require_once $GLOBALS["AA_INC_PATH"]."stringexpand.php3";
require_once $GLOBALS["AA_INC_PATH"]."item.php3"; // So site_ can create an item

function IsInDomain( $domain ) {
    global $HTTP_HOST;
    return (($HTTP_HOST == $domain)  || ($HTTP_HOST == 'www.'.$domain));
}

// ----------------- function definition end -----------------------------------
trace("+","site.php3");
$db          = new DB_AA;
$err["Init"] = "";          // error array (Init - just for initializing variable

// change the state
add_vars();                 // get variables pased to stm page

$site_info = GetModuleInfo($site_id,'W');   // W is identifier of "site" module
                                            //    - see /include/constants.php3

if ( !$site_info['state_file'] ) {
    echo "<br>Error: no 'state_file' defined";
    exit;
}

if ( substr($site_info['state_file'],0,4) == 'http' ) {
  echo "TODO";
} else {
  // in the following file we should define apc_state variable
  require_once "./sites/site_".$site_info['state_file'];
}

trace("=","site.php3","precachecheck");
// Look into cache if the page is not cached

// CACHE_TTL defines the time in seconds the page will be stored in cache
// (Time To Live) - in fact it can be infinity because of automatic cache
// flushing on page change
// CACHE_PURGE_FREQ - frequency in which the cache is checked for old values
//                    (in seconds)

/** Create keystring from values, which exactly identifies resulting content */
//  25June03 - Mitra - added post2shtml into here, maybe should add all URL?
//  25Sept03 - Honza - all apc_state is serialized instead of just
//       $apc_state['state'] (we store browser agent in state in kormidlo.cz)
$key_str = serialize($apc_state).":".$site_id.":".$post2shtml_id;
if( is_array($slices4cache) && !$nocache && ($res = $GLOBALS['pagecache']->get($key_str)) ) {
    echo $res;
    if( $debug ) {
        $timeend = getmicrotime();
        $time    = $timeend - $timestart;
        echo "<br><br>Site cache hit!!! Page generation time: $time";
    }
    trace("-");
    exit;
}
trace("=","site.php3","precachecheck");

require_once "./util.php3";                      // module specific utils
require_once "./sitetree.php3";
require_once $GLOBALS["AA_INC_PATH"]."searchlib.php3";
require_once $GLOBALS["AA_INC_PATH"]."easy_scroller.php3";
require_once $GLOBALS["AA_INC_PATH"]."view.php3";
require_once $GLOBALS["AA_INC_PATH"]."discussion.php3";
require_once $GLOBALS["AA_INC_PATH"]."item.php3";

trace("=","site.php3","preGetSite");
$res = ModW_GetSite( $apc_state, $site_id, $site_info );
echo $res;

// In $slices4cache array MUST be listed all (unpacked) slice ids (and other
// modules including site module itself), which is used in the site. If you
// mention the slice in this array, cache is cleared on any change of the slice
// (item addition) - the page is regenerated, then.

if (is_array($slices4cache) && !$nocache) {
    $clear_cache_str = "slice_id=". join(',slice_id=', $slices4cache);
    $GLOBALS['pagecache']->store($key_str, $res, $clear_cache_str);
}

if( $debugtime ) {
    $timeend = getmicrotime();
    $time    = $timeend - $timestart;
    echo "<br><br>Page generation time: $time";
    print_r($GLOBALS['d_times']);
}

// ----------------- process status end ---------------------------------------

function ModW_GetSite( $apc_state, $site_id, $site_info ) {
    global $show_ids;
    trace("+","ModW_GetSite");

    // site_id should be defined as url parameter
    $module_id   = $site_id;
    $p_module_id = q_pack_id($module_id);

    $tree        = new sitetree();
    $tree        = unserialize($site_info['structure']);

    $show_ids    = array();

    // it fills $show_ids array
    $tree->walkTree($apc_state, 1, 'ModW_StoreIDs', 'cond');
    if (count($show_ids)<1) {
        trace("-");
        exit;
    }

    $in_ids = implode( $show_ids, ',' );

    $db = getDB();
    // get contents to show
    $SQL = "SELECT spot_id, content, flag from site_spot
             WHERE site_id='$p_module_id' AND spot_id IN ($in_ids)";
    $db->tquery($SQL);
    while( $db->next_record() ) {
        $contents[$db->f('spot_id')] = $db->f('content');
        $flags[$db->f('spot_id')]    = $db->f('flag');
    }
    freeDB($db);

    foreach ( $show_ids as $v ) {
        $spot_content = $contents[$v];
        $out .= ( ($flags[$v] & MODW_FLAG_JUST_TEXT) ?
                $spot_content : ModW_unalias($spot_content, $apc_state) );
    }
    trace("-");
    return $out;
}

function ModW_StoreIDs($spot_id, $depth) {
    if ($GLOBALS['errcheck'] && ! $spot_id)      // There is a bug causes this
        huhl("Warning adding empty spot_id");
    $GLOBALS['show_ids'][] = $spot_id;
}

function ModW_unalias( &$text, &$state ) {
    // just create variables and set initial values
    trace("+","ModW_unalias",htmlentities($text));
    $maxlevel = 0;
    $level    = 0;
    $ret      = new_unalias_recurent($text, "", $level, $maxlevel,$state['item']);
    trace("-");
    return $ret;
}

// id = an item id, unpacked or short
// short_ids = boolean indicating type of $ids (default is false => unpacked)
function ModW_id2item($id,$use_short_ids="false") {
    return GetItemFromId($id, $use_short_ids);
}

/** Convert a state string into an array, based on the variable names and
 *  regular expression supplied, if str is not present or doesn't match
 *  the regular expression then use $strdef
 *  e.g. ModW_str2arr("tpmi",$apc,"--h-",	"^([-p])([-]|[0-9]+)([hbsfcCt])([-]|[0-9]+)";
 */
function ModW_str2arr($varnames, $str, $strdef, $reg) {
    global $debug;
    if (!$str) { $str = $strdef; }
    $varout = array();
    if (!(ereg($reg, $str, $vars))) {
        if (!(ereg($reg, $strdef, $vars))) {
            print("Error initial string $strdef doesn't match regexp $reg\n<br>");
        }
    }
    for ($i=0;$i < min(strlen($varnames),count($vars)-1); ++$i) {
        $varout[substr($varnames,$i,1)] = $vars[$i+1];
    }
    if ($debug) { print("<br>State="); print_r($varout); }
    return $varout;
}

/** Convert an array into a state string, in the order from $varnames
 *  This is fairly simplistic, just concatennating state, a more
 *  sophisticated sprint version might be needed
 */
function ModW_arr2str($varnames, $arr) {
    $strout = "";
    for ($i=0; $i < strlen($varnames); ++$i) {
        $strout .= $arr[substr($varnames,$i,1)];
    }
    return $strout;
}

// do not remove this exit - we do not want to allow users
// to include this script (honzam)
exit;

?>
