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

# call this script with the name of the .shtml page you want to send variables to
# parameters:
#    shtml_page = complete URL of the requested .shtml page

require "include/config.php3";
require $GLOBALS[AA_INC_PATH]."locsess.php3"; 

$db = new DB_AA;

$vars = array (
    "post" => $HTTP_POST_VARS,
    "get" => $HTTP_GET_VARS,
    "files" => $HTTP_POST_FILES,
    "cookie" => $HTTP_COOKIE_VARS);
    
reset ($vars);    
while (list ($key) = each ($vars)) {
    $var = &$vars[$key];
    if (is_array($var["md5"])) {
        md5_array ($var["md5"]);
        add_var2 ($var["md5"], $var);
        unset ($var["md5"]);
    }
}

$vars = addslashes (serialize ($vars));
     
$id = new_id();    
$db->query ("
    INSERT INTO post2shtml (id, vars, time) 
    VALUES ('$id', '$vars', ".time().")");

header("Status: 302 Moved Temporarily");
$shtml_page = stripslashes ($shtml_page);
$shtml_page .= (strchr ($shtml_page,"?") ? "&" : "?") . "post2shtml_id=$id";
header("Location: $shtml_page");

# returns new unpacked md5 unique id, except these which can  force unexpected end of string  
function new_id ($seed="hugo"){
  do {
   $foo=md5(uniqid($seed));
  } while (ereg("(00|27)",$foo));  // 00 is end of string, 27 is '
  return $foo;
} 

function md5_array (&$array) {
    if (is_array ($array)) {
        reset ($array);
        while (list ($key) = each ($array)) 
            md5_array (&$array[$key]);
    }
    else if ($array)
        $array = md5 ($array);
}

/** Adds all values from the $source array to the $dest array. Follows all paths
* in order that all values present in $dest and not in $source are kept.
*/
function add_var2 (&$source, &$dest) {
    if (is_array ($source)) {
        reset ($source);
        while (list ($key) = each ($source))
            add_var3 ($key, &$source[$key], &$dest);
    }
}

/** Recursively adds all values from the $source array to the $dest array. Follows all paths
* in order that all values present in $dest and not in $source are kept.
*/
function add_var3 ($varname, &$source, &$dest) {
    if (is_array ($source)) {
        reset ($source);
        while (list ($key) = each ($source)) 
            add_var3 ($key, &$source[$key], &$dest[$varname]);
    }
    else if (isset ($source))
        $dest[$varname] = $source;
}
?>
