<?php

$tablename = "wizard_welcome";

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."date.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."tabledit.php3";

if(!IsSuperadmin()) {
    MsgPage($sess->url(self_base())."index.php3", L_NO_PS_ADD, "standalone");
    exit;
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
    <TITLE><?php echo L_EDIT_WIZARD_WELCOME;?></TITLE>
    </HEAD>
<?php
    $show ["tabledit"] = false;
    require $GLOBALS[AA_INC_PATH]."aa_inc.php3";   //show navigation column depending on $show variable
    
    echo "<H1><B>" . L_EDIT_WIZARD_WELCOME . "</B></H1>";
    PrintArray($err);
    echo $Msg;
    
    $tblname = "wizard_welcome";
    $fields = array ("description","email","subject","mail_from");
    $primary_key = array ("id"=>"number");
    $hint = array ("email"=>"mail body", "mail_from"=>"\"from:\" field");
    $attrs_edit = array (
            "table"=>"border=0 cellpadding=3 cellspacing=0 bgcolor='".COLOR_TABBG."'",
            "td"=>"class=tabtxt");
    $attrs_browse = array (
            "url"=>"AA_CP_Session=$AA_CP_Session",
            "table"=>"border=1 cellpadding=3 width='100%' cellspacing=0 bgcolor='".COLOR_TABBG."'",
            "td"=>"class=tabtxt");
    
    if ($cmd["update"] && ($par["where"] || $cmd["insertsent"]))
        TableUpdate ($tblname, $par["where"], $val, $fields);
    
    else if ($cmd["delete"])
        TableDelete ($tblname, $cmd["delete"], $primary_key);
      
    if ($cmd["edit"] || $cmd["insert"]) TableEditView ($tblname, $cmd["edit"], "tabledit.php3?AA_CP_Session=$AA_CP_Session", $attrs_edit, $fields, $primary_key,$hint);
    else TableBrowseView ($tblname, $attrs_browse, $fields, $primary_key);
?>
