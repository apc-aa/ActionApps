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

if (!defined ("AA_ALERTS_UTIL_INCLUDED"))
     define  ("AA_ALERTS_UTIL_INCLUDED", 1);
else return;

require $GLOBALS["AA_INC_PATH"]."mail.php3";
require $GLOBALS["AA_INC_PATH"]."mgettext.php3";

if (!is_object ($db))   
    $db=new DB_AA;

function set_collectionid () {    
    global $collectionid, $collectionprop,
        $db, $new_module, $slice_id;
    
    if (!$new_module) {
        if (!$slice_id) { echo "Error: no slice ID"; exit; }
        $db->query ("SELECT * FROM alerts_collection WHERE moduleid='".q_pack_id($slice_id)."'");
        if ($db->next_record()) {
            $collectionid = $db->f("id");    
            $collectionprop = $db->Record;
        }
        else { echo "Can't find collection for $slice_id. Bailing out."; exit; }
    }
}

function get_howoften_options () {
    return array (
    "instant" => _m("instant"),
    "daily"=>_m("daily"),
    "weekly"=>_m("weekly"),
    "monthly"=>_m("monthly"));
}

function get_bin_names () {
    return array (
        1=>get_bin_name(1), 
        2=>get_bin_name(2), 
        3=>get_bin_name(3));
}

function get_bin_name ($status_code) {
    switch ($status_code) {
    case 1:
    case "app": return _m("Active bin"); 
    case "appb": return _m("pending");
    case "appc": return _m("expired");
    case 2: 
    case "hold": return _m("Holding bin");
    case 3:
    case "trash": return _m("Trash bin");
    }
}
 
function new_user_id () { 
    global $db;
    do { 
        $new_id = new_numeric_id (32767);
        $db->query ("SELECT id FROM alerts_user WHERE id = $new_id");        
    } while ($db->next_record());
    return $new_id;
}

function new_collection_id() {
    global $db;
    do { 
        $new_id = new_numeric_id (32767);
        $db->query ("SELECT id FROM alerts_collection WHERE id = $new_id");        
    } while ($db->next_record());
    return $new_id;
} 
    
function new_numeric_id ($max) {
    list($usec, $sec) = explode(' ', microtime());
    $seed = (float) $sec + ((float) $usec * 100000);
    srand($seed);
    return rand(1, $max);
}

function new_user_confirm ()
{ 
    global $db;
    do { 
        $retval = gensalt (4);
        $db->query ("SELECT confirm FROM alerts_user_collection WHERE confirm='".addslashes($retval)."'");         
    } while ($db->next_record());
    return $retval;
}

// ----------------------------------------------------------------------------------------

/**
* @param $info  should contain "uid" for $insert=false,
*               may contain "firstname","lastname","lang",
                "password"=md5-encrypted new password
* @param $insert if true, user is inserted, else updated
* @returns new user ID, if $insert=true, nothing otherwise
*/
function insert_or_update_user ($info, $insert) 
{        
    global $db, $slice_id;
    // insert new user
    $varset = new CVarset;
    if ($insert) {
        $userid = new_user_id();
        $varset->addkey ("id", "number", $userid);
    }
    else $varset->addkey ("id", "number", $info["uid"]);
    $userfields = array ("email","firstname","lastname","lang","password");
    reset ($userfields);
    while (list (,$field) = each ($userfields)) 
        if (isset ($info[$field]))        
            $varset->add ($field, "quoted", $info[$field]);
    $varset->add ("owner_module_id", "unpacked", $slice_id);
    $db->query ($varset->makeINSERTorUPDATE("alerts_user"));
    return $userid;
}

// ----------------------------------------------------------------------------------------
    
/** 
*   @param $info    array (field => value), it should contain fields
*                    "userid","allfilters","howoften","email" 
*   @param $confirmed   add the user confirmed? if no, the confirmation email is sent,
*                        if yes, no email is sent */    
function insert_or_update_user_collection ($info, $collection_record, $confirmed=false, $override=false) 
{  
    global $db;
    
    $varset = new CVarset();        
    $varset->addkey ("userid", "number", $info["userid"]);
    $varset->addkey ("collectionid", "number", $collection_record["id"]);
    $varset->add ("allfilters", "number", $info["allfilters"]);
    $varset->add ("howoften", "quoted", 
        $collection_record["fix_howoften"] ? $collection_record["fix_howoften"] : $info["howoften"]);
    
    $getdate = getdate ();
    $expiry = mktime (0, 0, 0, 
        $getdate["mon"] + $collection_record["expiry_months"], 
        $getdate["mday"], $getdate["year"]);       
    $varset->add ("start_date", "number", time());
    $varset->add ("expiry_date", "number", $expiry);
    
    if ($confirmed) {
        $varset->add ("status_code", "number", $collection_record ["confirmed_status_code"]);
    }
    else {
        $varset->add ("status_code", "number", $collection_record ["notconfirmed_status_code"]);
    }

    $db->query ($varset->makeSELECT ("alerts_user_collection")); 
    if ($db->next_record()) {
        if ($override) 
            return $db->query ($varset->makeUPDATE ("alerts_user_collection"));
        else return false;
    }
    else {
        if (!$confirmed) {
            $confirm = new_user_confirm($collection_record["id"], $info["userid"]);
            $varset->add ("confirm", "text", $confirm);

            $alias["_#HOWOFTEN"] = $info["howoften"];
            $confirmurl = AA_INSTAL_URL."ac.php3?id=$confirm";
            $alias["_#CONFIRM_"] = "<a href=\"$confirmurl\">$confirmurl</a>";
            if (!send_mail_from_table ($collection_record ["emailid_welcome"],
                $info["email"], $alias))
                echo "SOME ERROR WHEN SENDING MAIL TO $info[email].";                
        }        
        return $db->query ($varset->makeINSERT ("alerts_user_collection"));
    }
}

// ----------------------------------------------------------------------------------------

function email_address ($name, $email) {
    if ($name > " ") return "$name <$email>";
    else return $email;
}

// ----------------------------------------------------------------------------------------

function alerts_email_headers ($record, $default)
{
    $headers = array (
        "From" => "header_from",
        "Reply-To" => "reply_to",
        "Errors-To" => "errors_to",
        "Sender" => "sender");
    reset ($headers);
    while (list ($header, $field) = each ($headers)) {
        if ($record["$field"])
            $retval .= $header.": ".$record["$field"]."\r\n";
        else if ($default["$field"])
            $retval .= $header.": ".$default["$field"]."\r\n";
    }
    return $retval;
}

// -----------------------------------------------------------------------------------

function AlertsPageBegin() {
    // style sheet
    global $ss, $AA_INSTAL_PATH;
    $stylesheet = $ss ? $ss : $AA_INSTAL_PATH.ADMIN_CSS;

    echo 
    '<!DOCTYPE html public "-//W3C//DTD HTML 4.0 Transitional//EN">
       <HTML>
         <HEAD>
           <LINK rel=StyleSheet href="'.$stylesheet.'" type="text/css"  title="CPAdminCSS">
           <meta http-equiv="Content-Type" content="text/html; charset='.$LANGUAGE_CHARSETS[get_mgettext_lang()].'">';
}           

// -----------------------------------------------------------------------------------

/** Sends a single usage code allowing to access User Center without logging in. 
*   Useful for users who forgot their passwords. 
*/
function send_single_usage_code () {
    global $db, $auth, $Err, 
        // email address from the login page
        $email;
        
    if (!$email) {
        $Err[] = _m("Fill in your email.");
        return;
    }    
    
    $db->query("SELECT email.id FROM
        email INNER JOIN alerts_collection AC ON email.id = AC.emailid_access
        INNER JOIN alerts_user AU ON AU.owner_module_id = AC.moduleid
        WHERE AU.email = '$email'");
    if (!$db->next_record()) {
        $db->query ("SELECT id FROM email WHERE type='alerts access'");
        if (!$db->next_record()) {
            $Err[] = _m("Error: No appropriate email defined. Please contact the web administrator.");
            exit;
        }
    }
    $mailid = $db->f("id");
    $db->query("SELECT * FROM alerts_user WHERE email='$email'");
    if (!$db->next_record()) {
        $Err[] = _m("This email is not subscibed to any Alerts Collection.");
        return;
    }
    $uid = $db->f("id");
    if ($db->f("password") == "")
        $Err[] = _m("You don't use any password, you don't need any single usage access key.");
    else {
        $key = $db->f("single_usage_access_key");
        if (!$key) {
            do { 
                $key = gensalt (4);
                $db->query ("SELECT id FROM alerts_user 
                    WHERE single_usage_access_key='".addslashes($key)."'");         
            } while ($db->next_record());
            $db->query ("UPDATE alerts_user SET single_usage_access_key='".addslashes($key)."'
                WHERE id=$uid");
        }
        $aliases["_#ACCESURL"] = AA_INSTAL_URL."akey.php3?id=".$key;
        send_mail_from_table ($mailid, $email, $aliases);
        $GLOBALS["Msg"] = _m("The single usage access code was sent.");
    } 
}

?>