<?php
// ----------------------------------------------------------------------------

function showCollectionAddOns () 
{
    global $example, $auth, $cmd, $db, $AA_INSTAL_PATH, $AA_CP_Session;

    $edit = $cmd["ac_edit"]["edit"];
    if (!is_array ($edit)) {
        global $tabledit_cmd;
        $edit = $tabledit_cmd["ac_edit"]["edit"];
    }        
    
    reset ($edit);
    $collectionid = key($edit);    
    
    echo "<TABLE border=0 bgcolor=".COLOR_TABBG."><TR><TD class=tabtxt>
    <FORM NAME=\"example[form]\" METHOD=\"post\">
    <b>Run Action:</b><br><br>";
    
    if (!$example["howoften"]) $example["howoften"] = "weekly";
    if (!$example["email"]) {
        $me = GetUser ($auth->auth["uid"]);  
        $example["email"] = $me["mail"][0];
    }
        
    echo '<B><A href="javascript:document.forms[\'example[form]\'].submit()">'._m("Send now an example email to");
    echo '</A>&nbsp;
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