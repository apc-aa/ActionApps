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

require "../include/init_page.php3";
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
</head>
<body>
<center>
<?php
echo '<a href="'. con_url($sess->url("itemedit.php3"),"encap=false&edit=1&id=$sh_itm") .'" target="_parent" class="ipreview">'. L_EDIT .'</a>';
echo '<IMG src="../images/spacer.gif" width=50 height=1>';
echo '<a href="'. $sess->url("index.php3") .'" target="_parent" class="ipreview">'. L_OK .'</a>';
/*
$Log$
Revision 1.1  2000/06/21 18:39:57  madebeer
Initial revision

Revision 1.1.1.1  2000/06/12 21:49:46  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.2  2000/06/12 21:40:57  madebeer
added $Id $Log and $Copyright to some stray files

*/
?>
</center>
</body>
</html>
