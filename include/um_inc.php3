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
   echo '<body OnLoad="InitPage()" background="'. COLOR_BACKGROUND .'">';
  else
   echo '<body background="'. COLOR_BACKGROUND .'">';

$usermng_page = true;
require $GLOBALS[AA_INC_PATH] . "navbar.php3";

?>
<!-- left navigate column    -->
<table width="122" bgcolor="<?php echo COLOR_TABBG ?>" border="0" cellspacing="0" cellpadding="1" align="LEFT">
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
 <tr><td width="122" valign="TOP">
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
