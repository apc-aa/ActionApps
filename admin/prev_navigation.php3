<?php
/**
 *
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

require_once "../include/init_page.php3";

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
</head>
<body>
<center>
<?php
// we do not want to allow users to go back to edit, when it is opened in popup
// (test for openervar (see itemedit.php3) would do the same)
if ( $return_url != 'close_dialog' ) {
    echo '<a href="'. con_url($sess->url("itemedit.php3"),"encap=false&edit=1&id=$sh_itm") .'" target="_parent" class="ipreview">'. _m("Edit") .'</a>';
}
echo '<img src="../images/spacer.gif" width=50 height=1>';
if ( $return_url == 'close_dialog' ) {
    $go2url = 'javascript:window.close()';
} elseif ( $return_url ) {
    $go2url = $return_url;
} else {
    $go2url = $sess->url("index.php3");
}
echo '<a href="'. $go2url .'" target="_parent" class="ipreview">'. _m("OK") .'</a>';
?>
</center>
</body>
</html>
