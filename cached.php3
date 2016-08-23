<?php
/** Gets the content from the cache based on keystr
 *  This file is useful for storin external javascript files (used for client
 *  side speedup, because it is cached on client side
 *
 *  @package UserOutput
 *  @version $Id$
 *  @author Honza Malik <honza.malik@ecn.cz>
 *  @copyright Econnect, Honza Malik, October 2004
 *
 *  @param keystr = 32-characters long hexadecimal number of chache record
 */
/*
Copyright (C) 2002 Association for Progressive Communications
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

/** APC-AA configuration file */
require_once "./include/config.php3";
/** Main include file for using session management function on page */
require_once AA_INC_PATH."locsess.php3";
/** Caching functions */
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."pagecache.php3";

// headers copied from include/extsess.php3 file
$allowcache_expire = 24*3600; // 1 day
$exp_gmt           = gmdate("D, d M Y H:i:s", time() + $allowcache_expire) . " GMT";
$mod_gmt           = gmdate("D, d M Y H:i:s", getlastmod()) . " GMT";
// send headers for cache
header('Expires: '       . $exp_gmt);
header('Last-Modified: ' . $mod_gmt);
header('Cache-Control: public');
header('Cache-Control: max-age=' . $allowcache_expire);
header('Content-Type: application/x-javascript');
if(!$keystr) {
    $keystr = $_REQUEST['keystr'];
}
echo $GLOBALS['pagecache']->getById($keystr);

?>