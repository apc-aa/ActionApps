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
       $email
       $password (may be empty when the user wishes)
       $lang - set language
       $ss - set style sheet URL
       
       $show_email - email to be shown but not processed (used by confirm.php3)
*/

require "./lang.php3";

if ($send_passwd && $email) {
    $db = new DB_AA;
    $db->query ("SELECT * FROM alerts_user WHERE email='$email'");
    if ($db->num_rows() == 0) $Err[] = _m("This email is not registered with Alerts.");
    else {
        $db->next_record();
        alerts_subscribe ($email, $lang, "");
        $Msg = _m("OK. Confirmation email was sent.");
    }
}

else if ($email) {
    $db = new DB_AA;
    $db->query ("SELECT id, password, confirm FROM alerts_user WHERE email='$email'");
    if ($db->num_rows() == 0) $Err[] = _m("This email is not registered with Alerts.");
    else {
        $db->next_record();
        if ($db->f("confirm")) $Err[] = _m("You have not yet confirmed your subscription. See confirmation e-mail or use Send confirmation.");
        else if ($db->f("password") == "" || (md5($password) == $db->f("password"))) {
            $alerts_session = new_id ();
            $db->query ("UPDATE alerts_user 
                SET session='$alerts_session', sessiontime=".time()." 
                WHERE id = ".$db->f("id"));
            go_url (AA_INSTAL_URL."misc/alerts/user_filter.php3?alerts_session=$alerts_session&lang=$lang&ss=$ss");
            exit;
        }
        else $Err[] = _m("Wrong password.");
    }
}
      
AlertsPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
echo "<TITLE>". _m("Login to Alerts sending") ."</TITLE>
</HEAD>
<BODY>
    <table width='440' border='0' cellspacing='0' cellpadding='10' bgcolor=".COLOR_TABBG." align='center'>
    <TR><TD class=tabtxt>
    
    <h1>"._m("Login to Personal settings for Alerts sending")."</h1>";
    
    echo $Msg;
    PrintArray ($Err);
    
    if ($show_email && !$email) $email = $show_email;

    echo "
    <FORM NAME=login ACTION='index.php3' METHOD=post>
       <input type=hidden name=lang value=\"$lang\">
       <input type=hidden name=ss value=\"$ss\">
       <p align=right><a href='subscribe.php3?lang=$lang&ss=$ss'><font size=+1><b>"._m("New user? Subscribe!")."</b></font></a></p>
       <table width='440' border='0' cellspacing='0' cellpadding='10' bgcolor=".COLOR_TABBG." align='center'>
         <TR><TD class=tabtxt><B>"._m("E-mail").":</B></TD>
            <TD class=tabtxt><INPUT TYPE=text NAME=email VALUE='$email' SIZE=50></TD></TR>
         <TR><TD class=tabtxt><B>"._m("Password").":</B></TD>
            <TD class=tabtxt><INPUT TYPE=password NAME=password></TD></TR>
       </table> 
       <p align=center><INPUT TYPE=SUBMIT VALUE='"._m("Login")."'></p>";
       echo _m("Login by your e-mail address. If you don't use password, leave that box empty. If you forgot your password, click here and we will send a new confirmation e-mail to you.")
        ." <p align=center><INPUT TYPE=SUBMIT NAME='send_passwd' VALUE='"._m("Send confirmation")."'></p>
    </FORM>";

echo "</TD></TR></TABLE>
</BODY>";
?>

