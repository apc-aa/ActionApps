<?php  #se_users.php3 - user management page
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

# expected $slice_id for edit slice
# optionaly $Msg to show under <h1>Hedline</h1> (typicaly: Category update succesfull)

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";

if( $usr_edit AND $selected_user AND ($selected_user>0) )
  go_url(	$sess->url(self_base() . "um_uedit.php3"));
elseif( $grp_edit AND $selected_group AND ($selected_group>0) )
  go_url(	$sess->url(self_base() . "um_gedit.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_NEW_USER)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_USERS);
  exit;
}  

# Prints html tag <select .. 
function SelectGU_ID($name, $arr, $selected="") { 
  echo "<select name=\"$name\">";	
  reset($arr);
  while(list($k, $v) = each($arr)) { 
    echo "<option value=\"". htmlspecialchars($k)."\"";
    if ((string)$selected == (string)$k) 
      echo " selected";
    echo "> ". htmlspecialchars($v[name]) ." </option>";
  }
  echo "</select>\n";
}  

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_USERS_TIT;?></TITLE>
</HEAD>
<?php
  $xx = ($slice_id!="");
  $show = Array("u_new"=>$xx, "u_edit"=>$xx, "g_new"=>$xx, "g_edit"=>$xx);
  require $GLOBALS[AA_INC_PATH]."um_inc.php3";   //show navigation column depending on $show variable

  echo "<H1><B>".L_USER_MANAGEMENT."</B></H1>";
  echo $Msg;

  include $GLOBALS[AA_INC_PATH]."um_usrch.php3";
  include $GLOBALS[AA_INC_PATH]."um_usrch.php3";
  
/*
$Log$
Revision 1.1  2000/06/21 18:40:08  madebeer
Initial revision

Revision 1.1.1.1  2000/06/12 21:49:58  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.4  2000/06/12 19:58:25  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.3  2000/03/22 09:36:44  madebeer
also added Id and Log keywords to all .php3 and .inc files
*.php3 makes use of new variables in config.inc

*/
?>
</BODY>
</HTML>
<?php page_close()?>


