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

/** Authenticate to the second domain
 *
 * This is the blank pixel script, which can be used for authentication
 * to another domain if you are already logged in one - just include folowing
 * HTML code to sitemodule of example-one.org:
 *   <img src="//www.example-two.org/apc-aa/central/setcookie.php?s={cookie:AA_Sess}">
 * This will set the auth cookie in example-two.org.
 */
setcookie("AA_Sess", $_GET['s'], 0, '/');
header('Content-Type: image/gif');
//pixel blank
echo "\x47\x49\x46\x38\x37\x61\x1\x0\x1\x0\x80\x0\x0\xfc\x6a\x6c\x0\x0\x0\x2c\x0\x0\x0\x0\x1\x0\x1\x0\x0\x2\x2\x44\x1\x0\x3b";

