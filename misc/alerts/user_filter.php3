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
       $ss - URL for stylesheet, default: standard AA control panel stylesheet
*/

require "./lang.php3";
require $GLOBALS[AA_INC_PATH]."/formutil.php3";

$show_filters = false;
      
$db = new DB_AA;

$user = AlertsUser ($alerts_session);
if ($signout) go_url (AA_INSTAL_URL."misc/alerts?show_email=$email&lang=$lang&ss=$ss");
if (!$user) go_url (AA_INSTAL_URL."misc/alerts?show_email=$email&Msg="._m("Your session has expired. Please login again."));
else bind_mgettext_domain ($GLOBALS[AA_INC_PATH]."lang/".$user["lang"]."_alerts_lang.inc");

/* ----------------------------------------------------------------------------------------
                          PROCESS FORM DATA -- UPDATE DATABASE
   ---------------------------------------------------------------------------------------- */      

function process_data ()
{
    global $change_user, $chuser, $change_password, $Err, $howoften, $filter, $db, $user, $addpredefcol, $add,
        $alerts_session;
    $howoften_options = get_howoften_options();
    
    if ($change_user) {
        $db->query ("UPDATE alerts_user 
            SET firstname='$chuser[firstname]', lastname='$chuser[lastname]'
            WHERE id=$user[id]");
        $user = AlertsUser ($alerts_session);
    }            
          
    else if ($change_password) {
        if ($chuser["password"] == $chuser["password2"]) {
            $db->query ("UPDATE alerts_user 
                SET password='".addslashes(md5($chuser["password"]))."'
                WHERE id=$user[id]");
            $user = AlertsUser ($alerts_session);
        }
        else $Err[] = _m("Passwords differ. Please try again.");
    }        
    
    else {
        // add filter
        if ($howoften_options[ $add["f"]["ho"] ]) 
            $db->tquery("INSERT INTO alerts_user_filter (howoften, filterid, userid)
                        VALUES ('".$add[f][ho]."', ".$add[f][id].", ".$user[id].")");

        // add collection                        
        if ($howoften_options[ $add["c"]["ho"] ]) 
            $db->tquery("INSERT INTO alerts_user_filter (howoften, collectionid, userid)
                         VALUES ('".$add[c][ho]."', ".$add[c][id].", ".$user[id].")");
        
        // update filter    
        if (is_array ($howoften["f"])) {
            reset ($howoften["f"]);
            while (list ($user_filter_id, $ho) = each ($howoften["f"])) {
                $where = "WHERE id=$user_filter_id";
                if ($howoften_options[$ho])
                    $db->query ("UPDATE alerts_user_filter SET howoften='$ho' $where");
                else if ($ho == 0)
                    $db->query ("DELETE FROM alerts_user_filter $where");
            }
        }
        
        execute_edit_collections (0, $user);
    }
}

process_data();
        
/* ----------------------------------------------------------------------------------------
                                      SHOW PAGE
   ---------------------------------------------------------------------------------------- */      

AlertsPageBegin();   
   
echo "<TITLE>". _m("AA Alerts") ."</TITLE>
</HEAD>
<BODY>
    <FORM NAME=login ACTION='user_filter.php3' METHOD=post>
    <input type=hidden name='lang' value='$lang'>
    <input type=hidden name='alerts_session' value='$alerts_session'>
    <input type=hidden name='ss' value='$ss'>
    <table width='540' border='0' cellspacing='0' cellpadding='10' bgcolor=".COLOR_TABBG." align='center'>$ac_trstart<TD class=tabtxt>";
    
echo $Msg;
PrintArray ($Err);

echo "
   <h2>"._m("AA Alerts")."</h2>";

if ($user["firstname"]) 
     $welcome = $user["firstname"]." ".$user["lastname"];
else $welcome = $user["email"];
      
echo "<p><b>"._m("Welcome, %1", array ($welcome))."</b></p>";

echo "
</TD>$ac_trend
$ac_trstart<TD>
   <table border='0' cellspacing='0' cellpadding='3' bgcolor=".COLOR_TABBG." align='center'>
     $ac_trstart<TD class=tabtxt><B>"._m("First name").":</B></TD>
        <TD class=tabtxt><INPUT TYPE=text NAME='chuser[firstname]' VALUE='$user[firstname]'></TD>$ac_trend
     $ac_trstart<TD class=tabtxt><B>"._m("Last name").":</B></TD>
        <TD class=tabtxt><INPUT TYPE=text NAME='chuser[lastname]' VALUE='$user[lastname]'></TD>$ac_trend
   </table> 
   <p align=center><INPUT TYPE=SUBMIT NAME='change_user' VALUE='"._m("Change")."'></p>
</TD>    

<TD>
   <table border='0' cellspacing='0' cellpadding='3' bgcolor=".COLOR_TABBG." align='center'>
     $ac_trstart<TD class=tabtxt><B>"._m("New password").":</B></TD>
        <TD class=tabtxt><INPUT TYPE=password NAME='chuser[password]'></TD>$ac_trend
     $ac_trstart<TD class=tabtxt><B>"._m("Retype password").":</B></TD>
        <TD class=tabtxt><INPUT TYPE=password NAME='chuser[password2]'></TD>$ac_trend
   </table> 
   <p align=center><INPUT TYPE=SUBMIT NAME='change_password' VALUE='"._m("Change password")."'></p>
</TD>$ac_trend
</TABLE>
<br>";

/* -------------------------------------------------------------------------------    
                                    SUBSCRIPTIONS
   -------------------------------------------------------------------------------  */

echo "<table width='540' border='0' cellspacing='0' cellpadding='2' bgcolor=".COLOR_TABBG." align='center'>";

//echo "$ac_trstart<td colspan=2><h2>"._m("Subscriptions")."</h2></td>$ac_trend";

// array $digests - filling with info about views of type digest
$db->query ("
    SELECT DISTINCT slice.id AS sliceid, slice.name, slice.slice_url, 
        DF.description, DF.id AS filterid, DF.showme
    FROM view INNER JOIN 
    alerts_digest_filter DF ON view.id = DF.vid INNER JOIN 
    slice ON slice.id = view.slice_id
    WHERE view.type = 'digest' 
    ORDER BY slice.name");
      
while ($db->next_record()) {
    $slice_id = unpack_id ($db->f("sliceid"));
    $digests[$slice_id]["filters"][$db->f("filterid")] 
        = array ("description"=>$db->f("description"), "showme"=>$db->f("showme"));
    $digests[$slice_id]["name"] = $db->f("name");
    $digests[$slice_id]["url"] = $db->f("slice_url");
}

$db->query ("SELECT * FROM alerts_user_filter WHERE userid=$user[id]");
while ($db->next_record()) {
    reset ($digests);
    while (list ($vid) = each ($digests)) {
        if (isset ($digests[$vid]["filters"][$db->f("filterid")])) {
            $digests[$vid]["filters"][$db->f("filterid")]["howoften"] = $db->f("howoften");
            $digests[$vid]["filters"][$db->f("filterid")]["user_filter_id"] = $db->f("id");
        }
    }
}   

// -------------------------------------------------------------------------------    
// subscribed collections

echo "$ac_trstart<td colspan=4>&nbsp;</td>$ac_trend";
echo "$ac_trstart<td colspan=4><h3>"._m("Subscribed Collections")."</h3></td>$ac_trend";

$SQL = "SELECT C.id AS cid, C.description AS cdesc, 
    DF.description as fdesc, slice.name, CF.filterid AS fid, UF.howoften 
    
    FROM alerts_collection C INNER JOIN
    alerts_collection_filter CF ON C.id = CF.collectionid INNER JOIN
    alerts_digest_filter DF ON CF.filterid = DF.id INNER JOIN
    view ON DF.vid = view.id INNER JOIN
    slice ON view.slice_id = slice.id INNER JOIN
    alerts_user_filter UF ON UF.collectionid = C.id
    
    WHERE UF.userid = $user[id]
    
    ORDER BY C.description, C.id, CF.myindex";

print_js_options_filters();
print_edit_collections ($SQL, "user_filter.php3", true);

// -------------------------------------------------------------------------------    
// subscribed digests     

//echo "$ac_trstart<td colspan=2><h3>"._m("Subscribed Filters")."</h3></td>$ac_trend

$table_header = "
   $ac_trstart<td class=tabtxt><b>"._m("Filter")."</b></td>
   <td class=tabtxt><b>"._m("How often")."</b></td>$ac_trend";

$howoften_options = get_howoften_options ();
$howoften_options["0"] = _m("unsubscribe");

reset ($digests);
if ($show_filters)
while (list (,$digest) = each ($digests)) {
    if ($digest["url"]) $viewurl = "<a href=\"$digest[url]\">$digest[name]</a>";
    else $viewurl = $digest["name"];
    
    reset ($digest["filters"]);
    while (list ($filterid,$filter) = each ($digest["filters"])) {
        if ($filter["howoften"]) {
            echo $table_header; 
            $table_header = "";
            echo "$ac_trstart<TD class=tabtxt>$viewurl -- $filter[description]</TD>
                <TD class=tabtxt>";
            FrmSelectEasy("howoften[f][".$filter[user_filter_id]."]", $howoften_options, $filter["howoften"]); 
            echo "</TD>$ac_trend";
        }
    }
}

unset ($howoften_options["0"]);

// -------------------------------------------------------------------------------    

// Predefined collections
echo "$ac_trstart<td colspan=2>&nbsp;</td>$ac_trend";
echo "$ac_trstart<td colspan=2><h3>"._m("Add")."</h3></td>$ac_trend";

$db->tquery ("SELECT description, id FROM alerts_collection
              WHERE showme = 1
              ORDER BY description");

if ($db->num_rows()) {
    echo "$ac_trstart<td colspan=1><b>"._m("Add predefined collection")."</b></td>
           <td class=tabtxt><b>"._m("How often")."</b></td>$ac_trend";
    
    while ($db->next_record())
        $predef_col [$db->f("id")] = $db->f("description");              
        
    echo "$ac_trstart<TD class=tabtxt>";
    FrmSelectEasy ("add[c][id]", $predef_col);
    echo "</TD><TD class=tabtxt>";
    FrmSelectEasy("add[c][ho]", $howoften_options, 0);
    echo "&nbsp;&nbsp;<INPUT TYPE=SUBMIT VALUE='"._m("Add")."'>";
    echo "</TD>$ac_trend"; 
}

// -------------------------------------------------------------------------------    

// Predefined filters

$filters = array ();
reset ($digests);
while (list ($slice_id,$digest) = each ($digests)) {    
    reset ($digest["filters"]);
    while (list ($filterid,$filter) = each ($digest["filters"])) 
        if (!$filter["howoften"] && $filter["showme"]) 
            $filters[$filterid] = $digest["name"]." -- ".$filter["description"];
}

if ($show_filters)
if (count ($filters)) {
    echo "$ac_trstart<td><b>"._m("Add predefined filter")."</b></td>
    <td class=tabtxt><b>"._m("How often")."</b></td>$ac_trend";
    echo "$ac_trstart<td class=tabtxt>"; 
    FrmSelectEasy ("add[f][id]", $filters);
    echo "</td><td class=tabtxt>";
    FrmSelectEasy("add[f][ho]", $howoften_options, 0); 
    echo "</td>$ac_trend";
}

echo "$ac_trstart<td colspan=2>&nbsp;</td>$ac_trend";

// -------------------------------------------------------------------------------    

// New collection

echo "$ac_trstart<td colspan=2><hr></td>$ac_trend";
print_add_collection (true);
echo "$ac_trstart<td colspan=2><hr></td>$ac_trend";

echo "$ac_trstart<td colspan=2>&nbsp;</td>$ac_trend";
echo "$ac_trstart<td colspan=2 align=center>
   <INPUT TYPE=SUBMIT NAME=\"signout\" VALUE='"._m("Sign out")."'></INPUT>
   </td>$ac_trend
</TABLE>
</FORM>
</BODY>";
?>

