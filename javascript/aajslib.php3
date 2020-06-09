<?php
/** @deprecated - use aajslib-legacy.min.js directly or even better aajslib-jquery.min.js with jQuery
 *
 *  AA Javascripts library usable on the public pages, just like:
 *  <script src="https://actionapps.org/apc-aa/javascript/aajslib.php"></script>
 *  (replace "https://actionapps.org/apc-aa" with your server and aa path
 *
 *  It includes the scripts, which are based on great prototype.js library
 *  (see http://prototype.conio.net/)
 *
 *  @package UserOutput
 *  @version $Id$
 *  @author Honza Malik <honza.malik@ecn.cz>
 *  @copyright Econnect, Honza Malik, December 2006
 *
 */
/*
Copyright (C) 2002 Association for Progressive Communications
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

// headers copied from include/extsess.php3 file
$allowcache_expire = 24*3600; // 1 day
$exp_gmt           = gmdate("D, d M Y H:i:s", time() + $allowcache_expire) . " GMT";
$mod_gmt           = gmdate("D, d M Y H:i:s", getlastmod()) . " GMT";
header('Expires: '       . $exp_gmt);
header('Last-Modified: ' . $mod_gmt);
header('Cache-Control: public');
header('Cache-Control: max-age=' . $allowcache_expire);
header('Content-Type: application/javascript');

readfile(__DIR__. '/aajslib-legacy.min.js');

