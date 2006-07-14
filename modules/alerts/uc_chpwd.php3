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

// (c) Econnect, Jakub Adamek, December 2002

require "uc_menu.php3";
require $GLOBALS[AA_INC_PATH]."constants.php3";
require $GLOBALS[AA_INC_PATH]."tabledit.php3";
require $GLOBALS[AA_INC_PATH]."tv_common.php3";
require $GLOBALS[AA_INC_PATH]."util.php3";
require "uc_tableviews.php3";

if ($go_change_password) {
    if ($change_password != $retype_password)
        $Err[] = _m("The two given passwords differ.");
    else {
        if ($change_password)
            $update = "'".md5($change_password)."'";
        else $update = "NULL";
        $db->query ("UPDATE alerts_user SET password=".$update
                ." WHERE id=".$auth->auth["uid"]);
        go_url ($sess->url($AA_INSTAL_PATH."modules/alerts/uc_tabledit.php3?Msg="
            ._m("Password changed successfully.")));
    }
}

showMenu ("password");

echo '<FORM name="chpwd" action="'.$sess->url("uc_chpwd.php3")
    .'" method="post" onsubmit="return validate();">';
echo '<TABLE class="tabtxt" cellpadding="5">';
echo "<TR><TD class=tabtxt><B>"._m("New password").'</B></TD>
    <TD><INPUT TYPE=password NAME="change_password"></TD></TR>';
echo "<TR><TD class=tabtxt><B>"._m("Retype new password").'</B></TD>
    <TD><INPUT TYPE=password NAME="retype_password"></TD></TR>';
echo '<TR><TD colspan="2" align="center"><INPUT TYPE="submit" NAME="go_change_password" VALUE="'._m("Go").'"></TD></TR>';
echo '</TABLE></FORM>';

echo "    
<SCRIPT language=\"JavaScript\">
<!--
  function validate() {
    var myform = document.chpwd;
    if (myform['change_password'] != null 
      && myform['change_password'].value != myform['retype_password'].value) {
      alert ('"._m("The two given passwords differ.")."');
      return false;
    }
    return true;
   }
// -->
</SCRIPT>";

EndMenuPage();
?>
