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

/* Alerts subscription page
   Global parameters:
       $email
       $password (may be empty when the user wishes)
       $lang - set language
*/
       
require "./lang.php3";

if ($email) {
    alerts_subscribe ($email, $password, $firstname, $lastname);
    go_url ("index.php3?Msg="._m("An email with subscription informations was sent to you."));
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
echo "<TITLE>". _m("Subscribe to AA Alerts") ."</TITLE>
</HEAD>
<BODY>
    <table width='440' border='0' cellspacing='0' cellpadding='10' bgcolor=".COLOR_TABTITBG." align='center'><TR><TD>
    
    <h1>"._m("Subscribe to AA Alerts")."</h1>";
    
    echo $Msg;
    PrintArray ($Err);

    echo "
    <FORM NAME=login ACTION='subscribe.php3' METHOD=post>
       <p align=left><b>"._m("We will send an e-mail message to the address given. Follow the instructions in it to complete your subscription.")."</b></p>
       <table width='440' border='0' cellspacing='0' cellpadding='10' bgcolor=".COLOR_TABTITBG." align='center'>
         <TR><TD class=tabtxt><B>"._m("E-mail").":</B></TD>
            <TD class=tabtxt><INPUT TYPE=text NAME=email VALUE='$email' SIZE=50></TD></TR>
         <TR><TD class=tabtxt><B>"._m("Password").":</B></TD>
            <TD class=tabtxt><INPUT TYPE=password NAME=password></TD></TR>
       </table> 
       <p align=center><INPUT TYPE=SUBMIT VALUE="._m("Submit")."></p>
    </FORM>";

echo "</TD></TR></TABLE>
</BODY>";
?>

