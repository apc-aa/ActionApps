<?php
/**
 * Script for hierarchical constants
 *
 * params: 
 *  $varname - name of the select box with selected constants, defaults to "hiercons"
 *  $param - like in Field Input Type "hierarchical constants" but preceded with "group_name:"
 *          minimum is just "group_name"
 *  $lang_file - name of language file to be used, defaults to "en_news_lang.php3"
 *
 * @package UserOutput
 * @version $Id$
 * @author 
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

/** APC-AA constant definitions */
require "./include/constants.php3";
if (!$lang_file || !$LANGUAGE_FILES[$lang_file]) 
    $lang_file = "en_news_lang.php3";
require "./include/$lang_file";
/** APC-AA configuration file */
require "./include/config.php3";
/** Main include file for using session management function on a page */
require "./include/locsess.php3";
/** Set of useful functions used on most pages */
require "./include/util.php3";
require "./include/formutil.php3";
require "./include/itemfunc.php3";

echo "
<script language=JAVASCRIPT>
<!--
    var listboxes = new Array();
// -->
</script>";
if (!$varname) $varname = "hiercons";
if ($param) show_fnc_hco($varname, "", "", $param, 0);
?>

