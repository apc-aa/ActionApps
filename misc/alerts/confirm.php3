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

/* Shows the confirmation web page and confirms user in Alerts. 
   Global parameters:
       $id - ID to look for in database (required)
       $lang - set language 
       $ss - set style sheet URL
*/

require "./lang.php3";

$db = new DB_AA;

if ($id) {
    $db->query ("SELECT confirm,email FROM alerts_user WHERE confirm='$id'");
    $db->next_record();
    if ($db->num_rows()) { 
        $db->query ("UPDATE alerts_user SET confirm = '' WHERE confirm='$id'");
        $msg = _m("Congratulations. Your subscription is finished.");        
        go_url ("index.php3?Msg=$msg&lang=$lang&show_email=".$db->f("email"));
    }   
    else {
        $msg = _m("Your code is not valid any more. Please subscribe again.");
        go_url ("subscribe.php3?Msg=$msg&lang=$lang");
    }
}
   
AlertsPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
echo "<TITLE>". _m("Subscribe to E-mail Alerts") ."</TITLE>
</HEAD>
<BODY>
    <table width='440' border='0' cellspacing='0' cellpadding='10' bgcolor=".COLOR_TABBG." align='center'>
    <TR><TD class=tabtxt>
    <h1>"._m("Finish your subscription to AA Alerts")."</h1>";

if ($id) 
    echo _m("The code given is not OK. Please try it again. Don't use any spaces.");
echo "
<FORM NAME=confirm ACTION='confirm.php3?ss=$ss' METHOD=post>
     <B>Code:</B> <INPUT TYPE=text NAME=confirm>
     <INPUT TYPE=SUBMIT VALUE="._m("Submit").">
</FORM>";

echo "</TD></TR></TABLE>
</BODY>";
?>