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

 if( $useOnLoad )
   echo '<body OnLoad="InitPage()" background="../images/backgr.gif" link="#D20000" vlink="#D20000">';
  else
   echo '<body background="../images/backgr.gif" link="#D20000" vlink="#D20000">';

$usermng_page = true;
require $GLOBALS[AA_INC_PATH] . "navbar.php3";

/*
$Log$
Revision 1.2  2001/02/26 17:22:30  honzam
color profiles, itemmanager interface changes

Revision 1.1.1.1  2000/06/21 18:40:49  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:27  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.5  2000/06/12 19:58:37  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.4  2000/04/24 16:50:34  honzama
New usermanagement interface.

Revision 1.3  2000/03/29 15:54:47  honzama
Better Netscape Navigator javascript support, new direct feeding support, minor changes in texts and look.

Revision 1.2  2000/03/22 09:38:40  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc

*/
?>
<!-- left navigate column    -->
<table width="150" bgcolor="<?php echo COLOR_TABBG ?>" border="0" cellspacing="0" cellpadding="1" align="LEFT">
  <tr><td>&nbsp;</td></tr>
  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <tr><td class=leftmenu><?php echo L_USERS ?></td></tr>
  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <tr><td valign="TOP">
  <?php
  if( $show["u_edit"] ) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("um_uedit.php3") ."\" class=leftmenun>".L_EDIT_USER."</a></td>"; 
   else 
    echo "&nbsp;&nbsp;<span class=leftmenuy>". L_EDIT_USER ."</span></td>"; ?>
  </tr>
 <tr><td width="150" valign="TOP">
  <?php
  if( $show["u_new"] ) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("um_uedit.php3") ."&usr_new=1\" class=leftmenun>".L_NEW_USER."</a></td>"; 
   else 
    echo "&nbsp;&nbsp;<span class=leftmenuy>". L_NEW_USER ."</span></td>"; ?>
  </tr>
  <tr><td>&nbsp;</td></tr>
  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <tr><td class=leftmenu><?php echo L_GROUPS ?></td></tr>
  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <tr><td valign="TOP">
  <?php
  if( $show["g_edit"] ) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("um_gedit.php3") ."\" class=leftmenun>".L_EDIT_GROUP."</a></td>"; 
   else 
    echo "&nbsp;&nbsp;<span class=leftmenuy>". L_EDIT_GROUP ."</span></td>"; ?>
  </tr>
  <tr><td valign="TOP">
  <?php
  if( $show["g_new"] ) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("um_gedit.php3") ."&grp_new=1\" class=leftmenun>".L_NEW_GROUP."</a></td>"; 
   else 
    echo "&nbsp;&nbsp;<span class=leftmenuy>". L_NEW_GROUP ."</span></td>"; ?>
  </tr>
  <tr><td><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br></td></tr>
</table>
