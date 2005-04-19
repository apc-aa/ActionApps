<?php
/**
 * Function go_url used to move to another web page.
 * Formly this function was a part of util.php3 but in some pages
 * we don't want to include the whole util.
 *
 * @package Utils
 * @version $Id$
 * @author Jakub Adamek, Econnect
 * @copyright (c) 2002-3 Association for Progressive Communications
*/
/*
Copyright (C) 1999-2003 Association for Progressive Communications
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

/** Appends any number of QUERY_STRING (separated by & (&amp;)) parameters
*   to given URL, using apropriate ? or &. */
function con_url($url, $params) {
    list($path, $fragment) = explode( '#', $url, 2 );
    if (is_array($params)) {
        $params = implode('&amp;', $params);
    }
    return $path . (strstr($path, '?') ? "&amp;" : "?"). $params. ($fragment ? '#'.$fragment : '') ;
}

/// Move to another page (must be before any output from script)
function go_url($url, $add_param="", $usejs=false) {
    global $sess, $rXn;
    if (is_object($sess)) {
        page_close();
    }
    if ($add_param != "") {
        $url = con_url( $url, rawurlencode($add_param));
    }
    // special parameter for Netscape to reload page
    $url = con_url($url,($rXn=="") ? "rXn=1" : "rXn=".++$rXn);
    if ( $usejs OR headers_sent() ) {
       echo '
        <script language="JavaScript" type="text/javascript"> <!--
            document.location = "'.$url.'";
          //-->
        </script>
       ';
    } else {
        header("Status: 302 Moved Temporarily");
        header("Location: ". con_url($url,$netscape));
    }
    exit;
}
?>