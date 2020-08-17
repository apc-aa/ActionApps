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

/** Old utility functions for "Control file" controlled site modules
 * (the ones used control file in modules/site/sites - like the example - site_bayfm.php3)
 * Now we use SEO Router based sites, which do not need control file nor this utils
 *
 * The function bellow may be used in some site control files. AA itself no longer needs it
 *
 */

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
    if (!$str) { $str = $strdef; }
    $varout = [];
    $reg    = '`'.str_replace('`','\`',$reg).'`';
    if (!(preg_match($reg, $str, $vars))) {
        if (!(preg_match($reg, $strdef, $vars))) {
            print("Error initial string $strdef doesn't match regexp $reg\n<br>");
        }
    }
    for ($i=0, $ino=min(strlen($varnames),count($vars)-1); $i<$ino; ++$i) {
        $varout[substr($varnames,$i,1)] = $vars[$i+1];
    }
    AA::$debug&2 && AA::$dbg->info('State=',$varout);
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

