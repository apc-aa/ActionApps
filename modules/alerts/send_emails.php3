<?php
/**
 * Alerts menu: Send emails.
 * Functions for showing info and allowing to send an example email to the user.
 * 
 * @package Alerts
 * @version $Id$
 * @author Jakub Adámek <jakubadamek@ecn.cz>, Econnect, December 2002
 * @copyright Copyright (C) 1999-2002 Association for Progressive Communications 
*/
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

// (c) Econnect, Jakub Adamek, December 2002
/*
$directory_depth = "../";
require_once "$directory_depth../include/init_page.php3";
require_once $GLOBALS["AA_INC_PATH"]."constants.php3";
require_once $GLOBALS["AA_INC_PATH"]."locsess.php3";
require_once $GLOBALS["AA_INC_PATH"]."tabledit.php3";
require_once $GLOBALS["AA_INC_PATH"]."tv_common.php3";
require_once $GLOBALS["AA_INC_PATH"]."util.php3";
require_once $MODULES[$g_modules[$slice_id]['type']]['menu'];   
require_once "alerts_sending.php3";

// ----------------------------------------------------------------------------

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo "<TITLE>"._m("Alerts-Send emails")."</TITLE></HEAD>";
showMenu ($aamenus, "send_emails", "send_emails");

showCollectionAddOns();

HtmlPageEnd();
page_close();
*/

require_once "alerts_sending.php3";

function showCollectionAddOns () 
{
    global $example, $fire, $auth, $cmd, $db, $AA_INSTAL_PATH, $sess,
        $collectionprop, $collectionid;

    initialize_last();        

    echo "<BR><BR><TABLE border=0 bgcolor=".COLOR_TABBG."><TR><TD class=tabtxt>
    <FORM NAME=\"example[form]\" METHOD=\"post\">";

    if (!$example["howoften"]) $example["howoften"] = "weekly";
    if (!$example["email"]) {
        $me = GetUser ($auth->auth["uid"]);  
        $example["email"] = $me["mail"][0];
    }
    
    echo '<h2>'._m("Example").'</h2>';
    
    if ($example["go"]) 
        $mail_count = send_emails ($example["howoften"], array ($collectionid), 
            array ($example["email"]), false, "");                   
    
    echo '<B>'._m("Send now an example alert email to");
    echo '<BR>
        <INPUT TYPE="text" NAME="example[email]" size="40" 
            VALUE="'.$example["email"].'">'.'&nbsp;';    
    echo _m("as if")."&nbsp;";
    FrmSelectEasy("example[howoften]", get_howoften_options(false), $example["howoften"]);
    echo '</B> <INPUT TYPE=SUBMIT NAME="example[go]" VALUE="'._m("Go!").'"></FORM>';
    
    // SEND EMAILS
    
    echo '
    <FORM name="fire_form" method="post" action="'.$sess->url($GLOBALS["PHP_SELF"]).'"><HR>
    <h2>'._m("Send alerts").'</h2><B>';
        
    if ($fire["fire"]) 
        $mail_count = send_emails($fire["howoften"], "all", "all", true, "");
    
    echo _m("Last time the alerts were sent on:");
    $db->query("SELECT * FROM alerts_collection_howoften 
        WHERE collectionid='".$collectionid."'");
    while ($db->next_record())  
        $last[$db->f("howoften")] = $db->f("last");
    echo "<TABLE border=0>";
    $hos = get_howoften_options();
    while (list($ho,$msg) = each($hos)) 
        if ($ho != "instant")
        echo "<TR><TD>".$msg."</TD><TD>".date("j.n. H:i", $last[$ho])."</TD></TR>\n";         
    echo "</table>";
    
    echo _m("Send now alerts to all users subscribed to ").'
        '; FrmSelectEasy("fire[howoften]", get_howoften_options(false), $fire["howoften"]);
    echo " "._m("digest").'</B>
        <INPUT TYPE=SUBMIT NAME="fire[fire]" VALUE="'._m("Go!").'"><BR><B>'
        ._m("Warning: This is a real command!")
        .'</B></FORM>';
    
    if ($fire["fire"] || $example["go"])
        echo "<br><b>"._m("%1 email(s) sent", array ($mail_count+0))."</b>\n";

    echo "</TD></TR></TABLE><br><br>\n";
}

function showSelectionTable () 
{
    global $sess;
    $db = new DB_AA;
    $db->query (
        "SELECT view.slice_id, slice.name AS slice_name, 
            alerts_filter.vid, view.name AS view_name,
            alerts_filter.id AS fid, view.type AS view_type,
            alerts_filter.description AS filter_name
         FROM slice INNER JOIN view ON slice.id = view.slice_id
         INNER JOIN alerts_filter ON alerts_filter.vid = view.id
         ORDER BY slice.name, view.name, alerts_filter.id");
    $myslices = GetUserSlices();
    while ($db->next_record()) {
        $a[$db->f("slice_id")]["name"] = $db->f("slice_name");
        $av = &$a[$db->f("slice_id")]["views"];
        $av[$db->f("vid")]["name"] = $db->f("view_name");
        $av[$db->f("vid")]["type"] = $db->f("view_type");
        $av[$db->f("vid")]["filters"][$db->f("fid")] = $db->f("filter_name");
    }
    
    //echo "<BR><B>"._m("Selections ordered by slice and view:")."</B><BR>";
    echo "<BR><TABLE border=1 cellpadding=3 cellspacing=0>
        <TR class=tabtit><TD><B>"._m("Slice")."</B></TD>
        <TD><B>"._m("View (Selection set)")."</B></TD>
        <TD><B>"._m("Selections")."</B></TD></TR>";
    reset ($a);
    while (list ($slice_id, $slice) = each ($a)) {
        if (! IsSuperadmin() && ! strchr ($myslices [unpack_id128($slice_id)], PS_FULLTEXT)) 
            continue;
        echo "<TR><TD class=tabtxt rowspan=".count($slice["views"]).">
            <A href=\"".$sess->url($GLOBALS["AA_INSTAL_PATH"]
                ."admin/index.php3?slice_id=".unpack_id ($slice_id))."\">"
                .$slice["name"]."</A></TD>";
        reset ($slice["views"]);
        $first_view = true;
        while (list ($vid, $view) = each ($slice["views"])) {
            if (! $first_view)
                echo "<TR>";
            $first_view = false;
            echo "<TD class=tabtxt>
                <A href=\"".$sess->url($GLOBALS["AA_INSTAL_PATH"]
                ."admin/se_view.php3?slice_id=".unpack_id ($slice_id)
                ."&change_page=se_view.php3"
                ."&change_params[view_id]=".$vid
                ."&change_params[view_type]=".$view["type"])
                ."\">".$view["name"]."</A></TD><TD class=tabtxt>";
            reset ($view["filters"]);
            while (list ($fid, $filter) = each ($view["filters"]))
                echo "f".$fid." ".$filter."<br>";
            echo "</TD></TR>";    
        }
    }
    echo "</TABLE>";
}
         
?>