<?php

// This file is no longer needed - use AA_Router_Seo - in site moduule settings
// Honza 2015-12-13

/**
 * Example of external site module control file
 *
 * This is not the right place for this file - you should copy it to any
 * directory of your (or clients) website and control the site module from there
 *
 * @package Site Module
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 */
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

/**
 * Site control file site module
 *
 * All we have to do here is to transform passed variables to $apc_state
 * array and define $slices4cache array. Both those arrays then should be passed
 * to /apc-aa/modules/site.php3 script, which displays the content, then.
 *
 * ==Site control file==
 *
 * There are two possibilities, how to control the apc_state variable.
 *
 * 1) It could be se in ./modules/site/sites/site_...php control file.
 *    In such case the control file could be managed only by people, who have
 *    the access to the AA sources on the server.
 *
 * 2) If we want to permit control of the site to extenal people, which do
 *    not have access to AA scripts directory, then it is possible to them to
 *    not fill "site control file" in site configuration dialog and then call
 *    /apc-aa/modules/site/site.php3 script from this file. There we should
 *    prepare new $apc_state and togetger with $slices4cache and $site_id pass
 *    it by GET method. Just like this:
 *
 *    $url  = 'http://example.org/apc-aa/modules/site/site.php3?';
 *    $url .= http_build_query( array( 'apc_state'    => $apc_state,
 *                                     'slices4cache' => $slices4cache,
 *                                     'site_id'      => 'ac55f66e9d8f3f72345e16da7891f6ee'
 *                                    ));
*/


// new_state() function
//   - exactly the same as we use in standard site control file
function new_state(&$new_state, $x, $p, $d, $t, $scrl) {
    if (isset($t)) {
        $new_state['t'] = $t;
    }
    if (isset($d)) {
        $new_state['d'] = $d;
    }
    if (isset($p)) {
        $new_state['p'] = $p;
        $new_state['x'] = '-';
    }
    if (isset($x)) {
        $new_state['x'] = $x;
    }
    /*
    if (isset($scrl)) { #page scroller
        $pagevar = "scr_".$scrl."_Go";
        $new_state['a'] = $GLOBALS[$pagevar];
    }
    */

    return $new_state;
}

// -------------------- Some helper functions -----------------------------

/** Convert a state string into an array, based on the variable names and
 *  regular expression supplied, if str is not present or doesn't match
 *  the regular expression then use $strdef
 *  e.g. ApcStr2Arr("tpmi",$apc,"--h-",	"^([-p])([-]|[0-9]+)([hbsfcCt])([-]|[0-9]+)";
 */
function ApcStr2Arr($varnames, $str, $strdef, $reg) {
    if (!$str) { $str = $strdef; }
    $varout = array();
    $reg    = '`'.str_replace('`','\`',$reg).'`';
    if (!(preg_match($reg, $str, $vars))) {
        if (!(preg_match($reg, $strdef, $vars))) {
            print("Error initial string $strdef doesn't match regexp $reg\n<br>");
        }
    }
    for ($i=0, $ino = min(strlen($varnames),count($vars)-1); $i < $ino; ++$i) {
        $varout[substr($varnames,$i,1)] = $vars[$i+1];
    }
    return $varout;
}

/** Convert an array into a state string, in the order from $varnames
 *  This is fairly simplistic, just concatennating state, a more
 *  sophisticated sprint version might be needed
 */
function ApcArr2Str($varnames, $arr) {
    $strout = "";
    for ($i=0, $ino = strlen($varnames); $i < $ino; ++$i) {
        $strout .= $arr[substr($varnames,$i,1)];
    }
    return $strout;
}

/*---------------------------------------------------------------------------*/

// there is some example of apc variable as taken from one of our site
$apc_varnames = 'xpdt';
$apc_reg = "^([-]|[0-9]+)([-]|[a-z]{3})([-]|[1])([-]|[t])\$";
/*            ^===x====^  ^====p=====^  ^==d==^  ^==t==^ */
// x - item short_id
// p - page id
//	new - News
//	pub - Publications
//	nle - Newsletter
// 	act - Activities
// 	prj - Projects
// 	thm - Themes
// 	lnk - Links
// 	abt - About us
// d - display "Documents" menu instead "Press releases" and "New publications"
// t - set manually to "t", when testing, to print debug info

$apc_init = '----';   // Home page
$apc_state = ApcStr2Arr($apc_varnames, $apc, $apc_init, $apc_reg);

// get new state
new_state($apc_state, $x, $p, $d, $t, $scrl);

// more variables for site module
// such variables then could be used like {state} in your site code
$apc_state['state'] = ApcArr2Str($apc_varnames, $apc_state);
$apc_state['root'] = 'http://example.org/test';

$slices4cache = array(
    "c76cf3e44aa9da1d240c8b4cb37fba47", // Example slice - items'
    "e0709c24784078117368db404422249d", // Example slice - projects'
    "1c82a8e092b826208abcf4676dd737f8", // Example slice - links
);

$url  =  'http://example.org/apc-aa/modules/site/site.php3?';
$url .= http_build_query( array( 'apc_state'    => $apc_state,
                                 'slices4cache' => $slices4cache,
                                 'site_id'      => '9d87891f6eff3f7406cf55f66e1e16da'
                                ));

readfile($url);
exit;

?>