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
       
       $show_email - email to be shown but not processed (used by confirm.php3)
*/

require "./lang.php3";

if ($send_passwd && $email) {
    $db = new DB_AA;
    $db->query ("SELECT password FROM alerts_user WHERE email='$email'");
    if ($db->num_rows() == 0) $Err[] = _m("This email is not registered with Alerts.");
    else {
        $db->next_record();
        $passwd = $db->f("password");
        if (!$passwd) $passwd = _m("You don't have any password, leave the box empty.");
        
        mail ($email, _m("Password to Alerts"), 
            _m("Your login info for AA Alerts is (%1):\n
                e-mail: %2\n
                password: %3\n
                Enjoy AA Alerts!", AA_INSTAL_URL."misc/alerts", $email, $passwd),
            $headers = "From: ".ALERTS_EMAIL);
        $Msg = _m("OK. Password was sent.");
    }
}

else if ($email) {
    $db = new DB_AA;
    $db->query ("SELECT id, password FROM alerts_user WHERE email='$email'");
    if ($db->num_rows() == 0) $Err[] = _m("This email address is not registered with Alerts.");
    else {
        $db->next_record();
        if ($password == $db->f("password")) {
            $alerts_session = new_id ();
            $db->query ("UPDATE alerts_user 
                SET session='$alerts_session', sessiontime=".time()." 
                WHERE id = ".$db->f("id"));
            go_url (AA_INSTAL_URL."misc/alerts/user_filter.php3?alerts_session=$alerts_session");
            exit;
        }
        else $Err[] = _m("Wrong password.");
    }
}
      
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
echo "<TITLE>". _m("Login to Alerts sending") ."</TITLE>
</HEAD>
<BODY>
    <table width='440' border='0' cellspacing='0' cellpadding='10' bgcolor=".COLOR_TABTITBG." align='center'><TR><TD>
    
    <h1>"._m("Login to Personal settings for Alerts sending")."</h1>";
    
    echo $Msg;
    PrintArray ($Err);
    
    if ($show_email && !$email) $email = $show_email;

    echo "
    <FORM NAME=login ACTION='index.php3' METHOD=post>
       <p align=right><a href='subscribe.php3?lang=$lang'><font size=+1><b>"._m("New user? Subscribe!")."</b></font></a></p>
       <table width='440' border='0' cellspacing='0' cellpadding='10' bgcolor=".COLOR_TABTITBG." align='center'>
         <TR><TD class=tabtxt><B>E-mail:</B></TD>
            <TD class=tabtxt><INPUT TYPE=text NAME=email VALUE='$email' SIZE=50></TD></TR>
         <TR><TD class=tabtxt><B>Password:</B></TD>
            <TD class=tabtxt><INPUT TYPE=password NAME=password></TD></TR>
       </table> 
       <p align=center><INPUT TYPE=SUBMIT VALUE='"._m("Login")."'></p>";
       echo _m("Login by your e-mail address. If you don't use password, leave that box empty. If you have forgotten your password, click here and we will send it to your e-mail.")." <p align=center><INPUT TYPE=SUBMIT NAME='send_passwd' VALUE='"._m("Send password")."'></p>
    </FORM>";

echo "</TD></TR></TABLE>
</BODY>";
?>

