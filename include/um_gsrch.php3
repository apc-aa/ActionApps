<?php  #um_gsrch.php3  - include file with user search form
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

  $groups = FindGroups($grp);
  if( !is_array($groups) ) {
    if( $groups == "too much" ) {
      unset($groups);
      $groups[0] = L_TOO_MUCH_USERS;
    } else {
      unset($groups);
      $groups[0] = L_NO_USERS;
    }  
  }    
  
/*
$Log$
Revision 1.2  2001/02/26 17:22:30  honzam
color profiles, itemmanager interface changes

Revision 1.1.1.1  2000/06/21 18:40:48  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:27  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.5  2000/06/12 19:58:37  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.4  2000/04/24 16:50:34  honzama
New usermanagement interface.

Revision 1.3  2000/03/29 15:54:47  honzama
Better Netscape Navigator javascript support, new direct feeding support, minor changes in texts and look.

Revision 1.2  2000/03/22 09:38:39  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc

*/
?>
<form enctype="multipart/form-data" method=post action="<?php echo $sess->url($PHP_SELF) ?>">
 <table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
  <tr><td class=tabtit><b>&nbsp;<?php echo L_GROUPS?></b></td></tr>
  <tr><td>
    <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
     <tr>
    	<td>&nbsp;</td>
    	<td><input type=Text name=grp value="<?php echo $grp?>"></td>
    	<td><input type=submit name="GrpSrch" value="<?php echo L_SEARCH?>"></td>
     </tr>
     <tr>
    	<td class=tabtxt><b><?php echo L_GROUP ?></b></td>
    	<td><?php SelectGU_ID("selected_group", $groups, $selected_group) ?></td>
    	<td><input type=submit name="grp_edit" value="<?php echo L_EDIT?>">&nbsp;
          <input type=submit name="grp_del" value="<?php echo L_DELETE?>">
          <input type=hidden name="usr" value="<?php echo $usr?>"></td>
     </tr>
    </table>
   </td>
  </tr>
 </table>
</FORM>

