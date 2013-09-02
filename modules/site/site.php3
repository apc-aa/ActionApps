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

$timestart = microtime(true);

// APC AA site Module main administration page
require_once "../../include/config.php3";
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."pagecache.php3";
//require_once AA_INC_PATH."stringexpand.php3";
//require_once AA_INC_PATH."item.php3"; // So site_ can create an item
require_once AA_BASE_PATH."modules/site/router.class.php";
require_once AA_INC_PATH."hitcounter.class.php3";

function IsInDomain( $domain ) {
    return (($_SERVER['HTTP_HOST'] == $domain)  || ($_SERVER['HTTP_HOST'] == 'www.'.$domain));
}

// ----------------- function definition end -----------------------------------
$db          = new DB_AA;
$err["Init"] = "";          // error array (Init - just for initializing variable

// change the state
add_vars();                 // get variables pased to stm page

function StripslashesDeep($value) {
    return is_array($value) ? array_map('StripslashesDeep', $value) : stripslashes($value);
}

if ( get_magic_quotes_gpc() ) {
    $_POST    = StripslashesDeep($_POST);
    $_GET     = StripslashesDeep($_GET);
    $_REQUEST = StripslashesDeep($_REQUEST);
    $_COOKIE  = StripslashesDeep($_COOKIE);
}

AA::$site_id = $_REQUEST['site_id'];
$site_info = GetModuleInfo(AA::$site_id,'W');   // W is identifier of "site" module

//    - see /include/constants.php3
if ( !is_array($site_info) ) {
    echo "<br>Error: no 'site_id' or 'site_id' is invalid";
    exit;
}

// There are two possibilities, how to control the apc_state variable. It could
// be se in ./modules/site/sites/site_...php control file. The control file
// could be managed only by people, who have the access to the AA sources on the
// server. If we want to permit control of the site to extenal people, which do
// not have access to AA scripts directory, then it is possible to them to not
// fill "site control file" in site configuration dialog and then call this
// script from their own file, where the new $apc_state, $slices4cache and
// $site_id will be defined and passed by GET method. Just like this:
//
//    $url  = 'http://example.org/apc-aa/modules/site/site.php3?';
//    $url .= http_build_query( array( 'apc_state'    => $apc_state,
//                                     'slices4cache' => $slices4cache,
//                                     'site_id'      => 'ae54378beac7c7e8a998e7de8a998e7a'
//                                    ));
//    readfile($url);

$hit_zid = null;
if ($site_info['flag'] == 1) {    // 1 - Use AA_Router_Seo
    $slices4cache = GetTable2Array("SELECT destination_id FROM relation WHERE source_id='". q_pack_id(AA::$site_id) ."' AND flag='".REL_FLAG_MODULE_DEPEND."'", '', "unpack:destination_id");
    $lang_file    = AA_Modules::getModuleProperty(AA::$site_id, 'lang_file');
    $home         = trim($site_info['state_file']) ? trim($site_info['state_file']) : '/' .substr($lang_file,0,2). '/';
    $router       = AA_Router::singleton('AA_Router_Seo', $slices4cache, $home);

    // use REDIRECT_URL for homepage redirects:
    //    RewriteRule ^/?$ /en/home [QSA]
    //    RewriteRule ^/?en /apc-aa/modules/site/site.php3?site_id=439ee0af030d6b2598763de404aa5e34 [QSA,L,PT]

    // or (I think better)
    //    RewriteEngine on
    //    RewriteRule ^/?$  /apc-aa/modules/site/site.php3?site_id=439ee0af030d6b2598763de404aa5e34 [QSA,L,PT]
    //    RewriteRule ^/?en /apc-aa/modules/site/site.php3?site_id=439ee0af030d6b2598763de404aa5e34 [QSA,L,PT]


    $uri          = (strlen($_SERVER['REQUEST_URI']) > 1) ? $_SERVER['REQUEST_URI'] : $_SERVER['REDIRECT_URL'];
    $apc_state    = $router->parse($uri);
    $lang_file    = substr_replace($lang_file, $apc_state['xlang'], 0, 2);

    // count hit for current page - deffered after the page is sent to user
    if ($tmp_xid = $router->xid()) {
        $hit_zid = new zids($tmp_xid, 'l');
    }
} elseif ( $site_info['state_file'] ) {
    // in the following file we should define apc_state variable
    require_once AA_BASE_PATH."modules/site/sites/site_".$site_info['state_file'];
}

if ( !isset($apc_state) )  {
    echo "<br>Error: no 'state_file' nor 'apc_state' variable defined";
    exit;
}

// CACHE_TTL defines the time in seconds the page will be stored in cache
// (Time To Live) - in fact it can be infinity because of automatic cache
// flushing on page change

/** Create keystring from values, which exactly identifies resulting content */
//  25June03 - Mitra - added post2shtml into here, maybe should add all URL?
//  25Sept03 - Honza - all apc_state is serialized instead of just
//       $apc_state['state'] (we store browser agent in state in kormidlo.cz)
//  28Apr05  - Honza - added also $all_ids, $add_disc, $disc_type, $sh_itm,
//                     $parent_id, $ids, $sel_ids, $disc_ids - for discussions
//                      - it is in fact all global variables used in view.php3
$cache_key = get_hash('site', PageCache::globalKeystring(), AA::$site_id.":$post2shtml_id:$all_ids:$add_disc:$disc_type:$sh_itm:$parent_id", $ids, $sel_ids, $disc_ids);

// store nocache to the variable (since it should be set for some view and we
// do not want to have it set for whole site.
// temporary solution - should be solved on view level (not global nocache) - TODO
$site_nocache = $_REQUEST['nocache'];
if (is_array($slices4cache) && ($res = $GLOBALS['pagecache']->get($cache_key,$site_nocache))) {
    echo $res;
    if ( $debug ) {
        echo '<br><br>Site cache hit!!! Page generation time: '. (microtime(true) - $timestart);
    }
    if ($hit_zid) {
        AA_Hitcounter::hit($hit_zid);
    }
    exit;
}

require_once AA_BASE_PATH."modules/site/util.php3";                      // module specific utils
require_once AA_BASE_PATH."modules/site/sitetree.php3";
require_once AA_INC_PATH."searchlib.php3";
require_once AA_INC_PATH."easy_scroller.php3";
require_once AA_INC_PATH."view.php3";
require_once AA_INC_PATH."discussion.php3";
require_once AA_INC_PATH."item.php3";

if ($lang_file) {
    mgettext_bind(GetLang($lang_file), 'output');
}

$res = ModW_GetSite( $apc_state, AA::$site_id, $site_info );
echo $res;

if ($hit_zid) {
    AA_Hitcounter::hit($hit_zid);
}

// In $slices4cache array MUST be listed all (unpacked) slice ids (and other
// modules), which is used in the site. If you mention the slice in this array,
// cache is cleared on any change of the slice (item addition) - the page
// is regenerated, then.
// UPDATE: there is no need to add alse site module itself, since it
// is added automaticaly - Honza 2005-06-16

if ( $GLOBALS['debug'] ) huhl("<br>Site.php3 is_array(slices4cache):". is_array($slices4cache), '<br>Site.php3 nocache:'.$site_nocache);

// the cache should be always cleared, if the site is changed
if (!in_array(AA::$site_id, (array)$slices4cache)) {
    $slices4cache[] = AA::$site_id;
}

if (is_array($slices4cache) && !$site_nocache) {
    $str2find = new CacheStr2find($slices4cache, 'slice_id');
    $GLOBALS['pagecache']->store($cache_key, $res, $str2find);
}


if ($debugtime) {
    $timeend = microtime(true);
    $time    = $timeend - $timestart;
    echo "<br><br>Page generation time: $time";
    print_r($GLOBALS['d_times']);
}

// ----------------- process status end ---------------------------------------

function ModW_GetSite( $apc_state, $site_id, $site_info ) {
    global $show_ids;

    // site_id should be defined as url parameter
    $module_id   = $site_id;
    $p_module_id = q_pack_id($module_id);

    $tree        = new sitetree();
    $tree        = unserialize($site_info['structure']);

    $show_ids    = array();

    // it fills $show_ids array
    $tree->walkTree($apc_state, 1, 'ModW_StoreIDs', 'cond');
    if (count($show_ids)<1) {
        exit;
    }

    $in_ids = implode( $show_ids, ',' );

    $db = getDB();
    // get contents to show
    $SQL = "SELECT spot_id, content, flag from site_spot WHERE site_id='$p_module_id' AND spot_id IN ($in_ids)";
    $db->tquery($SQL);
    while ( $db->next_record() ) {
        $contents[$db->f('spot_id')] = $db->f('content');
        $flags[$db->f('spot_id')]    = $db->f('flag');
    }
    freeDB($db);

    foreach ( $show_ids as $v ) {
        $spot_content = $contents[$v];
        $out .= ( ($flags[$v] & MODW_FLAG_JUST_TEXT) ? $spot_content : AA_Stringexpand::unalias($spot_content, '', $apc_state['item']));
    }
    return $out;
}

function ModW_StoreIDs($spot_id, $depth) {
    if ($GLOBALS['errcheck'] && ! $spot_id) {      // There is a bug causes this
        huhl("Warning adding empty spot_id");
    }
    $GLOBALS['show_ids'][] = $spot_id;
}

// id = an item id, unpacked or short
// short_ids = boolean indicating type of $ids (default is false => unpacked)
function ModW_id2item($id,$use_short_ids="false") {
    return AA_Items::getItem(new zids($id, $use_short_ids ? 's' : 'l'));
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
    for ($i=0, $ino=min(strlen($varnames),count($vars)-1); $i<$ino; ++$i) {
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
    for ($i=0, $ino=strlen($varnames); $i<$ino; ++$i) {
        $strout .= $arr[substr($varnames,$i,1)];
    }
    return $strout;
}

// do not remove this exit - we do not want to allow users
// to include this script (honzam)
exit;

?>
