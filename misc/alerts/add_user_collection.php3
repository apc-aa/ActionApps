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

/* Subscribes user to collection. If she is already subscribed to it, updates howoften.

   Required parameters:
       $email
       $howoften (daily / weekly / monthly)
       $collectionid (one number or array)
       $lang - set language
   Optional parameters:
       $ss - URL of style sheet
       $ok_url
       $err_url 
*/
       
require "./lang.php3";

function error ($err)
{
    if ($err_url) {
        go_url ($err_url);
        exit;
    }
    else {
        HtmlPageBegin();   
        echo "</HEAD><BODY>";
        echo "<h1>"._m("Error - user was not subscribed")."</h1>";
        echo "Reason: $err<br>";
        echo "</BODY></HMTL>";
        exit;
    }
}
        
if (!$email || !$howoften || !$collectionid || !$lang) error (_m("Missing required info"));
    
$db = new DB_AA;
$db->query("SELECT * FROM alerts_user WHERE email='".addslashes($email)."'");
if (!$db->num_rows()) {
    $err = alerts_subscribe ($email, $lang, $password, $firstname, $lastname);
    if ($err) error ($err);
    $db->query("SELECT * FROM alerts_user WHERE email='".addslashes($email)."'");
}
$db->next_record();
$userid = $db->f("id");
$confirm = $db->f("confirm");

$howoften_options = get_howoften_options();
if (!$howoften_options [$howoften]) error (_m("Howoften set wrong"));

if (!is_array ($collectionid))
    $collectionid = array ($collectionid);
 
reset ($collectionid);
while (list (,$cid) = each ($collectionid)) {
    $db->query("SELECT id, description FROM alerts_collection WHERE id = $cid");
    if (!$db->num_rows()) error (_m("Wrong collection ID")." $cid");
    $db->next_record();
    $cdesc[] = $db->f ("description");
    
    $db->query("SELECT howoften FROM alerts_user_filter 
                 WHERE userid=$userid AND collectionid=$cid");
    if ($db->next_record()) {
        if ($db->f("howoften") != $howoften)
            $db->query("UPDATE alerts_user_filter SET howoften = '$howoften'
                         WHERE userid=$userid AND collectionid=$cid");
    }
    else $db->query("
        INSERT INTO alerts_user_filter (userid, howoften, collectionid)
        VALUES ($userid, '$howoften', $cid)");
}
                 
if ($ok_url) {
    go_url ($ok_url);             
    exit;
}
             
AlertsPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
echo "
</HEAD>
<BODY>
    <table width='440' border='0' cellspacing='0' cellpadding='10' bgcolor=".COLOR_TABBG." align='center'>
    <TR><TD class=tabtxt>
    <b>"._m("You have been successfully subscribed to AA Alerts for %1.",
        array (join (", ",$cdesc)))."</b><br><br>";
        
$url = AA_INSTAL_URL."misc/alerts?lang=$lang&show_email=$email";
$url = "<a href=\"$url\">$url</a>";

if ($confirm)
    echo _m("You must first confirm your subscription (see confirmation e-mail) "
        ."or subscribe again on %1 in order to receive e-mail Alerts.",
        array ($url));
else
    echo _m("You can change your subscriptions on %1.", array ($url));      

echo "</TD></TR></TABLE>
</BODY>";
?>

