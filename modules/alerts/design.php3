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

// (c) Econnect, Jakub Adamek, December 2002

require "alerts_sending.php3";

// ----------------------------------------------------------------------------

function showCollectionAddOns () 
{
    global $example, $auth, $cmd, $db, $AA_INSTAL_PATH, $AA_CP_Session,
        $collectionprop, $collectionid;

    echo "<BR><BR><TABLE border=0 bgcolor=".COLOR_TABBG."><TR><TD class=tabtxt>
    <FORM NAME=\"example[form]\" METHOD=\"post\">";

    $emailid = $collectionprop["emailid_alert"];
    $menu_settings = "tabledit.php3?set_tview=modedit&cmd[modedit][edit]["
                .$GLOBALS["slice_id"]."]=1&AA_CP_Session=$AA_CP_Session";
    if (!$emailid) {
        echo "<a href='".$menu_settings."'>"
            ._m("First set the Alert Email in the Settings menu.")."</a><br><br>";
        return;
    }
    $db->query ("SELECT description FROM email WHERE id = $emailid");
    $db->next_record();
    echo _m("Alert Email for this Collection: ")."<B>".$db->f("description")."<br>&nbsp;&nbsp;--> ";
    $useremails = GetUserEmails();
    if ($useremails[$emailid]) 
        echo " <a href='tabledit.php3?set_tview=email_edit&cmd[email_edit][edit][$emailid]=1&AA_CP_Session=$AA_CP_Session'>"
            ._m("Edit")."</a> - ";
    echo "<a href='".$menu_settings."'>"
        ._m("Choose another")."</a></B><br><br>";
       
    if (!$example["howoften"]) $example["howoften"] = "weekly";
    if (!$example["email"]) {
        $me = GetUser ($auth->auth["uid"]);  
        $example["email"] = $me["mail"][0];
    }
        
    echo '<B><A href="javascript:document.forms[\'example[form]\'].submit()">'
        ._m("Send now an example email to");
    echo '</A><BR>
        <INPUT TYPE=HIDDEN NAME="example[go]" VALUE=1>
        <INPUT TYPE="text" NAME="example[email]" VALUE="'.$example[email].'">'.'&nbsp;';    
    echo _m("as if")."&nbsp;";
    FrmSelectEasy("example[howoften]", get_howoften_options(), $example[howoften]);
    echo '</B>&nbsp;';
    
    if ($example["go"]) {
        $ho = $example["howoften"];
        $db->query("SELECT DF.conds, view.slice_id, DF.id AS filterid, DF.vid, slice.name AS slicename, slice.lang_file, FH.last
            FROM alerts_filter DF INNER JOIN
                 view ON view.id = DF.vid INNER JOIN
                 slice ON slice.id = view.slice_id INNER JOIN
                 alerts_collection_filter ACF ON ACF.filterid = DF.id INNER JOIN
                 alerts_filter_howoften FH ON DF.id = FH.filterid
            WHERE ACF.collectionid = $collectionid AND FH.howoften='$ho'
            ORDER BY view.slice_id, DF.vid");
        
        while ($db->next_record()) {
            $slices[$db->f("slice_id")]["name"] = $db->f("slicename");
            $slices[$db->f("slice_id")]["lang"] = substr ($db->f("lang_file"),0,2);
            $slices[$db->f("slice_id")]["views"][$db->f("vid")]["filters"][$db->f("filterid")] = 
                array ("conds"=>$db->f("conds"), "last"=>$db->f("last"));
        }
        
        $db->query ("SELECT id FROM alerts_user WHERE email='$example[email]'");
        if ($db->next_record())
            $uid = $db->f("id");
        create_filter_text_from_list ($example["howoften"], $slices, false);
        $sent = send_emails ($example["howoften"], array ($collectionid), 
            array ($uid => $example["email"]));                   
        echo "<br><b>"._m("%1 email sent", array ($sent+0))."</b>\n";
    }
    echo "</form></TD></TR></TABLE><br><br>\n";
}

?>