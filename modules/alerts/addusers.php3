<?php
/**
 * "Add users" page from Alerts menu
 * 
 * @package Alerts
 * @version $Id$
 * @author Jakub Adámek <jakubadamek@ecn.cz>, Econnect, December 2002
 * @copyright Copyright (C) 1999-2002 Association for Progressive Communications 
*/
/* 
Copyright (C) 1999-2002 Association for Progressive Communications 
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

$directory_depth = "../";
require "../../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $MODULES[$g_modules[$slice_id]['type']]['menu'];   

$db->query ("SELECT * FROM alerts_collection WHERE id=$collectionid");
$db->next_record();
$collection_record = $db->Record;

if ($add["go"]) 
    process_form_data();

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
echo "<TITLE>". _m("Collection Form Wizard") ."</TITLE>
</HEAD>";

if (!IfSlPerm (PS_USERS)) { echo "No permissions."; exit; }

showMenu ($aamenus, "addusers", "");

echo "
<FORM name=addusers METHOD=post ACTION=\"".$sess->url("addusers.php3")."\">
<table width='540' border='0' cellspacing='0' cellpadding='10' bgcolor=".COLOR_TABBG." align='center'>
<TR><TD class=tabtit><h1>"._m("Add Users")." ".$db->f("description")."</h1></TD></TR>
<TR><TD class=tabtxt>";
if ($Msg) echo "<b>$Msg</b><br>";
if ($err) {
    echo _m("Some errors occured: ")."<br><b><i>";
    PrintArray ($err);
    echo "</i></b><br>";
}

echo _m("Enter email addresses one on a row, you may add first and last name separated by whitespace (spaces, tabs), e.g.")
    ."<br>
    john.somebody@org.ca John Somebody<br><br>
    <TEXTAREA name=\"add[emails]\" cols=60 rows=15></TEXTAREA>
    <br>\n
    <INPUT type=\"checkbox\" name=\"add[proove_emails]\" checked>\n"
    ._m("Proove the email addresses format is correct.");

echo "<BR><BR><TABLE border='0'>";

if (!$collection_record ["fix_howoften"]) 
    FrmInputRadio("add[howoften]", _m("How often"), get_howoften_options(), "daily", false, "", "", true);

FrmInputRadio("add[confirm]", _m("Confirmation"), array (
    "notconfirmed" => _m("Send a confirmation email to users (recommended)."),
    "confirmed" => _m("Subscribe users immediately (use carefully).")),
    "notconfirmed", false, "", "", true);    
echo "</TABLE>";    
echo _m("Set the bin, into which the users will be added, on Alerts Admin.");
//echo "<BR><BR>\n<INPUT type=\"checkbox\" name=\"add[override]\">\n".
//    _m("Override settings for users already subscribed.");
echo "\n</TD></TR><TR><TD class=tabtit align=center>
    <INPUT type=submit name=\"add[go]\" value=\""._m("Go")."\">";
echo "</TD></TR></TABLE></FORM>";
HTMLPageEnd();
page_close();

// -------------------------------------------------------------------------------------

function process_form_data ()
{
    global $collection_record, $add, $err, $Msg;

    $okemail = "/^.+\\@(\\[?)[a-zA-Z0-9\\-\\.]+\\.([a-zA-Z]{2,3}|[0-9]{1,3})(\\]?)\$/";
    $koemail = "/^(@.*@)|(\\.\\.)|(@\\.)|(\\.@)|(^\\.)\$/";

    if ($collection_record ["fix_howoften"]) 
        $add["howoften"] = $collection_record ["fix_howoften"];
    $rows = split ("\n", $add["emails"]);
    reset ($rows);
    while (list (,$row) = each ($rows)) {
        $word = "([^ \\t\\n]+)";    
        $space = "[ \\t]+";
        $aspace = "[ \\t]*";
        $row = str_replace ("\r","",$row);
        if (!preg_match ("/^".$word.$aspace."\$/", $row, $fields))
        if (!preg_match ("/^".$word.$space.$word.$aspace."\$/", $row, $fields))
        if (!preg_match ("/^".$word.$space.$word.$space.$word.$aspace."\$/", $row, $fields)) {
            $err[] = "Wrong row: ".$row;
            continue;
        }
        if ($add["proove_emails"] 
            && (!preg_match ($okemail, $fields[1]) 
                || preg_match ($koemail, $fields[1]))) {
            $err[] = "Wrong email: ".$row;
            continue;
        }
                
        add_email ($fields[1], $fields[2], $fields[3]);
    }
    $Msg = _m ("%1 new users were created and %2 users were subscribed (including the new ones).",
        array ($GLOBALS["new_user_count"]+0, $GLOBALS["subscribed_user_count"]+0));
}


// the parameters are coming from a form and thus escaped
function add_email ($email, $firstname, $lastname) 
{
    global $db, $err, $add, $collection_record, $new_user_count, $subscribed_user_count;

    $db->query ("SELECT id, firstname, lastname FROM alerts_user WHERE email='$email'");
    if ($db->next_record()) {
        $userid = $db->f("id");
        if (($firstname || $lastname)
            && ($db->f("firstname") != $firstname || $db->f("lastname") != $lastname))
            $err[] = email_address ("$firstname $lastname", $email)." "._m("is already in the database with another name: ").$db->f("firstname")." ".$db->f("lastname");
    }
    else {
        $userid = add_user (array (
            "email" => $email,
            "firstname" => $firstname,
            "lastname" => $lastname,
            "lang" => get_mgettext_lang()));
        $new_user_count ++;
    }

    $ok = insert_or_update_user_collection (array (
        "email" => $email,
        "userid" => $userid,
        "allfilters" => 1,
        "howoften" => $add["howoften"]),
        $collection_record,
        $add["confirm"] == "confirmed",
        $add["override"]);
        
    if ($ok)
        $subscribed_user_count ++;
    else $err[] = "$email $firstname $lastname "
            ._m("is already subscribed to this collection.<br>");
}            
        

?>

