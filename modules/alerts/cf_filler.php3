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

/* Fills info from collection form into database.

   Parameters: $alerts .. array of info 
               $alerts[userid] 
               $alerts[collectionid]
               $alerts[password]
               etc.
*/

require "../../include/config.php3";
require $GLOBALS[AA_INC_PATH]."util.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."locsess.php3"; 
require "cf_common.php3";

if (!$alerts["run_filler"]) return;

$db = new DB_AA;

$userid = $alerts["userid"];
$collid = $alerts["collectionid"];

if ($userid) {
    // is the user ID valid?
    $db->query ("SELECT * FROM alerts_user WHERE id=$userid");
    if (!$db->next_record()) { my_die(_m("Wrong user ID")); return; }
}

else {
    // does such email exist in database?
    $db->query ("SELECT * FROM alerts_user WHERE email='$alerts[email]'");
    if ($db->next_record())
        $userid = $db->f("id");
    // no email nor user ID given => nothing will be changed in database
    else if (!$alerts["email"]) return;
    else if (!ValidateInput ("email", "email", $alerts["email"], $err, true, "email")) 
        { my_die($err); return; }
}

if ($userid)
    if ($db->f("password") && $db->f("password") != $alerts["password"]) 
        { my_die (_m("Wrong password.")); return; }

// change password        
$user_varset = new CVarset();
if ($alerts["chpwd"]) {
    if ($alerts["chpwd"] != $alerts["chpwd2"]) my_die ("Passwords differ.");
    else $user_varset->add ("password", "text", $alerts["chpwd"]);
}
    
reset ($cf_fields);
while (list ($fname, $fprop) = each ($cf_fields)) 
    if ($fprop["userinfo"] && isset ($alerts[$fname]))
        $user_varset->add ($fname, "quoted", $alerts[$fname]);

if ($alerts["userid"])
     $db->query ("UPDATE alerts_user SET ".$user_varset->makeUPDATE()." WHERE id=$userid");
else {
    $db->query ("INSERT INTO alerts_user ".$user_varset->makeINSERT());
    $userid = get_last_insert_id ($db, "alerts_user");
}

// change collection settings
if ($alerts["collectionid"]) {
    $db->query ("DELETE FROM alerts_user_collection_filter
                 WHERE userid=$userid AND collectionid=$collid");

    // filters were chosen by checkboxes
    switch ($alerts["choose_filters"]) {
    case "checkbox":
        $db->query ("SELECT * FROM alerts_collection_filter WHERE collectionid=$collid");
        if (!is_array ($alerts["filters"]))
            break;
        $allfilters = true;
        while ($db->next_record()) {
            if ($alerts["filters"][$db->f("filterid")])
                // add 100 to exclude filters not part of this collection
                $alerts["filters"][$db->f("filterid")] = 100 + $db->f("myindex");
            else $allfilters = false;
        }
        if (!$allfilters) {
            reset ($alerts["filters"]);    
            while (list ($filterid, $myindex) = each ($alerts["filters"])) 
                if ($myindex >= 100)
                    $db->query ("INSERT INTO alerts_user_collection_filter
                        (userid, collectionid, filterid, myindex)
                        VALUES ($userid, $collid, $filterid, $myindex)");
        }
        break;
    case "no":
        $allfilters = true;
        break;
    default: 
        my_die ("Wrong value of alerts[choose_filters]");
        $allfilters = true;
        break;
    }
    
    // was a valid howoften sent?
    if (get_howoften_options ($alerts["howoften"])) {
        $uc_varset = new CVarset();
        $uc_varset->add ("howoften", "quoted", $alerts["howoften"]);
        $uc_varset->add ("allfilters", "number", $allfilters);
        $uc_varset->addkey ("userid", "number", $userid);
        $uc_varset->addkey ("collectionid", "number", $collid);
        
        $db->query ($uc_varset->makeINSERTorUPDATE("alerts_user_collection"));
    }    
}        

function my_die ($err) {
   echo "<p class=\"cf_warning\">$err</p>";
}

?>
