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

/* Shows a Table View, allowing to edit, delete, update fields of a table
   Params:
       $set_tview -- required, name of the table view
*/

$require_default_lang = true;      // do not use module specific language file
                                   // (message for init_page.php3)
require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."date.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."tabledit.php3";
require $MODULES[$g_modules[$slice_id]['type']]['menu'];   //show navigation column depending on $show
require $GLOBALS[AA_INC_PATH]."mgettext.php3";
require $GLOBALS[AA_INC_PATH]."../misc/alerts/util.php3";
require $GLOBALS[AA_INC_PATH]."../misc/alerts/alerts_sending.php3";

require $GLOBALS[AA_INC_PATH]."tableviews.php3";

// ----------------------------------------------------------------------------------------

$sess->register("tview");
if ($set_tview) $tview = $set_tview;

$tableview = GetTableView($tview);

if (!is_array ($tableview)) {
    MsgPage ($sess->url(self_base()."index.php3"), "Bad Table view ID: ".$tview);
    exit;
}

if (! $tableview["cond"] ) {
    MsgPage ($sess->url(self_base()."index.php3"), L_NO_PS_ADD, "standalone");
    exit;
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo '<LINK rel=StyleSheet href="'.$AA_INSTAL_PATH.'/tabledit.css" type="text/css"  title="TableEditCSS">';
echo "<TITLE>".$tableview["title"]."</TITLE></HEAD>";
showMenu ($aamenus, $tableview["mainmenu"], $tableview["submenu"]);
echo "<H1><B>" . $tableview["caption"] . "</B></H1>";
PrintArray($err);
echo $Msg;

if ($tview == "ac_edit")
    showCollectionAddOns();

if ($tableview["help"])     
    echo '<table border="0" cellspacing="0" cellpadding="5"><tr><td class="tabtit">'
        .$tableview["help"]
        .'</td></tr></table><br>';

$script = "tabledit.php3?AA_CP_Session=$AA_CP_Session";

$tabledit = new tabledit ($tview, $script, $cmd, $val, $tableview, $AA_INSTAL_PATH."images/", $sess, "", "", "GetTableView");
$err = $tabledit->view ($where);

if ($err) echo "<b>$err</b>";
HTMLPageEnd();
page_close ();

// ----------------------------------------------------------------------------

function showCollectionAddOns () 
{
    global $example, $auth, $cmd, $db, $AA_INSTAL_PATH, $AA_CP_Session;

    reset ($cmd["ac_edit"]["edit"]);
    $collectionid = key ($cmd["ac_edit"]["edit"]);
    
    echo "<TABLE border=0 bgcolor=".COLOR_TABBG."><TR><TD class=tabtxt>
    <FORM NAME=\"example[form]\" METHOD=\"post\">
    <b>Run Action:</b><br><br>";
    echo "<b><a href='".$AA_INSTAL_PATH."misc/alerts/cf_wizard.php3?"
        ."collectionid=$collectionid&AA_CP_Session=$AA_CP_Session'>"
        ._m("Create a Collection Form using the Wizard")."</a></b><br>";
    
    if (!$example["howoften"]) $example["howoften"] = "weekly";
    if (!$example["email"]) {
        $me = GetUser ($auth->auth["uid"]);  
        $example["email"] = $me["mail"][0];
    }
        
    echo '<B><A href="javascript:document.forms[\'example[form]\'].submit()">'._m("Send now an example email to");
    echo '</A>&nbsp;<INPUT TYPE="text" NAME="example[email]" VALUE="'.$example[email].'">'.'&nbsp;';    
    echo _m("as if")."&nbsp;";
    FrmSelectEasy("example[howoften]", get_howoften_options(), $example[howoften]);
    echo '</B>&nbsp;';
    
    if ($example["go"]) {
        $ho = $example["howoften"];
        $db->query("SELECT DF.conds, view.slice_id, DF.id AS filterid, DF.vid, slice.name AS slicename, slice.lang_file, DF.last_$ho
            FROM alerts_digest_filter DF INNER JOIN
                 view ON view.id = DF.vid INNER JOIN
                 slice ON slice.id = view.slice_id INNER JOIN
                 alerts_collection_filter ACF ON ACF.filterid = DF.id
            WHERE ACF.collectionid = $collectionid
            ORDER BY view.slice_id, DF.vid");
        
        while ($db->next_record()) {
            $slices[$db->f("slice_id")]["name"] = $db->f("slicename");
            $slices[$db->f("slice_id")]["lang"] = substr ($db->f("lang_file"),0,2);
            $slices[$db->f("slice_id")]["views"][$db->f("vid")]["filters"][$db->f("filterid")] = 
                array ("conds"=>$db->f("conds"), "last"=>$db->f("last_$ho"));
        }
        
        $db->query ("SELECT id FROM alerts_user WHERE email='$example[email]'");
        if ($db->next_record())
            $uid = $db->f("id");
        create_filter_text_from_list ($example["howoften"], $slices, false);
        $sent = send_emails ($example["howoften"], array ($collectionid), 
            array ($uid => $example["email"], 
            2 => "<jakubadamek@seznam.cz>"));                   
        echo "<b>"._m("%1 email sent", array ($sent+0))."</b>";
    }
    echo "</form></TD></TR></TABLE><br><br>";
}
?>
