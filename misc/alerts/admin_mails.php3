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

/*  Alerts admin emails sending
       $ss = stylesheet
       
    Uses 
       
       $mail_confirm = number of days from unfinished subscription after which a mail 
                       with demand to subscribe again is send
       $delete_not_confirmed = number of days after which unfinished subscriptions
                               are deleted
*/


require_once "./lang.php3";
require_once $GLOBALS["AA_INC_PATH"]."item.php3";
require_once $GLOBALS["AA_INC_PATH"]."view.php3";
require_once $GLOBALS["AA_INC_PATH"]."pagecache.php3";
require_once $GLOBALS["AA_INC_PATH"]."searchlib.php3";

//$debug = 1;

$db = new DB_AA;
$db->query("select * from alerts_admin");
if ($db->num_rows() != 1)
{  echo "There must be exactly 1 row in alerts_admin."; exit; }
$db->next_record();
$alerts_admin = $db->Record;

echo "Actions taken:<br>";
if ($alerts_admin["delete_not_confirmed"])
    delete_not_confirmed();
if ($alerts_admin["mail_confirm"])   
    send_mail_confirm ();

function send_mail_confirm () {
    global $db, $ss, $alerts_admin;
    $db->query("select * from alerts_collection where description='$GLOBALS[ALERTS_SUBSCRIPTION_COLLECTION]'");
    $db->next_record();   
    $headers = alerts_email_headers ($db->Record, "");
    
    $wait_seconds = $alerts_admin["mail_confirm"]*24*60*60;
    $now = time () - $wait_seconds;

    $db->tquery ("
        SELECT * FROM alerts_user 
        WHERE confirm <> ''
        AND ((sessiontime BETWEEN ".($alerts_admin["last_mail_confirm"]+1)." AND $now)"
            ." OR sessiontime = 0)");
    echo ($db->num_rows() + 0)." mails sent<br>";
    while ($db->next_record()) {
        bind_mgettext_domain ($GLOBALS["AA_INC_PATH"]."lang/".$db->f("lang")."_alerts_lang.php3");
        $to  = email_address ($db->f("firstname")." ".$db->f("lastname"), $db->f("email"));    
        $subject = _m ("Please confirm your subscription");
        $url = AA_INSTAL_URL."misc/alerts/confirm.php3?id=".$db->f("confirm")."&lang=".$db->f("lang")."&ss=$ss";
        $message = _m("<p>Hello,</p>"
            ."<p>you didn't yet confirm your subscription to AA Alerts. You can't receive any emails until you do so. 
                Please click on URL:</p>"
            ."%1"
            ."<p>or copy the URL to your web browser. 
             The confirmation is done in order that we can see you did not subscribe by mistake and your e-mail is working.</p>"
            ."<p>Yours<br>"
            ."&nbsp; &nbsp; &nbsp;APC Alerts moderators</p>"
            , array ("<p align=center><a href='$url'>$url</a></p>"));
        
        global $LANGUAGE_CHARSETS;
        mail_html_text ($to, $subject, $message, $headers, $LANGUAGE_CHARSETS[get_mgettext_lang()], 0); 
    }
    $db->query("update alerts_admin set last_mail_confirm=$now where id=$alerts_admin[id]");    
}        

function delete_not_confirmed () {
    global $db, $alerts_admin;
    $now = time() - $alerts_admin["delete_not_confirmed"]*24*60*60;
    $db->tquery ("UPDATE alerts_user SET sessiontime=".time()." WHERE sessiontime=0 AND confirm<>''");
    $db->tquery ("
        SELECT * FROM alerts_user
        WHERE confirm <> ''
        AND sessiontime <= $now");
    echo ($db->num_rows()+0)." not confirmed users deleted<br>";
    $db->tquery ("
        DELETE FROM alerts_user
        WHERE confirm <> ''
        AND sessiontime <= $now");
    $db->query("update alerts_admin set last_delete=$now where id=$alerts_admin[id]");    
}
