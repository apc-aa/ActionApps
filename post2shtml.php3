<?php
/**
 * Allows to POST data to a PHP script SSI-included in a .shtml page.
 *
 * You can't use the POST method directly for .shtml pages, you must always use GET,
 * which has several disadvantages: the length of all parameters is limited by
 * a small size and the parameters appear in the URL.
 *
 * But post2shtml helps you. Instead of using
 *     <form action="some_page.shtml" method="get">
 * you write
 *     <form action="/aa/post2shtml.php3?shtml_page=some_page.shtml" method="post">
 *
 * The script works in these steps:
 *
 * 1. All the data coming in POST, GET, COOKIES and FILES is serialized and stored
 *    into the database, table post2shtml, with a new unique ID post2shtml_id
 * 2. The web server is redirected to "some_page.shtml?post2sthml_id=aa45db3d345de..."
 * 3. The web server parses the page "some_page.shtml" and comes to some
 *    SSI include like <!--#include virtual="/aa/some_script.php3"-->. It calls
 *    the PHP script some_script.php3.
 * 4. some_script.php3 knows it may be called this way and thus uses the post2shtml_id
 *    to find the right row in the post2shtml table and thus
 *    retrieve the form data with the function add_post2shtml_vars()
 *    from include/util.php3.
 *
 * One additional feature:
 * You can send passwords, which are stored encrypted by MD5: all members of
 * a md5[] array will be encrypted and stored outside the array. For example if you
 * add &lt;INPUT TYPE=password NAME="md5[password]"&gt; then after calling
 * add_post2shtml_vars() a global variable $password contains the encrypted password.
 * (CHANGE: 06/19/2003 Honza - we no longer call md5() function on passwords -
 *  we call crypt($password,'xx') function instead. crypt() is used for storing
 *  passwords to database (@see /include/itemfunct.php3 insert_fnc_pwd()) - it
 *  uses MD5 on some systems (but on other systems it uses DES?, ...))
 *
 * Parameters: <br>
 *     URL $shtml_page = complete URL of the requested .shtml page
 *
 * If you do not send $shtml_page, no HTTP headers are sent and the post2shtml_id
 * is set as a global variable.
 *
 * @package UserInput
 * @version $Id$
 * @author Jakub Ad�ek, Econnect, December 2002
 * @copyright Copyright (C) 1999-2002 Association for Progressive Communications
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

// post2shtml could be include from other files, where config.php3 is already
// required (and probably is on another path)
if ( !defined('DB_NAME') ) {
    require_once "./include/config.php3";
}
require_once AA_INC_PATH."locsess.php3";
require_once AA_INC_PATH."util.php3";

store_vars();

// Store variables, set $GLOBALS[post2shtml_id] or generate Location header
function store_vars() {
    global $shtml_page;
    if ($GLOBALS['debugfill']) huhl("post2html:store_vars:$shtml_page:");

    $vars = array (
        "post"   => &$_POST,
        "get"    => &$_GET,
        "files"  => &$_FILES,
        "cookie" => &$_COOKIE);

    foreach ($vars as $key => $foo) {
        $var = &$vars[$key];
        if (is_array($var["md5"])) {
            md5_array($var["md5"]);
            add_var2($var["md5"], $var);
            unset($var["md5"]);
        }
    }

    $vars = addslashes( serialize($vars) );

    $id = new_id();
    $db = getDB();
    $db->query("INSERT INTO post2shtml (id, vars, time) VALUES ('$id', '$vars', ".time().")");
    freeDB($db);
    if ($shtml_page) {
        $shtml_page  = stripslashes($shtml_page);
        $shtml_page .= (strchr ($shtml_page,"?") ? "&" : "?") . "post2shtml_id=$id";
        header("HTTP/1.1 Status: 302 Moved Temporarily");
        header("Status: 302 Moved Temporarily");
        header("Location: $shtml_page");
    }
    else {
        $GLOBALS["post2shtml_id"] = $id;
    }
}

function md5_array(&$array) {
    // uses crypt() instead of md5() - Change by honza 06/19/2003 (see top)
    if (is_array($array)) {
        foreach ($array as $key => $foo) {
            md5_array($array[$key]);
        }
    }
    elseif ($array) {
        $array = crypt( $array, 'xx');
    }
}

/** Adds all values from the $source array to the $dest array. Follows all paths
* in order that all values present in $dest and not in $source are kept.
*/
function add_var2(&$source, &$dest) {
    if (is_array($source)) {
        foreach ($source as $key => $foo) {
            add_var3($key, $source[$key], $dest);
        }
    }
}

/** Recursively adds all values from the $source array to the $dest array. Follows all paths
* in order that all values present in $dest and not in $source are kept.
*/
function add_var3($varname, &$source, &$dest) {
    if (is_array($source)) {
        foreach ($source as $key => $foo) {
            add_var3($key, $source[$key], $dest[$varname]);
        }
    }
    elseif (isset ($source)) {
        $dest[$varname] = $source;
    }
}
?>
