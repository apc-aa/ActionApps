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

/* Alerts user settings - shows login page
   Global parameters:
       $uid or $email
       $password (may be empty when the user wishes)
       $lang - set language
       $ss - set style sheet URL
       
       $show_email - email to be shown but not processed (used by confirm.php3)
*/

require "./lang.php3";
require $GLOBALS["AA_INC_PATH"]."formutil.php3";

global $db, $Msg, $Err, $email;

if (!is_object ($db)) $db = new DB_AA;

// ---------------------------------------------------------------------
// Process: New user? Subscribe to ...

global $go_subscribe_to, $subscribe_to;

if ($go_subscribe_to) {
    $db->query("SELECT * FROM alerts_collection AC
        INNER JOIN module ON AC.moduleid = module.id
        WHERE AC.id = ".$subscribe_to);
    if ($db->next_record())
        go_url ($db->f("slice_url"));
}

// End of New user?
// ---------------------------------------------------------------------

AlertsPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
echo "<TITLE>". _m("Login to Alerts sending") ."</TITLE>
</HEAD>
<BODY>
    <table width='440' border='0' cellspacing='0' cellpadding='10' bgcolor=".COLOR_TABBG." align='center'>
    <TR><TD class=tabtxt>
    
    <h1>"._m("Login to Personal settings for Alerts sending")."</h1>";
    
// ---------------------------------------------------------------------
// New user? Subscribe to ...

$db->query("SELECT AC.id, name, slice_url FROM alerts_collection AC
    INNER JOIN module ON AC.moduleid = module.id");
while ($db->next_record()) 
    $colls[$db->f("id")] = $db->f("name");
asort ($colls);
echo '<FORM name="form_subscribe_to" action="'.$this->url().'" method="post">';
echo '<b><font size="+1">New User?</font></b> Subscribe to: ';
FrmSelectEasy ("subscribe_to", $colls);
echo ' <INPUT TYPE="submit" NAME="go_subscribe_to" VALUE="'._m("Go").'">';
echo '</FORM>';

// End of New user?
// ---------------------------------------------------------------------
    
    echo $Msg;
    echo '<font color="red">';
    PrintArray ($Err);
    echo '</font>';
    
    if ($uid) {
        $db->query("SELECT email FROM alerts_user WHERE id=$uid"); 
        if ($db->next_record()) $email = $db->f("email");
    }
    
    echo "
    <FORM NAME=login ACTION='".$this->url()."' METHOD=post>
       <input type=hidden name=lang value=\"$lang\">
       <input type=hidden name=ss value=\"$ss\">
       <input type=hidden name=uid value=\"$uid\">";
//       <p align=right><a href='subscribe.php3?lang=$lang&ss=$ss'><font size=+1><b>"._m("New user? Subscribe!")."</b></font></a></p>
    echo"
    <table width='440' border='0' cellspacing='0' cellpadding='10' bgcolor=".COLOR_TABBG." align='center'>
         <TR><TD class=tabtxt><B>"._m("E-mail").":</B></TD>
            <TD class=tabtxt><INPUT TYPE=text NAME=email VALUE='$email' SIZE=50></TD></TR>
         <TR><TD class=tabtxt><B>"._m("Password").":</B></TD>
            <TD class=tabtxt><INPUT TYPE=password NAME=password></TD></TR>
       </table> 
       <p align=center><INPUT TYPE=SUBMIT VALUE='"._m("Login")."'></p>";
       echo _m("Login by your e-mail address. If you don't use password, leave that box empty. 
       If you forgot your password, fill the e-mail, click here and we will send you
       a single usage code allowing you to go directly to the Settings.")
        ." <p align=center><INPUT TYPE=SUBMIT NAME='send_single_usage_code' VALUE='"._m("Send single usage code")."'></p>
    </FORM>";

echo "</TD></TR></TABLE>
</BODY>";
?>

